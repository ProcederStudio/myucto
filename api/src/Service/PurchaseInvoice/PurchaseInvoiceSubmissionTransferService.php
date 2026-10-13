<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankEmailAttachmentIngestRepository;
use MyInvoice\Repository\PurchaseInvoiceSubmissionRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Document\DocumentStorage;

/**
 * Přesun dokladu z příchozích dokladů jedné firmy do druhé.
 *
 * Originál v cílové firmě vzniká stejnou cestou jako nové podání
 * ({@see PurchaseInvoiceSubmissionUploadService::submitBytes()}), takže dostane
 * vlastní soubor v úložišti cílové firmy, složku i deduplikaci. Ve zdrojové firmě
 * pak podání zmizí stejně jako při vyřazení z fronty: originál jde do koše Dokumentů.
 *
 * Přesunout jde jen doklad, který na zdrojovou firmu nic neváže. Podání z portálu
 * nebo z výzvy patří klientovi zdrojové firmy, karetní účtenka jejímu pohybu
 * a zpracované podání má fakturu. Dimenze se nepřenášejí, každá firma má své.
 * Kontrolu oprávnění v obou firmách dělá volající.
 */
final class PurchaseInvoiceSubmissionTransferService
{
    private const MOVABLE_STATUSES = ['submitted', 'needs_information', 'rejected'];

    public function __construct(
        private readonly Connection $db,
        private readonly PurchaseInvoiceSubmissionRepository $submissions,
        private readonly PurchaseInvoiceSubmissionUploadService $upload,
        private readonly PurchaseInvoiceSubmissionDeletionService $deletion,
        private readonly BankEmailAttachmentIngestRepository $attachmentLog,
        private readonly DocumentStorage $storage,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * Proč podání přesunout nejde. NULL = jde.
     *
     * @param array<string,mixed> $row
     */
    public static function blockReason(array $row): ?string
    {
        if (!in_array((string) ($row['status'] ?? ''), self::MOVABLE_STATUSES, true)
            || !empty($row['purchase_invoice_id'])
            || !empty($row['processing_started_at'])
            || ($row['extraction_status'] ?? '') === 'running') {
            return 'Zpracovaný ani právě zpracovávaný doklad přesunout nejde.';
        }
        if (!in_array((string) ($row['submitted_via'] ?? ''), ['staff', 'email'], true)
            || (int) ($row['request_count'] ?? 0) > 0) {
            return 'Doklad od klienta z portálu patří jeho firmě a přesunout ho nejde.';
        }
        if (!empty($row['bank_transaction_id'])) {
            return 'Doklad je navázaný na bankovní pohyb firmy a přesunout ho nejde.';
        }
        if (!empty($row['supersedes_submission_id']) || !empty($row['replacement_submission_id'])) {
            return 'Doklad je součástí náhrady jiného podání a přesunout ho nejde.';
        }
        if ((int) ($row['document_link_count'] ?? 0) > 0) {
            return 'Originál dokladu je připojený k jinému záznamu firmy a přesunout ho nejde.';
        }
        return null;
    }

    /**
     * @return array{submission:array<string,mixed>,duplicate:bool,source_document_id:int}
     */
    public function move(int $id, int $sourceSupplierId, int $targetSupplierId, int $userId, ?string $ip, string $userAgent): array
    {
        if ($targetSupplierId <= 0 || $targetSupplierId === $sourceSupplierId) {
            throw new PurchaseInvoiceSubmissionException('invalid_target', 'Vyberte jinou firmu, než ve které doklad je.', 400);
        }
        $row = $this->submissions->find($id, $sourceSupplierId);
        if ($row === null) {
            throw new PurchaseInvoiceSubmissionException('not_found', 'Podání nebylo nalezeno.', 404);
        }
        $blocked = self::blockReason($row);
        if ($blocked !== null) {
            throw new PurchaseInvoiceSubmissionException('not_movable', $blocked, 409);
        }

        $path = $this->storage->pathFor($sourceSupplierId, (string) $row['document_sha256'], (string) $row['document_filename']);
        $bytes = is_file($path) ? @file_get_contents($path) : false;
        if (!is_string($bytes) || $bytes === '') {
            throw new PurchaseInvoiceSubmissionException('source_missing', 'Originální soubor není v úložišti dostupný.', 500);
        }

        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        else $pdo->exec('SAVEPOINT submission_move');
        try {
            $created = $this->upload->submitBytes(
                $bytes,
                (string) ($row['original_name'] ?? 'doklad.pdf'),
                $targetSupplierId,
                $userId > 0 ? $userId : null,
                (string) $row['submitted_via'],
                isset($row['note']) ? (string) $row['note'] : null,
                isset($row['document_kind_hint']) ? (string) $row['document_kind_hint'] : null,
            );
            $newId = (int) $created['submission']['id'];
            if (empty($created['duplicate']) && (!empty($row['detected_buyer_ic']) || !empty($row['detected_buyer_dic']))) {
                $this->submissions->recordDetectedBuyer(
                    $newId,
                    $targetSupplierId,
                    isset($row['detected_buyer_ic']) ? (string) $row['detected_buyer_ic'] : null,
                    isset($row['detected_buyer_dic']) ? (string) $row['detected_buyer_dic'] : null,
                );
            }
            $this->attachmentLog->followMovedSubmission($id, $newId, $targetSupplierId);
            $deleted = $this->deletion->delete($id, $sourceSupplierId, $userId, null, false, $ip, $userAgent);
            $context = [
                'from_supplier_id' => $sourceSupplierId,
                'to_supplier_id' => $targetSupplierId,
                'from_submission_id' => $id,
                'to_submission_id' => $newId,
                'filename' => (string) ($row['original_name'] ?? ''),
                'duplicate' => !empty($created['duplicate']),
            ];
            $this->logger->log('purchase_invoice_submission.moved_out', $userId ?: null, 'purchase_invoice_submission', $id, $context, $ip, $userAgent, $sourceSupplierId);
            $this->logger->log('purchase_invoice_submission.moved_in', $userId ?: null, 'purchase_invoice_submission', $newId, $context, $ip, $userAgent, $targetSupplierId);
            if ($ownTransaction) $pdo->commit();
            else $pdo->exec('RELEASE SAVEPOINT submission_move');
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif (!$ownTransaction && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT submission_move');
                $pdo->exec('RELEASE SAVEPOINT submission_move');
            }
            throw $e;
        }

        return [
            'submission' => $created['submission'],
            'duplicate' => !empty($created['duplicate']),
            'source_document_id' => (int) $deleted['document_id'],
        ];
    }
}
