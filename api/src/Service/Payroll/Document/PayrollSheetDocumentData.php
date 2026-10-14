<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

final readonly class PayrollSheetDocumentData
{
    /** § 38ch — zmrazený výsledek ročního zúčtování za rok existuje. */
    public const ANNUAL_SETTLEMENT_APPROVED = 'approved';

    /** Za rok není zmrazený výsledek — zúčtování se neprovedlo. */
    public const ANNUAL_SETTLEMENT_NOT_PERFORMED = 'not_performed';

    /**
     * Revize vznikla nad mapováním, které stav ročního zúčtování nezjišťovalo
     * a zapisovalo natvrdo „neprovedeno". Zpětně se to nedopočítá — doklad by
     * jinak tvrdil, že se zúčtování neprovedlo, i když se provedlo.
     */
    public const ANNUAL_SETTLEMENT_NOT_RECORDED = 'not_recorded';

    /**
     * Co musí doklad o provedeném zúčtování unést, aby písm. h) splnil.
     *
     * První skupina je „výpočet daně" (§ 16, § 35ba a § 35c v ročním rozměru),
     * druhá „provedené roční zúčtování" (§ 35d odst. 7 a 8, § 38ch odst. 5).
     */
    private const ANNUAL_SETTLEMENT_FIELDS = [
        'revision_id',
        'snapshot_hash',
        'settled_on',
        'completed_months',
        'advance_base_minor_units',
        'rounded_tax_base_minor_units',
        'tax_before_credits_minor_units',
        'annual_credits_minor_units',
        'applied_credits_minor_units',
        'child_entitlement_minor_units',
        'child_credit_minor_units',
        'annual_tax_bonus_minor_units',
        'tax_after_all_credits_minor_units',
        'advance_tax_minor_units',
        'monthly_tax_bonus_minor_units',
        'external_certificate_count',
        'tax_difference_minor_units',
        'bonus_difference_minor_units',
        'settlement_difference_minor_units',
        'payable_minor_units',
        'outcome',
    ];

    /**
     * Popisky sloupců převzaté části. Pořadí určuje pořadí sloupců v dokladu;
     * pole, které tu není, se vypíše pod svým klíčem (viz carriedOverTemplate).
     */
    private const CARRIED_OVER_LABELS = [
        'social_assessment_base_minor_units' => 'Základ SP',
        'health_assessment_base_minor_units' => 'Základ ZP',
        'advance_base_minor_units' => 'Zálohový základ',
        'advance_tax_minor_units' => 'Sražená záloha',
        'applied_non_refundable_credits_minor_units' => 'Uplatněná sleva § 35ba',
        'applied_child_credit_minor_units' => 'Uplatněná sleva § 35c',
        'tax_bonus_minor_units' => 'Daňový bonus',
        'bonus_qualifying_income_minor_units' => 'Příjem pro nárok na bonus',
        'withholding_base_minor_units' => 'Základ srážkové daně',
        'withholding_tax_minor_units' => 'Srážková daň',
    ];

    /**
     * @param list<string> $previousNames
     * @param list<PayrollSheetMonth> $months
     * @param list<array{code:string,relation_type:string,start_date:string,
     *     actual_start_date:?string,end_date:?string}> $employments
     */
    public function __construct(
        public string $sourceSnapshotSha256,
        public int $taxYear,
        public string $employerName,
        public string $employerIdentificationNumber,
        public string $employerAddress,
        public string $employeeName,
        public array $previousNames,
        public string $personalIdentifierLabel,
        public string $personalIdentifierValue,
        public string $employeeAddress,
        public array $months,
        public string $annualSettlementStatus,
        /**
         * § 38j odst. 2 písm. e) — den nástupu do zaměstnání. Bere se ze
         * zmrazených pracovních vztahů zdrojových revizí, ne z dnešní evidence:
         * doklad nesmí zestárnout jinak než přes novou revizi.
         */
        public array $employments = [],
        /**
         * § 38j odst. 2 písm. h) — „údaje o výpočtu daně a provedeném ročním
         * zúčtování záloh a daňového zvýhodnění".
         *
         * Neseče se, četě se: hodnoty jsou zmřazené v revizi ročního zúčtování
         * (`purpose = annual_settlement_result`) a mzdový list je jen přebírá.
         * `null` už neznamená „nula" — stav výše říká, jestli se zúčtování
         * neprovedlo, nebo jestli o něm revize nic neví.
         *
         * @var ?array<string,mixed>
         */
        public ?array $annualSettlement = null,
        /**
         * Doloha § 38ch odst. 1 a 3 zapsaná v evidenci žádosti. Zmřazuje se
         * proto, že „neprovedeno" bez důvodu je prázdné místo, ne údaj.
         *
         * @var ?array<string,string>
         */
        public ?array $annualSettlementEvidence = null,
        /**
         * Převzatá část roku z předchozího mzdového programu, nebo `null`.
         *
         * Do `$months` NEPATŘÍ: měsíční řádek § 38j odst. 2 žádá hrubý příjem,
         * pojistné zaměstnavatele i čistou výplatu, a počáteční stav zákonné
         * kumulace nic z toho nenese. Vmáčknout ho tam s nulami by znamenalo
         * tvrdit, že zaměstnanec v těch měsících nic nedostal.
         *
         * @var ?array<string,mixed>
         */
        public ?array $carriedOver = null,
        /**
         * Posoudila revize, jestli převzatá část roku existuje?
         *
         * `false` u revizí vydaných před mapováním v6 — tam `null` v
         * `$carriedOver` neznamená „nic převzatého není", ale „nikdo se
         * neptal". Stejný důvod jako u ANNUAL_SETTLEMENT_NOT_RECORDED.
         */
        public bool $carriedOverAssessed = false,
        /**
         * Docházka a rozpad složek vlastních měsíců (klíč = měsíc).
         *
         * @var array<int,PayrollFrozenMonthFacts>
         */
        public array $monthDetails = [],
        /**
         * Měsíce zpracované předchozím programem, po měsících; údaj, který
         * zdroj nenese, je `null`.
         *
         * @var list<array<string,int|string|null>>
         */
        public array $takenOverMonths = [],
        /** @var array{birth_date:?string,health_insurer:?string}|array{} */
        public array $employeeExtras = [],
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $sourceSnapshotSha256) !== 1) {
            throw new \InvalidArgumentException('Zdrojový otisk mzdového listu není platný.');
        }
        if ($taxYear < 2000 || $taxYear > 2199) {
            throw new \InvalidArgumentException('Rok mzdového listu není platný.');
        }
        foreach ([
            $employerName,
            $employerIdentificationNumber,
            $employerAddress,
            $employeeName,
            $personalIdentifierLabel,
            $personalIdentifierValue,
            $employeeAddress,
        ] as $text) {
            if (trim($text) === '' || mb_strlen($text) > 500) {
                throw new \InvalidArgumentException('Identifikace mzdového listu není úplná.');
            }
        }
        if (count($previousNames) > 20) {
            throw new \InvalidArgumentException('Dřívější jména nemají platnou strukturu.');
        }
        foreach ($previousNames as $name) {
            if (trim($name) === '' || mb_strlen($name) > 191) {
                throw new \InvalidArgumentException('Dřívější jméno není platné.');
            }
        }
        if ($months === [] && $takenOverMonths === []) {
            throw new \InvalidArgumentException('Mzdový list nemá žádný schválený ani převzatý měsíc.');
        }
        $seen = [];
        foreach ($months as $month) {
            if (isset($seen[$month->month])) {
                throw new \InvalidArgumentException('Měsíce mzdového listu nejsou jednoznačné.');
            }
            $seen[$month->month] = true;
        }
        $takenSeen = [];
        foreach ($takenOverMonths as $row) {
            $month = $row['month'] ?? null;
            if (!is_int($month) || $month < 1 || $month > 12 || isset($seen[$month]) || isset($takenSeen[$month])) {
                // Převzatý měsíc, který MyÚčto zároveň spočítalo, by se
                // započetl dvakrát.
                throw new \InvalidArgumentException('Převzatý měsíc mzdového listu není jednoznačný.');
            }
            $takenSeen[$month] = true;
        }
        foreach ($monthDetails as $month => $facts) {
            if (!isset($seen[$month]) || !$facts instanceof PayrollFrozenMonthFacts) {
                throw new \InvalidArgumentException('Měsíční údaje patří jen k vlastním měsícům.');
            }
        }
        if (!in_array($annualSettlementStatus, [
            self::ANNUAL_SETTLEMENT_NOT_RECORDED,
            self::ANNUAL_SETTLEMENT_NOT_PERFORMED,
            self::ANNUAL_SETTLEMENT_APPROVED,
        ], true)) {
            throw new \InvalidArgumentException('Stav ročního zúčtování není platný.');
        }
        if (($annualSettlementStatus === self::ANNUAL_SETTLEMENT_APPROVED)
            !== ($annualSettlement !== null)
        ) {
            // Provedené zúčtování bez údajů o výpočtu by písm. h) nesplnilo
            // a údaje bez provedeného zúčtování by tvrdily úkon, který nenastal.
            throw new \InvalidArgumentException(
                'Stav ročního zúčtování nesouhlasí s údaji o jeho výpočtu.',
            );
        }
        if ($annualSettlement !== null) {
            foreach (self::ANNUAL_SETTLEMENT_FIELDS as $field) {
                if (!array_key_exists($field, $annualSettlement)) {
                    throw new \InvalidArgumentException(
                        "Údaje o ročním zúčtování neobsahují {$field}.",
                    );
                }
            }
        }
        if (count($employments) > 200) {
            throw new \InvalidArgumentException('Pracovní vztahy mzdového listu nemají platnou strukturu.');
        }
        foreach ($employments as $employment) {
            foreach (['code', 'relation_type', 'start_date'] as $field) {
                $value = $employment[$field] ?? null;
                if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 191) {
                    throw new \InvalidArgumentException('Pracovní vztah mzdového listu není úplný.');
                }
            }
            foreach (['actual_start_date', 'end_date'] as $field) {
                $value = $employment[$field] ?? null;
                if ($value !== null && (!is_string($value) || mb_strlen($value) > 191)) {
                    throw new \InvalidArgumentException('Pracovní vztah mzdového listu není úplný.');
                }
            }
        }
        if ($carriedOver !== null) {
            if (!$carriedOverAssessed) {
                throw new \InvalidArgumentException(
                    'Převzatou část roku nese jen revize, která ji posoudila.',
                );
            }
            $carriedMonths = $carriedOver['months'] ?? null;
            if (!is_array($carriedMonths)
                || !array_is_list($carriedMonths)
                || $carriedMonths === []
            ) {
                throw new \InvalidArgumentException(
                    'Převzatá část roku nemá seznam měsíců.',
                );
            }
            foreach ($carriedMonths as $month) {
                if (!is_int($month)
                    || $month < 1
                    || $month > 12
                    || isset($seen[$month])
                ) {
                    // Překryv s vlastním měsícem by tentýž měsíc uvedl dvakrát.
                    throw new \InvalidArgumentException(
                        'Převzatý měsíc není platný nebo se kryje s měsícem '
                        . 'vlastního výpočtu.',
                    );
                }
            }
            if (!is_array($carriedOver['month_rows'] ?? null)
                || !is_string($carriedOver['source_reference'] ?? null)
            ) {
                throw new \InvalidArgumentException(
                    'Převzatá část roku nemá rozpis měsíců a popis zdroje.',
                );
            }
        }
    }

    /**
     * Nese doklad údaje § 38j odst. 2 písm. f) bodů 2 a 3 za VŠECHNY měsíce?
     *
     * Roční součet se smí uvést jen tehdy — dílčí součet přes měsíce, které
     * údaj neevidují, by tvrdil nižší číslo, než jaké ve skutečnosti platí.
     */
    public function taxDetailComplete(): bool
    {
        foreach ($this->months as $month) {
            if (!$month->taxDetailRecorded()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Nese doklad měsíční daňové zvýhodnění (§ 38j odst. 2 písm. f) bod 6)
     * za VŠECHNY měsíce? Roční součet přes měsíce, které nárok neevidovaly,
     * by tvrdil nižší číslo, než jaké ve skutečnosti platilo.
     */
    public function childDetailComplete(): bool
    {
        foreach ($this->months as $month) {
            if (!$month->childDetailRecorded()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Nese doklad UPLATNĚNOU slevu podle § 35ba (§ 38j odst. 2 písm. f) bod 5)
     * za VŠECHNY měsíce? Starší revize do téhle kolonky zapsaly nárok, který
     * u nízké mzdy zálohu převyšuje — součet přes obojí by nebyl ani nárok,
     * ani poskytnutá sleva.
     */
    public function creditDetailComplete(): bool
    {
        foreach ($this->months as $month) {
            if (!$month->creditDetailApplied()) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,int> */
    public function totals(): array
    {
        $totals = ['source_revision_count' => 0];
        foreach ($this->months as $month) {
            $totals['source_revision_count'] = $this->add(
                $totals['source_revision_count'],
                $month->sourceRevisionCount,
            );
            foreach ($month->amounts() as $key => $amount) {
                $totals[$key] = $this->add($totals[$key] ?? 0, $amount);
            }
        }
        return $totals;
    }

    /** @return array<string,mixed> */
    public function toTemplateData(): array
    {
        return [
            'tax_year' => $this->taxYear,
            'source_snapshot_sha256' => $this->sourceSnapshotSha256,
            'employer' => [
                'name' => $this->employerName,
                'identification_number' => $this->employerIdentificationNumber,
                'address' => $this->employerAddress,
            ],
            'employee' => [
                'name' => $this->employeeName,
                'previous_names' => $this->previousNames,
                'identifier_label' => $this->personalIdentifierLabel,
                'identifier_value' => $this->personalIdentifierValue,
                'address' => $this->employeeAddress,
            ],
            'months' => array_map(
                static fn (PayrollSheetMonth $month): array => $month->toTemplateData(),
                $this->months,
            ),
            'totals' => $this->totals(),
            'annual_settlement_status' => $this->annualSettlementStatus,
            'annual_settlement' => $this->annualSettlement,
            'annual_settlement_evidence' => $this->annualSettlementEvidence,
            'child_detail_complete' => $this->childDetailComplete(),
            'credit_detail_complete' => $this->creditDetailComplete(),
            'employments' => $this->employments,
            'tax_detail_complete' => $this->taxDetailComplete(),
            'carried_over' => $this->carriedOverTemplate(),
            'carried_over_assessed' => $this->carriedOverAssessed,
            'employee_extras' => [
                'birth_date' => $this->employeeExtras['birth_date'] ?? null,
                'health_insurer' => $this->employeeExtras['health_insurer'] ?? null,
            ],
            'period_label' => $this->periodLabel(),
            'taken_over_month_numbers' => array_map(
                static fn (array $row): int => (int) $row['month'],
                $this->takenOverMonths,
            ),
            'matrix' => PayrollSheetMatrix::build($this),
        ];
    }

    /** Období dokladu „01/2026 - 09/2026" přes vlastní i převzaté měsíce. */
    public function periodLabel(): string
    {
        $all = [
            ...array_map(static fn (PayrollSheetMonth $month): int => $month->month, $this->months),
            ...array_map(static fn (array $row): int => (int) $row['month'], $this->takenOverMonths),
        ];
        if ($all === []) {
            return '';
        }

        return sprintf('%02d/%d - %02d/%d', min($all), $this->taxYear, max($all), $this->taxYear);
    }

    /**
     * Převzatá část roku pro šablonu: rozpis po měsících, úhrn a zdroj.
     *
     * Sloupce se ODVOZUJÍ z toho, co v počátečním stavu skutečně je, ne
     * z pevného seznamu. Až průvodce počátečních stavů začne zapisovat
     * zdravotní pojištění (`calculation_kind = 'health_insurance'`), projeví se
     * v dokladu samo; pole bez popisku se ukáže pod svým klíčem, což je
     * viditelná pobídka popisek doplnit — na rozdíl od tichého zahození.
     *
     * @return ?array{
     *   months:list<int>,
     *   months_label:string,
     *   source_reference:string,
     *   columns:list<array{field:string,label:string}>,
     *   rows:list<array{month:int,amounts:array<string,int>}>,
     *   totals:array<string,int>
     * }
     */
    private function carriedOverTemplate(): ?array
    {
        if ($this->carriedOver === null) {
            return null;
        }
        /** @var list<int> $months */
        $months = $this->carriedOver['months'];
        $rows = [];
        $totals = [];
        $fields = [];
        foreach ($this->carriedOver['month_rows'] as $row) {
            if (!is_array($row) || !is_int($row['month'] ?? null)) {
                throw new \InvalidArgumentException(
                    'Rozpis převzatého měsíce není platný.',
                );
            }
            $amounts = [];
            foreach ($row as $field => $amount) {
                if ($field === 'month') {
                    continue;
                }
                if (!is_string($field) || !is_int($amount) || $amount < 0) {
                    throw new \InvalidArgumentException(
                        'Částka v rozpisu převzatého měsíce není platná.',
                    );
                }
                $amounts[$field] = $amount;
                $fields[$field] = true;
                $totals[$field] = $this->add($totals[$field] ?? 0, $amount);
            }
            $rows[] = ['month' => $row['month'], 'amounts' => $amounts];
        }
        $columns = [];
        foreach (array_keys(self::CARRIED_OVER_LABELS) as $field) {
            if (isset($fields[$field])) {
                $columns[] = [
                    'field' => $field,
                    'label' => self::CARRIED_OVER_LABELS[$field],
                ];
                unset($fields[$field]);
            }
        }
        $remaining = array_keys($fields);
        sort($remaining, SORT_STRING);
        foreach ($remaining as $field) {
            $columns[] = ['field' => $field, 'label' => $field];
        }

        return [
            'months' => $months,
            'months_label' => is_string($this->carriedOver['months_label'] ?? null)
                && $this->carriedOver['months_label'] !== ''
                    ? $this->carriedOver['months_label']
                    : implode(', ', array_map(strval(...), $months)),
            'source_reference' => (string) $this->carriedOver['source_reference'],
            'columns' => $columns,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    private function add(int $left, int $right): int
    {
        if ($right > 0 && $left > PHP_INT_MAX - $right) {
            throw new \OverflowException('Roční součet mzdového listu přetekl.');
        }
        return $left + $right;
    }
}
