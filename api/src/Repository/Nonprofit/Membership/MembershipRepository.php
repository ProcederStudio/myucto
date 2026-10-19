<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Service\Nonprofit\Membership\MembershipRevisionTimeline;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;

final class MembershipRepository
{
    public function __construct(private readonly Connection $db) {}

    public function member(int $supplier, int $member, bool $currentRead = false): ?array
    {
        $this->current($currentRead);
        $q = $this->db->pdo()->prepare('SELECT * FROM nonprofit_members WHERE supplier_id=? AND id=?' . ($currentRead ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $member]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->memberRow($row);
    }

    public function page(int $supplier, int $afterId = 0, int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        $q = $this->db->pdo()->prepare(
            "SELECT m.*,c.company_name,r.revision_no,r.effective_from,r.status
               FROM nonprofit_members m
               JOIN clients c ON c.supplier_id=m.supplier_id AND c.id=m.client_id
          LEFT JOIN nonprofit_member_revisions r
                 ON r.supplier_id=m.supplier_id AND r.member_id=m.id
                AND NOT EXISTS(SELECT 1 FROM nonprofit_member_revisions newer
                                WHERE newer.supplier_id=r.supplier_id AND newer.member_id=r.member_id
                                  AND newer.revision_no>r.revision_no)
              WHERE m.supplier_id=? AND m.id>?
              ORDER BY m.id
              LIMIT {$limit}"
        );
        $q->execute([$supplier, max(0, $afterId)]);
        $rows = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach (['id', 'supplier_id', 'client_id', 'created_by'] as $field) $row[$field] = (int) $row[$field];
            $row['revision_no'] = $row['revision_no'] === null ? null : (int) $row['revision_no'];
            $rows[] = $row;
        }
        return $rows;
    }

    public function byIdentity(int $supplier, int $client, string $number, bool $currentRead = false): ?array
    {
        $this->current($currentRead);
        $q = $this->db->pdo()->prepare('SELECT * FROM nonprofit_members WHERE supplier_id=? AND (client_id=? OR member_number=?) ORDER BY id' . ($currentRead ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $client, $number]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) return null;
        if (count($rows) !== 1 || (int) $rows[0]['client_id'] !== $client || $rows[0]['member_number'] !== $number) {
            throw new MembershipException('nonprofit_membership_identity_conflict', 'Klient nebo členské číslo už patří jinému členovi.', 409);
        }
        return $this->memberRow($rows[0]);
    }

    public function insertMember(int $supplier, int $client, string $number, int $actor): array
    {
        $q = $this->db->pdo()->prepare('INSERT INTO nonprofit_members(supplier_id,client_id,member_number,created_by) VALUES(?,?,?,?)');
        $q->execute([$supplier, $client, $number, $actor]);
        return $this->member($supplier, (int) $this->db->pdo()->lastInsertId(), true)
            ?? throw new \LogicException('Inserted member disappeared.');
    }

    public function history(int $supplier, int $member, bool $currentRead = false): array
    {
        $this->current($currentRead);
        $q = $this->db->pdo()->prepare('SELECT * FROM nonprofit_member_revisions WHERE supplier_id=? AND member_id=? ORDER BY revision_no,id' . ($currentRead ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $member]);
        $rows = [];
        $previous = null;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $raw) {
            $row = $this->revisionRow($raw);
            if ($row['revision_no'] !== ($previous['revision_no'] ?? 0) + 1 || $row['previous_id'] !== ($previous['id'] ?? null)
                || !hash_equals($row['parent_hash'], $previous['record_hash'] ?? str_repeat('0', 64))
                || !hash_equals($row['request_hash'], NonprofitTaxRules::hash($row['request']))
                || !hash_equals($row['record_hash'], NonprofitTaxRules::hash($this->revisionRecord($row)))) {
                throw new MembershipException('nonprofit_membership_revision_corrupt', 'Historie členství není průkazná.', 409);
            }
            $rows[] = $row;
            $previous = $row;
        }
        MembershipRevisionTimeline::intervals($rows);
        return $rows;
    }

    public function revisionByKey(int $supplier, string $key, bool $currentRead = false): ?array
    {
        $this->current($currentRead);
        $q = $this->db->pdo()->prepare('SELECT member_id FROM nonprofit_member_revisions WHERE supplier_id=? AND idempotency_key=?' . ($currentRead ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $key]);
        $member = $q->fetchColumn();
        if ($member === false) return null;
        foreach ($this->history($supplier, (int) $member, $currentRead) as $row) if ($row['idempotency_key'] === $key) return $row;
        throw new MembershipException('nonprofit_membership_revision_corrupt', 'Historie členství není průkazná.', 409);
    }

    public function revision(int $supplier, int $member, int $revision, bool $currentRead = false): ?array
    {
        foreach ($this->history($supplier, $member, $currentRead) as $row) if ($row['id'] === $revision) return $row;
        return null;
    }

    public function appendRevision(array $row): array
    {
        $record = $this->revisionRecord($row);
        $row['record_hash'] = NonprofitTaxRules::hash($record);
        $q = $this->db->pdo()->prepare('INSERT INTO nonprofit_member_revisions(supplier_id,member_id,revision_no,previous_id,effective_from,status,legal_basis_json,request_json,idempotency_key,request_hash,parent_hash,record_hash,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$row['supplier_id'], $row['member_id'], $row['revision_no'], $row['previous_id'], $row['effective_from'], $row['status'],
            NonprofitTaxRules::canonicalJson($row['legal_basis']), NonprofitTaxRules::canonicalJson($row['request']), $row['idempotency_key'], $row['request_hash'], $row['parent_hash'], $row['record_hash'], $row['created_by']]);
        return $this->revision((int) $row['supplier_id'], (int) $row['member_id'], (int) $this->db->pdo()->lastInsertId(), true)
            ?? throw new \LogicException('Inserted membership revision disappeared.');
    }

    public function revisionRecord(array $row): array
    {
        return array_intersect_key($row, array_flip(['supplier_id', 'member_id', 'revision_no', 'previous_id', 'effective_from', 'status', 'legal_basis',
            'request', 'idempotency_key', 'request_hash', 'parent_hash', 'created_by']));
    }

    private function revisionRow(array $row): array
    {
        foreach (['id', 'supplier_id', 'member_id', 'revision_no', 'created_by'] as $field) $row[$field] = (int) $row[$field];
        $row['previous_id'] = $row['previous_id'] === null ? null : (int) $row['previous_id'];
        foreach (['legal_basis', 'request'] as $field) {
            $row[$field] = json_decode($row[$field . '_json'], true, flags: JSON_THROW_ON_ERROR);
            unset($row[$field . '_json']);
        }
        return $row;
    }

    private function memberRow(array $row): array
    {
        foreach (['id', 'supplier_id', 'client_id', 'created_by'] as $field) $row[$field] = (int) $row[$field];
        return $row;
    }

    private function current(bool $currentRead): void
    {
        if ($currentRead && !$this->db->pdo()->inTransaction()) throw new \LogicException('Membership current reads require a transaction.');
    }
}
