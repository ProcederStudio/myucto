<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Accounting\PostingException;
use PHPUnit\Framework\Attributes\Group;

/**
 * Guardy handleTransaction: cizí měna, avízo, ignored, ignore zaúčtované (409),
 * ne-double_entry firma (no-op), ambiguita tenanta (M4). §8.
 */
#[Group('integration')]
final class BankPostingServiceGuardsTest extends BankPostingTestCase
{
    public function testForeignCurrencyTxSkipped(): void
    {
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 1000.00, ['currency' => 'EUR']);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('skipped', $res['action']);
        self::assertSame('fx_not_supported', $res['reason']);
        self::assertSame(0, $this->entryCountForTx($tx));
        self::assertSame(0, $this->suggestionCountForTx($tx));
    }

    public function testCzkTxOnForeignInvoiceWithoutRateIsSkipped(): void
    {
        $eur = $this->currencyRow($this->supplierId, 'EUR');
        $client = $this->client('Odběratel s.r.o.');
        // Faktura v EUR (currency_id → EUR), platba přišla v CZK.
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 paid_total, status, vat_classification_code, created_by)
             VALUES (?, "FV-EUR", "invoice", ?, ?, ?, ?, ?, 0, 100, 0, 100, 0, "issued", "1", ?)'
        )->execute([$this->supplierId, $client, self::YEAR . '-06-10', self::YEAR . '-06-10', self::YEAR . '-06-10', $eur, $this->userId]);
        $inv = (int) $this->db->pdo()->lastInsertId();
        $this->postPredpis('invoice', $inv, '311', '602', 100.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 100.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);
        $this->invoicePayment($inv, $tx, 100.00);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('skipped', $res['action']);
        self::assertSame('missing_exchange_rate', $res['reason']);
        self::assertSame(0, $this->entryCountForTx($tx));
    }

    public function testEmailNoticeSkipped(): void
    {
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 1000.00, ['source' => 'email_notice']);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('skipped', $res['action']);
        self::assertSame('email_notice_provisional', $res['reason']);
    }

    public function testIgnoredTxNeverPosts(): void
    {
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 1000.00, ['match_status' => 'ignored']);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('skipped', $res['action']);
        self::assertSame('ignored', $res['reason']);
    }

    public function testIgnorePostedTransactionThrows409(): void
    {
        $client = $this->client('Odběratel s.r.o.');
        $inv = $this->saleInvoice('FV-G1', $client, 1000.00);
        $this->postPredpis('invoice', $inv, '311', '602', 1000.00);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 1000.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);
        $this->invoicePayment($inv, $tx, 1000.00);
        $this->service->handleTransaction($tx, $this->userId);

        try {
            $this->service->onIgnore($this->supplierId, $tx);
            self::fail('Zaúčtovanou tx nelze ignorovat.');
        } catch (PostingException $e) {
            self::assertSame('posted_transaction_cannot_be_ignored', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
    }

    public function testIgnorePostedUnmatchedTransactionKeepsJournalEntry(): void
    {
        // Pohyb zaúčtovaný bez dokladu (např. v uzavřeném období, kde zaúčtování zrušit
        // nejde) smí být označen jako ignorovaný — jen „nečeká na spárování", zápis zůstává.
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -920.00);
        $entryId = $this->postPredpis('bank', $tx, '321', '221', 920.00);

        $this->service->onIgnore($this->supplierId, $tx);

        $live = $this->db->pdo()->prepare('SELECT reversed_by FROM journal_entries WHERE id = ?');
        $live->execute([$entryId]);
        self::assertNull($live->fetchColumn(), 'Zápis v deníku musí zůstat živý.');
    }

    public function testNonDoubleEntrySupplierIsNoOp(): void
    {
        $other = $this->otherSupplierId('non');
        if ($other === 0) {
            self::markTestSkipped('Není k dispozici firma bez podvojného účetnictví.');
        }
        // Účet vlastněný ne-double_entry firmou → hook je no-op.
        $this->currencyRow($other, 'CZK', '990000111', '2250');
        $stmt = $this->statement('990000111', '2250');
        $tx = $this->transaction($stmt, 1000.00, ['match_status' => 'unmatched', 'counterparty_account' => '55501']);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('skipped', $res['action']);
        self::assertSame('not_double_entry', $res['reason']);
        self::assertSame(0, $this->entryCountForTx($tx));
    }

    public function testAmbiguousSupplierSkipped(): void
    {
        $other = $this->otherSupplierId('any');
        if ($other === 0) {
            self::markTestSkipped('Není druhá firma pro test ambiguity.');
        }
        // Druhá firma se stejným číslem účtu → ≥2 kandidáti (M4).
        $this->currencyRow($other, 'CZK', self::ACCOUNT, null);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 1000.00, ['match_status' => 'unmatched', 'counterparty_account' => '55501']);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('skipped', $res['action']);
        self::assertSame('ambiguous_supplier', $res['reason']);
        self::assertSame(0, $this->entryCountForTx($tx));
    }
}
