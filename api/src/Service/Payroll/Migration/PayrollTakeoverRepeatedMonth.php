<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Payroll\Time\PayrollTimeService;
use PDO;

/**
 * Opakovaný převod měsíce, který už počítá MyÚčto.
 *
 * Převod, který se pouští znovu nad změněným exportem nebo opravenou verzí převodu,
 * zakládá pro měsíc novou dávku docházky. Dva zbytky předchozí dávky by ji ale
 * popřely:
 *
 * - **schválené pracovní měsíce**: souhrn docházky z importu jde zapsat jen do
 *   otevřeného měsíce, takže nový souhrn (třeba svátky zvlášť) by se tiše nezapsal;
 * - **mzdové vstupy, které nová dávka nenese**: vstup má identitu
 *   `attendance:{měsíc}:{vztah}:{složka}`, takže tutéž složku nová dávka opraví, ale
 *   vstup složky, kterou nová dávka vede jinak (obecný příplatek → zákonný příplatek),
 *   by zůstal schválený vedle nového a mzda by ho vyplatila dvakrát.
 *
 * Obojí se dělá jen v měsíci, pro který ještě neexistuje mzdový běh se zamčenými
 * vstupy: rozpracovaný výpočet se tím nerozbije a hotový se nepřepíše. Každý krok jde
 * do protokolu převodu.
 */
