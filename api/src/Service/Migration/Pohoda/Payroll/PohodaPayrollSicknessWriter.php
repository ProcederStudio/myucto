<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAbsenceOverlapException;
use MyInvoice\Repository\Payroll\PayrollAbsenceRepository;
use MyInvoice\Repository\Payroll\PayrollAverageEarningRepository;
use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;
use MyInvoice\Repository\Payroll\PayrollSicknessCaseRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Payroll\Absence\AbsenceRuleset;
use MyInvoice\Service\Payroll\PayrollAbsenceValidator;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessCaseFromAbsenceService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessCaseService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDocumentKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;

/**
 * Zápis rozpracovaných případů nemocenské z PAMICA ({@see PohodaPayrollSickness}).
 *
 * Řeší jedinou věc, kterou ostatní kroky převodu neumějí: NÁVAZNOST. Nepřítomnost se
 * zakládá toutéž cestou jako ruční zápis (validátor + {@see PayrollAbsenceRepository}),
 * vlastní SQL do jejích tabulek tu není; navíc se u dočasné pracovní neschopnosti
 * a karantény zapíší dny okna náhrady mzdy podle § 192 ZP, které padly ještě u předchozího
 * plátce. Bez nich MyÚčto počítá okno z `date_from` nepřítomnosti a náhradu vyplatí znovu
 * od začátku - u nemoci přes přelom (červenec tam, srpen tady) až čtrnáct dnů navíc.
 *
 * **Bez prvního měsíce vedení mezd se nezapisuje nic.** `payroll_module_state.start_period`
 * je jediné, co dělí „vyčerpáno jinde“ od „vyčerpáno u nás“. Když nastavený není, krok
 * jen napíše do protokolu, kolik případů čeká, a skončí - hádaná hranice by vyrobila
 * hádané dny okna.
 *
 * **Jen doplnit, nepřepisovat.** Existující nepřítomnost se dohledá podle vztahu, druhu
 * a překryvu dat; zapíšou se jí vyčerpané dny a víc nic. Chybí-li, založí se ta část
 * případu, která spadá do MyÚčta, ve stavu „požadováno“ - schválení patří účetní, protože
 * konec neschopnosti z jiného programu není potvrzením ošetřujícího lékaře. Opakovaný
 * převod proto nic nezdvojí: podruhé už nepřítomnost existuje a vyčerpané dny sedí.
 *
 * **Případ dávky.** K rozběhnutému případu se založí i případ dávky nemocenského
 * pojištění ({@see SicknessCaseFromAbsenceService::onTakenOver()}) se skutečným dnem
 * vzniku z PAMICA: NEMPRI, jehož lhůta uplynula před prvním měsícem vedení mezd,
 * podala PAMICA, HZUPN k návratu do práce podá už MyÚčto a hlídač termínů ho hlídá.
 */
final class PohodaPayrollSicknessWriter
{
    private const MESSAGE_LIMIT = 40;
    private const SAVEPOINT = 'pohoda_payroll_sickness';
    private const NOTE = 'Převzato z PAMICA: ';

