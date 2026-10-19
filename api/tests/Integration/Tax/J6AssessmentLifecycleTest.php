<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentConfirmationService;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentDraftQueryService;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentDraftService;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentReadService;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6DraftContext;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;

final class J6AssessmentLifecycleTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertSame((string) getenv('MYINVOICE_DB_NAME'), (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testConfirmPublishesAndHistoricallyRecomputesTheWholePagedGraph(): void
    {
        $container = Bootstrap::buildContainer();
        $seed = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $supplier = $this->createIsolatedSupplier($this->pdo, $seed);
        $actor = (int) $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->prepare("UPDATE supplier SET accounting_mode='double_entry' WHERE id=?")->execute([$supplier]);
        $period = (new AccountingPeriodRepository($this->db))->create($supplier, 2026, '2026-01-01', '2026-12-31');
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework)
            VALUES(?,'2026-01-01','cz_504')")->execute([$supplier]);
        (new ChartOfAccountsSeeder($this->db))->seedForSupplier($supplier, '2026-01-01');

        $draft = $container->get(J6AssessmentDraftService::class)->openCurrent($supplier, $period,
            ['kind' => 'narrow_vpp', 'evidence' => 'Syntetické stanovy'], null, $actor);
        self::assertIsInt($draft['source_manifest_id']);
        $context = new J6DraftContext($supplier, $period, $draft['id'], $draft['revision_no'], $actor, $draft['source_input_hash']);
        $completion = $container->get(J6AssessmentConfirmationService::class)->confirm($context,
            'synthetic-j6-lifecycle-' . $supplier, 'Syntetické potvrzení', null);

        $draftSource = $container->get(J6AssessmentDraftQueryService::class)->sourcePage($context);
        self::assertSame($draft['source_manifest_id'], $draftSource['draft']['source_manifest_id']);
        self::assertSame([], $draftSource['rows']);
        try {
            $container->get(J6AssessmentConfirmationService::class)->confirm($context,
                'synthetic-j6-stale-head-' . $supplier, 'Syntetický zastaralý CAS', null);
            self::fail('Confirm must reject a stale confirmed-assessment head.');
        } catch (NonprofitTaxAssessmentException $failure) {
            self::assertSame('nonprofit_tax_assessment_changed', $failure->errorCode);
        }
        self::assertSame('confirmed', $completion->status);
        self::assertSame(0, $completion->receipt->summary['source_row_count']);
        self::assertEqualsCanonicalizing(['funding_reference', 'funding_history'], array_keys($completion->receipt->evidenceManifestIds));
        $read = $container->get(J6AssessmentReadService::class);
        self::assertSame(0, $read->summary($supplier, $period, $completion->assessmentId)['summary']['source_row_count']);
        self::assertSame(0, $read->verify($supplier, $period, $completion->assessmentId)->counts['result']);
        self::assertSame([], $read->sourcePage($supplier, $period, $completion->assessmentId)['rows']);
    }
}


