<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Services\Settlement;

/**
 * Lhůty zákona č. 67/2013 Sb. k vyúčtování služeb jako upozornění, ne blokace:
 *  - § 7: vyúčtování se doručí nejpozději do 4 měsíců od skončení zúčtovacího období,
 *  - § 7: finanční vyrovnání (přeplatek i nedoplatek) je splatné nejpozději
 *    do 4 měsíců od doručení vyúčtování,
 *  - § 8: námitky lze podat do 30 dnů od doručení, poskytovatel je vyřídí do 30 dnů
 *    od jejich doručení.
 * Vady vyúčtování ani námitka splatnost přeplatku neposouvají, proto se splatnost
 * počítá jen z doručení. Konec lhůty v měsících připadne na den shodný číslem,
 * v kratším měsíci na jeho poslední den (§ 605 odst. 2 občanského zákoníku).
 */
final class SettlementDeadlines
{
    public const DELIVERY_MONTHS = 4;
    public const SETTLEMENT_MONTHS = 4;
    public const OBJECTION_DAYS = 30;
    public const RESOLUTION_DAYS = 30;

    public static function addMonths(string $date, int $months): string
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', SettlementEvidence::date($date));
        $first = $day->modify('first day of this month')->modify('+'.$months.' months');
        $last = (int)$first->format('t');
        return $first->setDate((int)$first->format('Y'), (int)$first->format('m'), min((int)$day->format('d'), $last))->format('Y-m-d');
    }

    public static function addDays(string $date, int $days): string
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d', SettlementEvidence::date($date))->modify('+'.$days.' days')->format('Y-m-d');
    }

    /** Kalendářní den doručení v Praze z okamžiku uloženého v dokumentaci. */
    public static function pragueDate(string $instant): string
    {
        return (new \DateTimeImmutable($instant))->setTimezone(new \DateTimeZone('Europe/Prague'))->format('Y-m-d');
    }

    /**
     * @param array|null $dossier aktuální stav dokumentace (segmenty, doručení) nebo null
     * @param list<array{id:int,recipient_id:int,received_on:string,resolved_on:?string}> $objections
     */
    public static function evaluate(string $periodTo, ?array $dossier, array $objections, string $today): array
    {
        SettlementEvidence::date($today);
        $deliveryDeadline = self::addMonths($periodTo, self::DELIVERY_MONTHS);
        $deliveries = [];
        $warnings = [];
        $byRecipient = [];
        if ($dossier === null) {
            $warnings[] = ['code' => $today > $deliveryDeadline ? 'delivery_overdue' : 'delivery_unrecorded', 'deadline' => $deliveryDeadline];
        }
        foreach ($dossier['deliveries'] ?? [] as $delivery) {
            $row = [
                'segment_ordinal' => $delivery['segment_ordinal'],
                'recipient_id' => $delivery['recipient_id'],
                'status' => $delivery['status'],
                'delivery_deadline' => $deliveryDeadline,
                'delivered_on' => null,
                'due_date' => $delivery['due']['date'],
                'due_limit' => null,
                'objection_deadline' => null,
                'warnings' => [],
            ];
            if ($delivery['status'] === 'delivered') {
                $delivered = self::pragueDate($delivery['delivered_at']);
                $row['delivered_on'] = $delivered;
                $row['due_limit'] = self::addMonths($delivered, self::SETTLEMENT_MONTHS);
                $row['objection_deadline'] = self::addDays($delivered, self::OBJECTION_DAYS);
                if ($delivered > $deliveryDeadline) $row['warnings'][] = 'delivery_late';
                if ($row['due_date'] === null) $row['warnings'][] = 'due_missing';
                elseif ($row['due_date'] > $row['due_limit']) $row['warnings'][] = 'due_beyond_limit';
                $previous = $byRecipient[$delivery['recipient_id']] ?? null;
                if ($previous === null || $delivered < $previous) $byRecipient[$delivery['recipient_id']] = $delivered;
            } elseif ($today > $deliveryDeadline) {
                $row['warnings'][] = 'delivery_overdue';
            }
            foreach ($row['warnings'] as $code) $warnings[] = ['code' => $code, 'recipient_id' => $row['recipient_id'], 'segment_ordinal' => $row['segment_ordinal']];
            $deliveries[] = $row;
        }
        $items = [];
        foreach ($objections as $objection) {
            $delivered = $byRecipient[$objection['recipient_id']] ?? null;
            $row = $objection + [
                'submission_deadline' => $delivered === null ? null : self::addDays($delivered, self::OBJECTION_DAYS),
                'resolution_deadline' => self::addDays($objection['received_on'], self::RESOLUTION_DAYS),
                'warnings' => [],
            ];
            if ($delivered === null) $row['warnings'][] = 'objection_without_delivery';
            elseif ($objection['received_on'] > $row['submission_deadline']) $row['warnings'][] = 'objection_late';
            if ($objection['resolved_on'] === null && $today > $row['resolution_deadline']) $row['warnings'][] = 'objection_resolution_overdue';
            if ($objection['resolved_on'] !== null && $objection['resolved_on'] > $row['resolution_deadline']) $row['warnings'][] = 'objection_resolved_late';
            foreach ($row['warnings'] as $code) $warnings[] = ['code' => $code, 'recipient_id' => $objection['recipient_id'], 'objection_id' => $objection['id']];
            $items[] = $row;
        }
        return [
            'today' => $today,
            'period_to' => $periodTo,
            'delivery_deadline' => $deliveryDeadline,
            'deliveries' => $deliveries,
            'objections' => $items,
            'warnings' => $warnings,
            'blocking' => false,
        ];
    }
}
