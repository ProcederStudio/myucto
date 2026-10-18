<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Export\IsdocExporter;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Action\Invoice\BulkReissueAction;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Action\Invoice\CreateInvoiceAction;
use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Invoice\RecurringInvoiceGenerator;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

#[Group('integration')]
final class InvoiceRoundingTest extends StockTestCase
{
    private function create(float $gross = 916.44, array $over = []): int
    {
        $sid = $this->createSupplier(stockEnabled: false);
        $repo = $this->container->get(InvoiceRepository::class);
        $id = $repo->createDraft($over + [
            'client_id' => $this->client($sid),
            'currency_id' => $this->currencyIdFor($sid),
            'issue_date' => '2099-06-10', 'tax_date' => '2099-06-10', 'due_date' => '2099-06-24',
            'payment_method' => 'cash', 'prices_include_vat' => true,
        ], $this->userId);
        $repo->replaceItems($id, [[
            'description' => 'Syntetická položka', 'quantity' => 1,
            'unit_price_without_vat' => $gross, 'vat_rate_id' => $this->vatRateId,
            'vat_classification_code' => '1',
        ]]);
        return $id;
    }

    private function recompute(int $id): array
    {
        $this->container->get(InvoiceCalculator::class)->recompute($id);
        return $this->container->get(InvoiceRepository::class)->find($id);
    }

    public function testCashRoundsDownWithoutChangingItemsOrVatAndRecomputeIsIdempotent(): void
    {
        $id = $this->create();
        $inv = $this->recompute($id);
        self::assertSame('auto', $inv['rounding_mode']);
        self::assertEqualsWithDelta(916.0, $inv['amount_to_pay'], 0.001);
        self::assertEqualsWithDelta(-0.44, $inv['rounding'], 0.001);
        self::assertEqualsWithDelta(916.44, $inv['items'][0]['total_with_vat'], 0.001);
        self::assertEqualsWithDelta(916.44, $inv['total_without_vat'] + $inv['total_vat'], 0.001);
        self::assertEqualsWithDelta($inv['total_vat'], $inv['vat_breakdown'][0]['vat'], 0.001);
        self::assertEqualsWithDelta(916.0, $this->recompute($id)['amount_to_pay'], 0.001);
    }

