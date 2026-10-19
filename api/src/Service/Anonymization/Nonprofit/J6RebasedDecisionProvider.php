<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization\Nonprofit;

use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentDecisionProvider;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6DecisionContext;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ReadMode;

final readonly class J6RebasedDecisionProvider implements J6AssessmentDecisionProvider
{
    public function __construct(
        private J6AssessmentDecisionProvider $source,
        private J6CloneTypedTransformer $transformer,
        private J6DecisionContext $context,
    ) {
        $before = $source->context();
        if ($context->mode !== J6ReadMode::REBASED_HISTORIC || $before->mode === J6ReadMode::CURRENT
            || $before->supplierId !== $context->supplierId || $before->periodId !== $context->periodId
            || $before->assessmentId !== $context->assessmentId) {
            throw new \InvalidArgumentException('J6 clone decision provider context is invalid.');
        }
    }

    public function context(): J6DecisionContext { return $this->context; }
    public function header(): array
    {
        $header = $this->transformer->decisionHeader($this->source->header());
        if (array_key_exists('source_input_hash', $header)) {
            if (!is_string($header['source_input_hash'])
                || !hash_equals($this->source->context()->sourceInputHash, $header['source_input_hash'])) {
                throw new \UnexpectedValueException('J6 clone decision header source hash is invalid.');
            }
            $header['source_input_hash'] = $this->context->sourceInputHash;
        }
        return $header;
    }
    public function decisionPages(int $limit = 500): iterable { yield from $this->pages('source', $this->source->decisionPages($limit)); }
    public function activityPages(int $limit = 500): iterable { yield from $this->pages('activity', $this->source->activityPages($limit)); }
    public function fundingPoolPages(int $limit = 500): iterable { yield from $this->pages('funding_pool', $this->source->fundingPoolPages($limit)); }
    public function fundingMemberPages(int $limit = 500): iterable { yield from $this->pages('funding_member', $this->source->fundingMemberPages($limit)); }
    public function fundingLinkPages(int $limit = 500): iterable { yield from $this->pages('funding_link', $this->source->fundingLinkPages($limit)); }

    private function pages(string $kind, iterable $pages): iterable
    {
        foreach ($pages as $page) {
            if (!is_array($page) || count($page) > 500) throw new \UnexpectedValueException('J6 clone decision page exceeds its bound.');
            yield array_map(fn (array $record): array => $this->transformer->decisionRecord($kind, $record), $page);
        }
    }
}
