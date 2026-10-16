<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

final readonly class AccountingRoleProfile
{
    public function __construct(public string $framework = AccountingFrameworkService::CZ_500)
    {
        if (!in_array($framework, [AccountingFrameworkService::CZ_500, AccountingFrameworkService::CZ_504], true)) {
            throw new \InvalidArgumentException('Unknown accounting framework.');
        }
    }

    public function account(string $role): string
    {
        $accounts = $this->framework === AccountingFrameworkService::CZ_504
            ? ['closing.opening_balance' => '961', 'closing.final_balance' => '962', 'result.current' => '933', 'result.pending_approval' => '931', 'result.retained_profit' => '932', 'result.retained_loss' => '932']
            : ['closing.opening_balance' => '701', 'closing.final_balance' => '702', 'result.current' => '710', 'result.pending_approval' => '431', 'result.retained_profit' => '428', 'result.retained_loss' => '429'];
        return $accounts[$role] ?? throw new \InvalidArgumentException('Unknown account role: ' . $role);
    }

    public function openingAccount(string $code): string
    {
        return $this->framework === AccountingFrameworkService::CZ_504 && str_starts_with($code, $this->account('result.retained_profit'))
            ? $this->account('result.pending_approval')
            : $code;
    }

    public function isClosingAccount(string $code): bool
    {
        return str_starts_with($code, $this->framework === AccountingFrameworkService::CZ_504 ? '96' : '70');
    }

    public function isTransientAccount(string $code): bool
    {
        return $this->isClosingAccount($code) || str_starts_with($code, $this->account('result.current'));
    }

    public function assertCompatibleOpening(self $next): void
    {
        if ($this->framework !== $next->framework) {
            throw new AccountingFrameworkException('accounting_framework_transition_not_supported', 'Převod počátečních stavů mezi účetními rámci zatím není podporován.', 409);
        }
    }
}
