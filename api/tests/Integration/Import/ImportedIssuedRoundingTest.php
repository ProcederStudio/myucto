<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Service\Import\InvoiceImportService;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Zaokrouhlení převzatého vydaného dokladu z ISDOC (#289). Exportér „Fakturovač" nese
 * zaokrouhlení v `PayableRoundingAmount` a `PayableAmount` vyplňuje nulou. Import
 * zaokrouhlení zahodil (k úhradě zůstalo 430,10 místo 430,00) a nulovou částku k úhradě
 * vzal jako odpočet zálohy, takže doklad skončil konceptem. Syntetická data.
 */
#[Group('integration')]
final class ImportedIssuedRoundingTest extends StockTestCase
{
    private const SUPPLIER_IC = '12345679';

    private int $sid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sid = $this->createSupplier();
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET ic = ?, dic = ? WHERE id = ?')->execute([self::SUPPLIER_IC, 'CZ' . self::SUPPLIER_IC, $this->sid]);
        $pdo->prepare(
            "INSERT IGNORE INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer, is_identified)
             VALUES (?, '1900-01-01', 1, 0)"
        )->execute([$this->sid]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            foreach ($this->supplierIds as $supplierId) {
                $this->db->pdo()->prepare('DELETE FROM invoice_payments WHERE invoice_id IN (SELECT id FROM invoices WHERE supplier_id = ?)')->execute([$supplierId]);
                $this->db->pdo()->prepare('DELETE FROM invoice_counters WHERE supplier_id = ?')->execute([$supplierId]);
            }
        }
        parent::tearDown();
    }

    /**
     * FAIL-BEFORE: rounding 0, amount_to_pay 430,10 a stav koncept (PayableAmount 0
     * bralo pravidlo jako odpočet zálohy).
     */
    public function testFakturovacRoundingWithZeroPayableIsIssuedAndPayable(): void
    {
        $row = $this->importOne(self::isdoc(
            docType: 1,
            id: '202600001',
            vatApplicable: false,
            qty: '187.0000',
            unitPrice: '2.3',
            lineAmount: '430.1',
            monetary: '<TaxExclusiveAmount>430</TaxExclusiveAmount><TaxInclusiveAmount>430.0000</TaxInclusiveAmount>'
                . '<PayableRoundingAmount>-0.1000</PayableRoundingAmount><PaidDepositsAmount>0</PaidDepositsAmount>'
                . '<PayableAmount>0</PayableAmount>',
        ), '202600001');

        self::assertNotSame('draft', $row['status'], 'Nulová částka k úhradě bez zálohy není odpočet zálohy.');
        self::assertSame('-0.10', self::m($row['rounding']));
        self::assertSame('430.00', self::m($row['amount_to_pay']));
        self::assertSame('430.00', self::m($row['total_with_vat']));
        self::assertSame('430.10', self::m($row['total_without_vat']), 'Zaokrouhlení nesmí vstoupit do základu.');
        self::assertSame('whole_czk', $row['rounding_mode'], 'Pozdější přepočet musí zaokrouhlení zachovat.');
        self::assertSame('430.10', self::m($this->itemsTotal((int) $row['id'])), 'Řádky (výkazy) zůstávají beze změny.');

        $this->container->get(InvoicePaymentService::class)->recordPayment((int) $row['id'], 430.0, date('Y-m-d'));
        $after = $this->invoice('202600001');
        self::assertSame('paid', $after['status'], 'Platba 430 musí doklad uhradit beze zbytku.');
        self::assertSame('0.00', self::m((float) $after['amount_to_pay'] - (float) $after['paid_total']));
    }

    /**
     * Dobropis vedený v souboru KLADNÝMI částkami: MyÚčto ho ukládá zápornými řádky, takže
     * zaokrouhlení -0,10 ze souboru (snížení částky) je u nás +0,10.
     * FAIL-BEFORE: rounding 0, amount_to_pay -300,10.
     */
    public function testCreditNoteRoundingFollowsOurSign(): void
    {
        $row = $this->importOne(self::isdoc(
            docType: 2,
            id: '202690001',
            vatApplicable: false,
            qty: '1',
            unitPrice: '300.10',
            lineAmount: '300.10',
            monetary: '<TaxExclusiveAmount>300.10</TaxExclusiveAmount><TaxInclusiveAmount>300.10</TaxInclusiveAmount>'
                . '<PayableRoundingAmount>-0.10</PayableRoundingAmount><PayableAmount>300.00</PayableAmount>',
        ), '202690001');

        self::assertSame('credit_note', $row['invoice_type']);
        self::assertSame('0.10', self::m($row['rounding']));
        self::assertSame('-300.00', self::m($row['amount_to_pay']));
        self::assertSame('-300.10', self::m($this->itemsTotal((int) $row['id'])));
    }

    /**
     * Bez `PayableRoundingAmount`: zaokrouhlení se dopočítá z nenulové částky k úhradě.
     * FAIL-BEFORE: rounding 0, amount_to_pay 1 008,60.
     */
    public function testRoundingDerivedFromPayableWhenElementMissing(): void
    {
        $row = $this->importOne(self::isdoc(
            docType: 1,
            id: '202600002',
            vatApplicable: false,
            qty: '1',
            unitPrice: '1008.60',
            lineAmount: '1008.60',
            monetary: '<TaxExclusiveAmount>1008.60</TaxExclusiveAmount><TaxInclusiveAmount>1008.60</TaxInclusiveAmount>'
                . '<PayableAmount>1009.00</PayableAmount>',
        ), '202600002');

        self::assertNotSame('draft', $row['status']);
        self::assertSame('0.40', self::m($row['rounding']));
        self::assertSame('1009.00', self::m($row['amount_to_pay']));
    }

    /** Skutečný nesoulad nad toleranci zůstává konceptem i s nulovým zaokrouhlením. */
    public function testRealMismatchStillForcesDraft(): void
    {
        $row = $this->importOne(self::isdoc(
            docType: 1,
            id: '202600003',
            vatApplicable: false,
            qty: '1',
            unitPrice: '1000.00',
            lineAmount: '1000.00',
            monetary: '<TaxExclusiveAmount>1000.00</TaxExclusiveAmount><TaxInclusiveAmount>1000.00</TaxInclusiveAmount>'
                . '<PayableRoundingAmount>0</PayableRoundingAmount><PayableAmount>900.00</PayableAmount>',
        ), '202600003');

        self::assertSame('draft', $row['status']);
        self::assertSame('0.00', self::m($row['rounding']));
        self::assertSame('1000.00', self::m($row['amount_to_pay']));
    }

    /** @return array<string,mixed> */
    private function importOne(string $xml, string $vs): array
    {
        $out = $this->container->get(InvoiceImportService::class)->importBundle(
            [['name' => $vs . '.isdoc', 'content' => $xml]],
            $this->sid,
            $this->userId,
            'issued',
        );
        self::assertSame(1, $out['summary']['created'], implode(' | ', array_column($out['results'], 'reason')));

        return $this->invoice($vs);
    }

    /** @return array<string,mixed> */
    private function invoice(string $vs): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
        $stmt->execute([$this->sid, $vs]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Doklad $vs nevznikl.");

        return $row;
    }

    private function itemsTotal(int $invoiceId): float
    {
        $stmt = $this->db->pdo()->prepare('SELECT SUM(total_with_vat) FROM invoice_items WHERE invoice_id = ?');
        $stmt->execute([$invoiceId]);

        return (float) $stmt->fetchColumn();
    }

    private static function m(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private static function isdoc(int $docType, string $id, bool $vatApplicable, string $qty, string $unitPrice, string $lineAmount, string $monetary): string
    {
        $issue = date('Y-m-d');
        $due = date('Y-m-d', strtotime('+14 days'));
        $vat = $vatApplicable ? 'true' : 'false';

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="http://isdoc.cz/namespace/2013" version="6.0.1">
  <DocumentType>{$docType}</DocumentType>
  <ID>{$id}</ID>
  <IssuingSystem>Fakturovač</IssuingSystem>
  <IssueDate>{$issue}</IssueDate>
  <VATApplicable>{$vat}</VATApplicable>
  <LocalCurrencyCode>CZK</LocalCurrencyCode>
  <CurrRate>1.0</CurrRate>
  <RefCurrRate>1.0</RefCurrRate>
  <AccountingSupplierParty><Party>
    <PartyIdentification><ID>12345679</ID></PartyIdentification>
    <PartyName><Name>Testovací dodavatel s.r.o.</Name></PartyName>
    <PostalAddress><StreetName>Testovací</StreetName><BuildingNumber>1</BuildingNumber><CityName>Praha</CityName><PostalZone>11000</PostalZone><Country><IdentificationCode>CZ</IdentificationCode></Country></PostalAddress>
  </Party></AccountingSupplierParty>
  <AccountingCustomerParty><Party>
    <PartyName><Name>Jana Testovací</Name></PartyName>
    <PostalAddress><StreetName>Testovací</StreetName><BuildingNumber>2</BuildingNumber><CityName>Praha</CityName><PostalZone>11000</PostalZone><Country><IdentificationCode>CZ</IdentificationCode></Country></PostalAddress>
  </Party></AccountingCustomerParty>
  <InvoiceLines><InvoiceLine>
    <ID>1</ID>
    <InvoicedQuantity unitCode="ks">{$qty}</InvoicedQuantity>
    <LineExtensionAmount>{$lineAmount}</LineExtensionAmount>
    <LineExtensionAmountTaxInclusive>{$lineAmount}</LineExtensionAmountTaxInclusive>
    <LineExtensionTaxAmount>0</LineExtensionTaxAmount>
    <UnitPrice>{$unitPrice}</UnitPrice>
    <UnitPriceTaxInclusive>{$unitPrice}</UnitPriceTaxInclusive>
    <ClassifiedTaxCategory><Percent>0</Percent><VATCalculationMethod>0</VATCalculationMethod><VATApplicable>false</VATApplicable></ClassifiedTaxCategory>
    <Item><Description>Služby</Description></Item>
  </InvoiceLine></InvoiceLines>
  <TaxTotal><TaxSubTotal><TaxableAmount>{$lineAmount}</TaxableAmount><TaxAmount>0</TaxAmount><TaxInclusiveAmount>{$lineAmount}</TaxInclusiveAmount><TaxCategory><Percent>0</Percent></TaxCategory></TaxSubTotal><TaxAmount>0</TaxAmount></TaxTotal>
  <LegalMonetaryTotal>{$monetary}</LegalMonetaryTotal>
  <PaymentMeans><Payment><PaidAmount>0</PaidAmount><PaymentMeansCode>42</PaymentMeansCode><Details><PaymentDueDate>{$due}</PaymentDueDate></Details></Payment></PaymentMeans>
</Invoice>
XML;
    }
}
