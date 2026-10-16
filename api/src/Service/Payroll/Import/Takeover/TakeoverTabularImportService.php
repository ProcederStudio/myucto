<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Takeover;

use MyInvoice\Service\Payroll\Component\PayrollInputTabularParser;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportLookup;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotals;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotalsWriter;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationTakeoverFacts;

/**
 * Převzaté mzdy z tabulky libovolného mzdového systému (CSV/XLSX).
 *
 * ── Proč obecný import ──────────────────────────────────────────────────────
 * Dovnitř dosud vedla jediná cesta — převod z PAMICA. Jenže PAMICA je jen první
 * feeder: zákazník přicházející z jiného programu musí umět převzaté mzdy
 * dodat taky, jinak mu za rok přechodu nevznikne evidenční list důchodového
 * pojištění ani zpětná evidence plateb. Proto je zdroj `other` a formát
 * tabulkový: exportovat CSV umí každý mzdový software.
 *
 * ── Validace se neopisuje ───────────────────────────────────────────────────
 * Řádek se skládá do {@see PayrollMigrationReferenceTotals}
 * a {@see PayrollMigrationTakeoverFacts} — tedy do TÝCHŽ objektů, které přijímá
 * {@see PayrollMigrationReferenceTotalsWriter}. Jejich konstruktory jsou jediné
 * místo, kde je řečeno, co je platný převzatý měsíc; náhled i zápis se proto
 * nemohou ptát jinak. Import přidává jen to, co objekty vědět nemohou: že
 * zaměstnanec a jeho vztah v téhle firmě existují.
 *
 * ── Co se NEOPISUJE ze souboru ──────────────────────────────────────────────
 * Trvání a druh pracovního vztahu se berou z `payroll_employments`, ne ze
 * souboru. Evidenční list blokuje, když se trvání vztahu mezi měsíci liší,
 * takže dvanáct ručně přepsaných dvojic dat je dvanáct příležitostí ten list
 * zablokovat — kdežto z evidence vyjde pokaždé stejné. Identita osoby
 * v původním systému se odvozuje z `employee_id`; identita vztahu jde přebít
 * volitelným sloupcem, když si ji zákazník chce zachovat.
 *
 * ── Lidské sloupce ──────────────────────────────────────────────────────────
 * Vzorový soubor identifikuje osobu JMÉNEM a označením vztahu (tak, jak je
 * vidí na kartě) a částky jsou v KORUNÁCH (`12 345,50`). Interní `employee_id`
 * a částky v haléřích (`*_minor`) import přijímá dál, aby fungovaly exporty
 * připravené podle dřívějšího vzoru — v jednom souboru jde použít jen jedno
 * z obojího, jinak by se nedalo poznat, které číslo platí.
 *
 * ── Jednotky ────────────────────────────────────────────────────────────────
 * Částky v korunách s nejvýše dvěma desetinnými místy (nebo v celých haléřích
 * ve sloupcích `*_minor`), dny a hodiny desetinně s maximálně dvěma místy
 * (`21,5` i `21.5`) — půlden je u odpracované doby běžný a vynucovat setiny by
 * uživatele nutilo počítat.
 */
final class TakeoverTabularImportService
{
    /** Identifikace řádku ve vzorovém souboru. */
    private const KEY_COLUMNS = ['employee_name', 'employment_code', 'period'];

    /** Sloupce, které musí mít každý soubor bez ohledu na variantu. */
    private const REQUIRED_COLUMNS = [
        'employment_code',
        'period',
        'pension_participation',
        'insurance_days',
        'excluded_days',
        'worked_days',
        'payout_date',
    ];

    /** Doby a účast na pojištění (ELDP). */
    private const DURATION_COLUMNS = [
        'pension_participation',
        'insurance_days',
        'excluded_days',
        'worked_days',
    ];

    /** Částky v celých haléřích. */
    private const AMOUNT_COLUMNS = [
        'gross_minor',
        'net_minor',
        'deductions_minor',
        'net_payable_minor',
        'social_base_minor',
        'health_base_minor',
        'employee_social_minor',
        'employee_health_minor',
        'employer_social_minor',
        'employer_health_minor',
        'advance_tax_minor',
        'withholding_tax_minor',
        'tax_bonus_minor',
    ];

