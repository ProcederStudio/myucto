<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit;

final class NonprofitTaxCalculator
{
    public function calculate(array $snapshot, array $assessment): array
    {
        NonprofitTaxRules::assessment($assessment);
        $manual = array_key_exists('method', $assessment);
        $profile = $manual ? NonprofitTaxRules::documentedProfile($assessment, $snapshot['period'])
            : NonprofitTaxRules::profile($assessment['profile'] ?? [], $snapshot['period']);
        $decisions = $assessment['decisions'] ?? [];
        $rows = [];
        $groups = [];
        foreach ($snapshot['sources'] as $source) {
            $id = (string) $source['id'];
            $decision = $decisions[$id] ?? null;
            if (!is_array($decision)) NonprofitTaxRules::invalid('Každý zdrojový řádek musí mít vlastní posouzení.');
            NonprofitTaxRules::evidence($decision['evidence'] ?? null);
            $amount = $source['amount_cents'];
            if (!is_int($amount)) NonprofitTaxRules::invalid('Částka zdroje musí být vyjádřena v celých haléřích.');
            $kind = (string) $source['activity_kind_id'];
            NonprofitTaxRules::evidence($assessment['activities'][$kind]['evidence'] ?? null);
            $cohort = (string) ($decision['cohort'] ?? '');
            $declared = $assessment['activities'][$kind]['cohorts'] ?? [];
            if ($declared !== []) {
                if ($cohort === '' || !array_key_exists($cohort, $declared)) NonprofitTaxRules::invalid('Zdroj nemá doloženou cenovou kohortu.');
                NonprofitTaxRules::evidence($declared[$cohort]);
            } elseif ($cohort !== '') {
                NonprofitTaxRules::invalid('Cenová kohorta musí mít předem doložené podmínky.');
            }
            $group = $kind . ':' . $cohort;
            $row = $source + ['decision' => $decision, 'group' => $group];
            if ($source['kind'] === 'revenue') {
                $row['classification'] = $manual ? NonprofitTaxRules::documentedClassification($decision, 'revenue') : NonprofitTaxRules::revenue($profile, $decision);
                if ($row['classification']['method'] === 'final_withholding') {
                    $facts = $decision['facts'] ?? [];
                    $gross = $facts['gross_interest_cents'] ?? null;
                    $withheld = $facts['withholding_cents'] ?? null;
                    if (!is_int($gross) || !is_int($withheld) || $gross - $withheld !== $amount
                        || abs($withheld) > abs($gross) || $withheld * ($gross <=> 0) < 0) {
                        NonprofitTaxRules::invalid('Čistý bankovní úrok musí souhlasit s doloženým hrubým úrokem a sraženou daní.');
                    }
                }
                $row['deductible_cents'] = 0;
            } elseif ($source['kind'] === 'expense') {
                $allowed = $decision['deductible_cents'] ?? null;
                if (!is_int($allowed) || abs($allowed) > abs($amount) || $allowed * ($amount <=> 0) < 0) NonprofitTaxRules::invalid('Uznatelná část nákladu musí odpovídat znaménku a rozsahu zdrojové částky.');
                NonprofitTaxRules::evidence($decision['legal_basis'] ?? null);
                if (!empty($source['mandatory_non_deductible']) && $allowed !== 0) NonprofitTaxRules::invalid('Daňově neuznatelný zdrojový náklad nelze potvrdit jako uznatelný.');
                $title = $decision['title'] ?? '';
                if (!in_array($title, ['cost', 'nonbusiness_cost', 'excluded_cost', 'withholding_interest_cost'], true)) NonprofitTaxRules::invalid('Náklad nemá podporovaný účel.');
                if ($title === 'excluded_cost' && $allowed !== 0) NonprofitTaxRules::invalid('Vyloučený náklad nemůže být daňově uznatelný.');
                if ($title === 'withholding_interest_cost' && $profile === 'non_vpp') NonprofitTaxRules::invalid('Výjimka nákladů na srážkové úroky vyžaduje veřejně prospěšného poplatníka.');
                $row['classification'] = $manual ? NonprofitTaxRules::documentedClassification($decision, 'expense')
                    : ['regime' => $title === 'nonbusiness_cost' && $profile === 'narrow_vpp' ? 'pending' : 'subject',
                        'method' => 'cost', 'legal_basis' => $decision['legal_basis']];
                $row['deductible_cents'] = $allowed;
            } else {
                NonprofitTaxRules::invalid('Neznámý druh zdrojového řádku.');
            }
            if (isset($rows[$id])) NonprofitTaxRules::invalid('Duplicitní zdrojový řádek.');
            $rows[$id] = $row;
            if ($row['classification']['regime'] === 'pending') $groups[$group] = ['activity_kind_id' => $kind, 'cohort' => $cohort, 'revenue_cents' => 0, 'cost_cents' => 0];
        }
        if (count($decisions) !== count($rows)) NonprofitTaxRules::invalid('Posouzení obsahuje řádky mimo aktuální zdroje.');
        [$rows, $groups, $funding] = $this->resolveActivities($rows, $groups, $assessment, $snapshot);
        foreach ($rows as $id => &$row) {
            $row['funded_excluded_cents'] = $funding['excluded'][$id] ?? 0;
            if ($row['kind'] === 'expense') $row['deductible_cents'] = self::fundedDeductible($row, $funding);
        }
        unset($row);
        $result = $excluded = $addedBack = 0;
        foreach ($rows as &$row) {
            if ($row['classification']['regime'] === 'pending') {
                $row['classification']['regime'] = $groups[$row['group']]['regime'];
                $row['classification']['method'] = $row['classification']['regime'] === 'subject' ? 'return' : 'none';
                if ($row['kind'] === 'expense' && $row['classification']['regime'] === 'out_of_scope') $row['deductible_cents'] = 0;
            }
            if ($row['kind'] === 'revenue') {
                $result += $row['amount_cents'];
                $row['adjustment_cents'] = $row['classification']['regime'] !== 'subject' || $row['classification']['method'] === 'final_withholding' ? -$row['amount_cents'] : 0;
                $excluded -= $row['adjustment_cents'];
            } else {
                $result -= $row['amount_cents'];
                $row['adjustment_cents'] = $row['amount_cents'] - $row['deductible_cents'];
                $addedBack += $row['adjustment_cents'];
            }
        }
        unset($row);
        return ['ruleset' => NonprofitTaxRules::VERSION, 'profile' => $profile, 'assessment_method' => $manual ? 'documented_manual' : 'ruleset',
            'accounting_result_cents' => $result, 'excluded_revenue_cents' => $excluded,
            'cost_addbacks_cents' => $addedBack, 'assessed_result_cents' => $result - $excluded + $addedBack,
            'scope' => 'classification_bridge_before_general_adjustments', 'dppo_supported' => false,
            'activities' => array_values($groups), 'rows' => array_values($rows), 'funding' => $assessment['funding'] ?? [],
            'funding_usage' => $funding['usage'], 'external_adjustments' => $funding['external_adjustments']];
    }

