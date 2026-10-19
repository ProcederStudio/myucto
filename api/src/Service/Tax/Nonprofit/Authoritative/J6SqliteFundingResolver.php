<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;

final readonly class J6SqliteFundingResolver
{
    public function __construct(private J6SqliteWorkingStore $store) {}

    public function prepare(bool $hasFunding): void
    {
        $pdo = $this->store->connection();
        $pdo->exec(<<<'SQL'
CREATE TABLE resolved_pools (
 pool_kind TEXT NOT NULL,
 anchor TEXT NOT NULL,
 amount_cents INTEGER NOT NULL CHECK(typeof(amount_cents)='integer'),
 deductible_cents INTEGER NOT NULL CHECK(typeof(deductible_cents)='integer'),
 prior_drawdown_cents INTEGER NOT NULL DEFAULT 0 CHECK(typeof(prior_drawdown_cents)='integer'),
 payload_json TEXT NOT NULL CHECK(json_valid(payload_json)),
 PRIMARY KEY(pool_kind,anchor)
) WITHOUT ROWID;
CREATE TABLE resolved_pool_members (
 pool_kind TEXT NOT NULL,
 anchor TEXT NOT NULL,
 member_id TEXT NOT NULL,
 PRIMARY KEY(pool_kind,anchor,member_id),
 UNIQUE(pool_kind,member_id),
 FOREIGN KEY(pool_kind,anchor) REFERENCES resolved_pools(pool_kind,anchor)
) WITHOUT ROWID;
CREATE INDEX ix_j6_resolved_member ON resolved_pool_members(member_id,pool_kind,anchor);
CREATE TABLE funding_link_eval (
 source_anchor TEXT NOT NULL,
 cost_id TEXT NOT NULL,
 cost_anchor TEXT NOT NULL,
 amount_cents INTEGER NOT NULL CHECK(typeof(amount_cents)='integer' AND amount_cents>0),
 overlap_cents INTEGER NOT NULL CHECK(typeof(overlap_cents)='integer' AND overlap_cents>=0),
 payload_json TEXT NOT NULL CHECK(json_valid(payload_json)),
 PRIMARY KEY(source_anchor,cost_id)
) WITHOUT ROWID;
CREATE INDEX ix_j6_eval_cost ON funding_link_eval(cost_id,source_anchor);
CREATE INDEX ix_j6_eval_pool ON funding_link_eval(cost_anchor,source_anchor,cost_id);
SQL);
        $this->declaredPools();
        $this->implicitPools($hasFunding);
        $this->history();
        $this->links();
        $this->capacities();
    }

    public function resolveActivities(): int
    {
        $pdo = $this->store->connection();
        $pdo->exec(<<<'SQL'
CREATE TABLE activity_nodes (
 activity_kind_id TEXT PRIMARY KEY,
 resolved INTEGER NOT NULL DEFAULT 0 CHECK(resolved IN (0,1))
) WITHOUT ROWID;
INSERT INTO activity_nodes(activity_kind_id)
 SELECT DISTINCT activity_kind_id FROM sources WHERE current_row=1 AND regime='pending';
CREATE TABLE activity_edges (
 parent_activity TEXT NOT NULL,
 child_activity TEXT NOT NULL,
 PRIMARY KEY(parent_activity,child_activity)
) WITHOUT ROWID;
INSERT OR IGNORE INTO activity_edges(parent_activity,child_activity)
 SELECT src.activity_kind_id,cost.activity_kind_id
 FROM funding_link_eval e JOIN sources src ON src.source_id=e.source_anchor
 JOIN sources cost ON cost.source_id=e.cost_id
 WHERE src.regime='pending' AND cost.regime='pending' AND cost.deductible_cents<>0
;
CREATE TABLE activity_group_results (
 group_id TEXT PRIMARY KEY,
 activity_kind_id TEXT NOT NULL,
 cohort TEXT NOT NULL,
 revenue_cents INTEGER NOT NULL CHECK(typeof(revenue_cents)='integer'),
 cost_cents INTEGER NOT NULL CHECK(typeof(cost_cents)='integer'),
 regime TEXT NOT NULL CHECK(regime IN ('subject','out_of_scope')),
 contribution_cents INTEGER NOT NULL CHECK(typeof(contribution_cents)='integer')
) WITHOUT ROWID;
SQL);
        $groups = 0;
        while ((int) $pdo->query('SELECT COUNT(*) FROM activity_nodes WHERE resolved=0')->fetchColumn() > 0) {
            $activity = $pdo->query("SELECT n.activity_kind_id FROM activity_nodes n WHERE n.resolved=0
                AND NOT EXISTS(SELECT 1 FROM activity_edges e JOIN activity_nodes p ON p.activity_kind_id=e.parent_activity
                    WHERE e.child_activity=n.activity_kind_id AND p.resolved=0)
                ORDER BY n.activity_kind_id LIMIT 1")->fetchColumn();
            if (!is_string($activity)) {
                $groups += $this->resolveConditionalCycle();
                break;
            }
            $groups += $this->resolveActivity($activity);
            $stmt = $pdo->prepare('UPDATE activity_nodes SET resolved=1 WHERE activity_kind_id=?');
            $stmt->execute([$activity]);
        }
        $this->resolvedFunding();
        return $groups;
    }

    public function activityRows(): iterable
    {
        $statement = $this->store->connection()->query('SELECT group_id,activity_kind_id,cohort,revenue_cents,cost_cents,regime,contribution_cents FROM activity_group_results ORDER BY group_id');
        while (($row = $statement->fetch()) !== false) {
            yield ['group' => $row['group_id'], 'activity_kind_id' => $row['activity_kind_id'], 'cohort' => $row['cohort'],
                'revenue_cents' => (int) $row['revenue_cents'], 'cost_cents' => (int) $row['cost_cents'],
                'regime' => $row['regime'], 'contribution_cents' => (int) $row['contribution_cents']];
        }
    }

    public function externalAdjustmentRows(): iterable
    {
        $statement = $this->store->connection()->query("SELECT anchor,payload_json,prior_drawdown_cents,amount_cents
            FROM resolved_pools WHERE pool_kind='revenue' AND amount_cents-prior_drawdown_cents<0 ORDER BY anchor");
        while (($row = $statement->fetch()) !== false) {
            $payload = json_decode($row['payload_json'], true, flags: JSON_THROW_ON_ERROR);
            $review = $payload['prior_period_review'];
            yield ['evidence' => $review['evidence'], 'legal_basis' => $review['legal_basis'],
                'adjustment_cents' => $review['adjustment_cents'], 'funding_anchor' => $row['anchor'],
                'prior_drawdown_cents' => (int) $row['prior_drawdown_cents'], 'net_source_cents' => (int) $row['amount_cents'],
                'included_in_bridge' => false];
        }
    }

    public function finalRows(): iterable
    {
        $pdo = $this->store->connection();
        $statement = $pdo->query(<<<'SQL'
SELECT s.row_json,s.regime AS stored_regime,s.method AS stored_method,g.regime AS group_regime,
 COALESCE(x.excluded_cents,0) AS funded_excluded_cents
FROM sources s
LEFT JOIN activity_group_results g ON g.group_id=s.group_id
LEFT JOIN (
 SELECT e.cost_id,SUM(e.overlap_cents) AS excluded_cents
 FROM funding_link_eval e JOIN sources src ON src.source_id=e.source_anchor
 WHERE src.regime IN ('exempt','out_of_scope') GROUP BY e.cost_id
) x ON x.cost_id=s.source_id
WHERE s.current_row=1 ORDER BY s.source_id
SQL);
        while (($stored = $statement->fetch()) !== false) {
            $row = json_decode($stored['row_json'], true, flags: JSON_THROW_ON_ERROR);
            $regime = $stored['stored_regime'] === 'pending' ? $stored['group_regime'] : $stored['stored_regime'];
            if (!is_string($regime)) throw new \UnexpectedValueException('J6 activity result is missing.');
            $row['classification']['regime'] = $regime;
            if (($row['classification']['regime'] ?? null) !== $stored['stored_regime']) {
                $row['classification']['method'] = $regime === 'subject' ? 'return' : 'none';
            }
            $excluded = (int) $stored['funded_excluded_cents'];
            $row['funded_excluded_cents'] = $excluded;
            if ($row['kind'] === 'expense') {
                $row['deductible_cents'] = $regime === 'out_of_scope' ? 0
                    : ($row['amount_cents'] <=> 0) * (abs($row['deductible_cents']) - abs($excluded));
            }
            yield $row;
        }
    }

    public function usageRows(): iterable
    {
        $statement = $this->store->connection()->query(<<<'SQL'
SELECT p.anchor,m.member_id,p.amount_cents AS net_source_cents,p.prior_drawdown_cents,
 COALESCE(u.drawdown_cents,0) AS drawdown_cents,
 p.amount_cents-p.prior_drawdown_cents-COALESCE(u.drawdown_cents,0) AS remaining_cents,
 c.member_count
FROM resolved_pools p JOIN resolved_pool_members m ON m.pool_kind=p.pool_kind AND m.anchor=p.anchor
LEFT JOIN (SELECT source_anchor,SUM(amount_cents) AS drawdown_cents FROM funding_link_eval GROUP BY source_anchor) u
 ON u.source_anchor=p.anchor
JOIN (SELECT pool_kind,anchor,COUNT(*) AS member_count FROM resolved_pool_members GROUP BY pool_kind,anchor) c
 ON c.pool_kind=p.pool_kind AND c.anchor=p.anchor
WHERE p.pool_kind='revenue' AND (u.drawdown_cents IS NOT NULL OR p.prior_drawdown_cents<>0 OR c.member_count>1)
ORDER BY p.anchor,m.member_id
SQL);
        while (($row = $statement->fetch()) !== false) {
            unset($row['member_count']);
            foreach (['net_source_cents', 'prior_drawdown_cents', 'drawdown_cents', 'remaining_cents'] as $field) $row[$field] = (int) $row[$field];
            $row['usage_record_hash'] = NonprofitTaxRules::hash(['domain' => 'J6FUNDINGUSAGE2', 'row' => $row]);
            yield $row;
        }
    }

    private function declaredPools(): void
    {
        $pdo = $this->store->connection();
        $query = $pdo->query(<<<'SQL'
SELECT p.pool_kind,p.anchor,p.payload_json,
 COUNT(m.member_id) AS member_count,
 COALESCE(SUM(s.source_id IS NOT NULL),0) AS source_count,
 COALESCE(SUM(m.member_id=p.anchor),0) AS anchor_count,
 COALESCE(SUM(CASE WHEN p.pool_kind='expense' THEN s.kind='expense'
   ELSE s.kind='revenue' AND s.title IN ('gift','public_operating_grant','public_investment_grant') END),0) AS eligible_count,
 COALESCE(SUM(s.current_row=1 OR s.reference_current=1),0) AS current_count,
 COUNT(DISTINCT s.activity_kind_id) AS activity_count,
 COUNT(DISTINCT CASE WHEN p.pool_kind='revenue' THEN s.title END) AS title_count,
 COUNT(DISTINCT CASE WHEN p.pool_kind='revenue' THEN s.regime END) AS regime_count,
 COALESCE(SUM(s.amount_cents),0) AS amount_cents,
 COALESCE(SUM(s.deductible_cents),0) AS deductible_cents
FROM funding_pools p
LEFT JOIN funding_members m ON m.pool_kind=p.pool_kind AND m.anchor=p.anchor
LEFT JOIN sources s ON s.source_id=m.member_id
GROUP BY p.pool_kind,p.anchor,p.payload_json ORDER BY p.pool_kind,p.anchor
SQL);
        $insertPool = $pdo->prepare('INSERT INTO resolved_pools(pool_kind,anchor,amount_cents,deductible_cents,payload_json) VALUES(?,?,?,?,?)');
        while (($row = $query->fetch()) !== false) {
            $payload = json_decode($row['payload_json'], true, flags: JSON_THROW_ON_ERROR);
            NonprofitTaxRules::evidence($payload['evidence'] ?? null);
            $count = (int) $row['member_count'];
            if ($count === 0 || (int) $row['source_count'] !== $count || (int) $row['anchor_count'] !== 1
                || (int) $row['eligible_count'] !== $count || (int) $row['current_count'] !== $count) {
                NonprofitTaxRules::invalid('Fond financování nemá jednoznačný původní kladný řádek a doložené členy včetně oprav.');
            }
            if ((int) $row['activity_count'] !== 1 || ($row['pool_kind'] === 'revenue'
                && ((int) $row['title_count'] !== 1 || (int) $row['regime_count'] !== 1))) {
                $this->review('Spojení zdrojů s odlišnou činností nebo právním režimem vyžaduje samostatné posouzení.');
            }
            $insertPool->execute([$row['pool_kind'], $row['anchor'], (int) $row['amount_cents'],
                (int) $row['deductible_cents'], $row['payload_json']]);
        }
        $pdo->exec('INSERT INTO resolved_pool_members SELECT pool_kind,anchor,member_id FROM funding_members');
    }

    private function implicitPools(bool $hasFunding): void
    {
        $pdo = $this->store->connection();
        $negative = $pdo->prepare("SELECT 1 FROM sources s LEFT JOIN funding_members m
            ON m.pool_kind=s.kind AND m.member_id=s.source_id
            WHERE m.member_id IS NULL AND s.amount_cents<0 AND (s.kind='revenue' OR ?=1) LIMIT 1");
        $negative->execute([$hasFunding ? 1 : 0]);
        if ($negative->fetchColumn() !== false) {
            $this->review('Vrácení zdroje nebo nákladu musí mít doloženou vazbu na původní řádek financování.');
        }
        $pdo->exec(<<<'SQL'
INSERT INTO resolved_pools(pool_kind,anchor,amount_cents,deductible_cents,payload_json)
SELECT s.kind,s.source_id,s.amount_cents,s.deductible_cents,'{}'
FROM sources s LEFT JOIN funding_members m ON m.pool_kind=s.kind AND m.member_id=s.source_id
WHERE m.member_id IS NULL AND s.current_row=1 AND s.amount_cents>0
 AND (s.kind='expense' OR s.title IN ('gift','public_operating_grant','public_investment_grant'));
INSERT INTO resolved_pool_members(pool_kind,anchor,member_id)
SELECT pool_kind,anchor,anchor FROM resolved_pools
WHERE NOT EXISTS(SELECT 1 FROM funding_members m WHERE m.pool_kind=resolved_pools.pool_kind AND m.anchor=resolved_pools.anchor);
SQL);
    }

    private function history(): void
    {
        $pdo = $this->store->connection();
        $pdo->exec(<<<'SQL'
CREATE TEMP TABLE history_pool_map AS
SELECT DISTINCT h.assessment_id,h.anchor AS history_anchor,p.anchor AS current_anchor
FROM funding_history h JOIN resolved_pools p ON p.pool_kind='revenue'
WHERE h.anchor=p.anchor OR EXISTS (
 SELECT 1 FROM funding_history hm JOIN resolved_pool_members pm
  ON pm.pool_kind='revenue' AND pm.anchor=p.anchor AND pm.member_id=hm.member_id
 WHERE hm.assessment_id=h.assessment_id AND hm.anchor=h.anchor
);
CREATE UNIQUE INDEX ix_j6_history_map ON history_pool_map(assessment_id,history_anchor,current_anchor);
SQL);
        $bad = $pdo->query(<<<'SQL'
SELECT 1 FROM history_pool_map x
WHERE x.history_anchor<>x.current_anchor
 OR (SELECT COUNT(*) FROM history_pool_map y WHERE y.assessment_id=x.assessment_id AND y.history_anchor=x.history_anchor)<>1
 OR EXISTS(SELECT 1 FROM funding_history h WHERE h.assessment_id=x.assessment_id AND h.anchor=x.history_anchor
     AND (h.status<>'confirmed' OR h.current_proof<>1))
 OR EXISTS(SELECT 1 FROM funding_history h WHERE h.assessment_id=x.assessment_id AND h.anchor=x.history_anchor
     AND NOT EXISTS(SELECT 1 FROM resolved_pool_members p WHERE p.pool_kind='revenue' AND p.anchor=x.current_anchor AND p.member_id=h.member_id))
LIMIT 1
SQL)->fetchColumn();
        if ($bad !== false) $this->review('Předchozí čerpání nebo odvolané posouzení nemá průkaznou návaznost na současný zdroj financování.');
        $pdo->exec(<<<'SQL'
UPDATE resolved_pools SET prior_drawdown_cents=COALESCE((
 SELECT SUM(u.drawdown_cents) FROM (
  SELECT h.assessment_id,h.anchor,MIN(h.drawdown_cents) AS drawdown_cents
  FROM funding_history h WHERE h.anchor=resolved_pools.anchor GROUP BY h.assessment_id,h.anchor
 ) u
),0) WHERE pool_kind='revenue';
SQL);
    }

    private function links(): void
    {
        $pdo = $this->store->connection();
        $select = $pdo->query(<<<'SQL'
SELECT l.source_id,l.cost_id,l.payload_json,r.amount_cents AS source_amount,r.prior_drawdown_cents,
 c.amount_cents AS cost_amount,c.deductible_cents,m.anchor AS cost_anchor
FROM funding_links l
LEFT JOIN resolved_pools r ON r.pool_kind='revenue' AND r.anchor=l.source_id
LEFT JOIN sources c ON c.source_id=l.cost_id AND c.current_row=1
LEFT JOIN resolved_pool_members m ON m.pool_kind='expense' AND m.member_id=l.cost_id
ORDER BY l.source_id,l.cost_id
SQL);
        $insert = $pdo->prepare('INSERT INTO funding_link_eval(source_anchor,cost_id,cost_anchor,amount_cents,overlap_cents,payload_json) VALUES(?,?,?,?,?,?)');
        while (($row = $select->fetch()) !== false) {
            $payload = json_decode($row['payload_json'], true, flags: JSON_THROW_ON_ERROR);
            NonprofitTaxRules::evidence($payload['evidence'] ?? null);
            $amount = $payload['amount_cents'] ?? null;
            if (!is_int($amount) || $amount <= 0 || $row['source_amount'] === null || $row['cost_amount'] === null
                || $row['cost_anchor'] === null || (int) $row['source_amount'] <= 0 || (int) $row['cost_amount'] <= 0) {
                NonprofitTaxRules::invalid('Vazba financování musí spojovat kladný doložený příjem a náklad.');
            }
            $allowed = (int) $row['deductible_cents'];
            $cost = (int) $row['cost_amount'];
            $overlap = $allowed === $cost ? $amount : ($allowed === 0 ? 0 : ($payload['deductible_funded_cents'] ?? null));
            if (!is_int($overlap) || $overlap < max(0, $amount + $allowed - $cost) || $overlap > min($amount, $allowed)) {
                NonprofitTaxRules::invalid('U částečně uznatelného nákladu musí být doložen překryv financované a uznatelné části.');
            }
            $insert->execute([$row['source_id'], $row['cost_id'], $row['cost_anchor'], $amount, $overlap, $row['payload_json']]);
        }
    }

    private function capacities(): void
    {
        $pdo = $this->store->connection();
        $bad = $pdo->query(<<<'SQL'
SELECT 1 FROM (
 SELECT e.source_anchor,SUM(e.amount_cents) used,p.amount_cents-p.prior_drawdown_cents available
 FROM funding_link_eval e JOIN resolved_pools p ON p.pool_kind='revenue' AND p.anchor=e.source_anchor GROUP BY e.source_anchor
 HAVING used>MAX(0,available)
 UNION ALL
 SELECT e.cost_id,SUM(e.amount_cents),s.amount_cents FROM funding_link_eval e JOIN sources s ON s.source_id=e.cost_id GROUP BY e.cost_id HAVING SUM(e.amount_cents)>s.amount_cents
 UNION ALL
 SELECT e.cost_anchor,SUM(e.amount_cents),p.amount_cents FROM funding_link_eval e JOIN resolved_pools p ON p.pool_kind='expense' AND p.anchor=e.cost_anchor GROUP BY e.cost_anchor HAVING SUM(e.amount_cents)>MAX(0,p.amount_cents)
) LIMIT 1
SQL)->fetchColumn();
        if ($bad !== false) NonprofitTaxRules::invalid('Čerpání překračuje čistý zdroj po vrácení a předchozím čerpání nebo čistý náklad po opravách.');
    }

    private function resolveActivity(string $activity): int
    {
        $pdo = $this->store->connection();
        $sql = <<<'SQL'
SELECT s.group_id,s.cohort,
 SUM(CASE WHEN s.kind='revenue' THEN s.amount_cents ELSE 0 END) AS revenue_cents,
 SUM(CASE WHEN s.kind='expense' THEN s.deductible_cents-COALESCE(x.excluded_cents,0) ELSE 0 END) AS cost_cents
FROM sources s LEFT JOIN (
 SELECT e.cost_id,SUM(e.overlap_cents) AS excluded_cents
 FROM funding_link_eval e JOIN sources src ON src.source_id=e.source_anchor
 WHERE src.regime IN ('exempt','out_of_scope') GROUP BY e.cost_id
) x ON x.cost_id=s.source_id
WHERE s.current_row=1 AND s.activity_kind_id=? AND s.regime='pending'
GROUP BY s.group_id ORDER BY s.group_id
SQL;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$activity]);
        $positive = $nonpositive = false;
        while (($group = $stmt->fetch()) !== false) {
            if ($group['cohort'] !== '') {
                (int) $group['revenue_cents'] > (int) $group['cost_cents'] ? $positive = true : $nonpositive = true;
            }
        }
        $mixed = $positive && $nonpositive;
        $stmt->execute([$activity]);
        $insert = $pdo->prepare('INSERT INTO activity_group_results(group_id,activity_kind_id,cohort,revenue_cents,cost_cents,regime,contribution_cents) VALUES(?,?,?,?,?,?,?)');
        $update = $pdo->prepare("UPDATE sources SET regime=?,method=? WHERE current_row=1 AND kind='revenue' AND group_id=? AND regime='pending'");
        $count = 0;
        while (($group = $stmt->fetch()) !== false) {
            $revenue = (int) $group['revenue_cents'];
            $cost = (int) $group['cost_cents'];
            $out = $mixed ? $revenue <= $cost : $revenue < $cost;
            $regime = $out ? 'out_of_scope' : 'subject';
            $insert->execute([$group['group_id'], $activity, $group['cohort'], $revenue, $cost, $regime, $out ? 0 : $revenue - $cost]);
            $update->execute([$regime, $regime === 'subject' ? 'return' : 'none', $group['group_id']]);
            ++$count;
        }
        return $count;
    }

    private function resolveConditionalCycle(): int
    {
        $pdo = $this->store->connection();
        $pdo->exec(<<<'SQL'
CREATE TEMP TABLE cycle_groups (
 group_id TEXT PRIMARY KEY,
 bit INTEGER NOT NULL UNIQUE CHECK(typeof(bit)='integer' AND bit>=0 AND bit<10)
) WITHOUT ROWID;
INSERT INTO cycle_groups(group_id,bit)
 SELECT group_id,ROW_NUMBER() OVER(ORDER BY group_id)-1 FROM (
  SELECT DISTINCT src.group_id FROM funding_link_eval e
  JOIN sources src ON src.source_id=e.source_anchor
  WHERE src.current_row=1 AND src.kind='revenue' AND src.regime='pending'
 );
CREATE TEMP TABLE cycle_revenues (
 source_id TEXT PRIMARY KEY,
 group_id TEXT NOT NULL
) WITHOUT ROWID;
INSERT INTO cycle_revenues
 SELECT s.source_id,s.group_id FROM sources s JOIN cycle_groups g ON g.group_id=s.group_id
 WHERE s.current_row=1 AND s.kind='revenue' AND s.regime='pending';
CREATE TEMP TABLE cycle_solution (
 group_id TEXT PRIMARY KEY,
 activity_kind_id TEXT NOT NULL,
 cohort TEXT NOT NULL,
 revenue_cents INTEGER NOT NULL,
 cost_cents INTEGER NOT NULL,
 regime TEXT NOT NULL,
 contribution_cents INTEGER NOT NULL CHECK(typeof(contribution_cents)='integer')
) WITHOUT ROWID;
SQL);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM cycle_groups')->fetchColumn();
        if ($count === 0 || $count > 10) $this->cycleReview();
        $keys = [];
        $groups = $pdo->query('SELECT group_id,bit FROM cycle_groups ORDER BY bit');
        while (($group = $groups->fetch()) !== false) $keys[(int) $group['bit']] = $group['group_id'];
        $groups->closeCursor();
        $reset = $pdo->prepare("UPDATE sources SET regime='pending',method='return' WHERE source_id IN (SELECT source_id FROM cycle_revenues)");
        $assume = $pdo->prepare('UPDATE sources SET regime=?,method=? WHERE current_row=1 AND kind=\'revenue\' AND group_id=?');
        $solution = false;
        $uncertain = false;
        for ($state = 0; $state < (1 << $count); ++$state) {
            $reset->execute();
            $assumed = [];
            foreach ($keys as $bit => $groupId) {
                $regime = ($state & (1 << $bit)) !== 0 ? 'out_of_scope' : 'subject';
                $assume->execute([$regime, $regime === 'subject' ? 'return' : 'none', $groupId]);
                $assumed[$groupId] = $regime;
            }
            try {
                $this->resolvedFunding();
            } catch (NonprofitTaxAssessmentException) {
                $uncertain = true;
                continue;
            }
            $this->candidateGroupResults();
            $matches = true;
            $lookup = $pdo->prepare('SELECT regime FROM candidate_group_results WHERE group_id=?');
            foreach ($assumed as $groupId => $regime) {
                $lookup->execute([$groupId]);
                $actual = $lookup->fetchColumn();
                $lookup->closeCursor();
                if ($actual !== $regime) {
                    $matches = false;
                    break;
                }
            }
            if (!$matches) continue;
            if ($solution) $this->cycleReview();
            $pdo->exec('DELETE FROM cycle_solution; INSERT INTO cycle_solution SELECT * FROM candidate_group_results');
            $solution = true;
        }
        if (!$solution || $uncertain) $this->cycleReview();
        $reset->execute();
        $pdo->exec(<<<'SQL'
INSERT INTO activity_group_results SELECT * FROM cycle_solution;
UPDATE sources SET
 regime=(SELECT regime FROM cycle_solution WHERE group_id=sources.group_id),
 method=CASE (SELECT regime FROM cycle_solution WHERE group_id=sources.group_id) WHEN 'subject' THEN 'return' ELSE 'none' END
WHERE current_row=1 AND kind='revenue' AND regime='pending'
 AND EXISTS(SELECT 1 FROM cycle_solution WHERE group_id=sources.group_id);
UPDATE activity_nodes SET resolved=1
WHERE EXISTS(SELECT 1 FROM cycle_solution s WHERE s.activity_kind_id=activity_nodes.activity_kind_id);
SQL);
        $this->resolvedFunding();
        return (int) $pdo->query('SELECT COUNT(*) FROM cycle_solution')->fetchColumn();
    }

    private function candidateGroupResults(): void
    {
        $pdo = $this->store->connection();
        $pdo->exec('DROP TABLE IF EXISTS candidate_group_results; DROP TABLE IF EXISTS candidate_group_totals; DROP TABLE IF EXISTS candidate_activity_mix');
        $pdo->exec(<<<'SQL'
CREATE TEMP TABLE candidate_group_totals AS
SELECT s.group_id,s.activity_kind_id,s.cohort,
 SUM(CASE WHEN s.kind='revenue' THEN s.amount_cents ELSE 0 END) AS revenue_cents,
 SUM(CASE WHEN s.kind='expense' THEN s.deductible_cents-COALESCE(x.excluded_cents,0) ELSE 0 END) AS cost_cents
FROM sources s LEFT JOIN (
 SELECT e.cost_id,SUM(e.overlap_cents) AS excluded_cents
 FROM funding_link_eval e JOIN sources src ON src.source_id=e.source_anchor
 WHERE src.regime IN ('exempt','out_of_scope') GROUP BY e.cost_id
) x ON x.cost_id=s.source_id
WHERE s.current_row=1 AND EXISTS(SELECT 1 FROM cycle_groups c WHERE c.group_id=s.group_id)
GROUP BY s.group_id,s.activity_kind_id,s.cohort;
CREATE UNIQUE INDEX ix_j6_candidate_group ON candidate_group_totals(group_id);
CREATE TEMP TABLE candidate_activity_mix AS
SELECT activity_kind_id,
 MAX(CASE WHEN cohort<>'' AND revenue_cents>cost_cents THEN 1 ELSE 0 END) AS positive,
 MAX(CASE WHEN cohort<>'' AND revenue_cents<=cost_cents THEN 1 ELSE 0 END) AS nonpositive
FROM candidate_group_totals GROUP BY activity_kind_id;
CREATE UNIQUE INDEX ix_j6_candidate_activity ON candidate_activity_mix(activity_kind_id);
CREATE TEMP TABLE candidate_group_results AS
SELECT g.group_id,g.activity_kind_id,g.cohort,g.revenue_cents,g.cost_cents,
 CASE WHEN (m.positive=1 AND m.nonpositive=1 AND g.revenue_cents<=g.cost_cents)
        OR (NOT(m.positive=1 AND m.nonpositive=1) AND g.revenue_cents<g.cost_cents)
      THEN 'out_of_scope' ELSE 'subject' END AS regime,
 CASE WHEN (m.positive=1 AND m.nonpositive=1 AND g.revenue_cents<=g.cost_cents)
        OR (NOT(m.positive=1 AND m.nonpositive=1) AND g.revenue_cents<g.cost_cents)
      THEN 0 ELSE g.revenue_cents-g.cost_cents END AS contribution_cents
FROM candidate_group_totals g JOIN candidate_activity_mix m ON m.activity_kind_id=g.activity_kind_id;
CREATE UNIQUE INDEX ix_j6_candidate_result ON candidate_group_results(group_id);
SQL);
    }

    private function resolvedFunding(): void
    {
        $pdo = $this->store->connection();
        $costs = $pdo->query(<<<'SQL'
SELECT s.amount_cents,s.deductible_cents,
 COALESCE(SUM(CASE WHEN src.regime IN ('exempt','out_of_scope') THEN e.amount_cents ELSE 0 END),0) AS exempt_total,
 COALESCE(SUM(CASE WHEN src.regime IN ('exempt','out_of_scope') THEN e.overlap_cents ELSE 0 END),0) AS excluded_total
FROM sources s JOIN funding_link_eval e ON e.cost_id=s.source_id
JOIN sources src ON src.source_id=e.source_anchor GROUP BY s.source_id,s.amount_cents,s.deductible_cents
SQL);
        while (($row = $costs->fetch()) !== false) NonprofitTaxRules::cumulativeFundingOverlap(
            (int) $row['exempt_total'], (int) $row['excluded_total'], (int) $row['amount_cents'], (int) $row['deductible_cents']);
        $pools = $pdo->query(<<<'SQL'
SELECT p.amount_cents,p.deductible_cents,
 COALESCE(SUM(CASE WHEN src.regime IN ('exempt','out_of_scope') THEN e.amount_cents ELSE 0 END),0) AS exempt_total,
 COALESCE(SUM(CASE WHEN src.regime IN ('exempt','out_of_scope') THEN e.overlap_cents ELSE 0 END),0) AS excluded_total
FROM resolved_pools p JOIN funding_link_eval e ON e.cost_anchor=p.anchor
JOIN sources src ON src.source_id=e.source_anchor WHERE p.pool_kind='expense'
GROUP BY p.anchor,p.amount_cents,p.deductible_cents
SQL);
        while (($row = $pools->fetch()) !== false) NonprofitTaxRules::cumulativeFundingOverlap(
            (int) $row['exempt_total'], (int) $row['excluded_total'], (int) $row['amount_cents'], (int) $row['deductible_cents']);
        $negative = $pdo->query("SELECT p.anchor,p.payload_json,p.prior_drawdown_cents,p.amount_cents FROM resolved_pools p
            WHERE p.pool_kind='revenue' AND p.amount_cents-p.prior_drawdown_cents<0 ORDER BY p.anchor");
        while (($row = $negative->fetch()) !== false) {
            $payload = json_decode($row['payload_json'], true, flags: JSON_THROW_ON_ERROR);
            $review = $payload['prior_period_review'] ?? null;
            if (!is_array($review) || !is_int($review['adjustment_cents'] ?? null)) {
                $this->review('Vrácení zdroje zasahuje do dříve potvrzeného čerpání. Je nutné doložit samostatné daňové posouzení předchozích období.');
            }
            NonprofitTaxRules::evidence($review['evidence'] ?? null);
            NonprofitTaxRules::evidence($review['legal_basis'] ?? null);
        }
    }

    private function cycleReview(): never
    {
        $this->review('Kruhová závislost financování a ročního testu nemá doložené jediné řešení a vyžaduje samostatné posouzení.');
    }

    private function review(string $message): never
    {
        throw new NonprofitTaxAssessmentException('nonprofit_tax_review_required', $message);
    }
}
