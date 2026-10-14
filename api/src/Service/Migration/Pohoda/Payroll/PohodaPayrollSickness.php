<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\Payroll;

use MyInvoice\Service\Migration\Pohoda\PohodaXml;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriCodebook;

/**
 * Případy dávek nemocenského pojištění a náhrady mzdy z `91_mzdy.xml` (POHODA Mzdy / PAMICA).
 *
 * Nepřítomnosti samotné převádí už {@see PohodaPayrollPeople}: hodiny nesou měsíční sešity
 * a doba nepřítomnosti vzniká jako `payroll_absences`. Co tou cestou spolehlivě NEPROJDE,
 * je NÁVAZNOST rozpracované neschopnosti - PAMICA ji zná od skutečného počátku, kdežto
 * MyÚčto dostane jen tu část, která spadá do převáděného roku, případně až od měsíce,
 * kterým začíná vedení mezd. Okno náhrady mzdy podle § 192 ZP se přitom počítá z
 * `payroll_absences.date_from` ({@see \MyInvoice\Service\Payroll\Absence\AbsenceRuleset::sicknessWindowEnd()}),
 * takže bez údaje „kolik dnů okna už padlo jinde“ by se náhrada vyplatila znovu od začátku.
 *
 * Třída jen čte, normalizuje a počítá; nic nezapisuje ({@see PohodaPayrollSicknessWriter}).
 *
 * **Co export nese a co ne.** Druh nepřítomnosti se bere z ČÍSLA složky číselníku
 * `sMZneprit`, stejně jako v {@see PohodaPayrollPeople}; složka, kterou číselník označuje
 * za nemocenskou dávku (`JeNemDav`) a evidence ji nezná, jde do protokolu, ne do ticha.
 * Náhrada mzdy má v PAMICA vlastní tabulku `MZnahr`, jenže ta v exportu nemusí být vůbec
 * (viděn export, kde je jen zálohová kopie `zalMZnahr`). Pak je jediným zdrojem částky
 * `MZneprit.KcNahr`, a protokol řekne, odkud se vzala. Dávky nemocenského (`MZdavky`) se
 * nepřevádějí: od roku 2009 je nevyplácí zaměstnavatel, ale ČSSZ, takže v MyÚčtu nemají
 * kam - jejich výskyt se jen spočítá.
 *
 * **Dny se nedopočítávají.** Kalendářní i pracovní dny nepřítomnosti nese `MZneprit`
 * (`DnyKal`, `DnyPrac`); odvozovat je z hodin dělením úvazkem je zakázané, protože
 * výsledek vypadá jako údaj z exportu a přitom je to odhad.
 */
final class PohodaPayrollSickness
{
    /** Tabulky, které se čtou celé do paměti (jednotky až stovky řádků). */
    private const TABLES = ['ZAM', 'ZAMpomer', 'sMZneprit'];

    /** Podání dávek z PAMICA: hlavičky, přílohy NEMPRI a hlášení HZUPN. */
    private const SUBMISSION_TABLES = ['NEMPRI', 'NEMPRIpol', 'NEMPRIpecovalDny', 'HZUPN', 'HZUPNpol'];

    /**
     * Druh nepřítomnosti MyÚčta podle ČÍSLA složky `sMZneprit` - jen nemocenská oblast.
     * Seznam je podmnožinou {@see PohodaPayrollPeople} (tam se řeší i dovolená, překážky
     * a neplacené volno); rodičovská dovolená tu není, protože není dávkou nemocenského
     * pojištění a žádné okno náhrady mzdy neotvírá.
     */
    private const SICKNESS_CODES = [
        'N01' => 'dpn', 'H01' => 'dpn', 'N03' => 'dpn', 'H03' => 'dpn', 'N04' => 'dpn', 'H04' => 'dpn',
        'H11' => 'dpn', 'H12' => 'dpn', 'H13' => 'dpn', 'H14' => 'dpn',
        'N02' => 'quarantine', 'H02' => 'quarantine',
        'N05' => 'ocr', 'N06' => 'ocr', 'H05' => 'ocr', 'H06' => 'ocr', 'H17' => 'ocr',
        'H16' => 'long_term_care',
        'N07' => 'ppm', 'H07' => 'ppm',
        'H15' => 'paternity',
    ];

