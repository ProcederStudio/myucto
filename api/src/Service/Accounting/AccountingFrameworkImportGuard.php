<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;

final class AccountingFrameworkImportGuard
{
    public function __construct(private readonly Connection $db) {}

    public function assertCz500Periods(int $supplierId, array $periods, bool $currentRead = false): void
    {
        if ($currentRead) {
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
        }
        $frameworks = new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db));
        foreach ($periods as $period) {
            foreach ($frameworks->forInterval($supplierId, $period['starts_on'], $period['ends_on'], $currentRead) as $framework) {
                if ($framework !== AccountingFrameworkService::CZ_500) {
                    throw new AccountingFrameworkException('accounting_framework_import_unsupported',
                        'Převod účetnictví je podporován pouze pro období vedená celé v rámci vyhlášky 500.', 409);
                }
            }
        }
    }
}
