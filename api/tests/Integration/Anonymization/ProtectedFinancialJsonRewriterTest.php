<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Anonymization;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Anonymization\NonprofitArchiveJsonScalarTransformer;
use MyInvoice\Service\Anonymization\Pseudonymizer;
use MyInvoice\Service\Anonymization\ProtectedFinancialJsonRewriter;
use MyInvoice\Service\Anonymization\Streaming\FinancialArchiveJsonColumn;
use MyInvoice\Service\Anonymization\TextScrubber;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProtectedFinancialJsonRewriterTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private string $sourceJson;

    protected function setUp(): void
    {
        $this->db = Connection::withoutSharedTestConnection(static fn (): Connection => Bootstrap::buildContainer()->get(Connection::class));
        $this->pdo = $this->db->pdo();
        self::assertSame(getenv('MYINVOICE_DB_NAME'), $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS nonprofit_tax_assessments');
        $this->pdo->exec('CREATE TEMPORARY TABLE nonprofit_tax_assessments (
            id BIGINT UNSIGNED PRIMARY KEY,supplier_id BIGINT UNSIGNED NOT NULL,
            snapshot_json LONGTEXT NOT NULL,assessment_json LONGTEXT NOT NULL,result_json LONGTEXT NOT NULL,
            UNIQUE KEY uq_test_tenant(supplier_id,id)
        ) ENGINE=InnoDB');
        $rows = [];
        for ($index = 0; $index < 1001; $index++) {
            $rows[] = [
                'company_name' => 'Protected Original ' . $index . ' s.r.o.',
                'email' => 'protected' . $index . '@example.test',
                'note' => str_repeat('Příliš žluťoučký syntetický text ', 20),
                'amount_cents' => '50010',
                'date' => '2099-12-31',
            ];
        }
        $this->sourceJson = json_encode(['rows' => $rows], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->pdo->prepare("INSERT INTO nonprofit_tax_assessments VALUES (1,11,?,'{}','{}')")
            ->execute([$this->sourceJson]);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS nonprofit_tax_assessments');
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testRewriteUsesExactTenantPhysicalHashAndRollsBackWithOwnerTransaction(): void
    {
        $sourceHash = hash('sha256', $this->sourceJson);
        $this->pdo->beginTransaction();
        $result = (new ProtectedFinancialJsonRewriter($this->db))->rewrite(
            FinancialArchiveJsonColumn::ASSESSMENT_SNAPSHOT,
            11,
            1,
            $sourceHash,
            new NonprofitArchiveJsonScalarTransformer(
                new TextScrubber(new Pseudonymizer(hash('sha256', 'protected-json-secret', true))),
            ),
        );

        $rewritten = (string) $this->pdo->query('SELECT snapshot_json FROM nonprofit_tax_assessments WHERE id=1')->fetchColumn();
        self::assertSame($sourceHash, $result->sourceSha256);
        self::assertSame(hash('sha256', $rewritten), $result->targetSha256);
        self::assertNotSame($sourceHash, $result->targetSha256);
        self::assertStringNotContainsString('Protected Original', $rewritten);
        self::assertStringNotContainsString('@example.test', $rewritten);
        self::assertStringContainsString('"amount_cents":"50010"', $rewritten);
        self::assertStringContainsString('"date":"2099-12-31"', $rewritten);
        self::assertTrue($this->pdo->inTransaction());

        $this->pdo->rollBack();
        self::assertSame($this->sourceJson, $this->pdo->query('SELECT snapshot_json FROM nonprofit_tax_assessments WHERE id=1')->fetchColumn());
    }

    public function testRewriteRejectsStaleOrForeignPhysicalSource(): void
    {
        $rewriter = new ProtectedFinancialJsonRewriter($this->db);
        $transformer = new NonprofitArchiveJsonScalarTransformer(
            new TextScrubber(new Pseudonymizer(hash('sha256', 'protected-json-secret', true))),
        );
        $this->pdo->beginTransaction();
        try {
            $rewriter->rewrite(FinancialArchiveJsonColumn::ASSESSMENT_SNAPSHOT, 11, 1, str_repeat('0', 64), $transformer);
            self::fail('Stale physical source hash was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('fyzický otisk', $exception->getMessage());
        }
        try {
            $rewriter->rewrite(FinancialArchiveJsonColumn::ASSESSMENT_SNAPSHOT, 12, 1, hash('sha256', $this->sourceJson), $transformer);
            self::fail('Foreign tenant source was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('nebyl nalezen', $exception->getMessage());
        }
    }
}