    /**
     * Druhy, u kterých zaměstnavatel poskytuje náhradu mzdy v okně § 192 ZP. Jen u nich
     * dává smysl vyčerpané dny okna evidovat; u ošetřovného, PPM ani otcovské plátce
     * mzdy nic neposkytuje, dávku platí ČSSZ od prvního dne.
     */
    public const WAGE_COMPENSATION_TYPES = ['dpn', 'quarantine'];

    /**
     * Případy nemocenské z převáděného roku.
     *
     * `$startPeriod` je první měsíc, který vede MyÚčto (`payroll_module_state.start_period`
     * ve tvaru `RRRR-MM`). Podle něj se pozná rozpracovaný případ - ten, který u předchozího
     * programu začal a u nás pokračuje. Bez něj se rozpracované případy nedají rozlišit
     * a vrací se všechny s `in_progress = false`, aby si zápis nic nedomýšlel.
     *
     * @return array{
     *     start_period:?string,
     *     cases:list<array<string,mixed>>,
     *     wage_compensation_rows:int,
     *     benefit_rows:int,
     *     benefit_claims:int,
     *     unclassified:array<string,int>
     * }
     */
    public static function read(string $file, int $year, ?string $startPeriod = null): array
    {
        $boundary = self::periodStart($startPeriod);
        $byId = [];
        /** @var array<string,array{person:string,relation:string,period:string}> $payslips */
        $payslips = [];
        $benefitClaims = 0;
        // Číselníky, karty, mzdy a podání dávek jedním průchodem souborem, řádky ke mzdám
        // (náhrady, dávky, nepřítomnosti) druhým - potřebují už znát mzdy roku.
        /** @var array<string,list<array<string,mixed>>> $claimRows nepřítomnost (`MZneprit.ID`) => řádky `NEMPRIpol` */
        $claimRows = [];
        /** @var array<string,list<array<string,mixed>>> $reportRows nepřítomnost => řádky `HZUPNpol` */
        $reportRows = [];
        /** @var array<string,array<string,array<string,mixed>>> $headers tabulka podání => ID => hlavička */
        $headers = [];
        /** @var array<string,list<array{from:string,to:string}>> $careDays `NEMPRIpol.ID` => dny péče */
        $careDays = [];
        foreach (PohodaXml::scan($file, [...self::TABLES, 'MZ', ...self::SUBMISSION_TABLES]) as $table => $row) {
            if ($table === 'NEMPRI' || $table === 'HZUPN') {
                $headers[$table][PohodaXml::text($row, 'ID')] = $row;
                continue;
            }
            if ($table === 'NEMPRIpecovalDny') {
                $from = self::realDate(PohodaXml::date($row, 'PecovalOd'));
                $to = self::realDate(PohodaXml::date($row, 'PecovalDo'));
                if ($from !== null && $to !== null && $to >= $from) {
                    $careDays[PohodaXml::text($row, 'RefPol')][] = ['from' => $from, 'to' => $to];
                }
                continue;
            }
            if ($table === 'HZUPNpol') {
                $reportRows[PohodaXml::text($row, 'RefNeprit')][] = $row;
                continue;
            }
            if ($table === 'NEMPRIpol') {
                if ((int) PohodaXml::text($row, 'RokMZ') === $year) {
                    $benefitClaims++;
                }
                $claimRows[PohodaXml::text($row, 'RefNeprit')][] = $row;
                continue;
            }
            if ($table !== 'MZ') {
                $byId[$table][PohodaXml::text($row, 'ID')] = $row;
                continue;
            }
            $month = (int) PohodaXml::text($row, 'RelMes');
            if ((int) PohodaXml::text($row, 'Rok') !== $year || $month < 1 || $month > 12) {
                continue;
            }
            $payslips[PohodaXml::text($row, 'ID')] = [
                'person' => PohodaXml::text($row, 'RefZAM'),
                'relation' => PohodaXml::text($row, 'RefPomer'),
                'period' => sprintf('%04d-%02d', $year, $month),
            ];
        }
        $claims = [];
        foreach ($claimRows as $absence => $rows) {
            foreach ($rows as $row) {
                $claim = self::claim($row, $careDays[PohodaXml::text($row, 'ID')] ?? []);
                if ($claim !== null) {
                    $claims[$absence][] = $claim + self::sent($headers['NEMPRI'][$claim['header']] ?? []);
                }
            }
        }
        $reports = [];
        foreach ($reportRows as $absence => $rows) {
            foreach ($rows as $row) {
                $report = self::report($row);
                if ($report !== null) {
                    $reports[$absence][] = $report + self::sent($headers['HZUPN'][$report['header']] ?? []);
                }
            }
        }
        $relationCount = [];
        foreach ($byId['ZAMpomer'] ?? [] as $relation) {
            $person = PohodaXml::text($relation, 'RefZAM');
            $relationCount[$person] = ($relationCount[$person] ?? 0) + 1;
        }

        /*
         * Náhrady mzdy z vlastní tabulky. Chybějící tabulka není chyba exportu: generátor
         * ji vynechá, když je prázdná, a viděn byl i export, kde zůstala jen zálohová
         * kopie `zalMZnahr`. Proudové čtení pak jen nevrátí nic a krok pokračuje dál.
         */
        $compensations = [];
        // Dávky nemocenského se nepřevádějí (vyplácí je ČSSZ), jen se počítají do protokolu.
        $benefitRows = 0;
        /** @var array<string,list<array<string,mixed>>> $groups vztah|druh => nepřítomnosti */
        $groups = [];
        /** @var array<string,int> $unclassified nemocenská složka, kterou evidence nezná */
        $unclassified = [];
        foreach (PohodaXml::scan($file, ['MZnahr', 'MZdavky', 'MZneprit']) as $table => $row) {
            $payslip = $payslips[PohodaXml::text($row, 'RefAg')] ?? null;
            if ($payslip === null) {
                continue;
            }
            if ($table === 'MZnahr') {
                $compensations[] = [
                    'relation' => $payslip['relation'],
                    'from' => self::realDate(PohodaXml::date($row, 'DatZac')),
                    'to' => self::realDate(PohodaXml::date($row, 'DatKon')),
                    'minor' => self::minor(PohodaXml::num($row, 'Kc')),
                    'id' => PohodaXml::text($row, 'ID'),
                ];
                continue;
            }
            if ($table === 'MZdavky') {
                $benefitRows++;
                continue;
            }
            $catalog = $byId['sMZneprit'][PohodaXml::text($row, 'RefSlozka')] ?? [];
            $code = strtoupper(trim(PohodaXml::text($catalog, 'Cislo')));
            $type = self::SICKNESS_CODES[$code] ?? null;
            if ($type === null) {
                // Ostatní nepřítomnosti (dovolená, překážky) patří jinému kroku převodu;
                // sem se hlásí jen to, co číselník sám označuje za nemocenskou dávku.
                if (self::bool(PohodaXml::text($catalog, 'JeNemDav'))) {
                    $label = trim($code . ' ' . PohodaXml::text($catalog, 'Nazev'));
                    $unclassified[$label] = ($unclassified[$label] ?? 0) + 1;
                }
                continue;
            }
            $from = self::realDate(PohodaXml::date($row, 'DatZac'));
            $to = self::realDate(PohodaXml::date($row, 'DatKon'));
            if ($from === null || $to === null || $to < $from) {
                continue;
            }
            $groups[$payslip['relation'] . '|' . $type][] = [
                'from' => $from,
                'to' => $to,
                'person' => $payslip['person'],
                'calendar_days' => PohodaXml::num($row, 'DnyKal'),
                'work_days' => PohodaXml::num($row, 'DnyPrac'),
                'hours' => PohodaXml::num($row, 'HodPrac'),
                'compensation_minor' => self::minor(PohodaXml::num($row, 'KcNahr')),
                'childbirth' => self::realDate(PohodaXml::date($row, 'DatPorod')),
                'id' => PohodaXml::text($row, 'ID'),
            ];
        }

        $cases = [];
        foreach ($groups as $key => $rows) {
            [$relationId, $type] = explode('|', $key, 2);
            $relation = $byId['ZAMpomer'][$relationId] ?? [];
            $personId = PohodaXml::text($relation, 'RefZAM');
            $person = $byId['ZAM'][$personId] ?? [];
            $personalNumber = $person === [] || $relation === []
                ? null
                : PohodaPayrollPeople::personalNumber($person, $relation, $relationCount[$personId] ?? 1);
            foreach (self::merge($rows) as $case) {
                $cases[] = self::record(
                    $case,
                    $type,
                    $personId,
                    $personalNumber,
                    $relationId,
                    $compensations,
                    $boundary,
                ) + [
                    'benefit_claim' => self::latest($case['evidence'], $claims),
                    'end_report' => self::latest($case['evidence'], $reports),
                ];
            }
        }
        usort($cases, static fn (array $a, array $b): int
            => [$a['personal_number'] ?? '', $a['date_from']] <=> [$b['personal_number'] ?? '', $b['date_from']]);

        return [
            'start_period' => $startPeriod,
            'cases' => $cases,
            'wage_compensation_rows' => count($compensations),
            'benefit_rows' => $benefitRows,
            'benefit_claims' => $benefitClaims,
            'unclassified' => $unclassified,
        ];
    }

