<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Service\Migration\Shared\ChunkedUploadMessages;
use MyInvoice\Service\Migration\Shared\ChunkedUploadStore;
use Psr\Http\Message\StreamInterface;

/**
 * Nahrané zálohy agend čekající na převod: `storage/money-s3/<firma>/<token>/`.
 *
 * Záloha se nahraje jednou, rozbalí se a průvodce nad ní pouští náhled, zkoušku
 * nanečisto i ostrý převod. Po úspěšném ostrém převodu se adresář smaže. Záloha po
 * zkoušce nanečisto nebo po neúspěšném převodu zůstává pro další běh a denní úklid ji
 * smaže po 24 hodinách bez práce s ní ({@see purgeStaleAll()}).
 *
 * Velká záloha přichází po částech do `backup.lz.part` a job na pozadí z ní rozbalí
 * agendu do `agenda/`. Uspořádání, zámky a úklid jsou společné s ostatními převody
 * ({@see ChunkedUploadStore}); navíc tu leží sestavy z Money k rekonciliaci (`reports/`).
 */
final class MoneyS3Uploads
{
    public const TOKEN_PATTERN = ChunkedUploadStore::TOKEN_PATTERN;
    public const STATUS_UPLOADING = ChunkedUploadStore::STATUS_UPLOADING;
    public const STATUS_PROCESSING = ChunkedUploadStore::STATUS_PROCESSING;
    public const STATUS_READY = ChunkedUploadStore::STATUS_READY;
    public const STATUS_FAILED = ChunkedUploadStore::STATUS_FAILED;

    private static ?ChunkedUploadStore $store = null;

    public static function store(): ChunkedUploadStore
    {
        return self::$store ??= new ChunkedUploadStore(
            'money-s3',
            'backup.lz.part',
            'agenda',
            static fn (string $code, string $message, array $context, int $status): MoneyS3Exception => new MoneyS3Exception($code, $message, $context, $status),
            ChunkedUploadMessages::backup(),
            // Připojená sestava z Money je práce se zálohou.
            ['reports/*.csv'],
        );
    }

    public static function base(int $supplierId): string
    {
        return self::store()->base($supplierId);
    }

    public static function newToken(): string
    {
        return self::store()->newToken();
    }

    public static function dir(int $supplierId, string $token): string
    {
        return self::store()->dir($supplierId, $token);
    }

    public static function agendaDir(int $supplierId, string $token): string
    {
        return self::store()->dataDir($supplierId, $token);
    }

    /** @return array<string,mixed> */
    public static function meta(int $supplierId, string $token): array
    {
        return self::store()->meta($supplierId, $token);
    }

    /** @param array<string,mixed> $meta */
    public static function writeMeta(int $supplierId, string $token, array $meta): void
    {
        self::store()->writeMeta($supplierId, $token, $meta);
    }

    public static function hasMeta(int $supplierId, string $token): bool
    {
        return self::store()->hasMeta($supplierId, $token);
    }

    /**
     * Stav nahrávání po částech a zpracování na pozadí, nebo null u zálohy nahrané
     * jedním požadavkem (ta má rovnou `meta.json`).
     *
     * @return array<string,mixed>|null
     */
    public static function state(int $supplierId, string $token): ?array
    {
        return self::store()->state($supplierId, $token);
    }

    /** @param array<string,mixed> $state */
    public static function writeState(int $supplierId, string $token, array $state): void
    {
        self::store()->writeState($supplierId, $token, $state);
    }

    /**
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    public static function updateState(int $supplierId, string $token, array $changes): array
    {
        return self::store()->updateState($supplierId, $token, $changes);
    }

    public static function partPath(int $supplierId, string $token): string
    {
        return self::store()->partPath($supplierId, $token);
    }

    public static function partSize(int $supplierId, string $token): int
    {
        return self::store()->partSize($supplierId, $token);
    }

    /** @return int kolik bajtů zálohy server po připojení drží */
    public static function appendChunk(int $supplierId, string $token, int $offset, StreamInterface $chunk, int $maxChunkBytes): int
    {
        return self::store()->appendChunk($supplierId, $token, $offset, $chunk, $maxChunkBytes);
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function withUploadLock(int $supplierId, string $token, callable $fn): mixed
    {
        return self::store()->withUploadLock($supplierId, $token, $fn);
    }

    /** @return resource|null null = zálohu už zpracovává jiný proces */
    public static function acquireJobLock(int $supplierId, string $token)
    {
        return self::store()->acquireJobLock($supplierId, $token);
    }

    /** @param resource $handle */
    public static function releaseJobLock($handle): void
    {
        self::store()->releaseJobLock($handle);
    }

    public static function discardData(int $supplierId, string $token): void
    {
        self::store()->discardData($supplierId, $token);
    }

    public static function reportPath(int $supplierId, string $token, int $year): string
    {
        return self::dir($supplierId, $token) . '/reports/' . $year . '.csv';
    }

    /** @return array<int,string> rok => cesta k předvaze z Money */
    public static function reports(int $supplierId, string $token): array
    {
        $out = [];
        foreach (glob(self::dir($supplierId, $token) . '/reports/*.csv') ?: [] as $path) {
            $year = (int) basename($path, '.csv');
            if ($year >= 1990 && $year <= 2100) {
                $out[$year] = $path;
            }
        }
        ksort($out);
        return $out;
    }

    public static function purge(int $supplierId, string $token): void
    {
        self::store()->purge($supplierId, $token);
    }

    /** @return int kolik nahraných záloh firmy se smazalo */
    public static function purgeStale(int $supplierId): int
    {
        return self::store()->purgeStale($supplierId);
    }

    public static function makeRoom(int $supplierId, int $max): bool
    {
        return self::store()->makeRoom($supplierId, $max);
    }

    /** Denní úklid ({@see api/bin/cron-cleanup.php}) nahraných záloh všech firem. */
    public static function purgeStaleAll(): int
    {
        return self::store()->purgeStaleAll();
    }
}
