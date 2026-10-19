<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Repository\NonprofitTaxPagedAssessmentRepository;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use PDO;

final readonly class J6FundingDependencyGuard
{
    public function __construct(
        private NonprofitTaxPagedAssessmentRepository $assessments,
        private J6HistoricProviderFactory $historic,
    ) {}

    public function assertNoLaterUsage(int $supplierId, int $periodId, array $assessment): void
    {
        if (($assessment['supplier_id'] ?? null) !== $supplierId || ($assessment['period_id'] ?? null) !== $periodId
            || ($assessment['status'] ?? null) !== 'confirmed') {
            throw new \InvalidArgumentException('J6 funding dependency identity is invalid.');
        }
        $bounds = $this->assessments->periodBounds($supplierId, $periodId, true);
        $store = new J6SqliteWorkingStore();
        try {
            $sqlite = $store->connection();
            $sqlite->exec("CREATE TABLE target_members(member_id TEXT PRIMARY KEY) WITHOUT ROWID;
                CREATE TABLE candidate_members(member_id TEXT PRIMARY KEY) WITHOUT ROWID;");
            $target = $sqlite->prepare('INSERT OR IGNORE INTO target_members(member_id) VALUES(?)');
            $providers = $this->historic->create($this->assessments->binding($assessment), J6ReadMode::HISTORIC);
            foreach ($providers->calculation->pages('result', 500) as $rows) {
                foreach ($rows as $row) {
                    if (($row['kind'] ?? null) === 'revenue'
                        && in_array($row['decision']['title'] ?? null,
                            ['gift', 'public_operating_grant', 'public_investment_grant'], true)) {
                        $this->member($target, $row['id'] ?? null);
                    }
                }
            }
            foreach ($providers->calculation->pages('usage', 500) as $rows) {
                foreach ($rows as $row) {
                    $this->member($target, $row['anchor'] ?? null);
                    $this->member($target, $row['member_id'] ?? null);
                }
            }
            if ((int) $sqlite->query('SELECT COUNT(*) FROM target_members')->fetchColumn() === 0) return;

            $afterStartsOn = '';
            $afterPeriodId = 0;
            do {
                $later = $this->assessments->effectiveAfterPage($supplierId, $periodId, $bounds['ends_on'],
                    $afterStartsOn, $afterPeriodId, 100, true);
                foreach ($later as $revision) {
                    $afterStartsOn = $revision['period_starts_on'];
                    $afterPeriodId = $revision['period_id'];
                    if ($revision['status'] !== 'confirmed') continue;
                    $sqlite->exec('DELETE FROM candidate_members');
                    $candidate = $sqlite->prepare('INSERT OR IGNORE INTO candidate_members(member_id) VALUES(?)');
                    $historic = $this->historic->create($this->assessments->binding($revision), J6ReadMode::HISTORIC);
                    foreach ($historic->calculation->pages('usage', 500) as $rows) {
                        foreach ($rows as $row) {
                            $this->member($candidate, $row['anchor'] ?? null);
                            $this->member($candidate, $row['member_id'] ?? null);
                        }
                        if ($this->overlaps($sqlite)) $this->review();
                    }
                }
            } while (count($later) === 100);
        } finally {
            unset($candidate, $target, $sqlite);
            $store->closeAndDelete();
        }
    }

    private function member(\PDOStatement $insert, mixed $member): void
    {
        if (!is_string($member) || $member === '') throw new \UnexpectedValueException('J6 funding member identity is invalid.');
        $insert->execute([$member]);
    }

    private function overlaps(PDO $sqlite): bool
    {
        return $sqlite->query('SELECT 1 FROM target_members t JOIN candidate_members c ON c.member_id=t.member_id LIMIT 1')
            ->fetchColumn() !== false;
    }

    private function review(): never
    {
        throw new NonprofitTaxAssessmentException('nonprofit_tax_review_required',
            'Na zdroj navazuje potvrzené čerpání v pozdějším období. Nejprve je nutné doloženě přehodnotit navazující posouzení.', 409);
    }
}
