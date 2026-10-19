<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

final readonly class LegacyStoredProofStreamReceipt
{
    public function __construct(
        public string $profile,
        public int $rowCount,
        public array $lineCents,
        public string $rowsHash,
        public string $sourceLinesHash,
        public int $taxableSourceCount,
        public string $taxableSourcesHash,
        public ?string $j6ReceiptHash,
    ) {
        if (!in_array($profile, ['v3', 'v2', 'legacy'], true) || $rowCount < 0 || $taxableSourceCount < 0) {
            throw new \InvalidArgumentException('Legacy StoredProof stream receipt má neplatnou identitu.');
        }
        foreach ([$rowsHash, $sourceLinesHash, $taxableSourcesHash, ...($j6ReceiptHash === null ? [] : [$j6ReceiptHash])] as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new \InvalidArgumentException('Legacy StoredProof stream receipt má neplatný hash.');
            }
        }
    }

    public function financialIdentity(): array
    {
        return [
            'row_count' => $this->rowCount,
            'line_cents' => $this->lineCents,
            'taxable_source_count' => $this->taxableSourceCount,
            'taxable_sources_hash' => $this->taxableSourcesHash,
        ];
    }
}
