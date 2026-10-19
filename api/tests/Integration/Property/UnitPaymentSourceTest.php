<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Property\Payments\PaymentLocks;
use MyInvoice\Service\Property\Payments\PaymentSourceProof;
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PHPUnit\Framework\TestCase;
final class UnitPaymentSourceTest extends TestCase
{
    use ChargeIssuanceFixture;
    use \MyInvoice\Tests\Support\PaymentCorrectionFixture;
    private Connection $db;
    private array $f;
    protected function setUp(): void
    {
        $this->db=Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test',(string)$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction();$this->f=$this->issuanceFixture($this->db);
        $this->db->pdo()->prepare("INSERT INTO currencies (supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default) VALUES (?,'EUR','Synthetic EUR','EUR','EUR','EUR',2,1,0)")->execute([$this->f['supplier']]);
    }
    protected function tearDown(): void {if($this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();}
    public function testCurrentActualBankReceiptExposesExactCapacityAndRejectsForeignTenant(): void
    {
        $id=$this->bank('100.00');(new PaymentLocks($this->db))->acquire($this->f['supplier']);
        $proof=(new PaymentSourceProof($this->db))->current($this->f['supplier'],'bank',$id);
        self::assertSame('10000',$proof['available_cents']);self::assertSame('in',$proof['receipt']['direction']);
        self::assertSame('379.900',$proof['receipt']['clearing_line']['account_code']);
        try{(new PaymentSourceProof($this->db))->current($this->f['supplier']+100000,'bank',$id);self::fail('Foreign source accepted.');}
        catch(PaymentException $e){self::assertSame('property_payment_source_not_found',$e->errorCode);}
    }
    public function testForeignBankSourceUsesFrozenCzkCarrierAndAllocatesNativeExactly(): void
    {
        $id=$this->foreignBank('100.00','2500.00','25.000000');(new PaymentLocks($this->db))->acquire($this->f['supplier']);
        $proof=(new PaymentSourceProof($this->db))->current($this->f['supplier'],'bank',$id);
        self::assertSame('250000',$proof['available_cents']);
        self::assertSame('EUR',$proof['receipt']['currency']);
        self::assertSame(['currency'=>'EUR','amount'=>'100.00','amount_minor'=>'10000','minor_unit_decimals'=>2,'fx_rate'=>'25.000000'],$proof['receipt']['native']);
        $part=\MyInvoice\Service\Property\Payments\Workflow\PaymentSourcePreview::clearingLines($proof,'50000',null);
        self::assertSame([['key'=>'original','amount_cents'=>'50000','native_minor'=>'2000']],$part['selection']);
        self::assertSame('500.00',$part['lines'][0]['amount']);
        self::assertSame('20.00',$part['lines'][0]['amount_foreign']);
        self::assertSame('25.000000',$part['lines'][0]['fx_rate']);
        self::assertSame('EUR',$part['lines'][0]['currency_code']);
        $eligibility=$this->paymentFixtureContainer()->get(\MyInvoice\Service\Property\Payments\Workflow\PaymentSourceEligibility::class);
        $facts=$eligibility->current($this->f['supplier'],['kind'=>'bank','id'=>$id]);
        self::assertSame('250000',$facts['amount_cents']);
        self::assertSame($proof['receipt']['native'],$facts['native']);
        $journal=(new \MyInvoice\Service\Property\Payments\Workflow\PaymentWorkflowJournalPlan($this->db))->resolve($this->f['supplier'],'2026-02-01','unit_payment',[
            $part['lines'][0],
            ['account_code'=>'379.910','side'=>'credit','amount'=>'500.00'],
        ]);
        self::assertSame('20.00',$journal['lines'][0]['amount_foreign']);
        try {
            $credit=$this->creditRequest($id,'50000',$this->f['clients'][1],0);
            $this->correctionPayments()->preview($this->f['supplier'],$credit);
            self::fail('Foreign payment source created an implicitly converted credit.');
        } catch (PaymentException $e) {
            self::assertSame('property_payment_fx_review_required',$e->errorCode);
        }
    }
    public function testSameAmountPostedAgainstReceivableIsNotAnotherPaymentSource(): void
    {
        $id=$this->bank('100.00','315');(new PaymentLocks($this->db))->acquire($this->f['supplier']);
        $this->expectException(PaymentException::class);(new PaymentSourceProof($this->db))->current($this->f['supplier'],'bank',$id);
    }
    public function testForeignBankSourceSettlesOnlyProvedCzkDebtAndReversalRestoresBothValues(): void
    {
        $claim=$this->documentedCzkDebt();$source=$this->foreignBank('100.00','2500.00','25.000000');
        $request=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$source,'expected_version'=>0],'amount_cents'=>'50000','accounting_date'=>'2026-02-01','reason'=>'Synthetic EUR payment of documented CZK debt','debts'=>[$claim['debt']['id']],
            'payment'=>['payer_id'=>$claim['debtor'],'debtor_id'=>$claim['debtor'],'received_at'=>'2026-02-01T10:00:00+01:00','choice'=>['claim_ids'=>[$claim['debt']['id']],'evidence'=>'Synthetic designation of the documented CZK debt']]];
        $repo=$this->correctionPayments();$preview=$repo->preview($this->f['supplier'],$request);
        self::assertSame([['key'=>'original','amount_cents'=>'50000','native_minor'=>'2000']],$preview['snapshot']['clearing_selection']);
        $operation=$repo->apply($this->f['supplier'],$request,$preview['preview_hash'],'foreign-czk-debt',$this->f['actor']);
        self::assertSame('posted',$operation['status']);
        $carrier=array_values(array_filter($operation['snapshot']['lines'],static fn(array $line):bool=>$line['account_code']==='379.900'));
        self::assertCount(1,$carrier);self::assertSame('500.00',$carrier[0]['amount']);self::assertSame('20.00',$carrier[0]['amount_foreign']);self::assertSame('EUR',$carrier[0]['currency_code']);
        $proof=(new PaymentSourceProof($this->db))->current($this->f['supplier'],'bank',$source);
        self::assertSame('50000',$proof['consumed_cents']);self::assertSame('200000',$proof['available_cents']);
        self::assertSame('2000',$proof['consumed_native_minor']);self::assertSame('8000',$proof['available_native_minor']);
        $q=$this->db->pdo()->prepare('SELECT o.source_amount_cents,o.source_native_minor,s.currency_code,s.native_amount_minor,s.consumed_native_minor,s.fx_rate FROM unit_payment_operations o JOIN unit_payment_sources s ON s.supplier_id=o.supplier_id AND s.id=o.source_id WHERE o.supplier_id=? AND o.id=?');
        $q->execute([$this->f['supplier'],$operation['id']]);
        self::assertSame(['source_amount_cents'=>'50000','source_native_minor'=>'2000','currency_code'=>'EUR','native_amount_minor'=>'10000','consumed_native_minor'=>'2000','fx_rate'=>'25.000000'],$q->fetch(\PDO::FETCH_ASSOC));
        $reversal=$repo->reverse($this->f['supplier'],$operation['id'],$operation['row_version'],'2026-02-02','Synthetic exact reversal','foreign-czk-debt-reversal',$this->f['actor']);
        self::assertSame('posted',$reversal['status']);
        $restored=(new PaymentSourceProof($this->db))->current($this->f['supplier'],'bank',$source);
        self::assertSame('0',$restored['consumed_cents']);self::assertSame('250000',$restored['available_cents']);
        self::assertSame('0',$restored['consumed_native_minor']);self::assertSame('10000',$restored['available_native_minor']);
        self::assertSame('20.00',$reversal['snapshot']['lines'][0]['amount_foreign']);
    }
    public function testForeignCashSourceUsesOnlyItsPostedOwnerReceipt(): void
    {
        $id=$this->foreignCash('100.00','2500.00','25.000000');(new PaymentLocks($this->db))->acquire($this->f['supplier']);
        $proof=(new PaymentSourceProof($this->db))->current($this->f['supplier'],'cash',$id);
        self::assertSame('250000',$proof['available_cents']);self::assertSame('10000',$proof['available_native_minor']);
        self::assertSame('EUR',$proof['receipt']['currency']);self::assertSame('100.00',$proof['receipt']['native']['amount']);
        $facts=$this->paymentFixtureContainer()->get(\MyInvoice\Service\Property\Payments\Workflow\PaymentSourceEligibility::class)->current($this->f['supplier'],['kind'=>'cash','id'=>$id]);
        self::assertSame('250000',$facts['amount_cents']);self::assertSame('10000',$facts['native']['amount_minor']);
    }
    public function testForeignSourceRejectsTamperedFrozenRate(): void
    {
        $id=$this->foreignBank('100.00','2500.00','25.000000');
        $q=$this->db->pdo()->prepare("UPDATE journal_entry_lines l JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id SET l.fx_rate='26.000000' WHERE l.supplier_id=? AND a.account_code='379.900' AND l.entry_id=(SELECT id FROM journal_entries WHERE supplier_id=? AND source_type='bank' AND source_id=?)");
        $q->execute([$this->f['supplier'],$this->f['supplier'],$id]);(new PaymentLocks($this->db))->acquire($this->f['supplier']);
        try{(new PaymentSourceProof($this->db))->current($this->f['supplier'],'bank',$id);self::fail('Tampered frozen rate was accepted.');}
        catch(PaymentException $e){self::assertSame('property_payment_source_clearing_mismatch',$e->errorCode);}
    }
    public function testUnpostedForeignSourceStillRequiresBankOwner(): void
    {
        $pdo=$this->db->pdo();$pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Synthetic unposted FX',?,'1000000005','0100','EUR','2026-02-01')")->execute([$this->f['supplier'],hash('sha256',random_bytes(20))]);$statement=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions (statement_id,posted_at,amount,currency,counterparty_account,counterparty_bank) VALUES (?,'2026-02-01','100.00','EUR','1000000005','0100')")->execute([$statement]);$id=(int)$pdo->lastInsertId();
        try{$this->paymentFixtureContainer()->get(\MyInvoice\Service\Property\Payments\Workflow\PaymentSourceEligibility::class)->current($this->f['supplier'],['kind'=>'bank','id'=>$id]);self::fail('Unposted FX source bypassed its bank owner.');}
        catch(PaymentException $e){self::assertSame('property_payment_fx_review_required',$e->errorCode);}
    }
    public function testForeignSourceRejectsCurrencyWithoutTwoDecimalCapability(): void
    {
        $pdo=$this->db->pdo();$insert=$pdo->prepare('INSERT INTO currencies (supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default) VALUES (?,?,?,?,?,?,?,1,0)');
        foreach([['JPY',0],['KWD',3]] as [$currency,$decimals]){
            $insert->execute([$this->f['supplier'],$currency,'Synthetic '.$currency,$currency,$currency,$currency,$decimals]);
            $id=$this->foreignBank('100.00','2500.00','25.000000',$currency);(new PaymentLocks($this->db))->acquire($this->f['supplier']);
            try{(new PaymentSourceProof($this->db))->current($this->f['supplier'],'bank',$id);self::fail($currency.' source was presented as two-decimal native minor units.');}
            catch(PaymentException $e){self::assertSame('property_payment_fx_review_required',$e->errorCode);self::assertSame('foreign_currency_two_decimals',$e->details['capability']??null);}
        }
    }
    public function testAggregateSourceCreditAndPartialRefundNeverDoubleBooksMoney(): void
    {
        $repo=Bootstrap::buildContainer()->get(\MyInvoice\Repository\Property\Payments\PaymentRepository::class);
        $bank=$this->bank('100.00');$request=$this->creditRequest($bank,'6000',$this->f['clients'][1],0);
        $first=$repo->apply($this->f['supplier'],$request,$repo->preview($this->f['supplier'],$request)['preview_hash'],'first',$this->f['actor']);
        self::assertSame('6000',$repo->credit($this->f['supplier'],$first['credit_id'])['balance_cents']);
        $request2=$this->creditRequest($bank,'4000',$this->f['clients'][2],1);
        $second=$repo->apply($this->f['supplier'],$request2,$repo->preview($this->f['supplier'],$request2)['preview_hash'],'second',$this->f['actor']);
        self::assertNotSame($first['credit_id'],$second['credit_id']);
        $excess=$this->creditRequest($bank,'1',$this->f['clients'][1],2);
        try{$repo->preview($this->f['supplier'],$excess);self::fail('Source consumed twice.');}catch(PaymentException $e){self::assertSame('property_payment_source_capacity',$e->errorCode);}
        $reservation=$repo->reserve($this->f['supplier'],$first['credit_id'],1,['amount_cents'=>'5000','party_ids'=>[$this->f['clients'][1]],'disposition'=>'Synthetic authenticated refund instruction','recipient_account'=>'1000000005/0100'],'reserve',$this->f['actor']);
        self::assertSame('1000',$repo->credit($this->f['supplier'],$first['credit_id'])['available_cents']);
        $out=$this->bank('-50.00');
        $refund=['kind'=>'refund','source'=>['kind'=>'bank','id'=>$out,'expected_version'=>0],'amount_cents'=>'3000','accounting_date'=>'2026-02-01','reservation_id'=>$reservation['id'],'expected_reservation_version'=>1,'reason'=>'Synthetic partial refund'];
        $hash=$repo->preview($this->f['supplier'],$refund)['preview_hash'];$paid=$repo->apply($this->f['supplier'],$refund,$hash,'refund',$this->f['actor']);
        self::assertSame($paid['id'],$repo->apply($this->f['supplier'],$refund,$hash,'refund',$this->f['actor'])['id']);
        $credit=$repo->credit($this->f['supplier'],$first['credit_id']);
        self::assertSame('3000',$credit['balance_cents']);self::assertSame('2000',$credit['reserved_cents']);self::assertSame('1000',$credit['available_cents']);
        (new PaymentLocks($this->db))->acquire($this->f['supplier']);self::assertSame('2000',(new PaymentSourceProof($this->db))->current($this->f['supplier'],'bank',$out)['available_cents']);
        $q=$this->db->pdo()->prepare("SELECT COUNT(*) FROM journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id JOIN chart_of_accounts a ON a.id=l.account_id WHERE e.supplier_id=? AND e.source_type='unit_payment' AND (a.account_code LIKE '221%' OR a.account_code LIKE '211%')");$q->execute([$this->f['supplier']]);self::assertSame(0,(int)$q->fetchColumn());
    }
    public function testSjmClaimUsesActualTitleAndOnePrincipalDespiteTwoDebtors(): void
    {
        $supplier=$this->f['supplier'];$actor=$this->f['actor'];
        $issuance=Bootstrap::buildContainer()->get(\MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository::class);
        $preview=(new \MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService($this->db))->preview($supplier,$this->f['issue_request']);
        $batch=$issuance->issue($supplier,['request'=>$this->f['issue_request'],'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'synthetic-claims'],$actor);
        $batch=$issuance->post($supplier,$batch['id'],array_map(fn($c)=>['id'=>$c['id'],'row_version'=>1],$batch['charges']),$actor);
        $charge=null;foreach($batch['charges'] as $candidate)foreach($candidate['snapshot']['liability_groups'] as $group)if($group['basis']==='sjm_713'){$charge=$candidate;break 2;}
        self::assertNotNull($charge);
        $q=$this->db->pdo()->prepare('SELECT id FROM unit_charge_lines WHERE supplier_id=? AND charge_id=? ORDER BY id');$q->execute([$supplier,$charge['id']]);$lineIds=array_map('intval',$q->fetchAll(\PDO::FETCH_COLUMN));
        $debts=new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db);
        $title=['charge_id'=>$charge['id'],'expected_version'=>$charge['row_version'],'principal_line_ids'=>$lineIds,'title_ref'=>'Synthetic same legal obligation evidenced across three accounting components','due_date'=>$charge['due_date'],
            'legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-15','evidence'=>'Specific synthetic decision giving rise to this debt','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Documented absence of security'],'damages_for'=>null];
        $debt=$debts->register($supplier,$title,'debt',$actor);
        try{$debts->register($supplier,$title,'duplicate-title',$actor);self::fail('Same principal bound twice.');}catch(PaymentException $e){self::assertSame('property_payment_line_has_debt',$e->errorCode);}
        $amount=$charge['amount_cents'];$bank=$this->bank(bcdiv($amount,'100',2));$member=$charge['snapshot']['liability_groups'][0]['members'][0];
        $request=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>$amount,'accounting_date'=>'2026-02-01','reason'=>'Synthetic full settlement','debts'=>[$debt['id']],
            'payment'=>['payer_id'=>$this->f['clients'][18],'debtor_id'=>$member,'received_at'=>'2026-02-01T10:00:00+01:00','third_party'=>['basis'=>'debtor_consent','evidence'=>'Synthetic debtor consent'],
                'choice'=>['claim_ids'=>[$debt['id']],'evidence'=>'Specific designation by debtor at payment']]];
        $repo=Bootstrap::buildContainer()->get(\MyInvoice\Repository\Property\Payments\PaymentRepository::class);$plan=$repo->preview($supplier,$request);
        self::assertSame($amount,$plan['snapshot']['resolution']['allocated_cents']);self::assertSame('0',$plan['snapshot']['resolution']['credit_cents']);
        $result=$repo->apply($supplier,$request,$plan['preview_hash'],'settle',$actor);self::assertNull($result['credit_id']);
        $q=$this->db->pdo()->prepare('SELECT SUM(amount_cents),COUNT(*) FROM unit_payment_allocations WHERE supplier_id=? AND operation_id=?');$q->execute([$supplier,$result['id']]);$totals=$q->fetch(\PDO::FETCH_NUM);
        self::assertSame($amount,$totals[0]);self::assertSame(count($lineIds),(int)$totals[1]);
        $current=$debts->current($supplier,$debt['id']);self::assertSame($amount,array_reduce($current['paid'],fn($sum,$v)=>bcadd($sum,$v,0),'0'));
    }
    public function testVerifiedReversalReleasesSourceCapacityAndRetryCannotCreateNewCredit():void
    {
        $repo=Bootstrap::buildContainer()->get(\MyInvoice\Repository\Property\Payments\PaymentRepository::class);$supplier=$this->f['supplier'];$actor=$this->f['actor'];
        $bank=$this->bank('100.00');$request=$this->creditRequest($bank,'10000',$this->f['clients'][1],0);$hash=$repo->preview($supplier,$request)['preview_hash'];$op=$repo->apply($supplier,$request,$hash,'credit',$actor);
        (new PaymentLocks($this->db))->acquire($supplier);
        try{(new \MyInvoice\Service\Property\Payments\PaymentUseGuard($this->db))->assertEntriesMutable($supplier,[$op['journal_entry_id']]);self::fail('Owned entry mutable.');}catch(PaymentException $e){self::assertSame('property_payment_source_immutable',$e->errorCode);}
        $reservation=$repo->reserve($supplier,$op['credit_id'],1,['amount_cents'=>'1000','party_ids'=>[$this->f['clients'][1]],'disposition'=>'Synthetic instruction','recipient_account'=>'1000000005/0100'],'reserve',$actor);
        try{$repo->reverse($supplier,$op['id'],2,'2026-02-02','Synthetic correction','reverse',$actor);self::fail('Reserved credit reversed.');}catch(PaymentException $e){self::assertSame('property_payment_credit_in_use',$e->errorCode);}
        $repo->cancelReservation($supplier,$reservation['id'],1,'Synthetic withdrawal','cancel',$actor);
        $reversed=$repo->reverse($supplier,$op['id'],2,'2026-02-02','Synthetic correction','reverse',$actor);
        self::assertSame('reversed',$repo->operation($supplier,$op['id'])['status']);
        self::assertSame($reversed['id'],$repo->reverse($supplier,$op['id'],2,'2026-02-02','Synthetic correction','reverse',$actor)['id']);
        self::assertSame($op['id'],$repo->apply($supplier,$request,$hash,'credit',$actor)['id']);
        self::assertSame('0',$repo->credit($supplier,$op['credit_id'])['balance_cents']);
        self::assertSame('10000',(new PaymentSourceProof($this->db))->current($supplier,'bank',$bank)['available_cents']);
    }
    public function testCashRequiresItsOwnCurrentPostedLinkNotMerelyAnEqualJournal():void
    {
        $cash=$this->cash('in','100.00');(new PaymentLocks($this->db))->acquire($this->f['supplier']);
        self::assertSame('10000',(new PaymentSourceProof($this->db))->current($this->f['supplier'],'cash',$cash)['available_cents']);
        $this->db->pdo()->prepare('UPDATE cash_documents SET journal_entry_id=NULL WHERE id=?')->execute([$cash]);
        $this->expectException(PaymentException::class);(new PaymentSourceProof($this->db))->current($this->f['supplier'],'cash',$cash);
    }
    public function testCashRefundRequiresExplicitAuthorizedRecipientAndActualSignedCashReceipt():void
    {
        $f=$this->f;$repo=Bootstrap::buildContainer()->get(\MyInvoice\Repository\Property\Payments\PaymentRepository::class);$cash=$this->cash('in','100.00');$request=$this->creditRequest($cash,'10000',$f['clients'][1],0);$request['source']['kind']='cash';
        $operation=$repo->apply($f['supplier'],$request,$repo->preview($f['supplier'],$request)['preview_hash'],'cash-credit',$f['actor']);
        $reservation=$repo->reserve($f['supplier'],$operation['credit_id'],1,['amount_cents'=>'5000','party_ids'=>[$f['clients'][1]],'disposition'=>'Synthetic creditor instruction','delivery_method'=>'cash','recipient_id'=>$f['clients'][2],'recipient_evidence'=>'Synthetic authority to receive for creditor'],'cash-reserve',$f['actor']);
        $out=$this->cash('out','50.00');$refund=['kind'=>'refund','source'=>['kind'=>'cash','id'=>$out,'expected_version'=>0],'amount_cents'=>'3000','accounting_date'=>'2026-02-01','reservation_id'=>$reservation['id'],'expected_reservation_version'=>1,'reason'=>'Synthetic partial cash refund',
            'cash_receipt'=>['cash_document_id'=>$out,'recipient_id'=>$f['clients'][2],'recipient_name'=>'Synthetic recipient','evidence'=>'Synthetic signed receipt for actual cash document']];
        $bad=$refund;$bad['cash_receipt']['recipient_id']=$f['clients'][1];try{$repo->preview($f['supplier'],$bad);self::fail('Creditor substituted for authorized actual recipient.');}catch(PaymentException $e){self::assertSame('property_payment_refund_recipient_mismatch',$e->errorCode);}
        $result=$repo->apply($f['supplier'],$refund,$repo->preview($f['supplier'],$refund)['preview_hash'],'cash-refund',$f['actor']);self::assertSame('posted',$result['status']);
        self::assertSame('7000',$repo->credit($f['supplier'],$operation['credit_id'])['balance_cents']);self::assertSame('2000',$repo->credit($f['supplier'],$operation['credit_id'])['reserved_cents']);
    }
    public function testPartialAccessoryAllocationUsesActualReceiptsAndExplicitSameDebtBreakdown():void
    {
        $f=$this->f;$base=$this->settledPaymentFixture('1','0');$entry=Bootstrap::buildContainer()->get(PostingService::class)->postDocument($f['supplier'],'manual',null,[['account_code'=>'315','side'=>'debit','amount'=>'0.70'],['account_code'=>'315','side'=>'debit','amount'=>'1.30'],['account_code'=>'379.900','side'=>'credit','amount'=>'2.00']],['entry_date'=>'2026-01-31','posted_by'=>$f['actor']]);
        $q=$this->db->pdo()->prepare("SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.entry_id=? AND a.account_code='315' ORDER BY l.line_no,l.id");$q->execute([$entry]);$ids=$q->fetchAll(\PDO::FETCH_COLUMN);$debts=new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db);$accessories=[];
        foreach($ids as $id)$accessories[]=$debts->bindAccessory($f['supplier'],$base['debt']['id'],['journal_entry_id'=>$entry,'journal_line_id'=>(int)$id,'component'=>'interest','evidence'=>'Synthetic existing receivable title','liability_evidence'=>'Synthetic same liable group'],$f['actor'])['id'];
        $debtor=$base['charge']['snapshot']['liability_groups'][0]['members'][0];$request=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$this->bank('0.60'),'expected_version'=>0],'amount_cents'=>'60','accounting_date'=>'2026-02-01','reason'=>'Synthetic accessory payment','debts'=>[$base['debt']['id']],
            'payment'=>['payer_id'=>$debtor,'debtor_id'=>$debtor,'received_at'=>'2026-02-01T10:00:00+01:00','choice'=>['claim_ids'=>[$base['debt']['id']],'evidence'=>'Synthetic selected legal debt']]];
        $repo=$this->correctionPayments();try{$repo->preview($f['supplier'],$request);self::fail('Partial accessory split guessed.');}catch(PaymentException $e){self::assertSame('property_payment_accessory_distribution_required',$e->errorCode);}
        $request['payment']['accessory_distribution'][$base['debt']['id']]['interest']=['evidence'=>'Synthetic documented distribution inside same legal debt','parts'=>[['id'=>$accessories[0],'amount_cents'=>'45'],['id'=>$accessories[1],'amount_cents'=>'15']]];
        $result=$repo->apply($f['supplier'],$request,$repo->preview($f['supplier'],$request)['preview_hash'],'accessory-payment',$f['actor']);self::assertSame('posted',$result['status']);$current=$debts->current($f['supplier'],$base['debt']['id']);
        self::assertSame('45',$current['paid']['accessory:'.$accessories[0]]);self::assertSame('15',$current['paid']['accessory:'.$accessories[1]]);self::assertSame('1',$current['paid']['principal:'.$base['line_id']]);
    }
    private function cash(string $direction,string $amount):int
    {
        $pdo=$this->db->pdo();$q=$pdo->prepare('SELECT id FROM cash_registers WHERE supplier_id=? ORDER BY id LIMIT 1');$q->execute([$this->f['supplier']]);$register=$q->fetchColumn();
        if(!$register){$pdo->prepare("INSERT INTO cash_registers (supplier_id,name,account_code) VALUES (?,'Synthetic cash','211')")->execute([$this->f['supplier']]);$register=(int)$pdo->lastInsertId();}
        $pdo->prepare("INSERT INTO cash_documents (supplier_id,register_id,doc_type,purpose,issue_date,partner_name,description,total_amount,counter_account_code,status,created_by) VALUES (?,?,?,'other','2026-02-01','Synthetic recipient','Synthetic clearing',?,'379.900','posted',?)")->execute([$this->f['supplier'],$register,$direction,$amount,$this->f['actor']]);$id=(int)$pdo->lastInsertId();
        $entry=Bootstrap::buildContainer()->get(PostingService::class)->postDocument($this->f['supplier'],'cash',$id,[['account_code'=>'211','side'=>$direction==='in'?'debit':'credit','amount'=>$amount],['account_code'=>'379.900','side'=>$direction==='in'?'credit':'debit','amount'=>$amount]],['entry_date'=>'2026-02-01','posted_by'=>$this->f['actor']]);
        $pdo->prepare('UPDATE cash_documents SET journal_entry_id=? WHERE id=?')->execute([$entry,$id]);return $id;
    }
    private function creditRequest(int $source,string $cents,int $party,int $version): array
    {
        return ['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$source,'expected_version'=>$version],'amount_cents'=>$cents,'accounting_date'=>'2026-02-01','reason'=>'Synthetic separately entitled credit','debts'=>[],
            'credit_entitlement'=>['unit_id'=>array_values($this->f['units'])[0],'party_ids'=>[$party],'evidence'=>'Synthetic independently established creditor title','due_date'=>'2026-03-01']];
    }
    private function bank(string $amount,string $counter='379.900'): int
    {
        $pdo=$this->db->pdo();$pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Synthetic',?,'1000000005','0100','CZK','2026-02-01')")->execute([$this->f['supplier'],hash('sha256',random_bytes(20))]);$statement=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions (statement_id,posted_at,amount,currency,counterparty_account,counterparty_bank) VALUES (?,'2026-02-01',?,'CZK','1000000005','0100')")->execute([$statement,$amount]);$id=(int)$pdo->lastInsertId();
        Bootstrap::buildContainer()->get(PostingService::class)->postDocument($this->f['supplier'],'bank',$id,[['account_code'=>'221','side'=>str_starts_with($amount,'-')?'credit':'debit','amount'=>ltrim($amount,'-')],['account_code'=>$counter,'side'=>str_starts_with($amount,'-')?'debit':'credit','amount'=>ltrim($amount,'-')]],['entry_date'=>'2026-02-01','posted_by'=>$this->f['actor']]);
        return $id;
    }
    private function foreignBank(string $native,string $czk,string $rate,string $currency='EUR'): int
    {
        $pdo=$this->db->pdo();$pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Synthetic FX',?,'1000000005','0100',?,'2026-02-01')")->execute([$this->f['supplier'],hash('sha256',random_bytes(20)),$currency]);$statement=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions (statement_id,posted_at,amount,currency,counterparty_account,counterparty_bank) VALUES (?,'2026-02-01',?,?,'1000000005','0100')")->execute([$statement,$native,$currency]);$id=(int)$pdo->lastInsertId();
        Bootstrap::buildContainer()->get(PostingService::class)->postDocument($this->f['supplier'],'bank',$id,[
            ['account_code'=>'221','side'=>'debit','amount'=>$czk,'currency_code'=>$currency,'amount_foreign'=>$native,'fx_rate'=>$rate],
            ['account_code'=>'379.900','side'=>'credit','amount'=>$czk,'currency_code'=>$currency,'amount_foreign'=>$native,'fx_rate'=>$rate],
        ],['entry_date'=>'2026-02-01','posted_by'=>$this->f['actor']]);
        return $id;
    }
    private function foreignCash(string $native,string $czk,string $rate): int
    {
        $pdo=$this->db->pdo();$pdo->prepare("INSERT INTO cash_registers (supplier_id,name,currency_code,account_code) VALUES (?,'Synthetic EUR cash','EUR','211')")->execute([$this->f['supplier']]);$register=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO cash_documents (supplier_id,register_id,doc_type,purpose,issue_date,partner_name,description,total_amount,currency_code,fx_rate,amount_foreign,counter_account_code,status,created_by) VALUES (?,?,'in','other','2026-02-01','Synthetic payer','Synthetic EUR clearing',?,'EUR',?,?,'379.900','posted',?)")->execute([$this->f['supplier'],$register,$czk,$rate,$native,$this->f['actor']]);$id=(int)$pdo->lastInsertId();
        $entry=Bootstrap::buildContainer()->get(PostingService::class)->postDocument($this->f['supplier'],'cash',$id,[
            ['account_code'=>'211','side'=>'debit','amount'=>$czk,'currency_code'=>'EUR','amount_foreign'=>$native,'fx_rate'=>$rate],
            ['account_code'=>'379.900','side'=>'credit','amount'=>$czk,'currency_code'=>'EUR','amount_foreign'=>$native,'fx_rate'=>$rate],
        ],['entry_date'=>'2026-02-01','posted_by'=>$this->f['actor']]);
        $pdo->prepare('UPDATE cash_documents SET journal_entry_id=? WHERE id=?')->execute([$entry,$id]);return $id;
    }
    private function documentedCzkDebt(): array
    {
        $f=$this->f;$issuance=$this->paymentFixtureContainer()->get(\MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository::class);$request=$f['issue_request'];
        $preview=(new \MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService($this->db))->preview($f['supplier'],$request);
        $batch=$issuance->issue($f['supplier'],['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'foreign-czk-issue'],$f['actor']);
        $batch=$issuance->post($f['supplier'],$batch['id'],array_map(fn($charge)=>['id'=>$charge['id'],'row_version'=>1],$batch['charges']),$f['actor']);
        $charge=array_values(array_filter($batch['charges'],fn($candidate)=>$candidate['unit_id']===$f['units']['G12']))[0];
        $q=$this->db->pdo()->prepare('SELECT id FROM unit_charge_lines WHERE supplier_id=? AND charge_id=? AND component_id=?');$q->execute([$f['supplier'],$charge['id'],$f['components']['ADMIN']]);$line=(int)$q->fetchColumn();
        $debt=(new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->register($f['supplier'],['charge_id'=>$charge['id'],'expected_version'=>2,'principal_line_ids'=>[$line],'title_ref'=>'Synthetic documented CZK administration debt','due_date'=>'2026-01-15','legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-15','evidence'=>'Synthetic CZK decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic finding'],'damages_for'=>null],'foreign-czk-debt',$f['actor']);
        return ['debt'=>$debt,'debtor'=>$charge['snapshot']['liability_groups'][0]['members'][0]];
    }
}