    /**
     * Sloupce, které soubor mít nemusí.
     *
     * Parser má strop 32 sloupců (ochrana proti rozjetému exportu), takže jich
     * smí přibýt nejvýš tolik, kolik do stropu zbývá; vzorový soubor je
     * vypisuje všechny.
     */
    private const OPTIONAL_COLUMNS = ['worked_hours', 'activity_code', 'external_relationship_ref'];

    /**
     * Nepovinné čerpání ročních košů benefitů (§ 6 odst. 9 písm. d) a m) ZDP) v převzatém
     * měsíci; chybějící sloupec = zdroj údaj nenese (NULL), ne nula.
     */
    private const BENEFIT_COLUMNS = [
        'old_age_savings_minor',
        'non_cash_health_benefit_minor',
        'non_cash_leisure_benefit_minor',
    ];

    public function __construct(
        private readonly PayrollInputTabularParser $parser,
        private readonly PayrollMigrationReferenceTotalsWriter $writer,
        private readonly RegistrationImportLookup $lookup,
    ) {}

    /** @return list<string> sloupce, které musí mít každý soubor */
    public static function requiredColumns(): array
    {
        return self::REQUIRED_COLUMNS;
    }

    /**
     * Sloupec částky v korunách k haléřovému (`gross_minor` → `gross`).
     */
    public static function crownColumn(string $minorColumn): string
    {
        return substr($minorColumn, 0, -strlen('_minor'));
    }

    /** @return list<string> celá hlavička vzorového souboru (jméno, Kč) včetně nepovinných */
    public static function columns(): array
    {
        return [
            ...self::KEY_COLUMNS,
            ...self::DURATION_COLUMNS,
            ...array_map(self::crownColumn(...), self::AMOUNT_COLUMNS),
            'payout_date',
            ...self::OPTIONAL_COLUMNS,
            ...array_map(self::crownColumn(...), self::BENEFIT_COLUMNS),
        ];
    }

    /**
     * Vzorový soubor se správnou hlavičkou a jedním ukázkovým řádkem.
     *
     * Oddělovač `;` a BOM kvůli Excelu v českém prostředí — jinak Excel rozhodí
     * diakritiku i sloupce. Osoba jménem a označením vztahu, částky v korunách:
     * tak je účetní opíše ze sestavy předchozího programu bez přepočtu.
     */
    public static function template(): string
    {
        $columns = self::columns();
        $example = [
            'employee_name' => 'Jana Vzorová',
            'employment_code' => 'HPP-001',
            'period' => sprintf('%04d-01', (int) date('Y')),
            'pension_participation' => '1',
            'insurance_days' => '31',
            'excluded_days' => '0',
            'worked_days' => '21',
            'worked_hours' => '168',
            'gross' => '40000',
            'payout_date' => sprintf('%04d-02-10', (int) date('Y')),
            'activity_code' => '1',
            'external_relationship_ref' => '',
        ];
        $row = [];
        foreach ($columns as $column) {
            $row[] = $example[$column] ?? '0';
        }

        return "\xEF\xBB\xBF" . implode(';', $columns) . "\r\n" . implode(';', $row) . "\r\n";
    }

    /**
     * Náhled — nic nezapisuje.
     *
     * @return array{
     *   format:string,source:string,source_name:string,row_count:int,
     *   errors:list<array{row_number:int,error_code:string,field_name:?string,error_message:string}>,
     *   periods:list<array<string,mixed>>,
     *   people:list<array<string,mixed>>
     * }
     */
    public function preview(
        int $supplierId,
        string $source,
        string $format,
        string $sourceName,
        string $content,
    ): array {
        $prepared = $this->prepare($supplierId, $source, $format, $sourceName, $content);

        $periods = [];
        $people = [];
        foreach ($prepared['totals'] as $total) {
            $periods[$total->period] ??= ['period' => $total->period, 'row_count' => 0, 'gross_minor' => 0];
            $periods[$total->period]['row_count']++;
            $periods[$total->period]['gross_minor'] += $total->grossMinor;

            $key = (string) $total->employeeId;
            $people[$key] ??= [
                'employee_id' => $total->employeeId,
                'employee_name' => $prepared['names'][$key] ?? ('Osoba #' . $key),
                'month_count' => 0,
                'gross_minor' => 0,
                'net_payable_minor' => 0,
            ];
            $people[$key]['month_count']++;
            $people[$key]['gross_minor'] += $total->grossMinor;
            $people[$key]['net_payable_minor'] += $total->facts->netPayableMinor;
        }
        ksort($periods, SORT_STRING);
        usort($people, static fn (array $left, array $right): int
            => [$left['employee_name'], $left['employee_id']]
            <=> [$right['employee_name'], $right['employee_id']]);

        return [
            'format' => $prepared['format'],
            'source' => $prepared['source'],
            'source_name' => $prepared['source_name'],
            'row_count' => $prepared['row_count'],
            'errors' => $prepared['errors'],
            'periods' => array_values($periods),
            'people' => $people,
        ];
    }

