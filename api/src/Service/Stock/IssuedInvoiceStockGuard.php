<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\WarehouseRepository;
use PDO;

/**
 * Vynucená úprava vystaveného dokladu nesmí změnit to, co už pohnulo skladem.
 *
 * Výdejka (vratka) vzniká jen při vystavení ({@see StockIssueService::issueForInvoice()}).
 * Úprava přes `?force=1` ji znovu nespouští, takže přidaná karta se nikdy nevydala,
 * změněné množství zůstalo vydané v původní výši a smazaný řádek vydaný napořád.
 * Skladová část dokladu (karta, sklad, jednotka, množství) je proto po výdeji pevná:
 * oprava jde dobropisem, který vratku ocení původní cenou výdeje. Popis, cenu, DPH
 * a neskladové řádky úprava měnit smí; vazbu řádků výdejky na nové řádky dokladu
 * zachová {@see \MyInvoice\Repository\InvoiceRepository::replaceItems()}.
 *
 * Přijatá strana to řeší záměrně jinak: příjem z PF je ruční a částečný, takže přepsané
 * řádky PF stačí ohlásit (`pf_changed_after_receipt` v {@see StockReceiptService}) a o zbytku
 * rozhodne uživatel. Výdej k vydané faktuře je automatický, nikdo by ho nedorovnal.
 */
final class IssuedInvoiceStockGuard
{
    public function __construct(
        private readonly Connection $db,
        private readonly WarehouseRepository $warehouses,
    ) {}

    /**
     * Čísla zaúčtovaných skladových dokladů, které úpravu blokují. Prázdné pole =
     * úprava skladovou část nemění (nebo doklad skladem ještě nepohnul).
     *
     * @param list<array<string,mixed>>|array<int,mixed> $storedItems položky uloženého dokladu
     * @param list<array<string,mixed>>|array<int,mixed> $newItems    položky z těla požadavku
     * @return list<string>
     */
    public function lockedBy(int $supplierId, int $invoiceId, array $storedItems, array $newItems): array
    {
        $documents = $this->postedDocuments($supplierId, $invoiceId);
        if ($documents === []) {
            return [];
        }
        $defaultWarehouse = null;
        $warehouseOf = function (mixed $warehouseId) use ($supplierId, &$defaultWarehouse): int {
            if (is_numeric($warehouseId) && (int) $warehouseId > 0) {
                return (int) $warehouseId;
            }
            // Řádek bez skladu vydává výchozí sklad a editor ho při otevření doplní
            // do formuláře; bez sjednocení by úprava bez věcné změny skončila odmítnutím.
            $defaultWarehouse ??= (int) ($this->warehouses->getDefault($supplierId)['id'] ?? 0);
            return $defaultWarehouse;
        };

        return self::stockPart($storedItems, $warehouseOf) === self::stockPart($newItems, $warehouseOf)
            ? []
            : $documents;
    }

    /** @param list<string> $documents */
    public static function message(array $documents): string
    {
        return 'Faktura už pohnula skladem (' . implode(', ', $documents) . '). Úpravou vystavené faktury '
            . 'lze změnit popis, cenu i další údaje, ale ne skladové položky: kartu, množství, jednotku '
            . 'ani sklad. Úprava výdej znovu neprovede, takže by se sklad rozešel s fakturou. '
            . 'Opravte fakturu dobropisem, případně vystavte novou.';
    }

    /**
     * @param array<int,mixed>   $items
     * @param callable(mixed):int $warehouseOf
     * @return list<string>
     */
    private static function stockPart(array $items, callable $warehouseOf): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item) || ($item['item_kind'] ?? 'standard') === 'discount') {
                continue;
            }
            $stockItemId = is_numeric($item['stock_item_id'] ?? null) ? (int) $item['stock_item_id'] : 0;
            if ($stockItemId <= 0) {
                continue;
            }
            $out[] = implode('|', [
                $stockItemId,
                $warehouseOf($item['warehouse_id'] ?? null),
                // Stejné výchozí hodnoty, jaké zapíše replaceItems().
                (string) ($item['unit'] ?? 'ks'),
                number_format((float) ($item['quantity'] ?? 1), 3, '.', ''),
            ]);
        }
        sort($out);
        return $out;
    }

    /**
     * Čísla zaúčtovaných skladových dokladů faktury, navázaných hlavičkou i řádky.
     * Jediné místo, které odpovídá „faktura už pohnula skladem" (i pro výběr kusů).
     *
     * @return list<string>
     */
    public function postedDocuments(int $supplierId, int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT d.doc_number
               FROM stock_documents d
               JOIN stock_document_lines l ON l.document_id = d.id AND l.supplier_id = d.supplier_id
          LEFT JOIN invoice_items ii ON ii.id = l.invoice_item_id
              WHERE d.supplier_id = ? AND d.status = 'posted'
                AND (d.invoice_id = ? OR ii.invoice_id = ?)
              ORDER BY d.doc_number"
        );
        $stmt->execute([$supplierId, $invoiceId, $invoiceId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
