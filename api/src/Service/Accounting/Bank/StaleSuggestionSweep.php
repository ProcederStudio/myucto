<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\BankPostingSuggestionRepository;
use MyInvoice\Service\Accounting\AutoPostingPolicyService;
use PDO;

/**
 * Periodický přepočet návrhů, jejichž důvod mezitím přestal platit.
 *
 * PROČ: {@see BankPostingService::handleTransaction()} se nad pohybem spustí jen při
 * importu výpisu, při akci uživatele a z `api/bin/backfill-bank-posting.php`. Kdo
 * vyhodnotí úhradu DŘÍV, než v deníku vznikne předpis závazku, dostane návrh s
 * poznámkou „Předpis na zúčtovacím účtu chybí" — a od té chvíle už se ho nikdo
 * nezeptá znovu. Na ostrých datech to byly vteřiny: v 9:33:12 vznikl návrh na úhradu
 * DPH, v 9:33:38 se zaúčtovalo čtvrtletní zúčtování DPH. Poznámka byla pravdivá při
 * zápisu a lživá o půl minuty později. V běžném provozu to skoro nevadí (dubnová
 * platba se importuje až po březnovém zúčtování), po přestavbě deníku, backfillu nebo
 * po pozdě doúčtovaném dokladu ale zůstane fronta plná neplatných důvodů.
 *
 * CO DĚLÁ: pro každý kandidát zavolá TÝŽ engine jako import (žádná druhá logika) a
 * podle výsledku buď nechá zaúčtovat, nebo přepíše poznámku na aktuální pravdu.
 *
 * ČEHO SE NEDOTKNE:
 *   - uzavřeného ani zamčeného období — kontroluje se PŘED voláním enginu
 *     ({@see AutoPostingPolicyService::isOpenDate()}), takže se do zavřeného roku
 *     nezaloží ani návrh,
 *   - pohybu s živým zápisem (přepočet nikdy nepřepisuje hotové účetnictví),
 *   - toho, co člověk rozhodl: odmítnutý návrh, `approve_override`, `manual_post`,
 *     `unpost` i odložený (snooze) návrh se přeskakují,
 *   - důvodů, které přepočet změnit nemůže — seznam
 *     {@see AutoPostingPolicyService::TRANSIENT_NOTES} je záměrně krátký.
 *
 * IDEMPOTENCE: druhý běh hned po prvním nezaúčtuje nic navíc — zaúčtovaný pohyb má
 * živý zápis a jeho návrh už není `pending`, přepsaná poznámka se rovná aktuálnímu
 * důvodu, takže spadne do `unchanged`.
 */
final class StaleSuggestionSweep
{
    public function __construct(
        private readonly Connection $db,
        private readonly BankPostingService $service,
        private readonly BankPostingSuggestionRepository $suggestions,
        private readonly AutoPostingPolicyService $policy,
    ) {}

    /**
     * @param bool $dryRun nic nezapíše (engine zapisuje, na konci se vše roluje zpět)
     * @param int|null $supplierId omezení na jednu firmu (event trigger / test)
     * @return array{
     *   dry_run:bool, candidates:int, reevaluated:int, posted:int, refreshed:int,
     *   queued:int, unchanged:int, skipped:int, skip_reasons:array<string,int>,
     *   per_supplier:array<int,array{posted:int,refreshed:int,queued:int}>,
     *   errors:list<array{tx_id:int,message:string}>
     * }
     */
    public function run(bool $dryRun = false, ?int $supplierId = null): array
    {
        $report = [
            'dry_run'      => $dryRun,
            'candidates'   => 0,
            'reevaluated'  => 0,
            'posted'       => 0,
            'refreshed'    => 0,
            'queued'       => 0,
            'unchanged'    => 0,
            'skipped'      => 0,
            'skip_reasons' => [],
            'per_supplier' => [],
            'errors'       => [],
        ];

        $candidates = $this->candidates($supplierId);
        $report['candidates'] = count($candidates);
        if ($candidates === []) {
            return $report;
        }

        // Dry-run: engine umí zapisovat jen doopravdy, takže se všechno na konci roluje
        // zpět. Nested-safe stejně jako {@see BankPostingBackfill::run()} — pod testovací
        // transakcí se izoluje SAVEPOINTem a při běžném dokončení vrátí jen vlastní změny.
        $pdo = $this->db->pdo();
        $ownTx = $dryRun && !$pdo->inTransaction();
        $useSavepoint = $dryRun && $pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        } elseif ($useSavepoint) {
            $pdo->exec('SAVEPOINT stale_sweep_dry');
        }
        try {
            foreach ($candidates as $candidate) {
                $this->handle($candidate, $report);
            }
        } catch (\Throwable $e) {
            TransactionFailure::rollbackIfAborted($pdo, $e);
            throw $e;
        } finally {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif ($useSavepoint && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT stale_sweep_dry');
            }
        }

