<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;

/**
 * Zbývající částka a atomický zápis bankovní vratky k vydanému nebo přijatému dokladu.
 */
final class CreditRefundAllocation
{
    /** @return array{amount:float,remaining:float,covered:bool}|null */
    public static function allocateIssued(
        PDO $pdo,
        int $supplierId,
        int $transactionId,
        int $invoiceId,
        float $requestedAmount,
        string $matchType,
        ?int $confidence = null,
        ?int $userId = null,
        ?float $exactTolerance = null,
    ): ?array {
        self::assertTransaction($pdo);
        $remaining = self::issuedRemaining($pdo, $supplierId, $invoiceId, $transactionId, true);
        $allocation = self::allocation($remaining, $requestedAmount, $exactTolerance);
        if ($allocation === null) {
            return null;
        }

        $existing = $pdo->prepare(
            'SELECT id, match_type FROM payment_matches
              WHERE supplier_id = ? AND bank_transaction_id = ? AND invoice_id = ?
                AND purchase_invoice_id IS NULL
              ORDER BY id LIMIT 1'
        );
        $existing->execute([$supplierId, $transactionId, $invoiceId]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            $pdo->prepare(
                'INSERT INTO payment_matches
                    (supplier_id, bank_transaction_id, invoice_id, amount, match_type, match_confidence, matched_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $supplierId,
                $transactionId,
                $invoiceId,
                $allocation['amount'],
                $matchType,
                $matchType === 'manual' ? null : $confidence,
                $userId !== null && $userId > 0 ? $userId : null,
            ]);
        } elseif ($matchType === 'manual') {
            $pdo->prepare(
                "UPDATE payment_matches
                    SET amount = ?, match_type = 'manual', match_confidence = NULL, matched_by_user_id = ?
                  WHERE id = ?"
            )->execute([
                $allocation['amount'],
                $userId !== null && $userId > 0 ? $userId : null,
                (int) $row['id'],
            ]);
        } elseif ((string) $row['match_type'] === 'auto') {
            $pdo->prepare('UPDATE payment_matches SET amount = ?, match_confidence = ? WHERE id = ?')
                ->execute([$allocation['amount'], $confidence, (int) $row['id']]);
        }

        return $allocation;
    }

    /** @return array{amount:float,remaining:float,covered:bool}|null */
    public static function allocatePurchase(
        PDO $pdo,
        int $supplierId,
        int $transactionId,
        int $purchaseInvoiceId,
        float $requestedAmount,
        string $matchType,
        ?int $confidence = null,
        ?int $userId = null,
        ?float $exactTolerance = null,
        ?float $paymentMatchAmount = null,
    ): ?array {
        self::assertTransaction($pdo);
        $remaining = self::purchaseRemaining($pdo, $supplierId, $purchaseInvoiceId, $transactionId, true);
        $allocation = self::allocation($remaining, $requestedAmount, $exactTolerance);
        if ($allocation === null) {
            return null;
        }

        PurchasePaymentMatchWriter::record(
            $pdo,
            $supplierId,
            $transactionId,
            $purchaseInvoiceId,
            round(abs($paymentMatchAmount ?? $allocation['amount']), 2),
            $matchType,
            $confidence,
            $userId,
        );

        return $allocation;
    }

    public static function issuedRemaining(
        PDO $pdo,
        int $supplierId,
        int $invoiceId,
        int $excludeTransactionId = 0,
        bool $lock = false,
    ): ?float {
        return self::remaining(
            $pdo,
            'invoices',
            'invoice_id',
            'amount_to_pay',
            $supplierId,
            $invoiceId,
            $excludeTransactionId,
            $lock,
        );
    }

