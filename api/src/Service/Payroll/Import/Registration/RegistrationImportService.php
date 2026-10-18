<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Registration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Service\License\LicensePayrollLimitExceeded;
use MyInvoice\Service\Payroll\Import\ImportFiles;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzAveragePlanner;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzBatch;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzBatchItem;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzDerivedRegistrations;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzEmploymentHistory;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzExternalSubmissionStore;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzOpeningBalancePlanner;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportFile;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportForm;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportLookup;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportPlanner;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportReader;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportWriter;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzTakeoverPlanner;
use MyInvoice\Service\Payroll\Import\Ozuspoj\OzuspojImportPlanner;
use MyInvoice\Service\Payroll\Import\Ozuspoj\OzuspojPredecessorFile;
use MyInvoice\Service\Payroll\Import\Ozuspoj\OzuspojPredecessorIntentImporter;
use MyInvoice\Service\Payroll\Import\Ozuspoj\OzuspojXmlReader;
use MyInvoice\Service\Payroll\Import\Sickness\SicknessDocumentXmlReader;
use MyInvoice\Service\Payroll\Import\Sickness\SicknessImportPlanner;
use MyInvoice\Service\Payroll\Import\Sickness\SicknessImportRecord;
use MyInvoice\Service\Payroll\Import\Sickness\SicknessImportWriter;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojException;

/**
 * Import registrací ČSSZ (REGZEC25, PREZEC26), exportu zaměstnanců z ePortálu
 * ČSSZ, měsíčních hlášení JMHZ a podání NEMPRI, HZUPN a OZUSPOJ jiného
 * mzdového programu do mzdové evidence: náhled a použití vybraných vět.
 *
 * Soubor se rozpozná podle kořenového elementu; v jedné dávce mohou být
 * registrace i hlášení za víc měsíců. Export zaměstnanců datum nástupu nenese,
 * dosadí se z hlášení téže dávky ({@see CsszExportStartResolver}).
 *
 * NEMPRI a HZUPN předchozího programu ({@see SicknessImportPlanner}) se do
 * evidence zapíší jako podání vyřízená předchozím programem, OZUSPOJ jako
 * převzatý záměr slevy ({@see OzuspojPredecessorIntentImporter}); nic se
 * znovu neodesílá. Zapisují se až po registracích a hlášeních, aby viděly
 * osoby a vztahy založené v téže dávce.
 *
 * Náhled nic nezapisuje. Použití si soubory přečte a každou vybranou větu či
 * formulář naplánuje znovu nad AKTUÁLNÍM stavem evidence těsně před zápisem —
 * věty se tak navzájem vidí (přihlášení v jednom souboru, hlášení téže osoby
 * v druhém) a klientovi se nevěří nic kromě seznamu vybraných klíčů a ručního
 * párování formulářů s pracovními vztahy. Počáteční stavy a průměry se
 * počítají až po zápisu formulářů, aby viděly identifikátory, které právě
 * vznikly.
 */
final class RegistrationImportService
{
    private const ENVIRONMENTS = ['production', 'test'];
    private const KEY_PATTERN = '/^[0-9a-f]{16}:[0-9]{1,5}$/D';
    private const AUTO_CHANGE_NOTE = 'Odškrtnuto importem: změnu už vykázalo importované podání.';
    /** Pořadí vět odvozených z hlášení v náhledu: za všemi nahranými soubory. */
    private const DERIVED_FILE_INDEX = 99_999;

    public function __construct(
        private readonly RegistrationXmlReader $reader,
        private readonly RegistrationImportPlanner $planner,
        private readonly RegistrationImportWriter $writer,
        private readonly JmhzReportReader $jmhzReader,
        private readonly JmhzReportPlanner $jmhzPlanner,
        private readonly JmhzReportWriter $jmhzWriter,
        private readonly JmhzOpeningBalancePlanner $openingPlanner,
        private readonly JmhzAveragePlanner $averagePlanner,
        private readonly JmhzTakeoverPlanner $takeoverPlanner,
        private readonly JmhzReportLookup $jmhzLookup,
        private readonly PayrollEmploymentRepository $employments,
        private readonly RegistrationImportLookup $lookup,
        private readonly JmhzExternalSubmissionStore $externalSubmissions,
        private readonly SicknessDocumentXmlReader $sicknessReader,
        private readonly SicknessImportPlanner $sicknessPlanner,
        private readonly SicknessImportWriter $sicknessWriter,
        private readonly OzuspojXmlReader $ozuspojReader,
        private readonly OzuspojImportPlanner $ozuspojPlanner,
        private readonly OzuspojPredecessorIntentImporter $ozuspojImporter,
        private readonly Connection $db,
    ) {}

    /** @return array<string,mixed> */
    public function preview(
        int $supplierId,
        string $environment,
        mixed $files,
        mixed $pairs = null,
        mixed $relationTypes = null,
        mixed $terminations = null,
        mixed $receivedOn = null,
    ): array {
        $this->environment($environment);
        $pairMap = $this->pairs($pairs);
        $relationTypeMap = $this->relationTypes($relationTypes);
        $terminationKeys = $this->terminations($terminations);
        $receivedMap = $this->receivedOn($receivedOn);
        $read = $this->read($supplierId, $files);
        $records = [];
        $registrationPlans = [];
        $pairs = self::noShowPairs($read['registrations'], null);
        foreach ($read['registrations'] as $item) {
            $key = RegistrationImportPlanner::key($item['sha256'], $item['record']->position);
            $plan = $this->planner->plan(
                $supplierId,
                $environment,
                $item['record'],
                $item['file'],
                $item['sha256'],
                $relationTypeMap[$key] ?? null,
                isset($terminationKeys[$key]),
                isset($pairs['defer'][$key]),
                isset($pairs['follow'][$key]),
            );
            $registrationPlans[] = $plan;
            // Odvozená věta vztahu, který evidence už vede beze změny, by náhled
            // jen zahltila — zůstane, jen když má co zapsat nebo na co upozornit.
            if ($item['record']->isJmhzDerived() && $plan['operation'] === 'none' && $plan['blocker'] === null) {
                continue;
            }
            $records[$this->order($item['file_index'], $item['record']->position)] = self::publicPlan($plan);
        }
        $jmhzPlans = $this->planJmhz($supplierId, $environment, $read['batch'], $pairMap);
        $this->hintExportPairing($jmhzPlans, $registrationPlans);
        foreach ($jmhzPlans as $plan) {
            /** @var JmhzBatchItem $item */
            $item = $plan['_item'];
            $records[$this->order($item->fileIndex, $item->form->position)] = self::publicPlan($plan);
        }
        foreach ($read['sickness'] as $item) {
            $key = RegistrationImportPlanner::key($item['sha256'], $item['record']->position);
            $records[$this->order($item['file_index'], $item['record']->position)] = self::publicPlan(
                $this->sicknessPlanner->plan(
                    $supplierId,
                    $environment,
                    $item['record'],
                    $item['file'],
                    $item['sha256'],
                    $receivedMap[$key] ?? null,
                ),
            );
        }
        foreach ($read['ozuspoj'] as $item) {
            $key = RegistrationImportPlanner::key($item['sha256'], 1);
            $records[$this->order($item['file_index'], 1)] = self::publicPlan(
                $this->ozuspojPlanner->plan(
                    $supplierId,
                    $environment,
                    $item['predecessor'],
                    $item['file'],
                    1,
                    $receivedMap[$key] ?? null,
                ),
            );
        }
        ksort($records, SORT_STRING);
        $records = array_values($records);

        return [
            'environment' => $environment,
            'files' => $read['files'],
            'records' => $records,
            'summary' => $this->summary($records),
            'employment_options' => $read['has_jmhz'] ? $this->jmhzLookup->employmentOptions($supplierId) : [],
            'opening_balances' => $read['has_jmhz']
                ? $this->openingPlanner->preview($supplierId, array_values($jmhzPlans), $read['batch'])
                : [],
            'averages' => $read['has_jmhz']
                ? $this->averagePlanner->preview($supplierId, array_values($jmhzPlans))
                : [],
            'takeover' => $read['has_jmhz']
                ? $this->takeoverPlanner->preview($supplierId, array_values($jmhzPlans), $read['batch'])
                : null,
        ];
    }

