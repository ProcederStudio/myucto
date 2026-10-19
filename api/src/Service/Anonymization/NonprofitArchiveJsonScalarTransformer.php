<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use MyInvoice\Service\Anonymization\Streaming\JsonScalarTransformer;

final readonly class NonprofitArchiveJsonScalarTransformer implements JsonScalarTransformer
{
    public function __construct(
        private TextScrubber $scrubber,
        private ?FinancialCloneHashResolver $hashes = null,
    ) {}

    public function transform(array $path, mixed $value): mixed
    {
        return NonprofitDppoCloneScrubber::transformScalar($this->scrubber, $path, $value, $this->hashes);
    }
}
