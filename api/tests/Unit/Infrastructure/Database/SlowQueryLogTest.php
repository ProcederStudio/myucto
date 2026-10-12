<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Database;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use MyInvoice\Infrastructure\Database\LoggingPdo;
use MyInvoice\Infrastructure\Database\SlowQueryLog;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SlowQueryLogTest extends TestCase
{
    private TestHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new TestHandler();
    }

    private function log(int $thresholdMs): SlowQueryLog
    {
        return new SlowQueryLog(new Logger('slowquery', [$this->handler]), $thresholdMs);
    }

    public function testQueryOverThresholdIsLoggedWithContext(): void
    {
        $this->log(1000)->record("SELECT *\n  FROM invoices WHERE id = ?", [5], hrtime(true) - 2_500_000_000, 'myucto');

        $records = $this->handler->getRecords();
        self::assertCount(1, $records);
        self::assertSame('Slow query', $records[0]->message);
        self::assertGreaterThanOrEqual(2500, $records[0]->context['duration_ms']);
        self::assertSame('SELECT * FROM invoices WHERE id = ?', $records[0]->context['sql']);
        self::assertSame([5], $records[0]->context['params']);
        self::assertSame('myucto', $records[0]->context['database']);
        self::assertStringEndsWith('SlowQueryLogTest.php:' . (__LINE__ - 9), $records[0]->context['caller']);
        self::assertStringStartsWith('cli ', $records[0]->context['request']);
    }

    public function testFastQueryIsNotLogged(): void
    {
        $this->log(1000)->record('SELECT 1', [], hrtime(true), 'myucto');

        self::assertSame([], $this->handler->getRecords());
    }

    public function testSensitiveParamsAreRedacted(): void
    {
        $this->log(1)->record('UPDATE users SET password_hash = ? WHERE id = ?', ['hash', 1], hrtime(true) - 10_000_000, 'myucto');

        self::assertSame(
            ['__redacted__' => '*** params hidden (sensitive column referenced) ***'],
            $this->handler->getRecords()[0]->context['params'],
        );
    }

    public function testZeroThresholdDisablesLogging(): void
    {
        self::assertFalse($this->log(0)->enabled());
    }

    public function testLoggingPdoMeasuresPreparedStatementsQueriesAndExec(): void
    {
        $pdo = new LoggingPdo('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION], new NullLogger(), null, $this->log(1));
        $slow = 'WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 300000) SELECT COUNT(*) FROM n';

        // CAST: execute() váže parametry jako text a v SQLite je číslo < text vždy pravda.
        $pdo->prepare(str_replace('300000', 'CAST(? AS INTEGER)', $slow))->execute([300000]);
        $pdo->query($slow);
        $pdo->exec($slow . ' WHERE 2 = 2');

        $sqls = array_map(static fn ($r) => $r->context['sql'], $this->handler->getRecords());
        self::assertCount(3, $sqls);
        self::assertStringContainsString('i < CAST(? AS INTEGER)', $sqls[0]);
        self::assertStringEndsWith('WHERE 2 = 2', $sqls[2]);
    }
}
