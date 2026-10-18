<?php
declare(strict_types=1);
namespace MyInvoice\Service\Nonprofit\Funding;
use MyInvoice\Repository\Nonprofit\FundingRepository;
final class FundingEligibilityGuard
{
    public function __construct(private readonly FundingRepository $sources) {}
    public function assertAssessment(int $supplier,array $snapshot,array $assessment,array $result,bool $lock=false): void
    {
        $this->assertPolicies($this->sources->latest($supplier,$lock),$snapshot,$assessment,$result);
    }
    public function assertPolicies(array $policies,array $snapshot,array $assessment,array $result): void
    {
        $rows=array_column($result['rows'],null,'id');
        $catalog=$rows+($snapshot['funding_reference_sources']??[]);
        foreach($policies as $source) {
            $policy=$source['policy']; $anchor=$policy['source_anchor'];
            foreach($assessment['funding']??[] as $link) {
                if(!in_array($link['source_id'],$policy['source_members'],true))continue;
                if($link['source_id']!==$anchor)FundingPolicy::invalid('Použití zdroje musí zachovat jeho doloženou kotvu.','nonprofit_funding_source_changed');
                FundingPolicy::assertCost($policy,$rows[$link['cost_id']]??[]);
                FundingPolicy::assertTitle($policy,$catalog[$anchor]??[]);
            }
            foreach($result['funding_usage']??[] as $usage) {
                if($usage['anchor']!==$anchor&&array_intersect($usage['members'],$policy['source_members'])===[])continue;
                if($usage['anchor']!==$anchor)FundingPolicy::invalid('Členství zdroje financování se změnilo.','nonprofit_funding_source_changed');
                foreach(array_diff($usage['members'],$policy['source_members'])as$added){
                    $correction=$catalog[$added]??null;
                    if($correction===null||$correction['amount_cents']>=0)FundingPolicy::invalid('Nové kladné zdroje vyžadují samostatné doložené rozhodnutí.','nonprofit_funding_source_changed');
                    FundingPolicy::assertTitle($policy,$correction);
                }
                if($usage['prior_drawdown_cents']+$usage['drawdown_cents']>$policy['budget_cents'])FundingPolicy::invalid('Čerpání překračuje schválený rozpočet.','nonprofit_funding_budget_exceeded');
            }
        }
    }
}
