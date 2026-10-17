<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\{ChartOfAccountsSeeder, PostingService};
use MyInvoice\Service\Accounting\Closing\ClosingService;
use PHPUnit\Framework\TestCase;

final class FxNativeRemainingPaymentProofTest extends TestCase
{
    private Connection $db;
    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['native'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['unmatch'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['delete'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['historical_unmatch'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['historical_matches'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['both_representations'])]
    public function testRemainingForeignUsesAuthoritativePaymentExactlyOnce(string $mode): void
    {
        $c=Bootstrap::buildContainer();
        $this->db=$c->get(Connection::class);
        $pdo=$this->db->pdo();
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode)
            SELECT 'Synthetic FX remaining proof','Test1','Test','10000','fx-remaining@example.com',country_id,default_currency_id,default_vat_rate_id,'double_entry' FROM supplier ORDER BY id LIMIT 1");
        $sid=(int)$pdo->lastInsertId();
        $c->get(ChartOfAccountsSeeder::class)->seedForSupplier($sid);
        $pid=$c->get(AccountingPeriodRepository::class)->create($sid,2098,'2098-01-01','2098-12-31');
        $user=(int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->exec("INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES ('2098-06-15','EUR',24),('2098-09-15','EUR',24),('2098-12-31','EUR',25) ON DUPLICATE KEY UPDATE rate=VALUES(rate)");
        $invoice=$this->invoice($sid,24,'2098-06-15','2098000801');
        $tx=$this->payment($sid,$invoice,'2098-09-15',$user);
        if (in_array($mode,['historical_matches','both_representations','historical_unmatch'],true)) {
            $pdo->prepare("INSERT INTO payment_matches(supplier_id,bank_transaction_id,invoice_id,amount,match_type) VALUES (?,?,?,500,'manual')")->execute([$sid,$tx,$invoice]);
            if (in_array($mode,['historical_matches','historical_unmatch'],true)) $pdo->prepare('DELETE FROM invoice_payments WHERE supplier_id=? AND bank_transaction_id=?')->execute([$sid,$tx]);
        }
        $released=in_array($mode,['unmatch','delete','historical_unmatch'],true);
        if ($released) {
            $c->get(\MyInvoice\Service\Bank\BankTransactionReleaseService::class)->release($sid,$tx,$mode==='delete'?\MyInvoice\Service\Bank\BankTransactionReleaseService::MODE_DELETE:\MyInvoice\Service\Bank\BankTransactionReleaseService::MODE_UNMATCH,$user);
            self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id='.$tx)->fetchColumn());
            self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM payment_matches WHERE bank_transaction_id='.$tx)->fetchColumn());
            self::assertGreaterThan(0,(int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE supplier_id=$sid AND reversed_by IS NOT NULL")->fetchColumn());
        }
        $preview=$c->get(ClosingService::class)->fxPreview($sid,$pid);
        self::assertCount(1,$preview['saldo']['detail']);
        self::assertSame($released?1000.0:500.0,$preview['saldo']['detail'][0]['remaining_foreign'],json_encode($preview['totals']));
        self::assertSame(['loss'=>0.0,'gain'=>$released?1000.0:500.0],$preview['totals']);
    }

    private function payment(int $sid,int $invoice,string $date,int $user): int
    {
        $pdo=$this->db->pdo();
        $pdo->prepare("UPDATE currencies SET account_number='1000000005',bank_code='0100' WHERE supplier_id=? AND code='EUR'")->execute([$sid]);
        $pdo->prepare("INSERT INTO auto_posting_policy(supplier_id,operation_type,level,updated_by) VALUES (?,'bank.payment.matched','auto',?) ON DUPLICATE KEY UPDATE level='auto'")->execute([$sid,$user]);
        $pdo->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,imported_by) VALUES (?,'synthetic-fx.csv',?,'1000000005','0100','EUR',?,?)")->execute([$sid,hash('sha256','synthetic-fx-'.$sid),$date,$user]);
        $statement=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions(statement_id,source,posted_at,amount,currency,description,match_status,matched_invoice_id) VALUES (?,'statement',?,500,'EUR','Synthetic partial payment','manual',?)")->execute([$statement,$date,$invoice]);
        $tx=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO invoice_payments(supplier_id,invoice_id,paid_on,amount,currency,source,bank_transaction_id) VALUES (?,?,?,500,'EUR','bank',?)")->execute([$sid,$invoice,$date,$tx]);
        $result=Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Bank\BankPostingService::class)->handleTransaction($tx,$user);
        self::assertSame('posted',$result['action'],json_encode($result));
        $stmt=$pdo->prepare("SELECT l.amount,l.amount_foreign FROM journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id JOIN chart_of_accounts a ON a.id=l.account_id WHERE e.supplier_id=? AND e.source_type='bank' AND e.source_id=? AND a.account_code='311'");
        $stmt->execute([$sid,$tx]);
        self::assertSame(['amount'=>'12000.00','amount_foreign'=>'500.00'],$stmt->fetch(\PDO::FETCH_ASSOC));
        return $tx;
    }

    private function invoice(int $sid, float $rate, string $date, string $symbol): int
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
        $pdo->prepare("INSERT INTO invoices(supplier_id,varsymbol,invoice_type,client_id,issue_date,tax_date,due_date,currency_id,exchange_rate,total_without_vat,total_vat,total_with_vat,status,vat_classification_code,created_by)
            VALUES (?,?,'invoice',?,?,?,'2098-06-30',?,?,1000,0,1000,'issued','1',(SELECT id FROM users ORDER BY id LIMIT 1))")->execute([$sid, $symbol, $client, $date, $date, $eur, $rate]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO invoice_items(invoice_id,description,quantity,unit,unit_price_without_vat,vat_rate_id,vat_rate_snapshot,total_without_vat,total_vat,total_with_vat,order_index)
            VALUES (?,'Synthetic service',1,'ks',1000,(SELECT id FROM vat_rates ORDER BY id LIMIT 1),0,1000,0,1000,0)")->execute([$id]);
        $posting = Bootstrap::buildContainer()->get(PostingService::class);
        $posting->postDocument($sid, 'invoice', $id, $posting->buildFromInvoice($sid, $id), ['entry_date' => $date, 'posted' => true]);
        return $id;
    }
}
