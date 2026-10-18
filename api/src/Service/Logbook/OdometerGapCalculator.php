<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Km k doplnění: rozdíl mezi zjištěným stavem tachometru (`odometer_readings`)
 * a koncem jízd zapsaných v knize jízd.
 *
 * Pro každé vozidlo se odečty procházejí chronologicky. Odečet R_k je pokrytý do stavu
 * `max(konec jízd s datem ≤ R_k, předchozí odečet, počáteční stav vozidla)`; co nad ním
 * zbývá, je mezera s rozsahem dat od posledního známého stavu do data odečtu. Denní
 * odečty tak dávají rozpad po dnech. Jízdy bez stavu tachometru pokrytí neposunou —
 * kniha jízd o nich ví jen počet km, ne kde na tachometru leží.
 *
 * První odečet vozidla bez jízd a bez počátečního stavu mezeru netvoří: není od čeho
 * počítat, stává se výchozím bodem.
 */
final class OdometerGapCalculator
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array<string,mixed>> mezery všech (nearchivovaných) vozidel firmy
     */
    public function forTenant(int $supplierId, ?int $carId = null): array
    {
        $sql = 'SELECT id, registration, name, odometer_start, odometer_start_date
                  FROM cars WHERE supplier_id = ? AND is_archived = 0';
        $params = [$supplierId];
        if ($carId !== null) {
            $sql .= ' AND id = ?';
            $params[] = $carId;
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY id');
        $stmt->execute($params);
        $cars = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($cars as $car) {
            $id = (int) $car['id'];
            $readings = $this->readings($supplierId, $id);
            if ($readings === []) continue;
            $start = $car['odometer_start'] !== null
                ? ['odometer' => (int) $car['odometer_start'], 'date' => $car['odometer_start_date'] !== null ? (string) $car['odometer_start_date'] : null]
                : null;
            foreach (self::gaps($readings, $this->trips($supplierId, $id), $start) as $gap) {
                $out[] = [
                    'car_id'           => $id,
                    'car_registration' => (string) $car['registration'],
                    'car_name'         => $car['name'] !== null ? (string) $car['name'] : null,
                ] + $gap;
            }
        }
        return $out;
    }

    /** Jedna mezera podle odečtu, který ji uzavírá; null = odečet je pokrytý. */
    public function forReading(int $supplierId, int $carId, int $readingId): ?array
    {
        foreach ($this->forTenant($supplierId, $carId) as $gap) {
            if ($gap['reading_id'] === $readingId) return $gap;
        }
        return null;
    }

    /**
     * @param list<array{id:int, date:string, odometer:int}> $readings  odečty jednoho vozidla
     * @param list<array{date:string, odometer_end:?int}>    $trips     jízdy téhož vozidla
     * @param array{odometer:int, date:?string}|null         $carStart  počáteční stav vozidla
     * @return list<array{reading_id:int, date_from:?string, date_to:string, odometer_from:int, odometer_to:int, km:int}>
     */
    public static function gaps(array $readings, array $trips, ?array $carStart): array
    {
        usort($readings, static fn (array $a, array $b): int => [$a['date'], $a['odometer']] <=> [$b['date'], $b['odometer']]);
        $trips = array_values(array_filter($trips, static fn (array $t): bool => $t['odometer_end'] !== null));
        usort($trips, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        $gaps = [];
        $prev = $carStart;
        foreach ($readings as $reading) {
            $base = $prev;
            foreach ($trips as $trip) {
                if ($trip['date'] > $reading['date']) break;
                if ($base === null || (int) $trip['odometer_end'] >= $base['odometer']) {
                    $base = ['odometer' => (int) $trip['odometer_end'], 'date' => $trip['date']];
                }
            }
            if ($base !== null && $reading['odometer'] > $base['odometer']) {
                $gaps[] = [
                    'reading_id'    => $reading['id'],
                    'date_from'     => $base['date'],
                    'date_to'       => $reading['date'],
                    'odometer_from' => $base['odometer'],
                    'odometer_to'   => $reading['odometer'],
                    'km'            => $reading['odometer'] - $base['odometer'],
                ];
            }
            if ($prev === null || $reading['odometer'] >= $prev['odometer']) {
                $prev = ['odometer' => $reading['odometer'], 'date' => $reading['date']];
            }
        }
        return $gaps;
    }

    /** @return list<array{id:int, date:string, odometer:int}> */
    private function readings(int $supplierId, int $carId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, reading_date, odometer FROM odometer_readings
              WHERE supplier_id = ? AND car_id = ? ORDER BY reading_date, odometer'
        );
        $stmt->execute([$supplierId, $carId]);
        return array_map(static fn (array $r): array => [
            'id'       => (int) $r['id'],
            'date'     => (string) $r['reading_date'],
            'odometer' => (int) $r['odometer'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array{date:string, odometer_end:?int}> */
    private function trips(int $supplierId, int $carId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT trip_date, odometer_end FROM trips
              WHERE supplier_id = ? AND car_id = ? AND odometer_end IS NOT NULL'
        );
        $stmt->execute([$supplierId, $carId]);
        return array_map(static fn (array $r): array => [
            'date'         => (string) $r['trip_date'],
            'odometer_end' => (int) $r['odometer_end'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