    /**
     * @param mixed $keys
     * @return array{
     *   results:list<array<string,mixed>>,
     *   summary:array{applied:int,failed:int,skipped:int},
     *   opening_balances:array{saved:int,skipped:list<array<string,mixed>>},
     *   averages:array{created:int,approved:int,skipped:list<array<string,mixed>>},
     *   takeover:?array<string,mixed>,
     *   change_checklist:array{completed:int,failed:list<array{employment_id:int,item_key:string,message:string}>},
     *   outcome:string,
     *   unresolved:list<array{key:string,file:string,period:string,label:string,reason:string}>
     * }
     *
     * `outcome` je `incomplete`, když některá věta selhala nebo když v dávce zůstal
     * platný formulář hlášení bez pracovního vztahu či zablokovaný (`unresolved`).
     * Zapsané věty zůstávají zapsané — výsledek ale nesmí vypadat jako hotový
     * import, když z dávky část nepřešla.
     */
    public function apply(
        int $supplierId,
        string $environment,
        mixed $files,
        mixed $keys,
        bool $evidenceConfirmed,
        ?int $officeId,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
        mixed $pairs = null,
        bool $applyOpeningBalances = false,
        bool $applyAverages = false,
        bool $autoApproveChanges = false,
        bool $autoApproveAverages = false,
        bool $applyTakeover = false,
        mixed $relationTypes = null,
        mixed $terminations = null,
        mixed $receivedOn = null,
        bool $mayWriteSubmissions = false,
    ): array {
        $this->environment($environment);
        $relationTypeMap = $this->relationTypes($relationTypes);
        $terminationKeys = $this->terminations($terminations);
        $receivedMap = $this->receivedOn($receivedOn);
        if (!$evidenceConfirmed) {
            throw new \InvalidArgumentException(
                'Potvrďte, že soubory odpovídají podáním přijatým ČSSZ. Import podle nich zapisuje '
                . 'identifikátory OIČ a ID PPV jako ověřený opis, stejně jako ruční zadání z protokolu.',
            );
        }
        if (!is_array($keys) || !array_is_list($keys)) {
            throw new \InvalidArgumentException('Vyberte aspoň jednu větu, která se má zapsat.');
        }
        if ($keys === [] && !$applyOpeningBalances && !$applyAverages && !$applyTakeover) {
            throw new \InvalidArgumentException('Vyberte aspoň jednu větu, která se má zapsat.');
        }
        $selected = [];
        foreach ($keys as $key) {
            if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new \InvalidArgumentException('Seznam vybraných vět obsahuje neplatný klíč.');
            }
            $selected[$key] = true;
        }
        $pairMap = $this->pairs($pairs);
        if ($officeId !== null && $officeId <= 0) {
            throw new \InvalidArgumentException('Mzdová účtárna není platná.');
        }

        $read = $this->read($supplierId, $files);
        $byKey = [];
        foreach ($read['registrations'] as $item) {
            $byKey[RegistrationImportPlanner::key($item['sha256'], $item['record']->position)] = $item;
        }
        $ordered = [];
        foreach (array_keys($selected) as $key) {
            if (isset($byKey[$key])) {
                $ordered[] = $byKey[$key];
            }
        }
        // Nenastoupení musí jít po přihlášce téže osoby, i když má dřívější datum.
        $pairs = self::noShowPairs($read['registrations'], $selected);
        $sortDate = static function (array $item) use ($pairs): string {
            $key = RegistrationImportPlanner::key($item['sha256'], $item['record']->position);
            $date = $item['record']->decisiveDate() ?? '9999-12-31';

            return isset($pairs['after'][$key]) && $pairs['after'][$key] > $date ? $pairs['after'][$key] : $date;
        };
        // Přihlášení musí jít před změnou a odhlášením téže osoby, i když
        // leží v souborech v jiném pořadí.
        usort($ordered, static fn (array $a, array $b): int => [
            $sortDate($a),
            $a['record']->documentType === 'PREZEC26' ? 0 : 1,
            isset($pairs['after'][RegistrationImportPlanner::key($a['sha256'], $a['record']->position)]) ? 1 : 0,
            $a['file_index'],
            $a['record']->position,
        ] <=> [
            $sortDate($b),
            $b['record']->documentType === 'PREZEC26' ? 0 : 1,
            isset($pairs['after'][RegistrationImportPlanner::key($b['sha256'], $b['record']->position)]) ? 1 : 0,
            $b['file_index'],
            $b['record']->position,
        ]);

        $changesSince = $autoApproveChanges ? $this->employments->databaseNow() : null;
        $sicknessByKey = [];
        foreach ($read['sickness'] as $item) {
            $sicknessByKey[RegistrationImportPlanner::key($item['sha256'], $item['record']->position)] = $item;
        }
        $ozuspojByKey = [];
        foreach ($read['ozuspoj'] as $item) {
            $ozuspojByKey[RegistrationImportPlanner::key($item['sha256'], 1)] = $item;
        }
        $results = [];
        foreach (array_keys($selected) as $key) {
            if (!isset($byKey[$key]) && !isset($sicknessByKey[$key]) && !isset($ozuspojByKey[$key])
                && $read['batch']->item($key) === null
            ) {
                $results[$key] = $this->result($key, 'skipped', 'Věta s tímto klíčem v nahraných souborech není. '
                    . 'Nahrajte soubory znovu a obnovte náhled.');
            }
        }
        foreach ($ordered as $item) {
            $recordKey = RegistrationImportPlanner::key($item['sha256'], $item['record']->position);
            $result = $this->applyRegistration(
                $supplierId,
                $environment,
                $item,
                $officeId,
                $userId,
                $ip,
                $userAgent,
                $relationTypeMap,
                $terminationKeys,
                isset($pairs['defer'][$recordKey]),
                isset($pairs['follow'][$recordKey]),
            );
            $results[(string) $result['key']] = $result;
        }

