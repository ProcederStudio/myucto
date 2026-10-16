<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Crm;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Cache\EntityCache;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxReturnRepository;
use MyInvoice\Service\Crm\CrmAggregationService;
use MyInvoice\Service\Tax\Return\TaxRepresentationService;
use MyInvoice\Service\Tax\Return\TaxReturnService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class CrmTaxBalanceEarlyGateTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;
    private CrmAggregationService $service;
    private TaxReturnRepository $returns;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', taxpayer_type = 'po' WHERE id = ?")
            ->execute([$this->supplierId]);
        $this->returns = $container->get(TaxReturnRepository::class);
        $this->service = new CrmAggregationService(
            $this->db,
            $container->get(TaxReturnService::class),
            cache: EntityCache::disabled(),
            representation: $container->get(TaxRepresentationService::class),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testDistantCurrentYearDraftSkipsLiveTaxCalculation(): void
    {
        $this->returns->create($this->supplierId, 2026, 'po', [], null);
        $now = new \DateTimeImmutable('2026-09-23');
        $before = $this->selectCount();

        $item = $this->service->taxBalanceDueItem($this->supplierId, null, $now);

        self::assertNull($item);
        self::assertLessThanOrEqual(9, $this->selectCount() - $before);
    }

    public function testCurrentYearWithNearManualDeadlineStillShowsBalance(): void
    {
        $this->returns->create($this->supplierId, 2026, 'po', ['filing_deadline' => '2026-10-01'], null);
        $this->pdo->prepare("UPDATE income_tax_returns SET status = 'final', computed = ? WHERE supplier_id = ? AND year = 2026 AND taxpayer_type = 'po' AND variant = 'radne' AND variant_seq = 1")
            ->execute([json_encode(['computed' => ['balance_due' => 1000]], JSON_THROW_ON_ERROR), $this->supplierId]);

        $item = $this->service->taxBalanceDueItem($this->supplierId, null, new \DateTimeImmutable('2026-09-23'));

        self::assertNotNull($item);
        self::assertSame('dppo_balance_due', $item['type']);
        self::assertSame(8, $item['days']);
        self::assertSame('/reports/income-tax?year=2026', $item['link']);
        self::assertStringContainsString('1 000', $item['hint']);
    }

    public function testPreviousYearBalanceRetainsPriorityOverDistantCurrentYear(): void
    {
        $this->returns->create($this->supplierId, 2025, 'po', ['filing_deadline' => '2026-05-04'], null);
        $this->pdo->prepare("UPDATE income_tax_returns SET status = 'final', computed = ? WHERE supplier_id = ? AND year = 2025 AND taxpayer_type = 'po' AND variant = 'radne' AND variant_seq = 1")
            ->execute([json_encode(['computed' => ['balance_due' => 750]], JSON_THROW_ON_ERROR), $this->supplierId]);
        $this->returns->create($this->supplierId, 2026, 'po', [], null);

        $item = $this->service->taxBalanceDueItem($this->supplierId, null, new \DateTimeImmutable('2026-09-23'));

        self::assertNotNull($item);
        self::assertSame('dppo_balance_due', $item['type']);
        self::assertStringContainsString('DPPO 2025', $item['hint']);
        self::assertSame('/reports/income-tax?year=2025', $item['link']);
        self::assertStringContainsString('750', $item['hint']);
    }

    /**
     * Lhůtu § 136 odst. 2 DŘ (1. 7.) prodlužuje jen daňový poradce nebo advokát (4b/4c).
     * Obecný zmocněnec (4a) podepisuje, ale firma podává v běžné lhůtě (4. 5. 2026).
     */
    public function testGeneralProxyDoesNotExtendDeadline(): void
    {
        $this->finalReturn2025WithoutDeadline();
        (new TaxRepresentationService($this->db))->upsert(
            $this->supplierId, '2025-01-01', true, 'P',
            companyName: 'Vzorová účetní kancelář s.r.o.', ico: '01234567',
            code: '4a', signerFirstName: 'Petr', signerLastName: 'Podepisující',
        );

        $item = $this->service->taxBalanceDueItem($this->supplierId, null, new \DateTimeImmutable('2026-04-25'));

        self::assertNotNull($item, 'Obecný zmocněnec neprodlužuje lhůtu — doplatek je splatný 4. 5. 2026.');
        self::assertSame(9, $item['days']);
    }

    public function testTaxAdvisorExtendsDeadline(): void
    {
        $this->finalReturn2025WithoutDeadline();
        (new TaxRepresentationService($this->db))->upsert(
            $this->supplierId, '2025-01-01', true, 'F',
            firstName: 'Vzorový', lastName: 'Poradce', evNumber: 'EV-0001', code: '4b',
        );

        $item = $this->service->taxBalanceDueItem($this->supplierId, null, new \DateTimeImmutable('2026-04-25'));

        self::assertNull($item, 'Daňový poradce prodlužuje lhůtu do 1. 7. 2026 — na připomínku je brzy.');
    }

    private function finalReturn2025WithoutDeadline(): void
    {
        $this->returns->create($this->supplierId, 2025, 'po', [], null);
        $this->pdo->prepare("UPDATE income_tax_returns SET status = 'final', computed = ? WHERE supplier_id = ? AND year = 2025 AND taxpayer_type = 'po' AND variant = 'radne' AND variant_seq = 1")
            ->execute([json_encode(['computed' => ['balance_due' => 1000]], JSON_THROW_ON_ERROR), $this->supplierId]);
    }

    private function selectCount(): int
    {
        return (int) $this->pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
    }
}
