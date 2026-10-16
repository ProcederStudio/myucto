<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

/** Odkazy z přehledů používají stejné období jako výkaz, ne datum jeho splatnosti. */
final class TaxReportLink
{
    /** @param array{year:int,month:?int,quarter:?int} $period */
    public static function forPeriod(string $path, array $period): string
    {
        $query = ['year' => $period['year']];
        if ($period['quarter'] !== null) {
            $query['month'] = ($period['quarter'] - 1) * 3 + 1;
            $query['period'] = 'quarterly';
        } elseif ($period['month'] !== null) {
            $query['month'] = $period['month'];
            $query['period'] = 'monthly';
        }
        return $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
