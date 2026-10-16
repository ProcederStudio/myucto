<?php

declare(strict_types=1);

namespace MyInvoice\Service\TaxEvidence;

use MyInvoice\Repository\MovementClassificationRepository;

/** Prezentační výběr řádků společný pro přehled peněžního deníku i oba exporty. */
final class CashJournalClassificationFilter
{
    public static function normalize(mixed $classification): ?string
    {
        if (!is_string($classification)
            || ($classification !== '' && $classification !== 'nezarazeno'
                && !in_array($classification, MovementClassificationRepository::TAX_BUCKETS, true))) {
            throw new \InvalidArgumentException('classification musí být platná klasifikace peněžního deníku.');
        }
        return $classification === '' ? null : $classification;
    }

    /**
     * CashJournalService nejprve klasifikuje a spočítá celé období. Pohyb může mít více
     * daňových alokací, ale filtr se řídí jeho zobrazenou primární klasifikací.
     * Součty, běžné zůstatky a varování záměrně zůstávají za celý deník.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function apply(array $data, ?string $classification): array
    {
        $rows = $data['rows'];
        $data['classification'] = $classification;
        $data['total_rows'] = count($rows);
        $data['blocking_unclassified_count'] = count(array_filter(
            $rows,
            static fn (array $row): bool => $row['unclassified'] && $row['blocking'],
        ));
        if ($classification !== null) {
            $data['rows'] = array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['bucket'] === $classification,
            ));
        }
        $data['filtered_rows'] = count($data['rows']);
        return $data;
    }
}
