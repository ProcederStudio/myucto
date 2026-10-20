<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Receivables;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Receivables\FinancialHistoryRepository;
use MyInvoice\Repository\Property\Receivables\PartyEventRepository;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;

/**
 * Osobní pohled na hrubé pohledávky a kredity SVJ k účetnímu datu.
 *
 * Nárok patří právnímu dlužníkovi (skupinám v roli dlužníka platným k datu), kredit
 * věřitelům z jeho titulu. Řádek je jedna množina osob napříč jednotkami: SJM nebo
 * spoluvlastníci téhož nároku tvoří jeden řádek, aby se jistina neopakovala u každého
 * člena a součet řádků se rovnal hrubým zůstatkům domu z {@see PropertyReconciliation}.
 * Ručitel se do pohledávek nepočítá, vymáhání proti němu je samostatné posouzení.
 */
final class PropertyPartyReconciliation
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{accounting_date:string,knowledge_basis:string,items:list<array<string,mixed>>,next_cursor:?string,totals:array{receivable:string,credit:string}}
     */
    public function at(int $supplier, string $date, int $limit = 50, ?string $after = null): array
    {
        PropertyInput::id($supplier, 'supplier_id');
        if ($limit < 1 || $limit > 100) PropertyInput::invalid('limit');
        if ($after !== null && preg_match('/^[0-9]+(?:,[0-9]+)*$|^-$/D', $after) !== 1) PropertyInput::invalid('after');
        $known = PartyEventRepository::now();
        ReceivableTimeline::accountingDate([], $date, $known);
        $pdo = $this->db->pdo();
        $owned = !$pdo->inTransaction();
        if ($owned) $pdo->beginTransaction();
        try {
            $rows = $this->rows($supplier, $date, $known);
            if ($owned) $pdo->commit();
        } catch (\Throwable $e) {
            if ($owned && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $totals = ['receivable' => '0', 'credit' => '0'];
        foreach ($rows as $row) {
            $totals['receivable'] = bcadd($totals['receivable'], $row['receivable_cents'], 0);
            $totals['credit'] = bcadd($totals['credit'], $row['credit_cents'], 0);
        }
        ksort($rows, SORT_STRING);
        if ($after !== null) $rows = array_filter($rows, static fn (string $key): bool => strcmp($key, $after) > 0, ARRAY_FILTER_USE_KEY);
        $page = array_slice($rows, 0, $limit, true);
        $next = count($rows) > $limit ? (string) array_key_last($page) : null;
        $labels = $this->labels($supplier, array_merge([], ...array_column($page, 'party_ids')));
        foreach ($page as &$row) {
            $row['parties'] = array_map(static fn (int $id): array => ['id' => $id, 'label' => $labels[$id] ?? ''], $row['party_ids']);
        }
        unset($row);

        return ['accounting_date' => $date, 'knowledge_basis' => 'current_ledger', 'items' => array_values($page),
            'next_cursor' => $next, 'totals' => $totals];
    }

    /** @return array<string,array<string,mixed>> klíč množiny osob => řádek */
    private function rows(int $supplier, string $date, string $known): array
    {
        $history = (new FinancialHistoryRepository($this->db))->read($supplier);
        $partyEvents = (new PartyEventRepository($this->db))->history($supplier);
        $endOfDay = PropertyInstant::normalize((new \DateTimeImmutable($date, new \DateTimeZone('Europe/Prague')))->modify('+1 day')->format(DATE_ATOM));

        $rows = [];
        $claims = [];
        foreach ($history['debts'] as $debt) $claims[] = [$debt, true];
        foreach ($history['unregistered_claims'] as $claim) $claims[] = [$claim, false];
        foreach ($claims as [$debt, $registered]) {
            $amount = ReceivableTimeline::accountingDate($debt['events'], $date, $known)['gross_receivable_cents'];
            if ($amount === null) ReceivableException::reject('history_incomplete');
            if ($amount === '0') continue;
            // Přechod dluhu (§ 1186) se váže jen k evidovanému dluhu, nevyřízený řádek předpisu nese titul.
            $groups = $registered ? self::groupsAt($debt, $partyEvents, $endOfDay, $known) : ($debt['snapshot']['liability_groups'] ?? []);
            $groups = array_values(array_filter($groups, static fn (array $g): bool => $g['role'] === 'debtor'));
            $members = [];
            foreach ($groups as $group) foreach ($group['members'] as $member) $members[] = (int) $member;
            $row = &self::row($rows, $members, (int) $debt['unit_id']);
            $row['receivable_cents'] = bcadd($row['receivable_cents'], $amount, 0);
            foreach ($groups as $group) $row['group_keys'][$group['group_key']] = true;
            if (count($groups) !== 1) $row['liability_review_required'] = true;
            unset($row);
        }
        foreach ($history['credits'] as $credit) {
            $amount = ReceivableTimeline::accountingDate($credit['events'], $date, $known)['gross_credit_cents'];
            if ($amount === null) ReceivableException::reject('history_incomplete');
            if ($amount === '0') continue;
            $row = &self::row($rows, array_map(static fn (array $p): int => (int) $p['id'], $credit['snapshot']['parties'] ?? []), (int) $credit['unit_id']);
            $row['credit_cents'] = bcadd($row['credit_cents'], $amount, 0);
            unset($row);
        }
        foreach ($rows as &$row) {
            $row['unit_ids'] = array_map('intval', array_keys($row['unit_ids']));
            sort($row['unit_ids'], SORT_NUMERIC);
            $row['group_keys'] = array_keys($row['group_keys']);
            sort($row['group_keys'], SORT_STRING);
        }
        unset($row);
        return $rows;
    }

    /**
     * Skupiny odpovědnosti k datu: poslední potvrzená stranová událost dluhu účinná před
     * koncem dne, jinak skupiny z titulu nároku.
     */
    private static function groupsAt(array $debt, array $partyEvents, string $endOfDay, string $known): array
    {
        $groups = $debt['snapshot']['liability_groups'] ?? [];
        foreach ($partyEvents as $event) {
            if ($event['recorded_at'] > $known || $event['effective_at'] >= $endOfDay) continue;
            foreach ($event['snapshot']['debts'] as $part) {
                if ($part['debt_id'] === $debt['id']) $groups = $part['groups'];
            }
        }
        return $groups;
    }

    /** @param list<int> $ids @return array<int,string> označení osob stránky ze současného adresáře */
    private function labels(int $supplier, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) return [];
        $q = $this->db->pdo()->prepare(
            'SELECT id, company_name, first_name, last_name FROM clients WHERE supplier_id = ? AND id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $q->execute([$supplier, ...$ids]);
        $out = [];
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $client) {
            $person = trim((string) ($client['first_name'] ?? '') . ' ' . (string) ($client['last_name'] ?? ''));
            $out[(int) $client['id']] = trim((string) ($client['company_name'] ?? '')) !== '' ? (string) $client['company_name'] : $person;
        }
        return $out;
    }

    /** @param list<int> $parties */
    private static function &row(array &$rows, array $parties, int $unitId): array
    {
        $parties = array_values(array_unique($parties));
        sort($parties, SORT_NUMERIC);
        // Nárok bez doložených osob drží samostatný řádek, aby se součet domu neztratil.
        $key = $parties === [] ? '-' : implode(',', $parties);
        $rows[$key] ??= ['party_ids' => $parties, 'unit_ids' => [], 'group_keys' => [], 'receivable_cents' => '0', 'credit_cents' => '0',
            'liability_review_required' => $parties === []];
        $rows[$key]['unit_ids'][$unitId] = true;
        return $rows[$key];
    }
}