    private int $messages = 0;
    private int $windowWritten = 0;
    private int $compensationFromAbsence = 0;
    private int $withoutCompensation = 0;
    /** @var list<string> */
    private array $sicknessCaseSkipped = [];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollAbsenceRepository $absences,
        private readonly PayrollAbsenceValidator $validator,
        private readonly PayrollModuleStateRepository $moduleState,
        private readonly PayrollRulesetProvider $rulesets,
        private readonly PayrollAverageEarningRepository $averages,
        private readonly SicknessCaseFromAbsenceService $sicknessCases,
        private readonly PayrollSicknessCaseRepository $caseRows,
        private readonly SicknessCaseService $caseService,
    ) {}

    /** První měsíc vedení mezd, podle kterého se pozná rozpracovaný případ. */
    public function startPeriod(int $supplierId): ?string
    {
        return $this->moduleState->get($supplierId)['start_period'];
    }

    /**
     * @param array{
     *     start_period:?string,
     *     cases:list<array<string,mixed>>,
     *     wage_compensation_rows:int,
     *     benefit_rows:int,
     *     benefit_claims:int,
     *     unclassified:array<string,int>
     * } $result
     */
    public function write(int $supplierId, ?int $userId, array $result, ImportProtocol $protocol, string $step): void
    {
        $this->messages = 0;
        $this->windowWritten = 0;
        $this->compensationFromAbsence = 0;
        $this->withoutCompensation = 0;
        $this->sicknessCaseSkipped = [];

        $startPeriod = $result['start_period'];
        $inProgress = array_values(array_filter(
            $result['cases'],
            static fn (array $case): bool => $case['in_progress'] === true,
        ));
        $protocol->count($step, 'sickness_cases', count($result['cases']));

        if ($startPeriod === null) {
            $protocol->warn($step, 'sickness_start_period_missing', sprintf(
                'Případů nemocenské v exportu: %d. Převod z nich nezapsal nic, protože firma nemá '
                . 'nastavený první měsíc vedení mezd v MyÚčtu (Mzdy → Nastavení). Bez něj nejde '
                . 'poznat, které dny okna náhrady mzdy podle § 192 ZP vyčerpal předchozí plátce. '
                . 'Nastavte první měsíc, který PAMICA nezpracovala, a převod zopakujte.',
                count($result['cases']),
            ));
            $this->summary($protocol, $step, $result);
            return;
        }

        foreach ($inProgress as $case) {
            $number = (string) ($case['personal_number'] ?? $case['person_key']);
            $label = self::label($case);
            $employmentId = $this->employmentFor($supplierId, $case['personal_number']);
            if ($employmentId === null) {
                $protocol->count($step, 'sickness_person_not_found');
                $this->warn($protocol, $step, 'sickness_person_not_found',
                    "Osobní číslo {$number}: {$label} nelze převzít, pracovní vztah s tímto kódem ve firmě není.", $number);
                continue;
            }
            $this->part($protocol, $step, $number, $label,
                fn (): array => $this->case($supplierId, $employmentId, $case, (string) $startPeriod, $userId));
        }

        $protocol->count($step, 'sickness_cases_in_progress', count($inProgress));
        if ($this->windowWritten > 0) {
            $protocol->info($step, 'sickness_window_carried', sprintf(
                'Rozpracovaných neschopností s navázaným oknem náhrady mzdy: %d. U nich je zapsané, '
                . 'kolik kalendářních dnů okna podle § 192 ZP padlo ještě u předchozího plátce, '
                . 'takže MyÚčto vyplatí jen zbytek okna, ne celých čtrnáct dnů znovu.',
                $this->windowWritten,
            ));
        }
        if ($this->sicknessCaseSkipped !== []) {
            $protocol->warn($step, 'sickness_benefit_case_skipped', sprintf(
                'Případ dávky nemocenského pojištění se nezaložil u %d rozpracovaných případů, '
                . 'takže jejich lhůtu HZUPN MyÚčto nehlídá. Založte je ručně v Mzdy → Podání → '
                . 'Dávky nemocenského. Důvod u prvního: %s',
                count($this->sicknessCaseSkipped),
                $this->sicknessCaseSkipped[0],
            ));
        }
        $this->summary($protocol, $step, $result);
    }

    /**
     * Případ dávky téže události, i když se dohledaná nepřítomnost (část z převzatých
     * měsíců) k případu nevrací: případ nese celou událost od skutečného dne vzniku.
     */
    private function caseOf(int $supplierId, int $employmentId, string $type, string $from, string $to): ?int
    {
        $kind = SicknessCaseFromAbsenceService::benefitKindFor($type);
        if ($kind === null) {
            return null;
        }
        $cases = $this->caseRows->overlappingForEmployment(
            $supplierId,
            SicknessCaseFromAbsenceService::ENVIRONMENT,
            $employmentId,
            $kind->value,
            $from,
            $to,
        );

        return count($cases) === 1 ? (int) $cases[0]['id'] : null;
    }

    /**
     * Údaje z podání, která k případu sestavila PAMICA: z přílohy NEMPRI číslo rozhodnutí
     * lékaře, rozhodné období po měsících (i měsíce před převáděným rokem, které převod
     * jinak nemá) a u ošetřovného ošetřovanou osobu a péči, z hlášení HZUPN den vystavení
     * a návrat do práce. Doplňuje jen prázdné, ručně zadané údaje nepřepisuje. Podání,
     * které PAMICA odeslala za měsíc před začátkem vedení mezd v MyÚčtu, vede jako podané
     * předchozím programem; pozdější podá MyÚčto samo z převzatých údajů.
     *
     * @param array<string,mixed>|null $claim příloha NEMPRI
     * @param array<string,mixed>|null $report hlášení HZUPN
     * @return array<string,int>
     */
    private function submissions(int $supplierId, int $caseId, ?array $claim, ?array $report, string $startPeriod): array
    {
        $environment = SicknessCaseFromAbsenceService::ENVIRONMENT;
        $row = $this->caseRows->find($supplierId, $environment, $caseId);
        if ($row === null || (int) ($row['cancelled'] ?? 0) === 1) {
            return [];
        }
        $counts = [];
        $input = [];
        $decision = $claim['decision_number'] ?? $report['decision_number'] ?? null;
        if (is_string($decision) && trim((string) ($row['decision_number'] ?? '')) === '') {
            $input['decision_number'] = $decision;
        }
        foreach ([...($claim['fields'] ?? []), ...($report['fields'] ?? [])] as $field => $value) {
            $current = $row[$field] ?? null;
            if (str_starts_with($field, 'action_') || $current === null || $current === '') {
                $input[$field] = $value;
            }
        }
        if ($input !== []) {
            try {
                $this->caseService->update($supplierId, $environment, $caseId, (int) $row['row_version'], $input);
                $counts['sickness_case_details'] = 1;
            } catch (SicknessException|\InvalidArgumentException|\DomainException) {
                // Údaje, které případ nepřijme, doplní účetní; podání to při přípravě řekne.
            }
        }
        if (($claim['decisive_months'] ?? []) !== []
            && $this->caseRows->decisiveMonths($supplierId, $environment, $caseId) === []) {
            $this->caseRows->replaceDecisiveMonths($supplierId, $environment, $caseId, $claim['decisive_months']);
            $counts['sickness_decisive_months'] = 1;
        }
        $start = substr($startPeriod, 0, 7);
        foreach ([[SicknessDocumentKind::Nempri, 'nempri_status', $claim], [SicknessDocumentKind::Hzupn, 'hzupn_status', $report]]
            as [$document, $status, $submission]) {
            if (!is_array($submission) || $submission['sent'] !== true || $submission['period'] >= $start
                || ($row[$status] ?? null) !== 'pending') {
                continue;
            }
            try {
                $this->caseService->recordReceipt($supplierId, $environment, $caseId, $document,
                    'predecessor', $submission['accepted_on'], null);
                $counts['sickness_' . $document->value . '_predecessor'] = 1;
            } catch (SicknessException) {
                // Stav podání převod nerozhodne; zůstane k vyřízení v případu dávky.
            }
        }

        return $counts;
    }

    /**
     * Jeden rozpracovaný případ: buď se dohledá nepřítomnost, kterou MyÚčto už má, nebo
     * se založí její část od prvního měsíce vedení mezd. V obou případech se dopočítají
     * vyčerpané dny okna proti SKUTEČNÉMU `date_from` nepřítomnosti - to je den, od kterého
     * {@see AbsenceRuleset::sicknessWindowEnd()} okno počítá, takže cokoli před ním je
     * vyčerpané jinde.
     *
     * @param array<string,mixed> $case
     * @return array<string,int>
     */
    private function case(int $supplierId, int $employmentId, array $case, string $startPeriod, ?int $userId): array
    {
        $type = (string) $case['type'];
        $from = (string) $case['date_from'];
        $to = (string) $case['date_to'];
        $existing = $this->existingAbsence($supplierId, $employmentId, $type, $from, $to);
        $counts = [];
        if ($existing === null) {
            $body = [
                'employment_id' => $employmentId,
                'absence_type' => $type,
                // Do MyÚčta patří jen část případu od prvního měsíce, který vede; dřívější
                // dny zpracovala PAMICA a jsou v převedených mzdách.
                'date_from' => max($from, $startPeriod . '-01'),
                'date_to' => $to,
                'note' => self::note($case),
            ];
            if ($type === 'ppm' && is_string($case['childbirth'])) {
                $body['expected_childbirth_date'] = $case['childbirth'];
                $body['childbirth_date'] = $case['childbirth'];
            }
            $existing = $this->absences->create($supplierId, $this->validator->absence($body), $userId);
            $counts['sickness_absences'] = 1;
        }
        // Rozhodnout ji musí někdo, jinak nerozhodnutá nepřítomnost zablokuje schválení
        // celého pracovního měsíce. U převzatého případu rozhodl předchozí program: proběhl
        // a je podaný. Schvaluje se stejným pravidlem jako u ostatních převzatých
        // nepřítomností ({@see \MyInvoice\Service\Payroll\Migration\PayrollTakeoverAbsenceWriter::absences()}): druh, který potřebuje
        // průměrný výdělek, až když čtvrtletí schválený průměr má.
        //
        // Dorovnává se i u nepřítomnosti z dřívějšího běhu převodu, ale JEN u té, kterou
        // převod sám založil (pozná se podle poznámky). Cizí nerozhodnutou nepřítomnost by
        // převod rozhodovat neměl - účetní ji mohla nechat otevřenou schválně.
        if (self::takenOver($existing) && ($existing['status'] ?? null) === 'requested') {
            $quarter = (int) ceil(((int) substr((string) $existing['date_from'], 5, 2)) / 3);
            $year = (int) substr((string) $existing['date_from'], 0, 4);
            $needsAverage = in_array($type, PayrollAbsenceValidator::TYPES_REQUIRING_AVERAGE, true);
            // Průměr potřebuje jen náhrada mzdy. Vyčerpal-li okno náhrady předchozí plátce,
            // MyÚčto za nepřítomnost nic nepočítá a průměr čtvrtletí, ve kterém osoba celou
            // dobu nepracovala (a PAMICA ho proto nevede), schválení nebrání.
            if (!$needsAverage || $this->windowExhausted($case, (string) $existing['date_from'])
                || $this->averages->findApproved($supplierId, $employmentId, $year, $quarter) !== null
            ) {
                try {
                    $this->absences->decide($supplierId, (int) $existing['id'], (int) $existing['row_version'], 'approved', $userId);
                    $existing = $this->absences->find($supplierId, (int) $existing['id']) ?? $existing;
                    $counts['sickness_absences_approved'] = 1;
                } catch (\DomainException|\InvalidArgumentException) {
                    // Nechá se na účetní; protokol to vypíše jako nerozhodnutou nepřítomnost.
                }
            }
        }

        $carried = $this->carriedDays($case, (string) $existing['date_from']);
        if ($carried !== null && $carried !== (int) ($existing['sickness_window_carried_days'] ?? 0)) {
            $this->absences->setSicknessWindowCarriedDays($supplierId, (int) $existing['id'], $carried);
            $this->windowWritten++;
            $counts['sickness_window_carried'] = 1;
        }
        // Případ dávky: NEMPRI k rozběhnuté neschopnosti zpravidla podala PAMICA,
        // HZUPN k návratu do práce podá už MyÚčto (§ 97 odst. 3). Den vzniku je
        // skutečný začátek případu v PAMICA, ne první den v MyÚčtu.
        $sicknessCase = $this->sicknessCases->onTakenOver(
            $supplierId,
            $this->absences->find($supplierId, (int) $existing['id']) ?? $existing,
            $userId,
            $startPeriod,
            $from,
            'pamica:' . ($case['personal_number'] ?? $case['person_key']) . ':' . $type . ':' . $from,
        );
        $caseId = $sicknessCase['case_id'] ?? $this->caseOf($supplierId, $employmentId, $type, $from, $to);
        if ($caseId !== null && (is_array($case['benefit_claim'] ?? null) || is_array($case['end_report'] ?? null))) {
            $counts += $this->submissions($supplierId, (int) $caseId, $case['benefit_claim'] ?? null,
                $case['end_report'] ?? null, $startPeriod);
        }
        if ($sicknessCase !== null) {
            if ($sicknessCase['outcome'] === 'created') {
                $counts['sickness_benefit_cases'] = 1;
            } elseif ($sicknessCase['outcome'] === 'skipped') {
                $counts['sickness_benefit_cases_skipped'] = 1;
                $this->sicknessCaseSkipped[] = (string) $sicknessCase['message'];
            }
        }
        if ($case['compensation_minor'] === null) {
            $this->withoutCompensation++;
        } elseif ($case['compensation_source'] === 'mzneprit_kcnahr') {
            $this->compensationFromAbsence++;
        }

        return $counts;
    }

    /**
     * Vyčerpané dny okna § 192 ZP. Délku okna drží ruleset
     * ({@see AbsenceRuleset::sicknessWindowCalendarDays()}), takže se tu zastropují týmž
     * číslem, jakým se okno počítá; u druhů bez náhrady mzdy (ošetřovné, PPM, otcovská)
     * žádné okno neexistuje a nezapisuje se nic.
     *
     * @param array<string,mixed> $case
     */
    /** @param array<string,mixed> $case */
    private function windowExhausted(array $case, string $absenceFrom): bool
    {
        $carried = $this->carriedDays($case, $absenceFrom);

        return $carried !== null
            && $carried >= AbsenceRuleset::forDate($this->rulesets, (string) $case['date_from'])->sicknessWindowCalendarDays();
    }

    private function carriedDays(array $case, string $absenceFrom): ?int
    {
        if (!in_array((string) $case['type'], PohodaPayrollSickness::WAGE_COMPENSATION_TYPES, true)) {
            return null;
        }

        return PohodaPayrollSickness::windowUsedCalendarDays(
            (string) $case['date_from'],
            $absenceFrom,
            AbsenceRuleset::forDate($this->rulesets, (string) $case['date_from'])->sicknessWindowCalendarDays(),
        );
    }

    /**
     * Nepřítomnost téhož vztahu a druhu, která se s případem překrývá. Bere se ta nejdřívější:
     * případ z PAMICA je souvislá řada dnů, takže víc nepřítomností v jeho rozsahu znamená
     * rozdělenou evidenci a okno počítá ta první.
     *
     * @return array<string,mixed>|null
     */
    private function existingAbsence(int $supplierId, int $employmentId, string $type, string $from, string $to): ?array
    {
        $found = null;
        foreach ($this->absences->list($supplierId, $from, $to, $employmentId, PayrollAbsenceRepository::LIST_MAX_LIMIT)['items'] as $absence) {
            if ($absence['absence_type'] !== $type || $absence['status'] === 'cancelled' || $absence['status'] === 'rejected') {
                continue;
            }
            if ($found === null || $absence['date_from'] < $found['date_from']) {
                $found = $absence;
            }
        }

        return $found;
    }

    /** @param array<string,mixed> $case */
    /** Nepřítomnost, kterou založil převod; cizí záznam se nerozhoduje. */
    private static function takenOver(array $absence): bool
    {
        return str_starts_with((string) ($absence['note'] ?? ''), self::NOTE);
    }

    private static function note(array $case): string
    {
        $note = self::NOTE . 'rozpracovaný případ nemocenské od ' . $case['date_from'] . '.';
        if ($case['compensation_minor'] !== null) {
            $note .= sprintf(' Náhrada mzdy vyplacená předchozím programem: %s Kč (%s).',
                number_format(((int) $case['compensation_minor']) / 100, 2, ',', ' '),
                $case['compensation_source'] === 'mznahr' ? 'MZnahr' : 'MZneprit.KcNahr');
        }

        return mb_substr($note . ' Zkontrolujte a schvalte proti dokladu.', 0, 1000);
    }

    /** @param array<string,mixed> $case */
    private static function label(array $case): string
    {
        return sprintf('Nepřítomnost %s %s až %s', $case['type'], $case['date_from'], $case['date_to']);
    }

    /**
     * Co se z nemocenské oblasti převést nedá. Protokol to musí říct nahlas - tichý
     * výpadek údaje vypadá jako převedený údaj.
     *
     * @param array<string,mixed> $result
     */
    private function summary(ImportProtocol $protocol, string $step, array $result): void
    {
        if ($result['wage_compensation_rows'] === 0) {
            $protocol->info($step, 'sickness_compensation_source', sprintf(
                'Tabulku náhrad mzdy `MZnahr` export nenese (nebo je prázdná). Vyplacená náhrada '
                . 'se proto bere z nepřítomností (`MZneprit.KcNahr`) a slouží jen jako poznámka '
                . 'u nepřítomnosti; MyÚčto si náhradu počítá samo z průměrného výdělku. '
                . 'Případů bez jakékoli částky náhrady: %d.',
                $this->withoutCompensation,
            ));
        } elseif ($this->compensationFromAbsence > 0) {
            $protocol->info($step, 'sickness_compensation_source', sprintf(
                'Případů, u kterých náhrada mzdy není v `MZnahr` a vzala se z `MZneprit.KcNahr`: %d.',
                $this->compensationFromAbsence,
            ));
        }
        if ($result['benefit_rows'] > 0) {
            $protocol->warn($step, 'sickness_benefits_skipped', sprintf(
                'Dávek nemocenského pojištění v exportu (`MZdavky`): %d. Vyplacené dávky se nepřevádějí - '
                . 'od roku 2009 je nevyplácí zaměstnavatel, ale ČSSZ. Rozpracovaná neschopnost, '
                . 'ošetřování nebo mateřská z nepřítomností se převádí jako případ dávky '
                . '(Mzdy → Podání → Dávky nemocenského): NEMPRI, jehož lhůta uplynula před prvním '
                . 'měsícem vedení mezd v MyÚčtu, je vedené jako podané z PAMICA a MyÚčto hlídá '
                . 'HZUPN k návratu do práce. Zkontrolujte převzaté případy proti podáním z PAMICA.',
                $result['benefit_rows'],
            ));
        }
        if ($result['benefit_claims'] > 0) {
            $protocol->info($step, 'sickness_claims', sprintf(
                'Příloh k žádosti o dávku (`NEMPRIpol`): %d. K případům dávek, které pokračují '
                . 'do MyÚčta, převod převzal číslo rozhodnutí a rozhodné období po měsících, '
                . 'jak je PAMICA podala. Přílohu odeslanou před začátkem vedení mezd vede jako '
                . 'podanou předchozím programem.',
                $result['benefit_claims'],
            ));
        }
        if ($result['unclassified'] !== []) {
            arsort($result['unclassified']);
            $top = [];
            foreach (array_slice($result['unclassified'], 0, 8, true) as $name => $count) {
                $top[] = "{$name} ({$count}×)";
            }
            $protocol->warn($step, 'sickness_unclassified', sprintf(
                'Nemocenské složky, které evidence MyÚčta nezná: %s. Číselník `sMZneprit` je vede '
                . 'jako dávku nemocenského, ale druh nepřítomnosti jim neodpovídá, takže se '
                . 'nepřevedly; zadejte je ručně v Mzdy → Nepřítomnosti.',
                implode(', ', $top),
            ));
        }
    }

    /**
     * Pracovní vztah podle osobního čísla z PAMICA.
     */
    private function employmentFor(int $supplierId, ?string $number): ?int
    {
        if ($number === null || $number === '') {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_employments WHERE supplier_id = ? AND code = ? ORDER BY id LIMIT 1'
        );
        $stmt->execute([$supplierId, $number]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @param callable():array<string,int> $work */
    private function part(ImportProtocol $protocol, string $step, string $number, string $label, callable $work): void
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }
        try {
            $counts = $work();
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
        } catch (PayrollAbsenceOverlapException $e) {
            $this->rollback($pdo, $owns);
            // Týž den už jinou nepřítomnost má: druhý zápis by evidenci rozdvojil.
            $protocol->count($step, 'sickness_overlap');
            $this->warn($protocol, $step, 'sickness_overlap',
                "Osobní číslo {$number}: {$label} se překrývá s jinou nepřítomností, okno náhrady "
                . 'nastavte ručně u té existující.', $number);
            return;
        } catch (\Exception $e) {
            $this->rollback($pdo, $owns);
            $protocol->count($step, 'sickness_failed');
            $this->warn($protocol, $step, 'sickness_failed', "Osobní číslo {$number}: {$label}: {$e->getMessage()}", $number);
            return;
        }
        foreach ($counts as $key => $value) {
            if ($value > 0) {
                $protocol->count($step, $key, $value);
            }
        }
    }

    private function rollback(\PDO $pdo, bool $owns): void
    {
        if ($owns) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return;
        }
        if ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
            $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
        }
    }

    private function warn(ImportProtocol $protocol, string $step, string $code, string $message, string $number): void
    {
        if ($this->messages++ < self::MESSAGE_LIMIT) {
            $protocol->warn($step, $code, $message, ['personal_number' => $number]);
        } elseif ($this->messages === self::MESSAGE_LIMIT + 1) {
            $protocol->warn($step, 'messages_truncated', 'Další upozornění k nemocenské protokol nevypisuje, jejich počet je v počtech kroku.');
        }
    }
}