    private function resolveActivities(array $rows, array $groups, array $assessment, array $snapshot): array
    {
        $funding = $this->funding($rows, $assessment, $snapshot);
        $order = $this->activityFundingOrder($rows, $groups, $assessment, $snapshot);
        if ($order === null) return $this->resolveConditionalCycle($rows, $groups, $assessment, $snapshot);
        foreach ($order as $activity) {
            $selected = [];
            foreach ($groups as $key => $group) if ($group['activity_kind_id'] === $activity) $selected[$key] = $group;
            foreach ($this->activityGroups($rows, $selected, $funding) as $key => $group) $groups[$key] = $group;
            foreach ($rows as &$row) {
                if ($row['kind'] !== 'revenue' || (string) $row['activity_kind_id'] !== $activity || $row['classification']['regime'] !== 'pending') continue;
                $row['classification']['regime'] = $groups[$row['group']]['regime'];
                $row['classification']['method'] = $row['classification']['regime'] === 'subject' ? 'return' : 'none';
            }
            unset($row);
            $funding = $this->funding($rows, $assessment, $snapshot);
        }
        return [$rows, $groups, $funding];
    }

    private function activityFundingOrder(array $rows, array $groups, array $assessment, array $snapshot): ?array
    {
        $incoming = [];
        foreach ($groups as $group) $incoming[$group['activity_kind_id']] = [];
        foreach ($assessment['funding'] ?? [] as $link) {
            $source = $rows[$link['source_id']] ?? $snapshot['funding_reference_sources'][$link['source_id']] ?? null;
            $cost = $rows[$link['cost_id']] ?? null;
            if (($source['classification']['regime'] ?? null) !== 'pending' || ($cost['classification']['regime'] ?? null) !== 'pending'
                || $cost['deductible_cents'] === 0) continue;
            $incoming[$cost['activity_kind_id']][$source['activity_kind_id']] = true;
        }
        $order = [];
        while ($incoming !== []) {
            $ready = array_keys(array_filter($incoming, static fn (array $parents): bool => $parents === []));
            if ($ready === []) return null;
            sort($ready, SORT_NATURAL);
            foreach ($ready as $activity) {
                $order[] = (string) $activity;
                unset($incoming[$activity]);
                foreach ($incoming as &$parents) unset($parents[$activity]);
                unset($parents);
            }
        }
        return $order;
    }

