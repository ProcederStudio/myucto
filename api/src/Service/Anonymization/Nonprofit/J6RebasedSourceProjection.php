<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization\Nonprofit;

use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AuthoritativeSourceProvider;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ReadMode;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotHeaderV2;

final class J6RebasedSourceProjection
{
    private bool $consumed = false;
    private int $findingCount = 0;

    public function __construct(
        private readonly J6AuthoritativeSourceProvider $source,
        private readonly J6CloneTypedTransformer $transformer,
    ) {
        if ($source->context()->mode === J6ReadMode::CURRENT) {
            throw new \InvalidArgumentException('J6 clone source projection requires an immutable historic source.');
        }
    }

    public function header(): SnapshotHeaderV2
    {
        return $this->transformer->header($this->source->header());
    }

    public function pages(): iterable
    {
        if ($this->consumed) throw new \LogicException('J6 clone source projection is single-use.');
        $this->consumed = true;
        foreach ($this->source->pages(null, 500) as $page) {
            $transformed = $this->transformer->sourcePage($page);
            $this->findingCount += count($transformed->findings);
            yield $transformed;
        }
    }

    public function findingCount(): int
    {
        if (!$this->consumed) throw new \LogicException('J6 clone source projection was not consumed.');
        return $this->findingCount;
    }
}