        return $report;
    }

    /**
     * @param array{suggestion_id:?int, supplier_id:int, tx_id:int, posted_at:string, note:?string} $candidate
     * @param array<string,mixed> $report
     */
    private function handle(array $candidate, array &$report): void
    {
        $supplierId = $candidate['supplier_id'];
        $txId = $candidate['tx_id'];
        try {
            if (!$this->policy->isOpenDate($supplierId, $candidate['posted_at'])) {
                $this->tallySkip($report, 'period_closed');
                return;
            }
            $report['reevaluated']++;
            $result = $this->service->handleTransaction($txId);
            $action = (string) $result['action'];
            $reason = isset($result['reason']) ? (string) $result['reason'] : null;

            if ($action === 'posted') {
                $report['posted']++;
                $this->tallySupplier($report, $supplierId, 'posted');
                return;
            }
            if ($action !== 'suggested') {
                $this->tallySkip($report, $reason ?? 'unknown');
                if ($reason === 'error' && isset($result['message'])) {
                    $report['errors'][] = ['tx_id' => $txId, 'message' => mb_substr((string) $result['message'], 0, 300)];
                }
                return;
            }

            // Návrh zůstává návrhem. `createIfNoPending()` u existujícího řádku poznámku
            // nepřepisuje, takže to musí udělat přepočet — jinak by ve frontě dál svítil
            // důvod, kvůli kterému sem tenhle kód vůbec vznikl.
            if ($candidate['suggestion_id'] === null) {
                $report['queued']++;
                $this->tallySupplier($report, $supplierId, 'queued');
                return;
            }
            if ($reason === $candidate['note']) {
                $report['unchanged']++;
                return;
            }
            $newId = isset($result['suggestion_id']) ? (int) $result['suggestion_id'] : 0;
            if ($newId === $candidate['suggestion_id']) {
                $this->suggestions->refreshNote($supplierId, $candidate['suggestion_id'], $reason);
            }
            $report['refreshed']++;
            $this->tallySupplier($report, $supplierId, 'refreshed');
        } catch (\Throwable $e) {
            if (TransactionFailure::aborted($e)) {
                throw $e;
            }
            $this->tallySkip($report, 'error');
            $report['errors'][] = ['tx_id' => $txId, 'message' => mb_substr($e->getMessage(), 0, 300)];
        }
    }

    /**
     * Dva vstupy, jedna fronta: uložený transientní důvod a spárovaný pohyb, u kterého
     * engine kvůli nezaúčtovanému předpisu dokladu nezaložil vůbec nic.
     *
     * @return list<array{suggestion_id:?int, supplier_id:int, tx_id:int, posted_at:string, note:?string}>
     */
    private function candidates(?int $supplierId): array
    {
        $candidates = $this->suggestions->transientPendingCandidates(
            AutoPostingPolicyService::TRANSIENT_NOTES,
            $supplierId,
        );
        $seen = [];
        foreach ($candidates as $row) {
            $seen[$row['tx_id']] = true;
        }
        foreach ($this->doubleEntrySuppliers($supplierId) as $sid) {
            foreach ($this->suggestions->matchedUnpostedWithoutSuggestion($sid) as $row) {
                if (isset($seen[$row['tx_id']])) {
                    continue;
                }
                $seen[$row['tx_id']] = true;
                $candidates[] = $row;
            }
        }
        return $candidates;
    }

    /** @return list<int> */
    private function doubleEntrySuppliers(?int $supplierId): array
    {
        $sql = "SELECT id FROM supplier WHERE accounting_mode = 'double_entry'";
        $params = [];
        if ($supplierId !== null) {
            $sql .= ' AND id = ?';
            $params[] = $supplierId;
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY id');
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param array<string,mixed> $report */
    private function tallySkip(array &$report, string $reason): void
    {
        $report['skipped']++;
        $report['skip_reasons'][$reason] = ($report['skip_reasons'][$reason] ?? 0) + 1;
    }

    /** @param array<string,mixed> $report */
    private function tallySupplier(array &$report, int $supplierId, string $key): void
    {
        $report['per_supplier'][$supplierId] ??= ['posted' => 0, 'refreshed' => 0, 'queued' => 0];
        $report['per_supplier'][$supplierId][$key]++;
    }
}
