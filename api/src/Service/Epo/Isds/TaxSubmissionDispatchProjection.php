<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo\Isds;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxSubmissionEpoRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Report\TaxSubmissionArchiver;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Promítne doložené odeslání a doručení obecné fronty do daňového podání.
 *
 * ── Kdy je podání podané ────────────────────────────────────────────────────
 * Podání datovou zprávou je učiněno dodáním do datové schránky správce daně.
 * ISDS zprávu dodá v okamžiku odeslání, takže čas odeslání z fronty je zároveň
 * dnem podání a ID datové zprávy je jeho identifikátor. Stav se proto zapisuje
 * stejnou cestou jako ruční „Označit jako podané"
 * ({@see TaxSubmissionArchiver::markSubmitted()}): zúčtování DPH, zámek období
 * i základna opravného a dodatečného tvrzení fungují pro obě cesty stejně.
 *
 * ── Co se NEdozvíme ─────────────────────────────────────────────────────────
 * Datová schránka nevrací protokol o zpracování (na rozdíl od P7S z EPO).
 * Doručenka dokládá den podání, ne jeho bezvadnost; vady oznamuje FÚ výzvou.
 * Stav `accepted` tahle cesta proto nikdy nenastavuje.
 *
 * Chyby se jen logují: zpráva už odešla a zápis do fronty je závazný důkaz.
 * Kdyby se kvůli chybě projekce odeslání vrátilo, vzniklo by podání dvakrát.
 */
final readonly class TaxSubmissionDispatchProjection
{
    public function __construct(
        private Connection $db,
        private TaxSubmissionRepository $submissions,
        private TaxSubmissionArchiver $archiver,
        private TaxSubmissionEpoRepository $epo,
        private ActivityLogger $activity,
        private LoggerInterface $logger,
    ) {}

    /** @param array<string,mixed> $outboxRow */
    public function project(int $supplierId, array $outboxRow, string $externalMessageId): void
    {
        if (!self::applies($outboxRow)) {
            return;
        }
        $submissionId = (int) $outboxRow['artifact_id'];

        try {
            $submission = $this->submissions->find($submissionId, $supplierId);
            if ($submission === null
                || in_array((string) $submission['status'], ['submitted', 'accepted'], true)
            ) {
                return;
            }
            $sentAt = self::timestamp($outboxRow['sent_at'] ?? null) ?? date('Y-m-d H:i:s');
            $userId = self::userId($outboxRow);
            $reference = 'ISDS ' . $externalMessageId;

            $this->archiver->markSubmitted($submissionId, $supplierId, $sentAt, $reference, $userId);
            $this->activity->log(
                'report.submission_sent_isds',
                $userId,
                'tax_submission',
                $submissionId,
                [
                    'submission_id' => $submissionId,
                    'outbox_id' => (int) $outboxRow['id'],
                    'form_code' => $submission['form_code'] ?? null,
                    'period_year' => $submission['period_year'] ?? null,
                    'period_month' => $submission['period_month'] ?? null,
                    'period_quarter' => $submission['period_quarter'] ?? null,
                    'recipient_box_id' => $outboxRow['recipient_box_id'] ?? null,
                    'external_message_id' => $externalMessageId,
                    'submitted_at' => $sentAt,
                ],
                null,
                null,
                $supplierId,
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Sent tax submission could not be projected to its snapshot', [
                'supplier_id' => $supplierId,
                'tax_submission_id' => $submissionId,
                'outbox_id' => $outboxRow['id'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Doručenka (ZFO) se přiloží k podání jako důkaz dne podání. Když zpráva
     * odešla ručně z vlastní datové schránky a aplikace se o tom dozvídá až
     * z doručenky, podání se tím zároveň označí jako podané.
     *
     * @param array<string,mixed> $outboxRow
     */
    public function projectDelivery(int $supplierId, array $outboxRow): void
    {
        if (!self::applies($outboxRow)) {
            return;
        }
        $submissionId = (int) $outboxRow['artifact_id'];

        try {
            $messageId = trim((string) ($outboxRow['external_message_id'] ?? ''));
            if ($messageId !== '') {
                $this->project($supplierId, $outboxRow, $messageId);
            }

            $documentId = isset($outboxRow['receipt_document_id']) && $outboxRow['receipt_document_id'] !== null
                ? (int) $outboxRow['receipt_document_id']
                : null;
            if ($documentId === null) {
                return;
            }
            $this->linkDocument(
                $supplierId,
                $submissionId,
                $documentId,
                'isds_delivery_zfo',
                [
                    'outbox_id' => (int) $outboxRow['id'],
                    'external_message_id' => $messageId !== '' ? $messageId : null,
                    'delivered_at' => $outboxRow['delivered_at'] ?? null,
                    'matched_by' => $outboxRow['receipt_matched_by'] ?? null,
                ],
                self::userId($outboxRow),
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Delivered tax submission could not archive its receipt', [
                'supplier_id' => $supplierId,
                'tax_submission_id' => $submissionId,
                'outbox_id' => $outboxRow['id'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Přiloží už uložený dokument (ZFO z datové schránky) k podání. Idempotentní:
     * dokument může být artefaktem jen jednou.
     *
     * @param array<string,mixed> $verification
     */
    public function linkDocument(
        int $supplierId,
        int $submissionId,
        int $documentId,
        string $kind,
        array $verification,
        ?int $userId,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'SELECT d.sha256,
                    EXISTS(SELECT 1 FROM tax_submission_artifacts a WHERE a.document_id = d.id) AS linked
               FROM documents d
              WHERE d.id = ? AND d.supplier_id = ?'
        );
        $stmt->execute([$documentId, $supplierId]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($document === false || (bool) $document['linked']) {
            return;
        }
        $this->epo->addArtifact(
            $supplierId,
            $submissionId,
            null,
            $documentId,
            $kind,
            (string) $document['sha256'],
            'not_applicable',
            array_filter($verification, static fn (mixed $value): bool => $value !== null),
            $userId,
        );
    }

    /** @param array<string,mixed> $row */
    private static function applies(array $row): bool
    {
        return (string) ($row['artifact_kind'] ?? '') === 'tax_submission'
            && (string) ($row['channel'] ?? '') === 'isds'
            && (string) ($row['environment'] ?? '') === 'production'
            && (int) ($row['artifact_id'] ?? 0) > 0;
    }

    /** @param array<string,mixed> $row */
    private static function userId(array $row): ?int
    {
        foreach (['confirmed_by', 'created_by'] as $key) {
            if (isset($row[$key]) && $row[$key] !== null && (int) $row[$key] > 0) {
                return (int) $row[$key];
            }
        }
        return null;
    }

    private static function timestamp(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }
}
