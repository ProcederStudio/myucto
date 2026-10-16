<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Invoice\BulkReissueAction;
use MyInvoice\Action\Invoice\CancelInvoiceAction;
use MyInvoice\Action\Invoice\CreateInvoiceAction;
use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Řádek faktury nese vybraná sériová čísla nebo šarže sledované karty
 * (`tracking_allocations`). Výběr přežije úpravu konceptu (replaceItems je DELETE +
 * INSERT), dobropis z faktury ho převezme, kopie faktury ne.
 */
#[Group('integration')]
final class InvoiceItemTrackingTest extends StockTestCase
{
    public function testDraftRoundTripKeepsTrackingAcrossEdits(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-1', 'serial');
        $invoiceId = $this->createInvoice($sid, [
            $this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-A']]),
            $this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-B']]),
        ]);

        $items = $this->storedItems($invoiceId);
        self::assertSame(['VIN-A', 'VIN-B'], [$items[0]['tracking_allocations'][0]['serial_number'], $items[1]['tracking_allocations'][0]['serial_number']]);
        self::assertSame('1.000', $items[0]['tracking_allocations'][0]['quantity']);

        $items[0]['unit_price_without_vat'] = 250000;
        $res = $this->updateInvoice($sid, $invoiceId, ['items' => $items]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $after = $this->storedItems($invoiceId);
        self::assertSame(['VIN-A', 'VIN-B'], [$after[0]['tracking_allocations'][0]['serial_number'], $after[1]['tracking_allocations'][0]['serial_number']]);

        $res = $this->updateInvoice($sid, $invoiceId, ['discount_percent' => 5]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('VIN-A', $this->storedItems($invoiceId)[0]['tracking_allocations'][0]['serial_number'], 'Přehrání uložených řádků při změně slevy výběr zachová.');
    }

    public function testLineWithoutTrackingReturnsEmptyList(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $plain = $this->item($sid, 'PLAIN-1');
        $invoiceId = $this->createInvoice($sid, [$this->line($plain, $wh, '2', null)]);
        self::assertSame([], $this->storedItems($invoiceId)[0]['tracking_allocations']);
    }

    public function testTrackingOnUntrackedItemIsRejected(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $plain = $this->item($sid, 'PLAIN-2');
        $res = $this->createInvoiceResponse($sid, [$this->line($plain, $wh, '1', [['quantity' => '1', 'serial_number' => 'X']])]);
        $this->assertError($res, 422, 'tracking_not_enabled');
    }

    public function testSerialQuantityMustBeOne(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-2', 'serial');
        $res = $this->createInvoiceResponse($sid, [$this->line($moto, $wh, '2', [['quantity' => '2', 'serial_number' => 'VIN-C']])]);
        $this->assertError($res, 422, 'invalid_tracking_quantity');
    }

    public function testTrackingWithoutStockItemIsRejected(): void
    {
        $sid = $this->createSupplier();
        $res = $this->createInvoiceResponse($sid, [$this->line(null, null, '1', [['quantity' => '1', 'serial_number' => 'VIN-D']])]);
        $this->assertError($res, 422, 'tracking_not_enabled');
    }

    public function testStockUnitIdIsNotAcceptedFromInvoice(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-3', 'serial');
        $invoiceId = $this->createInvoice($sid, [
            $this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-E', 'stock_tracking_unit_id' => 999999, 'location_id' => 3, 'location_to_id' => 5]]),
        ]);
        $stored = $this->storedItems($invoiceId)[0]['tracking_allocations'][0];
        self::assertArrayNotHasKey('stock_tracking_unit_id', $stored, 'Identita se ukládá textem, ne id jednotky.');
        self::assertArrayNotHasKey('location_to_id', $stored);
        self::assertArrayNotHasKey('location_id', $stored, 'Umístění se dohledá až při výdeji (obnova archivu, přesuny ve skladu).');
    }

    public function testLotWithExpiryRoundTrips(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $oil = $this->trackedItem($sid, 'OIL-1', 'lot');
        $invoiceId = $this->createInvoice($sid, [
            $this->line($oil, $wh, '3', [['quantity' => '2', 'lot_code' => 'L1', 'expires_on' => '2099-12-31'], ['quantity' => '1', 'lot_code' => 'L2']]),
        ]);
        $alloc = $this->storedItems($invoiceId)[0]['tracking_allocations'];
        self::assertSame([['L1', '2099-12-31', '2.000'], ['L2', null, '1.000']], array_map(
            static fn (array $a): array => [$a['lot_code'], $a['expires_on'], $a['quantity']],
            $alloc,
        ));
    }

    public function testCreditNoteFromInvoiceCopiesTracking(): void
    {
        $sid = $this->createSupplier(autoIssue: false);
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-4', 'serial');
        $invoiceId = $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-F']])]);
        $this->markIssued($invoiceId);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody(['mode' => 'credit_note', 'reason' => 'Vrácení motocyklu']);
        $res = $this->container->get(CancelInvoiceAction::class)($request, new Psr7Response(), ['id' => (string) $invoiceId]);
        $body = $this->body($res);
        self::assertSame(201, $res->getStatusCode(), json_encode($body, JSON_UNESCAPED_UNICODE));

        $credit = $this->storedItems((int) $body['credit_note_id']);
        self::assertSame('VIN-F', $credit[0]['tracking_allocations'][0]['serial_number']);
    }

    public function testCloneDoesNotCopyTracking(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-5', 'serial');
        $invoiceId = $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-G']])]);

        $cloneId = $this->container->get(BulkReissueAction::class)->cloneOne($invoiceId, '2099-06-20', false, $this->userId);
        $clone = $this->storedItems($cloneId);
        self::assertSame($moto, $clone[0]['stock_item_id'], 'Kopie převezme kartu.');
        self::assertSame([], $clone[0]['tracking_allocations'], 'Konkrétní kus se znovu neprodává.');
    }

    public function testFinalInvoiceFromProformaKeepsTracking(): void
    {
        $sid = $this->createSupplier(autoIssue: false);
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-6', 'serial');
        $proformaId = $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-H']])], 'proforma');
        $this->markIssued($proformaId);

        $finalId = $this->container->get(\MyInvoice\Service\Invoice\FinalFromProformaCreator::class)->create($proformaId, $this->userId);
        self::assertSame('VIN-H', $this->storedItems($finalId)[0]['tracking_allocations'][0]['serial_number'], 'Sklad hýbe až finál, výběr z proformy přechází s ním.');
    }

    public function testForeignTrackedItemIsRejected(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $foreign = $this->trackedItem($this->createSupplier(), 'CIZI-MOTO', 'serial');
        $res = $this->createInvoiceResponse($sid, [$this->line($foreign, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-X']])]);
        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
    }

    public function testInjectedTrackingJsonIsIgnored(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-20', 'serial');
        $line = $this->line($moto, $wh, '1', null);
        $line['tracking_json'] = '[{"quantity":"1.000","serial_number":"PODVRH"}]';
        $invoiceId = $this->createInvoice($sid, [$line]);
        self::assertSame([], $this->storedItems($invoiceId)[0]['tracking_allocations'], 'tracking_json z těla požadavku obchází validaci.');

        $items = $this->storedItems($invoiceId);
        $items[0]['tracking_json'] = '[{"quantity":"1.000","serial_number":"PODVRH"}]';
        self::assertSame(200, $this->updateInvoice($sid, $invoiceId, ['items' => $items])->getStatusCode());
        self::assertSame([], $this->storedItems($invoiceId)[0]['tracking_allocations']);
    }

    public function testPutWithoutTrackingClearsSelection(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-21', 'serial');
        $invoiceId = $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-K']])]);
        $items = $this->storedItems($invoiceId);
        unset($items[0]['tracking_allocations']);
        self::assertSame(200, $this->updateInvoice($sid, $invoiceId, ['items' => $items])->getStatusCode());
        self::assertSame([], $this->storedItems($invoiceId)[0]['tracking_allocations'], 'Položky se nahrazují celé, výběr se posílá s nimi.');
    }

    public function testDeletedCardHidesStaleSelection(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-22', 'serial');
        $invoiceId = $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-L']])]);
        // fk_ii_stock_item je ON DELETE SET NULL: smazaná karta nechá řádek bez vazby.
        $this->db->pdo()->prepare('UPDATE invoice_items SET stock_item_id = NULL WHERE invoice_id = ?')->execute([$invoiceId]);

        $items = $this->storedItems($invoiceId);
        self::assertSame([], $items[0]['tracking_allocations'], 'Řádek bez karty výběr nenabízí.');
        self::assertSame(200, $this->updateInvoice($sid, $invoiceId, ['items' => $items])->getStatusCode(), 'GET → PUT projde.');
    }

    public function testTrackingModeChangeIsBlockedWhileInvoiceSelectsUnits(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-23', 'serial');
        $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-M']])]);

        $res = $this->container->get(\MyInvoice\Action\Stock\StockItemAction::class)->update(
            $this->request('PUT', $sid, ['tracking_mode' => 'none']), new Psr7Response(), ['id' => (string) $moto],
        );
        $this->assertError($res, 409, 'tracking_mode_in_use');
    }

    public function testIssuedInvoiceWithStockDocumentRejectsSelectionChange(): void
    {
        $sid = $this->createSupplier(autoIssue: false);
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-24', 'serial');
        $this->receiveUnits($sid, $wh, $moto, ['VIN-N', 'VIN-O']);
        $invoiceId = $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-N']])]);
        $this->markIssued($invoiceId);
        $issue = $this->documents->create($sid, [
            'doc_type' => 'issue', 'origin' => 'invoice', 'warehouse_id' => $wh, 'doc_date' => '2099-06-10',
            'description' => 'Výdej k faktuře', 'invoice_id' => $invoiceId,
            'lines' => [['stock_item_id' => $moto, 'qty' => '1.000', 'invoice_item_id' => $this->storedItems($invoiceId)[0]['id'],
                'tracking_allocations' => [['quantity' => '1', 'serial_number' => 'VIN-N']]]],
        ], $this->userId);
        $this->documents->post($sid, (int) $issue['id'], $this->userId);

        $items = $this->storedItems($invoiceId);
        $items[0]['tracking_allocations'] = [['quantity' => '1', 'serial_number' => 'VIN-O']];
        $res = $this->container->get(UpdateInvoiceAction::class)(
            $this->request('PUT', $sid, ['items' => $items])->withQueryParams(['force' => '1']), new Psr7Response(), ['id' => (string) $invoiceId],
        );
        $this->assertError($res, 409, 'tracking_locked');
        self::assertSame('VIN-N', $this->storedItems($invoiceId)[0]['tracking_allocations'][0]['serial_number']);

        $items = $this->storedItems($invoiceId);
        $items[0]['description'] = 'Motocykl, opravený popis';
        $res = $this->container->get(UpdateInvoiceAction::class)(
            $this->request('PUT', $sid, ['items' => $items])->withQueryParams(['force' => '1']), new Psr7Response(), ['id' => (string) $invoiceId],
        );
        self::assertSame(200, $res->getStatusCode(), 'Bez změny výběru úprava projde: ' . $res->getBody());
        self::assertSame('VIN-N', $this->storedItems($invoiceId)[0]['tracking_allocations'][0]['serial_number']);
    }

    // ── PDF faktury ─────────────────────────────────────────────────────────

    public function testPdfListsSelectedSerialsAndLots(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-PDF', 'serial');
        $oil = $this->trackedItem($sid, 'OIL-PDF', 'lot');
        $plain = $this->item($sid, 'PLAIN-PDF');
        $invoiceId = $this->createInvoice($sid, [
            $this->line($moto, $wh, '2', [['quantity' => '1', 'serial_number' => 'VIN-PDF-1'], ['quantity' => '1', 'serial_number' => 'VIN-PDF-2']]),
            $this->line($oil, $wh, '3', [['quantity' => '2', 'lot_code' => 'L-PDF-1', 'expires_on' => '2099-12-31'], ['quantity' => '1', 'lot_code' => 'L-PDF-2']]),
            $this->line($plain, $wh, '1', null),
        ]);

        $html = $this->pdfHtml($invoiceId);
        self::assertStringContainsString('Výr. č.: VIN-PDF-1, VIN-PDF-2', $html);
        self::assertStringContainsString('Šarže: L-PDF-1 (exp. 31. 12. 2099): 2 ks, L-PDF-2: 1 ks', $html);
        self::assertSame(1, substr_count($html, 'Výr. č.:'), 'Řádek bez výběru nic nevypisuje.');
    }

    public function testPdfInEnglishUsesEnglishLabels(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-PDF-EN', 'serial');
        $invoiceId = $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-EN-1']])]);
        $this->db->pdo()->prepare("UPDATE invoices SET language = 'en' WHERE id = ?")->execute([$invoiceId]);

        self::assertStringContainsString('Serial no.: VIN-EN-1', $this->pdfHtml($invoiceId));
    }

    // ── pomocníci ────────────────────────────────────────────────────────────

    /** @param list<string> $serials */
    private function receiveUnits(int $supplierId, int $warehouseId, int $itemId, array $serials): void
    {
        $draft = $this->documents->create($supplierId, [
            'doc_type' => 'receipt', 'origin' => 'manual', 'warehouse_id' => $warehouseId, 'doc_date' => '2099-01-10',
            'description' => 'Příjem kusů',
            'lines' => [['stock_item_id' => $itemId, 'qty' => count($serials) . '.000', 'unit_cost' => '100.000000',
                'tracking_allocations' => array_map(static fn (string $s): array => ['quantity' => '1', 'serial_number' => $s], $serials)]],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $draft['id'], $this->userId);
    }

    private function pdfHtml(int $invoiceId): string
    {
        $html = $this->container->get(\MyInvoice\Service\Pdf\InvoicePdfRenderer::class)->renderHtml(
            $this->container->get(InvoiceRepository::class)->find($invoiceId),
            includeCss: false,
            includeWorkReport: false,
        );
        return html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function trackedItem(int $supplierId, string $sku, string $mode): int
    {
        $id = $this->item($supplierId, $sku);
        $this->db->pdo()->prepare('UPDATE stock_items SET tracking_mode = ? WHERE supplier_id = ? AND id = ?')->execute([$mode, $supplierId, $id]);
        return $id;
    }

    /**
     * @param list<array<string,mixed>>|null $tracking
     * @return array<string,mixed>
     */
    private function line(?int $itemId, ?int $warehouseId, string $qty, ?array $tracking): array
    {
        $line = [
            'description' => 'Položka ' . ($itemId ?? 'bez karty'),
            'quantity' => $qty,
            'unit' => 'ks',
            'unit_price_without_vat' => 1000,
            'vat_rate_id' => $this->vatRateId,
            'stock_item_id' => $itemId,
            'warehouse_id' => $warehouseId,
        ];
        if ($tracking !== null) {
            $line['tracking_allocations'] = $tracking;
        }
        return $line;
    }

    /** @param list<array<string,mixed>> $items */
    private function createInvoiceResponse(int $supplierId, array $items, string $type = 'invoice'): ResponseInterface
    {
        $body = [
            'client_id' => $this->client($supplierId),
            'invoice_type' => $type,
            'issue_date' => '2099-06-10',
            'tax_date' => '2099-06-10',
            'due_date' => '2099-06-24',
            'currency_id' => $this->currencyIdFor($supplierId),
            'items' => $items,
        ];
        return $this->container->get(CreateInvoiceAction::class)($this->request('POST', $supplierId, $body), new Psr7Response());
    }

    /** @param list<array<string,mixed>> $items */
    private function createInvoice(int $supplierId, array $items, string $type = 'invoice'): int
    {
        $res = $this->createInvoiceResponse($supplierId, $items, $type);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        return (int) $this->body($res)['id'];
    }

    /** @param array<string,mixed> $body */
    private function updateInvoice(int $supplierId, int $invoiceId, array $body): ResponseInterface
    {
        return $this->container->get(UpdateInvoiceAction::class)(
            $this->request('PUT', $supplierId, $body),
            new Psr7Response(),
            ['id' => (string) $invoiceId],
        );
    }

    /** @return list<array<string,mixed>> */
    private function storedItems(int $invoiceId): array
    {
        return array_values(array_filter(
            (array) ($this->container->get(InvoiceRepository::class)->find($invoiceId)['items'] ?? []),
            static fn (array $item): bool => ($item['item_kind'] ?? 'standard') !== 'discount',
        ));
    }

    private function markIssued(int $invoiceId): void
    {
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued', varsymbol = ? WHERE id = ?")
            ->execute(['FV' . random_int(10000000, 99999999), $invoiceId]);
    }

    /** @param array<string,mixed> $body */
    private function request(string $method, int $supplierId, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, '/api/test')
            ->withParsedBody($body)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function body(ResponseInterface $res): array
    {
        $res->getBody()->rewind();
        return (array) json_decode((string) $res->getBody(), true);
    }

    private function assertError(ResponseInterface $res, int $status, string $code): void
    {
        self::assertSame($status, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame($code, $this->body($res)['error']['code'] ?? null);
    }
}
