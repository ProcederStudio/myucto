<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use PHPUnit\Framework\Attributes\Group;

/**
 * #164: bankovní řádek peněžního deníku nese skutečnou částku pohybu, ne součet
 * navázaných úhrad, a avízo se nezapočte vedle výpisu, který týž den pokrývá.
 */
#[Group('integration')]
final class CashJournalBankAllocationTest extends CashJournalTestCase
{
    public function testDuplicateAllocationDoesNotInflateExpense(): void
    {
        $statement = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($statement, -1000.0, ['match_status' => 'manual']);
        $invoice = $this->purchaseInvoice($this->supplierId, ['without' => 1000.0, 'with' => 1000.0, 'status' => 'paid']);
        $this->paymentMatch($this->supplierId, $tx, $invoice, 1000.0);
        $this->paymentMatch($this->supplierId, $tx, $invoice, 1000.0);

        $result = $this->fullYear($this->supplierId, false);

        $row = $this->bankRow($result, $tx);
        self::assertEqualsWithDelta(1000.0, $row['expense'], 0.001, 'Výdaj je skutečný pohyb, ne součet dvou párování.');
        self::assertEqualsWithDelta(1000.0, $result['totals']['vydaj_danovy'], 0.001);
        self::assertEqualsWithDelta(-1000.0, $result['closing_balance'], 0.001, 'Zůstatek klesne o pohyb, ne o 2 000.');
        $warning = $this->warning($result, 'bank_allocation_exceeds_movement', $tx);
        self::assertTrue($warning['blocking'], 'Převis párování musí účetní opravit.');
    }

    public function testValidSplitBetweenInvoicesStaysIntact(): void
    {
        $statement = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($statement, -1000.0, ['match_status' => 'manual']);
        $taxable = $this->purchaseInvoice($this->supplierId, ['without' => 400.0, 'with' => 400.0, 'status' => 'paid']);
        $advance = $this->purchaseInvoice($this->supplierId, ['without' => 600.0, 'with' => 600.0, 'status' => 'paid', 'tax_deductible' => 0]);
        $this->paymentMatch($this->supplierId, $tx, $taxable, 400.0);
        $this->paymentMatch($this->supplierId, $tx, $advance, 600.0);

        $result = $this->fullYear($this->supplierId, false);

        self::assertEqualsWithDelta(1000.0, $this->bankRow($result, $tx)['expense'], 0.001);
        self::assertEqualsWithDelta(400.0, $result['totals']['vydaj_danovy'], 0.001);
        self::assertEqualsWithDelta(600.0, $result['totals']['vydaj_nedanovy'], 0.001);
        self::assertSame([], $this->allocationWarnings($result));
    }

    public function testOneInvoicePaidByTwoMovementsKeepsBoth(): void
    {
        $statement = $this->statement($this->supplierId, $this->accountA);
        $invoice = $this->purchaseInvoice($this->supplierId, ['without' => 1000.0, 'with' => 1000.0, 'status' => 'paid']);
        $first = $this->bankTx($statement, -400.0, ['match_status' => 'manual']);
        $second = $this->bankTx($statement, -600.0, ['match_status' => 'manual', 'posted_at' => self::YEAR . '-06-20']);
        $this->paymentMatch($this->supplierId, $first, $invoice, 400.0);
        $this->paymentMatch($this->supplierId, $second, $invoice, 600.0);

        $result = $this->fullYear($this->supplierId, false);

        self::assertEqualsWithDelta(400.0, $this->bankRow($result, $first)['expense'], 0.001);
        self::assertEqualsWithDelta(600.0, $this->bankRow($result, $second)['expense'], 0.001);
        self::assertEqualsWithDelta(1000.0, $result['totals']['vydaj_danovy'], 0.001);
        self::assertSame([], $this->allocationWarnings($result));
    }

    public function testOverAllocatedIncomeDoesNotInflateIncome(): void
    {
        $statement = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($statement, 1000.0, ['match_status' => 'manual']);
        foreach ([1000.0, 500.0] as $amount) {
            $invoice = $this->saleInvoice($this->supplierId, ['without' => $amount, 'with' => $amount, 'status' => 'paid']);
            $this->invoicePayment($this->supplierId, $invoice, $amount, 'bank', $tx);
        }

        $result = $this->fullYear($this->supplierId, false);

        self::assertEqualsWithDelta(1000.0, $this->bankRow($result, $tx)['income'], 0.001);
        self::assertEqualsWithDelta(1000.0, $result['totals']['prijem_danovy'], 0.001, 'Příjem nesmí být 1 500.');
        self::assertTrue($this->warning($result, 'bank_allocation_exceeds_movement', $tx)['blocking']);
    }

