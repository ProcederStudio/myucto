<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final readonly class FinancialArchiveTargetWriter
{
    public function __construct(private Connection $db) {}

    /** @param iterable<array{tax_submission_id:int|string,supplier_id:int|string,source_xml_sha256:string}> $submissions */
    public function apply(
        CapturedFinancialArchive $archive,
        FinancialArchiveTargetArtifact $artifact,
        iterable $submissions,
        FinancialCloneHashMap $hashes,
    ): void {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Target archive writer vyžaduje vlastnickou transakci.');
        }
        if ($archive->finalSnapshot !== ($artifact->computed !== null)) {
            throw new \LogicException('Target artifact neodpovídá finalitě captured archivu.');
        }
        $mappedXmlHash = $hashes->resolve($archive->xmlSha256);
        if ($mappedXmlHash !== null && !hash_equals($mappedXmlHash, $artifact->xml->sha256)) {
            throw new \LogicException('Target XML neodpovídá již zapsané hash mapě.');
        }
        $this->lockArchive($pdo,$archive);
        if ($archive->finalSnapshot) {
            $this->lockReturn($pdo,$archive);
        }
        $this->writeArchive($pdo,$archive,$artifact);
        if ($archive->finalSnapshot) {
            $this->writeReturn($pdo,$archive,$artifact);
        }

        $actualSubmissions = 0;
        $lastSubmissionId = 0;
        foreach ($submissions as $submission) {
            $id = filter_var($submission['tax_submission_id'] ?? null,FILTER_VALIDATE_INT);
            $supplier = filter_var($submission['supplier_id'] ?? null,FILTER_VALIDATE_INT);
            $sourceHash = $submission['source_xml_sha256'] ?? null;
            if (!is_int($id) || $id <= $lastSubmissionId || !is_int($supplier) || $supplier !== $archive->supplierId
                || !is_string($sourceHash) || !hash_equals($archive->xmlSha256,$sourceHash)) {
                throw new \LogicException('Captured podání obsahuje cizího tenanta, hash nebo nekanonické pořadí.');
            }
            $this->writeSubmission($pdo, $archive, $artifact, $id, $mappedXmlHash !== null);
            $lastSubmissionId = $id;
            $actualSubmissions++;
        }
        if ($actualSubmissions !== $archive->submissionCount
            || (!hash_equals($archive->xmlSha256, $artifact->xml->sha256) && $this->sourceSubmissionCount($pdo, $archive) !== 0)) {
            throw new \LogicException('Target archive writer neobdržel úplnou zachycenou množinu podání.');
        }
        $this->verifyTarget($pdo, $archive, $artifact);
        $hashes->record(FinancialCloneHashKind::ARCHIVE_SNAPSHOT,$archive->archiveId,$archive->snapshotSha256,$artifact->snapshot->sha256);
        $hashes->record(FinancialCloneHashKind::ARCHIVE_SOURCE,$archive->archiveId,$archive->sourceSha256,$artifact->sourceSha256);
        $hashes->record(FinancialCloneHashKind::ARCHIVE_XML,$archive->archiveId,$archive->xmlSha256,$artifact->xml->sha256);
    }

    private function lockArchive(PDO $pdo,CapturedFinancialArchive $archive): void
    {
        $statement = $pdo->prepare('SELECT tax_return_id,supplier_id,source_sha256,xml_sha256,'
            . 'LOWER(SHA2(CAST(snapshot_json AS BINARY),256)) snapshot_physical,'
            . 'LOWER(SHA2(CAST(source_manifest AS BINARY),256)) source_physical,'
            . 'LOWER(SHA2(CAST(xml_content AS BINARY),256)) xml_physical '
            . 'FROM income_tax_return_snapshots WHERE supplier_id=? AND id=? FOR UPDATE');
        $statement->execute([$archive->supplierId,$archive->archiveId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int)$row['tax_return_id'] !== $archive->taxReturnId || (int)$row['supplier_id'] !== $archive->supplierId
            || !self::same($archive->snapshotSha256,$row['snapshot_physical']??null)
            || !self::same($archive->sourceSha256,$row['source_physical']??null)
            || !self::same($archive->sourceSha256,$row['source_sha256']??null)
            || !self::same($archive->xmlSha256,$row['xml_physical']??null)
            || !self::same($archive->xmlSha256,$row['xml_sha256']??null)) {
            throw new \LogicException('Target archiv se liší od capture důkazu.');
        }
    }

    private function lockReturn(PDO $pdo,CapturedFinancialArchive $archive): void
    {
        $statement = $pdo->prepare('SELECT final_snapshot_id,LOWER(SHA2(CAST(computed AS BINARY),256)) computed_physical '
            . 'FROM income_tax_returns WHERE supplier_id=? AND id=? FOR UPDATE');
        $statement->execute([$archive->supplierId,$archive->taxReturnId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int)($row['final_snapshot_id']??0) !== $archive->archiveId
            || !self::same($archive->computedSha256,$row['computed_physical']??null)) {
            throw new \LogicException('Target finální přiznání se liší od capture důkazu.');
        }
    }

    private function sourceSubmissionCount(PDO $pdo, CapturedFinancialArchive $archive): int
    {
        $statement = $pdo->prepare("SELECT COUNT(*) FROM tax_submissions FORCE INDEX (idx_tax_submission_archive_xml) "
            . "WHERE supplier_id=? AND form_code='dppdp9' AND period_year=? AND xml_sha256=? "
            . 'AND LOWER(SHA2(CAST(xml_content AS BINARY),256))=?');
        $statement->execute([$archive->supplierId, $archive->periodYear, $archive->xmlSha256, $archive->xmlSha256]);

        return (int) $statement->fetchColumn();
    }

    private function writeArchive(PDO $pdo,CapturedFinancialArchive $archive,FinancialArchiveTargetArtifact $artifact): void
    {
        $statement = $pdo->prepare('UPDATE income_tax_return_snapshots SET snapshot_json=?,source_manifest=?,source_sha256=?,xml_content=?,xml_sha256=? '
            . 'WHERE supplier_id=? AND id=? AND source_sha256=? AND xml_sha256=? '
            . 'AND LOWER(SHA2(CAST(snapshot_json AS BINARY),256))=? AND LOWER(SHA2(CAST(source_manifest AS BINARY),256))=? '
            . 'AND LOWER(SHA2(CAST(xml_content AS BINARY),256))=?');
        $snapshot = $artifact->snapshot->stream();
        $xml = $artifact->xml->stream();
        $statement->bindParam(1,$snapshot,PDO::PARAM_LOB);
        $statement->bindValue(2,$artifact->sourceManifest,PDO::PARAM_STR);
        $statement->bindValue(3,$artifact->sourceSha256,PDO::PARAM_STR);
        $statement->bindParam(4,$xml,PDO::PARAM_LOB);
        $statement->bindValue(5,$artifact->xml->sha256,PDO::PARAM_STR);
        $statement->bindValue(6,$archive->supplierId,PDO::PARAM_INT);
        $statement->bindValue(7,$archive->archiveId,PDO::PARAM_INT);
        $statement->bindValue(8,$archive->sourceSha256,PDO::PARAM_STR);
        $statement->bindValue(9,$archive->xmlSha256,PDO::PARAM_STR);
        $statement->bindValue(10,$archive->snapshotSha256,PDO::PARAM_STR);
        $statement->bindValue(11,$archive->sourceSha256,PDO::PARAM_STR);
        $statement->bindValue(12,$archive->xmlSha256,PDO::PARAM_STR);
        $statement->execute();
    }

    private function writeReturn(PDO $pdo, CapturedFinancialArchive $archive, FinancialArchiveTargetArtifact $artifact): void
    {
        $computed = $artifact->computed ?? throw new \LogicException('Finální target artifact postrádá computed JSON.');
        $computedSha256 = $archive->computedSha256 ?? throw new \LogicException('Finální capture postrádá computed SHA-256.');
        $statement = $pdo->prepare('UPDATE income_tax_returns SET computed=? WHERE supplier_id=? AND id=? AND final_snapshot_id=? '
            . 'AND LOWER(SHA2(CAST(computed AS BINARY),256))=?');
        $statement->execute([$computed, $archive->supplierId, $archive->taxReturnId, $archive->archiveId, $computedSha256]);
    }

    private function writeSubmission(PDO $pdo, CapturedFinancialArchive $archive, FinancialArchiveTargetArtifact $artifact, int $id, bool $alreadyMapped): void
    {
        $lock = $pdo->prepare("SELECT supplier_id,form_code,period_year,xml_sha256,LOWER(SHA2(CAST(xml_content AS BINARY),256)) xml_physical "
            . 'FROM tax_submissions WHERE id=? FOR UPDATE');
        $lock->execute([$id]);
        $row = $lock->fetch(PDO::FETCH_ASSOC);
        $expectedHash = $alreadyMapped ? $artifact->xml->sha256 : $archive->xmlSha256;
        if (!is_array($row) || (int) $row['supplier_id'] !== $archive->supplierId || $row['form_code'] !== 'dppdp9'
            || (int) $row['period_year'] !== $archive->periodYear || !self::same($expectedHash, $row['xml_sha256'] ?? null)
            || !self::same($expectedHash, $row['xml_physical'] ?? null)) {
            throw new \LogicException('Captured podání obsahuje cizího tenanta nebo stale XML.');
        }
        if ($alreadyMapped) {
            return;
        }
        $update = $pdo->prepare('UPDATE tax_submissions SET xml_content=?,xml_size_bytes=?,xml_sha256=? '
            . 'WHERE id=? AND supplier_id=? AND xml_sha256=? AND LOWER(SHA2(CAST(xml_content AS BINARY),256))=?');
        $xml = $artifact->xml->stream();
        $update->bindParam(1,$xml,PDO::PARAM_LOB);
        $update->bindValue(2,$artifact->xml->bytes,PDO::PARAM_INT);
        $update->bindValue(3,$artifact->xml->sha256,PDO::PARAM_STR);
        $update->bindValue(4,$id,PDO::PARAM_INT);
        $update->bindValue(5,$archive->supplierId,PDO::PARAM_INT);
        $update->bindValue(6,$archive->xmlSha256,PDO::PARAM_STR);
        $update->bindValue(7,$archive->xmlSha256,PDO::PARAM_STR);
        $update->execute();
    }

    private function verifyTarget(PDO $pdo, CapturedFinancialArchive $archive, FinancialArchiveTargetArtifact $artifact): void
    {
        $snapshot = $pdo->prepare('SELECT LOWER(SHA2(CAST(snapshot_json AS BINARY),256)),LOWER(SHA2(CAST(source_manifest AS BINARY),256)),'
            . 'source_sha256,LOWER(SHA2(CAST(xml_content AS BINARY),256)),xml_sha256 FROM income_tax_return_snapshots WHERE supplier_id=? AND id=?');
        $snapshot->execute([$archive->supplierId,$archive->archiveId]);
        $row = $snapshot->fetch(PDO::FETCH_NUM);
        if (!is_array($row) || !self::same($artifact->snapshot->sha256,$row[0]??null)
            || !self::same($artifact->sourceSha256,$row[1]??null) || !self::same($artifact->sourceSha256,$row[2]??null)
            || !self::same($artifact->xml->sha256,$row[3]??null) || !self::same($artifact->xml->sha256,$row[4]??null)) {
            throw new \LogicException('Target archiv po zápisu neodpovídá artifactu.');
        }
        if ($archive->finalSnapshot) {
            $computedSha256 = $artifact->computedSha256 ?? throw new \LogicException('Finální target artifact postrádá computed SHA-256.');
            $return = $pdo->prepare('SELECT LOWER(SHA2(CAST(computed AS BINARY),256)) FROM income_tax_returns WHERE supplier_id=? AND id=? AND final_snapshot_id=?');
            $return->execute([$archive->supplierId, $archive->taxReturnId, $archive->archiveId]);
            if (!self::same($computedSha256, $return->fetchColumn())) {
                throw new \LogicException('Target finální přiznání po zápisu neodpovídá artifactu.');
            }
        }
    }

    private static function same(string $expected,mixed $actual): bool
    {
        return is_string($actual) && hash_equals($expected,strtolower($actual));
    }
}
