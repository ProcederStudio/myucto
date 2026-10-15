<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;

/** Nastavení doručení je aktuální volba dodavatele, nikoli historický snapshot dokladu. */
final class InvoiceIsdocDeliverySettings
{
    public function __construct(private readonly Connection $db) {}

    public function publicEnabled(array $invoice): bool
    {
        return InvoiceIsdocService::isExportable($invoice)
            && $this->supplierFlag((int) ($invoice['supplier_id'] ?? 0), 'public_isdoc_enabled');
    }

    public function emailEnabled(array $invoice): bool
    {
        if (!InvoiceIsdocService::isExportable($invoice)) {
            return false;
        }
        $supplierId = (int) ($invoice['supplier_id'] ?? 0);
        $clientId = (int) ($invoice['client_id'] ?? 0);
        if ($supplierId > 0 && $clientId > 0) {
            // Klientská preference patří výhradně tenantovi faktury, stejně jako
            // výchozí volba dodavatele. Snapshot dokladu doručování nezamyká.
            $stmt = $this->db->pdo()->prepare('SELECT email_attach_isdoc FROM clients WHERE id = ? AND supplier_id = ?');
            $stmt->execute([$clientId, $supplierId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row !== false && $row['email_attach_isdoc'] !== null) {
                return (bool) $row['email_attach_isdoc'];
            }
        }
        return $this->supplierFlag($supplierId, 'email_attach_isdoc');
    }

    private function supplierFlag(int $supplierId, string $column): bool
    {
        if ($supplierId <= 0) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare("SELECT {$column} FROM supplier WHERE id = ?");
        $stmt->execute([$supplierId]);
        return (bool) $stmt->fetchColumn();
    }
}
