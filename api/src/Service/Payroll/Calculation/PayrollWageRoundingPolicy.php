<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Calculation;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Volba zaokrouhlení mzdy firmy z nastavení zaměstnavatele; bez nastavení zákonné nahoru.
 *
 * Statická nad spojením, které mají všechny výpočty náhrad a krácení mzdy k dispozici,
 * takže je nikdo nemusí dostávat jako novou závislost.
 */
final class PayrollWageRoundingPolicy
{
    public static function forSupplier(Connection $db, int $supplierId): PayrollWageRounding
    {
        $stmt = $db->pdo()->prepare(
            'SELECT wage_rounding FROM payroll_employer_settings WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();

        return $value === false
            ? PayrollWageRounding::Ceil
            : (PayrollWageRounding::tryFrom((string) $value) ?? PayrollWageRounding::Ceil);
    }
}
