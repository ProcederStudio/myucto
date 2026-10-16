<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use Psr\Http\Message\StreamInterface;

/**
 * Úložiště souborů nahraných do průvodce převodem: `storage/<zdroj>/<firma>/<token>/`.
 *
 * Soubor (záloha, export) se nahraje jednou, zpracuje a průvodce nad ním pouští náhled,
 * zkoušku nanečisto i ostrý převod — worker běží až po skončení requestu, takže data musí
 * ležet na disku. Token je náhodný a adresář je pod firmou: cizí firma na nahraný soubor
 * nedosáhne ani se znalostí tokenu.
 *
 * Velký soubor přichází po částech: `upload.json` drží stav nahrávání a zpracování, části
 * se připojují do souboru části (`$partFile`) a job na pozadí z něj po dokončení rozbalí
 * data do `$dataDir` a zapíše `meta.json`. Po dobu zpracování drží job zámek `job.lock`
 * a úklid takový adresář nesmaže. Denní úklid maže adresáře po 24 hodinách bez práce s nimi:
 * rozbalená záloha má stovky megabajtů až gigabajty a převod z ní je otázka jednoho sezení.
 *
 * Jednotlivé zdroje (Money S3, POHODA, PREMIER) nad úložištěm drží statické fasády se svou
 * konfigurací; liší se jménem podadresáře, souboru části, adresáře rozbalených dat, třídou
 * výjimky a texty chyb.
 */
final class ChunkedUploadStore
{
    public const TOKEN_PATTERN = '/^[a-f0-9]{16}$/';
    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';
    public const STALE_SECONDS = 86400;
    private const STATE_FILE = 'upload.json';
    private const META_FILE = 'meta.json';
    private const UPLOAD_LOCK_FILE = 'upload.lock';
    private const JOB_LOCK_FILE = 'job.lock';

    /**
     * @param string $storageDir podadresář `storage/` zdroje (`money-s3`, `pohoda`, …)
     * @param string $partFile soubor, do kterého se připojují části
     * @param string $dataDir podadresář rozbalených dat
     * @param \Closure(string,string,array<string,mixed>,int):\RuntimeException $exception výjimka zdroje (kód, text, kontext, HTTP stav)
     * @param list<string> $activityGlobs další soubory (glob relativně k adresáři nahrání), jejichž změna je práce s nahraným souborem
     */
    public function __construct(
        private readonly string $storageDir,
        private readonly string $partFile,
        private readonly string $dataDir,
        private readonly \Closure $exception,
        private readonly ChunkedUploadMessages $messages,
        private readonly array $activityGlobs = [],
        private readonly int $tokenBytes = 8,
        private readonly string $stateFile = self::STATE_FILE,
        private readonly string $uploadLockFile = self::UPLOAD_LOCK_FILE,
    ) {
        if (!in_array($tokenBytes, [8, 16], true)
            || preg_match('/^[a-z][a-z0-9._-]*$/D', $stateFile) !== 1
            || preg_match('/^[a-z][a-z0-9._-]*$/D', $uploadLockFile) !== 1) {
            throw new \InvalidArgumentException('Invalid upload storage layout.');
        }
    }

    public function messages(): ChunkedUploadMessages
    {
        return $this->messages;
    }

    public function base(int $supplierId): string
    {
        return RuntimePaths::storage($this->storageDir . '/' . $supplierId);
    }

    public function newToken(): string
    {
        return bin2hex(random_bytes($this->tokenBytes));
    }

    public function isValid(int $supplierId, string $token): bool
    {
        return $supplierId > 0 && preg_match('/^[a-f0-9]{' . ($this->tokenBytes * 2) . '}$/D', $token) === 1;
    }

    public function dir(int $supplierId, string $token): string
    {
        if (!$this->isValid($supplierId, $token)) {
            throw $this->fail('upload_not_found', $this->messages->notFound, [], 404);
        }
        return $this->base($supplierId) . '/' . $token;
    }

