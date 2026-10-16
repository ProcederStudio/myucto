<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Migration\Shared\MigrationUploadLimits;
use MyInvoice\Service\Migration\Shared\ChunkedUploadStore;
use MyInvoice\Service\Migration\Shared\ChunkedUploadMessages;
use Psr\Http\Message\StreamInterface;

/** Přechodné šifrované archivy jsou oddělené podle cílové firmy a náhodného tokenu. */
final class StereoNxUploads
{
    public const CHUNK_BYTES = MigrationUploadLimits::CHUNK_BYTES;
    public const MAX_BYTES = MigrationUploadLimits::STEREO_NX_MAX_BYTES;
    private const MAX_ACTIVE = MigrationUploadLimits::MAX_ACTIVE_UPLOADS;
    private const RESULT_PATTERN = '/^result-[1-9]\d*\.json(\.tmp)?$/D';

    /** Zachovává rozložení už nahraných záloh; zápis částí spravuje společné úložiště. */
    public static function store(): ChunkedUploadStore
    {
        return new ChunkedUploadStore('stereo-nx', 'backup.zip', 'data',
            static fn (string $code, string $message): StereoNxException => new StereoNxException(
                match ($code) { 'chunk_exceeds_size' => 'chunk_too_large', 'upload_not_uploading' => 'upload_finished', default => $code }, $message),
            ChunkedUploadMessages::backup(), ['result-*.json'], 16, 'state.json', 'lock');
    }

    public static function token(): string { return self::store()->newToken(); }
    public static function dir(int $supplierId, string $token): string { return self::store()->dir($supplierId, $token); }
    public static function archive(int $supplierId, string $token): string { return self::store()->partPath($supplierId, $token); }

    /** @return list<array{token:string,filename:string,size:int,received:int,complete:bool,created_at:int}> */
    public static function listForUser(int $supplierId, int $userId): array
    {
        if ($supplierId < 1 || $userId < 1) return [];
        $root = RuntimePaths::storage();
        $uploadsRoot = $root . '/stereo-nx';
        $base = $uploadsRoot . '/' . $supplierId;
        if (is_link($root) || is_link($uploadsRoot) || is_link($base) || !is_dir($base)) return [];

        $uploads = [];
        foreach (scandir($base) ?: [] as $token) {
            if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) continue;
            $dir = $base . '/' . $token;
            $statePath = $dir . '/state.json';
            $archive = $dir . '/backup.zip';
            if (is_link($dir) || !is_dir($dir) || is_link($statePath) || !is_file($statePath)
                || is_link($archive) || filesize($statePath) > 65536) continue;
            $state = json_decode((string) file_get_contents($statePath), true);
            if (!is_array($state) || (int) ($state['uploaded_by'] ?? 0) !== $userId
                || !in_array($state['status'] ?? null, ['uploading', 'ready'], true)) continue;
            $size = (int) ($state['size'] ?? 0);
            if ($size < 1 || $size > self::MAX_BYTES) continue;
            $uploads[] = [
                'token' => $token,
                'filename' => basename(str_replace('\\', '/', (string) ($state['file_name'] ?? ''))),
                'size' => $size,
                'received' => max(0, min($size, (int) ($state['received'] ?? 0))),
                'complete' => ($state['status'] === 'ready' && is_file($archive) && filesize($archive) === $size),
                'created_at' => max(0, (int) ($state['created_at'] ?? 0)),
            ];
        }
        usort($uploads, static fn (array $a, array $b): int => $b['created_at'] <=> $a['created_at'] ?: strcmp($b['token'], $a['token']));
        return $uploads;
    }

    public static function prepareRoom(int $supplierId): bool
    {
        $base = RuntimePaths::storage('stereo-nx/' . $supplierId);
        $active = 0;
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $token = basename($dir);
            if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) continue;
            $statePath = $dir . '/state.json';
            $state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : null;
            if (!is_array($state) || (int) ($state['created_at'] ?? 0) < time() - ChunkedUploadStore::STALE_SECONDS) {
                $handle = @fopen($dir . '/lock', 'c');
                if ($handle !== false && flock($handle, LOCK_EX | LOCK_NB)) {
                    if (is_array($state)) {
                        $state['status'] = 'deleting';
                        self::save($supplierId, $token, $state);
                    }
                    flock($handle, LOCK_UN);
                    fclose($handle);
                    self::delete($supplierId, $token);
                } else {
                    if ($handle !== false) fclose($handle);
                    $active++;
                }
            } else {
                $active++;
            }
        }
        return $active < self::MAX_ACTIVE;
    }

    /** Denní úklid záloh všech firem, se kterými 24 hodin nikdo nepracoval. */
    public static function purgeStaleAll(): int
    {
        return self::store()->purgeStaleAll();
    }

    public static function delete(int $supplierId, string $token): void
    {
        $dir = self::dir($supplierId, $token);
        if (!is_dir($dir)) return;
        $names = ['backup.zip', 'state.json', 'state.json.tmp', 'lock'];
        foreach (scandir($dir) ?: [] as $name) {
            if (preg_match(self::RESULT_PATTERN, $name) === 1) $names[] = $name;
        }
        foreach ($names as $name) {
            $path = $dir . '/' . $name;
            if (is_file($path)) @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Výsledek (report) převodu, který běžel jako job na pozadí. Leží u zálohy, dokud
     * ji uživatel nebo úklid nesmaže; průvodce si ho po doběhnutí jobu stáhne.
     *
     * @param array<string,mixed> $report
     */
    public static function saveResult(int $supplierId, string $token, int $jobId, array $report): void
    {
        $path = self::dir($supplierId, $token) . '/result-' . $jobId . '.json';
        $temp = $path . '.tmp';
        $json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false || file_put_contents($temp, $json, LOCK_EX) === false || !rename($temp, $path)) {
            @unlink($temp);
            throw new StereoNxException('storage_error', 'Výsledek převodu nelze uložit.');
        }
    }

    /** @return array<string,mixed>|null null = job ještě nedoběhl nebo výsledek neuložil */
    public static function result(int $supplierId, string $token, int $jobId): ?array
    {
        $path = self::dir($supplierId, $token) . '/result-' . $jobId . '.json';
        if ($jobId < 1 || !is_file($path) || is_link($path)) return null;
        $report = json_decode((string) file_get_contents($path), true);
        return is_array($report) ? $report : null;
    }

    /** @return array<string,mixed> */
    public static function state(int $supplierId, string $token): array
    {
        return self::store()->state($supplierId, $token)
            ?? throw new StereoNxException('upload_not_found', 'Nahraná záloha nebyla nalezena.');
    }

    public static function save(int $supplierId, string $token, array $state): void
    {
        self::store()->writeState($supplierId, $token, $state);
    }

    /** @template T @param callable(array<string,mixed>):T $callback @return T */
    public static function locked(int $supplierId, string $token, callable $callback): mixed
    {
        return self::store()->withUploadLock($supplierId, $token,
            static fn () => $callback(self::state($supplierId, $token)));
    }

    public static function append(int $supplierId, string $token, int $offset, StreamInterface $stream): int
    {
        return self::store()->appendChunk($supplierId, $token, $offset, $stream, self::CHUNK_BYTES);
    }
}