    private function activityGroups(array $rows, array $groups, array $funding): array
    {
        foreach ($rows as $row) {
            if (!isset($groups[$row['group']]) || $row['classification']['regime'] !== 'pending') continue;
            $field = $row['kind'] === 'revenue' ? 'revenue_cents' : 'cost_cents';
            $groups[$row['group']][$field] += $row['kind'] === 'revenue' ? $row['amount_cents'] : self::fundedDeductible($row, $funding);
        }
        $mixed = [];
        foreach ($groups as $group) if ($group['cohort'] !== '') $mixed[$group['activity_kind_id']][$group['revenue_cents'] > $group['cost_cents'] ? 'positive' : 'nonpositive'] = true;
        foreach ($groups as &$group) {
            $hasMixed = isset($mixed[$group['activity_kind_id']]['positive'], $mixed[$group['activity_kind_id']]['nonpositive']);
            $out = $hasMixed ? $group['revenue_cents'] <= $group['cost_cents'] : $group['revenue_cents'] < $group['cost_cents'];
            $group['regime'] = $out ? 'out_of_scope' : 'subject';
            $group['contribution_cents'] = $out ? 0 : $group['revenue_cents'] - $group['cost_cents'];
        }
        unset($group);
        return $groups;
    }

    private function resolveConditionalCycle(array $rows, array $groups, array $assessment, array $snapshot): array
    {
        $conditional = [];
        foreach ($assessment['funding'] ?? [] as $link) {
            $source = $rows[$link['source_id']] ?? null;
            if (($source['classification']['regime'] ?? null) === 'pending') $conditional[$source['group']] = true;
        }
        $keys = array_keys($conditional);
        if (count($keys) > 10) $this->cycleReview();
        $solution = null;
        $uncertain = false;
        for ($state = 0; $state < (1 << count($keys)); ++$state) {
            $assumed = [];
            foreach ($keys as $bit => $key) $assumed[$key] = ($state & (1 << $bit)) !== 0 ? 'out_of_scope' : 'subject';
            $candidate = $rows;
            foreach ($candidate as &$row) {
                if ($row['kind'] !== 'revenue' || $row['classification']['regime'] !== 'pending' || !isset($assumed[$row['group']])) continue;
                $row['classification']['regime'] = $assumed[$row['group']];
                $row['classification']['method'] = $row['classification']['regime'] === 'subject' ? 'return' : 'none';
            }
            unset($row);
            try {
                $funding = $this->funding($candidate, $assessment, $snapshot);
            } catch (NonprofitTaxAssessmentException) {
                $uncertain = true;
                continue;
            }
            $resolved = $this->activityGroups($rows, $groups, $funding);
            foreach ($assumed as $key => $regime) if ($resolved[$key]['regime'] !== $regime) continue 2;
            if ($solution !== null) $this->cycleReview();
            $solution = [$resolved, $funding];
        }
        if ($solution === null || $uncertain) $this->cycleReview();
        [$groups, $funding] = $solution;
        foreach ($rows as &$row) {
            if ($row['kind'] !== 'revenue' || $row['classification']['regime'] !== 'pending') continue;
            $row['classification']['regime'] = $groups[$row['group']]['regime'];
            $row['classification']['method'] = $row['classification']['regime'] === 'subject' ? 'return' : 'none';
        }
        unset($row);
        return [$rows, $groups, $this->funding($rows, $assessment, $snapshot)];
    }

