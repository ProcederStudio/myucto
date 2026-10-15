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
        return InvoiceIsdocService::isExportable($invoice)
            && $this->supplierFlag((int) ($invoice['supplier_id'] ?? 0), 'email_attach_isdoc');
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
