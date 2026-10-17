<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FxClosedHomePositionTest extends TestCase
{
    private Connection $db;
    private int $sid;
    private int $pid;
    private int $user;
    private int $originalCurrency;
    private bool $committed = false;
    private ?Connection $other = null;
    private ?int $snapshotIsolation = null;

    protected function setUp(): void
    {
        $c = Bootstrap::buildContainer();
        $this->db = $c->get(Connection::class);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $this->originalCurrency = (int) $pdo->query('SELECT default_currency_id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode) SELECT 'Synthetic closed FX','Test','Test','10000','closed-fx@example.invalid',country_id,default_currency_id,default_vat_rate_id,'double_entry' FROM supplier ORDER BY id LIMIT 1");
        $this->sid = (int) $pdo->lastInsertId();
        $this->user = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $c->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->sid);
        $this->pid = $c->get(AccountingPeriodRepository::class)->create($this->sid, 2097, '2097-01-01', '2097-12-31');
        $pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default,account_number,bank_code) VALUES (?,'CZK','CZK','Kč','Koruna','Koruna',2,1,1,'1000000005','0100')")->execute([$this->sid]);
        $pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([(int) $pdo->lastInsertId(), $this->sid]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
        $this->other?->close();
        if ($this->committed) {
            $this->db->pdo()->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([$this->originalCurrency, $this->sid]);
            foreach (['activity_log', 'journal_entries', 'cash_documents', 'invoice_payments', 'bank_statements', 'invoices', 'purchase_invoices', 'clients', 'cash_registers', 'currencies'] as $table) $this->db->pdo()->prepare('DELETE FROM ' . $table . ' WHERE supplier_id=?')->execute([$this->sid]);
            $this->db->pdo()->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->sid]);
        }
        if ($this->snapshotIsolation !== null) $this->db->pdo()->exec('SET SESSION innodb_snapshot_isolation=' . $this->snapshotIsolation);
    }

    public static function cases(): iterable
    {
        foreach (['invoice', 'purchase_invoice'] as $type) {
            foreach (['native_bank', 'legacy_bank', 'native_cash', 'reversal', 'readonly', 'current_tuple', 'current_member', 'current_dimensions', 'source_link'] as $mode) yield $type . '_' . $mode => [$type, $mode];
        }
    }

    #[DataProvider('cases')]
    public function testExactHomeSettlementPreservesLifecycleAndReadMode(string $type, string $mode): void
    {
        $c = Bootstrap::buildContainer();
        $pdo = $this->db->pdo();
        $id = $this->document($type);
        self::assertFalse($this->evidence($type, $id, '2097-07-19')['complete']);
        $bank = $this->settle($type, $id, $mode === 'native_cash');
        $dimensionProof = $mode === 'current_dimensions' ? $this->dimensions($type) : null;
        if ($mode === 'legacy_bank' && $type === 'invoice') {
            $pdo->prepare("INSERT INTO payment_matches(supplier_id,bank_transaction_id,invoice_id,amount,match_type) VALUES (?,?,?,24000,'manual')")->execute([$this->sid, $bank, $id]);
            $pdo->prepare('DELETE FROM invoice_payments WHERE supplier_id=? AND bank_transaction_id=?')->execute([$this->sid, $bank]);
        }
        $full = $this->evidence($type, $id, '2097-07-20');
        self::assertTrue($full['complete'], json_encode($full));
        self::assertSame(['0.00', '0.00'], [$full['remaining_foreign'], $full['booked_czk']]);
        self::assertTrue($full['closed_home_position_proven']);
        if ($mode === 'source_link') {
            $entry = $c->get(\MyInvoice\Repository\JournalEntryRepository::class)->findBySource($this->sid, 'bank', $bank);
            $c->get(\MyInvoice\Repository\JournalEntryDocumentLinkRepository::class)->add((int) $entry['id'], $this->sid, 'purchase_invoice', $this->documentOther($c), null, $this->user);
            self::assertFalse($this->evidence($type, $id, '2097-07-20')['complete']);
        }
        if ($mode === 'reversal') {
            $entry = $c->get(\MyInvoice\Repository\JournalEntryRepository::class)->findBySource($this->sid, 'bank', $bank);
            $c->get(PostingService::class)->reverse($this->sid, (int) $entry['id'], ['entry_date' => '2097-07-21']);
            self::assertTrue($this->evidence($type, $id, '2097-07-20')['complete']);
            self::assertFalse($this->evidence($type, $id, '2097-07-21')['complete']);
        }
        if ($mode === 'readonly' || str_starts_with($mode, 'current_')) {
            $pdo->commit();
            $this->committed = true;
            $this->snapshotIsolation = (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
            $pdo->exec('SET SESSION innodb_snapshot_isolation=0');
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            if ($mode === 'readonly') $pdo->exec('SET TRANSACTION READ ONLY');
            $pdo->beginTransaction();
            self::assertTrue($this->evidence($type, $id, '2097-07-20')['complete']);
            if ($mode === 'readonly') {
                $before = (int) $pdo->query('SELECT COUNT(*) FROM journal_entries WHERE supplier_id=' . $this->sid)->fetchColumn();
                $preview = $c->get(ClosingService::class)->fxPreview($this->sid, $this->pid);
                self::assertSame(['loss' => 0.0, 'gain' => 0.0], $preview['totals']);
                self::assertNotContains('closed_home_position_proven', array_column($preview['warnings'], 'code'));
                self::assertSame($before, (int) $pdo->query('SELECT COUNT(*) FROM journal_entries WHERE supplier_id=' . $this->sid)->fetchColumn());
            }
            if ($mode !== 'readonly') {
                $otherContainer = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer());
                $this->other = Connection::withoutSharedTestConnection(fn () => $otherContainer->get(Connection::class));
                if ($mode === 'current_tuple') {
                    $this->other->pdo()->prepare("UPDATE journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id SET l.cost_center='Changed' WHERE e.supplier_id=? AND e.source_type='bank' AND e.source_id=?")->execute([$this->sid, $bank]);
                } elseif ($mode === 'current_dimensions') {
                    (new \MyInvoice\Repository\DimensionAssignmentRepository($this->other))->replaceLineSplits($this->sid, $dimensionProof['bank_line'], [$dimensionProof['type'] => [$dimensionProof['a'] => 0.5, $dimensionProof['b'] => 0.5]]);
                } else {
                    $this->other->pdo()->prepare('INSERT INTO payment_matches(supplier_id,bank_transaction_id,invoice_id,purchase_invoice_id,amount,match_type) VALUES (?,?,NULL,?,1,"manual")')->execute([$this->sid, $bank, $this->documentOther($otherContainer)]);
                }
                self::assertTrue($this->evidence($type, $id, '2097-07-20')['complete']);
                self::assertFalse($this->evidence($type, $id, '2097-07-20', true)['complete']);
                self::assertTrue($this->evidence($type, $id, '2097-07-20')['complete']);
            }
        }
        self::assertTrue($pdo->inTransaction());
    }

    private function dimensions(string $type): array
    {
        $repo = new \MyInvoice\Repository\DimensionRepository($this->db);
        $splitType = $repo->createType($this->sid, false, ['code' => 'fx_split', 'name' => 'FX split', 'kind' => 'custom']);
        $row = $repo->findType($this->sid, $splitType);
        $a = $repo->createValue($row, ['code' => 'a', 'name' => 'A']);
        $b = $repo->createValue($row, ['code' => 'b', 'name' => 'B']);
        $directType = $repo->createType($this->sid, false, ['code' => 'fx_direct', 'name' => 'FX direct', 'kind' => 'custom']);
        $direct = $repo->createValue($repo->findType($this->sid, $directType), ['code' => 'c', 'name' => 'C']);
        $stmt = $this->db->pdo()->prepare('SELECT l.id,e.source_type FROM journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id JOIN chart_of_accounts a ON a.id=l.account_id WHERE e.supplier_id=? AND a.account_code=? ORDER BY l.id');
        $stmt->execute([$this->sid, $type === 'invoice' ? '311' : '321']);
        $assign = new \MyInvoice\Repository\DimensionAssignmentRepository($this->db);
        $bankLine = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $line) {
            $assign->replaceLineDimensions($this->sid, (int) $line['id'], [$directType => $direct]);
            $assign->replaceLineSplits($this->sid, (int) $line['id'], [$splitType => [$a => 0.25, $b => 0.75]]);
            if ($line['source_type'] === 'bank') $bankLine = (int) $line['id'];
        }
        self::assertGreaterThan(0, $bankLine);
        return ['type' => $splitType, 'a' => $a, 'b' => $b, 'bank_line' => $bankLine];
    }

    private function documentOther(\Psr\Container\ContainerInterface $c): int
    {
        $pdo = $c->get(Connection::class)->pdo();
        $pdo->prepare("INSERT INTO purchase_invoices(supplier_id,varsymbol,vendor_id,vendor_invoice_number,document_kind,issue_date,tax_date,due_date,received_at,currency_id,vendor_snapshot,total_without_vat,total_with_vat,status,created_by) SELECT id,'other',(SELECT id FROM clients WHERE supplier_id=? ORDER BY id LIMIT 1),'other','invoice','2097-07-01','2097-07-01','2097-07-20','2097-07-01',default_currency_id,'{}',1,1,'received',? FROM supplier WHERE id=?")->execute([$this->sid, $this->user, $this->sid]);
        return (int) $pdo->lastInsertId();
    }

    private function document(string $type): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id) SELECT id,'Synthetic party','Test','Test','10000',country_id,'party@example.invalid',default_currency_id FROM supplier WHERE id=?")->execute([$this->sid]);
        $partner = (int) $pdo->lastInsertId();
        $table = $type === 'invoice' ? 'invoices' : 'purchase_invoices';
        $party = $type === 'invoice' ? 'client_id' : 'vendor_id';
        $kind = $type === 'invoice' ? 'invoice_type' : 'document_kind';
        $extraColumns = $type === 'purchase_invoice' ? ',vendor_invoice_number,received_at,vendor_snapshot' : '';
        $extraValues = $type === 'purchase_invoice' ? ",'SYN-2097000801','2097-07-01','{}'" : '';
        $pdo->prepare("INSERT INTO {$table}(supplier_id,varsymbol,{$party},{$kind},issue_date,tax_date,due_date,currency_id,total_without_vat,total_vat,total_with_vat,status,created_by{$extraColumns}) SELECT id,'2097000801',?,'invoice','2097-07-01','2097-07-01','2097-07-20',default_currency_id,24000,0,24000,?,?{$extraValues} FROM supplier WHERE id=?")->execute([$partner, $type === 'invoice' ? 'issued' : 'received', $this->user, $this->sid]);
        $id = (int) $pdo->lastInsertId();
        Bootstrap::buildContainer()->get(PostingService::class)->postDocument($this->sid, $type, $id, [
            ['account_code' => $type === 'invoice' ? '311' : '321', 'side' => $type === 'invoice' ? 'debit' : 'credit', 'amount' => '24000.00', 'currency_code' => 'EUR', 'amount_foreign' => '1000.00', 'fx_rate' => '24.000000'],
            ['account_code' => $type === 'invoice' ? '602' : '518', 'side' => $type === 'invoice' ? 'credit' : 'debit', 'amount' => '24000.00'],
        ], ['entry_date' => '2097-07-01', 'posted' => true]);
        return $id;
    }

    private function settle(string $type, int $id, bool $cash): ?int
    {
        $c = Bootstrap::buildContainer();
        $pdo = $this->db->pdo();
        if ($cash) {
            $register = $c->get(\MyInvoice\Repository\CashRegisterRepository::class)->create($this->sid, ['name' => 'Synthetic CZK', 'currency_code' => 'CZK', 'account_code' => '211']);
            $c->get(\MyInvoice\Service\Accounting\Cash\CashDocumentService::class)->create($this->sid, ['register_id' => $register, 'purpose' => $type === 'invoice' ? 'invoice_payment' : 'purchase_payment', 'doc_type' => $type === 'invoice' ? 'in' : 'out', 'issue_date' => '2097-07-20', 'description' => 'Synthetic full payment', 'vat_mode' => 'none', 'total_amount' => 24000, $type === 'invoice' ? 'invoice_id' : 'purchase_invoice_id' => $id], $this->user);
            return null;
        }
        $pdo->prepare("INSERT INTO auto_posting_policy(supplier_id,operation_type,level,updated_by) VALUES (?,'bank.payment.matched','auto',?)")->execute([$this->sid, $this->user]);
        $pdo->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,imported_by) VALUES (?,'synthetic.csv',?,'1000000005','0100','CZK','2097-07-20',?)")->execute([$this->sid, hash('sha256', 'closed-fx-' . $this->sid), $this->user]);
        $statement = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions(statement_id,source,posted_at,amount,currency,description,match_status,matched_invoice_id) VALUES (?,'statement','2097-07-20',?,'CZK','Synthetic full payment','manual',?)")->execute([$statement, $type === 'invoice' ? 24000 : -24000, $type === 'invoice' ? $id : null]);
        $bank = (int) $pdo->lastInsertId();
        if ($type === 'invoice') $c->get(\MyInvoice\Service\Invoice\InvoicePaymentService::class)->recordPayment($id, 24000, '2097-07-20', ['source' => 'bank', 'bank_transaction_id' => $bank]);
        else $pdo->prepare("INSERT INTO payment_matches(supplier_id,bank_transaction_id,purchase_invoice_id,amount,match_type) VALUES (?,?,?,24000,'manual')")->execute([$this->sid, $bank, $id]);
        $result = $c->get(\MyInvoice\Service\Accounting\Bank\BankPostingService::class)->handleTransaction($bank, $this->user);
        self::assertSame('posted', $result['action'], json_encode($result));
        return $bank;
    }

    private function evidence(string $type, int $id, string $asOf, bool $current = false): array
    {
        $rows = array_values(array_filter((new ClosingRepository($this->db))->fxItemsWithSettlement($this->sid, $asOf, $current), static fn (array $row): bool => $row['item']['doc_type'] === $type && $row['item']['doc_id'] === $id));
        self::assertCount(1, $rows);
        return $rows[0]['evidence'];
    }
}