        $batch = $read['batch'];
        $jmhzSelected = array_values(array_filter(
            $batch->items(),
            static fn (JmhzBatchItem $item): bool => isset($selected[$item->key]),
        ));
        usort($jmhzSelected, static fn (JmhzBatchItem $a, JmhzBatchItem $b): int => $a->sortKey() <=> $b->sortKey());
        // Konflikty (dva formuláře na tentýž vztah a měsíc) se určí jednou
        // nad celou dávkou; jednotlivé formuláře se pak plánují znovu těsně
        // před zápisem. Formuláře, které nic nezapíšou, sdílejí načtenou
        // evidenci; po každém zápisu se další formulář plánuje nad čerstvým stavem.
        $this->jmhzPlanner->batch(function () use ($supplierId, $environment, $jmhzSelected, $batch, $pairMap, $userId, $ip, $userAgent, &$results): void {
            $this->planJmhz($supplierId, $environment, $batch, $pairMap);
            foreach ($jmhzSelected as $item) {
                $plan = $this->jmhzPlanner->plan($supplierId, $environment, $item, $batch, $pairMap[$item->key] ?? null);
                $key = $item->key;
                if ($plan['blocker'] !== null) {
                    $results[$key] = $this->result($key, 'skipped', (string) $plan['blocker'], $plan);
                    continue;
                }
                if ($plan['operation'] === 'pair_required') {
                    $results[$key] = $this->result($key, 'skipped', 'Formulář není spárovaný s pracovním vztahem. '
                        . 'Vyberte vztah, ke kterému patří, a použití zopakujte.', $plan);
                    continue;
                }
                if ($plan['operation'] === 'create_employment') {
                    $results[$key] = $this->result($key, 'skipped', 'Formulář patří dalšímu pracovnímu vztahu osoby, '
                        . 'který v evidenci zatím není. Vyberte spolu s ním i větu „Vztah doložený měsíčními hlášeními '
                        . 'JMHZ“ (nebo export zaměstnanců), která vztah založí, a použití zopakujte.', $plan);
                    continue;
                }
                if (!$plan['selectable']) {
                    $results[$key] = $this->result(
                        $key,
                        'skipped',
                        $batch->note($key) ?? 'Formulář nemá co zapsat — evidence už odpovídá.',
                        $plan,
                    );
                    continue;
                }
                $outer = $this->db->pdo()->inTransaction();
                try {
                    $applied = $this->jmhzWriter->apply($supplierId, $environment, $plan, $userId, $ip, $userAgent);
                    $results[$key] = ['key' => $key] + $applied;
                } catch (\Exception $e) {
                    $this->rethrowOuterAbort($outer, $e);
                    $results[$key] = $this->result($key, 'failed', $e->getMessage(), $plan);
                } finally {
                    $this->jmhzPlanner->forget();
                }
            }
        });

        // Podání dávek a záměry slevy předchozího programu jdou až po registracích
        // a hlášeních: osoba a vztah, ke kterým patří, mohla vzniknout v téže dávce.
        foreach (array_keys($selected) as $key) {
            if (!$mayWriteSubmissions && (isset($sicknessByKey[$key]) || isset($ozuspojByKey[$key]))) {
                // Podání dávek a záměry slevy jinde chrání právo `payroll.submissions`;
                // import je nesmí obejít právem na osoby. Náhled je ukáže, zápis ne.
                $results[$key] = $this->result(
                    $key,
                    'skipped',
                    'Zápis podání dávek a záměrů slevy vyžaduje právo ke správě mzdových podání. '
                    . 'Požádejte správce o jeho přidělení, nebo větu v importu nevybírejte.',
                );
            } elseif (isset($sicknessByKey[$key])) {
                $results[$key] = $this->applySickness(
                    $supplierId,
                    $environment,
                    $sicknessByKey[$key],
                    $receivedMap[$key] ?? null,
                    $userId,
                );
            } elseif (isset($ozuspojByKey[$key])) {
                $results[$key] = $this->applyOzuspoj(
                    $supplierId,
                    $environment,
                    $ozuspojByKey[$key],
                    $receivedMap[$key] ?? null,
                    $userId,
                );
            }
        }

        // Plány po zápisu formulářů. Historie podání, počáteční stavy ani průměry
        // párování formulářů nemění, takže stačí je spočítat znovu jen po převzetí
        // historie mezd.
        $settled = $batch->items() === [] ? [] : $this->planJmhz($supplierId, $environment, $batch, $pairMap);
        if ($read['reports'] !== []) {
            $this->recordHistory($supplierId, $environment, $read['reports'], $settled, $userId);
        }

        $checklist = ['completed' => 0, 'failed' => []];
        if ($changesSince !== null) {
            $checklist = $this->completeImportedChanges($supplierId, $results, $changesSince, $userId, $ip, $userAgent);
        }

        $list = [];
        foreach (array_keys($selected) as $key) {
            if (isset($results[$key])) {
                $list[] = $results[$key];
            }
        }
        $summary = ['applied' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($list as $row) {
            $summary[$row['status']]++;
        }

        $openings = ['saved' => 0, 'skipped' => []];
        $averages = ['created' => 0, 'approved' => 0, 'skipped' => []];
        $takeover = null;
        if (($applyOpeningBalances || $applyAverages || $applyTakeover) && $batch->items() !== []) {
            $fresh = array_values($settled);
            // Převzetí historie jde první: schválí průměry, se kterými počítal předchozí
            // program, a návrh průměru z hlášení pak za tatáž čtvrtletí už nevzniká.
            if ($applyTakeover) {
                $takeover = $this->takeoverPlanner->apply($supplierId, $fresh, $batch, $userId);
                $settled = $this->planJmhz($supplierId, $environment, $batch, $pairMap);
                $fresh = array_values($settled);
            }
            if ($applyOpeningBalances) {
                $openings = $this->openingPlanner->apply($supplierId, $fresh, $batch, $userId);
            }
            if ($applyAverages) {
                $averages = $this->averagePlanner->apply($supplierId, $fresh, $userId, $autoApproveAverages);
            }
        }
        $unresolved = self::unresolvedForms($settled);
        // Přihláška, jejíž aktivaci jsme odložili kvůli nenastoupení z téže dávky,
        // nesmí vypadat jako hotová, když se nenastoupení nezapsalo: vztah zůstal plánovaný.
        foreach ($pairs['defer'] as $registrationKey => $noShowKey) {
            $registration = $results[$registrationKey] ?? null;
            if ($registration === null || ($registration['status'] ?? null) !== 'applied') {
                continue;
            }
            $noShow = $results[$noShowKey] ?? null;
            if (($noShow['status'] ?? null) === 'applied') {
                continue;
            }
            $unresolved[] = [
                'key' => $registrationKey,
                'file' => (string) ($byKey[$registrationKey]['file'] ?? ''),
                'period' => substr((string) ($byKey[$registrationKey]['record']->startOn ?? ''), 0, 7),
                'label' => (string) ($byKey[$registrationKey]['record']->fullName() ?? ''),
                'reason' => 'Přihláška se zapsala, ale vztah zůstal plánovaný, protože ho má uzavřít hlášení '
                    . 'o nenastoupení z téže dávky, a to se nezapsalo'
                    . (isset($noShow['message']) && is_string($noShow['message']) ? ': ' . $noShow['message'] : '.'),
            ];
        }

        return [
            'results' => $list,
            'summary' => $summary,
            'opening_balances' => $openings,
            'averages' => $averages,
            'takeover' => $takeover,
            'change_checklist' => $checklist,
            'outcome' => $summary['failed'] === 0 && $unresolved === [] ? 'complete' : 'incomplete',
            'unresolved' => $unresolved,
        ];
    }

