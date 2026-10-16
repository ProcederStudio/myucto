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
            $this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-E', 'stock_tracking_unit_id' => 999999, 'location_to_id' => 5]]),
        ]);
        $stored = $this->storedItems($invoiceId)[0]['tracking_allocations'][0];
        self::assertArrayNotHasKey('stock_tracking_unit_id', $stored, 'Identita se ukládá textem, ne id jednotky.');
        self::assertArrayNotHasKey('location_to_id', $stored);
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

    // ── pomocníci ────────────────────────────────────────────────────────────

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
