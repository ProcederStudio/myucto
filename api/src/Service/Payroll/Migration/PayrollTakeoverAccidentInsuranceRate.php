<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAccidentInsuranceRateRepository;
use MyInvoice\Service\Payroll\AccidentInsuranceRateSchedule;
use MyInvoice\Service\Payroll\Employment\PayrollRelationType;

/**
 * Sazba zákonného pojištění odpovědnosti zaměstnavatele odvozená z plateb předchozího
 * programu.
 *
 * Sazbu (promile podle převažující činnosti, příloha č. 2 vyhlášky č. 125/1993 Sb.)
 * mzdové programy v exportu nevedou, platby pojišťovně ano. Pojistné za čtvrtletí je
 * sazba × souhrn vyměřovacích základů za čtvrtletí, které platbě předchází (§ 12 odst. 2),
 * takže sazbu jde zpětně spočítat z platby a převzatých základů a zarovnat na sazebník.
 * Zapíše se jen tehdy, když všechny platby se základem vyjdou na tutéž sazbu přílohy
 * (s tolerancí na drobné opravy základů) a firma sazbu ještě nemá; jinak rozhodne účetní.
 */
final class PayrollTakeoverAccidentInsuranceRate
{
    /** Odchylka spočtené sazby od sazby přílohy, kterou převod ještě přijme. */
    private const TOLERANCE = 0.03;

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollAccidentInsuranceRateRepository $rates,
        private readonly AccidentInsuranceRateSchedule $schedule,
    ) {}

    /**
     * @param list<array{code:string,name:string,due_on:string,amount_minor:int}> $payments
     * @return array{code:string,rate:string,payments:int}|null zapsaná sazba, `null` když se nezapsala
     */
    public function apply(int $supplierId, array $payments, int $year, string $source, ?int $userId): ?array
    {
        $from = sprintf('%04d-01-01', $year);
        // Sazbu, kterou firma už má (zadala ji účetní nebo dřívější převod), převod nemění.
        if ($payments === [] || $this->rates->list($supplierId) !== []) {
            return null;
        }
        $code = null;
        $rate = null;
        $used = 0;
        foreach ($payments as $payment) {
            $base = $this->quarterBase($supplierId, $source, self::previousQuarterStart($payment['due_on']));
            if ($base <= 0) {
                continue;
            }
            $matched = $this->annexRate($payment['amount_minor'] * 1000 / $base);
            if ($matched === null || ($rate !== null && $matched !== $rate) || ($code !== null && $code !== $payment['code'])) {
                return null;
            }
            $rate = $matched;
            $code = $payment['code'];
            $used++;
        }
        if ($rate === null || $code === null) {
            return null;
        }
        $this->rates->insert($supplierId, $code, $rate, $from, $userId);

        return ['code' => $code, 'rate' => $rate, 'payments' => $used];
    }

    /** Sazba přílohy nejbližší spočtené, je-li v toleranci. */
    private function annexRate(float $perMille): ?string
    {
        $best = null;
        foreach ($this->schedule->rates() as $rate) {
            $distance = abs((float) $rate - $perMille) / (float) $rate;
            if ($distance <= self::TOLERANCE && ($best === null || $distance < $best[1])) {
                $best = [$rate, $distance];
            }
        }

        return $best[0] ?? null;
    }

    /** Souhrn převzatých vyměřovacích základů čtvrtletí bez vztahů statutárních orgánů a společníků. */
    private function quarterBase(int $supplierId, string $source, string $quarterStart): int
    {
        $end = (new \DateTimeImmutable($quarterStart))->modify('+2 months')->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare(
            'SELECT relation_type, SUM(social_base_minor) AS base
               FROM payroll_migration_reference_totals
              WHERE supplier_id = ? AND source = ? AND period_start BETWEEN ? AND ?
              GROUP BY relation_type'
        );
        $stmt->execute([$supplierId, $source, $quarterStart, $end]);
        $base = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $type = PayrollRelationType::tryFrom((string) ($row['relation_type'] ?? ''));
            if ($type !== null && $type->isCompanyBody()) {
                continue;
            }
            $base += (int) $row['base'];
        }

        return $base;
    }

    private static function previousQuarterStart(string $date): string
    {
        $day = new \DateTimeImmutable(substr($date, 0, 10));
        $quarterStartMonth = intdiv((int) $day->format('n') - 1, 3) * 3 + 1;

        return $day->setDate((int) $day->format('Y'), $quarterStartMonth, 1)->modify('-3 months')->format('Y-m-d');
    }
}
