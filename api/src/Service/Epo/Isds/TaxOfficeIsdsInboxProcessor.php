<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo\Isds;

use MyInvoice\Repository\Submission\SubmissionInboxRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
use MyInvoice\Service\Submission\Channel\InboxMessageHeader;
use MyInvoice\Service\Submission\InboxMessageClassifier;
use MyInvoice\Service\Submission\SubmissionInboxMessageProcessor;

/**
 * Zpráva finančního úřadu k daňovému podání odeslanému datovou schránkou.
 *
 * ── Co se s ní děje ─────────────────────────────────────────────────────────
 * Originál (ZFO) se přiloží k podání jako `isds_response_zfo`, aby ho účetní
 * našla u přiznání, ne jen v datové schránce. Stav podání se NEMĚNÍ: zpráva
 * od FÚ není strojově čitelný protokol o zpracování (výzva k odstranění vad,
 * platební výměr, sdělení…) a o jejím významu rozhoduje člověk, který ji přečte.
 *
 * ── Vazba na podání ─────────────────────────────────────────────────────────
 * Jen přes to, co jsme do odchozí zprávy dali sami a co ISDS přidělilo:
 * naše spisová značka (`dmSenderIdent`, páruje už klasifikátor), nebo ID
 * odeslané datové zprávy v předmětu odpovědi. Jiné shody (DIČ, období, čas)
 * se nepoužívají — špatná vazba by u přiznání tvrdila něco, o čem nic neví.
 * Nespárovanou zprávu přiřadí účetní ručně u podání.
 */
final readonly class TaxOfficeIsdsInboxProcessor implements SubmissionInboxMessageProcessor
{
    public function __construct(
        private SubmissionInboxRepository $inbox,
        private SubmissionOutboxRepository $outbox,
        private TaxSubmissionDispatchProjection $projection,
    ) {}

    /**
     * @param array{classification:string,matched_outbox_id:?int} $verdict
     * @return array{status:string,code:?string,submission_id:?int,receipt_id:?int,remote_status:?string}
     */
    public function process(
        int $supplierId,
        string $environment,
        int $inboxMessageId,
        InboxMessageHeader $header,
        array $verdict,
        string $zfoBytes,
        ?int $actorUserId,
    ): array {
        if ($verdict['classification'] !== InboxMessageClassifier::TAX_OFFICE_RESPONSE) {
            return self::result('not_applicable');
        }

        $outboxId = $verdict['matched_outbox_id'] ?? $this->matchBySentMessageId($supplierId, $environment, $header);
        if ($outboxId === null) {
            return self::result('manual_review', 'tax_isds_response_unmatched');
        }
        $outbox = $this->outbox->find($supplierId, $outboxId);
        if ($outbox === null
            || (string) $outbox['environment'] !== $environment
            || (string) $outbox['channel'] !== 'isds'
            || (string) $outbox['artifact_kind'] !== 'tax_submission'
        ) {
            return self::result('not_applicable');
        }
        $submissionId = (int) $outbox['artifact_id'];

        $message = $this->inbox->findById($supplierId, $inboxMessageId);
        if ($message === null || (string) ($message['external_message_id'] ?? '') !== $header->externalMessageId) {
            return self::result('manual_review', 'tax_isds_inbox_scope_mismatch', $submissionId);
        }
        if ($verdict['matched_outbox_id'] === null) {
            $this->inbox->linkToOutbox($supplierId, $inboxMessageId, $outboxId);
        }

        $documentId = isset($message['document_id']) && $message['document_id'] !== null
            ? (int) $message['document_id']
            : null;
        if ($documentId !== null) {
            $this->projection->linkDocument(
                $supplierId,
                $submissionId,
                $documentId,
                'isds_response_zfo',
                [
                    'inbox_message_id' => $inboxMessageId,
                    'external_message_id' => $header->externalMessageId,
                    'sender_box_id' => $header->senderBoxId,
                    'sender_name' => $header->senderName,
                    'subject' => $header->subject,
                    'delivered_at' => $header->deliveredAt?->format('Y-m-d H:i:s'),
                ],
                $actorUserId,
            );
        }

        return self::result('manual_review', 'tax_isds_response_linked', $submissionId);
    }

    /**
     * ID naší odeslané datové zprávy v předmětu odpovědi. ID přidělilo ISDS,
     * takže shoda je stejně přesná jako vlastní spisová značka — ale jen když
     * je jednoznačná.
     */
    private function matchBySentMessageId(int $supplierId, string $environment, InboxMessageHeader $header): ?int
    {
        if (preg_match_all('/\b([0-9]{6,12})\b/', (string) $header->subject, $matches) === 0) {
            return null;
        }
        $found = [];
        foreach (array_unique($matches[1]) as $candidate) {
            $row = $this->outbox->findByExternalMessageId($supplierId, 'isds', $candidate);
            if ($row !== null
                && (string) $row['environment'] === $environment
                && (string) $row['artifact_kind'] === 'tax_submission'
            ) {
                $found[(int) $row['id']] = true;
            }
        }
        return count($found) === 1 ? (int) array_key_first($found) : null;
    }

    /** @return array{status:string,code:?string,submission_id:?int,receipt_id:?int,remote_status:?string} */
    private static function result(string $status, ?string $code = null, ?int $submissionId = null): array
    {
        return [
            'status' => $status,
            'code' => $code,
            'submission_id' => $submissionId,
            'receipt_id' => null,
            'remote_status' => null,
        ];
    }
}
