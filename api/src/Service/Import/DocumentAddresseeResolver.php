<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Support\CompanyIdNormalizer;

/**
 * Které z daných firem doklad patří? Jediné místo pro rozdělování dokladů ze
 * sdílené schránky i pro návrh přesunu v příchozích dokladech.
 *
 * Pořadí důkazů:
 *   1. strojový ISDOC (samostatný, ISDOCX i vložený v PDF) — odběratel je
 *      v datech, rozhoduje jeho IČO, pak DIČ. Text se pak už neposuzuje,
 *      protože PDF k ISDOC může jmenovat i jiné firmy,
 *   2. textová vrstva PDF — IČO nebo DIČ firmy; jméno firmy jen tehdy, když
 *      žádná firma neodpovídá číslem,
 *   3. odběratel přečtený při vytěžení ({@see byBuyer()}).
 *
 * Doklad patří firmě jen při JEDNOZNAČNÉ shodě. Zmiňuje-li text dvě firmy
 * (například účetní kancelář i klienta), výsledek je `ambiguous` a doklad
 * zůstane tam, kam by šel bez rozdělování.
 */
final class DocumentAddresseeResolver
{
    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceDocumentRecognizer $recognizer,
        private readonly PdfTotalExtractor $pdfText,
        private readonly InvoiceExtractionRouter $router,
    ) {}

    /**
     * @param list<int> $candidateIds
     * @return array{supplier_id:?int,matched_by:?string,buyer_ic:?string,buyer_dic:?string,ambiguous:bool}
     */
    public function resolve(string $bytes, ?string $extension, array $candidateIds): array
    {
        $result = ['supplier_id' => null, 'matched_by' => null, 'buyer_ic' => null, 'buyer_dic' => null, 'ambiguous' => false];
        $identities = $this->identities($candidateIds);
        if ($identities === [] || $bytes === '') {
            return $result;
        }

        try {
            $decision = $this->router->decide($bytes, $extension);
        } catch (\Throwable) {
            $decision = null;
        }
        $client = (array) ((($decision?->parsed ?? [])['invoices'][0] ?? [])['client'] ?? []);
        $buyerIc = CompanyIdNormalizer::ic(isset($client['ic']) ? (string) $client['ic'] : null);
        $buyerDic = CompanyIdNormalizer::dic(isset($client['dic']) ? (string) $client['dic'] : null);
        if ($buyerIc !== null || $buyerDic !== null) {
            $match = $this->matchBuyer($buyerIc, $buyerDic, $identities);
            return ['buyer_ic' => $buyerIc, 'buyer_dic' => $buyerDic] + $match + ['matched_by' => null] + $result;
        }

        if (!str_starts_with($bytes, '%PDF')) {
            return $result;
        }
        $text = (string) ($this->pdfText->extractText($bytes) ?? '');
        if (trim($text) === '') {
            return $result;
        }

        $byNumber = [];
        $byName = [];
        foreach ($identities as $id => $identity) {
            $match = $this->recognizer->identityMatch($text, $identity);
            if ($match === null) {
                continue;
            }
            if ($match['by'] === 'name') {
                $byName[$id] = $match['by'];
            } else {
                $byNumber[$id] = $match['by'];
            }
        }
        $pool = $byNumber !== [] ? $byNumber : $byName;
        if (count($pool) === 1) {
            $id = (int) array_key_first($pool);
            return ['supplier_id' => $id, 'matched_by' => (string) $pool[$id]] + $result;
        }
        return ['ambiguous' => count($pool) > 1] + $result;
    }

    /**
     * Firma podle odběratele, kterého přečetlo vytěžení.
     *
     * @param list<int> $candidateIds
     * @return array{supplier_id:?int,matched_by:?string,ambiguous:bool}
     */
    public function byBuyer(?string $ic, ?string $dic, array $candidateIds): array
    {
        return $this->matchBuyer(CompanyIdNormalizer::ic($ic), CompanyIdNormalizer::dic($dic), $this->identities($candidateIds));
    }

    /**
     * @param array<int,array{ic:?string,dic:?string,name:?string}> $identities
     * @return array{supplier_id:?int,matched_by:?string,ambiguous:bool}
     */
    private function matchBuyer(?string $ic, ?string $dic, array $identities): array
    {
        foreach (['ic' => $ic, 'dic' => $dic] as $key => $value) {
            if ($value === null) {
                continue;
            }
            $hits = [];
            foreach ($identities as $id => $identity) {
                $own = $key === 'ic' ? CompanyIdNormalizer::ic($identity['ic']) : CompanyIdNormalizer::dic($identity['dic']);
                if ($own !== null && $own === $value) {
                    $hits[] = $id;
                }
            }
            if (count($hits) === 1) {
                return ['supplier_id' => $hits[0], 'matched_by' => 'isdoc_' . $key, 'ambiguous' => false];
            }
            if (count($hits) > 1) {
                return ['supplier_id' => null, 'matched_by' => null, 'ambiguous' => true];
            }
        }
        return ['supplier_id' => null, 'matched_by' => null, 'ambiguous' => false];
    }

    /**
     * @param list<int> $candidateIds
     * @return array<int,array{ic:?string,dic:?string,name:?string}>
     */
    private function identities(array $candidateIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $candidateIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT id, company_name, ic, dic FROM supplier WHERE id IN ($in) ORDER BY id");
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['id']] = [
                'ic' => $row['ic'] !== null && (string) $row['ic'] !== '' ? (string) $row['ic'] : null,
                'dic' => $row['dic'] !== null && (string) $row['dic'] !== '' ? (string) $row['dic'] : null,
                'name' => $row['company_name'] !== null && (string) $row['company_name'] !== '' ? (string) $row['company_name'] : null,
            ];
        }
        return $out;
    }
}
