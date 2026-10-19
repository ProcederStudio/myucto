<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Snapshot;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\NonprofitTaxSnapshotManifestRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Tax\Nonprofit\SourceFacts\MembershipJ6SourceFacts;

final class NonprofitTaxSnapshotMaterializer
{
    public function __construct(
        private readonly Connection $db,
        private readonly NonprofitTaxSnapshotBatchReader $reader,
        private readonly NonprofitTaxSnapshotHasher $hasher,
        private readonly NonprofitTaxSnapshotManifestRepository $manifests,
    ) {}

    public function materializeCurrent(int $supplierId, int $periodId): SnapshotManifestReference
    {
        if ($supplierId <= 0 || $periodId <= 0) throw new \InvalidArgumentException('Snapshot materialization identity is invalid.');
        $guard = new DimensionPeriodGuard($this->db);
        return $guard->transaction(function () use ($guard, $supplierId, $periodId): SnapshotManifestReference {
            $guard->lockPeriods($supplierId);
            $header = $this->reader->header($supplierId, $periodId, true);
            $spool = SnapshotChunkSpool::create();
            try {
                $root = $this->hasher->startRoot();
                $cursor = null;
                $ordinal = 0;
                $rowCount = 0;
                $findingCount = 0;
                $membershipFactCount = 0;
                do {
                    $page = $this->reader->page($supplierId, $periodId, $cursor, 500, true);
                    if ($page->rows !== [] || $page->findings !== []) {
                        $chunk = $this->hasher->chunk($ordinal, $page);
                        $chunkId = $this->manifests->putChunk($supplierId, $chunk);
                        $this->hasher->updateRoot($root, $chunk['descriptor']);
                        $spool->append($chunkId, $chunk['descriptor']);
                        $rowCount += $chunk['descriptor']['row_count'];
                        $findingCount += $chunk['descriptor']['finding_count'];
                        foreach ($page->rows as $row) if (isset($row['module_facts'][MembershipJ6SourceFacts::SCHEMA])) ++$membershipFactCount;
                        ++$ordinal;
                    }
                    $next = $page->nextCursor;
                    if ($next !== null && $cursor !== null && $next->toArray() === $cursor->toArray()) throw new \UnexpectedValueException('Snapshot page cursor did not advance.');
                    $cursor = $next;
                } while ($cursor !== null);
                $rootHash = $this->hasher->finishRoot($root, $ordinal);
                $headerHash = $this->hasher->header($header);
                $inputHash = $this->hasher->input($header, $headerHash, $rootHash, $rowCount, $findingCount, $ordinal);
                $existing = $this->manifests->findByInputHash($supplierId, $inputHash, true);
                if ($existing !== null) return $this->manifests->verify($supplierId, $existing->id, true);
                $manifest = ['supplier_id' => $supplierId, 'period_id' => $periodId, 'ruleset' => $header->data['ruleset'],
                    'header' => $header->data, 'header_hash' => $headerHash, 'root_hash' => $rootHash, 'input_hash' => $inputHash,
                    'row_count' => $rowCount, 'finding_count' => $findingCount, 'chunk_count' => $ordinal,
                    'membership_fact_count' => $membershipFactCount];
                $reference = $this->manifests->appendManifest($manifest, $spool->records());
                $verified = $this->manifests->verify($supplierId, $reference->id, true);
                if ($verified != $reference) throw new \UnexpectedValueException('Stored snapshot manifest reference changed during verification.');
                return $reference;
            } finally {
                $spool->closeAndDelete();
            }
        });
    }
}
