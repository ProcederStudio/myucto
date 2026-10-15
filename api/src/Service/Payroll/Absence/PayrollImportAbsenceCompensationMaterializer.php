<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAttendanceImportRepository;
use MyInvoice\Repository\Payroll\PayrollAverageEarningRepository;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollInputCancellationException;
use MyInvoice\Repository\Payroll\PayrollInputConflictException;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Repository\Payroll\PayrollTimeRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Calculation\PayrollWageRoundingPolicy;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMeaning;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Time\CzechHolidayCalendar;
use MyInvoice\Service\Payroll\Time\PayrollWorkCalendarSchedule;
use PDO;

/**
 * Náhrady mzdy z měsíčních součtů hodin importu docházky.
 *
 * Absence s daty počítá náhradu z publikovaných směn, které měsíc ze souhrnu
 * importu nemá, takže by z ní vyšla nula. Tady se proto počítá přímo ze
 * souhrnu pracovního měsíce, který zapsala táž dávka: hodiny × schválený
 * průměrný hodinový výdělek čtvrtletí × sazba. Data absencí se nevymýšlejí.
 *
 * Počítá se jen to, co jde z měsíčního součtu doložit: dovolená (§ 222 ZP),
 * lékař (§ 199 ZP) a překážka na straně zaměstnavatele (§ 207 až § 209 ZP).
 * Náhrada při DPN potřebuje dny a okno § 192, u ostatních hodin náhrada
 * nepřísluší nebo ji podklady nerozliší; ty se jen vypíšou s důvodem.
 *
 * Vstup vzniká jako KONCEPT: souhrn z podkladů není rozhodnutím o penězích,
 * na rozdíl od schválené dovolené v {@see PayrollLeaveInputMaterializer}.
 * Původ je `absence` s předponou `leave:` — jen tak dovolí integritní kontrola
 * `chk_payroll_input_source_snapshot` uložit stopu výpočtu a jen tak projde
 * náhrada za dovolenou pojistkou `ABSENCE_ONLY_CODES`. Idempotenci drží
 * `external_id` nad `uq_payroll_input_external`. Změněné hodiny koncept zruší
 * a založí znovu (úprava by ponechala starou stopu), schválený či uzamčený
 * vstup se nemění nikdy, jen se ohlásí.
 */
final class PayrollImportAbsenceCompensationMaterializer
{
    public const EXTERNAL_ID_PREFIX = 'leave:attendance:';
    private const SAVEPOINT = 'payroll_import_absence_compensation';

    /**
     * Lékař je překážka na straně zaměstnance (§ 199 ZP), překážka na straně
     * zaměstnavatele má vlastní složku; obě mají v měsíčním hlášení vlastní
     * kolonku náhrad (10341, 10340).
     */
    private const COMPONENTS = [
        'vacation_hours' => 'NAHRADA_MZDY_DOVOLENA',
        'doctor_hours' => 'NAHRADA_MZDY_PREKAZKY_ZAMESTNANEC',
        'obstacle_employer_hours' => 'NAHRADA_MZDY_PREKAZKY_ZAMESTNAVATEL',
    ];

    private const ENTITLEMENT_BASIS = [
        'vacation_hours' => 'zp-222-1',
        'doctor_hours' => 'zp-199-1+nv-590-2006',
        'obstacle_employer_hours' => 'zp-207-209',
        'holiday_hours' => 'zp-115-3',
        'obstacle_employee_hours' => 'zp-199-1+nv-590-2006',
    ];

    /**
     * Svátek v jinak pracovní den (§ 115 odst. 3 ZP): zaměstnanci s měsíční mzdou se
     * mzda nekrátí, ostatním (mzda za hodiny, úkol) náleží náhrada ve výši průměrného
     * výdělku. Kdo má měsíční mzdu, souhrn neříká; volající proto náhradu za svátek
     * zapíná výslovně a platí jen pro vztah bez předpisu základní měsíční mzdy.
     */
    private const HOLIDAY = ['holiday_hours' => 'NAHRADA_MZDY_SVATEK'];
    private const HOLIDAY_PERCENT = 100;

