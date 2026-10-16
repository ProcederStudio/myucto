<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Export\IsdocExporter;
use MyInvoice\Service\Invoice\InvoiceIsdocDeliverySettings;
use MyInvoice\Service\Invoice\InvoiceIsdocService;
use MyInvoice\Service\Signing\Pdf\PdfSigningService;

trait StandaloneIsdocTestTrait
{
    private \PDO $pdo;
    private Connection $db;
    private InvoiceIsdocDeliverySettings $delivery;

    private function setupIsdocDatabase(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY, country_id INTEGER, public_isdoc_enabled INTEGER, email_attach_isdoc INTEGER)');
        $this->pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, supplier_id INTEGER, country_id INTEGER, email_attach_isdoc INTEGER NULL)');
        $this->pdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY, iso2 TEXT, name_cs TEXT, name_en TEXT)');
        $this->pdo->exec("INSERT INTO countries VALUES (1, 'CZ', 'Česko', 'Czechia')");
        $this->pdo->exec('INSERT INTO supplier VALUES (41, 1, 0, 0), (42, 1, 0, 0)');
        $this->db = $this->createStub(Connection::class);
        $this->db->method('pdo')->willReturn($this->pdo);
        $this->delivery = new InvoiceIsdocDeliverySettings($this->db);
    }

    private function isdocService(?InvoiceRepository $repo = null, ?ExchangeRateApplier $rates = null, ?PdfSigningService $signing = null): InvoiceIsdocService
    {
        $repo ??= $this->createStub(InvoiceRepository::class);
        if ($signing === null) {
            $signing = $this->createStub(PdfSigningService::class);
            $signing->method('signIsdocIfEnabled')->willReturnCallback(static fn (string $xml): string => $xml);
        }
        return new InvoiceIsdocService($repo, new IsdocExporter($repo, $this->db), $rates ?? $this->createStub(ExchangeRateApplier::class), $signing);
    }

    private function isdocInvoice(array $overrides = []): array
    {
        return array_replace([
            'id' => 91, 'supplier_id' => 41, 'status' => 'issued', 'invoice_type' => 'invoice',
            'varsymbol' => 'TEST-2026-91', 'currency' => 'CZK',
            'issue_date' => '2026-10-01', 'tax_date' => '2026-10-01', 'due_date' => '2026-10-15',
            'supplier_snapshot' => ['company_name' => 'Syntetický dodavatel', 'is_vat_payer' => true, 'country_iso2' => 'CZ'],
            'client_snapshot' => ['company_name' => 'Syntetický odběratel', 'country_iso2' => 'CZ'],
            'bank_snapshot' => ['account_number' => '1000000005', 'bank_code' => '0100'],
            'items' => [[
                'description' => 'Testovací služba', 'quantity' => 1, 'unit' => 'ks',
                'unit_price_without_vat' => 100, 'vat_rate_snapshot' => 21,
                'total_without_vat' => 100, 'total_vat' => 21, 'total_with_vat' => 121,
            ]],
            'totals' => ['without_vat' => 100, 'with_vat' => 121, 'rounding' => 0],
            'vat_breakdown' => [['rate' => 21, 'base' => 100, 'vat' => 21]],
            'amount_to_pay' => 121, 'advance_paid_amount' => 0,
        ], $overrides);
    }

    private function assertIsdocSchema(string $xml): void
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml));
        self::assertTrue($dom->schemaValidate(__DIR__ . '/../../xsd/isdoc-invoice-6.0.2.xsd'));
    }
}