    /**
     * Přihlášky, které v téže dávce ruší hlášení o nenastoupení (REGZEC A1 + A8 téže
     * osoby), a částečná přihlášení s ukončením předregistrace (PREZEC P1 + P2).
     *
     * Každá věta se jinak plánuje nad stavem evidence PŘED dávkou: A1 s datem nástupu
     * v minulosti by vztah aktivovala a nenastoupení by pak narazilo na „vztah už
     * začal". Dvojice se proto pozná předem: přihláška vztah jen založí (zůstane
     * plánovaný) a nenastoupení ho uzavře. Páruje se jen jednoznačně (jedna přihláška
     * a jedno nenastoupení se shodným rodným číslem, OIČ nebo ID PPV); jinak se věty
     * plánují po staru a nenastoupení případně narazí na blokaci.
     *
     * @param list<array{record:RegistrationRecord,file:string,sha256:string,file_index:int}> $items
     * @param array<string,true>|null $selected vybrané klíče vět; `null` = všechny (náhled)
     * @return array{defer:array<string,string>,follow:array<string,string>,after:array<string,string>}
     *   `defer` přihláška => nenastoupení, `follow` nenastoupení => přihláška, `after` nenastoupení => nejdřív zpracovat po dni
     */
    private static function noShowPairs(array $items, ?array $selected): array
    {
        $starts = [];
        $endings = [];
        foreach ($items as $item) {
            $record = $item['record'];
            $key = RegistrationImportPlanner::key($item['sha256'], $record->position);
            if ($selected !== null && !isset($selected[$key])) {
                continue;
            }
            $identity = [];
            if ($record->birthNumber !== null) {
                $identity[] = 'bno:' . preg_replace('/\D/', '', $record->birthNumber);
            }
            if ($record->personIdentifier !== null) {
                $identity[] = 'oic:' . $record->personIdentifier;
            }
            if ($record->employmentIdentifier !== null) {
                $identity[] = 'ppv:' . $record->employmentIdentifier;
            }
            if ($identity === []) {
                continue;
            }
            if (($record->documentType === 'REGZEC25' && $record->actionCode === 1)
                || ($record->documentType === 'PREZEC26' && $record->actionCode === 9)
            ) {
                $starts[$key] = ['type' => $record->documentType, 'identity' => $identity, 'date' => $record->decisiveDate()];
            } elseif (($record->documentType === 'REGZEC25' && $record->actionCode === 8)
                || ($record->documentType === 'PREZEC26' && $record->actionCode === 10)
            ) {
                $endings[$key] = ['type' => $record->documentType, 'identity' => $identity];
            }
        }
        $result = ['defer' => [], 'follow' => [], 'after' => []];
        foreach ($endings as $endingKey => $ending) {
            $matches = array_keys(array_filter(
                $starts,
                static fn (array $start): bool => $start['type'] === $ending['type']
                    && array_intersect($start['identity'], $ending['identity']) !== [],
            ));
            if (count($matches) !== 1) {
                continue;
            }
            $startKey = (string) $matches[0];
            $reverse = array_filter(
                $endings,
                static fn (array $other): bool => $other['type'] === $starts[$startKey]['type']
                    && array_intersect($starts[$startKey]['identity'], $other['identity']) !== [],
            );
            if (count($reverse) !== 1) {
                continue;
            }
            $result['follow'][$endingKey] = $startKey;
            if ($ending['type'] === 'REGZEC25') {
                $result['defer'][$startKey] = $endingKey;
            }
            if ($starts[$startKey]['date'] !== null) {
                $result['after'][$endingKey] = (string) $starts[$startKey]['date'];
            }
        }

        return $result;
    }

    /**
     * Formuláře, které po zápisu v evidenci nemají kam patřit: platný formulář
     * bez pracovního vztahu (čeká na ruční přiřazení nebo na vztah, který
     * nevznikl) a formulář zablokovaný (konflikt, rozporné identifikátory).
     * Nahrazené a stornované formuláře sem nepatří — z nich se nepřebírá nic.
     *
     * @param array<string,array<string,mixed>> $plans
     * @return list<array{key:string,file:string,period:string,label:string,reason:string}>
     */
    private static function unresolvedForms(array $plans): array
    {
        $unresolved = [];
        foreach ($plans as $key => $plan) {
            $blocked = $plan['blocker'] !== null;
            if (!$blocked && !($plan['_effective'] === true && $plan['_employment_id'] === null)) {
                continue;
            }
            $unresolved[] = [
                'key' => (string) $key,
                'file' => (string) $plan['file'],
                'period' => (string) $plan['period'],
                'label' => (string) ($plan['person']['full_name'] ?? ''),
                'reason' => $blocked
                    ? (string) $plan['blocker']
                    : ($plan['operation'] === 'create_employment'
                        ? 'Pracovní vztah formuláře v evidenci není, nezaložila ho žádná věta dávky.'
                        : 'Formulář není spárovaný s pracovním vztahem.'),
            ];
        }

        return $unresolved;
    }

    /**
     * Naplánuje větu registrace nad aktuální evidencí a zapíše ji.
     *
     * @param array{record:RegistrationRecord,file:string,sha256:string,file_index:int} $item
     * @return array<string,mixed>
     */
    private function applyRegistration(
        int $supplierId,
        string $environment,
        array $item,
        ?int $officeId,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
        array $relationTypeMap = [],
        array $terminationKeys = [],
        bool $deferActivation = false,
        bool $registrationInBatch = false,
    ): array {
        $recordKey = RegistrationImportPlanner::key($item['sha256'], $item['record']->position);
        $plan = $this->planner->plan(
            $supplierId,
            $environment,
            $item['record'],
            $item['file'],
            $item['sha256'],
            $relationTypeMap[$recordKey] ?? null,
            isset($terminationKeys[$recordKey]),
            $deferActivation,
            $registrationInBatch,
        );
        $key = (string) $plan['key'];
        if ($plan['blocker'] !== null) {
            return $this->result($key, 'skipped', (string) $plan['blocker'], $plan);
        }
        if (!$plan['selectable']) {
            return $this->result($key, 'skipped', 'Věta nemá co zapsat — evidence už odpovídá.', $plan);
        }
        $outer = $this->db->pdo()->inTransaction();
        try {
            return ['key' => $key]
                + $this->writer->apply($supplierId, $environment, $plan, $officeId, $userId, $ip, $userAgent)
                + ['start_estimated' => $plan['employment']['start_estimated'] === true];
        } catch (LicensePayrollLimitExceeded) {
            return $this->result($key, 'failed', 'Dalšího aktivního zaměstnance lze přidat až po '
                . 'rozšíření mzdového doplňku.', $plan);
        } catch (\Exception $e) {
            $this->rethrowOuterAbort($outer, $e);
            return $this->result($key, 'failed', $e->getMessage(), $plan);
        }
    }

