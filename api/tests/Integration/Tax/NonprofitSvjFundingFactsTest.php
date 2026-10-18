<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Tax;
use MyInvoice\Tests\Support\FundingFixture;
use MyInvoice\Service\Tax\Nonprofit\NonprofitSvjSourceFacts;
use PHPUnit\Framework\TestCase;

final class NonprofitSvjFundingFactsTest extends TestCase
{
    use FundingFixture;
    public function testActualIssuedManagementContributionsExcludeOnlyFundedCostPart():void
    {
        $this->fundingIssue();$cost=$this->fundingCost('2026-09-01','518.200','10000.00');$operation=$this->fundingApply($this->fundingDraw('2026-09-01','administration','500000',$cost,'issued'),'tax-native-management');
        $sources=[['id'=>'cost','kind'=>'expense','line_id'=>$cost,'origin'=>['line_id'=>$cost],'amount_cents'=>1000000]];
        foreach($operation['receipt']['ledger']['lines']as$line)if(str_starts_with($line['account_code'],'649'))$sources[]=['id'=>'income-'.$line['id'],'kind'=>'revenue','line_id'=>$line['id'],'origin'=>['line_id'=>$line['id']],'amount_cents'=>(int)bcmul($line['amount'],'100',0)];
        $snapshot=['legal_profile'=>['profile'=>['kind'=>'svj']],'period'=>['starts_on'=>'2026-01-01','ends_on'=>'2026-12-31'],'sources'=>$sources];$actual=(new NonprofitSvjSourceFacts($this->db))->attach($this->f['supplier'],$snapshot);
        self::assertSame(500000,$actual['sources'][0]['svj_owner_facts']['covered_cost_cents']);self::assertSame('zdp_18_2f_2',$actual['sources'][0]['svj_owner_facts']['legal_basis']);
        foreach(array_slice($actual['sources'],1)as$source){self::assertSame('zdp_18_2f_2',$source['svj_owner_facts']['legal_basis']);self::assertSame($operation['id'],$source['svj_owner_facts']['operation_id']);}
    }
    public function testOpeningBalanceAloneDoesNotProveManagementContributionLegalTitle():void
    {
        $this->fundingOpening();$cost=$this->fundingCost('2026-03-01','549.900','10000.00');$this->fundingApply($this->fundingDraw('2026-03-01','repair','500000',$cost),'tax-opening-unknown');
        $snapshot=['legal_profile'=>['profile'=>['kind'=>'svj']],'period'=>['starts_on'=>'2026-01-01','ends_on'=>'2026-12-31'],'sources'=>[['id'=>'cost','kind'=>'expense','line_id'=>$cost,'origin'=>['line_id'=>$cost],'amount_cents'=>1000000]]];
        $actual=(new NonprofitSvjSourceFacts($this->db))->attach($this->f['supplier'],$snapshot);self::assertArrayNotHasKey('svj_owner_facts',$actual['sources'][0]);
    }
}
