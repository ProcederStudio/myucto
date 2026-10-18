<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TripRepository;

/**
 * Doplní km k doplnění ({@see OdometerGapCalculator}) jízdami.
 *
 * Jízdy navazují na tachometru v pořadí podle data a času odjezdu: první začíná na stavu,
 * kde mezera začíná, každá další na konci předchozí. Stav tachometru je v celých km, ujeté
 * km s desetinou; konce se proto zaokrouhlují z kumulativního součtu, aby se chyba
 * nesčítala. Zbytek mezery může dorovnat soukromá jízda, poslední jízda pak skončí přesně
 * na odečtu. Bez ní je doplnění částečné a zbytek zůstane k doplnění.
 */
final class OdometerGapFiller
{
    public function __construct(
        private readonly Connection $db,
        private readonly TripRepository $trips,
    ) {}

    /**
     * @param array<string,mixed>       $gap   mezera z OdometerGapCalculator
     * @param list<array<string,mixed>> $rows  jízdy (trip_date, distance_km, category_id, purpose, origin, destination, time_start, time_end)
     * @return list<int> id vytvořených jízd
     */
    public function fill(int $supplierId, array $gap, array $rows, ?string $remainderPurpose, ?int $userId): array
    {
        $carId = (int) $gap['car_id'];
        $from = (int) $gap['odometer_from'];
        $to = (int) $gap['odometer_to'];

        $trips = [];
        $sum = 0.0;
        foreach ($rows as $row) {
            $distance = round((float) $row['distance_km'], 1);
            $sum += $distance;
            $trips[] = [
                'car_id'      => $carId,
                'trip_date'   => $row['trip_date'],
                'time_start'  => $row['time_start'] ?? null,
                'time_end'    => $row['time_end'] ?? null,
                'distance_km' => $distance,
                'category_id' => $row['category_id'] ?? null,
                'purpose'     => $row['purpose'] ?? null,
                'origin'      => $row['origin'] ?? null,
                'destination' => $row['destination'] ?? null,
            ];
        }
        $remainder = round(($to - $from) - $sum, 1);
        if ($remainderPurpose !== null && $remainder > 0) {
            $trips[] = [
                'car_id'      => $carId,
                'trip_date'   => $gap['date_to'],
                'distance_km' => $remainder,
                'category_id' => $this->privateCategoryId($supplierId),
                'purpose'     => $remainderPurpose,
            ];
        }
        // Řetěz tachometru musí jít v pořadí, v jakém jízdy řadí přepočet tachometru
        // (TripRepository::recalculateOdometer) i kontrola návaznosti v souhrnu
        // (LogbookSummaryService::continuityDetail): datum, čas odjezdu (bez času dřív), id.
        // Jinak by jízda bez času, třeba soukromý zbytek, vyšla jako překryv.
        usort($trips, static fn (array $a, array $b): int =>
            [$a['trip_date'], (string) ($a['time_start'] ?? '')] <=> [$b['trip_date'], (string) ($b['time_start'] ?? '')]);

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $ids = [];
            $cumulative = 0.0;
            $end = $from;
            foreach ($trips as $trip) {
                $cumulative += $trip['distance_km'];
                $start = $end;
                $end = min($to, $from + (int) round($cumulative));
                $ids[] = $this->trips->create($supplierId, $trip + [
                    'odometer_start' => $start,
                    'odometer_end'   => $end,
                ], $userId);
            }
            if ($ownTx) $pdo->commit();
            return $ids;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Kategorie pro soukromé jízdy: první nearchivovaná s příznakem soukromá. */
    public function privateCategoryId(int $supplierId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM trip_categories
              WHERE supplier_id = ? AND is_private = 1 AND is_archived = 0
              ORDER BY is_default DESC, display_order ASC, id ASC LIMIT 1'
        );
        $stmt->execute([$supplierId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }
}
