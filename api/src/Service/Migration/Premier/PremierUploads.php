<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Service\Migration\Shared\ChunkedUploadMessages;
use MyInvoice\Service\Migration\Shared\ChunkedUploadStore;
use Psr\Http\Message\StreamInterface;

/**
 * Nahrané zálohy dat z PREMIER čekající na převod: `storage/premier/<firma>/<token>/`.
 *
 * Stejné uspořádání jako u POHODY ({@see ChunkedUploadStore}): záloha přichází po částech
 * do `backup.part` (stav v `upload.json`), job na pozadí ji rozbalí do `backup/` a zapíše
 * `meta.json`.
 *
 * Na rozdíl od POHODY drží záloha VŠECHNY účetní roky najednou (jde o databázi Visual
 * FoxPro), takže se po úspěšném ostrém převodu jednoho roku nemaže - uživatel v ní
 * postupně převádí rok po roku. Uklidí ji až denní úklid po 24 hodinách bez práce s ní.
 */
final class PremierUploads
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
            'premier',
            'backup.part',
            'backup',
            static fn (string $code, string $message, array $context, int $status): PremierException => new PremierException($code, $message, $context, $status),
            ChunkedUploadMessages::backup(),
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

    public static function backupDir(int $supplierId, string $token): string
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

    /** @return array<string,mixed>|null */
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

    /** Zahodí data nepovedeného zpracování, stav s chybou nechá pro průvodce. */
    public static function discardData(int $supplierId, string $token): void
    {
        self::store()->discardData($supplierId, $token);
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

    /** Průvodce se zálohou pracuje (spouští převod) - denní úklid ji zatím nesmaže. */
    public static function touch(int $supplierId, string $token): void
    {
        self::store()->touch($supplierId, $token);
    }

    /** Denní úklid ({@see api/bin/cron-cleanup.php}) nahraných záloh všech firem. */
    public static function purgeStaleAll(): int
    {
        return self::store()->purgeStaleAll();
    }
}
