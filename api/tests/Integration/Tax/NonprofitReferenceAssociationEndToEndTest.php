<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoAcceptanceService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitLegalProfileService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingsLedger;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingsService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingUseGuard;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use MyInvoice\Service\Tax\Return\TaxReturnService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Referenční spolek TJ Příklad (syntetický etalon E0) od zdrojových operací přes
 * daňové posouzení po druzích činností až po DPPO 504: základ 100 000 Kč, snížení
 * podle § 20 odst. 7 o 100 000 Kč, daň 0 Kč, úspora 21 000 Kč a její použití
 * v následujícím zdaňovacím období.
 *
 * Etalon popisuje kalendářní rok 2026. Připnutý formulář DPPDP9 05.01.01 ale začátek
 * roku 2026 nepřijímá (finalizace, XML i podání jsou pro něj zavřené), proto test
 * používá stejné částky v kalendářním roce 2025, který spadá do týchž pravidel 2024-2026.
 * Společná režie O-13/O-14 se zaúčtuje rovnou do materializovaných kombinací; samotná
 * alokace je pokrytá testy společných alokací.
 */
final class NonprofitReferenceAssociationEndToEndTest extends TestCase
{
    use IsolatedSupplierTrait;

    private \DI\Container $container;
    private Connection $db;
    private PDO $pdo;
    private int $supplier;
    private int $actor;
    private int $period;
    private array $catalog;
    private array $kinds = [];
    private array $accounts = [];
    private array $lines = [];

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
        $seed = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->actor = (int) $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->supplier = $this->createIsolatedSupplier($this->pdo, $seed);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode='double_entry',taxpayer_type='po' WHERE id=?")->execute([$this->supplier]);
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2025-01-01','cz_504')")
            ->execute([$this->supplier]);
        $this->period = (new AccountingPeriodRepository($this->db))->create($this->supplier, 2025, '2025-01-01', '2025-12-31');
        $this->catalog = (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier, '2025-01-01');
        foreach (['sport', 'education', 'rental', 'membership', 'general_support', 'culture_free_events'] as $code) {
            $this->pdo->prepare('INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES(?,?,?,?)')
                ->execute([$this->catalog['activity_kind']['id'], $this->supplier, strtoupper($code), 'Syntetický druh ' . $code]);
            $this->kinds[$code] = (int) $this->pdo->lastInsertId();
        }
        $types = ['221' => 'asset', '022' => 'asset', '082' => 'asset', '348' => 'asset', '321' => 'liability', '901' => 'equity',
            '501' => 'expense', '518' => 'expense', '551' => 'expense',
            '602' => 'revenue', '649' => 'revenue', '682' => 'revenue', '684' => 'revenue', '691' => 'revenue'];
        foreach ($types as $code => $type) {
            $this->pdo->prepare('INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type) VALUES(?,?,?,?)')
                ->execute([$this->supplier, (string) $code, 'Syntetický účet ' . $code, $type]);
            $this->accounts[(string) $code] = (int) $this->pdo->lastInsertId();
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testReferenceAssociationReachesZeroTaxSavingAndNextPeriodUse(): void
    {
        $this->postReferenceYear();
        (new NonprofitLegalProfileService($this->db))->confirm($this->supplier, $this->period, $this->legalRequest('tj-legal-2025'), $this->actor);

        $j6 = $this->container->get(NonprofitTaxAssessmentService::class);
        $snapshot = $j6->read($this->supplier, $this->period)['snapshot'];
        self::assertSame([], $snapshot['findings']);
        self::assertSame(13040000, $snapshot['accounting_result_cents']);
        $confirmed = $j6->confirm($this->supplier, $this->period, $this->referenceAssessment(), $snapshot['input_hash'],
            'Syntetické posouzení TJ Příklad', 'tj-j6-2025', $this->actor);
        $result = $confirmed['result'];
        self::assertSame(13040000, $result['accounting_result_cents']);
        self::assertSame(10000000, $result['assessed_result_cents']);
        $rows = array_column($result['rows'], null, 'id');
        self::assertSame(2500000, $rows[$this->lines['O-08']]['deductible_cents']);
        self::assertSame(5500000, $rows[$this->lines['O-08']]['funded_excluded_cents']);
        self::assertSame('subject', $rows[$this->lines['O-01']]['classification']['regime']);
        self::assertSame('exempt', $rows[$this->lines['O-04']]['classification']['regime']);
        self::assertSame('exempt', $rows[$this->lines['O-05']]['classification']['regime']);
        self::assertSame('out_of_scope', $rows[$this->lines['O-06']]['classification']['regime']);
        self::assertSame('out_of_scope', $rows[$this->lines['O-21']]['classification']['regime']);

        self::assertSame('return', $rows[$this->lines['O-01']]['classification']['method']);
        $this->saveStatementNotes(2025);
        $service = $this->container->get(TaxReturnService::class);
        $read = $service->getReturn($this->supplier, 2025, 'po', $this->actor);
        $draft = $service->saveInputs($this->supplier, 2025, 'po', ['nonprofit' => ['reduction_kc' => 100000,
            'reduction_evidence' => 'Syntetický předem schválený plán použití úspory']], $read['return']['row_version'], $this->actor, partial: true);
        $saving = $draft['computed']['nonprofit']['saving'];
        self::assertSame(100000, $saving['base_before_reduction_kc']);
        self::assertSame(100000, $saving['reduction_kc']);
        self::assertSame(21000, $saving['tax_without_reduction_kc']);
        self::assertSame(0, $saving['tax_with_reduction_kc']);
        self::assertSame(21000, $saving['saving_kc']);
        self::assertSame(0, (int) $draft['computed']['tax']);

        try {
            $service->finalize($this->supplier, 2025, 'po', $draft['return']['row_version'], $this->actor,
                expectedNonprofitFilingProofHash: $draft['nonprofit_filing_proof_hash']);
            self::fail('DPPO 504 finalization passed without accepted calculation.');
        } catch (TaxReturnException $e) {
            self::assertSame('nonprofit_dppo_calculation_not_accepted', $e->errorCode);
        }
        (new NonprofitDppoAcceptanceService($this->db))->change($this->supplier, NonprofitDppoAcceptanceService::ACCEPT, true,
            'Syntetický protokol porovnání s etalonem TJ Příklad', 'Syntetická akceptace výpočtu', 'tj-accept', null, $this->actor);
        $draft = $service->getReturn($this->supplier, 2025, 'po', $this->actor);
        $final = $service->finalize($this->supplier, 2025, 'po', $draft['return']['row_version'], $this->actor,
            expectedNonprofitFilingProofHash: $draft['nonprofit_filing_proof_hash']);
        self::assertSame('final', $final['return']['status']);
        self::assertSame(0, (int) $final['computed']['tax']);
        $built = $service->buildXml($this->supplier, 2025, 'po');
        self::assertStringContainsString('uv_vyhl="504"', $built['xml']);

        $savings = new NonprofitTaxSavingsService($this->db);
        $source = $savings->context($this->supplier)['sources'][0];
        self::assertSame('2100000', $source['source']['saving_cents']);
        self::assertSame(100000, $source['source']['reduction_kc']);

        $next = $this->nextPeriodWithCost();
        $due = $this->due($next['period']);
        self::assertSame(100000, $due['addback_kc']);
        $source = $savings->context($this->supplier)['sources'][0];
        $input = ['cost_line_id' => $next['line'], 'amount_cents' => '2100000', 'purpose' => 'nonbusiness',
            'evidence' => 'Syntetické použití úspory na nepodnikatelský náklad'];
        $preview = $savings->previewUse($this->supplier, $source['source']['id'], $input);
        $use = $savings->confirmUse($this->supplier, $source['source']['id'], $input, $preview['preview_hash'],
            $preview['head_hash'], 'tj-saving-use', $this->actor);
        self::assertSame('use', $use['operation']);
        self::assertSame(0, $this->due($next['period'])['addback_kc']);
        self::assertSame('0', $savings->context($this->supplier)['sources'][0]['remaining_cents']);

        $guard = new NonprofitTaxSavingUseGuard($this->db);
        self::assertFalse($guard->assertEntryChange($this->supplier, $this->lineEntry($this->lines['O-03']), [], '2025-03-01'));
        try {
            $guard->assertEntryChange($this->supplier, $next['entry'], null, null);
            self::fail('Expense proving the tax saving use was released for change.');
        } catch (PostingException $e) {
            self::assertSame('nonprofit_tax_saving_cost_in_use', $e->errorCode);
        }
    }

    private function postReferenceYear(): void
    {
        $main = $this->catalog['activity']['values']['main'];
        $economic = $this->catalog['activity']['values']['economic'];
        $regime = $this->catalog['tax_regime']['values'];
        $this->entry('O-00', '2025-01-01', [['221', 'debit', '300000.00'], ['901', 'credit', '300000.00']]);
        $operations = [
            ['O-01', '602', 'credit', '100000.00', $main, 'sport', 'subject'],
            ['O-02', '602', 'credit', '100000.00', $main, 'education', 'subject'],
            ['O-03', '602', 'credit', '60000.00', $economic, 'rental', 'subject'],
            ['O-04', '684', 'credit', '40000.00', $main, 'membership', 'exempt'],
            ['O-05', '682', 'credit', '25000.00', $main, 'general_support', 'exempt'],
            ['O-09', '518', 'debit', '57925.93', $main, 'sport', 'subject'],
            ['O-10', '501', 'debit', '20000.00', $main, 'education', 'subject'],
            ['O-11', '518', 'debit', '24969.14', $main, 'education', 'subject'],
            ['O-12', '518', 'debit', '11981.48', $economic, 'rental', 'subject'],
            ['O-14a', '518', 'debit', '12074.07', $main, 'sport', 'subject'],
            ['O-14b', '518', 'debit', '5030.86', $main, 'education', 'subject'],
            ['O-14c', '518', 'debit', '3018.52', $economic, 'rental', 'subject'],
        ];
        foreach ($operations as [$id, $account, $side, $amount, $activity, $kind, $taxRegime]) {
            $this->entry($id, '2025-03-01', [[$account, $side, $amount, [$activity, $this->kinds[$kind], $regime[$taxRegime]]],
                ['221', $side === 'debit' ? 'credit' : 'debit', $amount]]);
        }
        $this->entry('O-06', '2025-02-01', [['348', 'debit', '30000.00'],
            ['691', 'credit', '30000.00', [$main, $this->kinds['general_support'], $regime['out_of_scope']]]]);
        $this->entry('O-07', '2025-02-15', [['221', 'debit', '30000.00'], ['348', 'credit', '30000.00']]);
        $this->entry('O-08', '2025-03-10', [['501', 'debit', '80000.00', [$main, $this->kinds['sport'], $regime['subject']]],
            ['321', 'credit', '80000.00']]);
        $this->entry('O-15', '2025-03-20', [['321', 'debit', '80000.00'], ['221', 'credit', '80000.00']]);
        $this->entry('O-16', '2025-04-01', [['348', 'debit', '72000.00'], ['901', 'credit', '72000.00']]);
        $this->entry('O-17', '2025-04-15', [['221', 'debit', '72000.00'], ['348', 'credit', '72000.00']]);
        $this->entry('O-18', '2025-05-01', [['022', 'debit', '120000.00'], ['321', 'credit', '120000.00']]);
        $this->entry('O-19', '2025-05-10', [['321', 'debit', '120000.00'], ['221', 'credit', '120000.00']]);
        $this->entry('O-20', '2025-12-31', [['551', 'debit', '24000.00', [$main, $this->kinds['culture_free_events'], $regime['out_of_scope']]],
            ['082', 'credit', '24000.00']]);
        $this->entry('O-21', '2025-12-31', [['901', 'debit', '14400.00'],
            ['649', 'credit', '14400.00', [$main, $this->kinds['culture_free_events'], $regime['out_of_scope']]]]);
    }

    /** @param list<array{0:string,1:string,2:string,3?:array{0:int,1:int,2:int}}> $lines */
    private function entry(string $id, string $date, array $lines, ?int $period = null): int
    {
        $this->pdo->prepare("INSERT INTO journal_entries(supplier_id,period_id,entry_date,source_type,description) VALUES(?,?,?,'manual',?)")
            ->execute([$this->supplier, $period ?? $this->period, $date, 'Syntetická operace ' . $id]);
        $entry = (int) $this->pdo->lastInsertId();
        foreach ($lines as $number => $line) {
            $this->pdo->prepare('INSERT INTO journal_entry_lines(supplier_id,entry_id,line_no,account_id,side,amount) VALUES(?,?,?,?,?,?)')
                ->execute([$this->supplier, $entry, $number + 1, $this->accounts[$line[0]], $line[1], $line[2]]);
            $lineId = (int) $this->pdo->lastInsertId();
            if (!isset($line[3])) continue;
            $this->lines[$id] = 'line:' . $lineId;
            foreach (['activity', 'activity_kind', 'tax_regime'] as $axis => $type) {
                $this->pdo->prepare("INSERT INTO journal_entry_line_dimensions(supplier_id,line_id,dimension_type_id,dimension_value_id,assignment_origin)
                    VALUES(?,?,?,?,'explicit')")->execute([$this->supplier, $lineId, $this->catalog[$type]['id'], $line[3][$axis]]);
            }
        }
        $this->pdo->prepare('UPDATE journal_entries SET posted_at=NOW() WHERE id=?')->execute([$entry]);
        return $entry;
    }

    private function referenceAssessment(): array
    {
        $evidence = 'Syntetický doklad etalonu TJ Příklad';
        $activities = [];
        foreach ($this->kinds as $code => $kind) $activities[(string) $kind] = ['evidence' => 'Syntetické stanovy: ' . $code];
        $revenue = static fn (string $title, array $facts = []) => ['title' => $title, 'evidence' => $evidence,
            'legal_basis' => 'zdp_18a', 'deductible_cents' => 0] + ($facts === [] ? [] : ['facts' => $facts]);
        $cost = static fn (string $title, int $deductible) => ['title' => $title, 'evidence' => $evidence,
            'legal_basis' => 'zdp_24_25', 'deductible_cents' => $deductible];
        $decisions = [
            $this->lines['O-01'] => $revenue('nonbusiness'),
            $this->lines['O-02'] => $revenue('nonbusiness'),
            $this->lines['O-03'] => $revenue('rent'),
            $this->lines['O-04'] => $revenue('membership', ['qualifying_membership' => true]),
            $this->lines['O-05'] => $revenue('gift', ['claim_exemption' => true, 'qualifying_purpose' => true,
                'election_evidence' => 'Syntetické uplatnění osvobození daru']),
            $this->lines['O-06'] => $revenue('public_operating_grant', ['public_budget' => true]),
            $this->lines['O-21'] => $revenue('public_investment_grant', ['public_budget' => true]),
            $this->lines['O-08'] => $cost('nonbusiness_cost', 8000000),
            $this->lines['O-09'] => $cost('nonbusiness_cost', 5792593),
            $this->lines['O-14a'] => $cost('nonbusiness_cost', 1207407),
            $this->lines['O-10'] => $cost('nonbusiness_cost', 2000000),
            $this->lines['O-11'] => $cost('nonbusiness_cost', 2496914),
            $this->lines['O-14b'] => $cost('nonbusiness_cost', 503086),
            $this->lines['O-12'] => $cost('cost', 1198148),
            $this->lines['O-14c'] => $cost('cost', 301852),
            $this->lines['O-20'] => $cost('excluded_cost', 0),
        ];
        return ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'Syntetické stanovy spolku TJ Příklad'],
            'activities' => $activities, 'decisions' => $decisions, 'funding_sources' => [], 'funding_costs' => [],
            'funding' => [
                ['source_id' => $this->lines['O-06'], 'cost_id' => $this->lines['O-08'], 'amount_cents' => 3000000,
                    'evidence' => 'F-01 Syntetické rozhodnutí obce o účelu provozní dotace'],
                ['source_id' => $this->lines['O-05'], 'cost_id' => $this->lines['O-08'], 'amount_cents' => 2500000,
                    'evidence' => 'F-02 Syntetické rozhodnutí spolku o použití daru'],
            ]];
    }

