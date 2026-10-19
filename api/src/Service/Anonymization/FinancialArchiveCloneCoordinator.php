<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class FinancialArchiveCloneCoordinator
{
    private const PAGE = 500;

    public function capture(Connection $connection): FinancialArchiveCloneCapture
    {
        $pdo = $connection->pdo();
        if ($pdo->inTransaction()
            || (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchColumn() !== 0) {
            throw new \LogicException('Financial archive capture must run before trigger installation outside a transaction.');
        }
        $capture = FinancialArchiveCloneCapture::create($pdo);
        try {
            $lastSupplier = 0;
            $lastId = 0;
            do {
                $page = $this->snapshotPage($pdo, $lastSupplier, $lastId);
                if ($page === []) {
                    break;
                }
                $lastSupplier = (int) $page[array_key_last($page)]['supplier_id'];
                $lastId = (int) $page[array_key_last($page)]['id'];
                $archives = [];
                foreach ($page as $row) {
                    if ($row['accounting_decree'] !== '504') {
                        continue;
                    }
                    if (!is_string($row['source_sha256']) || !is_string($row['actual_source_sha256'])
                        || !hash_equals($row['source_sha256'], $row['actual_source_sha256'])
                        || !is_string($row['xml_sha256']) || !is_string($row['actual_xml_sha256'])
                        || !hash_equals($row['xml_sha256'], $row['actual_xml_sha256'])) {
                        throw new \LogicException('DPPO 504 archive hash is invalid.');
                    }
                    $version = $row['record_version'] === null ? null : (int) $row['record_version'];
                    if ($version !== null && !in_array($version, [2, 3], true)) {
                        throw new \LogicException('DPPO 504 archive version is unsupported.');
                    }
                    $year = filter_var($row['period_year'], FILTER_VALIDATE_INT);
                    if (!is_int($year) || $year < 1 || $year > 65535) {
                        throw new \LogicException('DPPO 504 archive period is invalid.');
                    }
                    $archives[] = [
                        'archive_id' => (int) $row['id'],
                        'supplier_id' => (int) $row['supplier_id'],
                        'tax_return_id' => (int) $row['tax_return_id'],
                        'period_year' => $year,
                        'record_version' => $version,
                        'snapshot_sha256' => $row['snapshot_sha256'],
                        'source_sha256' => $row['source_sha256'],
                        'xml_sha256' => $row['xml_sha256'],
                        'snapshot_bytes' => (int) $row['snapshot_bytes'],
                        'manifest_bytes' => (int) $row['manifest_bytes'],
                        'xml_bytes' => (int) $row['xml_bytes'],
                    ];
                }
                if ($archives !== []) {
                    $archives = $this->validateReturnsAndProtect($pdo, $capture, $archives);
                    $capture->addArchives($archives);
                }
            } while (count($page) === self::PAGE);
            $capture->linkSubmissions();
            $capture->captureTransport();
            $capture->captureSavings();

            return $capture;
        } catch (\Throwable $e) {
            $capture->drop();
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    private function snapshotPage(PDO $pdo, int $lastSupplier, int $lastId): array
    {
        $select = 'SELECT id,tax_return_id,supplier_id,source_sha256,xml_sha256, '
            . 'OCTET_LENGTH(snapshot_json) snapshot_bytes,OCTET_LENGTH(source_manifest) manifest_bytes,OCTET_LENGTH(xml_content) xml_bytes, '
            . 'SHA2(CAST(snapshot_json AS BINARY),256) snapshot_sha256,SHA2(CAST(source_manifest AS BINARY),256) actual_source_sha256,SHA2(CAST(xml_content AS BINARY),256) actual_xml_sha256, '
            . "JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.podklady.accounting_decree')) accounting_decree, "
            . "JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.podklady.nonprofit_calculation.version')) record_version, "
            . "JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.podklady.nonprofit_calculation.data.period.fiscal_year')) period_year "
            . 'FROM income_tax_return_snapshots FORCE INDEX (uq_dppo_snapshot_tenant) ';
        $current = $pdo->prepare($select
            . 'WHERE supplier_id=? AND id>? ORDER BY id LIMIT ' . self::PAGE);
        $current->execute([$lastSupplier, $lastId]);
        $rows = $current->fetchAll(PDO::FETCH_ASSOC);
        $remaining = self::PAGE - count($rows);
        if ($remaining === 0) {
            return $rows;
        }
        $next = $pdo->prepare($select
            . 'WHERE supplier_id>? ORDER BY supplier_id,id LIMIT ' . $remaining);
        $next->execute([$lastSupplier]);

        return [...$rows, ...$next->fetchAll(PDO::FETCH_ASSOC)];
    }

    /**
     * @param list<array{archive_id:int,supplier_id:int,tax_return_id:int,period_year:int,record_version:?int,snapshot_sha256:string,source_sha256:string,xml_sha256:string,snapshot_bytes:int,manifest_bytes:int,xml_bytes:int}> $archives
     * @return list<array{archive_id:int,supplier_id:int,tax_return_id:int,period_year:int,record_version:?int,final_snapshot:bool,computed_sha256:?string,snapshot_sha256:string,source_sha256:string,xml_sha256:string,snapshot_bytes:int,manifest_bytes:int,xml_bytes:int}>
     */
    private function validateReturnsAndProtect(PDO $pdo, FinancialArchiveCloneCapture $capture, array $archives): array
    {
        $ids = array_values(array_unique(array_column($archives, 'tax_return_id')));
        $statement = $pdo->prepare('SELECT id,supplier_id,year,taxpayer_type,status,final_snapshot_id,LOWER(SHA2(CAST(computed AS BINARY),256)) computed_sha256 FROM income_tax_returns WHERE id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')');
        $statement->execute($ids);
        $returns = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $returns[(int) $row['id']] = $row;
        }
        $snapshotIds = [];
        $returnIds = [];
        foreach ($archives as $index => $archive) {
            $return = $returns[$archive['tax_return_id']] ?? null;
            if (!is_array($return)
                || (int) $return['supplier_id'] !== $archive['supplier_id']
                || (int) $return['year'] !== $archive['period_year']
                || $return['taxpayer_type'] !== 'po') {
                throw new \LogicException('DPPO 504 archive has a foreign or inconsistent tax return.');
            }
            $snapshotIds[] = $archive['archive_id'];
            $finalSnapshot = (int) ($return['final_snapshot_id'] ?? 0) === $archive['archive_id'];
            $computedSha256 = $finalSnapshot && is_string($return['computed_sha256'] ?? null)
                ? strtolower($return['computed_sha256'])
                : null;
            if ($finalSnapshot && preg_match('/^[a-f0-9]{64}$/D', (string) $computedSha256) !== 1) {
                throw new \LogicException('Finální DPPO 504 přiznání nemá fyzický computed hash.');
            }
            $archives[$index]['final_snapshot'] = $finalSnapshot;
            $archives[$index]['computed_sha256'] = $computedSha256;
            if ($finalSnapshot) {
                $returnIds[] = $archive['tax_return_id'];
            }
        }
        $capture->protectedRows()->protectMany(FinancialArchiveProtectedTable::SNAPSHOT, array_values(array_unique($snapshotIds)));
        if ($returnIds !== []) {
            $capture->protectedRows()->protectMany(FinancialArchiveProtectedTable::TAX_RETURN, array_values(array_unique($returnIds)));
        }

        return $archives;
    }
}
