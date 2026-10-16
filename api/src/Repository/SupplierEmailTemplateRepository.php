<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class SupplierEmailTemplateRepository
{
    public function __construct(private readonly Connection $db) {}

    public function find(int $supplierId, string $code, string $locale): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM supplier_email_templates WHERE supplier_id = ? AND code = ? AND locale = ?');
        $stmt->execute([$supplierId, $code, $locale]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function save(int $supplierId, string $code, string $locale, string $subject, string $html, string $text, ?int $userId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO supplier_email_templates (supplier_id, code, locale, subject, body_html, body_text, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE subject = VALUES(subject), body_html = VALUES(body_html), body_text = VALUES(body_text), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$supplierId, $code, $locale, $subject, $html, $text, $userId]);
    }

    public function delete(int $supplierId, string $code, string $locale): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM supplier_email_templates WHERE supplier_id = ? AND code = ? AND locale = ?');
        $stmt->execute([$supplierId, $code, $locale]);
    }
}