    private function legalRequest(string $key, int $revision = 0): array
    {
        return ['expected_revision' => $revision, 'idempotency_key' => $key, 'reason' => 'Syntetické právní posouzení spolku',
            'profile' => ['kind' => 'narrow_vpp', 'entity_type' => 'association', 'main_activity_nonbusiness' => true,
                'legal_basis' => 'zdp_17a', 'evidence' => 'Syntetické stanovy spolku TJ Příklad']];
    }

    private function nextPeriodWithCost(): array
    {
        $period = (new AccountingPeriodRepository($this->db))->create($this->supplier, 2026, '2026-01-01', '2026-12-31');
        (new NonprofitLegalProfileService($this->db))->confirm($this->supplier, $period, $this->legalRequest('tj-legal-2026'), $this->actor);
        $main = $this->catalog['activity']['values']['main'];
        $subject = $this->catalog['tax_regime']['values']['subject'];
        $entry = $this->entry('N-01', '2026-02-01', [['518', 'debit', '25000.00', [$main, $this->kinds['sport'], $subject]],
            ['221', 'credit', '25000.00']], $period);
        $j6 = $this->container->get(NonprofitTaxAssessmentService::class);
        $snapshot = $j6->read($this->supplier, $period)['snapshot'];
        $assessment = ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'Syntetické stanovy spolku TJ Příklad'],
            'activities' => [(string) $this->kinds['sport'] => ['evidence' => 'Syntetická sportovní činnost']],
            'decisions' => [], 'funding' => []];
        foreach ($snapshot['sources'] as $row) {
            $assessment['decisions'][$row['id']] = ['title' => 'nonbusiness_cost', 'deductible_cents' => 0,
                'evidence' => 'Syntetický nepodnikatelský náklad hrazený z úspory', 'legal_basis' => 'zdp_20_7'];
        }
        $j6->confirm($this->supplier, $period, $assessment, $snapshot['input_hash'], 'Syntetické posouzení dalšího období', 'tj-j6-2026', $this->actor);
        return ['period' => $period, 'entry' => $entry, 'line' => (int) substr($this->lines['N-01'], 5)];
    }

    private function due(int $period): array
    {
        return (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->transaction(function () use ($period): array {
            (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->lockPeriods($this->supplier);
            return (new NonprofitTaxSavingsLedger($this->db))->due($this->supplier, $period);
        });
    }

    private function lineEntry(string $sourceId): int
    {
        $stmt = $this->pdo->prepare('SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id=?');
        $stmt->execute([$this->supplier, (int) substr($sourceId, 5)]);
        return (int) $stmt->fetchColumn();
    }

    private function saveStatementNotes(int $year): void
    {
        $notes = $this->container->get(\MyInvoice\Service\Accounting\Reports\StatementNotesService::class);
        foreach ($notes->build($this->supplier, $this->period)['sections'] as $section) {
            $notes->saveSection($this->supplier, $year, $section['key'], 'Syntetická zkontrolovaná příloha', $this->actor);
        }
    }
}
