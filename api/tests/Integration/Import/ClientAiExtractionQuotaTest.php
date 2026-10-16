<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Service\Import\ClientAiExtractionQuota;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class ClientAiExtractionQuotaTest extends StockTestCase
{
    public function testQuotaIsSupplierScopedResetsByMonthAndPreservesHistory(): void
    {
        $supplier = $this->createSupplier();
        $other = $this->createSupplier();
        $this->db->pdo()->prepare('UPDATE supplier SET client_ai_extraction_monthly_limit = 1 WHERE id = ?')->execute([$supplier]);
        $quota = $this->container->get(ClientAiExtractionQuota::class);
        $september = new \DateTimeImmutable('2026-09-30 23:59:59 Europe/Prague');
        $october = new \DateTimeImmutable('2026-10-01 00:00:00 Europe/Prague');
        self::assertSame(['period' => '2026-09', 'limit' => 1, 'used' => 1, 'remaining' => 0], $quota->reserve($supplier, $september));
        self::assertNull($quota->reserve($supplier, $september));
        self::assertSame(0, $quota->usage($other, $september)['used']);
        self::assertSame(0, $quota->usage($supplier, $october)['used']);
        self::assertSame('2026-10', $quota->usage($supplier, new \DateTimeImmutable('2026-09-30 22:00:00 UTC'))['period']);
        self::assertSame(1, $quota->reserve($supplier, $october)['used']);
        self::assertSame(1, $quota->usage($supplier, $september)['used']);
        self::assertFalse($this->db->pdo()->inTransaction());
    }

    public function testZeroLimitBlocksAndLoweringLimitNeverRefundsUsage(): void
    {
        $supplier = $this->createSupplier();
        $quota = $this->container->get(ClientAiExtractionQuota::class);
        self::assertSame(100, $quota->usage($supplier)['limit']);
        self::assertNotNull($quota->reserve($supplier));
        $this->db->pdo()->prepare('UPDATE supplier SET client_ai_extraction_monthly_limit = 0 WHERE id = ?')->execute([$supplier]);
        self::assertNull($quota->reserve($supplier));
        self::assertSame(1, $quota->usage($supplier)['used']);
        self::assertSame(0, $quota->usage($supplier)['remaining']);
        $this->db->pdo()->prepare('UPDATE supplier SET client_ai_extraction_monthly_limit = 2 WHERE id = ?')->execute([$supplier]);
        self::assertNotNull($quota->reserve($supplier));
        self::assertNull($quota->reserve($supplier));
    }

    public function testConcurrentRequestsCannotOverspendMonthlyLimit(): void
    {
        $supplier = $this->createSupplier();
        $this->db->pdo()->prepare('UPDATE supplier SET client_ai_extraction_monthly_limit = 3 WHERE id = ?')->execute([$supplier]);
        $barrier = tempnam(sys_get_temp_dir(), 'client-quota-');
        self::assertIsString($barrier);
        unlink($barrier);
        $workers = [];
        try {
            for ($i = 0; $i < 8; $i++) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Support/ClientAiQuotaWorker.php', (string) $supplier, $barrier, (string) $i],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
                    dirname(__DIR__, 3), null, ['bypass_shell' => true]);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 20;
            while (count(glob($barrier . '.ready-*') ?: []) !== 8) {
                self::assertLessThan($deadline, microtime(true), 'Workers se nepřipravili k souběžné rezervaci.');
                usleep(10000);
            }
            touch($barrier);
            $accepted = 0;
            foreach ($workers as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $errors);
                $accepted += (int) $output;
            }
            $workers = [];
            self::assertSame(3, $accepted);
            self::assertSame(3, $this->container->get(ClientAiExtractionQuota::class)->usage($supplier)['used']);
        } finally {
            foreach ($workers as [$process, $pipes]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            if (is_file($barrier)) {
                unlink($barrier);
            }
            foreach (glob($barrier . '.ready-*') ?: [] as $ready) {
                unlink($ready);
            }
        }
    }
}
