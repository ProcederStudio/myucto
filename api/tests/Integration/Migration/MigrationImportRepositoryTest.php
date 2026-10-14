<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AbstractMigrationImportRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Exception;
use MyInvoice\Service\Migration\Pohoda\PohodaException;
use MyInvoice\Service\Migration\Premier\PremierException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Evidence běhů, mapa a zámek převodu jsou jeden kód nad tabulkami zdroje
 * ({@see AbstractMigrationImportRepository}). Každý zdroj si drží vlastní sloupce běhu,
 * vlastní mapu, vlastní zámek a vlastní výjimku konfliktu.
 */
#[Group('integration')]
final class MigrationImportRepositoryTest extends TestCase
{
    private PDO $pdo;
    private Connection $db;
    private Connection $other;
    private int $supplierId;
    /** @var list<array{string,int}> */
    private array $createdRuns = [];
    /** @var list<array{string,string}> */
    private array $createdKeys = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->pdo = $this->db->pdo();
            // Druhé spojení: named lock drží spojení, ne proces.
            $this->other = Connection::withoutSharedTestConnection(static function (): Connection {
                $connection = Bootstrap::buildContainer()->get(Connection::class);
                $connection->pdo();
                return $connection;
            });
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }
        $this->supplierId = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí dodavatel.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->createdRuns as [$table, $id]) {
            $this->pdo->exec("DELETE FROM {$table} WHERE id = {$id}");
        }
        foreach ($this->createdKeys as [$table, $column]) {
            $this->pdo->prepare("DELETE FROM {$table} WHERE supplier_id = ? AND {$column} LIKE 'shared-repo-test|%'")->execute([$this->supplierId]);
        }
    }

    /** @return iterable<string,array{class-string<AbstractMigrationImportRepository>,string,string,class-string<\RuntimeException>,array<string,mixed>,array<string,mixed>}> */
    public static function sources(): iterable
    {
        yield 'money_s3' => [MoneyS3ImportRepository::class, 'money_s3_imports', 'money_s3_import_map', MoneyS3Exception::class,
            ['agenda_ico' => '12345678', 'agenda_name' => 'Syntetická agenda', 'money_version' => '19.100', 'backup_sha256' => str_repeat('a', 64)],
            ['agenda_ico' => '12345678', 'agenda_name' => 'Syntetická agenda', 'money_version' => '19.100', 'backup_sha256' => str_repeat('a', 64)]];
        yield 'pohoda' => [PohodaImportRepository::class, 'pohoda_imports', 'pohoda_import_map', PohodaException::class,
            ['ico' => '12345678', 'year' => '2024', 'program' => 'POHODA E1', 'sha256' => str_repeat('b', 64)],
            ['agenda_ico' => '12345678', 'agenda_year' => 2024, 'pohoda_version' => 'POHODA E1', 'export_sha256' => str_repeat('b', 64), 'kind' => 'accounting']];
        yield 'premier' => [PremierImportRepository::class, 'premier_imports', 'premier_import_map', PremierException::class,
            ['ico' => '12345678', 'year' => 2023, 'program' => 'PREMIER', 'sha256' => ''],
            ['agenda_ico' => '12345678', 'agenda_year' => 2023, 'program_version' => 'PREMIER', 'backup_sha256' => null, 'kind' => 'accounting']];
    }

    /**
     * @param class-string<AbstractMigrationImportRepository> $class
     * @param class-string<\RuntimeException> $exception
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $expected
     */
    #[DataProvider('sources')]
    public function testRunsKeepSourceColumns(string $class, string $runsTable, string $mapTable, string $exception, array $meta, array $expected): void
    {
        $repo = new $class($this->db);
        $id = $repo->startRun($this->supplierId, null, 'whatever', $meta, null);
        $this->createdRuns[] = [$runsTable, $id];

        $running = $repo->findRun($id, $this->supplierId);
        self::assertEquals(['mode' => 'dry_run', 'status' => 'running', 'job_id' => null, 'created_by' => null, 'protocol' => null],
            array_intersect_key($running, ['mode' => 1, 'status' => 1, 'job_id' => 1, 'created_by' => 1, 'protocol' => 1]));
        self::assertSame($id, $running['id']);

        $repo->finishRun($id, $this->supplierId, 'completed', ['status' => 'completed', 'steps' => []]);
        $listed = array_values(array_filter($repo->listRuns($this->supplierId, 100), static fn (array $r): bool => $r['id'] === $id))[0];
        self::assertSame($expected, array_intersect_key($listed, $expected));
        self::assertArrayNotHasKey('protocol', $listed, 'Přehled protokol nevrací.');
        self::assertSame(['status' => 'completed', 'steps' => []], $repo->findRun($id, $this->supplierId)['protocol']);
        self::assertNull($repo->findRun($id, $this->supplierId + 100000), 'Cizí firma běh nevidí.');

        self::assertTrue($repo->deleteDryRun($id, $this->supplierId));
        $this->createdRuns = [];
    }

    /**
     * @param class-string<AbstractMigrationImportRepository> $class
     * @param class-string<\RuntimeException> $exception
     */
    #[DataProvider('sources')]
    public function testMapRejectsDuplicateKeyWithSourceException(string $class, string $runsTable, string $mapTable, string $exception, array $meta, array $expected): void
    {
        $repo = new $class($this->db);
        $column = str_replace('_import_map', '', $mapTable) === 'money_s3' ? 'money_key' : str_replace('_import_map', '', $mapTable) . '_key';
        $this->createdKeys[] = [$mapTable, $column];

        $repo->put($this->supplierId, 'period', 'shared-repo-test|1', 11, null);
        $repo->put($this->supplierId, 'period', 'shared-repo-test|2', 12, null);
        self::assertSame(11, $repo->get($this->supplierId, 'period', 'shared-repo-test|1'));
        self::assertNull($repo->get($this->supplierId, 'client', 'shared-repo-test|1'));
        $all = array_filter($repo->all($this->supplierId, 'period'), static fn (string $k): bool => str_starts_with($k, 'shared-repo-test|'), ARRAY_FILTER_USE_KEY);
        self::assertSame(['shared-repo-test|1' => 11, 'shared-repo-test|2' => 12], $all);

        try {
            $repo->put($this->supplierId, 'period', 'shared-repo-test|1', 99, null);
            self::fail('Duplicitní klíč mapy měl selhat.');
        } catch (\RuntimeException $e) {
            self::assertInstanceOf($exception, $e);
            self::assertSame('map_conflict', $e->errorCode);
        }
        $repo->repoint($this->supplierId, 'period', 'shared-repo-test|1', 21, null);
        self::assertSame(21, $repo->get($this->supplierId, 'period', 'shared-repo-test|1'));
    }

    /**
     * Běžící převod mezd protokol ještě nemá. Druh nese úloha; bez ní by se běh vypsal jako
     * účetnictví a průvodce PAMICA by běžící zkoušku nanečisto vůbec neukázal.
     */
    public function testRunningPayrollRunIsListedAsPayroll(): void
    {
        $this->pdo->prepare("INSERT INTO import_jobs (supplier_id, source, status, params, created_by) VALUES (?, 'pohoda_import', 'running', ?, (SELECT MIN(id) FROM users))")
            ->execute([$this->supplierId, json_encode(['mode' => 'dry_run', 'kind' => 'payroll'])]);
        $jobId = (int) $this->pdo->lastInsertId();
        $repo = new PohodaImportRepository($this->db);
        try {
            $id = $repo->startRun($this->supplierId, $jobId, 'dry_run', ['ico' => '12345678', 'year' => '2026', 'program' => 'POHODA Mzdy', 'sha256' => null], null);
            $this->createdRuns[] = ['pohoda_imports', $id];
            $listed = array_values(array_filter($repo->listRuns($this->supplierId, 100), static fn (array $r): bool => $r['id'] === $id))[0];
            self::assertSame('payroll', $listed['kind']);
        } finally {
            $this->pdo->prepare('DELETE FROM import_jobs WHERE id = ?')->execute([$jobId]);
        }
    }

    public function testLocksAreSeparatePerSourceAndHeldByConnection(): void
    {
        $money = new MoneyS3ImportRepository($this->db);
        $pohoda = new PohodaImportRepository($this->db);
        $moneyOther = new MoneyS3ImportRepository($this->other);
        $pohodaOther = new PohodaImportRepository($this->other);

        self::assertTrue($money->acquireLock($this->supplierId));
        try {
            self::assertFalse($moneyOther->isLockFree($this->supplierId));
            self::assertFalse($moneyOther->acquireLock($this->supplierId), 'Druhý převod téže firmy se odmítne.');
            self::assertTrue($pohodaOther->isLockFree($this->supplierId), 'Zámek jiného zdroje je nezávislý.');
            self::assertTrue($pohoda->isLockFree($this->supplierId));
        } finally {
            $money->releaseLock($this->supplierId);
        }
        self::assertTrue($moneyOther->isLockFree($this->supplierId));
    }
}