    /**
     * Kolik kalendářních dnů okna náhrady mzdy padlo PŘED dnem, od kterého případ vede
     * MyÚčto. Den, kterým okno začíná, se počítá, den převzetí už ne - okno je souvislá
     * řada kalendářních dnů od vzniku neschopnosti, ne od nástupu k plátci.
     *
     * Jediná cesta, jak tohle číslo spočítat: používá ji čtení převodu (proti prvnímu
     * měsíci vedení mezd) i jeho zápis (proti skutečnému `date_from` nepřítomnosti).
     * `$windowCalendarDays` je délka okna z rulesetu; bez ní se vrací neomezený počet
     * uplynulých dnů, protože zákonné číslo si tahle třída vymýšlet nesmí.
     */
    public static function windowUsedCalendarDays(
        string $caseFrom,
        string $carriedFrom,
        ?int $windowCalendarDays = null,
    ): int {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $caseFrom);
        $taken = \DateTimeImmutable::createFromFormat('!Y-m-d', $carriedFrom);
        if (!$start instanceof \DateTimeImmutable || !$taken instanceof \DateTimeImmutable) {
            return 0;
        }
        $used = (int) $start->diff($taken)->format('%r%a');
        if ($used <= 0) {
            return 0;
        }

        return $windowCalendarDays === null ? $used : min($used, $windowCalendarDays);
    }

    /**
     * Příloha k žádosti o dávku (`NEMPRIpol`), jak ji PAMICA sestavila: číslo rozhodnutí
     * lékaře, rozhodné období po měsících a u ošetřovného údaje o ošetřované osobě a péči.
     * MyÚčto z ní umí podání připravit samo, i když měsíce před převáděným rokem nemá.
     *
     * `fields` jsou hodnoty sloupců případu dávky ve tvaru, který přijímá jeho editace.
     *
     * @param array<string,mixed> $row
     * @param list<array{from:string,to:string}> $careDays
     * @return array<string,mixed>|null
     */
    private static function claim(array $row, array $careDays): ?array
    {
        $period = self::submissionPeriod($row);
        if ($period === null) {
            return null;
        }
        $months = [];
        for ($i = 1; $i <= 12; $i++) {
            $from = self::realDate(PohodaXml::date($row, 'DatR' . $i));
            if ($from === null) {
                continue;
            }
            $months[substr($from, 0, 7)] = [
                'income_minor' => max(0, self::minor(PohodaXml::num($row, 'KcPrijR' . $i))),
                'excluded_days' => max(0, min(31, (int) round(PohodaXml::num($row, 'VyldnyR' . $i)))),
            ];
        }
        ksort($months);
        $fields = [];
        $kind = strtoupper(trim(PohodaXml::text($row, 'DruhDavky')));
        if (in_array($kind, ['OSE', 'DLO'], true)) {
            $actions = [
                'action_start' => self::bool(PohodaXml::text($row, 'Vznik')),
                'action_continuation' => self::bool(PohodaXml::text($row, 'Trvani')),
                'action_end' => self::bool(PohodaXml::text($row, 'Ukonceni')),
            ];
            if (in_array(true, $actions, true)) {
                $fields += $actions;
            }
            $fields['application_from'] = self::realDate(PohodaXml::date($row, 'OdeDne'));
            $fields['application_to'] = self::realDate(PohodaXml::date($row, 'DoDne'));
            $fields['cared_first_name'] = self::textOrNull(PohodaXml::text($row, 'OsetrOsJmeno'));
            $fields['cared_last_name'] = self::textOrNull(PohodaXml::text($row, 'OsetrOsPrij'));
            $fields['cared_birth_date'] = self::realDate(PohodaXml::date($row, 'OsetrOsDatNar'));
            $fields['care_reason'] = match (true) {
                self::bool(PohodaXml::text($row, 'Onemocnela')) => 'ill',
                self::bool(PohodaXml::text($row, 'NarizenKaran')) => 'quarantine',
                self::bool(PohodaXml::text($row, 'NemuzePecovat')) => 'cannot_care',
                self::bool(PohodaXml::text($row, 'ZarizeniUzavreno')) => 'school_closed',
                default => null,
            };
            $fields['shared_household'] = self::bool(PohodaXml::text($row, 'SpolecDoma'));
            $fields['lone_caregiver'] = self::bool(PohodaXml::text($row, 'JeOsamely'));
            $fields['child_under_16'] = self::bool(PohodaXml::text($row, 'DiteDo16Let'));
            $fields['cared_personally'] = self::bool(PohodaXml::text($row, 'PecovalOsobne'));
            // Kód vztahu ukládá PAMICA jako pořadí v číselníku ČSSZ (1 = první položka).
            $order = $kind === 'OSE'
                ? (int) PohodaXml::text($row, 'RelKodRodVztah')
                : (int) PohodaXml::text($row, 'RelKodVztah');
            $codes = $kind === 'OSE' ? NempriCodebook::FAMILY_RELATIONSHIPS : NempriCodebook::CARE_RELATIONSHIPS;
            $fields['relationship_code'] = $codes[$order - 1] ?? null;
            $fields['care_days'] = $careDays === [] ? null : $careDays;
            $fields['worked_last_day'] = self::bool(PohodaXml::text($row, 'PracovalPoslDenPD'));
            $fields['planned_shifts'] = self::bool(PohodaXml::text($row, 'PlanovSmeny'));
            $fields['planned_shifts_worked'] = self::bool(PohodaXml::text($row, 'PlanovSmenyOdprac'));
        }

        return [
            'header' => PohodaXml::text($row, 'RefAg'),
            'period' => $period,
            'decision_number' => self::textOrNull(PohodaXml::text($row, 'CisPotvrz')),
            'decisive_months' => $months,
            'fields' => array_filter($fields, static fn (mixed $value): bool => $value !== null),
        ];
    }

    /**
     * Hlášení při ukončení pracovní neschopnosti (`HZUPNpol`): den vystavení, návrat
     * do práce a hodiny posledního dne, jak je PAMICA podala.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private static function report(array $row): ?array
    {
        $period = self::submissionPeriod($row);
        if ($period === null) {
            return null;
        }
        $returned = self::bool(PohodaXml::text($row, 'NavratDoPrace'));
        $shift = PohodaXml::num($row, 'PracovniDobaPoslDenPD');

        return [
            'header' => PohodaXml::text($row, 'RefAg'),
            'period' => $period,
            'decision_number' => self::textOrNull(PohodaXml::text($row, 'CisloPotvrzeni')),
            'fields' => array_filter([
                'issued_on' => self::realDate(PohodaXml::date($row, 'DatumVystaveni')),
                'returned_to_work' => $returned,
                'returned_on' => $returned ? self::realDate(PohodaXml::date($row, 'DatumNavratuDoPrace')) : null,
                'hours_worked_last_day' => PohodaXml::num($row, 'PocetOdpracHodinPoslDenPD'),
                'shift_hours_last_day' => $shift > 0 ? $shift : null,
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    /** @param array<string,mixed> $row */
    private static function submissionPeriod(array $row): ?string
    {
        $year = (int) PohodaXml::text($row, 'RokMZ');
        $month = (int) PohodaXml::text($row, 'MesicMZ');

        return $year < 2000 || $month < 1 || $month > 12 ? null : sprintf('%04d-%02d', $year, $month);
    }

    private static function textOrNull(string $text): ?string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', $text));

        return $value === '' ? null : $value;
    }

    /**
     * Zda PAMICA podání odeslala a kdy ho ČSSZ přijala (hlavička `NEMPRI`/`HZUPN`).
     *
     * @param array<string,mixed> $header
     * @return array{sent:bool,accepted_on:?string}
     */
    private static function sent(array $header): array
    {
        return [
            'sent' => $header !== [] && self::bool(PohodaXml::text($header, 'ElOdeslano')),
            'accepted_on' => $header === [] ? null : self::realDate(PohodaXml::date($header, 'DatPrij')),
        ];
    }

    /**
     * Poslední podání k případu podle nepřítomností, ze kterých případ vznikl.
     *
     * @param list<string> $evidence
     * @param array<string,list<array<string,mixed>>> $byAbsence
     * @return array<string,mixed>|null
     */
    private static function latest(array $evidence, array $byAbsence): ?array
    {
        $found = [];
        foreach ($evidence as $reference) {
            if (str_starts_with($reference, 'MZneprit:')) {
                $found = [...$found, ...($byAbsence[substr($reference, 9)] ?? [])];
            }
        }
        if ($found === []) {
            return null;
        }
        usort($found, static fn (array $a, array $b): int => $b['period'] <=> $a['period']);

        return $found[0];
    }

    /**
     * Jeden případ z už sloučených řádků `MZneprit`.
     *
     * @param array<string,mixed> $case
     * @param list<array<string,mixed>> $compensations
     * @return array<string,mixed>
     */
    private static function record(
        array $case,
        string $type,
        string $personId,
        ?string $personalNumber,
        string $relationId,
        array $compensations,
        ?string $boundary,
    ): array {
        $from = (string) $case['from'];
        $to = (string) $case['to'];
        $paid = null;
        foreach ($compensations as $row) {
            if ($row['relation'] !== $relationId) {
                continue;
            }
            // Náhrada bez data se přiřadit nedá; zůstane nevyplněná a protokol ji vypíše.
            if (!is_string($row['from']) || !is_string($row['to']) || $row['from'] > $to || $row['to'] < $from) {
                continue;
            }
            $paid = ($paid ?? 0) + (int) $row['minor'];
        }
        $source = $paid === null ? null : 'mznahr';
        if ($paid === null && $case['compensation_minor'] > 0) {
            $paid = (int) $case['compensation_minor'];
            $source = 'mzneprit_kcnahr';
        }
        $carried = $boundary !== null && in_array($type, self::WAGE_COMPENSATION_TYPES, true)
            ? self::windowUsedCalendarDays($from, $boundary)
            : null;

        return [
            'person_key' => $personId,
            'relation_key' => $relationId,
            'personal_number' => $personalNumber,
            'type' => $type,
            'date_from' => $from,
            'date_to' => $to,
            'childbirth' => $case['childbirth'],
            // Dny z exportu, ne z hodin: `null` znamená „PAMICA je nevede“.
            'calendar_days' => $case['calendar_days'] > 0 ? (int) round((float) $case['calendar_days']) : null,
            'work_days' => $case['work_days'] > 0 ? (float) $case['work_days'] : null,
            'hours' => $case['hours'] > 0 ? (float) $case['hours'] : null,
            'compensation_minor' => $paid,
            'compensation_source' => $source,
            /*
             * Uplynulé dny okna § 192 ZP ke dni převzetí. Zastropuje je až zápis podle
             * délky okna z rulesetu - zákonné číslo tahle třída nezná a hádat ho nebude.
             */
            'window_used_calendar_days' => $carried,
            // Rozpracovaný případ: u předchozího programu začal a do MyÚčta pokračuje.
            'in_progress' => $boundary !== null && $to >= $boundary,
            'crosses_start_period' => $boundary !== null && $from < $boundary && $to >= $boundary,
            'evidence' => $case['evidence'],
        ];
    }

    /**
     * Sloučení řádků téže nepřítomnosti. PAMICA vede `MZneprit` u každé mzdy, takže jeden
     * případ přes víc měsíců je víc řádků - buď se shodným rozsahem případu, nebo
     * rozkrájený po měsících. Slučuje se proto překryv i navazující den; mezera mezi dvěma
     * rozsahy znamená dva případy a dvě okna náhrady.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function merge(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => [$a['from'], $a['to']] <=> [$b['from'], $b['to']]);
        $merged = [];
        foreach ($rows as $row) {
            $last = $merged === [] ? null : $merged[count($merged) - 1];
            $next = $last === null
                ? null
                : (\DateTimeImmutable::createFromFormat('!Y-m-d', (string) $last['to']) ?: null)?->modify('+1 day')->format('Y-m-d');
            if ($last === null || $next === null || $row['from'] > $next) {
                $merged[] = [
                    'from' => $row['from'],
                    'to' => $row['to'],
                    'calendar_days' => (float) $row['calendar_days'],
                    'work_days' => (float) $row['work_days'],
                    'hours' => (float) $row['hours'],
                    'compensation_minor' => (int) $row['compensation_minor'],
                    'childbirth' => $row['childbirth'],
                    'evidence' => ['MZneprit:' . $row['id']],
                ];
                continue;
            }
            $index = count($merged) - 1;
            $merged[$index]['to'] = max((string) $last['to'], (string) $row['to']);
            /*
             * Dny a hodiny se sčítají jen u řádků rozkrájených po měsících. Řádek se
             * shodným rozsahem je táž evidence viděná z jiné mzdy, takže by se počítala
             * dvakrát; pozná se podle toho, že nový rozsah nic nepřidal.
             */
            if ((string) $row['to'] > (string) $last['to']) {
                $merged[$index]['calendar_days'] += (float) $row['calendar_days'];
                $merged[$index]['work_days'] += (float) $row['work_days'];
                $merged[$index]['hours'] += (float) $row['hours'];
            }
            // Náhrada se sčítá vždy: je to částka konkrétní mzdy, ne opis rozsahu případu.
            $merged[$index]['compensation_minor'] += (int) $row['compensation_minor'];
            $merged[$index]['childbirth'] ??= $row['childbirth'];
            $merged[$index]['evidence'][] = 'MZneprit:' . $row['id'];
        }

        return $merged;
    }

    /** První den měsíce, kterým začíná vedení mezd v MyÚčtu. */
    private static function periodStart(?string $period): ?string
    {
        return $period !== null && preg_match('/^\d{4}-\d{2}$/D', $period) === 1 ? $period . '-01' : null;
    }

    /** Nulové datum Accessu (před rokem 1901) = žádné datum. */
    private static function realDate(?string $date): ?string
    {
        return $date !== null && (int) substr($date, 0, 4) >= 1901 ? $date : null;
    }

    private static function bool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', '-1', 'true'], true);
    }

    private static function minor(float $value): int
    {
        return (int) round($value * 100);
    }
}
