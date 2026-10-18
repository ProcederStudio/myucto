<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\DocumentRequestRepository;
use MyInvoice\Repository\PurchaseInvoiceSubmissionRepository;
use MyInvoice\Service\ActivityLogger;

final class PurchaseInvoiceSubmissionDeletionService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PurchaseInvoiceSubmissionRepository $submissions,
        private readonly DocumentRepository $documents,
        private readonly DocumentRequestRepository $requests,
        private readonly ActivityLogger $logger,
    ) {}

    public static function canDeletePending(array $row, ?int $ownerId): bool
    {
        return ($ownerId === null || ($ownerId > 0 && (int) ($row['submitted_by'] ?? 0) === $ownerId))
            && ($row['status'] ?? '') === 'submitted'
            && empty($row['purchase_invoice_id'])
            && empty($row['processing_started_at'])
            && (int) ($row['document_link_count'] ?? 0) === 0
            && (int) ($row['resolved_request_count'] ?? 0) === 0
            && ($row['extraction_status'] ?? '') !== 'running';
    }

    public function delete(
        int $id,
        int $supplierId,
        int $userId,
        ?int $ownerId,
        bool $pendingOnly,
        ?string $ip,
        string $userAgent,
    ): array {
        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        else $pdo->exec('SAVEPOINT submission_delete');
        try {
            $row = $this->submissions->findForUpdate($id, $supplierId);
            if ($row === null || ($ownerId !== null && ($ownerId <= 0 || (int) ($row['submitted_by'] ?? 0) !== $ownerId))) {
                throw new PurchaseInvoiceSubmissionException('not_found', 'Podání nebylo nalezeno.', 404);
            }
            if ($pendingOnly && !self::canDeletePending($row, $ownerId)) {
                throw new PurchaseInvoiceSubmissionException('invalid_status', 'Zpracovávané ani již vyřízené podání smazat nelze.', 409);
            }
            $this->requests->reopenBySubmission($id, $supplierId);
            $deleted = $this->submissions->deleteFromQueue($id, $supplierId, $ownerId, $pendingOnly);
            if ($deleted === null) {
                throw new PurchaseInvoiceSubmissionException('invalid_status', 'Zpracované ani právě zpracovávané podání smazat nelze.', 409);
            }
            $this->documents->softDelete((int) $deleted['document_id'], $supplierId, $userId ?: null);
            $this->logger->log('purchase_invoice_submission.deleted', $userId ?: null, 'purchase_invoice_submission', $id, [
                'document_id' => (int) $deleted['document_id'],
                'filename' => (string) $deleted['original_name'],
                'status' => (string) $deleted['status'],
                'via' => (string) $deleted['submitted_via'],
            ], $ip, $userAgent, $supplierId);
            if ($ownTransaction) $pdo->commit();
            else $pdo->exec('RELEASE SAVEPOINT submission_delete');
            return ['ok' => true, 'document_id' => (int) $deleted['document_id']];
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            elseif (!$ownTransaction && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT submission_delete');
                $pdo->exec('RELEASE SAVEPOINT submission_delete');
            }
            throw $e;
        }
    }
}