    /**
     * Povinnosti ke změně (dodatek, oznámení pojišťovně a ČSSZ), které založila
     * nová verze podmínek z importu, odškrtne jako splněné: změnu už vykázalo
     * importované podání. Jde přes tutéž cestu jako ruční odškrtnutí, takže
     * na kartě vztahu zůstane událost. Starší rozpracované položky a fáze
     * nástupu a skončení nechává účetní.
     *
     * @param array<string,array<string,mixed>> $results
     * @return array{completed:int,failed:list<array{employment_id:int,item_key:string,message:string}>}
     */
    private function completeImportedChanges(
        int $supplierId,
        array $results,
        string $since,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $employmentIds = [];
        foreach ($results as $row) {
            if (($row['status'] ?? null) === 'applied' && isset($row['employment_id'])) {
                $employmentIds[(int) $row['employment_id']] = true;
            }
        }
        $completed = 0;
        $failed = [];
        foreach (array_keys($employmentIds) as $employmentId) {
            foreach ($this->employments->pendingChecklistItemsSince($supplierId, $employmentId, 'change', $since) as $item) {
                $outer = $this->db->pdo()->inTransaction();
                try {
                    $this->employments->updateChecklist(
                        $supplierId,
                        $employmentId,
                        $item['item_key'],
                        $item['row_version'],
                        'completed',
                        self::AUTO_CHANGE_NOTE,
                        $userId,
                        $ip,
                        $userAgent,
                    );
                    $completed++;
                } catch (\Exception $e) {
                    $this->rethrowOuterAbort($outer, $e);
                    $failed[] = [
                        'employment_id' => $employmentId,
                        'item_key' => $item['item_key'],
                        'message' => $e->getMessage(),
                    ];
                }
            }
        }

        return ['completed' => $completed, 'failed' => $failed];
    }

    /**
     * Plány všech formulářů hlášení. Dva platné formuláře, které se spárují
     * na tentýž vztah za tentýž měsíc, se zablokují oba — z dávky nejde poznat,
     * který ČSSZ přijala.
     *
     * @param array<string,int> $pairs
     * @return array<string,array<string,mixed>>
     */
    private function planJmhz(int $supplierId, string $environment, JmhzBatch $batch, array $pairs): array
    {
        return $this->jmhzPlanner->batch(fn (): array => $this->planJmhzBatch($supplierId, $environment, $batch, $pairs));
    }

    /**
     * @param array<string,int> $pairs
     * @return array<string,array<string,mixed>>
     */
    private function planJmhzBatch(int $supplierId, string $environment, JmhzBatch $batch, array $pairs): array
    {
        $plans = [];
        foreach ($batch->items() as $item) {
            $plans[$item->key] = $this->jmhzPlanner->plan($supplierId, $environment, $item, $batch, $pairs[$item->key] ?? null);
        }
        $byEmployment = [];
        foreach ($plans as $key => $plan) {
            if ($plan['_effective'] === true && $plan['_employment_id'] !== null && $plan['blocker'] === null) {
                $byEmployment[$plan['period'] . '|' . $plan['_employment_id']][] = $key;
            }
        }
        foreach ($byEmployment as $keys) {
            if (count($keys) < 2) {
                continue;
            }
            foreach ($keys as $key) {
                $batch->markConflict($key);
                $item = $batch->item($key);
                if ($item !== null) {
                    $plans[$key] = $this->jmhzPlanner->plan($supplierId, $environment, $item, $batch, $pairs[$key] ?? null);
                }
            }
        }

        return $plans;
    }