    /**
     * Placená překážka na straně zaměstnance (§ 199 ZP, NV č. 590/2006 Sb.): náhrada ve výši
     * průměrného výdělku. Obecný import ji zapnout nemůže — souhrn hodin neříká, o jakou
     * překážku jde, a část z nich náhradu nemá. Zapíná ji volající, jehož zdroj hodiny
     * vede výslovně jako placené volno (převod z PAMICA: `V03` mimo lékaře).
     */
    private const PAID_EMPLOYEE_OBSTACLE = ['obstacle_employee_hours' => 'NAHRADA_MZDY_PREKAZKY_ZAMESTNANEC'];

    public const NOT_COMPUTED = [
        'sick_hours' => 'Náhradu mzdy při DPN nejde z měsíčního součtu hodin ověřit (okno prvních 14 dnů, redukce průměru, dny nemoci). Zadejte ji ručně podle rozhodnutí o DPN.',
        'care_hours' => 'Ošetřovné vyplácí ČSSZ, zaměstnavatel za tyto hodiny náhradu mzdy neposkytuje.',
        'paternity_hours' => 'Otcovskou vyplácí ČSSZ, zaměstnavatel za tyto hodiny náhradu mzdy neposkytuje.',
        'obstacle_employee_hours' => 'Překážky na straně zaměstnance (paragraf) podklady nerozlišují a jednotlivé druhy mají různou náhradu, nebo žádnou. Zadejte náhradu ručně.',
        'unpaid_leave_hours' => 'Neplacené volno: mzda ani náhrada nepřísluší.',
        'unexcused_hours' => 'Neomluvená absence: mzda ani náhrada nepřísluší.',
    ];

