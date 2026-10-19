<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization\Nonprofit;

final readonly class J6CloneAssessmentCapture
{
    public string $receiptHash;

    public function __construct(
        public int $assessmentId,
        public string $storageVersion,
        public ?string $receiptBytes,
        public string $inputHash,
        public string $requestHash,
        public array $financialSummary,
    ) {
        if ($assessmentId <= 0 || $storageVersion === '' || ($receiptBytes !== null && strlen($receiptBytes) > 1_048_576)) {
            throw new \InvalidArgumentException('J6 clone capture identity is invalid.');
        }
        foreach ([$inputHash, $requestHash] as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) throw new \InvalidArgumentException('J6 clone capture hash is invalid.');
        }
        foreach (self::financialFields() as $field) {
            if (!is_int($financialSummary[$field] ?? null)) throw new \InvalidArgumentException('J6 clone capture financial summary is incomplete.');
        }
        $this->receiptHash = self::hashReceipt($receiptBytes);
    }

    public function assertMatches(array $row): void
    {
        $receipt = $row['j6_receipt_json'] ?? null;
        if (($row['id'] ?? null) !== $this->assessmentId
            || ($row['storage_version'] ?? null) !== $this->storageVersion
            || ($row['input_hash'] ?? null) !== $this->inputHash
            || ($row['request_hash'] ?? null) !== $this->requestHash
            || $receipt !== $this->receiptBytes
            || !hash_equals($this->receiptHash, self::hashReceipt(is_string($receipt) ? $receipt : null))) {
            throw new \UnexpectedValueException('J6 clone target changed after capture.');
        }
    }

    public static function financialFields(): array
    {
        return ['accounting_result_cents', 'excluded_revenue_cents', 'cost_addbacks_cents', 'assessed_result_cents'];
    }

    private static function hashReceipt(?string $receipt): string
    {
        return hash('sha256', ($receipt === null ? "N\0" : "S\0") . ($receipt ?? ''));
    }
}
