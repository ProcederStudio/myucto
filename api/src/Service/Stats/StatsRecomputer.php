<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stats;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use PDO;

/**
 * Přepočítává cache tabulky `project_revenue_cache` a `client_revenue_cache`
 * po každé změně faktur (create/update/issue/cancel/delete).
 *
 * Dvě různé filtrace v rámci jednoho dotazu:
 *  - `revenue` / `invoice_count` — jen `invoice` + `credit_note` (proforma není daňový doklad)
 *  - `last_invoice_date` — jakákoli **aktivita** na projektu/klientu, vč. proform
 *    (status IN issued/sent/reminded/paid, type != cancellation). Reflektuje, kdy se na
 *    projektu naposled něco dělo, ne jen finální fakturace.
 *
 * Idempotentní — řádky entity se přepíší upsertem a měny bez dokladu se smažou.
 */
final class StatsRecomputer
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Přepočte cache pro projekt podle aktuálního stavu invoices.
     */
    public function recomputeProject(int $projectId): void
    {
        $this->recompute('project_revenue_cache', 'project_id', $projectId);
    }

    /**
     * Přepočte cache pro klienta — agreguje VŠECHNY jeho faktury (s project_id i bez).
     */
    public function recomputeClient(int $clientId): void
    {
        $this->recompute('client_revenue_cache', 'client_id', $clientId);
    }

    /**
     * Zapisuje jen konkrétní klíče (entita, měna): upsert spočtených řádků a smazání
     * měn, ve kterých entita už žádný doklad nemá. Plošný `DELETE … WHERE entita = ?`
     * bral pod REPEATABLE READ zámky i na mezery mezi klíči, takže souběžně vystavené
     * faktury končily deadlockem 1213. Vlastní transakce proto běží pod READ COMMITTED.
     *
     * Stávající klíče se čtou PŘED agregací: smazat smí jen měnu, kterou agregace
     * z dokladů stejně čerstvých nebo novějších nenašla.
     *
     * @param 'project_revenue_cache'|'client_revenue_cache' $table
     * @param 'project_id'|'client_id' $column
     */
    private function recompute(string $table, string $column, int $id): void
    {
        if ($id <= 0) return;
        $pdo = $this->db->pdo();

        // Volající smí přepočet pustit uvnitř vlastní transakce (IssueInvoiceAction to
        // výslovně podporuje), pak se jeho transakce nezakládá ani nepotvrzuje tady.
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
        }
        try {
            $existing = $pdo->prepare("SELECT currency_id FROM {$table} WHERE {$column} = ?");
            $existing->execute([$id]);
            $stale = array_fill_keys(array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN)), true);

            $stmt = $pdo->prepare(
                "SELECT i.currency_id,
                        SUM(CASE WHEN i.invoice_type IN ('invoice', 'credit_note', 'tax_document')
                                  THEN CASE WHEN s.is_vat_payer = 1 THEN i.total_without_vat ELSE i.total_with_vat END
                                  ELSE 0 END) AS revenue,
                        SUM(CASE WHEN i.invoice_type IN ('invoice', 'credit_note', 'tax_document')
                                  THEN 1 ELSE 0 END) AS cnt,
                        MAX(COALESCE(i.tax_date, i.issue_date)) AS last_date
                   FROM invoices i
                   JOIN supplier s ON s.id = i.supplier_id
                  WHERE i.{$column} = ?
                    AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                    AND i.invoice_type != 'cancellation'
               GROUP BY i.currency_id"
            );
            $stmt->execute([$id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $upsert = $pdo->prepare(
                "INSERT INTO {$table} ({$column}, currency_id, revenue, last_invoice_date, invoice_count)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE revenue = VALUES(revenue),
                                         last_invoice_date = VALUES(last_invoice_date),
                                         invoice_count = VALUES(invoice_count)"
            );
            foreach ($rows as $r) {
                unset($stale[(int) $r['currency_id']]);
                $upsert->execute([
                    $id,
                    (int) $r['currency_id'],
                    (float) $r['revenue'],
                    $r['last_date'] ?: null,
                    (int) $r['cnt'],
                ]);
            }

            $delete = $pdo->prepare("DELETE FROM {$table} WHERE {$column} = ? AND currency_id = ?");
            foreach (array_keys($stale) as $currencyId) {
                $delete->execute([$id, $currencyId]);
            }
            if ($owns) $pdo->commit();
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Convenience: zavolá recompute pro project i client jedné faktury.
     * Volat po create/update/issue/cancel/markPaid faktury.
     *
     * Pro DELETE faktury volat dvě sady — viz `recomputeForChangedInvoice()` níže.
     */
    public function recomputeForInvoiceId(int $invoiceId): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT client_id, project_id FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;
        $this->recomputeClient((int) $row['client_id']);
        if (!empty($row['project_id'])) {
            $this->recomputeProject((int) $row['project_id']);
        }
    }

    /**
     * Přepočet po už potvrzeném zápisu dokladu (vystavení, úhrada). Cache je odvozená
     * a dopočte ji i příští přepočet, takže její chyba nesmí změnit výsledek operace,
     * která je už uložená: volající by vrátil chybu a klient API by doklad nebo úhradu
     * poslal znovu. Odvolaný přepočet (1020/1213) se zopakuje, jiná chyba se zaloguje.
     * Uvnitř cizí transakce se odvolání předá dál, protože je odvolaná i ta transakce.
     */
    public function recomputeAfterCommit(int $invoiceId): void
    {
        $pdo = $this->db->pdo();
        $outer = $pdo->inTransaction();
        try {
            TransactionFailure::retrying($pdo, fn () => $this->recomputeForInvoiceId($invoiceId));
        } catch (\Throwable $e) {
            if ($outer && TransactionFailure::aborted($e)) {
                throw $e;
            }
            error_log('Přepočet statistik k faktuře #' . $invoiceId . ' selhal: ' . $e->getMessage());
        }
    }

    /**
     * Recompute pro klienta + projekt z předaných ID (potřebné po DELETE,
     * kdy už řádek faktury neexistuje).
     */
    public function recomputeForIds(?int $clientId, ?int $projectId): void
    {
        if ($clientId !== null && $clientId > 0) $this->recomputeClient($clientId);
        if ($projectId !== null && $projectId > 0) $this->recomputeProject($projectId);
    }

    /**
     * Dávkový přepočet po hromadné operaci (import, bulk reissue) — KAŽDÉHO dotčeného
     * klienta a projektu přepočte PRÁVĚ JEDNOU, bez ohledu na to, kolikrát se v předaných
     * polích opakuje.
     *
     * Volat MÍSTO opakovaného `recomputeForInvoiceId()`/`recomputeClient()` po každém
     * dokladu — dávka stovek dokladů nad týmiž klienty by jinak znamenala stovky
     * zbytečných přepisů stejných řádků cache.
     *
     * @param iterable<int> $clientIds
     * @param iterable<int> $projectIds
     */
    public function recomputeMany(iterable $clientIds, iterable $projectIds = []): void
    {
        $uniqueClients = [];
        foreach ($clientIds as $id) {
            $id = (int) $id;
            if ($id > 0) $uniqueClients[$id] = true;
        }
        foreach (array_keys($uniqueClients) as $id) {
            $this->recomputeClient($id);
        }

        $uniqueProjects = [];
        foreach ($projectIds as $id) {
            $id = (int) $id;
            if ($id > 0) $uniqueProjects[$id] = true;
        }
        foreach (array_keys($uniqueProjects) as $id) {
            $this->recomputeProject($id);
        }
    }
}
