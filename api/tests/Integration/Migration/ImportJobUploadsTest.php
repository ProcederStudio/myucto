<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3BatchJobService;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3BatchUploads;
use MyInvoice\Service\Migration\Pohoda\PohodaImportJobService;
use MyInvoice\Service\Migration\Pohoda\PohodaUploads;
use MyInvoice\Service\Migration\Shared\ImportJobUploads;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Smazání jobu převodu smaže nahraný a rozbalený soubor, na který už žádný jiný job
 * neodkazuje; soubor, se kterým pracuje běžící job, nechá. Transakce s rollbackem v tearDown.
 */
#[Group('integration')]
final class ImportJobUploadsTest extends TestCase
{
    private Connection $db;
    private ImportJobRepository $jobs;
    private int $supplierId = 0;
    private int $userId = 0;
    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->jobs = $container->get(ImportJobRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí dodavatel nebo uživatel.');
        }
        $pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        foreach ($this->dirs as $dir) {
            @unlink($dir . '/export/01_ucetni_denik.xml');
            @rmdir($dir . '/export');
            @unlink($dir . '/meta.json');
            @unlink($dir . '/job.lock');
            @rmdir($dir);
        }
    }

    public function testDeletingLastJobPurgesExtractedExport(): void
    {
        $token = $this->upload(PohodaUploads::newToken());
        $prepare = $this->job(PohodaImportJobService::SOURCE, ['token' => $token, 'mode' => 'prepare']);
        $dryRun = $this->job(PohodaImportJobService::SOURCE, ['token' => $token, 'mode' => 'dry_run']);

        self::assertSame(0, $this->delete($prepare));
        self::assertDirectoryExists(PohodaUploads::dir($this->supplierId, $token), 'Na export odkazuje zkouška nanečisto.');

        self::assertSame(1, $this->delete($dryRun));
        self::assertDirectoryDoesNotExist(PohodaUploads::dir($this->supplierId, $token));
    }

    public function testUploadUsedByRunningJobStays(): void
    {
        $token = $this->upload(PohodaUploads::newToken());
        $job = $this->job(PohodaImportJobService::SOURCE, ['token' => $token, 'mode' => 'prepare']);
        $lock = PohodaUploads::acquireJobLock($this->supplierId, $token);
        self::assertNotNull($lock);
        try {
            self::assertSame(0, $this->delete($job));
            self::assertDirectoryExists(PohodaUploads::dir($this->supplierId, $token));
        } finally {
            PohodaUploads::releaseJobLock($lock);
        }
    }

    public function testBatchJobPurgesItemUploads(): void
    {
        $store = MoneyS3BatchUploads::store();
        $a = $this->upload($store->newToken(), $store->dir(...));
        $b = $this->upload($store->newToken(), $store->dir(...));
        $job = $this->job(MoneyS3BatchJobService::SOURCE, ['mode' => 'import', 'items' => [['token' => $a], ['token' => $b]]]);

        self::assertSame(2, $this->delete($job));
        self::assertDirectoryDoesNotExist($store->dir($this->supplierId, $a));
        self::assertDirectoryDoesNotExist($store->dir($this->supplierId, $b));
    }

    /** @param (callable(int,string):string)|null $dir */
    private function upload(string $token, ?callable $dir = null): string
    {
        $path = ($dir ?? PohodaUploads::dir(...))($this->supplierId, $token);
        $this->dirs[] = $path;
        self::assertTrue(@mkdir($path . '/export', 0777, true));
        file_put_contents($path . '/export/01_ucetni_denik.xml', '<dataPack/>');
        file_put_contents($path . '/meta.json', '{}');
        return $token;
    }

    /** @param array<string,mixed> $params */
    private function job(string $source, array $params): int
    {
        return $this->jobs->create($this->supplierId, $source, $params, $this->userId);
    }

    private function delete(int $jobId): int
    {
        $job = $this->jobs->find($jobId, $this->supplierId);
        self::assertNotNull($job);
        self::assertTrue($this->jobs->delete($jobId, $this->supplierId));
        return ImportJobUploads::purgeOrphaned($this->jobs, $job);
    }
}
