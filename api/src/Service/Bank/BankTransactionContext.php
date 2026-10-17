<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use PDO;

final class BankTransactionContext
{
    public function __construct(private readonly Connection $db) {}

    private function lockContext(int $supplierId): void
    {
        (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
    }

    /** @return array<string,mixed>|null */
    public function statement(int $supplierId, int $statementId): ?array
    {
        $this->lockContext($supplierId);
        $stmt = $this->db->pdo()->prepare(
            'SELECT bs.* FROM bank_statements bs WHERE bs.id = ? AND '
                . BankStatementOwnershipResolver::sql('bs', true) . ' FOR UPDATE'
        );
        $stmt->execute([$statementId, ...BankStatementOwnershipResolver::params($supplierId)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function lock(int $supplierId, int $txId): ?array
    {
        $this->lockContext($supplierId);
        if (!(new BankStatementOwnershipResolver($this->db))->transactionOwned($txId, $supplierId, true)) {
            return null;
        }
        return $this->find($txId, true);
    }

    /** @return array<string,mixed>|null */
    public function find(int $txId, bool $currentRead = false): ?array
    {
        if ($currentRead && !$this->db->pdo()->inTransaction()) {
            throw new \LogicException('Current bank reads require a transaction.');
        }
        $lock = $currentRead ? ' FOR UPDATE' : '';
        $stmt = $this->db->pdo()->prepare(
            'SELECT bt.*, bs.supplier_id AS statement_supplier_id,
                    bs.account_number AS recipient_account, bs.bank_code AS recipient_bank,
                    bs.currency AS statement_currency,
                    (EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.bank_transaction_id = bt.id' . $lock . ')
                     OR EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.bank_transaction_id = bt.id' . $lock . '))
                        AS has_explicit_allocation
               FROM bank_transactions bt
               JOIN bank_statements   bs ON bs.id = bt.statement_id
              WHERE bt.id = ?' . $lock
        );
        $stmt->execute([$txId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
