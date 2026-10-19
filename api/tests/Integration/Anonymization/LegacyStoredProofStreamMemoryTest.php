<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Anonymization;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
#[Group('performance')]
final class LegacyStoredProofStreamMemoryTest extends TestCase
{
    public function testOneHundredThousandAndOneRowsStayBounded(): void
    {
        $result = $this->worker(100_001, 'valid');

        self::assertFalse($result['rejected'], (string) ($result['error'] ?? ''));
        self::assertSame(100_001, $result['rows']);
        self::assertSame('v3', $result['profile']);
        self::assertLessThanOrEqual(32 * 1024 * 1024, $result['peak_delta_bytes'], json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testMutatedFinalBridgeRowIsRejectedWithoutMaterializingGraph(): void
    {
        $result = $this->worker(1_001, 'mutant');

        self::assertTrue($result['rejected']);
        self::assertSame('Legacy StoredProof bridge row nesouhlasí se zdrojem.', $result['error']);
        self::assertLessThanOrEqual(32 * 1024 * 1024, $result['peak_delta_bytes'], json_encode($result, JSON_THROW_ON_ERROR));
    }

    private function worker(int $rows, string $mode): array
    {
        $command = [
            PHP_BINARY,
            '-d',
            'memory_limit=64M',
            __DIR__ . '/../../Support/LegacyStoredProofStreamWorker.php',
            (string) $rows,
            $mode,
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
        $result = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        return $result;
    }
}
