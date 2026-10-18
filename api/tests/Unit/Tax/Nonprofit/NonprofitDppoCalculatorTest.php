<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Tax\Nonprofit;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoComputation;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoClassificationBridge;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoLegalRules;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

final class NonprofitDppoCalculatorTest extends TestCase
{
    private function data(array $inputs=[],string $kind='narrow_vpp',int $income=80000000,int $expense=0,int $deductible=0):array
    {
        $profile=NonprofitDppoLegalRules::profile(['kind'=>$kind,'entity_type'=>$kind==='svj'?'svj':'association','main_activity_nonbusiness'=>true,'evidence'=>'Synthetic legal deed','legal_basis'=>'zdp_17a']);
        $rows=[['id'=>'income','kind'=>'revenue','amount_cents'=>$income,'adjustment_cents'=>0,'classification'=>['regime'=>'subject','method'=>'return','legal_basis'=>'zdp_18']]];
        if($expense!==0)$rows[]=['id'=>'expense','kind'=>'expense','amount_cents'=>$expense,'deductible_cents'=>$deductible,'adjustment_cents'=>$expense-$deductible,'classification'=>['regime'=>'subject','method'=>'cost','legal_basis'=>'zdp_24']];
        $result=['profile'=>$kind,'rows'=>$rows,'ruleset'=>NonprofitTaxRules::VERSION,'scope'=>'classification_bridge_before_general_adjustments','accounting_result_cents'=>$income-$expense,'cost_addbacks_cents'=>$expense-$deductible,'excluded_revenue_cents'=>0,'assessed_result_cents'=>$income-$deductible];
        return ['accounting_decree'=>'504','vh'=>($income-$expense)/100,'non_deductible_costs'=>999999,'depreciation'=>['tax'=>9999999,'accounting'=>1],'period'=>['starts_on'=>'2026-01-01','ends_on'=>'2026-12-31'],'nonprofit'=>NonprofitDppoComputation::seal(['schema'=>NonprofitDppoLegalRules::VERSION,'legal_profile'=>['id'=>1,'record_hash'=>str_repeat('a',64),'profile'=>$profile],'assessment'=>['id'=>1,'result'=>$result,'input_hash'=>str_repeat('b',64)],'bridge'=>(new NonprofitDppoClassificationBridge())->build($result),'elections_hash'=>NonprofitTaxRules::hash($inputs['nonprofit']??[]),'adjustments'=>[],'unused_reduction_addback_kc'=>0])];
    }
    private function compute(array $inputs=[],string $kind='narrow_vpp',int $income=80000000,int $expense=0,int $deductible=0):array
    {return(new DppoReturnCalculator())->compute($this->data($inputs,$kind,$income,$expense,$deductible),$inputs,TaxConstants::forYear(2026));}
    private function lines(array $r):array{return array_column($r['lines'],'value','line');}
    public function test504UsesJ6ClassificationWithoutGeneric543OrDepreciationDoubleAddback():void
    {
        $r=$this->compute([],expense:30000000,deductible:10000000);$l=$this->lines($r);self::assertSame(500000.0,$l[10]);self::assertSame(200000.0,$l[40]);self::assertSame(0.0,$l[50]);self::assertSame(0.0,$l[150]);self::assertSame(700000.0,$l[200]);
    }
    public function testReductionFollowsAllSection34Deductions():void
    {
        $r=$this->compute(['loss_carryforward'=>200000,'rnd_deduction'=>100000,'education_deduction'=>100000,'nonprofit'=>['reduction_kc'=>300000,'reduction_evidence'=>'Synthetic election']]);$l=$this->lines($r);
        self::assertSame(400000.0,$l[250]);self::assertSame(300000.0,$l[251]);self::assertSame(100000.0,$l[270]);self::assertSame(21000.0,$r['tax']);self::assertSame(63000,$r['nonprofit']['saving']['saving_kc']);
    }
    public function testActualSavingHonoursCreditCappedAgainstBothTaxCalculations():void
    {
        $r=$this->compute(['disabled_employees_avg'=>1,'disabled_employees_severe_avg'=>0.5,'nonprofit'=>['reduction_kc'=>300000,'reduction_evidence'=>'Synthetic election']],income:30000000);self::assertSame(0.0,$r['tax']);self::assertSame(15000,$r['nonprofit']['saving']['saving_kc']);self::assertTrue($r['nonprofit']['filing_obligation']['required']);
    }
    public function testVppCannotClaimDonationDeduction():void
    {$this->expectException(TaxReturnException::class);$this->compute(['donations'=>10000]);}
    public function testSvjCannotClaimSection20Reduction():void
    {$this->expectException(TaxReturnException::class);$this->compute(['nonprofit'=>['reduction_kc'=>10000,'reduction_evidence'=>'Invalid election']],'svj');}
    public function testReductionAboveLimitRejectedInsteadOfClipped():void
    {$this->expectException(TaxReturnException::class);$this->compute(['nonprofit'=>['reduction_kc'=>300001,'reduction_evidence'=>'Overclaim']]);}
    public function testRawGenericManualAdjustmentCannotBypassNativeSourceProof():void
    {$this->expectException(TaxReturnException::class);$this->compute(['manual_increase_items'=>[['text'=>'Unproved tax adjustment','amount'=>50000]]]);}
    public function testChangedElectionRequiresNewProviderProof():void
    {$d=$this->data();$this->expectException(TaxReturnException::class);(new DppoReturnCalculator())->compute($d,['nonprofit'=>['reduction_kc'=>300000]],TaxConstants::forYear(2026));}
}
