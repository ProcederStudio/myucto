<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Tax\Nonprofit;
use MyInvoice\Service\Tax\Nonprofit\{NonprofitDppoReduction,NonprofitDppoStoredProof,NonprofitTaxRules};
use MyInvoice\Service\Tax\Return\{DppoReturnCalculator,TaxReturnException};
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class NonprofitAnnualConstantsCompatibilityTest extends TestCase
{
    private function fixture():array
    {
        $inputs=['nonprofit'=>['reduction_kc'=>300000,'reduction_evidence'=>'Synthetic historical election']];$data=(new \ReflectionMethod(NonprofitDppoCalculatorTest::class,'data'))->invoke(new NonprofitDppoCalculatorTest('testReductionAboveLimitRejectedInsteadOfClipped'),$inputs);$data['period']['fiscal_year']=2026;$constants=TaxConstants::forYear(2026);$computed=(new DppoReturnCalculator())->compute($data,$inputs,$constants);$record=NonprofitDppoStoredProof::capture($data,$inputs,$constants,[]);return[$computed,$record];
    }
    private function seal(array $record):array
    {unset($record['filing_proof_hash']);$record['filing_proof_hash']=NonprofitTaxRules::hash($record);return$record;}
    public function testUnversionedCompleteAndLegacyPinnedRecordsKeepOriginalHashAndContents():void
    {
        [$computed,$record]=$this->fixture();self::assertSame(2,$record['version']);unset($record['version']);$record=$this->seal($record);$before=$record;NonprofitDppoStoredProof::assertCalculation($computed,$record);self::assertSame($before,$record);
        foreach(array_keys(NonprofitDppoReduction::configuration($record['constants']))as$key)unset($record['constants'][$key]);$record=$this->seal($record);$before=$record;NonprofitDppoStoredProof::assertCalculation($computed,$record);self::assertSame($before,$record);
        foreach([2024,2025,2026]as$year){$record['constants']['year']=$year;$record['data']['period']['fiscal_year']=$year;self::assertSame(300000,NonprofitDppoStoredProof::constantsForRecord($record)['nonprofit_reduction_min']);}
    }
    public static function invalid():array
    {return[['v2_missing_all'],['v2_missing_one'],['legacy_missing_one'],['legacy_unknown_year'],['legacy_unknown_ruleset'],['legacy_period_mismatch'],['unknown_version'],['null_version']];}
    #[DataProvider('invalid')]
    public function testRehashedIncompleteOrUnknownRecordCannotUseLegacyCompatibility(string $case):void
    {
        [$computed,$record]=$this->fixture();$keys=array_keys(NonprofitDppoReduction::configuration($record['constants']));
        if(str_starts_with($case,'legacy_'))unset($record['version']);
        if(in_array($case,['v2_missing_all','legacy_unknown_year','legacy_unknown_ruleset','legacy_period_mismatch'],true))foreach($keys as$key)unset($record['constants'][$key]);
        if(str_ends_with($case,'missing_one'))unset($record['constants'][$keys[0]]);
        if($case==='legacy_unknown_year')$record['constants']['year']=$record['data']['period']['fiscal_year']=2019;
        if($case==='legacy_unknown_ruleset')$record['data']['nonprofit']['schema']='unknown';
        if($case==='legacy_period_mismatch')$record['data']['period']['fiscal_year']=2025;
        if($case==='unknown_version')$record['version']=3;if($case==='null_version')$record['version']=null;
        $record=$this->seal($record);$this->expectException(TaxReturnException::class);$this->expectExceptionMessage('Finální přiznání 504');NonprofitDppoStoredProof::assertCalculation($computed,$record);
    }
    public function testNewCaptureCannotCreateUnversionedLegacyShape():void
    {[$computed,$record]=$this->fixture();unset($record['constants']['nonprofit_reduction_rate']);$this->expectException(TaxReturnException::class);NonprofitDppoStoredProof::capture($record['data'],$record['inputs'],$record['constants'],[]);}
}
