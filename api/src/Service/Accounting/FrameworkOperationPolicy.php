<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

final class FrameworkOperationPolicy
{
    public static function allows(AccountingRoleProfile $profile, string $operation, array $context = []): bool
    {
        if (!in_array($operation, ['profit_distribution', 'deferred_tax', 'reserve.other', 'reserve.income_tax', 'reserve.legal', 'provision.accounting', 'provision.legal'], true)) {
            throw new \InvalidArgumentException('Unknown framework operation: ' . $operation);
        }
        if ($profile->framework === AccountingFrameworkService::CZ_500) {
            return true;
        }
        return in_array($operation, ['reserve.legal', 'provision.legal'], true)
            && ($context['taxable_activity'] ?? null) === true
            && is_string($context['legal_basis'] ?? null)
            && trim($context['legal_basis']) !== '';
    }

    public static function assertAllowed(AccountingRoleProfile $profile, string $operation, array $context = []): void
    {
        if (!self::allows($profile, $operation, $context)) {
            throw new AccountingFrameworkException('accounting_framework_operation_not_supported', 'Tato operace není v daném účetním rámci podporována.', 422);
        }
    }
}
