<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

final readonly class ProtectedFinancialJsonRewriteResult
{
    public function __construct(
        public string $sourceSha256,
        public string $targetSha256,
        public int $sourceBytes,
        public int $targetBytes,
        public int $nodes,
        public int $members,
    ) {}
}
