<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Service\Bank\EmailNoticeReconciler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class CashJournalSupersededNoticeTest extends CashJournalTestCase
{
    public static function secondarySources(): iterable
    {
        yield 'email notice' => ['email_notice'];
        yield 'imported iDoklad movement' => ['idoklad'];
    }

    #[DataProvider('secondarySources')]
    public function testTransferredYearEndNoticeDoesNotRemainAnExtraCashMovement(string $source): void
    {
        [$noticeStatement, $officialStatement] = $this->statements($source);
        $invoiceId = $this->saleInvoice($this->supplierId, ['with' => 12100, 'without' => 10000]);
        $notice = $this->bankTx($noticeStatement, 12100, [
            'posted_at' => '2099-12-31',
            'variable_symbol' => '157123101',
            'matched_invoice_id' => $invoiceId,
            'match_status' => 'manual',
        ]);
        $this->db->pdo()->prepare('UPDATE bank_transactions SET source = ? WHERE id = ?')->execute([$source, $notice]);
        $official = $this->bankTx($officialStatement, 12100, ['posted_at' => '2100-01-02', 'variable_symbol' => '157123101']);
        $paymentId = $this->invoicePayment($this->supplierId, $invoiceId, 12100, 'bank', $notice, '2099-12-31');
        self::assertCount(1, $this->repo->movements($this->supplierId, '2099-12-31', '2099-12-31'));

        $result = $this->container->get(EmailNoticeReconciler::class)->takeOverFromEmailNotice($official);
        self::assertNotNull($result, 'Převzetí musí zachovat stávající toleranci přes hranici roku.');
        self::assertSame($notice, $result['email_tx_id']);
        self::assertSame((string) $official, (string) $this->db->pdo()->query("SELECT bank_transaction_id FROM invoice_payments WHERE id = {$paymentId}")->fetchColumn());
        self::assertSame('2099-12-31', $this->db->pdo()->query("SELECT paid_on FROM invoice_payments WHERE id = {$paymentId}")->fetchColumn());

        self::assertSame([], $this->repo->movements($this->supplierId, '2099-12-31', '2099-12-31'), 'Převzaté avízo nesmí přidat 12 100 Kč do předchozího roku.');
        self::assertEqualsWithDelta(0, $this->repo->openingBalance($this->supplierId, '2100-01-01'), 0.01);
        $january = $this->repo->movements($this->supplierId, '2100-01-01', '2100-01-31');
        self::assertCount(1, $january);
        self::assertSame($official, $january[0]['source_id']);
        self::assertEqualsWithDelta(12100, $january[0]['amount'], 0.01);

        // Smazání výpisu nesmí oživit starou zprávu a zpětně změnit minulý rok.
        $this->db->pdo()->prepare('DELETE FROM bank_transactions WHERE id = ?')->execute([$official]);
        self::assertSame([], $this->repo->movements($this->supplierId, '2099-12-31', '2099-12-31'));
    }

    public function testRematchedNoticeAfterStatementDeletionIsCashMovementAgain(): void
    {
        [$noticeStatement, $officialStatement] = $this->statements('email_notice');
        $invoiceId = $this->saleInvoice($this->supplierId, ['with' => 12100, 'without' => 10000]);
        $notice = $this->bankTx($noticeStatement, 12100, [
            'posted_at' => '2099-12-31',
            'variable_symbol' => '157123102',
            'matched_invoice_id' => $invoiceId,
            'match_status' => 'manual',
        ]);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET source = 'email_notice' WHERE id = ?")->execute([$notice]);
        $official = $this->bankTx($officialStatement, 12100, ['posted_at' => '2100-01-02', 'variable_symbol' => '157123102']);
        $paymentId = $this->invoicePayment($this->supplierId, $invoiceId, 12100, 'bank', $notice, '2099-12-31');
        self::assertNotNull($this->container->get(EmailNoticeReconciler::class)->takeOverFromEmailNotice($official));

        // Smazání výpisu uvolní úhradu a uživatel pak avízo spáruje znovu ručně.
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM invoice_payments WHERE id = ?')->execute([$paymentId]);
        $pdo->prepare('DELETE FROM bank_transactions WHERE id = ?')->execute([$official]);
        $pdo->prepare("UPDATE bank_transactions SET match_status = 'manual', matched_invoice_id = ? WHERE id = ?")->execute([$invoiceId, $notice]);
        $this->invoicePayment($this->supplierId, $invoiceId, 12100, 'bank', $notice, '2099-12-31');

        $rows = $this->repo->movements($this->supplierId, '2099-12-31', '2099-12-31');
        self::assertCount(1, $rows, 'Uhrazená faktura musí mít příjem v peněžním deníku.');
        self::assertSame($notice, $rows[0]['source_id']);
        self::assertEqualsWithDelta(12100, $rows[0]['amount'], 0.01);
    }

    public function testRetiredNoticeDoesNotRequestExchangeRateButLiveNoticeStillDoes(): void
    {
        [$statement] = $this->statements('email_notice');
        $retired = $this->bankTx($statement, -100, ['posted_at' => '2099-12-29']);
        $live = $this->bankTx($statement, -200, ['posted_at' => '2099-12-30']);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET source = 'email_notice', currency = 'XTS' WHERE id IN (?, ?)")->execute([$retired, $live]);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET ignore_origin = 'superseded' WHERE id = ?")->execute([$retired]);

        self::assertSame([
            ['currency' => 'XTS', 'date' => '2099-12-30', 'forward_date' => null],
        ], $this->repo->unpricedCurrencyDays($this->supplierId, '2099-12-31'));
    }

    public function testOnlySupersededProvisionalMovementsAreExcluded(): void
    {
        [$noticeStatement, $officialStatement] = $this->statements('email_notice');
        $live = $this->bankTx($noticeStatement, -200, ['posted_at' => '2099-12-30']);
        $retired = $this->bankTx($noticeStatement, -100, ['posted_at' => '2099-12-30']);
        $official = $this->bankTx($officialStatement, -300, ['posted_at' => '2100-01-02', 'match_status' => 'ignored']);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET source = 'email_notice' WHERE id IN (?, ?)")->execute([$live, $retired]);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET ignore_origin = 'superseded' WHERE id IN (?, ?)")->execute([$retired, $official]);

        $rows = $this->repo->movements($this->supplierId, '2099-12-01', '2100-01-31');
        self::assertSame([$live, $official], array_column($rows, 'source_id'), 'Živé avízo mimo pokrytí výpisem zůstává; ignorování výpisu nevylučuje skutečný peněžní tok.');
    }

    /** @return array{int,int} */
    private function statements(string $source): array
    {
        $this->accountA = '1000000005';
        $this->db->pdo()->prepare('UPDATE currencies SET account_number = ?, bank_code = ? WHERE id = ?')->execute([$this->accountA, '0100', $this->currencyId]);
        $notice = $this->statement($this->supplierId, $this->accountA, '0100');
        $official = $this->statement($this->supplierId, $this->accountA, '0100');
        $this->db->pdo()->prepare('UPDATE bank_statements SET source = ?, supplier_id = ? WHERE id = ?')->execute([$source, $this->supplierId, $notice]);
        $this->db->pdo()->prepare("UPDATE bank_statements SET source = 'gpc', supplier_id = ? WHERE id = ?")->execute([$this->supplierId, $official]);
        return [$notice, $official];
    }
}