final class PayrollTakeoverRepeatedMonth
{
    /** Stavy běhu, ve kterých vstupy měsíce ještě nikdo nezamkl. */
    private const OPEN_RUN_STATUSES = ['draft', 'cancelled'];
    private const SAVEPOINT = 'payroll_takeover_superseded_input';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollTimeService $time,
        private readonly PayrollInputRepository $inputs,
    ) {}

    /**
     * Mzdový běh měsíce, který už zamkl vstupy (`null` = měsíc jde převést znovu).
     *
     * @return array{id:int,status:string}|null
     */
    public function lockingRun(int $supplierId, string $period): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, status FROM payroll_runs
              WHERE supplier_id = ? AND period_start = ? AND run_kind = 'calculated'
                AND status NOT IN ('" . implode("','", self::OPEN_RUN_STATUSES) . "')
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$supplierId, $period . '-01']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ['id' => (int) $row['id'], 'status' => (string) $row['status']];
    }

    /**
     * Znovuotevření schválených pracovních měsíců, které nesou souhrn z předchozí dávky
     * převodu. Jiné pracovní měsíce (docházka zadaná v MyÚčtu) převod neotevírá.
     *
     * @param list<int> $previousBatchIds dávky docházky z dřívějších převodů téhož měsíce
     */
    public function reopenWorkMonths(int $supplierId, string $period, array $previousBatchIds, ?int $userId, string $label, ImportProtocol $protocol, string $step): int
    {
        if ($previousBatchIds === []) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($previousBatchIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT month.employment_id, month.row_version
               FROM payroll_time_months month
               JOIN payroll_time_month_import_summaries summary
                 ON summary.supplier_id = month.supplier_id
                AND summary.time_month_id = month.id
                AND summary.time_month_revision_no = month.revision_no
              WHERE month.supplier_id = ? AND month.period_start = ?
                AND month.status = 'approved' AND month.work_source = 'import_summary'
                AND summary.attendance_import_id IN ({$marks})"
        );
        $stmt->execute([$supplierId, $period . '-01', ...$previousBatchIds]);
        $reopened = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            try {
                $this->time->reopen($supplierId, $period, [
                    'employment_id' => (int) $row['employment_id'],
                    'row_version' => (int) $row['row_version'],
                    'reason' => "Opakovaný převod z {$label}: nový souhrn docházky.",
                ], $userId);
                $reopened++;
            } catch (\InvalidArgumentException|\DomainException $e) {
                $protocol->count($step, 'work_months_reopen_failed');
            }
        }
        if ($reopened > 0) {
            $protocol->count($step, 'work_months_reopened', $reopened);
            $protocol->info($step, 'work_months_reopened', sprintf(
                '%s: pracovních měsíců znovu otevřeno %d. Nesly souhrn docházky z dřívějšího převodu '
                . 'a nový souhrn jde zapsat jen do otevřeného měsíce.',
                $period,
                $reopened,
            ), ['period' => $period]);
        }

        return $reopened;
    }

    /**
     * Zrušení mzdových vstupů dřívějších dávek převodu, které nová dávka už nenese.
     *
     * @param list<int> $previousBatchIds dávky docházky z dřívějších převodů téhož měsíce
     */
    public function supersedeInputs(int $supplierId, string $period, array $previousBatchIds, int $newBatchId, string $label, ImportProtocol $protocol, string $step): int
    {
        $previousBatchIds = array_values(array_diff($previousBatchIds, [$newBatchId]));
        if ($previousBatchIds === [] || $newBatchId <= 0) {
            return 0;
        }
        $pdo = $this->db->pdo();
        $marks = implode(',', array_fill(0, count($previousBatchIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT DISTINCT input.id, input.status, input.row_version, component.code
               FROM payroll_attendance_imports batch
               JOIN payroll_input_import_rows import_row
                 ON import_row.supplier_id = batch.supplier_id
                AND import_row.import_id = batch.input_import_id
               JOIN payroll_inputs input
                 ON input.supplier_id = import_row.supplier_id
                AND input.id = import_row.input_id
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id
                AND component.id = input.component_id
              WHERE batch.supplier_id = ? AND batch.id IN ({$marks})
                AND input.period_start = ? AND input.status IN ('draft', 'approved')
                AND input.external_id NOT IN (
                    SELECT current_row.external_id
                      FROM payroll_attendance_imports current_batch
                      JOIN payroll_input_import_rows current_row
                        ON current_row.supplier_id = current_batch.supplier_id
                       AND current_row.import_id = current_batch.input_import_id
                     WHERE current_batch.supplier_id = ? AND current_batch.id = ?
                       AND current_row.external_id IS NOT NULL
                )"
        );
        $stmt->execute([$supplierId, ...$previousBatchIds, $period . '-01', $supplierId, $newBatchId]);
        $cancelled = [];
        $failed = 0;
        $outer = $pdo->inTransaction();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            try {
                // Vrácení do konceptu a zrušení jdou spolu: vstup nesmí zůstat napůl.
                TransactionFailure::retrying($pdo, fn () => $this->atomic($pdo, function () use ($supplierId, $row): void {
                    $id = (int) $row['id'];
                    $version = (int) $row['row_version'];
                    if ($row['status'] === 'approved') {
                        $this->inputs->revertToDraft($supplierId, $id, $version);
                        $version++;
                    }
                    $this->inputs->cancel($supplierId, $id, $version);
                }));
                $code = (string) $row['code'];
                $cancelled[$code] = ($cancelled[$code] ?? 0) + 1;
            } catch (\Throwable $e) {
                // Odvolaná transakce (1020/1213) nevrátila jen tenhle vstup, ale i dávku
                // docházky volajícího. Zbytek převodu by běžel v autocommitu a varování
                // by tvrdilo, že vstupy vstoupily do běhu.
                if (TransactionFailure::aborted($e) || ($outer && !$pdo->inTransaction())) {
                    throw $e;
                }
                $failed++;
            }
        }
        if ($failed > 0) {
            $protocol->count($step, 'superseded_inputs_failed', $failed);
            $protocol->warn($step, 'superseded_inputs_failed', sprintf(
                '%s: %d mzdových vstupů z dřívějšího převodu nejde zrušit (už vstoupily do mzdového běhu). '
                . 'Nová dávka je nenese; zkontrolujte je v Mzdy → Vstupy, jinak se vyplatí dvakrát.',
                $period,
                $failed,
            ), ['period' => $period]);
        }
        if ($cancelled === []) {
            return 0;
        }
        ksort($cancelled);
        $list = [];
        foreach ($cancelled as $code => $count) {
            $list[] = "{$code} ({$count}×)";
        }
        $total = array_sum($cancelled);
        $protocol->count($step, 'superseded_inputs', $total);
        $protocol->info($step, 'superseded_inputs', sprintf(
            '%s: zrušeno %d mzdových vstupů z dřívějšího převodu z %s, které nová dávka už nevede: %s.',
            $period,
            $total,
            $label,
            implode(', ', $list),
        ), ['period' => $period]);

        return $total;
    }

    /** @param callable():void $work */
    private function atomic(PDO $pdo, callable $work): void
    {
        $owns = !$pdo->inTransaction();
        $owns ? $pdo->beginTransaction() : $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        try {
            $work();
            $owns ? $pdo->commit() : $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e) || !$pdo->inTransaction()) {
                throw $e;
            }
            if ($owns) {
                $pdo->rollBack();
                throw $e;
            }
            try {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            } catch (\PDOException) {
                // Savepoint zmizel s transakcí, kterou MariaDB odvolala pod přebalenou chybou.
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