    private const DOCTOR_NOTICE = 'Náhrada za lékaře se počítá ze všech hodin z podkladů. Nárok je jen na nezbytně nutnou dobu (NV č. 590/2006 Sb.) a z měsíčního součtu to ověřit nejde, zkontrolujte podklad.';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollAttendanceImportRepository $imports,
        private readonly PayrollTimeRepository $time,
        private readonly PayrollAverageEarningRepository $averages,
        private readonly PayrollComponentRepository $components,
        private readonly PayrollInputRepository $inputs,
    ) {}

    /**
     * @return array{
     *   created:int,
     *   updated:int,
     *   unchanged:int,
     *   cancelled:int,
     *   rates:array<string,int>,
     *   skipped:list<array{employment_id:int,meaning:?string,reason:string}>,
     *   warnings:list<array{employment_id:int,meaning:string,message:string}>
     * }
     */
    public function materializeFromBatch(
        int $supplierId,
        int $importId,
        ?int $userId,
        ?ImportAbsenceCompensationRates $rates = null,
        bool $holidayWithoutMonthlyWage = false,
        bool $paidEmployeeObstacle = false,
    ): array {
        $rates ??= ImportAbsenceCompensationRates::defaults();
        $batch = $this->imports->batch($supplierId, $importId);
        if ($batch === null || $batch['source_system'] !== AttendanceMeaning::SOURCE_SYSTEM) {
            throw new \InvalidArgumentException('Dávka importu docházky nebyla nalezena.');
        }
        $period = (string) $batch['period'];
        $employmentIds = [];
        foreach ($this->imports->batchRows($supplierId, $importId) as $row) {
            if (in_array((string) $row['meaning'], AttendanceMeaning::HOURS, true)) {
                $employmentIds[(int) $row['employment_id']] = true;
            }
        }
        $employmentIds = array_keys($employmentIds);
        sort($employmentIds);
        $this->components->ensureDefaults($supplierId);

        $report = [
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'cancelled' => 0,
            'rates' => $rates->toArray(),
            'skipped' => [],
            'warnings' => [],
        ];
        foreach ($employmentIds as $employmentId) {
            try {
                $outcome = $this->transactional(fn (): array => $this->materializeEmployment(
                    $supplierId,
                    $employmentId,
                    $importId,
                    $period,
                    $rates,
                    $userId,
                    $holidayWithoutMonthlyWage,
                    $paidEmployeeObstacle,
                ));
            } catch (PayrollInputConflictException|PayrollInputCancellationException|\InvalidArgumentException|\DomainException $e) {
                $report['skipped'][] = [
                    'employment_id' => $employmentId,
                    'meaning' => null,
                    'reason' => $e->getMessage(),
                ];
                continue;
            }
            foreach (['created', 'updated', 'unchanged', 'cancelled'] as $key) {
                $report[$key] += $outcome[$key];
            }
            array_push($report['skipped'], ...$outcome['skipped']);
            array_push($report['warnings'], ...$outcome['warnings']);
        }

        return $report;
    }

    /**
     * Plánované minuty svátků měsíce, které leží uvnitř schválené pracovní
     * neschopnosti nebo karantény vztahu.
     */
    private function holidayMinutesInSickness(int $supplierId, int $employmentId, string $periodStart): int
    {
        $periodEnd = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $holidays = PayrollWorkCalendarSchedule::holidaysBetween(new CzechHolidayCalendar(), $periodStart, $periodEnd);
        if ($holidays === []) {
            return 0;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT date_from, date_to FROM payroll_absences
              WHERE supplier_id = ? AND employment_id = ? AND status = "approved"
                AND absence_type IN ("dpn", "quarantine")
                AND date_from <= ? AND date_to >= ?'
        );
        $stmt->execute([$supplierId, $employmentId, $periodEnd, $periodStart]);
        $dates = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach (array_keys($holidays) as $date) {
                if ($date >= (string) $row['date_from'] && $date <= (string) $row['date_to']) {
                    $dates[$date] = true;
                }
            }
        }
        if ($dates === []) {
            return 0;
        }

        return array_sum((new PayrollWorkCalendarSchedule($this->db))->plannedMinutes($supplierId, $employmentId, array_keys($dates)));
    }

    public static function externalId(string $period, int $employmentId, string $meaning): string
    {
        return self::EXTERNAL_ID_PREFIX . "{$period}:{$employmentId}:{$meaning}";
    }

    /**
     * Minuty z millihodin podkladů, zaokrouhlené na celou minutu. Doba
     * zapsaná v podkladech jako h:mm (7:20) přijde jako 7,333 h a teprve
     * zaokrouhlení ji vrátí na skutečných 440 minut.
     */
    public static function minutes(int $millihours): int
    {
        if ($millihours < 0) {
            throw new \InvalidArgumentException('Hodiny v souhrnu nesmí být záporné.');
        }

        return intdiv($millihours * 60 + 500, 1000);
    }

    /**
     * @return array{
     *   created:int,updated:int,unchanged:int,cancelled:int,
     *   skipped:list<array{employment_id:int,meaning:?string,reason:string}>,
     *   warnings:list<array{employment_id:int,meaning:string,message:string}>
     * }
     */
    private function materializeEmployment(
        int $supplierId,
        int $employmentId,
        int $importId,
        string $period,
        ImportAbsenceCompensationRates $rates,
        ?int $userId,
        bool $holidayWithoutMonthlyWage = false,
        bool $paidEmployeeObstacle = false,
    ): array {
        $outcome = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'cancelled' => 0, 'skipped' => [], 'warnings' => []];
        $periodStart = $period . '-01';
        $summary = $this->time->importSummary($supplierId, $employmentId, $periodStart);
        if ($summary === null || $summary['attendance_import_id'] !== $importId) {
            $outcome['skipped'][] = [
                'employment_id' => $employmentId,
                'meaning' => null,
                'reason' => 'Souhrn pracovního měsíce z této dávky se nezapsal nebo ho nahradila novější dávka, náhrady se z něj nepočítají.',
            ];

            return $outcome;
        }
        $values = $summary['values'];
        foreach (self::NOT_COMPUTED as $meaning => $reason) {
            if ($paidEmployeeObstacle && isset(self::PAID_EMPLOYEE_OBSTACLE[$meaning])) {
                continue;
            }
            if (($values[$meaning] ?? 0) > 0) {
                $outcome['skipped'][] = ['employment_id' => $employmentId, 'meaning' => $meaning, 'reason' => $reason];
            }
        }

        $average = null;
        $averageLoaded = false;
        $employeeId = null;
        $components = self::COMPONENTS;
        if ($holidayWithoutMonthlyWage && !$this->hasMonthlyWage($supplierId, $employmentId, $periodStart)) {
            $components += self::HOLIDAY;
        }
        if ($paidEmployeeObstacle) {
            $components += self::PAID_EMPLOYEE_OBSTACLE;
        }
        foreach ($components as $meaning => $code) {
            $millihours = $values[$meaning] ?? 0;
            $externalId = self::externalId($period, $employmentId, $meaning);
            $existing = $this->existingInput($supplierId, $employmentId, $periodStart, $externalId);
            $minutes = self::minutes($millihours);
            if ($meaning === 'holiday_hours' && $minutes > 0) {
                // Svátek uvnitř pracovní neschopnosti kryje náhrada při DPN nebo
                // dávka, ne náhrada za svátek (§ 115 odst. 3 ZP jen za svátek,
                // kdy by jinak pracoval). Souhrn ho mezi hodinami svátku nese.
                $sick = $this->holidayMinutesInSickness($supplierId, $employmentId, $periodStart);
                if ($sick > 0) {
                    $minutes = max(0, $minutes - $sick);
                    $millihours = intdiv($minutes * 1000, 60);
                }
            }
            if ($minutes <= 0) {
                if ($existing === null) {
                    continue;
                }
                if ($existing['status'] === 'draft') {
                    $this->cancel($supplierId, $existing);
                    ++$outcome['cancelled'];
                } else {
                    $outcome['skipped'][] = [
                        'employment_id' => $employmentId,
                        'meaning' => $meaning,
                        'reason' => 'Hodiny v podkladech už nejsou, ale vstup náhrady je schválený. Import ho nemění, opravte ho v Mzdových vstupech.',
                    ];
                }
                continue;
            }
            if (!$averageLoaded) {
                $average = $this->approvedAverage($supplierId, $employmentId, $periodStart);
                $averageLoaded = true;
            }
            if ($average === null) {
                $quarter = intdiv((int) substr($period, 5, 2) - 1, 3) + 1;
                $outcome['skipped'][] = [
                    'employment_id' => $employmentId,
                    'meaning' => $meaning,
                    'reason' => "Chybí schválený průměrný výdělek za {$quarter}. čtvrtletí " . substr($period, 0, 4)
                        . '. Doplňte ho v záložce Průměry a import použijte znovu.',
                ];
                continue;
            }
            if ($meaning === 'doctor_hours') {
                $outcome['warnings'][] = ['employment_id' => $employmentId, 'meaning' => $meaning, 'message' => self::DOCTOR_NOTICE];
            }

            $percent = isset(self::HOLIDAY[$meaning]) || isset(self::PAID_EMPLOYEE_OBSTACLE[$meaning])
                ? self::HOLIDAY_PERCENT
                : $rates->percentFor($meaning);
            $amount = LeaveCompensationCalculator::calculateMinutes(
                $average['average_hourly_minor'],
                $minutes,
                $percent,
                $rounding ??= PayrollWageRoundingPolicy::forSupplier($this->db, $supplierId),
            );
            $componentId = $this->componentId($supplierId, $code, $periodStart);
            if ($existing !== null) {
                $trace = self::decodedTrace($existing['source_snapshot_json'] ?? null);
                $same = (int) $existing['amount_minor'] === $amount
                    && (int) ($existing['quantity_milliunits'] ?? -1) === $millihours
                    && (int) $existing['component_id'] === $componentId
                    && ($trace['average_snapshot_id'] ?? null) === $average['id']
                    && ($trace['rate_percent'] ?? null) === $percent;
                if ($same) {
                    ++$outcome['unchanged'];
                    continue;
                }
                if ($existing['status'] !== 'draft') {
                    $outcome['skipped'][] = [
                        'employment_id' => $employmentId,
                        'meaning' => $meaning,
                        'reason' => 'Hodiny se v podkladech změnily, ale vstup náhrady je už schválený. Import schválený vstup nepřepisuje, opravte ho v Mzdových vstupech.',
                    ];
                    continue;
                }
                $this->cancel($supplierId, $existing);
            }

            $snapshot = CanonicalJson::encode([
                'kind' => 'import_absence_compensation.v1',
                'attendance_import_id' => $importId,
                'time_month_import_summary_id' => $summary['id'],
                'period_start' => $periodStart,
                'meaning' => $meaning,
                'source' => $summary['sources'][$meaning] ?? null,
                'millihours' => $millihours,
                'minutes' => $minutes,
                'average_hourly_minor' => $average['average_hourly_minor'],
                'average_snapshot_id' => $average['id'],
                'rate_percent' => $percent,
                'amount_minor' => $amount,
                'rounding' => $rounding->traceLabel(),
                'rounding_basis' => $rounding->basisLabel(),
                'entitlement_basis' => self::ENTITLEMENT_BASIS[$meaning],
            ]);
            $employeeId ??= $this->employeeId($supplierId, $employmentId);
            $this->inputs->create($supplierId, [
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                'component_id' => $componentId,
                'period_start' => $periodStart,
                'source_period_start' => null,
                'amount_minor' => $amount,
                'quantity_milliunits' => $millihours,
                'source_kind' => 'absence',
                'external_id' => $externalId,
                'source_snapshot_json' => $snapshot,
                'source_snapshot_hash' => hash('sha256', $snapshot, true),
            ], $userId);
            $existing === null ? ++$outcome['created'] : ++$outcome['updated'];
        }

        return $outcome;
    }

    /**
     * Schválený a podporovaný průměr čtvrtletí, do kterého období patří —
     * stejné pravidlo jako u absence s daty.
     *
     * @return array{id:int,average_hourly_minor:int}|null
     */
    private function approvedAverage(int $supplierId, int $employmentId, string $periodStart): ?array
    {
        $year = (int) substr($periodStart, 0, 4);
        $quarter = intdiv((int) substr($periodStart, 5, 2) - 1, 3) + 1;
        $snapshot = $this->averages->findApproved($supplierId, $employmentId, $year, $quarter);
        if ($snapshot === null
            || ($snapshot['support_status'] ?? null) !== 'supported'
            || !is_int($snapshot['average_hourly_minor'] ?? null)
            || $snapshot['average_hourly_minor'] <= 0
        ) {
            return null;
        }

        return [
            'id' => PayrollTimeValue::int($snapshot['id'] ?? null, 'average_snapshot_id'),
            'average_hourly_minor' => $snapshot['average_hourly_minor'],
        ];
    }

    /** Má vztah v měsíci aktivní předpis základní měsíční mzdy (druh `base_wage`)? */
    private function hasMonthlyWage(int $supplierId, int $employmentId, string $periodStart): bool
    {
        $periodEnd = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $statement = $this->db->pdo()->prepare(
            "SELECT 1
               FROM payroll_recurring_components recurring
               JOIN payroll_component_definitions component
                 ON component.supplier_id = recurring.supplier_id AND component.id = recurring.component_id
              WHERE recurring.supplier_id = ? AND recurring.employment_id = ? AND recurring.is_active = 1
                AND component.component_kind = 'base_wage'
                AND recurring.valid_from <= ? AND (recurring.valid_to IS NULL OR recurring.valid_to >= ?)
              LIMIT 1"
        );
        $statement->execute([$supplierId, $employmentId, $periodEnd, $periodStart]);

        return $statement->fetchColumn() !== false;
    }

    /** @return array<string,mixed>|null */
    private function existingInput(int $supplierId, int $employmentId, string $periodStart, string $externalId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                AND source_kind = "absence" AND external_id = ? AND status <> "cancelled"
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $employmentId, $periodStart, $externalId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $input */
    private function cancel(int $supplierId, array $input): void
    {
        $this->inputs->cancel(
            $supplierId,
            PayrollTimeValue::int($input['id'] ?? null, 'input_id'),
            PayrollTimeValue::int($input['row_version'] ?? null, 'row_version'),
        );
    }

    /** @return array<string,mixed> */
    private static function decodedTrace(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function employeeId(int $supplierId, int $employmentId): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT employee_id FROM payroll_employments WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$supplierId, $employmentId]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new \OutOfBoundsException('Pracovní vztah z dávky importu nebyl nalezen.');
        }

        return PayrollTimeValue::int($id, 'employee_id');
    }

    private function componentId(int $supplierId, string $code, string $periodStart): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = ? AND is_active = 1
                AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC LIMIT 1',
        );
        $statement->execute([$supplierId, $code, $periodStart, $periodStart]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new \DomainException("Pro náhradu mzdy chybí účinná mzdová složka {$code}.");
        }

        return PayrollTimeValue::int($id, 'component_id');
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function transactional(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }
        try {
            $result = $callback();
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }

            return $result;
        } catch (\Throwable $e) {
            if ($owns) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
            throw $e;
        }
    }
}
