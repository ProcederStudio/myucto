<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization\Streaming;

interface JsonScalarTransformer
{
    /** @param list<int|string> $path */
    public function transform(array $path, mixed $value): mixed;
}
