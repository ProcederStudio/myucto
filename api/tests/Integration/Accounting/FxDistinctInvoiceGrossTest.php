<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountingPeriodRepository, JournalEntryRepository};
use MyInvoice\Service\Accounting\{ChartOfAccountsSeeder, PostingService};
use MyInvoice\Service\Accounting\Closing\ClosingService;
use PHPUnit\Framework\TestCase;

final class FxDistinctInvoiceGrossTest extends TestCase
{
    private Connection $db;
    private int $committedSupplier = 0;
    private ?Connection $other = null;
    private ?int $snapshotIsolation = null;
    private array $previousRates = [];
    private const RATE_DATES = "'2098-06-15','2098-06-16','2098-12-31'";

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
        $this->other?->close();
        if ($this->committedSupplier) {
            foreach (['activity_log', 'journal_entries', 'invoices', 'purchase_invoices', 'clients', 'currencies'] as $table) $this->db->pdo()->prepare('DELETE FROM ' . $table . ' WHERE supplier_id=?')->execute([$this->committedSupplier]);
            $this->db->pdo()->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->committedSupplier]);
            $this->db->pdo()->exec("DELETE FROM exchange_rates WHERE currency_code='EUR' AND rate_date IN (" . self::RATE_DATES . ')');
            foreach ($this->previousRates as $row) $this->db->pdo()->prepare('INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES (?,?,?)')->execute([$row['rate_date'],$row['currency_code'],$row['rate']]);
        }
        if ($this->snapshotIsolation !== null) $this->db->pdo()->exec('SET SESSION innodb_snapshot_isolation=' . $this->snapshotIsolation);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['preview'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['repeat'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['legacy_net'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['tuple'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['purchase'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['current_tuple'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['current_membership'])]
    public function testDistinctInvoicesRetainGrossExchangeGainAndLoss(string $mode): void
    {
        $c = Bootstrap::buildContainer();
        $this->db = $c->get(Connection::class);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode)
            SELECT 'Synthetic distinct FX cases','Test 1','Test','10000','fx-cases@example.com',country_id,default_currency_id,default_vat_rate_id,'double_entry' FROM supplier ORDER BY id LIMIT 1");
        $sid = (int) $pdo->lastInsertId();
        $c->get(ChartOfAccountsSeeder::class)->seedForSupplier($sid);
        $pid = $c->get(AccountingPeriodRepository::class)->create($sid, 2098, '2098-01-01', '2098-12-31');
        $this->previousRates = $pdo->query("SELECT rate_date,currency_code,rate FROM exchange_rates WHERE currency_code='EUR' AND rate_date IN (" . self::RATE_DATES . ')')->fetchAll(\PDO::FETCH_ASSOC);
        $pdo->exec("INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES ('2098-06-15','EUR',24),('2098-06-16','EUR',26),('2098-12-31','EUR',25) ON DUPLICATE KEY UPDATE rate=VALUES(rate)");
        $this->invoice($sid, 24, '2098-06-15', '2098000101', $mode === 'purchase');
        $this->invoice($sid, $mode === 'legacy_net' ? 25.5 : 26, '2098-06-16', '2098000102', $mode === 'purchase');
        if (str_starts_with($mode, 'current_')) {
            $pdo->commit();
            $this->committedSupplier = $sid;
            $this->snapshotIsolation = (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
            $pdo->exec('SET SESSION innodb_snapshot_isolation=OFF');
            $other = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer());
            $this->other = Connection::withoutSharedTestConnection(fn () => $other->get(Connection::class));
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->beginTransaction();
            $period = ['id' => $pid, 'starts_on' => '2098-01-01', 'ends_on' => '2098-12-31'];
            $repo = $c->get(\MyInvoice\Repository\ClosingRepository::class);
            $before = $repo->fxCaseSnapshot($sid, $period);
            $projector = new \MyInvoice\Service\Accounting\Closing\FxCaseCarryingProjection();
            self::assertTrue($projector->project($before, $period)['complete']);
            if ($mode === 'current_tuple') {
                $line = $before['items'][0]['item']['source_line_id'];
                $this->other->pdo()->prepare("UPDATE journal_entry_lines SET cost_center='CURRENT-FX' WHERE supplier_id=? AND id=?")->execute([$sid, $line]);
            } else {
                $other->get(PostingService::class)->postDocument($sid, 'fx_revaluation', 987654321, [
                    ['account_code' => '311', 'side' => 'debit', 'amount' => 10, 'currency_code' => 'EUR', 'amount_foreign' => 0, 'fx_rate' => 25],
                    ['account_code' => '663', 'side' => 'credit', 'amount' => 10],
                ], ['entry_date' => '2098-12-31', 'posted' => true]);
            }
            $current = $repo->fxCaseSnapshot($sid, $period, true);
            self::assertTrue($pdo->inTransaction());
            self::assertSame($before, $repo->fxCaseSnapshot($sid, $period), 'Current reads must preserve the caller snapshot.');
            if ($mode === 'current_tuple') {
                self::assertSame('CURRENT-FX', $current['items'][0]['item']['source_tuple']['cost_center']);
                self::assertNotSame($before['items'][0]['item']['source_proof_hash'], $current['items'][0]['item']['source_proof_hash']);
            } else {
                self::assertSame(['historical_fx_entry_unattributed'], $projector->project($current, $period)['findings']);
            }
            return;
        }
        if ($mode === 'tuple') {
            $pdo->exec("UPDATE journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id JOIN chart_of_accounts a ON a.id=l.account_id SET l.cost_center=CONCAT('FX-',e.source_id) WHERE e.supplier_id=$sid AND e.source_type='invoice' AND a.account_code='311'");
            $pdo->exec("UPDATE supplier SET dimensions_enabled=1 WHERE id=$sid");
            $pdo->prepare("INSERT INTO dimension_types(supplier_id,code,name) VALUES (?,'FXCASE','Synthetic FX case')")->execute([$sid]);
            $type=(int)$pdo->lastInsertId();
            $values=[];
            foreach (['A','B'] as $code) {
                $pdo->prepare('INSERT INTO dimension_values(supplier_id,type_id,code,name) VALUES (?,?,?,?)')->execute([$sid,$type,$code,'Synthetic '.$code]);
                $values[]=(int)$pdo->lastInsertId();
            }
            $sourceIds=array_map('intval',$pdo->query("SELECT l.id FROM journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id WHERE e.supplier_id=$sid AND e.source_type='invoice' AND l.currency_code='EUR' ORDER BY l.id")->fetchAll(\PDO::FETCH_COLUMN));
            $assignment=$c->get(\MyInvoice\Repository\DimensionAssignmentRepository::class);
            foreach ($sourceIds as $lineId) $assignment->insertLineSplits($sid,$lineId,[$type=>[$values[0]=>0.25,$values[1]=>0.75]]);
        }
        $closing = $c->get(ClosingService::class);
        if ($mode !== 'preview') {
            $version = fn (): int => (int) $pdo->query('SELECT row_version FROM accounting_periods WHERE id=' . $pid)->fetchColumn();
            $closing->start($sid, $pid, $version());
            $first = $mode === 'legacy_net' ? \MyInvoice\Tests\Support\LegacyFxNetFixture::install($sid, $pid) : $closing->runFxRevaluation($sid, $pid, [], $version());
            if ($mode === 'legacy_net') {
                self::assertNotEmpty($first['entry_ids'], 'Native historical net journal must really exist.');
                $row = $pdo->query("SELECT SUM(l.amount) FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.supplier_id=$sid AND a.account_code='663' AND l.entry_id IN (" . implode(',', array_map('intval', $first['entry_ids'])) . ')')->fetchColumn();
                self::assertSame('500.00', $row, 'Fixture contains the native500 old net gain.');
            }
            $closing->runFxRevaluation($sid, $pid, [], $version());
        }
        $preview = $c->get(ClosingService::class)->fxPreview($sid, $pid);
        self::assertCount(2, $preview['saldo']['detail']);
        $diffs = array_column($preview['saldo']['detail'], 'diff');
        sort($diffs);
        self::assertSame([$mode === 'legacy_net' ? -500.0 : -1000.0, 1000.0], $diffs);
        self::assertSame(['loss' => $mode === 'legacy_net' ? 500.0 : 1000.0, 'gain' => 1000.0], $preview['totals']);
        if ($mode !== 'preview') {
            $statement = $c->get(\MyInvoice\Service\Accounting\Reports\FinancialStatementService::class)->incomeStatement($sid,$pid,'2098-12-31','full');
            $amounts = array_column($statement['rows'],'amount','row_code');
            self::assertSame(1000.0,$amounts['VII.']);
            self::assertSame($mode === 'legacy_net' ? 500.0 : 1000.0,$amounts['K.']);
            self::assertSame($mode === 'purchase' ? -50000.0 : 50000.0,$statement['checks']['profit_current']);
            self::assertTrue($statement['checks']['profit_matches']);
        }
        if ($mode === 'tuple') {
            $rows=$pdo->query("SELECT l.id,l.cost_center FROM journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id WHERE e.supplier_id=$sid AND e.source_type='fx_revaluation' ORDER BY l.id")->fetchAll(\PDO::FETCH_ASSOC);
            self::assertCount(4,$rows);
            foreach ($rows as $row) {
                self::assertStringStartsWith('FX-', (string)$row['cost_center']);
                self::assertSame([$type=>[$values[0]=>0.25,$values[1]=>0.75]],$assignment->lineSplits($sid,[(int)$row['id']])[(int)$row['id']]??[]);
            }
        }
    }

    private function invoice(int $sid, float $rate, string $date, string $symbol, bool $purchase = false): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare("SELECT id FROM currencies WHERE supplier_id=? AND code='EUR'");
        $stmt->execute([$sid]);
        $eur = (int) $stmt->fetchColumn();
        if (!$eur) {
            $pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default) VALUES (?,'EUR','EUR','EUR','Euro','Euro',2,1,0)")->execute([$sid]);
            $eur = (int) $pdo->lastInsertId();
        }
        $pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id)
            SELECT id,'Synthetic FX client','Test','Test','10000',country_id,'fx-client@example.com',? FROM supplier WHERE id=?")->execute([$eur, $sid]);
        $client = (int) $pdo->lastInsertId();
        if ($purchase) {
            $pdo->prepare("INSERT INTO purchase_invoices(supplier_id,varsymbol,vendor_id,vendor_invoice_number,document_kind,issue_date,tax_date,due_date,received_at,currency_id,exchange_rate,vendor_snapshot,total_without_vat,total_with_vat,status,created_by) VALUES (?,?,?,?,'invoice',?,?,'2098-06-30',?,?,?,'{}',1000,1000,'received',(SELECT id FROM users ORDER BY id LIMIT 1))")->execute([$sid,$symbol,$client,'SYN-'.$symbol,$date,$date,$date,$eur,$rate]);
            $id=(int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO purchase_invoice_items(purchase_invoice_id,description,quantity,unit,unit_price_without_vat,vat_rate_id,vat_rate_snapshot,total_without_vat,total_vat,total_with_vat,order_index) VALUES (?,'Synthetic service',1,'ks',1000,(SELECT id FROM vat_rates ORDER BY id LIMIT 1),0,1000,0,1000,0)")->execute([$id]);
            Bootstrap::buildContainer()->get(PostingService::class)->postDocument($sid,'purchase_invoice',$id,[
                ['account_code'=>'518','side'=>'debit','amount'=>$rate*1000],
                ['account_code'=>'321','side'=>'credit','amount'=>$rate*1000,'currency_code'=>'EUR','fx_rate'=>$rate,'amount_foreign'=>1000],
            ],['entry_date'=>$date,'posted'=>true]);
            return;
        }
        $pdo->prepare("INSERT INTO invoices(supplier_id,varsymbol,invoice_type,client_id,issue_date,tax_date,due_date,currency_id,exchange_rate,total_without_vat,total_vat,total_with_vat,status,vat_classification_code,created_by)
            VALUES (?,?,'invoice',?,?,?,'2098-06-30',?,?,1000,0,1000,'issued','1',(SELECT id FROM users ORDER BY id LIMIT 1))")->execute([$sid, $symbol, $client, $date, $date, $eur, $rate]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO invoice_items(invoice_id,description,quantity,unit,unit_price_without_vat,vat_rate_id,vat_rate_snapshot,total_without_vat,total_vat,total_with_vat,order_index)
            VALUES (?,'Synthetic service',1,'ks',1000,(SELECT id FROM vat_rates ORDER BY id LIMIT 1),0,1000,0,1000,0)")->execute([$id]);
        $posting = Bootstrap::buildContainer()->get(PostingService::class);
        $posting->postDocument($sid, 'invoice', $id, $posting->buildFromInvoice($sid, $id), ['entry_date' => $date, 'posted' => true]);
    }
}