    /**
     * Zapíše soubor, nebo neuloží nic.
     *
     * Vadný řádek shodí CELÝ soubor. Převzaté mzdy jsou souvislá řada měsíců
     * a částečně zapsaná řada by tiše zkreslila jak kontrolní sestavu, tak
     * dobu pojištění v evidenčním listu — a nikdo by nepoznal, že tam měsíc
     * chybí kvůli přeskočenému řádku, ne kvůli nepřítomnosti v původním systému.
     *
     * Opakovaný import týchž dat nic nezdvojí: zapisovač má UNIQUE na
     * (firma, zdroj, období, identita vztahu) a řádek přepíše.
     *
     * @return array{format:string,source:string,source_name:string,written:int,
     *   employee_count:int,periods:list<string>}
     */
    public function apply(
        int $supplierId,
        string $source,
        string $format,
        string $sourceName,
        string $content,
    ): array {
        $prepared = $this->prepare($supplierId, $source, $format, $sourceName, $content);
        if ($prepared['errors'] !== []) {
            $first = $prepared['errors'][0];
            throw new \InvalidArgumentException(sprintf(
                'Soubor obsahuje %d vadných řádků a nezapisuje se. První vada (řádek %d): %s',
                count($prepared['errors']),
                $first['row_number'],
                $first['error_message'],
            ));
        }
        if ($prepared['totals'] === []) {
            throw new \InvalidArgumentException('Soubor neobsahuje žádný převzatý měsíc.');
        }

        $written = $this->writer->store(
            $supplierId,
            $prepared['source'],
            $prepared['totals'],
            'takeover-import:' . $prepared['source_name'],
        );

        $periods = [];
        $employees = [];
        foreach ($prepared['totals'] as $total) {
            $periods[$total->period] = true;
            $employees[(string) $total->employeeId] = true;
        }
        ksort($periods, SORT_STRING);

        return [
            'format' => $prepared['format'],
            'source' => $prepared['source'],
            'source_name' => $prepared['source_name'],
            'written' => $written,
            'employee_count' => count($employees),
            'periods' => array_keys($periods),
        ];
    }

    /**
     * Rozparsuje, ověří a složí řádky. Společný krok náhledu i zápisu, aby se
     * nemohly zeptat jinak.
     *
     * @return array{format:string,source:string,source_name:string,row_count:int,
     *   errors:list<array{row_number:int,error_code:string,field_name:?string,error_message:string}>,
     *   totals:list<PayrollMigrationReferenceTotals>,
     *   names:array<string,string>}
     */
    private function prepare(
        int $supplierId,
        string $source,
        string $format,
        string $sourceName,
        string $content,
    ): array {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Firma musí být zvolená.');
        }
        $source = self::source($source);
        $format = self::format($format);
        $sourceName = self::sourceName($sourceName);
        $parsed = $this->parser->parse($format, $content, self::requiredColumns(), decimals: true);

        $errors = $parsed['errors'];
        $totals = [];
        $names = [];
        $seen = [];
        foreach ($parsed['rows'] as $raw) {
            $rowNumber = is_int($raw['row_number'] ?? null) ? $raw['row_number'] : 0;
            try {
                [$total, $name] = $this->buildRow($supplierId, $raw);
            } catch (\InvalidArgumentException $e) {
                $errors[] = [
                    'row_number' => $rowNumber,
                    'error_code' => 'row_validation_failed',
                    'field_name' => null,
                    'error_message' => $e->getMessage(),
                ];
                continue;
            }
            $key = $total->period . '|' . $total->externalRelationshipRef;
            if (isset($seen[$key])) {
                $errors[] = [
                    'row_number' => $rowNumber,
                    'error_code' => 'duplicate_month',
                    'field_name' => 'period',
                    'error_message' => sprintf(
                        'Období %s je u téhož pracovního vztahu v souboru dvakrát (poprvé na řádku %d).',
                        $total->period,
                        $seen[$key],
                    ),
                ];
                continue;
            }
            $seen[$key] = $rowNumber;
            $totals[] = $total;
            $names[(string) $total->employeeId] = $name;
        }

