<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Anonymization;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
#[Group('performance')]
final class CanonicalJsonCompositeMemoryTest extends TestCase
{
    public function testOneHundredThousandAndOneEmbeddedRowsStayBounded(): void
    {
        $command = [
            PHP_BINARY,
            '-d',
            'memory_limit=64M',
            __DIR__ . '/../../Support/CanonicalJsonCompositeWorker.php',
            '100001',
        ];
        $pipes = [];
        $environment = getenv();
        self::assertIsArray($environment);
        self::assertIsString($environment['MYINVOICE_DB_NAME'] ?? null);
        self::assertStringEndsWith('_test', $environment['MYINVOICE_DB_NAME']);
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__, 3), $environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertSame(0, $exit, $stderr ?: $stdout);
        $result = json_decode((string) $stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(100001, $result['rows']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $result['sha256']);
        self::assertGreaterThan(100001, $result['nodes']);
        self::assertLessThanOrEqual(16 * 1024 * 1024, $result['peak_delta_bytes'], (string) $stdout);
    }
}
