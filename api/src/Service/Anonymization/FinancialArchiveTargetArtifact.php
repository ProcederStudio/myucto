<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

final readonly class FinancialArchiveTargetArtifact
{
    public string $sourceSha256;
    public ?string $computedSha256;

    public function __construct(
        public FinancialArchiveSpool $snapshot,
        public string $sourceManifest,
        public ?string $computed,
        public FinancialArchiveSpool $xml,
    ) {
        foreach (['source manifest' => $sourceManifest, ...($computed === null ? [] : ['computed return' => $computed])] as $label => $json) {
            try {
                $value = json_decode($json,true,512,JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \InvalidArgumentException("Target {$label} není platný JSON.",0,$e);
            }
            if (!is_array($value)) {
                throw new \InvalidArgumentException("Target {$label} musí být JSON objekt nebo pole.");
            }
        }
        $this->sourceSha256 = hash('sha256', $sourceManifest);
        $this->computedSha256 = $computed === null ? null : hash('sha256', $computed);
    }
}
