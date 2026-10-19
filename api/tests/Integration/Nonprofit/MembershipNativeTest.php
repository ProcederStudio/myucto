<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalLinePairingRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipBalanceRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipChargeRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Nonprofit\Membership\MembershipChargeService;
use MyInvoice\Service\Nonprofit\Membership\MembershipMemberService;
use MyInvoice\Service\Nonprofit\Membership\MembershipReceivablePaymentAdapter;
use MyInvoice\Service\Nonprofit\Membership\MembershipReconciliationService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitLegalProfileService;
use PDO;
use PHPUnit\Framework\TestCase;

final class MembershipNativeTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private PostingService $posting;
    private MembershipMemberService $members;
    private MembershipChargeService $charges;
    private MembershipBalanceRepository $balances;
    private int $supplier;
    private int $period;
    private int $actor;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
        $vat = (int) $this->pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo->prepare("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode) VALUES('Synthetic membership association','Synthetic street','Synthetic city','10000','membership-association@example.test',203,1,?,'double_entry')")->execute([$vat]);
        $this->supplier = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default,account_number,bank_code) VALUES(?,'CZK','Synthetic CZK','Kč','Koruna','Crown',2,1,1,'1000000005','0100')")->execute([$this->supplier]);
        $currency = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([$currency, $this->supplier]);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->pdo->prepare("INSERT INTO users(email,password_hash,name,role,locale,is_active) VALUES(? ,?,'Synthetic membership actor','admin','cs',1)")
            ->execute(['membership-actor@example.test', password_hash('Synthetic unused password', PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->actor = (int) $this->pdo->lastInsertId();
        $this->period = $container->get(AccountingPeriodRepository::class)->create($this->supplier, 2026, '2026-01-01', '2026-12-31');
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2026-01-01','cz_504')")->execute([$this->supplier]);
        foreach (['221' => 'asset', '315.100' => 'asset', '684.100' => 'revenue'] as $code => $type) {
            $this->pdo->prepare('INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type) VALUES(?,?,?,?)')
                ->execute([$this->supplier, $code, 'Synthetic ' . $code, $type]);
        }
        (new NonprofitLegalProfileService($this->db))->confirm($this->supplier, $this->period, [
            'expected_revision' => 0,
            'idempotency_key' => 'membership-legal',
            'reason' => 'Synthetic reviewed association statutes',
            'profile' => ['kind' => 'narrow_vpp', 'entity_type' => 'association', 'main_activity_nonbusiness' => true,
                'legal_basis' => 'zdp_17a', 'evidence' => 'Synthetic signed association statutes'],
        ], $this->actor);
        $repository = new MembershipRepository($this->db);
        $this->members = new MembershipMemberService($this->db, $repository);
        $this->posting = $container->get(PostingService::class);
        $this->charges = new MembershipChargeService($this->db, $repository, new MembershipChargeRepository($this->db), $this->posting);
        $this->balances = new MembershipBalanceRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testTwoMembersProduceExactNativeTotalsAndReplayUsesCapturedProof(): void
    {
        $one = $this->member('M-001', 'one');
        $two = $this->member('M-002', 'two');
        $first = $this->issue($one['id'], 'first');
        $second = $this->issue($two['id'], 'second');

        $q = $this->pdo->prepare("SELECT a.account_code,l.side,SUM(l.amount) amount,COUNT(*) rows_count
            FROM journal_entry_lines l JOIN journal_entries e ON e.supplier_id=l.supplier_id AND e.id=l.entry_id
            JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id
            WHERE l.supplier_id=? AND e.source_type='nonprofit_membership' AND e.source_id IS NOT NULL
            GROUP BY a.account_code,l.side ORDER BY a.account_code,l.side");
        $q->execute([$this->supplier]);
        self::assertSame([
            ['account_code' => '315.100', 'side' => 'debit', 'amount' => '40000.00', 'rows_count' => 2],
            ['account_code' => '684.100', 'side' => 'credit', 'amount' => '40000.00', 'rows_count' => 2],
        ], array_map(static function (array $row): array { $row['rows_count'] = (int) $row['rows_count']; return $row; }, $q->fetchAll(PDO::FETCH_ASSOC)));
        $reconciliation = (new MembershipReconciliationService($this->balances))->periodReconciliation($this->supplier, $this->period);
        self::assertSame('balanced', $reconciliation['status']);
        self::assertTrue($reconciliation['proof_complete']);
        self::assertSame('4000000', (string) $reconciliation['claims']['cents']);
        self::assertSame('4000000', (string) $reconciliation['ledger']['receivable_cents']);
        self::assertSame('4000000', (string) $reconciliation['ledger']['revenue_cents']);

        $this->members->appendRevision($this->supplier, $one['id'], [
            'status' => 'ended', 'effective_from' => '2027-01-01', 'expected_revision' => 1,
            'idempotency_key' => 'member-one-ended',
            'legal_basis' => $this->basis('Synthetic future membership end'),
        ], $this->actor);
        $replayed = $this->charges->confirmIssue($this->supplier, $this->request($one['id']), 0, $first['preview_hash'], 'issue-first', $this->actor);
        self::assertTrue($replayed['idempotent_replay']);
        self::assertSame($first['operation']['id'], $replayed['operation']['id']);
        self::assertSame($second['operation']['amount_cents'], 2000000);
    }

    public function testStalePreviewOverlapAndClosedPeriodAreRejected(): void
    {
        $member = $this->member('M-005', 'five');
        $request = $this->request($member['id']);
        $stale = $this->charges->previewIssue($this->supplier, $request);
        $this->members->appendRevision($this->supplier, $member['id'], [
            'status' => 'ended', 'effective_from' => '2027-01-01', 'expected_revision' => 1,
            'idempotency_key' => 'member-five-ended', 'legal_basis' => $this->basis('Synthetic future end reviewed after preview'),
        ], $this->actor);
        try {
            $this->charges->confirmIssue($this->supplier, $request, 0, $stale['preview_hash'], 'stale-five', $this->actor);
            self::fail('Changed effective timeline must invalidate a prior preview.');
        } catch (\MyInvoice\Service\Nonprofit\Membership\MembershipException $e) {
            self::assertSame('nonprofit_membership_preview_stale', $e->errorCode);
        }
        $fresh = $this->charges->previewIssue($this->supplier, $request);
        $this->charges->confirmIssue($this->supplier, $request, 0, $fresh['preview_hash'], 'fresh-five', $this->actor);
        $overlap = $request;
        $overlap['period_from'] = '2026-02-01';
        $overlap['period_to'] = '2026-11-30';
        try {
            $this->charges->previewIssue($this->supplier, $overlap);
            self::fail('An active title cannot overlap itself.');
        } catch (\MyInvoice\Service\Nonprofit\Membership\MembershipException $e) {
            self::assertSame('nonprofit_membership_claim_overlap', $e->errorCode);
        }
        $closed = $request;
        $closed['title_key'] = 'closed-period-title';
        $closedPreview = $this->charges->previewIssue($this->supplier, $closed);
        $this->pdo->prepare("UPDATE accounting_periods SET status='closed' WHERE supplier_id=? AND id=?")->execute([$this->supplier, $this->period]);
        try {
            $this->charges->confirmIssue($this->supplier, $closed, 0, $closedPreview['preview_hash'], 'closed-five', $this->actor);
            self::fail('Closed period must reject a financial write.');
        } catch (\MyInvoice\Service\Nonprofit\Membership\MembershipException $e) {
            self::assertSame('nonprofit_membership_period_locked', $e->errorCode);
        }
    }

    public function testNativeBankPairingIsTheOnlyPaymentEvidenceAndReversalConsumesClaim(): void
    {
        $member = $this->member('M-003', 'three');
        $issued = $this->issue($member['id'], 'payment');
        $claimId = (int) $this->pdo->query('SELECT id FROM nonprofit_membership_charge_claims WHERE operation_id=' . (int) $issued['operation']['id'])->fetchColumn();
        $reconciliation = new MembershipReconciliationService($this->balances);
        $before = $reconciliation->claimSettlement($this->supplier, $claimId);
        self::assertSame('unreconciled', $before['settlement']['status']);
        self::assertNull($before['settlement']['paid_cents']);
        self::assertNull($before['settlement']['open_cents']);

        $target = (new MembershipReceivablePaymentAdapter($this->balances))->target($this->supplier, $claimId);
        $forged = $this->posting->postDocument($this->supplier, 'bank', null, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => '20000.00'],
            ['account_code' => '315.100', 'side' => 'credit', 'amount' => '20000.00'],
        ], ['entry_date' => '2026-02-01', 'description' => 'Synthetic unowned bank-looking entry', 'posted' => true, 'posted_by' => $this->actor]);
        $q = $this->pdo->prepare("SELECT l.line_no,l.account_id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id WHERE l.supplier_id=? AND l.entry_id=? AND a.account_code='315.100'");
        $q->execute([$this->supplier, $forged]);
        $forgedLine = $q->fetch(PDO::FETCH_ASSOC);
        $pairings = new JournalLinePairingRepository($this->db);
        $forgedPairing = $pairings->create($this->supplier, $target['journal_line']['account_id'], [
            ['entry_id' => $target['journal_line']['entry_id'], 'line_no' => $target['journal_line']['line_no'], 'account_id' => $target['journal_line']['account_id']],
            ['entry_id' => $forged, 'line_no' => (int) $forgedLine['line_no'], 'account_id' => (int) $forgedLine['account_id']],
        ], 'Synthetic forged payment evidence', 'manual', $this->actor);
        self::assertSame('ambiguous', $reconciliation->claimSettlement($this->supplier, $claimId)['settlement']['status']);
        self::assertTrue($pairings->delete($this->supplier, $forgedPairing));

        $this->pdo->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,imported_by) VALUES(?,'synthetic-membership.gpc',?,'1000000005','0100','CZK','2026-02-01',?)")
            ->execute([$this->supplier, hash('sha256', 'synthetic membership payment ' . $this->supplier), $this->actor]);
        $statement = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO bank_transactions(statement_id,posted_at,amount,currency,description,match_status) VALUES(?,'2026-02-01',20000,'CZK','Synthetic native membership payment','manual')")
            ->execute([$statement]);
        $transaction = (int) $this->pdo->lastInsertId();
        $payment = $this->posting->postDocument($this->supplier, 'bank', $transaction, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => '20000.00'],
            ['account_code' => '315.100', 'side' => 'credit', 'amount' => '20000.00'],
        ], ['entry_date' => '2026-02-01', 'description' => 'Synthetic native bank payment', 'posted' => true, 'posted_by' => $this->actor]);
        $q->execute([$this->supplier, $payment]);
        $paymentLine = $q->fetch(PDO::FETCH_ASSOC);
        $pairings->create($this->supplier, $target['journal_line']['account_id'], [
            ['entry_id' => $target['journal_line']['entry_id'], 'line_no' => $target['journal_line']['line_no'], 'account_id' => $target['journal_line']['account_id']],
            ['entry_id' => $payment, 'line_no' => (int) $paymentLine['line_no'], 'account_id' => (int) $paymentLine['account_id']],
        ], 'Synthetic exact membership payment', 'manual', $this->actor);
        $settled = $reconciliation->claimSettlement($this->supplier, $claimId);
        self::assertSame('settled', $settled['settlement']['status']);
        self::assertSame(2000000, $settled['settlement']['paid_cents']);
        self::assertSame(0, $settled['settlement']['open_cents']);

        $preview = $this->charges->previewReverse($this->supplier, ['case_id' => $issued['case']['id'], 'accounting_date' => '2026-12-31', 'reason' => 'Synthetic approved cancellation']);
        $reversed = $this->charges->confirmReverse($this->supplier, ['case_id' => $issued['case']['id'], 'accounting_date' => '2026-12-31', 'reason' => 'Synthetic approved cancellation'],
            $preview['expected_case_version'], $preview['preview_hash'], 'reverse-payment', $this->actor);
        self::assertSame('reverse', $reversed['operation']['operation']);
        self::assertSame('reversed', $reconciliation->claimSettlement($this->supplier, $claimId)['settlement']['status']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM journal_line_pairing_items WHERE entry_id=' . $target['journal_line']['entry_id'])->fetchColumn());
    }

    public function testFinancialFactsAreAppendOnly(): void
    {
        $member = $this->member('M-004', 'four');
        $issued = $this->issue($member['id'], 'immutable');
        $this->expectException(\PDOException::class);
        $this->pdo->prepare('UPDATE nonprofit_membership_charge_operations SET amount_cents=1 WHERE supplier_id=? AND id=?')
            ->execute([$this->supplier, $issued['operation']['id']]);
    }

    public function testMemberBalanceQueryCountIsConstantWithinOnePage(): void
    {
        $member = $this->member('M-006', 'six');
        $this->issueTitle($member['id'], 'batch-1', 'balance-batch-1');
        $service = new MembershipReconciliationService($this->balances);
        $one = $this->selectQueries(fn (): array => $service->memberBalance($this->supplier, $member['id']));
        for ($index = 2; $index <= 6; ++$index) $this->issueTitle($member['id'], 'batch-' . $index, 'balance-batch-' . $index);
        $many = $this->selectQueries(fn (): array => $service->memberBalance($this->supplier, $member['id']));

        self::assertSame($one, $many);
        self::assertLessThanOrEqual(3, $many);
    }

    private function member(string $number, string $key): array
    {
        $currency = (int) $this->pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor) VALUES(?,'Synthetic member','Synthetic street','Synthetic city','10000',203,?,'cs',?,1,0)")
            ->execute([$this->supplier, $key . '@example.test', $currency]);
        $member = $this->members->create($this->supplier, (int) $this->pdo->lastInsertId(), $number, $this->actor);
        $this->members->appendRevision($this->supplier, $member['id'], [
            'status' => 'active', 'effective_from' => '2026-01-01', 'expected_revision' => 0,
            'idempotency_key' => 'member-' . $key, 'legal_basis' => $this->basis('Synthetic member evidence ' . $key),
        ], $this->actor);
        return $member;
    }

    private function basis(string $evidence): array
    {
        return ['organisation_form' => 'association', 'relationship' => 'nonreciprocal_membership',
            'reference' => 'Synthetic association statutes article 5', 'evidence' => $evidence];
    }

    private function request(int $member): array
    {
        return ['member_id' => $member, 'title_key' => 'annual-2026', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
            'accounting_date' => '2026-01-01', 'due_date' => '2026-01-31', 'amount' => '20000.00',
            'receivable_account' => '315.100', 'revenue_account' => '684.100', 'title_reference' => 'Synthetic statutes article 5',
            'evidence' => 'Synthetic approved annual membership resolution', 'nonreciprocal' => true, 'reason' => 'Synthetic annual membership charge'];
    }

    private function issue(int $member, string $key): array
    {
        $request = $this->request($member);
        $preview = $this->charges->previewIssue($this->supplier, $request);
        return $this->charges->confirmIssue($this->supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], 'issue-' . $key, $this->actor)
            + ['preview_hash' => $preview['preview_hash']];
    }

    private function issueTitle(int $member, string $title, string $key): array
    {
        $request = $this->request($member);
        $request['title_key'] = $title;
        $preview = $this->charges->previewIssue($this->supplier, $request);
        return $this->charges->confirmIssue($this->supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], $key, $this->actor);
    }

    private function selectQueries(callable $work): int
    {
        $before = (int) $this->pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
        $work();
        return (int) $this->pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1] - $before;
    }
}