    public function testPartiallyAllocatedMovementKeepsRemainderVisible(): void
    {
        $statement = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($statement, -1000.0, ['match_status' => 'manual']);
        $invoice = $this->purchaseInvoice($this->supplierId, ['without' => 600.0, 'with' => 600.0, 'status' => 'paid']);
        $this->paymentMatch($this->supplierId, $tx, $invoice, 600.0);

        $result = $this->fullYear($this->supplierId, false);

        self::assertEqualsWithDelta(1000.0, $this->bankRow($result, $tx)['expense'], 0.001, 'Celý pohyb zůstává v deníku.');
        self::assertEqualsWithDelta(600.0, $result['totals']['vydaj_danovy'], 0.001);
        self::assertEqualsWithDelta(400.0, $result['totals']['nezarazeno'], 0.001, 'Zbytek bez dokladu je nezařazený.');
        $warning = $this->warning($result, 'bank_allocation_below_movement', $tx);
        self::assertEqualsWithDelta(400.0, $warning['amount'], 0.001);
        self::assertFalse($warning['blocking']);
    }

    public function testForeignInvoicePaidFromCrownAccountUsesCreditedCrowns(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, "XTS", "XTS", "XTS", "XTS", "XTS", 2, 1, 0)'
        )->execute([$this->supplierId]);
        $xts = (int) $pdo->lastInsertId();
        $invoice = $this->saleInvoice($this->supplierId, ['without' => 1000.0, 'with' => 1000.0, 'status' => 'paid']);
        $pdo->prepare('UPDATE invoices SET currency_id = ?, exchange_rate = 24.50 WHERE id = ?')->execute([$xts, $invoice]);
        $statement = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($statement, 24400.0, ['match_status' => 'manual']);
        $pdo->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, bank_transaction_id)
             VALUES (?, ?, ?, 1000.00, "XTS", "bank", ?)'
        )->execute([$this->supplierId, $invoice, self::YEAR . '-06-15', $tx]);

        $result = $this->fullYear($this->supplierId, false);

        self::assertEqualsWithDelta(24400.0, $this->bankRow($result, $tx)['income'], 0.001);
        self::assertEqualsWithDelta(24400.0, $result['totals']['prijem_danovy'], 0.001,
            'Kasový příjem jsou skutečně připsané koruny, ne 1 000 × 24,50.');
        self::assertSame([], $this->allocationWarnings($result), 'Kurzový rozdíl není chyba párování.');
    }

    public function testDuplicateAllocationBeforePeriodDoesNotSkewOpeningBalance(): void
    {
        $statement = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($statement, -1000.0, ['match_status' => 'manual', 'posted_at' => (self::YEAR - 1) . '-12-15']);
        $invoice = $this->purchaseInvoice($this->supplierId, ['without' => 1000.0, 'with' => 1000.0, 'status' => 'paid']);
        $this->paymentMatch($this->supplierId, $tx, $invoice, 1000.0);
        $this->paymentMatch($this->supplierId, $tx, $invoice, 1000.0);

        $result = $this->fullYear($this->supplierId, false);

        self::assertEqualsWithDelta(-1000.0, $result['opening_balance'], 0.001);
    }

    public function testNoticeTakenOverByStatementCountsOnce(): void
    {
        $invoice = $this->saleInvoice($this->supplierId, ['without' => 1210.0, 'with' => 1210.0, 'status' => 'paid']);
        $notices = $this->noticeStatement();
        $notice = $this->noticeTx($notices, 1210.0, self::YEAR . '-06-14');
        $statement = $this->statement($this->supplierId, $this->accountA);
        $this->bankTx($statement, 500.0, ['posted_at' => self::YEAR . '-06-01']);
        $official = $this->bankTx($statement, 1210.0, ['match_status' => 'auto_exact', 'posted_at' => self::YEAR . '-06-15']);
        $this->bankTx($statement, 100.0, ['posted_at' => self::YEAR . '-06-30']);
        $this->invoicePayment($this->supplierId, $invoice, 1210.0, 'bank', $official);

        $result = $this->fullYear($this->supplierId, false);

        self::assertSame(0, $this->countRows($result, 'bank', $notice), 'Avízo pokryté výpisem v deníku není.');
        self::assertSame(1, $this->countRows($result, 'bank', $official));
        self::assertEqualsWithDelta(1210.0, $result['totals']['prijem_danovy'], 0.001);
        self::assertEqualsWithDelta(500.0 + 1210.0 + 100.0, $result['closing_balance'], 0.001);
    }

    public function testSupersededNoticeIsNotCounted(): void
    {
        $notices = $this->noticeStatement();
        $notice = $this->noticeTx($notices, 1000.0, self::YEAR . '-06-15', 'ignored');

        $result = $this->fullYear($this->supplierId, false);

        self::assertSame(0, $this->countRows($result, 'bank', $notice), 'Ignorované avízo není peněžní pohyb.');
    }

    public function testNoticeOutsideStatementCoverageStays(): void
    {
        $notices = $this->noticeStatement();
        $beforeStatements = $this->noticeTx($notices, 300.0, self::YEAR . '-03-10');
        $afterStatements = $this->noticeTx($notices, 700.0, self::YEAR . '-08-20');
        $statement = $this->statement($this->supplierId, $this->accountA);
        $this->bankTx($statement, 1000.0, ['posted_at' => self::YEAR . '-06-01']);
        $this->bankTx($statement, 1000.0, ['posted_at' => self::YEAR . '-06-30']);

        $result = $this->fullYear($this->supplierId, false);

        self::assertSame(1, $this->countRows($result, 'bank', $beforeStatements), 'Avízo mimo pokryté období je jediný doklad pohybu.');
        self::assertSame(1, $this->countRows($result, 'bank', $afterStatements));
    }

    public function testWeekendGapBetweenDailyStatementsIsCovered(): void
    {
        $notices = $this->noticeStatement();
        $saturday = $this->noticeTx($notices, -250.0, self::YEAR . '-06-13');
        $friday = $this->statement($this->supplierId, $this->accountA);
        $this->bankTx($friday, 1000.0, ['posted_at' => self::YEAR . '-06-12']);
        $monday = $this->statement($this->supplierId, $this->accountA);
        $this->bankTx($monday, -250.0, ['posted_at' => self::YEAR . '-06-15']);

        $result = $this->fullYear($this->supplierId, false);

        self::assertSame(0, $this->countRows($result, 'bank', $saturday), 'Víkendová karta je v pondělním výpisu.');
    }

    private function noticeStatement(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO bank_statements (supplier_id, source, file_name, file_hash, account_number, bank_code, currency, statement_date, imported_by)
             VALUES (?, 'email_notice', 'avizo.eml', ?, ?, '2010', 'CZK', ?, ?)"
        )->execute([$this->supplierId, hash('sha256', uniqid('notice', true)), $this->accountA, self::YEAR . '-06-01', $this->userId]);
        return (int) $pdo->lastInsertId();
    }

    private function noticeTx(int $statementId, float $amount, string $date, string $status = 'unmatched'): int
    {
        $tx = $this->bankTx($statementId, $amount, ['posted_at' => $date, 'match_status' => $status]);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET source = 'email_notice' WHERE id = ?")->execute([$tx]);
        return $tx;
    }

    /** @return array<string,mixed> */
    private function bankRow(array $result, int $tx): array
    {
        foreach ($result['rows'] as $row) {
            if ($row['source_type'] === 'bank' && $row['source_id'] === $tx) {
                return $row;
            }
        }
        self::fail('Bankovní pohyb v deníku chybí.');
    }

    /** @return array<string,mixed> */
    private function warning(array $result, string $type, int $tx): array
    {
        foreach ($result['warnings'] as $warning) {
            if (($warning['type'] ?? '') === $type && (int) $warning['source_id'] === $tx) {
                return $warning;
            }
        }
        self::fail("Chybí varování {$type}.");
    }

    /** @return list<array<string,mixed>> */
    private function allocationWarnings(array $result): array
    {
        return array_values(array_filter(
            $result['warnings'],
            static fn (array $w): bool => str_starts_with((string) ($w['type'] ?? ''), 'bank_allocation_'),
        ));
    }
}
