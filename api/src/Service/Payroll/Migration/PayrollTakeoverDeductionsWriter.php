<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollDeductionAgreementRepository;
use MyInvoice\Repository\Payroll\PayrollEnforcementRepository;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Payroll\Garnishment\InsolvencyMode;
use MyInvoice\Service\Payroll\Net\DeductionAgreementStatus;
use MyInvoice\Service\Payroll\Net\DeductionAgreementTerms;

/**
 * Zápis srážek, exekucí a insolvencí převzatých z předchozího mzdového systému, společný
 * pro všechny převody mezd (PAMICA, PREMIER).
 *
 * Každý záznam jde toutéž cestou jako ruční zadání v aplikaci: exekuce přes exekuční
 * případ a jeho pohledávku, dobrovolná srážka přes dohodu o srážkách, příjemce přes
 * katalog platebních účtů institucí. Vlastní SQL do těchto tabulek tu není.
 *
 * **Exekuce v tom stavu, v jakém ji vedl zdroj.** Případ s příjemcem se doloží převzatou
 * evidencí a běžící se zahájí, skončený zastaví ({@see PayrollTakeoverEnforcementActivation}).
 * „Přijato“ zůstane jen případ bez použitelného příjemce a běžící insolvence (oddlužení
 * vyžaduje rozhodnutí soudu); protokol spočítá, kolik případů na doložení čeká.
 *
 * **Jen doplnit, nepřepisovat.** Idempotenci nese mapa převodu zdroje
 * ({@see PayrollTakeoverDeductionMap}) pod stabilní referencí srážky, u dohod navíc
 * `agreement_reference`. Každý záznam má vlastní savepoint: co neprojde kontrolou domény,
 * se vrátí a důvod jde do protokolu s osobním číslem.
 *
 * Záznam srážky (čtečka zdroje): `reference`, `title`, `target` (`enforcement`,
 * `insolvency`, `voluntary` nebo `null` = nezařazená), `personal_numbers`, `person_key`,
 * `valid_from`, `valid_to`, `priority_date`, `priority_no`, `category`,
 * `maintenance_weight_minor`, `total_minor`, `outstanding_minor`, `monthly_minor`,
 * `dependants`, `deduction_kind`, `carried_by_attendance`, `recipient`, `periods`,
 * `evidence`. Texty protokolu nesou název zdroje z {@see PayrollTakeoverPolicy}.
 */
final class PayrollTakeoverDeductionsWriter
{
    private const MESSAGE_LIMIT = 40;
    private const SAVEPOINT = 'payroll_takeover_deductions';

    /**
     * Pásmo 1-9 v dohodách o srážkách patří zákonným a exekučním titulům
     * ({@see DeductionAgreementTerms::PRIORITY_FLOOR}), převod se do něj nevejde.
     */
    private const PRIORITY_BASE = DeductionAgreementTerms::PRIORITY_FLOOR;

