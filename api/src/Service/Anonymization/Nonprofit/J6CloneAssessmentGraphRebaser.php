<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization\Nonprofit;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\NonprofitTaxCalculationManifestRepository;
use MyInvoice\Repository\NonprofitTaxSnapshotManifestRepository;
use MyInvoice\Repository\NonprofitTaxSourceEvidenceManifestRepository;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\HistoricJ6SourceProvider;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentDecisionProvider;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentGraphReceipt;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AuthoritativeSourceProvider;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6CalculationPageHasher;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6DecisionContext;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6HistoricAssessmentBinding;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ImmutableManifestSink;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ReadContext;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ReadMode;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6SourceEvidencePageHasher;
use MyInvoice\Service\Tax\Nonprofit\J6AuthoritativeCalculator;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\J6SourceManifestMaterializer;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotHasher;

final readonly class J6CloneAssessmentGraphRebaser
{
    public function __construct(
        private Connection $db,
        private J6CloneTypedTransformer $transformer,
        private J6SourceManifestMaterializer $sourceMaterializer,
        private NonprofitTaxSnapshotManifestRepository $sourceManifests,
        private NonprofitTaxSnapshotHasher $sourceHasher,
        private J6AuthoritativeCalculator $calculator,
        private NonprofitTaxCalculationManifestRepository $calculationManifests,
        private J6CalculationPageHasher $calculationHasher,
        private NonprofitTaxSourceEvidenceManifestRepository $evidenceManifests,
        private J6SourceEvidencePageHasher $evidenceHasher,
        private J6CloneAssessmentCas $cas,
    ) {}

    public function rebase(
        int $targetSupplierId,
        J6CloneAssessmentCapture $captured,
        J6AuthoritativeSourceProvider $source,
        J6AssessmentDecisionProvider $decisions,
        string $rebasedRequestHash,
    ): J6HistoricAssessmentBinding {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException('J6 clone graph rebase requires an owner transaction.');
        }
        $sourceContext = $source->context();
        $decisionContext = $decisions->context();
        if ($sourceContext->mode === J6ReadMode::CURRENT || $decisionContext->mode === J6ReadMode::CURRENT
            || $sourceContext->supplierId !== $targetSupplierId || $decisionContext->supplierId !== $targetSupplierId
            || $sourceContext->periodId !== $decisionContext->periodId
            || $sourceContext->assessmentId !== $captured->assessmentId
            || $decisionContext->assessmentId !== $captured->assessmentId
            || $decisionContext->decisionManifestId === null) {
            throw new \InvalidArgumentException('J6 clone graph source binding is invalid.');
        }

        $projection = new J6RebasedSourceProjection($source, $this->transformer);
        $snapshot = $this->sourceMaterializer->materialize($projection->header(), $projection->pages());
        $rebasedContext = new J6ReadContext($targetSupplierId, $sourceContext->periodId,
            J6ReadMode::REBASED_HISTORIC, $captured->assessmentId, $snapshot->id);
        $funding = new J6RebasedFundingEvidenceProvider($source, $this->transformer, $rebasedContext);
        $rebasedSource = new HistoricJ6SourceProvider($this->sourceManifests, $this->sourceHasher,
            $rebasedContext, $funding);
        $rebasedDecisions = new J6RebasedDecisionProvider($decisions, $this->transformer,
            new J6DecisionContext($targetSupplierId, $sourceContext->periodId, J6ReadMode::REBASED_HISTORIC,
                $snapshot->inputHash, assessmentId: $captured->assessmentId,
                decisionManifestId: $decisionContext->decisionManifestId));
        $calculation = $this->calculator->calculate($rebasedSource, $rebasedDecisions,
            new J6ImmutableManifestSink($this->calculationManifests, $this->calculationHasher,
                $this->evidenceManifests, $this->evidenceHasher));
        $receipt = J6AssessmentGraphReceipt::fromCalculation($snapshot, $projection->findingCount(), $calculation);
        return $this->cas->swap($targetSupplierId, $captured, $receipt, $rebasedDecisions->header(),
            $rebasedRequestHash);
    }
}