    private function cycleReview(): never
    {
        $this->review('Kruhová závislost financování a ročního testu nemá doložené jediné řešení a vyžaduje samostatné posouzení.');
    }

    private static function fundedDeductible(array $row, array $funding): int
    {
        return ($row['amount_cents'] <=> 0) * (abs($row['deductible_cents']) - abs($funding['excluded'][$row['id']] ?? 0));
    }

    private function funding(array $rows, array $assessment, array $snapshot): array
    {
        $links = $assessment['funding'] ?? [];
        $references = $snapshot['funding_reference_sources'] ?? [];
        $catalog = $rows + $references;
        $incomePools = $this->pools($catalog, $assessment['funding_sources'] ?? [], 'revenue', $rows, $links !== []);
        $costPools = $this->pools($rows, $assessment['funding_costs'] ?? [], 'expense', $rows, $links !== []);
        $byCost = [];
        foreach ($costPools as $anchor => $pool) foreach ($pool['members'] as $id) $byCost[$id] = $anchor;
        $external = [];
        foreach ($incomePools as $anchor => &$pool) {
            $pool['prior_drawdown_cents'] = 0;
            foreach ($snapshot['funding_history'] ?? [] as $revision) {
                foreach ($revision['usage'] as $usage) {
                    if ($usage['anchor'] !== $anchor && array_intersect($usage['members'], $pool['members']) === []) continue;
                    if ($usage['anchor'] !== $anchor || array_diff($usage['members'], $pool['members']) !== [] || $revision['status'] !== 'confirmed' || ($revision['current'] ?? false) !== true) {
                        $this->review('Předchozí čerpání nebo odvolané posouzení nemá průkaznou návaznost na současný zdroj financování.');
                    }
                    $pool['prior_drawdown_cents'] += $usage['drawdown_cents'];
                }
            }
            $pool['available_cents'] = $pool['amount_cents'] - $pool['prior_drawdown_cents'];
            if ($pool['available_cents'] < 0) {
                $review = $pool['prior_period_review'] ?? null;
                if (!is_array($review) || !is_int($review['adjustment_cents'] ?? null)) $this->review('Vrácení zdroje zasahuje do dříve potvrzeného čerpání. Je nutné doložit samostatné daňové posouzení předchozích období.');
                NonprofitTaxRules::evidence($review['evidence'] ?? null);
                NonprofitTaxRules::evidence($review['legal_basis'] ?? null);
                $external[] = ['evidence' => $review['evidence'], 'legal_basis' => $review['legal_basis'], 'adjustment_cents' => $review['adjustment_cents'],
                    'funding_anchor' => $anchor, 'prior_drawdown_cents' => $pool['prior_drawdown_cents'],
                    'net_source_cents' => $pool['amount_cents'], 'included_in_bridge' => false];
            }
        }
        unset($pool);
        $sources = $costs = $pairs = [];
        $poolCostUse = $poolCostExcluded = $poolCostExemptUse = [];
        foreach ($links as $link) {
            NonprofitTaxRules::evidence($link['evidence'] ?? null);
            $source = $incomePools[(string) ($link['source_id'] ?? '')] ?? null;
            $cost = $rows[(string) ($link['cost_id'] ?? '')] ?? null;
            $amount = $link['amount_cents'] ?? null;
            if ($source === null || $cost === null || $cost['kind'] !== 'expense'
                || !is_int($amount) || $amount <= 0 || $source['amount_cents'] <= 0 || $cost['amount_cents'] <= 0) NonprofitTaxRules::invalid('Vazba financování musí spojovat kladný doložený příjem a náklad.');
            $pair = $source['id'] . ':' . $cost['id'];
            if (isset($pairs[$pair])) NonprofitTaxRules::invalid('Duplicitní vazba financování.');
            $pairs[$pair] = true;
            $sources[$source['id']] = ($sources[$source['id']] ?? 0) + $amount;
            $costs[$cost['id']]['total'] = ($costs[$cost['id']]['total'] ?? 0) + $amount;
            $costAnchor = $byCost[$cost['id']] ?? null;
            if ($costAnchor === null) $this->review('Financovaný náklad nemá průkazný čistý základ po opravách.');
            $poolCostUse[$costAnchor] = ($poolCostUse[$costAnchor] ?? 0) + $amount;
            if ($sources[$source['id']] > max(0, $source['available_cents']) || $costs[$cost['id']]['total'] > $cost['amount_cents']
                || $poolCostUse[$costAnchor] > max(0, $costPools[$costAnchor]['amount_cents'])) NonprofitTaxRules::invalid('Čerpání překračuje čistý zdroj po vrácení a předchozím čerpání nebo čistý náklad po opravách.');
            if (in_array($source['classification']['regime'], ['exempt', 'out_of_scope'], true)) {
                $costs[$cost['id']]['exempt_total'] = ($costs[$cost['id']]['exempt_total'] ?? 0) + $amount;
                $poolCostExemptUse[$costAnchor] = ($poolCostExemptUse[$costAnchor] ?? 0) + $amount;
                $allowed = $cost['deductible_cents'];
                $overlap = $allowed === $cost['amount_cents'] ? $amount : ($allowed === 0 ? 0 : ($link['deductible_funded_cents'] ?? null));
                if (!is_int($overlap) || $overlap < max(0, $amount + $allowed - $cost['amount_cents']) || $overlap > min($amount, $allowed)) {
                    NonprofitTaxRules::invalid('U částečně uznatelného nákladu musí být doložen překryv financované a uznatelné části.');
                }
                $costs[$cost['id']]['excluded'] = ($costs[$cost['id']]['excluded'] ?? 0) + $overlap;
                if ($costs[$cost['id']]['excluded'] > $allowed) NonprofitTaxRules::invalid('Financovaná uznatelná část nákladu se nesmí překrývat mezi zdroji.');
                $poolCostExcluded[$costAnchor] = ($poolCostExcluded[$costAnchor] ?? 0) + $overlap;
                if ($poolCostExcluded[$costAnchor] > max(0, $costPools[$costAnchor]['deductible_cents'])) NonprofitTaxRules::invalid('Financovaná uznatelná část překračuje čistý uznatelný náklad po opravách.');
            }
        }
        foreach ($costs as $id => $funded) {
            NonprofitTaxRules::cumulativeFundingOverlap($funded['exempt_total'] ?? 0, $funded['excluded'] ?? 0,
                $rows[$id]['amount_cents'], $rows[$id]['deductible_cents']);
        }
        foreach ($poolCostExemptUse as $anchor => $funded) {
            NonprofitTaxRules::cumulativeFundingOverlap($funded, $poolCostExcluded[$anchor] ?? 0,
                $costPools[$anchor]['amount_cents'], $costPools[$anchor]['deductible_cents']);
        }
        $usage = [];
        foreach ($incomePools as $anchor => $pool) {
            if (!isset($sources[$anchor]) && count($pool['members']) === 1 && $pool['prior_drawdown_cents'] === 0) continue;
            $usage[] = ['anchor' => $anchor, 'members' => $pool['members'], 'net_source_cents' => $pool['amount_cents'],
                'prior_drawdown_cents' => $pool['prior_drawdown_cents'], 'drawdown_cents' => $sources[$anchor] ?? 0,
                'remaining_cents' => $pool['available_cents'] - ($sources[$anchor] ?? 0)];
        }
        return ['excluded' => array_map(static fn (array $cost): int => $cost['excluded'] ?? 0, $costs),
            'usage' => $usage, 'external_adjustments' => $external];
    }

