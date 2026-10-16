<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Migration\Shared\ChunkedUploadMessages;
use MyInvoice\Service\Migration\Shared\ChunkedUploadStore;
use MyInvoice\Service\Migration\Shared\FiledDppoFiling;
use MyInvoice\Service\Tax\Return\TaxReturnException;

/**
 * Zálohy a podaná přiznání k DPPO nahraná do dávkového převodu z Money S3:
 * `storage/money-s3-batch/<firma>/<token>/backup.lz` a `…/<firma>/filings/<sha1>.xml`.
 *
 * Na rozdíl od průvodce jedné firmy se záloha po nahrání nerozbaluje natrvalo: dávka
 * drží desítky záloh a rozbalené účetnictví každé by zabíralo místo celé dny. Po
 * nahrání se záloha rozbalí jen na přečtení přehledu agendy, pak se rozbalená data
 * smažou a zůstane jen soubor zálohy; převod ji rozbalí znovu, až na ni dojde řada,
 * a po převodu data zase smaže.
 */
final class MoneyS3BatchUploads
{
    public const BACKUP_FILE = 'backup.lz';

    /** Nahraných záloh dávky najednou — kancelář převádí desítky firem. */
    public const MAX_ACTIVE_UPLOADS = 100;

    /** Podané přiznání (XML) — jednotky kB, strop proti omylu. */
    public const MAX_FILING_BYTES = 5 * 1024 * 1024;

    private static ?ChunkedUploadStore $store = null;

    public static function store(): ChunkedUploadStore
    {
        return self::$store ??= new ChunkedUploadStore(
            'money-s3-batch',
            'backup.lz.part',
            'agenda',
            static fn (string $code, string $message, array $context, int $status): MoneyS3Exception => new MoneyS3Exception($code, $message, $context, $status),
            ChunkedUploadMessages::backup(),
            [self::BACKUP_FILE],
        );
    }

    public static function backupPath(int $supplierId, string $token): string
    {
        return self::store()->dir($supplierId, $token) . '/' . self::BACKUP_FILE;
    }

    /**
     * Nahrané zálohy firmy (připravené i rozpracované) s přehledem agendy.
     *
     * @return list<array<string,mixed>>
     */
    public static function listUploads(int $supplierId): array
    {
        $store = self::store();
        $out = [];
        foreach (glob($store->base($supplierId) . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $token = basename($dir);
            if (preg_match(ChunkedUploadStore::TOKEN_PATTERN, $token) !== 1) {
                continue;
            }
            if ($store->hasMeta($supplierId, $token)) {
                $out[] = $store->meta($supplierId, $token) + ['token' => $token, 'status' => ChunkedUploadStore::STATUS_READY];
                continue;
            }
            $state = $store->state($supplierId, $token);
            if ($state !== null) {
                $out[] = ['token' => $token, 'file_name' => (string) ($state['file_name'] ?? ''), 'status' => (string) ($state['status'] ?? ''),
                    'size' => (int) ($state['size'] ?? 0), 'job_id' => $state['job_id'] ?? null, 'error' => $state['error'] ?? null,
                    'created_at' => $state['created_at'] ?? null];
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp((string) ($a['uploaded_at'] ?? $a['created_at'] ?? ''), (string) ($b['uploaded_at'] ?? $b['created_at'] ?? '')));
        return $out;
    }

    public static function filingsDir(int $supplierId): string
    {
        return RuntimePaths::storage('money-s3-batch/' . $supplierId . '/filings');
    }

    /**
     * Uloží podané přiznání (DPPDP9). Totéž přiznání nahrané znovu je týž soubor.
     *
     * @return array{id:string,filing:FiledDppoFiling}
     * @throws TaxReturnException soubor není přiznání DPPDP9
     */
    public static function saveFiling(int $supplierId, string $xml, string $fileName): array
    {
        $filing = FiledDppoFiling::parse($xml);
        $dir = self::filingsDir($supplierId);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new MoneyS3Exception('storage_not_writable', 'Úložiště pro podaná přiznání není zapisovatelné.', [], 500);
        }
        $id = sha1($xml);
        file_put_contents($dir . '/' . $id . '.xml', $xml);
        file_put_contents($dir . '/' . $id . '.json', (string) json_encode(['file_name' => mb_substr(basename(str_replace('\\', '/', $fileName)), 0, 200), 'uploaded_at' => date('c')], JSON_UNESCAPED_UNICODE));
        return ['id' => $id, 'filing' => $filing];
    }

    /**
     * Nahraná podaná přiznání firmy.
     *
     * @return list<array{id:string,file:string,file_name:string,xml:string,filing:FiledDppoFiling}>
     */
    public static function filings(int $supplierId): array
    {
        $out = [];
        foreach (glob(self::filingsDir($supplierId) . '/*.xml') ?: [] as $path) {
            $id = basename($path, '.xml');
            if (preg_match('/^[a-f0-9]{40}$/', $id) !== 1) {
                continue;
            }
            $xml = (string) file_get_contents($path);
            try {
                $filing = FiledDppoFiling::parse($xml);
            } catch (TaxReturnException) {
                continue;
            }
            $meta = json_decode((string) @file_get_contents(self::filingsDir($supplierId) . '/' . $id . '.json'), true);
            $out[] = ['id' => $id, 'file' => $path, 'file_name' => (string) ($meta['file_name'] ?? $id . '.xml'), 'xml' => $xml, 'filing' => $filing];
        }
        usort($out, static fn (array $a, array $b): int => [$a['filing']->ic, $a['filing']->year()] <=> [$b['filing']->ic, $b['filing']->year()]);
        return $out;
    }

    public static function deleteFiling(int $supplierId, string $id): bool
    {
        if (preg_match('/^[a-f0-9]{40}$/', $id) !== 1) {
            return false;
        }
        $path = self::filingsDir($supplierId) . '/' . $id . '.xml';
        if (!is_file($path)) {
            return false;
        }
        @unlink($path);
        @unlink(self::filingsDir($supplierId) . '/' . $id . '.json');
        return true;
    }

    /** Denní úklid: zálohy i podaná přiznání 24 hodin bez práce s nimi. */
    public static function purgeStaleAll(): int
    {
        $removed = self::store()->purgeStaleAll();
        $limit = time() - ChunkedUploadStore::STALE_SECONDS;
        foreach (glob(RuntimePaths::storage('money-s3-batch') . '/*/filings/*.xml') ?: [] as $path) {
            if ((int) @filemtime($path) < $limit) {
                @unlink($path);
                @unlink(substr($path, 0, -4) . '.json');
                $removed++;
            }
        }
        return $removed;
    }
}
