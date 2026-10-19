<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

final readonly class AnonymizedCloneSubmissionProvenance
{
    private const TABLE = 'anonymized_clone_submission_provenance';

    public function __construct(private Connection $db) {}

    public function assertDispatchable(int $supplierId, int $taxSubmissionId): void
    {
        if ($supplierId <= 0 || $taxSubmissionId <= 0) {
            throw new \InvalidArgumentException('Identita daňového podání musí být kladná.');
        }
        if (!$this->db->hasTable(self::TABLE)) {
            return;
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT transport_class FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND tax_submission_id = ? LIMIT 1',
        );
        $statement->execute([$supplierId, $taxSubmissionId]);
        if ($statement->fetchColumn() === false) {
            return;
        }

        throw new SubmissionChannelException(
            'anonymized_clone_archived_submission',
            'Archivované podání v anonymizované kopii nese pouze mapu zdrojového transportu a nelze je znovu odeslat.',
            409,
        );
    }
}