    public function dataDir(int $supplierId, string $token): string
    {
        return $this->dir($supplierId, $token) . '/' . $this->dataDir;
    }

    /** @return array<string,mixed> */
    public function meta(int $supplierId, string $token): array
    {
        $path = $this->dir($supplierId, $token) . '/' . self::META_FILE;
        if (!is_file($path)) {
            throw $this->fail('upload_not_found', $this->messages->metaNotFound, [], 404);
        }
        $meta = json_decode((string) file_get_contents($path), true);
        return is_array($meta) ? $meta : [];
    }

    /** @param array<string,mixed> $meta */
    public function writeMeta(int $supplierId, string $token, array $meta): void
    {
        self::writeJson($this->dir($supplierId, $token) . '/' . self::META_FILE, $meta);
    }

    public function hasMeta(int $supplierId, string $token): bool
    {
        return is_file($this->dir($supplierId, $token) . '/' . self::META_FILE);
    }

    /**
     * Stav nahrávání po částech a zpracování na pozadí, nebo null u souboru nahraného
     * jedním požadavkem (ten má rovnou `meta.json`).
     *
     * @return array<string,mixed>|null
     */
    public function state(int $supplierId, string $token): ?array
    {
        $path = $this->dir($supplierId, $token) . '/' . $this->stateFile;
        if (!is_file($path)) {
            return null;
        }
        $state = json_decode((string) file_get_contents($path), true);
        return is_array($state) ? $state : null;
    }

    /** @param array<string,mixed> $state */
    public function writeState(int $supplierId, string $token, array $state): void
    {
        self::writeJson($this->dir($supplierId, $token) . '/' . $this->stateFile, $state);
    }

    /**
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    public function updateState(int $supplierId, string $token, array $changes): array
    {
        $state = array_merge($this->state($supplierId, $token) ?? [], $changes);
        $this->writeState($supplierId, $token, $state);
        return $state;
    }

    public function partPath(int $supplierId, string $token): string
    {
        return $this->dir($supplierId, $token) . '/' . $this->partFile;
    }

    public function partSize(int $supplierId, string $token): int
    {
        $path = $this->partPath($supplierId, $token);
        clearstatcache(true, $path);
        return is_file($path) ? (int) filesize($path) : 0;
    }

    /**
     * Připojí část na konec souboru části. Přijme ji jen tehdy, když navazuje přesně na to,
     * co už server má — opakovaná nebo předbíhající část vrátí 409 s tím, kolik bajtů
     * server drží, a klient podle toho naváže.
     *
     * @return int kolik bajtů server po připojení drží
     */
    public function appendChunk(int $supplierId, string $token, int $offset, StreamInterface $chunk, int $maxChunkBytes): int
    {
        return $this->withUploadLock($supplierId, $token, function () use ($supplierId, $token, $offset, $chunk, $maxChunkBytes): int {
            $state = $this->state($supplierId, $token);
            if ($state === null) {
                throw $this->fail('upload_not_found', $this->messages->uploadNotFound, [], 404);
            }
            $size = (int) ($state['size'] ?? 0);
            $current = $this->partSize($supplierId, $token);
            if (($state['status'] ?? '') !== self::STATUS_UPLOADING) {
                throw $this->fail('upload_not_uploading', $this->messages->notUploading, ['received' => $current], 409);
            }
            if ($offset !== $current) {
                throw $this->fail('chunk_offset_mismatch', $this->messages->offsetMismatch, ['received' => $current], 409);
            }
            $out = @fopen($this->partPath($supplierId, $token), 'ab');
            if ($out === false) {
                throw $this->fail('storage_not_writable', $this->messages->storageNotWritable, [], 500);
            }
            $written = 0;
            try {
                if ($chunk->isSeekable()) {
                    $chunk->rewind();
                }
                while (!$chunk->eof()) {
                    $buffer = $chunk->read(1024 * 1024);
                    if ($buffer === '') {
                        break;
                    }
                    $length = strlen($buffer);
                    if ($written + $length > $maxChunkBytes) {
                        throw $this->fail('chunk_too_large', $this->messages->chunkTooLarge, ['received' => $current], 413);
                    }
                    if ($current + $written + $length > $size) {
                        throw $this->fail('chunk_exceeds_size', $this->messages->chunkExceedsSize, ['received' => $current], 422);
                    }
                    if (fwrite($out, $buffer) !== $length) {
                        throw $this->fail('storage_not_writable', $this->messages->chunkWriteFailed, ['received' => $current], 500);
                    }
                    $written += $length;
                }
                fflush($out);
            } catch (\Throwable $e) {
                ftruncate($out, $current);
                throw $e;
            } finally {
                fclose($out);
            }
            if ($written === 0) {
                throw $this->fail('chunk_empty', $this->messages->chunkEmpty, ['received' => $current], 400);
            }
            $received = $current + $written;
            $this->updateState($supplierId, $token, ['received' => $received]);
            return $received;
        });
    }

