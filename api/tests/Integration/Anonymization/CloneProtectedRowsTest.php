<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Anonymization;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Anonymization\CloneProtectedRows;
use MyInvoice\Service\Anonymization\FinancialArchiveProtectedTable;
use PDO;
use PHPUnit\Framework\TestCase;

final class CloneProtectedRowsTest extends TestCase
{
    private PDO $pdo;

    private ?CloneProtectedRows $protected = null;

    /** @var list<int> */
    private array $submissionIds = [];

    protected function setUp(): void
    {
        $config = Config::load(Bootstrap::rootDir());
        self::assertContains($config->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        $database = (string) $config->get('db.name');
        self::assertStringEndsWith('_test', $database);
        self::assertSame(getenv('MYINVOICE_DB_NAME'), $database);
        $this->pdo = new PDO(
            'mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306) . ';dbname=' . $database . ';charset=utf8mb4',
            (string) $config->get('db.user'),
            (string) $config->get('db.pass', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
    }

    protected function tearDown(): void
    {
        if ($this->protected !== null) {
            $this->protected->drop();
        }
        if ($this->submissionIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->submissionIds), '?'));
            $this->pdo->prepare("DELETE FROM tax_submissions WHERE id IN ({$placeholders})")->execute($this->submissionIds);
        }
    }

    public function testExactProtectedIdsAreExcludedFromBoundedKeysetPages(): void
    {
        $supplier = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $supplier);
        $insert = $this->pdo->prepare(
            "INSERT INTO tax_submissions (supplier_id,form_code,period_year,xml_content,xml_size_bytes,xml_sha256,validation_status) VALUES (?, 'dppdp9', 2099, ?, ?, ?, 'passed')",
        );
        foreach (['<a>one</a>', '<a>two</a>', '<a>three</a>'] as $xml) {
            $insert->execute([$supplier, $xml, strlen($xml), hash('sha256', $xml)]);
            $this->submissionIds[] = (int) $this->pdo->lastInsertId();
        }
        $this->protected = CloneProtectedRows::create($this->pdo);
        $this->protected->protect(FinancialArchiveProtectedTable::TAX_SUBMISSION, $this->submissionIds[1]);

        $first = $this->protected->selectUnprotectedPage(
            FinancialArchiveProtectedTable::TAX_SUBMISSION,
            ['id', 'xml_sha256'],
            null,
            1,
        );
        $second = $this->protected->selectUnprotectedPage(
            FinancialArchiveProtectedTable::TAX_SUBMISSION,
            ['id', 'xml_sha256'],
            (int) $first[0]['id'],
            2,
        );

        self::assertSame($this->submissionIds[0], (int) $first[0]['id']);
        self::assertSame([$this->submissionIds[2]], array_map(static fn (array $row): int => (int) $row['id'], $second));
        self::assertSame(1, $this->protected->count(FinancialArchiveProtectedTable::TAX_SUBMISSION));
    }

    public function testProtectingTheSameExactIdAcrossCapturePagesIsIdempotent(): void
    {
        $this->protected = CloneProtectedRows::create($this->pdo);

        $this->protected->protectMany(FinancialArchiveProtectedTable::TAX_RETURN, [123, 124]);
        $this->protected->protectMany(FinancialArchiveProtectedTable::TAX_RETURN, [123]);

        self::assertSame(2, $this->protected->count(FinancialArchiveProtectedTable::TAX_RETURN));
    }

    public function testDropRequiresItsExactStageToken(): void
    {
        $this->protected = CloneProtectedRows::create($this->pdo);
        $table = $this->protected->stageTable();
        $this->pdo->exec('UPDATE `' . $table . "` SET run_token = REPEAT('f', 64) WHERE table_name = '__run'");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('vlastnictví staging tabulky');
        try {
            $this->protected->drop();
        } finally {
            $this->pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
            $this->protected = null;
        }
    }
}
