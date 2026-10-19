<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization\Nonprofit;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentGraphReceipt;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6CalculationPageHasher;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6HistoricAssessmentBinding;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6HistoricProviderFactory;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ReadMode;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6SourceEvidencePageHasher;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6VerificationSink;
use MyInvoice\Service\Tax\Nonprofit\J6AuthoritativeCalculator;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;

final readonly class J6CloneAssessmentCas
{
    public function __construct(
        private Connection $db,
        private J6HistoricProviderFactory $providers,
        private J6AuthoritativeCalculator $calculator,
        private J6CalculationPageHasher $calculationHasher,
        private J6SourceEvidencePageHasher $evidenceHasher,
    ) {}

    public function swap(
        int $targetSupplierId,
        J6CloneAssessmentCapture $captured,
        J6AssessmentGraphReceipt $receipt,
        array $decisionHeader,
        string $rebasedRequestHash,
    ): J6HistoricAssessmentBinding {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('J6 clone CAS requires an owner transaction.');
        if ($targetSupplierId <= 0 || preg_match('/^[a-f0-9]{64}$/D', $rebasedRequestHash) !== 1) {
            throw new \InvalidArgumentException('J6 clone CAS target identity is invalid.');
        }
        foreach (['activities', 'decisions', 'funding', 'funding_sources', 'funding_costs'] as $unbounded) {
            if (array_key_exists($unbounded, $decisionHeader)) throw new \InvalidArgumentException('J6 clone decision header contains row collections.');
        }
        $row = $this->locked($targetSupplierId, $captured->assessmentId);
        $captured->assertMatches($row);
        if ($row['ruleset'] !== $receipt->ruleset) throw new \UnexpectedValueException('J6 clone ruleset changed.');
        foreach (J6CloneAssessmentCapture::financialFields() as $field) {
            if (($receipt->summary[$field] ?? null) !== $captured->financialSummary[$field]) {
                throw new \UnexpectedValueException('J6 clone changed the financial assessment.');
            }
        }

        $snapshot = ['schema' => 'nonprofit-j6-snapshot-reference.v1', 'manifest_id' => $receipt->snapshotManifestId,
            'input_hash' => $receipt->snapshotInputHash, 'root_hash' => $receipt->snapshotRootHash];
        $assessment = ['schema' => 'nonprofit-j6-decision-reference.v1', 'header' => $decisionHeader,
            'manifest_id' => $receipt->manifestIds['decision'], 'root_hash' => $receipt->rootHashes['decision']];
        $result = ['schema' => 'nonprofit-j6-result-reference.v1', 'summary' => $receipt->summary,
            'manifest_id' => $receipt->manifestIds['result'], 'root_hash' => $receipt->rootHashes['result'],
            'coverage_manifest_id' => $receipt->manifestIds['coverage'], 'coverage_root_hash' => $receipt->rootHashes['coverage'],
            'usage_manifest_id' => $receipt->manifestIds['usage'], 'usage_root_hash' => $receipt->rootHashes['usage']];
        $graph = NonprofitTaxRules::canonicalJson($receipt->toArray());
        $stmt = $this->db->pdo()->prepare('UPDATE nonprofit_tax_assessments SET
            storage_version=?,input_hash=?,request_hash=?,snapshot_json=?,assessment_json=?,result_json=?,
            source_manifest_id=?,funding_reference_manifest_id=?,funding_history_manifest_id=?,decision_manifest_id=?,
            result_manifest_id=?,coverage_manifest_id=?,usage_manifest_id=?,calculation_input_hash=?,result_root_hash=?,
            coverage_root_hash=?,summary_json=?,j6_receipt_json=?
            WHERE supplier_id=? AND id=? AND storage_version=? AND input_hash=? AND request_hash=? AND j6_receipt_json <=> ?');
        $stmt->execute(['paged_v2', $receipt->snapshotInputHash, $rebasedRequestHash,
            NonprofitTaxRules::canonicalJson($snapshot), NonprofitTaxRules::canonicalJson($assessment),
            NonprofitTaxRules::canonicalJson($result), $receipt->snapshotManifestId,
            $receipt->evidenceManifestIds['funding_reference'], $receipt->evidenceManifestIds['funding_history'],
            $receipt->manifestIds['decision'], $receipt->manifestIds['result'], $receipt->manifestIds['coverage'],
            $receipt->manifestIds['usage'], $receipt->calculationInputHash, $receipt->rootHashes['result'],
            $receipt->rootHashes['coverage'], NonprofitTaxRules::canonicalJson($receipt->summary), $graph,
            $targetSupplierId, $captured->assessmentId, $captured->storageVersion, $captured->inputHash,
            $captured->requestHash, $captured->receiptBytes]);
        if ($stmt->rowCount() !== 1) throw new \UnexpectedValueException('J6 clone CAS target changed.');

        $after = $this->locked($targetSupplierId, $captured->assessmentId);
        foreach (['id', 'supplier_id', 'period_id', 'revision_no', 'previous_id', 'status', 'reason', 'ruleset',
            'idempotency_key', 'created_by', 'created_at'] as $field) {
            if ($after[$field] !== $row[$field]) throw new \UnexpectedValueException('J6 clone CAS changed historical identity.');
        }
        $binding = new J6HistoricAssessmentBinding($targetSupplierId, $row['period_id'], $captured->assessmentId,
            $receipt->snapshotManifestId, $receipt->evidenceManifestIds['funding_reference'],
            $receipt->evidenceManifestIds['funding_history'], $receipt->manifestIds['decision'],
            $receipt->manifestIds['result'], $receipt->manifestIds['coverage'], $receipt->manifestIds['usage'],
            $receipt->snapshotInputHash, $receipt->sourceInputHash, $receipt->calculationInputHash,
            $receipt->rootHashes, $receipt->evidenceRootHashes);
        $providerSet = $this->providers->create($binding, J6ReadMode::REBASED_HISTORIC);
        foreach (['decision', 'result', 'coverage', 'usage'] as $kind) {
            $count = 0;
            foreach ($providerSet->calculation->pages($kind, 500) as $page) $count += count($page);
            if ($count !== $receipt->counts[$kind]) throw new \UnexpectedValueException('J6 clone persisted graph count changed.');
        }
        $this->calculator->calculate($providerSet->sources, $providerSet->decisions,
            new J6VerificationSink($this->calculationHasher, $this->evidenceHasher, $receipt));
        return $binding;
    }

    private function locked(int $supplierId, int $assessmentId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id,supplier_id,period_id,revision_no,previous_id,status,reason,ruleset,
            storage_version,input_hash,request_hash,idempotency_key,created_by,created_at,j6_receipt_json
            FROM nonprofit_tax_assessments WHERE supplier_id=? AND id=? FOR UPDATE');
        $stmt->execute([$supplierId, $assessmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new \UnexpectedValueException('J6 clone target assessment was not found.');
        foreach (['id', 'supplier_id', 'period_id', 'revision_no', 'created_by'] as $field) $row[$field] = (int) $row[$field];
        $row['previous_id'] = $row['previous_id'] === null ? null : (int) $row['previous_id'];
        return $row;
    }
}
