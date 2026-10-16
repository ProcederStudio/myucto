<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

/**
 * Jeden převzatý měsíc jednoho pracovního vztahu, tak jak leží v databázi.
 *
 * Je to ČTECÍ protějšek {@see PayrollMigrationReferenceTotals}: zápisová strana
 * je přísná (validuje, co smí dovnitř), tahle je úplná (nese i to, co se
 * dopočítalo nebo doplnilo později) a nic nepřepočítává. Granularita je
 * PRACOVNÍ VZTAH × MĚSÍC, stejná jako v tabulce — sečíst na osobu umí volající,
 * rozpočítat zpět na vztahy by nešlo.
 *
 * Peníze v haléřích, dny v setinách dne, hodiny v minutách; všechno celá čísla.
 */
final readonly class PayrollTakeoverMonth
{
    public function __construct(
        /** `YYYY-MM`. */
        public string $period,
        /** Jedna z {@see PayrollMigrationReferenceTotalsWriter::SOURCES}. */
        public string $source,
        public string $externalPersonRef,
        public string $externalRelationshipRef,
        /** `null` = osoba se do MyÚčta nepřevedla. */
        public ?int $employeeId,
        /** `null` = vztah se do MyÚčta nepřevedl. */
        public ?int $employmentId,
        public ?string $relationshipStartDate,
        public ?string $relationshipEndDate,
        public ?string $relationType,
        public ?string $activityCode,
        public bool $pensionParticipation,
        public int $insuranceDays,
        public int $excludedDays,
        public int $workedDaysHundredths,
        public int $workedMinutes,
        public int $grossMinor,
        /** Čistá mzda PŘED srážkami. */
        public int $netMinor,
        public int $deductionsMinor,
        /** Čistá mzda PO srážkách, tedy částka k výplatě. */
        public int $netPayableMinor,
        public int $socialBaseMinor,
        public int $healthBaseMinor,
        public int $employeeSocialMinor,
        public int $employeeHealthMinor,
        public int $employerSocialMinor,
        public int $employerHealthMinor,
        public int $advanceTaxMinor,
        public int $withholdingTaxMinor,
        public int $taxBonusMinor,
        public ?string $payoutDate,
        public ?string $importReference,
        /**
         * Vyloučené dny § 18 odst. 7 zák. č. 187/2006 Sb.; `null` = zdroj je
         * nevydal. `$excludedDays` jsou vyloučené DOBY § 16 odst. 4
         * zák. č. 155/1995 Sb. pro důchodové pojištění.
         */
        public ?int $sicknessExcludedDays = null,
        /** Příjem včetně nepojištěné činnosti (JMHZ 10476); `null` = zdroj ho nevydal. */
        public ?int $uninsuredIncomeMinor = null,
        /** Čerpání ročních košů benefitů v převzatém měsíci; `null` = zdroj je nevydal. */
        public ?int $oldAgeSavingsContributionMinor = null,
        public ?int $nonCashHealthBenefitMinor = null,
        public ?int $nonCashLeisureBenefitMinor = null,
    ) {}

    /** @param array<string,mixed> $row řádek `payroll_migration_reference_totals` */
    public static function fromRow(array $row): self
    {
        return new self(
            substr(self::text($row, 'period') ?? self::text($row, 'period_start') ?? '', 0, 7),
            self::text($row, 'source') ?? 'other',
            self::text($row, 'external_person_ref') ?? '',
            self::text($row, 'external_relationship_ref') ?? '',
            self::positiveInt($row, 'employee_id'),
            self::positiveInt($row, 'employment_id'),
            self::text($row, 'relationship_start_date'),
            self::text($row, 'relationship_end_date'),
            self::text($row, 'relation_type'),
            self::text($row, 'activity_code'),
            self::int($row, 'pension_participation') !== 0,
            self::int($row, 'insurance_days'),
            self::int($row, 'excluded_days'),
            self::int($row, 'worked_days_hundredths'),
            self::int($row, 'worked_minutes'),
            self::int($row, 'gross_minor'),
            self::int($row, 'net_minor'),
            self::int($row, 'deductions_minor'),
            self::int($row, 'net_payable_minor'),
            self::int($row, 'social_base_minor'),
            self::int($row, 'health_base_minor'),
            self::int($row, 'employee_social_minor'),
            self::int($row, 'employee_health_minor'),
            self::int($row, 'employer_social_minor'),
            self::int($row, 'employer_health_minor'),
            self::int($row, 'advance_tax_minor'),
            self::int($row, 'withholding_tax_minor'),
            self::int($row, 'tax_bonus_minor'),
            self::text($row, 'payout_date'),
            self::text($row, 'import_reference'),
            self::nullableInt($row, 'sickness_excluded_days'),
            self::nullableInt($row, 'uninsured_income_minor'),
            self::nullableInt($row, 'old_age_savings_contribution_minor'),
            self::nullableInt($row, 'non_cash_health_benefit_minor'),
            self::nullableInt($row, 'non_cash_leisure_benefit_minor'),
        );
    }

    /**
     * Kód sekce evidenčního listu, tedy druh činnosti plus `++`.
     *
     * `null`, když druh činnosti není 1–9: `EldpAnnualStatementBuilder` jiný
     * kód neumí doložit a vrátit něco poskládaného by znamenalo vyrobit
     * nedoložený údaj v zákonném tiskopise.
     */
    public function eldpCode(): ?string
    {
        return $this->activityCode !== null
            && preg_match('/^[1-9]$/D', $this->activityCode) === 1
                ? $this->activityCode . '++'
                : null;
    }

    /** Poslední den mzdového měsíce, `YYYY-MM-DD`. */
    public function periodEnd(): string
    {
        return (new \DateTimeImmutable($this->period . '-01'))
            ->modify('last day of this month')->format('Y-m-d');
    }

    /**
     * Doba pojištění v měsíci jako `[od, do]`, oříznutá trváním vztahu.
     *
     * `null`, když vztah v měsíci netrval nebo nejsou známá jeho data — pak
     * interval nejde doložit a navazující sestavení ho musí označit jako
     * chybějící podklad, ne dopočítat.
     *
     * @return array{0:string,1:string}|null
     */
    public function insuranceSpan(): ?array
    {
        $from = $this->period . '-01';
        $to = $this->periodEnd();
        if ($this->relationshipStartDate !== null) {
            $from = max($from, $this->relationshipStartDate);
        }
        if ($this->relationshipEndDate !== null) {
            $to = min($to, $this->relationshipEndDate);
        }

        return $from <= $to ? [$from, $to] : null;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'source' => $this->source,
            'external_person_ref' => $this->externalPersonRef,
            'external_relationship_ref' => $this->externalRelationshipRef,
            'employee_id' => $this->employeeId,
            'employment_id' => $this->employmentId,
            'relationship_start_date' => $this->relationshipStartDate,
            'relationship_end_date' => $this->relationshipEndDate,
            'relation_type' => $this->relationType,
            'activity_code' => $this->activityCode,
            'eldp_code' => $this->eldpCode(),
            'pension_participation' => $this->pensionParticipation,
            'insurance_days' => $this->insuranceDays,
            'excluded_days' => $this->excludedDays,
            'worked_days_hundredths' => $this->workedDaysHundredths,
            'worked_minutes' => $this->workedMinutes,
            'gross_minor' => $this->grossMinor,
            'net_minor' => $this->netMinor,
            'deductions_minor' => $this->deductionsMinor,
            'net_payable_minor' => $this->netPayableMinor,
            'social_base_minor' => $this->socialBaseMinor,
            'health_base_minor' => $this->healthBaseMinor,
            'employee_social_minor' => $this->employeeSocialMinor,
            'employee_health_minor' => $this->employeeHealthMinor,
            'employer_social_minor' => $this->employerSocialMinor,
            'employer_health_minor' => $this->employerHealthMinor,
            'advance_tax_minor' => $this->advanceTaxMinor,
            'withholding_tax_minor' => $this->withholdingTaxMinor,
            'tax_bonus_minor' => $this->taxBonusMinor,
            'payout_date' => $this->payoutDate,
            'import_reference' => $this->importReference,
        ];
    }

    /** @param array<string,mixed> $row */
    private static function text(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /** @param array<string,mixed> $row */
    private static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /** @param array<string,mixed> $row */
    private static function nullableInt(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /** @param array<string,mixed> $row */
    private static function positiveInt(array $row, string $key): ?int
    {
        $value = self::int($row, $key);

        return $value > 0 ? $value : null;
    }
}
