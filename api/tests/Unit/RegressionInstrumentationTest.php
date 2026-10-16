<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit;

use MyUctoRegression\Network;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/tools/regression/bootstrap.php';

final class RegressionInstrumentationTest extends TestCase
{
    public function testIsolatedConfigPreservesInstanceOptionsAndCredentialsInMemory(): void
    {
        self::assertSame(array_fill(0, 8, true), self::probe('config-snapshot')['preserved']);
    }

    public function testOriginalAndGuardedSqliteSelectNullAndTransactionAreIdentical(): void
    {
        $original = self::probe('sqlite-original');
        $guarded = self::probe('sqlite-guarded');
        self::assertSame($original, $guarded);
        self::assertSame('12345678901234567890.1234567890', $guarded['rows'][0]['amount']);
        self::assertNull($guarded['rows'][0]['nullable']);
        self::assertFalse($guarded['transaction']);
    }

    public function testCaughtCurlAndSuppressedStreamAttemptsRemainVisible(): void
    {
        self::assertSame(['network_or_process_attempt', 'network_stream_attempt'], self::probe('network')['violations']);
    }

    public function testUnknownConnectionSourceIsRejectedBeforeConnecting(): void
    {
        self::assertTrue(self::probe('unknown-signature')['rejected']);
    }

    public static function probe(string $mode, string $database = ''): array
    {
        $command = [PHP_BINARY, '-d', 'display_errors=0', '-d', 'disable_functions=' . implode(',', Network::FUNCTIONS), dirname(__DIR__) . '/Fixtures/regression-probe.php', $mode, $database];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertSame('', $stderr, 'Probe must not emit raw errors.');
        self::assertSame(0, $exit, 'Probe failed with sanitized output: ' . $stdout);
        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    }
}
