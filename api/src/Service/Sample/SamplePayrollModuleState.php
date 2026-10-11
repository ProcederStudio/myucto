<?php

declare(strict_types=1);

namespace MyInvoice\Service\Sample;

use DateTimeImmutable;
use PDO;

final class SamplePayrollModuleState
{
    private const STATUSES = ['disabled', 'setup', 'qualification_required', 'active', 'suspended'];
    private const FIELDS = ['status', 'start_period', 'row_version', 'activated_by', 'activated_at', 'suspended_at', 'created_at', 'updated_at'];

    public static function prepare(PDO $pdo, int $supplierId, int $userId, string $start, callable $track): void
    {
        $row = self::row($pdo, $supplierId);
        if ($row === null) {
            $pdo->prepare('INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at) VALUES (?, "setup", ?, ?, NOW())')->execute([$supplierId, $start, $userId]);
            $track('payroll_module_state', $supplierId);
            return;
        }
        foreach (self::FIELDS as $field) $track('payroll_restore_' . $field, self::encode($field, $row[$field]));
        $status = in_array($row['status'], ['disabled', 'suspended'], true) ? 'setup' : $row['status'];
        $pdo->prepare('UPDATE payroll_module_state SET status = ?, start_period = ?, row_version = row_version + 1 WHERE supplier_id = ?')->execute([$status, $start, $supplierId]);
    }

    public static function trackGenerated(PDO $pdo, int $supplierId, callable $track): void
    {
        $row = self::row($pdo, $supplierId);
        if ($row === null) return;
        foreach (self::FIELDS as $field) $track('payroll_expected_' . $field, self::encode($field, $row[$field]));
    }

    public static function restore(PDO $pdo, int $supplierId): void
    {
        $stmt = $pdo->prepare('SELECT entity_type, entity_id FROM sample_data_entries WHERE supplier_id = ? AND (entity_type LIKE "payroll_restore_%" OR entity_type LIKE "payroll_expected_%")');
        $stmt->execute([$supplierId]);
        $values = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!isset($values['payroll_restore_status'])) return;
        $row = self::row($pdo, $supplierId);
        $params = [];
        foreach (self::FIELDS as $field) {
            if ($row === null || !isset($values['payroll_expected_' . $field], $values['payroll_restore_' . $field])
                || self::encode($field, $row[$field]) !== (int) $values['payroll_expected_' . $field]) {
                throw new \DomainException('Nastavení mzdového modulu se od generování ukázek změnilo. Odebrání bylo zastaveno.');
            }
            $params[] = self::decode($field, (int) $values['payroll_restore_' . $field]);
        }
        $assignments = implode(', ', array_map(static fn (string $field): string => "`$field` = ?", self::FIELDS));
        $pdo->prepare("UPDATE payroll_module_state SET $assignments WHERE supplier_id = ?")->execute([...$params, $supplierId]);
    }

    private static function row(PDO $pdo, int $supplierId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM payroll_module_state WHERE supplier_id = ? FOR UPDATE');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private static function encode(string $field, mixed $value): int
    {
        if ($value === null) return 1;
        if ($field === 'status') return (int) array_search($value, self::STATUSES, true) + 2;
        if ($field === 'start_period' || str_ends_with($field, '_at')) return (int) preg_replace('/\D/', '', (string) $value) + 1;
        return (int) $value + 2;
    }

    private static function decode(string $field, int $value): mixed
    {
        if ($value === 1) return null;
        if ($field === 'status') return self::STATUSES[$value - 2];
        if ($field === 'start_period') return DateTimeImmutable::createFromFormat('!Ymd', (string) ($value - 1))->format('Y-m-d');
        if (str_ends_with($field, '_at')) return DateTimeImmutable::createFromFormat('!YmdHis', (string) ($value - 1))->format('Y-m-d H:i:s');
        return $value - 2;
    }
}