    /**
     * @return array{
     *   files:list<array<string,mixed>>,
     *   registrations:list<array{record:RegistrationRecord,file:string,sha256:string,file_index:int}>,
     *   batch:JmhzBatch,
     *   history:JmhzEmploymentHistory,
     *   has_jmhz:bool
     * }
     */
    private function read(int $supplierId, mixed $files): array
    {
        $fileRows = [];
        $records = [];
        $jmhzItems = [];
        $stornos = [];
        $hasJmhz = false;
        $reports = [];
        $sickness = [];
        $ozuspoj = [];
        foreach (ImportFiles::fromRequest(
            $files,
            ['xml'],
            ImportFiles::XML_MAX_FILE_BYTES,
            ImportFiles::XML_MAX_TOTAL_BYTES,
        ) as $index => $file) {
            if (JmhzReportReader::isJmhz($file['content'])) {
                $hasJmhz = true;
                try {
                    $reports[] = ['file' => $file, 'index' => $index, 'report' => $this->jmhzReader->read($file['content'])];
                } catch (RegistrationImportFileException $e) {
                    $fileRows[$index] = $this->fileRow($file, 'JMHZ', 0, $e->getMessage());
                }
                continue;
            }
            if (SicknessDocumentXmlReader::looksLike($file['content'])) {
                try {
                    $document = $this->sicknessReader->read($file['content']);
                } catch (RegistrationImportFileException $e) {
                    $fileRows[$index] = $this->fileRow($file, null, 0, $e->getMessage());
                    continue;
                }
                $fileRows[$index] = $this->fileRow(
                    $file,
                    $document['document_type'],
                    count($document['records']),
                    null,
                    $document['warnings'],
                );
                foreach ($document['records'] as $record) {
                    $sickness[] = [
                        'record' => $record,
                        'file' => $file['name'],
                        'sha256' => $file['sha256'],
                        'file_index' => $index,
                    ];
                }
                continue;
            }
            if (OzuspojXmlReader::looksLike($file['content'])) {
                try {
                    $predecessor = $this->ozuspojReader->read($file['content']);
                } catch (OzuspojException $e) {
                    $fileRows[$index] = $this->fileRow($file, 'OZUSPOJ23', 0, $e->getMessage());
                    continue;
                }
                $fileRows[$index] = $this->fileRow($file, 'OZUSPOJ23', 1, null);
                $ozuspoj[] = [
                    'predecessor' => $predecessor,
                    'file' => $file['name'],
                    'sha256' => $file['sha256'],
                    'file_index' => $index,
                    'content' => $file['content'],
                ];
                continue;
            }
            try {
                $read = $this->reader->read($file['content']);
            } catch (RegistrationImportFileException $e) {
                $fileRows[$index] = $this->fileRow($file, null, 0, $e->getMessage());
                continue;
            }
            $fileRows[$index] = $this->fileRow(
                $file,
                $read['document_type'],
                count($read['records']),
                null,
                array_map(
                    static fn (array $sentence): string => 'Věta ' . $sentence['position']
                        . ($sentence['sequence'] === null ? '' : ' (sqnr ' . $sentence['sequence'] . ')')
                        . ' neodpovídá schématu ČSSZ ' . $read['document_type']
                        . ($sentence['reason'] === '' ? '' : ' (' . $sentence['reason'] . ')')
                        . ' a nepřebírá se, takže její údaje doplňte ručně. Ostatní věty'
                        . ' souboru se převezmou, stejně jako je ČSSZ zpracuje po větách.',
                    $read['rejected'],
                ),
            );
            foreach ($read['records'] as $record) {
                $records[] = [
                    'record' => $record,
                    'file' => $file['name'],
                    'sha256' => $file['sha256'],
                    'file_index' => $index,
                ];
            }
        }

        $foreign = $this->foreignReports($supplierId, $reports);
        $accepted = [];
        foreach ($reports as ['file' => $file, 'index' => $index, 'report' => $report]) {
            if (isset($foreign['errors'][$index])) {
                $fileRows[$index] = $this->fileRow($file, 'JMHZ', count($report->forms), $foreign['errors'][$index], [], $report->period(), $report->submissionType);
                continue;
            }
            $accepted[] = ['file' => $file, 'report' => $report];
            $warnings = [...$report->warnings, ...$foreign['warnings']];
            if ($report->submissionType === 'S' && $report->forms === []) {
                $stornos[] = ['file' => $report, 'name' => $file['name']];
                $warnings[] = 'Stornující podání za ' . $report->period() . ' — z hlášení, které ruší, se nic nepřebírá.';
            }
            $fileRows[$index] = $this->fileRow($file, 'JMHZ', count($report->forms), null, $warnings, $report->period(), $report->submissionType);
            foreach ($report->forms as $form) {
                $jmhzItems[] = new JmhzBatchItem(
                    RegistrationImportPlanner::key($file['sha256'], $form->position),
                    $report,
                    $form,
                    $file['name'],
                    $file['sha256'],
                    $index,
                );
            }
        }
        ksort($fileRows);

        $batch = JmhzBatch::build($jmhzItems, $stornos);
        foreach ($records as $index => $item) {
            $record = $item['record'];
            if ($record->isCsszExport() && $record->startOn === null) {
                $start = CsszExportStartResolver::forExport($record, $batch);
                if ($start !== null) {
                    $records[$index]['record'] = $record->withDerivedStart($start);
                    if ($start['source'] === CsszExportStartResolver::SOURCE_EXPORT && $record->employmentIdentifier !== null) {
                        $batch->declareStart('ppv:' . $record->employmentIdentifier, $start['on']);
                    }
                }
            }
        }
        $history = $batch->history();
        if ($jmhzItems !== []) {
            $derived = JmhzDerivedRegistrations::build(
                $batch,
                $history,
                array_map(static fn (array $item): RegistrationRecord => $item['record'], $records),
            );
            foreach ($derived['records'] as $record) {
                $records[] = [
                    'record' => $record,
                    'file' => JmhzDerivedRegistrations::FILE_NAME,
                    'sha256' => $derived['sha256'],
                    'file_index' => self::DERIVED_FILE_INDEX,
                ];
            }
        }

        return [
            'files' => array_values($fileRows),
            'registrations' => $records,
            'batch' => $batch,
            'history' => $history,
            'has_jmhz' => $hasJmhz,
            'reports' => $accepted,
            'sickness' => $sickness,
            'ozuspoj' => $ozuspoj,
        ];
    }

    /**
     * Věta NEMPRI nebo HZUPN předchozího programu: naplánuje ji nad aktuální
     * evidencí a zapíše vyřízení podání.
     *
     * @param array{record:SicknessImportRecord,file:string,sha256:string,file_index:int} $item
     * @return array<string,mixed>
     */
    private function applySickness(int $supplierId, string $environment, array $item, ?string $receivedOn, ?int $userId): array
    {
        $plan = $this->sicknessPlanner->plan($supplierId, $environment, $item['record'], $item['file'], $item['sha256'], $receivedOn);
        $key = (string) $plan['key'];
        if ($plan['blocker'] !== null) {
            return $this->result($key, 'skipped', (string) $plan['blocker'], $plan);
        }
        if (!$plan['selectable']) {
            return $this->result($key, 'skipped', 'Věta nemá co zapsat - evidence už odpovídá.', $plan);
        }

        $outer = $this->db->pdo()->inTransaction();
        try {
            return ['key' => $key] + $this->sicknessWriter->apply($supplierId, $environment, $plan, $userId);
        } catch (\Exception $e) {
            $this->rethrowOuterAbort($outer, $e);
            return $this->result($key, 'failed', $e->getMessage(), $plan);
        }
    }

    /**
     * Věta, která selže, se zapíše jako neúspěšná a dávka pokračuje. Odvolanou transakci
     * (1020/1213) ale MariaDB vrátila celou, i s dřívějšími větami: běží-li import uvnitř
     * cizí transakce (zkušební převod), zbytek dávky by se zapsal v autocommitu. Chybu
     * pak dostane vlastník transakce. Transakci, kterou zápis vlastnil sám, už vrátil
     * a po vyčerpání opakování může dávka pokračovat další větou.
     */
    private function rethrowOuterAbort(bool $outer, \Exception $e): void
    {
        if ($outer && TransactionFailure::aborted($e)) {
            throw $e;
        }
    }

    /**
     * Záměr slevy z OZUSPOJ23 předchozího programu: den doručení z protokolu
     * zadává účetní, soubor ho nenese.
     *
     * @param array{predecessor:OzuspojPredecessorFile,file:string,sha256:string,file_index:int,content:string} $item
     * @return array<string,mixed>
     */
    private function applyOzuspoj(int $supplierId, string $environment, array $item, ?string $receivedOn, ?int $userId): array
    {
        $plan = $this->ozuspojPlanner->plan($supplierId, $environment, $item['predecessor'], $item['file'], 1, $receivedOn);
        $key = (string) $plan['key'];
        if ($plan['blocker'] !== null) {
            return $this->result($key, 'skipped', (string) $plan['blocker'], $plan);
        }
        if (!$plan['selectable'] || $receivedOn === null) {
            return $this->result($key, 'skipped', 'Věta nemá co zapsat - evidence už odpovídá.', $plan);
        }
        if ($userId === null || $userId <= 0) {
            return $this->result($key, 'failed', 'Záměr se zakládá jménem přihlášené účetní.', $plan);
        }
        try {
            $imported = $this->ozuspojImporter->import($supplierId, $environment, $item['content'], $receivedOn, $userId);
        } catch (OzuspojException $e) {
            return $this->result($key, 'failed', $e->getMessage(), $plan);
        }

        return [
            'key' => $key,
            'status' => 'applied',
            'message' => null,
            'employee_id' => $plan['_employee_id'],
            'employment_id' => $plan['_employment_id'],
            'operations' => ['intent_' . (string) ($imported['import_status'] ?? 'created')],
        ];
    }