    private int $messages = 0;
    private int $pendingEvidence = 0;
    /** @var list<string> exekuce, které zdroj v posledních měsících opravdu srážel */
    private array $withheldBefore = [];
    /** @var list<string> exekuce bez doložené srážky v posledních měsících */
    private array $notWithheld = [];
    private int $recipientsWithoutAccount = 0;
    private int $dependantsWritten = 0;
    /** @var array<string,int> druh srážky bez zařazení => počet */
    private array $unclassified = [];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEnforcementRepository $enforcement,
        private readonly PayrollDeductionAgreementRepository $agreements,
        private readonly PayrollInstitutionAccountRepository $institutions,
        private readonly PayrollTakeoverEnforcementActivation $activation,
    ) {}

    /**
     * @param array{deductions:list<array<string,mixed>>,protected_amount_inputs:int} $result
     */
    public function write(
        int $supplierId,
        ?int $userId,
        array $result,
        int $year,
        ImportProtocol $protocol,
        string $step,
        PayrollTakeoverPolicy $policy,
        PayrollTakeoverDeductionMap $map,
        ?int $runId = null,
    ): void {
        $this->messages = 0;
        $this->pendingEvidence = 0;
        $this->withheldBefore = [];
        $this->notWithheld = [];
        $this->recipientsWithoutAccount = 0;
        $this->dependantsWritten = 0;
        $this->unclassified = [];
        $done = $map->all($supplierId);
        $fromAttendance = 0;
        $label = $policy->label;

        foreach ($result['deductions'] as $record) {
            $title = (string) $record['title'];
            if ($record['target'] === null) {
                $this->unclassified[$title] = ($this->unclassified[$title] ?? 0) + 1;
                $protocol->count($step, 'deductions_unclassified');
                continue;
            }
            if ($record['carried_by_attendance'] === true) {
                $fromAttendance++;
                continue;
            }
            $reference = (string) $record['reference'];
            if (isset($done[$reference])) {
                $protocol->count($step, 'deductions_existing');
                // Případ z dřívějšího převodu, který zůstal „přijato“, opakovaný převod dovede
                // do stavu ze zdroje; jinak by ho účetní musela doložit ručně.
                if ($record['target'] !== 'voluntary' && $this->caseStatus($supplierId, (int) $done[$reference]) === 'received') {
                    $number = $record['personal_numbers'][0] ?? $record['person_key'];
                    $this->part($protocol, $step, (string) $number, "Exekuční případ „{$title}“",
                        fn (): array => $this->settle($supplierId, (int) $done[$reference], $record, $this->caseAccount($supplierId, (int) $done[$reference]), $userId, $policy));
                }
                continue;
            }
            $employment = $this->employmentFor($supplierId, (array) $record['personal_numbers']);
            $number = $record['personal_numbers'][0] ?? $record['person_key'];
            if ($employment === null) {
                $protocol->count($step, 'deductions_person_not_found');
                $this->warn($protocol, $step, 'deduction_person_not_found',
                    "Osobní číslo {$number}: srážku „{$title}“ nelze převzít, pracovní vztah s tímto kódem ve firmě není.", (string) $number);
                continue;
            }
            $employeeId = (int) $employment['employee_id'];
            if ($record['target'] === 'voluntary') {
                $this->part($protocol, $step, (string) $number, "Dohoda o srážkách „{$title}“",
                    fn (): array => $this->agreement($supplierId, $employeeId, $record, $reference, $userId, $runId, $policy, $map));
                continue;
            }
            $this->part($protocol, $step, (string) $number, "Exekuční případ „{$title}“",
                fn (): array => $this->enforcementCase($supplierId, $employeeId, $record, $reference, $year, $userId, $runId, $policy, $map, (string) $number));
        }
        $evidence = $this->startMonthEvidence($supplierId, $userId);
        if ($evidence > 0) {
            $protocol->count($step, 'enforcement_month_evidence', $evidence);
        }

        if ($fromAttendance > 0) {
            $protocol->count($step, 'deductions_from_attendance', $fromAttendance);
            $protocol->info($step, 'deductions_from_attendance', sprintf(
                'Dobrovolných srážek, které převod nezakládá znovu: %d. Jsou to srážky, které '
                . 'nese měsíční sešit a import docházky z nich dělá dohodu o srážkách za každý '
                . 'převedený měsíc; druhý zápis by je z čisté mzdy strhl dvakrát.',
                $fromAttendance,
            ));
        }
        if ($this->unclassified !== []) {
            arsort($this->unclassified);
            $top = [];
            foreach (array_slice($this->unclassified, 0, 8, true) as $name => $count) {
                $top[] = "{$name} ({$count}×)";
            }
            $protocol->warn($step, 'deductions_unclassified', sprintf(
                'Srážky, u kterých převod nepoznal druh: %s. Číselník srážek v %s k nim nemá '
                . 'položku, takže o exekuci ani o dohodě se nedá rozhodnout; zadejte je ručně '
                . 'v Mzdy → Exekuce a insolvence nebo Dohody o srážkách.',
                implode(', ', $top),
                $label,
            ));
        }
        if ($this->pendingEvidence > 0) {
            $protocol->warn($step, 'deductions_evidence_pending', sprintf(
                'Exekučních případů zůstává ve stavu „přijato“: %d. Jsou to běžící insolvence (oddlužení '
                . 'vyžaduje rozhodnutí soudu) a případy bez použitelného účtu příjemce; do mzdového běhu '
                . 'nevstoupí, dokud je v Mzdy → Exekuce a insolvence nedoložíte a neaktivujete.',
                $this->pendingEvidence,
            ));
        }
        if ($this->withheldBefore !== []) {
            $protocol->count($step, 'enforcement_withheld_by_source', count($this->withheldBefore));
            $protocol->info($step, 'enforcement_withheld_by_source', sprintf(
                'Exekuce, které %s v posledních měsících opravdu srážel (%d): %s.',
                $label,
                count($this->withheldBefore),
                implode('; ', array_slice($this->withheldBefore, 0, self::MESSAGE_LIMIT)),
            ));
        }
        if ($this->notWithheld !== []) {
            $protocol->count($step, 'enforcement_not_withheld_by_source', count($this->notWithheld));
            $protocol->info($step, 'enforcement_not_withheld_by_source', sprintf(
                'Exekuce bez srážky v posledních měsících %s (%d): %s. Může jít o nový příkaz, '
                . 'odklad nebo doplacenou pohledávku; ověřte stav proti spisu v Mzdy → Exekuce a insolvence.',
                $label,
                count($this->notWithheld),
                implode('; ', array_slice($this->notWithheld, 0, self::MESSAGE_LIMIT)),
            ));
        }
        if ($this->recipientsWithoutAccount > 0) {
            $protocol->warn($step, 'deductions_recipient_incomplete', sprintf(
                'Případů bez použitelného příjemce: %d. %s u nich nevede číslo účtu s platným '
                . 'kódem banky, takže platební cesta nemá kam poslat sraženou částku. Účet příjemce '
                . 'doplňte v Mzdy → Platební účty institucí a připněte ho k případu.',
                $this->recipientsWithoutAccount,
                $label,
            ));
        }
        if ($this->dependantsWritten > 0) {
            $protocol->info($step, 'deductions_dependants', sprintf(
                'Vyživovaných osob pro nezabavitelnou částku převzato: %d. %s vede jen jejich '
                . 'počet, ne kdo to je, takže se zapisují jako vyživované osoby bez rozlišení '
                . 'manžela nebo partnera a bez ověření nároku.',
                $this->dependantsWritten,
                $label,
            ));
        }
        if ($result['protected_amount_inputs'] > 0) {
            $protocol->warn($step, 'deductions_protected_amount_inputs', sprintf(
                'Podklady pro nezabavitelnou částku vede %s u %d osob (jiné příjmy povinného). '
                . 'MyÚčto u měsíce eviduje jen přebití nezabavitelné částky, ne vstupy, ze kterých '
                . 'ji jiný program počítal, takže se nepřevádějí; u dotčených osob je zadejte ručně.',
                $label,
                $result['protected_amount_inputs'],
            ));
        }
    }

    /**
     * Exekuční nebo insolvenční případ s jednou pohledávkou.
     *
     * @param array<string,mixed> $record
     * @return array<string,int>
     */
    private function enforcementCase(int $supplierId, int $employeeId, array $record, string $reference, int $year, ?int $userId, ?int $runId,
        PayrollTakeoverPolicy $policy, PayrollTakeoverDeductionMap $map, string $number = ''): array
    {
        $from = is_string($record['valid_from']) ? $record['valid_from'] : sprintf('%04d-01-01', $year);
        $case = $this->enforcement->createCase($supplierId, $employeeId, 'enforcement', $from, $userId);
        $caseId = (int) $case['id'];
        /*
         * Evidenční příznaky zůstávají nevyplněné - viz hlavička třídy. Datum pořadí jde do
         * dne doručení prvnímu plátci mzdy: MyÚčto z něj podle § 280 odst. 3 o. s. ř.
         * odvozuje pořadí pohledávky a vlastní `priority_date` od volajícího nepřijímá.
         */
        $this->enforcement->addClaim($supplierId, $caseId, [
            'legal_basis' => 'statutory',
            'category' => $record['category'],
            'outstanding_minor_units' => $record['outstanding_minor'],
            'maintenance_weight_minor_units' => $record['maintenance_weight_minor'],
            'first_payer_delivered_on' => $record['priority_date'],
            'order_issued_on' => null,
            'legal_title_verified' => false,
            'order_or_notice_delivered' => false,
            'priority_classification_verified' => false,
            'agreement_verified' => false,
            'due_monetary_claim_verified' => false,
        ]);
        // Jen zdroj, který srážky po měsících vede (`withheld_periods`); u jiného by každý
        // případ vyšel jako „nesráženo“.
        if ($record['target'] === 'enforcement' && array_key_exists('withheld_periods', $record)) {
            $last = self::withheldRecently($record);
            $case = trim($number . ' ' . $record['title']);
            if ($last === null) {
                $this->notWithheld[] = $case;
            } else {
                $this->withheldBefore[] = "{$case}, naposledy {$last}";
            }
        }
        $counts = ['enforcement_cases' => 1];
        if ($record['target'] === 'insolvency') {
            $counts['insolvency_months'] = $this->insolvencyAlerts($supplierId, $employeeId, $record, $userId);
        }
        [$recipientCounts, $paymentAccountId] = $this->recipient($supplierId, $caseId, $record, $from, $userId, $policy);
        $counts += $recipientCounts;
        $counts += $this->dependants($supplierId, $employeeId, $record, $from);
        $counts += $this->settle($supplierId, $caseId, $record, $paymentAccountId, $userId, $policy);
        $map->put($supplierId, $reference, $caseId, $runId);

        return $counts;
    }

    /**
     * Stav případu podle zdroje ({@see PayrollTakeoverEnforcementActivation}). Běžící
     * insolvence se nezahajuje (viz insolvencyAlerts()), skončenou ale převod zastaví.
     *
     * @param array<string,mixed> $record
     * @return array<string,int>
     */
    private function settle(int $supplierId, int $caseId, array $record, ?int $paymentAccountId, ?int $userId, PayrollTakeoverPolicy $policy): array
    {
        $state = $record['target'] === 'insolvency'
            && PayrollTakeoverEnforcementActivation::decide($record, $this->activation->startPeriod($supplierId))['action'] === 'activate'
            ? PayrollTakeoverEnforcementActivation::PENDING
            : $this->activation->apply($supplierId, $caseId, $record, $paymentAccountId, $userId, $policy);
        if ($state === PayrollTakeoverEnforcementActivation::PENDING) {
            $this->pendingEvidence++;
            return [];
        }

        return ['enforcement_' . $state => 1];
    }

    /**
     * Měsíční exekuční evidence za první měsíc vedení mezd u osob, jejichž případy převod
     * zahájil: rejstřík pohledávek je úplný (zdroj vedl všechny srážky osoby a převod je
     * převzal), vyživované osoby a manžel jsou doložené počtem ze zdroje, důchod osoba
     * nepobírá (zdroj by ho vedl jako jiný příjem). Insolvence zůstává v režimu upozornění
     * s rozhodnutím, které zdroj vykonával. Evidenci, kterou už někdo zadal, převod nemění.
     */
    private function startMonthEvidence(int $supplierId, ?int $userId): int
    {
        $start = $this->activation->startPeriod($supplierId);
        if ($start === null) {
            return 0;
        }
        $period = substr($start, 0, 7);
        $stmt = $this->db->pdo()->prepare(
            "SELECT employee_id FROM payroll_enforcement_cases
              WHERE supplier_id = ? AND status IN ('withhold_and_hold', 'remit', 'deferred_hold')
             UNION
             SELECT employee_id FROM payroll_enforcement_person_month_evidence
              WHERE supplier_id = ? AND period_start = ? AND insolvency_mode = 'alert_only'"
        );
        $stmt->execute([$supplierId, $supplierId, $period . '-01']);
        $written = 0;
        foreach (array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)) as $employeeId) {
            $current = $this->enforcement->monthEvidence($supplierId, $employeeId, $period);
            if ($current['row_version'] !== null && !in_array($current['insolvency_mode'] ?? null, [InsolvencyMode::AlertOnly->value], true)) {
                continue;
            }
            $this->enforcement->saveMonthEvidence($supplierId, $employeeId, $period, [
                'claim_register_evidence_complete' => true,
                'dependants_evidence_complete' => true,
                'spouse_evidence_complete' => true,
                'pension_evidence' => (string) $current['pension_evidence'] === 'unknown' ? 'none' : (string) $current['pension_evidence'],
                'has_multiple_payers' => (bool) $current['has_multiple_payers'],
                'protected_amount_override_minor_units' => $current['protected_amount_override_minor_units'],
                'protected_amount_override_verified' => (bool) $current['protected_amount_override_verified'],
                'insolvency_mode' => (string) $current['insolvency_mode'],
                'insolvency_decision_verified' => (string) $current['insolvency_mode'] !== InsolvencyMode::None->value,
                'insolvency_recipient_verified' => false,
                'court_determined_amount_minor_units' => null,
            ], $userId, $current['row_version'] === null ? null : (int) $current['row_version']);
            $written++;
        }

        return $written;
    }

    private function caseStatus(int $supplierId, int $caseId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM payroll_enforcement_cases WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $caseId]);
        $status = $stmt->fetchColumn();

        return is_string($status) ? $status : null;
    }

    /** Účet příjemce připnutého k případu (katalog institucí), nebo `null`. */
    private function caseAccount(int $supplierId, int $caseId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT account.id
               FROM payroll_enforcement_cases c
               JOIN payroll_institution_accounts account
                 ON account.supplier_id = c.supplier_id AND account.institution_id = c.recipient_institution_id
              WHERE c.supplier_id = ? AND c.id = ?
              ORDER BY account.valid_from, account.id
              LIMIT 1',
        );
        $stmt->execute([$supplierId, $caseId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Poslední měsíc, ve kterém zdroj exekuci opravdu srazil, pokud tím prokazatelně
     * srážel až do konce převáděných dat: kladná srážka v některém ze tří posledních
     * zpracovaných měsíců, platnost neskončila a pohledávka není doplacená (nulová celková
     * výše = běžné výživné bez stanovené částky). Jinak `null` - nový příkaz, odklad nebo
     * doplacená pohledávka, o které rozhodne účetní proti spisu.
     *
     * @param array<string,mixed> $record
     */
    public static function withheldRecently(array $record): ?string
    {
        $withheld = array_values(array_filter((array) ($record['withheld_periods'] ?? []), 'is_string'));
        $sourceLast = $record['source_last_period'] ?? null;
        if ($withheld === [] || !is_string($sourceLast) || preg_match('/^\d{4}-\d{2}$/', $sourceLast) !== 1) {
            return null;
        }
        $last = max($withheld);
        $monthIndex = static fn (string $period): int => (int) substr($period, 0, 4) * 12 + (int) substr($period, 5, 2);
        if ($monthIndex($sourceLast) - $monthIndex($last) > 2) {
            return null;
        }
        $validTo = $record['valid_to'] ?? null;
        if (is_string($validTo) && $validTo < $sourceLast . '-01') {
            return null;
        }
        if ((int) ($record['total_minor'] ?? 0) > 0 && (int) ($record['outstanding_minor'] ?? 0) <= 0) {
            return null;
        }

        return $last;
    }

    /**
     * Příjemce srážky: záznam v katalogu platebních účtů institucí typu „ostatní příjemce“
     * připnutý k případu. Účet se zakládá jako `imported` a ověření příjemce na případu
     * zůstává nevyplněné - platební cesta ho tím pádem sama nepoužije.
     *
     * @param array<string,mixed> $record
     * @return array{0:array<string,int>,1:?int} počty a účet příjemce v katalogu institucí
     */
    private function recipient(int $supplierId, int $caseId, array $record, string $from, ?int $userId, PayrollTakeoverPolicy $policy): array
    {
        $recipient = $record['recipient'];
        if (!is_array($recipient) || !is_string($recipient['account']) || !is_string($recipient['bank_code'])) {
            $this->recipientsWithoutAccount++;
            return [[], null];
        }
        // Identita instituce: IČO, kde ho zdroj má, jinak stabilní otisk jména a účtu.
        // Na jednom exekutorském úřadu visí víc případů a katalog má mít jeden záznam.
        $code = is_string($recipient['ico'])
            ? 'ico:' . $recipient['ico']
            : $policy->sourceKey . ':' . substr(hash('sha256', $recipient['name'] . '|' . $recipient['account'] . '/' . $recipient['bank_code']), 0, 24);
        $institutionId = null;
        $accountId = null;
        foreach ($this->institutions->list($supplierId) as $account) {
            if (($account['institution_type'] ?? null) === 'other_recipient' && ($account['institution_code'] ?? null) === $code) {
                $institutionId = (int) $account['institution_id'];
                $accountId = (int) $account['id'];
                break;
            }
        }
        $counts = [];
        if ($institutionId === null) {
            $created = $this->institutions->create($supplierId, [
                'institution_type' => 'other_recipient',
                'institution_code' => $code,
                'institution_name' => $recipient['name'],
                'bank_account' => $recipient['account'] . '/' . $recipient['bank_code'],
                'currency_code' => 'CZK',
                'variable_symbol' => $recipient['variable_symbol'],
                'specific_symbol' => $recipient['specific_symbol'],
                'constant_symbol' => $recipient['constant_symbol'],
                'valid_from' => $from,
                'valid_to' => null,
                'source_kind' => 'imported',
                'source_reference' => mb_substr($policy->note('příjemce srážky'
                    . (is_string($recipient['reference']) ? ', rozhodnutí ' . $recipient['reference'] : '')), 0, 500),
                'verified_on' => date('Y-m-d'),
            ], $userId);
            $institutionId = (int) $created['institution_id'];
            $accountId = (int) $created['id'];
            $counts['recipient_accounts'] = 1;
        }
        $current = $this->enforcement->findCase($supplierId, $caseId)
            ?? throw new \DomainException('exekuční případ nebyl po založení nalezen.');
        $this->enforcement->updateCaseEvidence(
            $supplierId,
            $caseId,
            false,
            false,
            (int) $current['row_version'],
            $userId,
            $institutionId,
            true,
        );

        return [$counts + ['recipients' => 1], $accountId];
    }

    /**
     * Vyživované osoby pro nezabavitelnou částku. Zdroj vede jen jejich počet, ne jména ani
     * vztah, takže se zapisují bez rozlišení a bez ověření nároku. Osobě, která už
     * vyživované osoby v MyÚčtu má, se nic nepřidává - druhý případ téhož zaměstnance by je
     * jinak započetl znovu a nezabavitelná částka by vyšla vyšší, než na jakou má nárok.
     *
     * @param array<string,mixed> $record
     * @return array<string,int>
     */
    private function dependants(int $supplierId, int $employeeId, array $record, string $from): array
    {
        $wanted = (int) $record['dependants'];
        if ($wanted <= 0 || $this->enforcement->dependantsForEmployee($supplierId, $employeeId) !== []) {
            return [];
        }
        for ($i = 0; $i < $wanted; $i++) {
            $this->enforcement->addDependant($supplierId, $employeeId, [
                'dependant_kind' => 'dependant',
                'valid_from' => $from,
                'valid_to' => null,
                'eligibility_verified' => false,
                'excluded_for_maintenance' => false,
            ]);
            $this->dependantsWritten++;
        }

        return ['dependants' => $wanted];
    }

    /**
     * Upozornění na insolvenci u měsíců, ve kterých se ve zdroji srážela.
     *
     * Vyšší režim než `alert_only` převod nastavit nesmí: schválené oddlužení podle
     * § 406 insolvenčního zákona vyžaduje ověřené rozhodnutí i příjemce a teprve z nich
     * vzniká neměnný platební pokyn na účet insolvenčního správce. Ten se nedá vyrobit
     * z exportu jiného programu; účetní ho zadá proti rozhodnutí soudu.
     *
     * @param array<string,mixed> $record
     */
    private function insolvencyAlerts(int $supplierId, int $employeeId, array $record, ?int $userId): int
    {
        $written = 0;
        foreach ((array) $record['periods'] as $period) {
            $current = $this->enforcement->monthEvidence($supplierId, $employeeId, (string) $period);
            if (($current['insolvency_mode'] ?? null) !== InsolvencyMode::None->value) {
                continue;
            }
            $this->enforcement->saveMonthEvidence($supplierId, $employeeId, (string) $period, [
                'claim_register_evidence_complete' => (bool) $current['claim_register_evidence_complete'],
                'dependants_evidence_complete' => (bool) $current['dependants_evidence_complete'],
                'spouse_evidence_complete' => (bool) $current['spouse_evidence_complete'],
                'pension_evidence' => (string) $current['pension_evidence'],
                'has_multiple_payers' => (bool) $current['has_multiple_payers'],
                'protected_amount_override_minor_units' => $current['protected_amount_override_minor_units'],
                'protected_amount_override_verified' => (bool) $current['protected_amount_override_verified'],
                'insolvency_mode' => InsolvencyMode::AlertOnly->value,
                'insolvency_decision_verified' => false,
                'insolvency_recipient_verified' => false,
                'court_determined_amount_minor_units' => null,
            ], $userId, $current['row_version'] === null ? null : (int) $current['row_version']);
            $written++;
        }

        return $written;
    }

    /**
     * Dohoda o srážkách ze mzdy (§ 146 písm. b) zákoníku práce) z trvalé srážky na kartě.
     *
     * @param array<string,mixed> $record
     * @return array<string,int>
     */
    private function agreement(int $supplierId, int $employeeId, array $record, string $reference, ?int $userId, ?int $runId,
        PayrollTakeoverPolicy $policy, PayrollTakeoverDeductionMap $map): array
    {
        $existing = $this->agreements->findByReference($supplierId, $employeeId, $reference);
        if ($existing !== null) {
            $map->put($supplierId, $reference, (int) $existing['id'], $runId);
            return ['deduction_agreements_existing' => 1];
        }
        $requested = (int) $record['monthly_minor'];
        $validTo = is_string($record['valid_to']) ? $record['valid_to'] : null;
        $delivered = is_string($record['priority_date']) ? $record['priority_date'] : null;
        if ($delivered !== null && $validTo !== null && $delivered > $validTo) {
            $delivered = null;
        }
        $recipient = is_array($record['recipient']) ? $record['recipient'] : null;
        $terms = DeductionAgreementTerms::fromRequest([
            'agreement_reference' => $reference,
            'title' => $record['title'],
            'deduction_kind' => $record['deduction_kind'],
            // Pořadí z karty zdroje posunuté nad pásmo vyhrazené zákonným titulům.
            'priority_no' => min(DeductionAgreementTerms::PRIORITY_CEILING, self::PRIORITY_BASE + max(0, (int) $record['priority_no'])),
            'requested_minor' => $requested,
            'total_limit_minor' => (int) $record['total_minor'] > 0 ? (int) $record['total_minor'] : null,
            'valid_from' => $record['valid_from'],
            'valid_to' => $validTo,
            'delivered_on' => $delivered,
            'recipient_reference' => $recipient === null ? null : mb_substr(
                $recipient['name'] . (is_string($recipient['account']) ? ', ' . $recipient['account'] . '/' . $recipient['bank_code'] : ''),
                0,
                190,
            ),
            'note' => mb_substr($policy->note('trvalá srážka ' . $record['title']
                . ' (' . implode(', ', (array) $record['evidence']) . ').'), 0, 500),
        ]);
        /*
         * Bez měsíční částky by aktivní dohoda každý měsíc srazila nulu a vypadala přitom
         * jako vyřízená. Zdroj takovou srážku vede třeba procentem ze základu, který
         * export nenese, takže dohoda vzniká jako návrh k doplnění účetní.
         */
        $status = $requested > 0 ? DeductionAgreementStatus::Active : DeductionAgreementStatus::Draft;
        $agreement = $this->agreements->create($supplierId, $employeeId, $terms, $status, $userId);
        $map->put($supplierId, $reference, (int) $agreement['id'], $runId);

        return $requested > 0
            ? ['deduction_agreements' => 1]
            : ['deduction_agreements' => 1, 'deduction_agreements_draft' => 1];
    }

    /**
     * Pracovní vztah podle osobních čísel osoby ze zdroje; srážky patří zaměstnanci,
     * takže stačí první vztah, který ve firmě je.
     *
     * @param list<string> $numbers
     * @return array<string,mixed>|null
     */
    private function employmentFor(int $supplierId, array $numbers): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, employee_id FROM payroll_employments WHERE supplier_id = ? AND code = ? ORDER BY id LIMIT 1'
        );
        foreach ($numbers as $number) {
            $stmt->execute([$supplierId, $number]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row !== false) {
                return $row;
            }
        }

        return null;
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
        } catch (\Exception $e) {
            if ($owns) {
                $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
            // Zápis se vrátil celý, takže po pádu nezůstane případ bez pohledávky ani
            // dohoda bez záznamu v mapě převodu.
            $protocol->count($step, 'deductions_failed');
            $this->warn($protocol, $step, 'deduction_failed', "Osobní číslo {$number}: {$label}: {$e->getMessage()}", $number);
            return;
        }
        foreach ($counts as $key => $value) {
            if ($value > 0) {
                $protocol->count($step, $key, $value);
            }
        }
    }

    private function warn(ImportProtocol $protocol, string $step, string $code, string $message, string $number): void
    {
        if ($this->messages++ < self::MESSAGE_LIMIT) {
            $protocol->warn($step, $code, $message, ['personal_number' => $number]);
        } elseif ($this->messages === self::MESSAGE_LIMIT + 1) {
            $protocol->warn($step, 'messages_truncated', 'Další upozornění ke srážkám protokol nevypisuje, jejich počet je v počtech kroku.');
        }
    }
}