    /**
     * Stav nahrávání mění souběžné požadavky (opakovaná část, dvojí dokončení) — každá
     * změna proto běží pod výhradním zámkem tokenu.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function withUploadLock(int $supplierId, string $token, callable $fn): mixed
    {
        $handle = @fopen($this->dir($supplierId, $token) . '/' . $this->uploadLockFile, 'c');
        if ($handle === false) {
            throw $this->fail('upload_not_found', $this->messages->uploadNotFound, [], 404);
        }
        try {
            flock($handle, LOCK_EX);
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Zámek, který job drží po celé zpracování nebo převod. Úklid adresář se zámkem
     * nesmaže ({@see purgeStale()}).
     *
     * @return resource|null null = soubor už zpracovává jiný proces
     */
    public function acquireJobLock(int $supplierId, string $token)
    {
        $handle = @fopen($this->dir($supplierId, $token) . '/' . self::JOB_LOCK_FILE, 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    /** @param resource $handle */
    public function releaseJobLock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * Zahodí data nepovedeného zpracování (rozbalená data a nahraný soubor), stav s chybou
     * ale nechá — průvodce ji ukáže a denní úklid adresář smaže později.
     */
    public function discardData(int $supplierId, string $token): void
    {
        if (!$this->isValid($supplierId, $token)) {
            return;
        }
        $dir = $this->dir($supplierId, $token);
        if (is_dir($dir . '/' . $this->dataDir)) {
            $this->removeTree($dir . '/' . $this->dataDir, $supplierId);
        }
        @unlink($dir . '/' . $this->partFile);
        @unlink($dir . '/' . self::META_FILE);
    }

    public function purge(int $supplierId, string $token): void
    {
        if (!$this->isValid($supplierId, $token)) {
            return;
        }
        $this->removeTree($this->dir($supplierId, $token), $supplierId);
    }

    /** Smaže nahraný soubor, pokud s ním zrovna nepracuje job. False = nesmazáno. */
    public function purgeIdle(int $supplierId, string $token): bool
    {
        if (!$this->isValid($supplierId, $token)) {
            return false;
        }
        $dir = $this->dir($supplierId, $token);
        if (!is_dir($dir) || self::isBusy($dir)) {
            return false;
        }
        $this->removeTree($dir, $supplierId);
        clearstatcache(true, $dir);
        return !is_dir($dir);
    }

    /** @return int kolik nahraných souborů firmy se smazalo */
    public function purgeStale(int $supplierId): int
    {
        $limit = time() - self::STALE_SECONDS;
        $removed = 0;
        foreach (glob($this->base($supplierId) . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if ($this->isValid($supplierId, basename($dir)) && $this->lastActivity($dir) < $limit && !self::isBusy($dir)) {
                $this->removeTree($dir, $supplierId);
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Nové nahrání uvolní místo: z nečinných souborů firmy nechá jen naposledy použité tak,
     * aby jich i s novým bylo nejvýš `$max`, starší smaže. Soubor, se kterým právě pracuje
     * job, nechá. False = místo není, všechny soubory se zpracovávají.
     */
    public function makeRoom(int $supplierId, int $max): bool
    {
        $idle = [];
        $busy = 0;
        foreach (glob($this->base($supplierId) . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (!$this->isValid($supplierId, basename($dir))) {
                continue;
            }
            if (self::isBusy($dir)) {
                $busy++;
            } else {
                $idle[$dir] = $this->lastActivity($dir);
            }
        }
        arsort($idle);
        $keep = max(0, $max - 1 - $busy);
        foreach (array_keys($idle) as $dir) {
            if ($keep > 0) {
                $keep--;
                continue;
            }
            $this->removeTree($dir, $supplierId);
        }
        return $busy < $max;
    }

    /** Průvodce se souborem pracuje (spouští převod) — denní úklid ho zatím nesmaže. */
    public function touch(int $supplierId, string $token): void
    {
        $path = $this->dir($supplierId, $token) . '/' . self::META_FILE;
        if (is_file($path)) {
            @touch($path);
        }
    }

    /**
     * Denní úklid ({@see api/bin/cron-cleanup.php}) nahraných souborů všech firem.
     * Rozbalená data jsou celé účetnictví firmy — nesmí na disku čekat, až je uklidí
     * další upload téže firmy, který nemusí přijít nikdy.
     */
    public function purgeStaleAll(): int
    {
        $removed = 0;
        foreach (glob(RuntimePaths::storage($this->storageDir) . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (preg_match('/^[1-9]\d*$/', basename($dir)) === 1) {
                $removed += $this->purgeStale((int) basename($dir));
            }
        }
        return $removed;
    }

    /**
     * Poslední práce s nahraným souborem: nahrání (meta.json), stav nahrávání po částech,
     * poslední přijatá část, případně další soubory zdroje (`$activityGlobs`). Čas adresáře
     * nestačí — Windows ho u adresářů změnou obsahu podadresářů neposouvá.
     */
    private function lastActivity(string $dir): int
    {
        $latest = 0;
        $files = [$dir . '/' . self::META_FILE, $dir . '/' . $this->stateFile, $dir . '/' . $this->partFile];
        foreach ($this->activityGlobs as $pattern) {
            $files = array_merge($files, glob($dir . '/' . $pattern) ?: []);
        }
        foreach ($files as $file) {
            clearstatcache(true, $file);
            $latest = max($latest, is_file($file) ? (int) filemtime($file) : 0);
        }
        return $latest > 0 ? $latest : (int) filemtime($dir);
    }

    /** Drží zámek adresáře běžící job? */
    private static function isBusy(string $dir): bool
    {
        $path = $dir . '/' . self::JOB_LOCK_FILE;
        if (!is_file($path)) {
            return false;
        }
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return true;
        }
        $free = flock($handle, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        return !$free;
    }

    /**
     * Zápis přes dočasný soubor — souběžné čtení nikdy nedostane napůl zapsaný JSON.
     *
     * @param array<string,mixed> $data
     */
    private static function writeJson(string $path, array $data): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            file_put_contents($path, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }
    }

    /**
     * Mazání jen pod adresářem firmy. Casing cest na Windows nesedí spolehlivě,
     * proto se obě strany porovnávají malými písmeny.
     */
    private function removeTree(string $dir, int $supplierId): void
    {
        $real = realpath($dir);
        $base = realpath($this->base($supplierId));
        if ($real === false || $base === false
            || !str_starts_with(strtolower($real), strtolower($base) . DIRECTORY_SEPARATOR)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($real);
    }

    /** @param array<string,mixed> $context */
    private function fail(string $code, string $message, array $context, int $status): \RuntimeException
    {
        return ($this->exception)($code, $message, $context, $status);
    }
}
