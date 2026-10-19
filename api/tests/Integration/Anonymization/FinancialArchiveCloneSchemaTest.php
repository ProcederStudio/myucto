<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Anonymization;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use PDO;
use PHPUnit\Framework\TestCase;

final class FinancialArchiveCloneSchemaTest extends TestCase
{
    private PDO $pdo;

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
        self::assertSame($database, $this->pdo->query('SELECT DATABASE()')->fetchColumn());
    }

    public function testArchiveLookupsAndProvenanceHaveTenantLeadingIndexes(): void
    {
        self::assertSame(
            ['supplier_id', 'xml_sha256', 'id'],
            $this->indexColumns('income_tax_return_snapshots', 'idx_itr_snapshot_supplier_xml'),
        );
        self::assertSame(
            ['supplier_id', 'form_code', 'period_year', 'xml_sha256', 'id'],
            $this->indexColumns('tax_submissions', 'idx_tax_submission_archive_xml'),
        );
        self::assertSame(
            ['supplier_id', 'tax_submission_id'],
            $this->indexColumns('anonymized_clone_submission_provenance', 'uq_acsp_submission'),
        );
        self::assertSame(
            ['supplier_id', 'source_xml_sha256', 'tax_submission_id'],
            $this->indexColumns('anonymized_clone_submission_provenance', 'idx_acsp_source_xml'),
        );
    }

    /** @return list<string> */
    private function indexColumns(string $table, string $index): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX',
        );
        $statement->execute([$table, $index]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }
}