        return [
            'format' => $format,
            'source' => $source,
            'source_name' => $sourceName,
            'row_count' => count($parsed['rows']) + count($parsed['errors']),
            'errors' => $errors,
            'totals' => $totals,
            'names' => $names,
        ];
    }

    /**
     * @param array<string,string|int> $raw
     * @return array{0:PayrollMigrationReferenceTotals,1:string}
     */
    private function buildRow(int $supplierId, array $raw): array
    {
        $code = trim((string) ($raw['employment_code'] ?? ''));
        if ($code === '') {
            throw new \InvalidArgumentException('Sloupec employment_code je prázdný.');
        }
        $employeeId = $this->employeeId($supplierId, $raw, $code);
        $employments = $this->lookup->employments($supplierId, $employeeId);
        if ($employments === []) {
            throw new \InvalidArgumentException(
                "Zaměstnanec #{$employeeId} v této firmě neexistuje nebo nemá žádný pracovní vztah.",
            );
        }
        $employment = null;
        foreach ($employments as $candidate) {
            if ($candidate['code'] === $code) {
                $employment = $candidate;
                break;
            }
        }
        if ($employment === null) {
            // Párovací dvojice: samotné id se dá přepsat omylem a měsíc by se
            // tiše zapsal jinému člověku.
            throw new \InvalidArgumentException(sprintf(
                'Označení vztahu „%s" neodpovídá zaměstnanci #%d.',
                $code,
                $employeeId,
            ));
        }

        $period = self::period($raw);
        $facts = new PayrollMigrationTakeoverFacts(
            relationshipStartDate: $employment['actual_start_date'] ?? $employment['start_date'],
            relationshipEndDate: $employment['end_date'],
            relationType: $employment['relation_type'],
            activityCode: self::optionalText($raw, 'activity_code'),
            pensionParticipation: self::flag($raw, 'pension_participation'),
            insuranceDays: self::wholeNumber($raw, 'insurance_days'),
            excludedDays: self::wholeNumber($raw, 'excluded_days'),
            workedDaysHundredths: self::decimal($raw, 'worked_days', 100),
            workedMinutes: self::decimal($raw, 'worked_hours', 60),
            deductionsMinor: self::amount($raw, 'deductions_minor'),
            netPayableMinor: self::amount($raw, 'net_payable_minor'),
            payoutDate: self::optionalDate($raw, 'payout_date'),
            oldAgeSavingsContributionMinor: self::optionalAmount($raw, 'old_age_savings_minor'),
            nonCashHealthBenefitMinor: self::optionalAmount($raw, 'non_cash_health_benefit_minor'),
            nonCashLeisureBenefitMinor: self::optionalAmount($raw, 'non_cash_leisure_benefit_minor'),
        );

        $relationshipRef = self::optionalText($raw, 'external_relationship_ref')
            ?? ('employment:' . $employment['id']);

        return [
            new PayrollMigrationReferenceTotals(
                $period,
                'employee:' . $employeeId,
                $relationshipRef,
                $employeeId,
                (int) $employment['id'],
                self::amount($raw, 'gross_minor'),
                self::amount($raw, 'net_minor'),
                self::amount($raw, 'social_base_minor'),
                self::amount($raw, 'health_base_minor'),
                self::amount($raw, 'employee_social_minor'),
                self::amount($raw, 'employee_health_minor'),
                self::amount($raw, 'employer_social_minor'),
                self::amount($raw, 'employer_health_minor'),
                self::amount($raw, 'advance_tax_minor'),
                self::amount($raw, 'withholding_tax_minor'),
                self::amount($raw, 'tax_bonus_minor'),
                $facts,
            ),
            $this->lookup->employeeName($supplierId, $employeeId) ?? ('Osoba #' . $employeeId),
        ];
    }

    /** @param array<string,string|int> $raw */
    private static function period(array $raw): string
    {
        $value = trim((string) ($raw['period'] ?? ''));
        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Sloupec period musí být mzdové období ve tvaru YYYY-MM.');
        }
        $year = (int) substr($value, 0, 4);
        if ($year < 2000 || $year > 2200) {
            throw new \InvalidArgumentException('Rok převzaté mzdy není platný.');
        }

        return $value;
    }

    /**
     * Osoba řádku: jménem a označením vztahu (vzorový soubor), nebo interním
     * `employee_id` (starší vzor). Obojí je párovací dvojice s označením vztahu —
     * samotné jméno ani samotné id by se dalo splést a měsíc by se zapsal jinému.
     *
     * @param array<string,string|int> $raw
     */
    private function employeeId(int $supplierId, array $raw, string $code): int
    {
        $id = trim((string) ($raw['employee_id'] ?? ''));
        $name = trim((string) ($raw['employee_name'] ?? ''));
        if ($id !== '') {
            return self::positiveInt($raw, 'employee_id');
        }
        if ($name === '') {
            throw new \InvalidArgumentException(
                'Řádek nemá jméno zaměstnance (sloupec employee_name) ani jeho číslo (employee_id).',
            );
        }
        $matches = $this->lookup->employeesByNameAndEmploymentCode($supplierId, $name, $code);
        if ($matches === []) {
            throw new \InvalidArgumentException(sprintf(
                'Zaměstnanec „%s" s pracovním vztahem „%s" v této firmě není. Zkontrolujte jméno '
                    . 'a označení vztahu v kartě zaměstnance.',
                $name,
                $code,
            ));
        }
        if (count($matches) > 1) {
            throw new \InvalidArgumentException(sprintf(
                'Jméno „%s" se vztahem „%s" má ve firmě víc zaměstnanců; odlište je sloupcem employee_id.',
                $name,
                $code,
            ));
        }

        return $matches[0];
    }

    /**
     * Nepovinná částka: bez sloupce (v korunách ani haléřích) `null`.
     *
     * @param array<string,string|int> $raw
     */
    private static function optionalAmount(array $raw, string $column): ?int
    {
        if (!array_key_exists(self::crownColumn($column), $raw) && !array_key_exists($column, $raw)) {
            return null;
        }
        $value = self::amount($raw, $column);
        if ($value < 0) {
            throw new \InvalidArgumentException(sprintf('Sloupec „%s“ nesmí být záporný.', self::crownColumn($column)));
        }

        return $value;
    }

    /**
     * Částka řádku v haléřích. Vzorový soubor ji nese v korunách (`gross`),
     * starší v celých haléřích (`gross_minor`); oba sloupce najednou nejdou,
     * protože by nebylo poznat, které číslo platí.
     *
     * @param array<string,string|int> $raw
     */
    private static function amount(array $raw, string $column): int
    {
        $crown = self::crownColumn($column);
        $hasCrown = array_key_exists($crown, $raw);
        $hasMinor = array_key_exists($column, $raw);
        if ($hasCrown && $hasMinor) {
            throw new \InvalidArgumentException(sprintf(
                'Soubor má sloupec „%s" v korunách i „%s" v haléřích; ponechte jen jeden.',
                $crown,
                $column,
            ));
        }
        if (!$hasCrown && !$hasMinor) {
            throw new \InvalidArgumentException(sprintf(
                'Soubor nemá sloupec „%s" (částka v korunách). Stáhněte si vzor, ať sedí hlavička.',
                $crown,
            ));
        }
        if ($hasCrown) {
            return self::crowns($raw, $crown);
        }
        $value = trim((string) ($raw[$column] ?? ''));
        if ($value === '') {
            return 0;
        }
        if (preg_match('/^-?[0-9]{1,15}$/D', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Sloupec „%s" musí být částka v celých haléřích (celé číslo).',
                $column,
            ));
        }

        return (int) $value;
    }

    /**
     * Koruny na haléře bez plovoucí řádové čárky: `12 345,50`, `12345.5`,
     * `12345` i `-120`. Mezery a pevné mezery jako oddělovač tisíců se vynechají.
     *
     * @param array<string,string|int> $raw
     */
    private static function crowns(array $raw, string $column): int
    {
        $value = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim((string) ($raw[$column] ?? '')));
        if ($value === '') {
            return 0;
        }
        if (preg_match('/^(-?)([0-9]{1,13})(?:[.,]([0-9]{1,2}))?$/D', $value, $match) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Sloupec „%s" musí být částka v korunách s nejvýše dvěma desetinnými místy (např. 12 345,50).',
                $column,
            ));
        }
        $minor = (int) $match[2] * 100 + (int) str_pad($match[3] ?? '', 2, '0');

        return $match[1] === '-' ? -$minor : $minor;
    }

    /** @param array<string,string|int> $raw */
    private static function wholeNumber(array $raw, string $column): int
    {
        $value = trim((string) ($raw[$column] ?? ''));
        if ($value === '') {
            return 0;
        }
        if (preg_match('/^[0-9]{1,3}$/D', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Sloupec „%s" musí být celý počet dnů, nula nebo vyšší.',
                $column,
            ));
        }

        return (int) $value;
    }

    /**
     * Desetinné číslo na celé jednotky (`21,5` dne → 2150 setin, `7,5` hodiny → 450 minut).
     *
     * @param array<string,string|int> $raw
     */
    private static function decimal(array $raw, string $column, int $scale): int
    {
        $value = str_replace(',', '.', trim((string) ($raw[$column] ?? '')));
        if ($value === '') {
            return 0;
        }
        if (preg_match('/^[0-9]{1,5}(\.[0-9]{1,2})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Sloupec „%s" musí být nezáporné číslo s nejvýše dvěma desetinnými místy.',
                $column,
            ));
        }
        // Přes celé setiny, ne přes násobení floatem: 0,07 * 60 dá v plovoucí
        // řádové čárce 4,199999… a zaokrouhlení dolů by ztratilo minutu.
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');

        return intdiv((int) $whole * 100 * $scale + (int) str_pad($fraction, 2, '0') * $scale, 100);
    }

    /** @param array<string,string|int> $raw */
    private static function flag(array $raw, string $column): bool
    {
        $value = strtolower(trim((string) ($raw[$column] ?? '')));
        if ($value === '') {
            return true;
        }
        if (in_array($value, ['1', 'true', 'ano', 'yes', 'y', 'a'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'ne', 'no', 'n'], true)) {
            return false;
        }
        throw new \InvalidArgumentException(sprintf(
            'Sloupec „%s" musí být 1/0 (ano/ne).',
            $column,
        ));
    }

    /** @param array<string,string|int> $raw */
    private static function optionalText(array $raw, string $column): ?string
    {
        $value = trim((string) ($raw[$column] ?? ''));
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > 64) {
            throw new \InvalidArgumentException(sprintf(
                'Sloupec „%s" smí mít nejvýše 64 znaků.',
                $column,
            ));
        }

        return $value;
    }

    /** @param array<string,string|int> $raw */
    private static function optionalDate(array $raw, string $column): ?string
    {
        $value = trim((string) ($raw[$column] ?? ''));
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(sprintf(
                'Sloupec „%s" musí být datum ve tvaru YYYY-MM-DD.',
                $column,
            ));
        }

        return $value;
    }

    /** @param array<string,string|int> $raw */
    private static function positiveInt(array $raw, string $column): int
    {
        $value = trim((string) ($raw[$column] ?? ''));
        if (preg_match('/^\d+$/D', $value) !== 1 || (int) $value <= 0) {
            throw new \InvalidArgumentException("Sloupec {$column} musí být kladné celé číslo.");
        }

        return (int) $value;
    }

    private static function source(string $value): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return 'other';
        }
        // Tento zdroj smí vzniknout jen z ověřené zálohy a její importní mapy.
        if ($normalized === 'stereo_nx') {
            throw new \InvalidArgumentException('Zdroj Stereo NX lze převzít jen ze zálohy Stereo NX.');
        }
        if (!in_array($normalized, PayrollMigrationReferenceTotalsWriter::SOURCES, true)) {
            throw new \InvalidArgumentException('Neznámý zdroj převzatých mezd.');
        }
        // Úhrny z hlášení JMHZ zapisuje jen import hlášení; tabulka pod tímhle
        // zdrojem by se tvářila jako opis přijatého podání.
        if ($normalized === PayrollMigrationReferenceTotalsWriter::SOURCE_JMHZ) {
            throw new \InvalidArgumentException('Převzaté mzdy z hlášení JMHZ se nahrávají importem hlášení, ne tabulkou.');
        }

        return $normalized;
    }

    private static function format(string $value): string
    {
        $normalized = strtolower(trim($value));
        if (!in_array($normalized, ['csv', 'xlsx'], true)) {
            throw new \InvalidArgumentException('Formát musí být csv nebo xlsx.');
        }

        return $normalized;
    }

    private static function sourceName(string $value): string
    {
        $normalized = basename(str_replace('\\', '/', trim($value)));
        if ($normalized === '' || mb_strlen($normalized) > 120
            || preg_match('/[\x00-\x1F\x7F]/u', $normalized) === 1) {
            throw new \InvalidArgumentException('Název importního souboru není platný.');
        }

        return $normalized;
    }
}