    public function testCashRoundsUpAndIsdocTotalsBalance(): void
    {
        $inv = $this->recompute($this->create(9.70));
        self::assertEqualsWithDelta(10.0, $inv['amount_to_pay'], 0.001);
        self::assertEqualsWithDelta(0.30, $inv['rounding'], 0.001);
        $xml = $this->container->get(IsdocExporter::class)->buildXml($inv);
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml));
        self::assertTrue($dom->schemaValidate(dirname(__DIR__, 3) . '/xsd/isdoc-invoice-6.0.2.xsd'));
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('i', 'http://isdoc.cz/namespace/2013');
        self::assertEqualsWithDelta(9.70, (float) $xp->evaluate('string(//i:LegalMonetaryTotal/i:TaxInclusiveAmount)'), 0.001);
        self::assertEqualsWithDelta(10.0, (float) $xp->evaluate('string(//i:LegalMonetaryTotal/i:PayableAmount)'), 0.001);
    }

    public function testModeCanBeChangedAndOmittedUpdatePreservesIt(): void
    {
        $id = $this->create();
        $repo = $this->container->get(InvoiceRepository::class);
        $inv = $this->recompute($id);
        $repo->updateDraft($id, $inv + ['note_above_items' => 'Syntetická poznámka']);
        self::assertSame('auto', $this->recompute($id)['rounding_mode']);
        $inv['rounding_mode'] = 'none';
        $repo->updateDraft($id, $inv);
        self::assertEqualsWithDelta(916.44, $this->recompute($id)['amount_to_pay'], 0.001);
    }

    public function testAdvanceIsDeductedBeforeRounding(): void
    {
        $inv = $this->recompute($this->create(916.44, ['advance_paid_amount' => 100.70]));
        self::assertEqualsWithDelta(816.0, $inv['amount_to_pay'], 0.001);
        self::assertEqualsWithDelta(0.26, $inv['rounding'], 0.001);
    }

    public function testLinkingAdvanceRecomputesPayableRounding(): void
    {
        $id = $this->create();
        $inv = $this->recompute($id);
        $repo = $this->container->get(InvoiceRepository::class);
        $advanceId = $repo->createDraft(array_replace($inv, [
            'invoice_type' => 'proforma', 'varsymbol' => null,
        ]), $this->userId);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'paid', total_with_vat = 100.70, paid_total = 100.70 WHERE id = ?")
            ->execute([$advanceId]);
        $repo->linkAdvance($id, $advanceId, (int) $inv['supplier_id']);
        $linked = $repo->find($id);
        self::assertEqualsWithDelta(100.70, $linked['advance_paid_amount'], 0.001);
        self::assertEqualsWithDelta(816.0, $linked['amount_to_pay'], 0.001);
        self::assertEqualsWithDelta(0.26, $linked['rounding'], 0.001);
    }

    public function testRoundedCashPaymentFullySettlesInvoice(): void
    {
        $id = $this->create();
        $this->recompute($id);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$id]);
        $payment = $this->container->get(InvoicePaymentService::class)->recordPayment(
            $id, 916.0, '2099-06-10', ['source' => 'manual', 'created_by' => $this->userId],
        );
        self::assertTrue($payment['became_paid']);
        self::assertEqualsWithDelta(0, $payment['remaining'], 0.001);
        $inv = $this->container->get(InvoiceRepository::class)->find($id);
        self::assertSame('paid', $inv['status']);
        self::assertSame('paid', $inv['payment_status']);
        self::assertEqualsWithDelta(916.0, $inv['paid_total'], 0.001);
    }

    public function testClonePreservesRoundingMode(): void
    {
        $id = $this->create(9.7, ['payment_method' => 'cash_on_delivery', 'rounding_mode' => 'whole_czk']);
        $this->recompute($id);
        $cloneId = $this->container->get(BulkReissueAction::class)->cloneOne($id, '2099-07-10', false, $this->userId);
        $clone = $this->container->get(InvoiceRepository::class)->find($cloneId);
        self::assertSame('whole_czk', $clone['rounding_mode']);
        self::assertEqualsWithDelta(10.0, $clone['amount_to_pay'], 0.001);
    }

    public function testVatLedgerPostingAndPdfUseSeparateRounding(): void
    {
        foreach ([916.44, 9.70] as $gross) {
            $id = $this->create($gross);
            $inv = $this->recompute($id);
            $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$id]);
            $rows = $this->container->get(VatLedgerService::class)->rows((int) $inv['supplier_id'], '2099-06-01', '2099-06-30');
            $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['source'] === 'sale' && $r['invoice_id'] === $id));
            self::assertCount(1, $rows);
            self::assertEqualsWithDelta($gross, $rows[0]['base_czk'] + $rows[0]['vat_czk'], 0.001);
            self::assertEqualsWithDelta($inv['total_vat'], $rows[0]['vat_czk'], 0.001);
            $lines = $this->container->get(PostingService::class)->buildFromInvoice((int) $inv['supplier_id'], $id);
            $receivable = array_values(array_filter($lines, static fn (array $l): bool => $l['account_code'] === '311'));
            self::assertCount(1, $receivable);
            self::assertEqualsWithDelta($inv['amount_to_pay'], $receivable[0]['amount'], 0.001);
            $rounding = array_values(array_filter($lines, static fn (array $l): bool => in_array($l['account_code'], ['548', '648'], true)));
            self::assertCount(1, $rounding);
            self::assertSame($gross === 916.44 ? '548' : '648', $rounding[0]['account_code']);
            self::assertEqualsWithDelta(abs($inv['rounding']), $rounding[0]['amount'], 0.001);
            $html = $this->container->get(InvoicePdfRenderer::class)->renderHtml($inv);
            self::assertStringContainsString('Zaokrouhlení', $html);
            self::assertStringContainsString($gross === 916.44 ? '-0,44' : '0,30', $html);
        }
    }

    public function testCodIsOptInAndProformaAndCardAreNeverRounded(): void
    {
        foreach ([
            ['cash_on_delivery', 'auto', 'invoice', 916.44],
            ['cash_on_delivery', 'whole_czk', 'invoice', 916.0],
            ['bank_transfer', 'auto', 'invoice', 916.44],
            ['bank_transfer', 'whole_czk', 'invoice', 916.0],
            ['card', 'whole_czk', 'invoice', 916.44],
            ['cash', 'whole_czk', 'proforma', 916.44],
            ['cash', 'auto', 'credit_note', -916.0],
        ] as [$method, $mode, $type, $expected]) {
            $inv = $this->recompute($this->create($type === 'credit_note' ? -916.44 : 916.44, [
                'payment_method' => $method, 'rounding_mode' => $mode, 'invoice_type' => $type,
            ]));
            self::assertEqualsWithDelta($expected, $inv['amount_to_pay'], 0.001, "$method/$mode/$type");
        }
    }

    public function testNewInvoiceWithoutModeTakesCompanyDefault(): void
    {
        $sid = $this->createSupplier(stockEnabled: false);
        $this->db->pdo()->prepare("UPDATE supplier SET default_rounding_mode = 'whole_czk' WHERE id = ?")->execute([$sid]);
        $body = [
            'client_id' => $this->client($sid), 'currency_id' => $this->currencyIdFor($sid),
            'issue_date' => '2099-06-10', 'tax_date' => '2099-06-10', 'due_date' => '2099-06-24',
            'payment_method' => 'bank_transfer', 'prices_include_vat' => true,
            'items' => [[
                'description' => 'Syntetická položka', 'quantity' => 1,
                'unit_price_without_vat' => 916.44, 'vat_rate_id' => $this->vatRateId,
            ]],
        ];
        $create = $this->container->get(CreateInvoiceAction::class);

        $res = $create($this->request('POST', $sid, $body), new Psr7Response());
        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        $inv = $this->recompute((int) $this->json($res)['id']);
        self::assertSame('whole_czk', $inv['rounding_mode']);
        self::assertEqualsWithDelta(916.0, $inv['amount_to_pay'], 0.001);

        $res = $create($this->request('POST', $sid, $body + ['rounding_mode' => 'none']), new Psr7Response());
        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('none', $this->recompute((int) $this->json($res)['id'])['rounding_mode'], 'Výslovný režim má přednost.');
    }

    public function testCompanyWithoutChoiceKeepsAutoForNewInvoices(): void
    {
        $sid = $this->createSupplier(stockEnabled: false);
        $res = $this->container->get(CreateInvoiceAction::class)($this->request('POST', $sid, [
            'client_id' => $this->client($sid), 'currency_id' => $this->currencyIdFor($sid),
            'issue_date' => '2099-06-10', 'tax_date' => '2099-06-10', 'due_date' => '2099-06-24',
        ]), new Psr7Response());
        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('auto', $this->recompute((int) $this->json($res)['id'])['rounding_mode']);
    }

    public function testRecurringInvoiceTakesCompanyDefault(): void
    {
        $sid = $this->createSupplier(stockEnabled: false);
        $this->db->pdo()->prepare("UPDATE supplier SET default_rounding_mode = 'whole_czk' WHERE id = ?")->execute([$sid]);
        $templates = $this->container->get(RecurringTemplateRepository::class);
        $tplId = $templates->create([
            'supplier_id' => $sid, 'client_id' => $this->client($sid), 'name' => 'Syntetická šablona',
            'frequency' => 'monthly', 'end_of_month' => false, 'anchor_date' => '2099-06-10',
            'next_run_date' => '2099-06-10', 'invoice_type' => 'invoice', 'currency_id' => $this->currencyIdFor($sid),
            'language' => 'cs', 'payment_method' => 'bank_transfer', 'payment_due_days' => 14,
            'auto_issue' => false, 'auto_send_email' => false, 'status' => 'active',
        ], $this->userId);
        $templates->replaceItems($tplId, [[
            'description' => 'Syntetický paušál', 'quantity' => 1.0, 'unit' => 'ks',
            'unit_price_without_vat' => 9.70, 'vat_rate_id' => $this->vatRateId, 'order_index' => 0,
        ]]);

        try {
            $res = $this->container->get(RecurringInvoiceGenerator::class)->generate($tplId, null, $this->userId, '127.0.0.1', 'phpunit');

            self::assertSame('whole_czk', $this->container->get(InvoiceRepository::class)->find((int) $res['invoice_id'])['rounding_mode']);
        } finally {
            // Šablona drží klienta cizím klíčem, úklid StockTestCase by ho jinak nesmazal.
            $this->db->pdo()->prepare('DELETE FROM recurring_invoice_templates WHERE id = ?')->execute([$tplId]);
        }
    }

    public function testCompanyDefaultIsSavedValidatedAndExposed(): void
    {
        $sid = $this->createSupplier(stockEnabled: false);
        $settings = $this->container->get(SettingsAction::class);

        $res = $settings->getSupplier($this->request('GET', $sid), new Psr7Response());
        self::assertSame('auto', $this->json($res)['default_rounding_mode']);

        $res = $settings->updateSupplier($this->request('PUT', $sid, ['default_rounding_mode' => 'whole_czk']), new Psr7Response());
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('whole_czk', $this->json($res)['default_rounding_mode']);

        foreach (['ceil', 'whole_czk_all', null, 1] as $invalid) {
            $res = $settings->updateSupplier($this->request('PUT', $sid, ['default_rounding_mode' => $invalid]), new Psr7Response());
            self::assertSame(400, $res->getStatusCode(), var_export($invalid, true));
        }
        $stored = $this->db->pdo()->prepare('SELECT default_rounding_mode FROM supplier WHERE id = ?');
        $stored->execute([$sid]);
        self::assertSame('whole_czk', $stored->fetchColumn());
    }

    private function request(string $method, int $supplierId, ?array $body = null): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        return $body !== null ? $request->withParsedBody($body) : $request;
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