    public function fundingIncomePools(array $result,array $snapshot,array $assessment):array
    {
        $rows=array_column($result['rows'],null,'id');
        return $this->pools($rows+($snapshot['funding_reference_sources']??[]),$assessment['funding_sources']??[],'revenue',$rows,($assessment['funding']??[])!==[]);
    }

    public function fundingCostPools(array $result,array $assessment):array
    {
        $rows=array_column($result['rows'],null,'id');
        return $this->pools($rows,$assessment['funding_costs']??[],'expense',$rows,($assessment['funding']??[])!==[]);
    }

    private function pools(array $catalog, array $declared, string $kind, array $currentRows, bool $hasFunding): array
    {
        $eligible = static fn (array $row): bool => $row['kind'] === $kind && ($kind === 'expense'
            || in_array($row['decision']['title'] ?? '', ['gift', 'public_operating_grant', 'public_investment_grant'], true));
        $pools = $claimed = [];
        foreach ($declared as $pool) {
            NonprofitTaxRules::evidence($pool['evidence'] ?? null);
            $anchor = $pool['anchor'] ?? null;
            $members = $pool['members'] ?? null;
            if (!is_string($anchor) || !is_array($members) || !array_is_list($members) || $members === []
                || !in_array($anchor, $members, true) || !isset($catalog[$anchor]) || $catalog[$anchor]['amount_cents'] <= 0) $this->review('Financování vyžaduje jednoznačný původní kladný řádek a doložené členy včetně oprav.');
            $base = $catalog[$anchor];
            $total = $deductible = 0;
            foreach ($members as $id) {
                if (!is_string($id) || !isset($catalog[$id]) || isset($claimed[$id]) || !$eligible($catalog[$id])) NonprofitTaxRules::invalid('Člen financování chybí, patří k jinému druhu nebo je použit opakovaně.');
                $row = $catalog[$id];
                if (!isset($currentRows[$id]) && ($row['reference_current'] ?? false) !== true) $this->review('Historický zdroj financování nemá aktuálně ověřený účetní podklad.');
                if ($row['activity_kind_id'] !== $base['activity_kind_id'] || ($kind === 'revenue'
                    && ($row['decision']['title'] !== $base['decision']['title'] || $row['classification']['regime'] !== $base['classification']['regime']))) {
                    $this->review('Spojení zdrojů s odlišnou činností nebo právním režimem vyžaduje samostatné posouzení.');
                }
                $claimed[$id] = true;
                $total += $row['amount_cents'];
                $deductible += $row['deductible_cents'] ?? 0;
            }
            sort($members, SORT_STRING);
            $pools[$anchor] = ['id' => $anchor, 'members' => $members, 'amount_cents' => $total, 'deductible_cents' => $deductible,
                'classification' => $base['classification'], 'prior_period_review' => $pool['prior_period_review'] ?? null];
        }
        foreach ($currentRows as $id => $row) {
            if (!$eligible($row) || isset($claimed[$id])) continue;
            if ($row['amount_cents'] < 0 && ($hasFunding || $kind === 'revenue')) $this->review('Vrácení zdroje nebo nákladu musí mít doloženou vazbu na původní řádek financování.');
            if ($row['amount_cents'] <= 0) continue;
            $pools[$id] = ['id' => $id, 'members' => [$id], 'amount_cents' => $row['amount_cents'],
                'deductible_cents' => $row['deductible_cents'] ?? 0, 'classification' => $row['classification']];
        }
        return $pools;
    }

    private function review(string $message): never
    {
        throw new NonprofitTaxAssessmentException('nonprofit_tax_review_required', $message);
    }
}
