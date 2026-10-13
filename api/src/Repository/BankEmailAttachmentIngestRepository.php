<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Auditní stopa posouzených PDF příloh z e-mailové schránky bankovních avíz.
 *
 * Drží i ZAMÍTNUTÍ — bez nich by uživatel neviděl, proč se faktura z e-mailu
 * nenačetla, a heuristiku {@see \MyInvoice\Service\Import\InvoiceDocumentRecognizer}
 * by nešlo ladit. Zároveň je to dedup klíč: jednou posouzená příloha se při dalším
 * skenu už neextrahuje znovu.
 */
final class BankEmailAttachmentIngestRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array<string,mixed>|null
     */
    public function findBySha(int $supplierId, string $sha256): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM bank_email_attachment_ingests WHERE supplier_id = ? AND sha256 = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $sha256]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function record(array $data): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO bank_email_attachment_ingests
                (supplier_id, imap_account_id, message_id, sender, subject, filename, sha256, size_bytes,
                 status, reason, submission_id, target_supplier_id, bank_statement_id, matched_by, match_score)
             VALUES
                (:supplier_id, :imap_account_id, :message_id, :sender, :subject, :filename, :sha256, :size_bytes,
                 :status, :reason, :submission_id, :target_supplier_id, :bank_statement_id, :matched_by, :match_score)
             ON DUPLICATE KEY UPDATE
                status = VALUES(status), reason = VALUES(reason), submission_id = VALUES(submission_id),
                target_supplier_id = VALUES(target_supplier_id),
                bank_statement_id = VALUES(bank_statement_id),
                matched_by = VALUES(matched_by), match_score = VALUES(match_score)'
        );
        $target = isset($data['target_supplier_id']) ? (int) $data['target_supplier_id'] : 0;
        $stmt->execute([
            'supplier_id' => (int) $data['supplier_id'],
            'imap_account_id' => $data['imap_account_id'] ?? null,
            'message_id' => $this->truncate($data['message_id'] ?? null, 255),
            'sender' => $this->truncate($data['sender'] ?? null, 255),
            'subject' => $this->truncate($data['subject'] ?? null, 500),
            'filename' => (string) mb_substr((string) $data['filename'], 0, 255),
            'sha256' => (string) $data['sha256'],
            'size_bytes' => (int) ($data['size_bytes'] ?? 0),
            'status' => (string) $data['status'],
            'reason' => $this->truncate($data['reason'] ?? null, 1000),
            'submission_id' => $data['submission_id'] ?? null,
            // Firma schránky se neukládá jako cíl: NULL znamená „zůstalo doma".
            'target_supplier_id' => $target > 0 && $target !== (int) $data['supplier_id'] ? $target : null,
            'bank_statement_id' => $data['bank_statement_id'] ?? null,
            'matched_by' => $this->truncate($data['matched_by'] ?? null, 32),
            'match_score' => $data['match_score'] ?? null,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function recent(int $supplierId, int $limit = 50): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.*, im.name AS imap_account_name, s.status AS submission_status,
                    s.purchase_invoice_id,
                    COALESCE(NULLIF(target.display_name, ''), target.company_name) AS target_supplier_name
               FROM bank_email_attachment_ingests a
          LEFT JOIN bank_email_imap_settings im ON im.id = a.imap_account_id AND im.supplier_id = a.supplier_id
          LEFT JOIN purchase_invoice_submissions s
                 ON s.id = a.submission_id AND s.supplier_id = COALESCE(a.target_supplier_id, a.supplier_id)
          LEFT JOIN supplier target ON target.id = a.target_supplier_id
              WHERE a.supplier_id = ?
           ORDER BY a.created_at DESC, a.id DESC
              LIMIT ?"
        );
        $stmt->bindValue(1, $supplierId, \PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, min(200, $limit)), \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['supplier_id'] = (int) $row['supplier_id'];
            $row['imap_account_id'] = $row['imap_account_id'] !== null ? (int) $row['imap_account_id'] : null;
            $row['submission_id'] = $row['submission_id'] !== null ? (int) $row['submission_id'] : null;
            $row['target_supplier_id'] = $row['target_supplier_id'] !== null ? (int) $row['target_supplier_id'] : null;
            $row['bank_statement_id'] = $row['bank_statement_id'] !== null ? (int) $row['bank_statement_id'] : null;
            $row['purchase_invoice_id'] = $row['purchase_invoice_id'] !== null ? (int) $row['purchase_invoice_id'] : null;
            $row['size_bytes'] = (int) $row['size_bytes'];
            $row['match_score'] = $row['match_score'] !== null ? (float) $row['match_score'] : null;
        }
        return $rows;
    }

    /**
     * Podání se přestěhovalo do jiné firmy. Log přílohy zůstává ve firmě schránky,
     * jen ukáže, kam doklad odešel — jinak by jeho vazba po smazání starého podání
     * spadla na NULL a uživatel by doklad v logu ztratil.
     */
    public function followMovedSubmission(int $oldSubmissionId, int $newSubmissionId, int $targetSupplierId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE bank_email_attachment_ingests
                SET submission_id = ?, target_supplier_id = IF(supplier_id = ?, NULL, ?)
              WHERE submission_id = ?'
        )->execute([$newSubmissionId, $targetSupplierId, $targetSupplierId, $oldSubmissionId]);
    }

    private function truncate(mixed $value, int $length): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text !== '' ? mb_substr($text, 0, $length) : null;
    }
}
