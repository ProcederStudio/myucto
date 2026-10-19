<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization\Nonprofit;

use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AuthoritativeSourceProvider;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6FundingEvidenceProvider;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ReadContext;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ReadMode;

final readonly class J6RebasedFundingEvidenceProvider implements J6FundingEvidenceProvider
{
    public function __construct(
        private J6AuthoritativeSourceProvider $source,
        private J6CloneTypedTransformer $transformer,
        private J6ReadContext $context,
    ) {
        $before = $source->context();
        if ($context->mode !== J6ReadMode::REBASED_HISTORIC || $before->mode === J6ReadMode::CURRENT
            || $before->supplierId !== $context->supplierId || $before->periodId !== $context->periodId
            || $before->assessmentId !== $context->assessmentId || $context->sourceManifestId === null) {
            throw new \InvalidArgumentException('J6 clone funding provider context is invalid.');
        }
    }

    public function fundingReferencePages(J6ReadContext $context, int $limit = 500): iterable
    {
        $this->assertContext($context, $limit);
        foreach ($this->source->fundingReferencePages($limit) as $page) {
            if (!is_array($page) || count($page) > $limit) throw new \UnexpectedValueException('J6 clone funding reference page exceeds its bound.');
            yield array_map(fn (array $row): array => $this->transformer->fundingReference($row), $page);
        }
    }

    public function fundingHistoryPages(J6ReadContext $context, int $limit = 500): iterable
    {
        $this->assertContext($context, $limit);
        foreach ($this->source->fundingHistoryPages($limit) as $page) {
            if (!is_array($page) || count($page) > $limit) throw new \UnexpectedValueException('J6 clone funding history page exceeds its bound.');
            yield array_map(fn (array $row): array => $this->transformer->fundingHistory($row), $page);
        }
    }

    private function assertContext(J6ReadContext $context, int $limit): void
    {
        if ($limit < 1 || $limit > 500 || $context->mode !== $this->context->mode
            || $context->supplierId !== $this->context->supplierId || $context->periodId !== $this->context->periodId
            || $context->assessmentId !== $this->context->assessmentId
            || $context->sourceManifestId !== $this->context->sourceManifestId) {
            throw new \InvalidArgumentException('J6 clone funding evidence was requested for another graph.');
        }
    }
}
