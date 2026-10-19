<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use PDO;

final readonly class TaxSubmissionTransportCloneScrubber
{
    public function __construct(private PDO $pdo) {}

    public function finalize(
        TaxSubmissionTransportCloneCapture $capture,
        FinancialCloneHashResolver $xmlHashes,
    ): string {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('Finalizace transportní provenance vyžaduje vlastnickou transakci.');
        }
        $capture->assertCurrentProvenance();
        $aggregateRoot = hash('sha256', 'transport-provenance-v1');
        foreach ($this->receiptBatches($capture) as $rows) {
            $targets = $this->targetXmlHashes($rows);
            $values = [];
            $parameters = [];
            $submissionIds = [];
            foreach ($rows as $row) {
                $submissionId = (int) $row['tax_submission_id'];
                $supplierId = (int) $row['supplier_id'];
                $sourceHash = (string) $row['source_xml_sha256'];
                $targetHash = $xmlHashes->resolve($sourceHash);
                if ($targetHash === null || preg_match('/^[a-f0-9]{64}$/D', $targetHash) !== 1
                    || !isset($targets[$submissionId]) || $targets[$submissionId]['supplier_id'] !== $supplierId
                    || !hash_equals($targets[$submissionId]['xml_sha256'], $targetHash)) {
                    throw new \LogicException('Přepsané XML podání neodpovídá transportní provenance.');
                }
                $values[] = '(?,?,?,?,?,?,?,?,?,?,?)';
                array_push(
                    $parameters,
                    $supplierId,
                    $submissionId,
                    $sourceHash,
                    $targetHash,
                    $row['transport_receipt_root'],
                    $row['transport_class'],
                    (int) $row['attempt_count'],
                    (int) $row['status_event_count'],
                    (int) $row['artifact_count'],
                    (int) $row['outbox_count'],
                    (int) $row['outbox_attempt_count'],
                );
                $aggregateRoot = hash(
                    'sha256',
                    $aggregateRoot . "\0" . $supplierId . "\0" . $submissionId . "\0"
                    . $sourceHash . "\0" . $targetHash . "\0" . $row['transport_receipt_root']
                    . "\0" . $row['transport_class'],
                );
                $submissionIds[] = $submissionId;
            }
            $this->pdo->prepare(
                'INSERT INTO anonymized_clone_submission_provenance '
                . '(supplier_id,tax_submission_id,source_xml_sha256,target_xml_sha256,transport_receipt_root,transport_class,'
                . 'attempt_count,status_event_count,artifact_count,outbox_count,outbox_attempt_count) VALUES '
                . implode(',', $values)
            )->execute($parameters);
            $this->detachPendingWork($submissionIds);
        }

        return $aggregateRoot;
    }

    /** @return iterable<list<array<string,mixed>>> */
    private function receiptBatches(TaxSubmissionTransportCloneCapture $capture): iterable
    {
        $batch = [];
        foreach ($capture->receiptPages(500) as $row) {
            $batch[] = $row;
            if (count($batch) === 500) {
                yield $batch;
                $batch = [];
            }
        }
        if ($batch !== []) {
            yield $batch;
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<int,array{supplier_id:int,xml_sha256:string}>
     */
    private function targetXmlHashes(array $rows): array
    {
        $ids = array_map(static fn (array $row): int => (int) $row['tax_submission_id'], $rows);
        $statement = $this->pdo->prepare(
            'SELECT id,supplier_id,xml_sha256 FROM tax_submissions WHERE id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE'
        );
        $statement->execute($ids);
        $targets = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $targets[(int) $row['id']] = [
                'supplier_id' => (int) $row['supplier_id'],
                'xml_sha256' => (string) $row['xml_sha256'],
            ];
        }
        return $targets;
    }

    /** @param list<int> $submissionIds */
    private function detachPendingWork(array $submissionIds): void
    {
        $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
        $this->pdo->prepare(
            "UPDATE tax_submission_attempts SET status='cancelled',next_poll_at=NULL "
            . "WHERE tax_submission_id IN ({$placeholders}) AND status IN "
            . "('prepared','handoff_created','awaiting_confirmation','testing','test_passed','test_failed','failed','expired','cancelled')"
        )->execute($submissionIds);
        $this->pdo->prepare(
            "UPDATE tax_submission_attempts SET next_poll_at=NULL WHERE tax_submission_id IN ({$placeholders})"
        )->execute($submissionIds);
        $this->pdo->prepare(
            "UPDATE submission_outbox SET dispatch_state='cancelled' WHERE artifact_kind='tax_submission' "
            . "AND artifact_id IN ({$placeholders}) AND dispatch_state IN ('ready','failed','cancelled')"
        )->execute($submissionIds);
        $this->pdo->prepare(
            "UPDATE submission_outbox SET dispatch_state='send_uncertain' WHERE artifact_kind='tax_submission' "
            . "AND artifact_id IN ({$placeholders}) AND dispatch_state='sending'"
        )->execute($submissionIds);
    }
}
