<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

use Psr\Log\LoggerInterface;

/**
 * Aplikační slow log: dotaz delší než práh zapíše do vlastního denního souboru
 * (`log/slow-query-YYYY-MM-DD.log`, drží se 14 dní).
 *
 * PROČ V APLIKACI. Slow log MariaDB na sdíleném hostingu (myucto.online) má práh
 * 15 s, je společný pro všechny instance serveru a uživatel instance ho nepřečte.
 * Tady víme navíc i to, odkud dotaz přišel (caller, URL requestu / cron skript).
 *
 * Měří se jen doba execute()/query()/exec(), ne fetch. U nebufferovaných dotazů
 * by tak čas chyběl, aplikace ale používá výchozí bufferovaný režim.
 */
final class SlowQueryLog
{
    private const MAX_SQL_LENGTH = 4000;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $thresholdMs = 1000,
    ) {}

    public function enabled(): bool
    {
        return $this->thresholdMs > 0;
    }

    /** @param array<array-key,mixed> $params */
    public function record(string $sql, array $params, int $startedNs, string $database): void
    {
        $ms = (hrtime(true) - $startedNs) / 1e6;
        if ($ms < $this->thresholdMs) {
            return;
        }
        $sql = DbErrorLogger::normalizeSql($sql);
        $this->logger->warning('Slow query', [
            'duration_ms' => round($ms),
            'database'    => $database,
            'sql'         => mb_strlen($sql) > self::MAX_SQL_LENGTH ? mb_substr($sql, 0, self::MAX_SQL_LENGTH) . ' …' : $sql,
            'params'      => DbErrorLogger::redactParams($sql, $params),
            'caller'      => self::caller(),
            'request'     => self::requestLabel(),
        ]);
    }

    private static function caller(): string
    {
        $own = DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, ['src', 'Infrastructure', 'Database']) . DIRECTORY_SEPARATOR;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
            $file = (string) ($frame['file'] ?? '');
            if ($file === '' || str_contains($file, $own)) {
                continue;
            }
            return $file . ':' . (int) ($frame['line'] ?? 0);
        }
        return '';
    }

    private static function requestLabel(): string
    {
        if (PHP_SAPI === 'cli') {
            return 'cli ' . basename((string) ($_SERVER['argv'][0] ?? ''));
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        // Query string může nést tokeny (odkazy pro klienty, OAuth) — do logu jen cesta.
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '');
        return trim(($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . $path);
    }
}
