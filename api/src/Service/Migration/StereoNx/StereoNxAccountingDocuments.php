<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Service\Accounting\FiscalCalendar;

/** Účetní faktury Stereo NX; deník zůstává autoritativním zdrojem zaúčtování. */
final class StereoNxAccountingDocuments
{
    public function __construct(
        private readonly StereoNxSourcePlan $sourcePlan,
        private readonly StereoNxImporter $importer,
    ) {}

    /** @return array<string,mixed> */
    public function prepare(StereoNxBackup $backup, bool $blankCountryIsCz = false, ?FiscalCalendar $calendar = null): array
    {
        $tables = [];
        $available = array_flip($backup->tableNames());
        foreach (['LAdresy', 'LFirmaUc', 'Lsdph', 'LSloupce', 'Svfh', 'Svfp', 'SPFH',
            'Spfp', 'Cpz', 'CPZZ', 'ZAZPVDPH', 'CBanka', 'CBankap', 'CPokl', 'Cdenik'] as $name) {
            $tables[$name] = isset($available[$name]) ? iterator_to_array($backup->rows($name), false) : [];
        }
        $source = $this->sourcePlan->fromTables($tables, $backup->companyIdentity(), $blankCountryIsCz, true, $calendar);
        $source['source_company_index'] = $backup->companyIndex();
        $warnings = [];
        $foreignDocuments = 0;
        $skippedForeignDocuments = 0;
        foreach (['issued', 'purchases'] as $part) {
            foreach ($source[$part] ?? [] as $key => $document) {
                if (($document['currency_code'] ?? 'CZK') !== 'CZK') $foreignDocuments++;
                foreach (array_unique(array_map('strval', $document['review_codes'] ?? [])) as $code) {
                    $warnings[] = ['level' => 'warning', 'code' => $code,
                        'document_no' => (string) ($document['document_no'] ?? ''),
                        'message' => 'Doklad ' . (string) ($document['document_no'] ?? '') . ' vyžaduje kontrolu: '
                            . StereoNxImporter::reviewLabel($code) . '.'];
                }
                if (($document['currency_code'] ?? 'CZK') !== 'CZK'
                    && ($document['foreign_takeover_blocked'] ?? null) !== null) {
                    $skippedForeignDocuments++;
                    $warnings[] = ['level' => 'warning', 'code' => 'foreign_document_unverified',
                        'document_no' => (string) ($document['document_no'] ?? ''),
                        'message' => 'Doklad ' . (string) ($document['document_no'] ?? '')
                            . ' nebyl převzat: ' . $document['foreign_takeover_blocked'] . '.'];
                    unset($source[$part][$key]);
                }
            }
        }
        if ($foreignDocuments > 0) {
            $warnings[] = ['level' => 'warning', 'code' => 'foreign_document_ledger_currency_unavailable',
                'message' => 'Účetní deník Stereo NX je převzat v Kč; cizoměnové zůstatky pro kurzové přecenění je nutné zkontrolovat a případně doplnit.',
                'count' => $foreignDocuments];
        }
        return [
            'identity' => $source['identity'],
            'source_company_index' => $backup->companyIndex(),
            'counts' => ['issued' => count($source['issued'] ?? []), 'purchases' => count($source['purchases'] ?? []),
                'skipped_foreign_documents' => $skippedForeignDocuments,
                'requires_draft' => count(array_filter([...(array) ($source['issued'] ?? []), ...(array) ($source['purchases'] ?? [])],
                    static fn (array $d): bool => ($d['requires_draft'] ?? false) === true))],
            'warnings' => $warnings, 'errors' => [],
            'records' => ['clients' => $source['clients'] ?? [], 'issued' => $source['issued'] ?? [],
                'purchases' => $source['purchases'] ?? []],
        ];
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    public function write(array $plan, int $supplierId, int $userId): array
    {
        $source = ['identity' => $plan['identity'] ?? [],
            'source_company_index' => $plan['source_company_index'] ?? null,
            'clients' => $plan['records']['clients'] ?? [], 'issued' => $plan['records']['issued'] ?? [],
            'purchases' => $plan['records']['purchases'] ?? []];
        return $this->importer->writeAccountingDocuments($source, $supplierId, $userId);
    }
}
