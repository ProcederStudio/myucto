<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

final readonly class CapturedFinancialArchive
{
    public function __construct(
        public int $archiveId,
        public int $supplierId,
        public int $taxReturnId,
        public int $periodYear,
        public int $submissionCount,
        public string $snapshotSha256,
        public string $sourceSha256,
        public string $xmlSha256,
        public bool $finalSnapshot,
        public ?string $computedSha256,
    ) {
        if ($archiveId < 1 || $supplierId < 1 || $taxReturnId < 1 || $periodYear < 1 || $periodYear > 65535 || $submissionCount < 1) {
            throw new \InvalidArgumentException('Captured finanční archiv má neplatnou identitu.');
        }
        if ($finalSnapshot !== ($computedSha256 !== null)) {
            throw new \InvalidArgumentException('Jen finální captured archiv smí nést computed SHA-256.');
        }
        foreach ([$snapshotSha256, $sourceSha256, $xmlSha256, ...($computedSha256 === null ? [] : [$computedSha256])] as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/D',$hash) !== 1) {
                throw new \InvalidArgumentException('Captured finanční archiv vyžaduje přesné SHA-256.');
            }
        }
    }
}
