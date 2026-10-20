<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Property\Receivables;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Payments\DebtRepository;
use MyInvoice\Service\Property\PropertyInput;
use PDO;

/**
 * Hromadná registrace dluhů ze zaúčtované dávky předpisů.
 *
 * Každý zaúčtovaný kladný předpis dostane jeden dluh se všemi kladnými řádky, jinak by první
 * úhrada jednotky skončila na `debt_inventory_incomplete`. Ruční registrace přes DebtRepository
 * zůstává pro výjimky (jiný právní titul, zajištění, spotřebitelský režim, dělení složek);
 * ty se zvolí režimem `manual` při zaúčtování dávky.
 *
 * Počet dotazů nezávisí na počtu předpisů v dávce (po blocích 500), opakování je idempotentní:
 * řádek, který už dluh má, se znovu neregistruje.
 */
final class ChargeDebtRegistrar
{
    public const MODE_AUTOMATIC = 'automatic';
    public const MODE_MANUAL = 'manual';
    private const CHUNK = 500;

    public function __construct(private readonly Connection $db) {}

    public static function mode(mixed $value): string
    {
        if ($value === null) return self::MODE_AUTOMATIC;
        if (!in_array($value, [self::MODE_AUTOMATIC, self::MODE_MANUAL], true)) PropertyInput::invalid('debt_registration');
        return $value;
    }

    public static function token(int $chargeId): string
    {
        return 'charge-debt-'.$chargeId;
    }