    public static function purchaseRemaining(
        PDO $pdo,
        int $supplierId,
        int $purchaseInvoiceId,
        int $excludeTransactionId = 0,
        bool $lock = false,
    ): ?float {
        if ($lock) {
            self::assertTransaction($pdo);
        }
        $currentRead = $lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $lockSql = $currentRead ? ' FOR UPDATE' : '';
        $document = $pdo->prepare(
            'SELECT COALESCE(amount_to_pay, total_with_vat, 0) + COALESCE(rounding, 0) AS amount_to_pay
               FROM purchase_invoices
              WHERE id = ? AND supplier_id = ?' . $lockSql
        );
        $document->execute([$purchaseInvoiceId, $supplierId]);
        $amountToPay = $document->fetchColumn();
        if ($amountToPay === false) {
            return null;
        }

        $excludeSql = $excludeTransactionId > 0 ? ' AND pm.bank_transaction_id <> ?' : '';
        $params = [$supplierId, $purchaseInvoiceId];
        if ($excludeTransactionId > 0) {
            $params[] = $excludeTransactionId;
        }
        $allocatedInDocumentCurrency = PurchaseSettledExpr::bankAmountSql(
            'pm',
            'pmbt',
            'pmbs',
            'pi',
            'pmdc',
        );
        $allocated = $pdo->prepare(
            "SELECT {$allocatedInDocumentCurrency} AS amount
               FROM payment_matches pm
               JOIN purchase_invoices pi ON pi.id = pm.purchase_invoice_id AND pi.supplier_id = pm.supplier_id
          LEFT JOIN bank_transactions pmbt ON pmbt.id = pm.bank_transaction_id
          LEFT JOIN bank_statements pmbs ON pmbs.id = pmbt.statement_id
          LEFT JOIN currencies pmdc ON pmdc.id = pi.currency_id
              WHERE pm.supplier_id = ? AND pm.purchase_invoice_id = ?{$excludeSql}
              ORDER BY pm.id{$lockSql}"
        );
        $allocated->execute($params);
        $allocatedAmount = array_sum(array_map(
            static fn (mixed $amount): float => max(0.0, -(float) $amount),
            $allocated->fetchAll(PDO::FETCH_COLUMN),
        ));

        return max(0.0, round(abs((float) $amountToPay) - $allocatedAmount, 2));
    }

    private static function remaining(
        PDO $pdo,
        string $table,
        string $targetColumn,
        string $amountSql,
        int $supplierId,
        int $documentId,
        int $excludeTransactionId,
        bool $lock,
    ): ?float {
        if ($lock) {
            self::assertTransaction($pdo);
        }
        $currentRead = $lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $lockSql = $currentRead ? ' FOR UPDATE' : '';
        $document = $pdo->prepare(
            "SELECT {$amountSql} AS amount_to_pay
               FROM {$table}
              WHERE id = ? AND supplier_id = ?{$lockSql}"
        );
        $document->execute([$documentId, $supplierId]);
        $amountToPay = $document->fetchColumn();
        if ($amountToPay === false) {
            return null;
        }

        $excludeSql = $excludeTransactionId > 0 ? ' AND bank_transaction_id <> ?' : '';
        $params = [$supplierId, $documentId];
        if ($excludeTransactionId > 0) {
            $params[] = $excludeTransactionId;
        }

        if ($currentRead) {
            $allocated = $pdo->prepare(
                "SELECT amount FROM payment_matches
                  WHERE supplier_id = ? AND {$targetColumn} = ?{$excludeSql}
                  FOR UPDATE"
            );
            $allocated->execute($params);
            $allocatedAmount = array_sum(array_map('floatval', $allocated->fetchAll(PDO::FETCH_COLUMN)));
        } else {
            $allocated = $pdo->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM payment_matches
                  WHERE supplier_id = ? AND {$targetColumn} = ?{$excludeSql}"
            );
            $allocated->execute($params);
            $allocatedAmount = (float) $allocated->fetchColumn();
        }

        return max(0.0, round(abs((float) $amountToPay) - $allocatedAmount, 2));
    }

    /** @return array{amount:float,remaining:float,covered:bool}|null */
    private static function allocation(?float $remaining, float $requestedAmount, ?float $exactTolerance): ?array
    {
        $requestedAmount = round(abs($requestedAmount), 2);
        if ($remaining === null
            || $remaining <= InvoicePaymentService::TOLERANCE
            || $requestedAmount <= InvoicePaymentService::TOLERANCE
            || ($exactTolerance !== null && abs($requestedAmount - $remaining) > $exactTolerance)
        ) {
            return null;
        }

        $amount = min($remaining, $requestedAmount);

        return [
            'amount' => $amount,
            'remaining' => $remaining,
            'covered' => $amount >= $remaining - InvoicePaymentService::TOLERANCE,
        ];
    }

    private static function assertTransaction(PDO $pdo): void
    {
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Alokaci vratky lze zapsat jen uvnitř databázové transakce.');
        }
    }
}