    /**
     * Den doručení podání podle protokolu ČSSZ: `[{key, received_on}]`. U NEMPRI
     * a HZUPN je nepovinný (stav „podal předchozí program" ho nevyžaduje), u
     * OZUSPOJ povinný.
     *
     * @return array<string,string>
     */
    private function receivedOn(mixed $choices): array
    {
        if ($choices === null) {
            return [];
        }
        if (!is_array($choices) || !array_is_list($choices)) {
            throw new \InvalidArgumentException('Dny doručení musí být seznam dvojic {key, received_on}.');
        }
        $result = [];
        foreach ($choices as $choice) {
            $key = is_array($choice) ? ($choice['key'] ?? null) : null;
            $date = is_array($choice) ? ($choice['received_on'] ?? null) : null;
            if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new \InvalidArgumentException('Den doručení obsahuje neplatný klíč věty.');
            }
            if ($date === null || $date === '') {
                continue;
            }
            $parsed = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
            if (!$parsed instanceof \DateTimeImmutable || $parsed->format('Y-m-d') !== $date) {
                throw new \InvalidArgumentException('Den doručení podání musí být ve tvaru RRRR-MM-DD.');
            }
            $result[$key] = $date;
        }

        return $result;
    }

    /**
     * Nahraná hlášení jdou do historie podání předchozím programem (zdroj `jmhz_xml`),
     * stejnou cestou jako hlášení převzatá z PAMICA: příprava vlastního hlášení pak ví,
     * že za měsíc řádné hlášení už odešlo. Obsah je celé XML, formuláře nesou vazbu
     * na vztah, se kterým je import spároval.
     *
     * @param list<array{file:array<string,mixed>,report:JmhzReportFile}> $reports
     * @param array<string,array<string,mixed>> $plans plány formulářů podle klíče
     */
    private function recordHistory(int $supplierId, string $environment, array $reports, array $plans, ?int $userId): void
    {
        foreach ($reports as ['file' => $file, 'report' => $report]) {
            $forms = [];
            foreach ($report->forms as $form) {
                $plan = $plans[RegistrationImportPlanner::key((string) $file['sha256'], $form->position)] ?? [];
                $forms[] = [
                    'position' => $form->position,
                    'form_guid' => $form->formGuid,
                    'form_type' => $form->formType,
                    'source_relation_ref' => null,
                    'employee_id' => isset($plan['_employee_id']) ? (int) $plan['_employee_id'] : null,
                    'employment_id' => isset($plan['_employment_id']) ? (int) $plan['_employment_id'] : null,
                    'payload' => get_object_vars($form),
                ];
            }
            $this->externalSubmissions->store($supplierId, $environment, JmhzExternalSubmissionStore::SOURCE_JMHZ_XML, [
                'source_key' => 'guid:' . $report->submissionGuid . ':' . $report->submissionType . ':' . $report->period() . ':' . ($report->packageOrdinal ?? 1),
                'document_kind' => 'monthly',
                'period' => $report->period(),
                'submission_type' => $report->submissionType,
                'submission_guid' => $report->submissionGuid,
                'corrected_source_key' => null,
                'status' => JmhzExternalSubmissionStore::STATUS_SENT,
                'filled_at' => $report->filledAt,
                'submitted_at' => null,
                'accepted_at' => null,
                'program' => $report->vendor,
                'file_name' => (string) $file['name'],
                'payload' => [
                    'file_name' => (string) $file['name'],
                    'file_sha256' => (string) $file['sha256'],
                    'vendor' => $report->vendor,
                    'warnings' => $report->warnings,
                    'xml' => (string) $file['content'],
                ],
            ], $forms, $userId);
        }
    }

    /**
     * Hlášení jiného zaměstnavatele se nepřebírá: formuláře nesou jen OIČ a ID
     * PPV, takže by se osoba cizí firmy spárovala nebo založila tady.
     * Rozhoduje VS zaměstnavatele (10002) proti VS mzdových účtáren firmy.
     * Firma bez VS se ověřit nedá — projde jen dávka jediného zaměstnavatele.
     *
     * @param list<array{file:array<string,mixed>,index:int,report:JmhzReportFile}> $reports
     * @return array{errors:array<int,string>,warnings:list<string>}
     */
    private function foreignReports(int $supplierId, array $reports): array
    {
        $known = $this->lookup->variableSymbols($supplierId);
        $errors = [];
        $symbols = [];
        foreach ($reports as ['index' => $index, 'report' => $report]) {
            $symbol = $report->variableSymbol === null ? null : RegistrationImportLookup::variableSymbol($report->variableSymbol);
            if ($symbol === null) {
                continue;
            }
            $symbols[$symbol] = true;
            if ($known !== [] && !in_array($symbol, $known, true)) {
                $errors[$index] = "Hlášení podal zaměstnavatel s variabilním symbolem {$report->variableSymbol}, který "
                    . 'nepatří žádné mzdové účtárně této firmy. Nejspíš jde o hlášení jiné firmy; pokud ne, '
                    . 'doplňte VS v nastavení mzdové účtárny.';
            }
        }
        if ($known !== [] || $symbols === []) {
            return ['errors' => $errors, 'warnings' => []];
        }
        if (count($symbols) > 1) {
            foreach ($reports as ['index' => $index]) {
                $errors[$index] = 'Dávka obsahuje hlášení několika zaměstnavatelů (různé variabilní symboly) a firma '
                    . 'nemá VS mzdové účtárny, podle kterého by šlo poznat, které jsou její. Doplňte VS v nastavení '
                    . 'mzdové účtárny, nebo nahrajte hlášení jen jednoho zaměstnavatele.';
            }

            return ['errors' => $errors, 'warnings' => []];
        }

        return ['errors' => [], 'warnings' => [
            'Firma nemá vyplněný VS mzdové účtárny, takže nejde ověřit, že hlášení (VS ' . array_key_first($symbols)
                . ') patří jí. Doplňte VS v nastavení mzdové účtárny.',
        ]];
    }

    /**
     * Formulář hlášení osoby, kterou teprve založí věta exportu zaměstnanců
     * v téže dávce, se v náhledu ještě nemá s čím spárovat. Při použití se
     * export zapíše dřív a formulář se spáruje podle ID PPV sám.
     *
     * @param array<string,array<string,mixed>> $jmhzPlans
     * @param list<array<string,mixed>> $registrationPlans
     */
    private function hintExportPairing(array &$jmhzPlans, array $registrationPlans): void
    {
        $created = [];
        $blocked = [];
        foreach ($registrationPlans as $plan) {
            /** @var RegistrationRecord $record */
            $record = $plan['_record'];
            if ($record->isJmhzDerived() && $plan['blocker'] !== null) {
                $relation = JmhzReportForm::relationKeyOf($record->employmentIdentifier, $record->lastName, $record->firstName, $record->birthDate);
                if ($relation !== null) {
                    $blocked[$relation] = (string) $plan['blocker'];
                }
            }
            if (($record->isCsszExport() || ($record->isJmhzDerived() && $record->actionCode === 1))
                && $plan['selectable']
                && in_array($plan['operation'], ['create_person', 'create_employment'], true)
            ) {
                $relation = JmhzReportForm::relationKeyOf(
                    $record->employmentIdentifier,
                    $record->lastName,
                    $record->firstName,
                    $record->birthDate,
                );
                if ($relation !== null) {
                    $created[$relation] = [
                        'name' => $plan['person']['full_name'],
                        'source' => $record->isCsszExport() ? 'věta exportu zaměstnanců ČSSZ' : 'věta odvozená z hlášení',
                    ];
                }
            }
        }
        foreach ($jmhzPlans as $key => $plan) {
            /** @var JmhzBatchItem $item */
            $item = $plan['_item'];
            $relation = $item->form->relationKey();
            if ($plan['operation'] === 'pair_required' && $relation !== null && isset($created[$relation])) {
                $jmhzPlans[$key]['warnings'][] = 'Pracovní vztah založí ' . $created[$relation]['source'] . ' ('
                    . $created[$relation]['name'] . '). Vyberte ji spolu s formulářem — při zápisu se formulář '
                    . 'spáruje sám.';
            }
            if ($plan['operation'] === 'create_employment' && $relation !== null && isset($blocked[$relation])) {
                $jmhzPlans[$key]['warnings'][] = 'Věta, která by vztah založila, je zablokovaná: ' . $blocked[$relation];
            }
        }
    }

    /**
     * @param array{name:string,sha256:string} $file
     * @param list<string> $warnings
     * @return array<string,mixed>
     */
    private function fileRow(
        array $file,
        ?string $documentType,
        int $recordCount,
        ?string $error,
        array $warnings = [],
        ?string $period = null,
        ?string $submissionType = null,
    ): array {
        return [
            'name' => $file['name'],
            'sha256' => $file['sha256'],
            'document_type' => $documentType,
            'record_count' => $recordCount,
            'error' => $error,
            'warnings' => $warnings,
            'period' => $period,
            'submission_type' => $submissionType,
        ];
    }

    /**
     * Ruční párování formulářů hlášení s pracovními vztahy: `[{key, employment_id}]`.
     *
     * @return array<string,int>
     */
    private function pairs(mixed $pairs): array
    {
        if ($pairs === null) {
            return [];
        }
        if (!is_array($pairs) || !array_is_list($pairs)) {
            throw new \InvalidArgumentException('Ruční párování musí být seznam dvojic {key, employment_id}.');
        }
        $result = [];
        foreach ($pairs as $pair) {
            $key = is_array($pair) ? ($pair['key'] ?? null) : null;
            $employmentId = is_array($pair) ? ($pair['employment_id'] ?? null) : null;
            if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1
                || !is_int($employmentId) || $employmentId <= 0
            ) {
                throw new \InvalidArgumentException('Ruční párování obsahuje neplatný klíč formuláře nebo pracovní vztah.');
            }
            if (isset($result[$key])) {
                throw new \InvalidArgumentException('Formulář je v ručním párování uvedený dvakrát.');
            }
            $result[$key] = $employmentId;
        }

        return $result;
    }

    /**
     * Druh vztahu zvolený účetní u věty, která nabízí víc možností
     * (`relation_type_options`): `[{key, relation_type}]`. Volbu mimo nabídnuté
     * druhy planner ignoruje.
     *
     * @return array<string,string>
     */
    private function relationTypes(mixed $choices): array
    {
        if ($choices === null) {
            return [];
        }
        if (!is_array($choices) || !array_is_list($choices)) {
            throw new \InvalidArgumentException('Volba druhu vztahu musí být seznam dvojic {key, relation_type}.');
        }
        $result = [];
        foreach ($choices as $choice) {
            $key = is_array($choice) ? ($choice['key'] ?? null) : null;
            $type = is_array($choice) ? ($choice['relation_type'] ?? null) : null;
            if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1
                || !is_string($type) || !in_array($type, RegistrationImportVocabulary::RELATION_TYPES, true)
            ) {
                throw new \InvalidArgumentException('Volba druhu vztahu obsahuje neplatný klíč věty nebo druh vztahu.');
            }
            $result[$key] = $type;
        }

        return $result;
    }

    /**
     * Věty exportu ČSSZ, u kterých účetní potvrdila ukončení trvajícího
     * vztahu ke konci pojistného vztahu z exportu: seznam klíčů vět.
     *
     * @return array<string,true>
     */
    private function terminations(mixed $keys): array
    {
        if ($keys === null) {
            return [];
        }
        if (!is_array($keys) || !array_is_list($keys)) {
            throw new \InvalidArgumentException('Potvrzená ukončení vztahů musí být seznam klíčů vět.');
        }
        $result = [];
        foreach ($keys as $key) {
            if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new \InvalidArgumentException('Potvrzená ukončení vztahů obsahují neplatný klíč věty.');
            }
            $result[$key] = true;
        }

        return $result;
    }

    private function order(int $fileIndex, int $position): string
    {
        return sprintf('%05d:%05d', $fileIndex, $position);
    }

    /**
     * @param list<array<string,mixed>> $plans
     * @return array<string,int>
     */
    private function summary(array $plans): array
    {
        $summary = [
            'total' => count($plans),
            'create' => 0,
            'update' => 0,
            'terminate' => 0,
            'none' => 0,
            'blocked' => 0,
            'pair_required' => 0,
        ];
        foreach ($plans as $plan) {
            if ($plan['blocker'] !== null || $plan['operation'] === 'unsupported') {
                $summary['blocked']++;
                continue;
            }
            $bucket = match ($plan['operation']) {
                'create_person', 'create_employment', 'create_case' => 'create',
                'update', 'assign_identifiers', 'update_case', 'import_intent' => 'update',
                'terminate' => 'terminate',
                'pair_required' => 'pair_required',
                default => 'none',
            };
            $summary[$bucket]++;
        }

        return $summary;
    }

    /**
     * @param array<string,mixed>|null $plan
     * @return array<string,mixed>
     */
    private function result(string $key, string $status, string $message, ?array $plan = null): array
    {
        return [
            'key' => $key,
            'status' => $status,
            'message' => $message,
            'employee_id' => $plan['_employee_id'] ?? null,
            'employment_id' => $plan['_employment_id'] ?? null,
            'operations' => [],
        ];
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private static function publicPlan(array $plan): array
    {
        return array_filter($plan, static fn (string $key): bool => !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
    }

    private function environment(string $environment): void
    {
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new \InvalidArgumentException('Prostředí musí být ostré (production), nebo testovací (test).');
        }
    }
}