    /**
     * @param list<array> $charges zaúčtované předpisy (stav po zaúčtování: journal_entry_id a row_version)
     * @return array<int,int> charge_id => debt_id nově registrovaných dluhů
     */
    public function registerPosted(int $supplier, array $charges, int $actor): array
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Charge debt registration requires the posting transaction.');
        PropertyInput::id($actor, 'actor');
        $eligible = [];
        foreach ($charges as $charge) {
            if ($charge['status'] !== 'posted' || $charge['journal_entry_id'] === null) throw new \LogicException('Only posted charges own debts.');
            if (bccomp($charge['amount_cents'], '0', 0) <= 0) continue;
            $eligible[$charge['id']] = $charge;
        }
        ksort($eligible, SORT_NUMERIC);
        $registered = [];
        foreach (array_chunk($eligible, self::CHUNK, true) as $chunk) {
            $registered += $this->registerChunk($supplier, $chunk, $actor);
        }
        return $registered;
    }

    private function registerChunk(int $supplier, array $charges, int $actor): array
    {
        $ids = array_keys($charges);
        $marks = self::marks(count($ids));
        $lines = [];
        $bound = [];
        $rows = $this->all(
            'SELECT l.id,l.charge_id,l.amount_cents,l.line_json,dl.debt_id FROM unit_charge_lines l'
            .' LEFT JOIN unit_payment_debt_lines dl ON dl.supplier_id=l.supplier_id AND dl.charge_line_id=l.id'
            .' WHERE l.supplier_id=? AND l.charge_id IN ('.$marks.') ORDER BY l.charge_id,l.id FOR UPDATE',
            [$supplier, ...$ids]
        );
        foreach ($rows as $row) {
            $chargeId = (int)$row['charge_id'];
            if ($row['debt_id'] !== null) {
                $bound[$chargeId] = true;
                continue;
            }
            if (bccomp($row['amount_cents'], '0', 0) <= 0) continue;
            $lines[$chargeId][] = ['id' => (int)$row['id'], 'line' => json_decode($row['line_json'], true, flags: JSON_THROW_ON_ERROR)];
        }
        $tokens = array_map(self::token(...), $ids);
        $existing = [];
        foreach ($this->all('SELECT charge_id FROM unit_payment_debts WHERE supplier_id=? AND idempotency_key IN ('.$marks.') FOR UPDATE', [$supplier, ...$tokens]) as $row) {
            $existing[(int)$row['charge_id']] = true;
        }

        $debtValues = [];
        $debtParams = [];
        $pending = [];
        foreach ($charges as $id => $charge) {
            // Předpis s ručně registrovaným dluhem je výjimka, automatika ho nedoplňuje.
            if (isset($existing[$id]) || isset($bound[$id]) || !isset($lines[$id])) continue;
            $lineIds = array_column($lines[$id], 'id');
            $amount = '0';
            foreach ($lines[$id] as $line) {
                if (bccomp($line['line']['amount_cents'], '0', 0) <= 0) throw new \LogicException('Charge line diverged from its snapshot.');
                $amount = bcadd($amount, $line['line']['amount_cents'], 0);
            }
            $request = [
                'charge_id' => $id,
                'expected_version' => $charge['row_version'],
                'principal_line_ids' => $lineIds,
                'title_ref' => 'Předpis P-'.$id.' za období '.$charge['period_from'].' až '.$charge['period_to'],
                'due_date' => $charge['due_date'],
                'legal' => ['basis' => 'statutory', 'evidence' => 'Zaúčtovaný předpis P-'.$id.' (dávka '.$charge['batch_id'].')', 'regime' => 'general'],
                'security' => ['kind' => 'unsecured', 'evidence' => 'Předpis neeviduje zajištění dluhu.'],
                'damages_for' => null,
            ];
            $token = self::token($id);
            $snapshot = DebtRepository::chargeSnapshot($charge, $lines[$id], $amount, $request);
            $debtValues[] = '(?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))';
            array_push($debtParams, $supplier, $id, self::json($request), self::json($snapshot),
                PropertyInput::hash(['request' => $request, 'token' => $token, 'actor' => $actor]), PropertyInput::hash($snapshot), $token, $actor);
            $pending[$id] = ['token' => $token, 'line_ids' => $lineIds, 'snapshot_hash' => PropertyInput::hash($snapshot)];
        }
        if ($pending === []) return [];

        $this->execute('INSERT INTO unit_payment_debts (supplier_id,charge_id,request_json,snapshot_json,request_hash,snapshot_hash,idempotency_key,created_by,recorded_at) VALUES '.implode(',', $debtValues), $debtParams);
        $created = [];
        $newTokens = array_column($pending, 'token');
        foreach ($this->all('SELECT id,charge_id FROM unit_payment_debts WHERE supplier_id=? AND idempotency_key IN ('.self::marks(count($newTokens)).') ORDER BY id', [$supplier, ...$newTokens]) as $row) {
            $created[(int)$row['charge_id']] = (int)$row['id'];
        }
        if (count($created) !== count($pending)) throw new \LogicException('Registered debts were not materialized.');

        $lineValues = [];
        $lineParams = [];
        $auditValues = [];
        $auditParams = [];
        foreach ($pending as $chargeId => $fact) {
            $debtId = $created[$chargeId];
            foreach ($fact['line_ids'] as $lineId) {
                $lineValues[] = '(?,?,?)';
                array_push($lineParams, $supplier, $debtId, $lineId);
            }
            $payload = ['snapshot_hash' => $fact['snapshot_hash'], 'charge_id' => $chargeId];
            $auditValues[] = "(?,'debt',?,'registered',?,?,?,UTC_TIMESTAMP(6))";
            array_push($auditParams, $supplier, $debtId, self::json($payload), PropertyInput::hash($payload), $actor);
        }
        $this->execute('INSERT INTO unit_payment_debt_lines (supplier_id,debt_id,charge_line_id) VALUES '.implode(',', $lineValues), $lineParams);
        $this->execute('INSERT INTO unit_payment_audit (supplier_id,object_kind,object_id,action,payload_json,payload_hash,created_by,recorded_at) VALUES '.implode(',', $auditValues), $auditParams);
        return $created;
    }

    private static function marks(int $count): string
    {
        return implode(',', array_fill(0, $count, '?'));
    }

    private function execute(string $sql, array $params): \PDOStatement
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    private function all(string $sql, array $params): array
    {
        return $this->execute($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
