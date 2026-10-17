<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InTransitRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Vynucená úprava vystavené faktury, ke které už vznikla výdejka.
 *
 * `replaceItems()` je DELETE + INSERT, takže řádky faktury dostanou nová id. Řádky
 * výdejky na ně odkazují přes `invoice_item_id` (FK ON DELETE SET NULL) a vazbu
 * ztrácely: faktura pak znovu blokovala celé množství jako rezervaci, pohyb karty
 * přišel o prodejní cenu a Intrastat o řádek faktury. Výdej běží jen při vystavení,
 * takže přidaná nebo změněná skladová položka se navíc nikdy nevydala.
 */
#[Group('integration')]
final class IssuedInvoiceStockEditTest extends StockTestCase
{
    public function testForceEditWithoutStockChangeKeepsIssueLinkAndReservation(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'EDIT-KEEP');
        $this->receiveStock($supplierId, $whId, $itemId, '10.000', 10.0);
        $invoiceId = $this->issuedInvoice($supplierId, [[$itemId, $whId, '3.000']]);

        $items = $this->storedItems($invoiceId);
        $items[0]['description'] = 'Opravený popis';
        $items[0]['unit_price_without_vat'] = 120;
        $res = $this->forceEdit($supplierId, $invoiceId, $items);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $newItemId = (int) $this->storedItems($invoiceId)[0]['id'];
        self::assertSame([$newItemId], $this->issueLineLinks($supplierId, $invoiceId), 'Výdejka musí ukazovat na nový řádek faktury.');
        self::assertSame([], $this->reserved($supplierId, $itemId), 'Vydaná faktura nesmí po úpravě znovu blokovat zboží.');
        self::assertSame(7000, $this->level($supplierId, $whId, $itemId)['qtyT']);
    }

    public function testForceEditKeepsLinksOfRepeatedStockItemInOrder(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'EDIT-TWICE');
        $this->receiveStock($supplierId, $whId, $itemId, '10.000', 10.0);
        $invoiceId = $this->issuedInvoice($supplierId, [[$itemId, $whId, '1.000'], [$itemId, $whId, '2.000']]);

        $items = $this->storedItems($invoiceId);
        $items[1]['description'] = 'Druhý řádek';
        $res = $this->forceEdit($supplierId, $invoiceId, $items);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $stmt = $this->db->pdo()->prepare(
            'SELECT ii.quantity, l.qty
               FROM stock_document_lines l
               JOIN invoice_items ii ON ii.id = l.invoice_item_id
              WHERE l.supplier_id = ? AND ii.invoice_id = ?
              ORDER BY ii.order_index'
        );
        $stmt->execute([$supplierId, $invoiceId]);
        $pairs = $stmt->fetchAll(\PDO::FETCH_NUM);
        self::assertCount(2, $pairs);
        foreach ($pairs as [$invoiceQty, $issuedQty]) {
            self::assertSame((float) $invoiceQty, (float) $issuedQty, 'Řádek výdejky musí zůstat u řádku se stejným množstvím.');
        }
        self::assertSame([], $this->reserved($supplierId, $itemId));
    }

    public function testForceEditAcceptsDefaultWarehouseFilledByEditor(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'EDIT-NOWH');
        $this->receiveStock($supplierId, $whId, $itemId, '10.000', 10.0);
        // Řádek bez skladu vydá výchozí sklad; editor ho při otevření doplní do formuláře.
        $invoiceId = $this->issuedInvoice($supplierId, [[$itemId, null, '1.000']]);

        $items = $this->storedItems($invoiceId);
        $items[0]['warehouse_id'] = $whId;
        $res = $this->forceEdit($supplierId, $invoiceId, $items);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([], $this->reserved($supplierId, $itemId));
    }

    public function testForceEditAddingStockItemIsRejected(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'EDIT-BASE');
        $addedId = $this->item($supplierId, 'EDIT-ADDED');
        $this->receiveStock($supplierId, $whId, $itemId, '10.000', 10.0);
        $this->receiveStock($supplierId, $whId, $addedId, '10.000', 10.0);
        $invoiceId = $this->issuedInvoice($supplierId, [[$itemId, $whId, '1.000']]);
        $before = $this->storedItems($invoiceId);

        $items = $before;
        $items[] = [
            'description'            => 'Přidaná kšiltovka',
            'quantity'               => 1,
            'unit'                   => 'ks',
            'unit_price_without_vat' => 250,
            'vat_rate_id'            => $this->vatRateId,
            'stock_item_id'          => $addedId,
            'warehouse_id'           => $whId,
        ];
        $res = $this->forceEdit($supplierId, $invoiceId, $items);

        $this->assertStockLocked($res);
        self::assertSame(array_column($before, 'id'), array_column($this->storedItems($invoiceId), 'id'), 'Odmítnutá úprava nesmí sáhnout na řádky.');
        self::assertSame(10000, $this->level($supplierId, $whId, $addedId)['qtyT']);
    }

    public function testForceEditChangingQuantityOrRemovingStockItemIsRejected(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'EDIT-QTY');
        $this->receiveStock($supplierId, $whId, $itemId, '10.000', 10.0);
        $invoiceId = $this->issuedInvoice($supplierId, [[$itemId, $whId, '2.000'], [null, null, '1.000']]);

        $items = $this->storedItems($invoiceId);
        $items[0]['quantity'] = 3;
        $this->assertStockLocked($this->forceEdit($supplierId, $invoiceId, $items));

        $items = $this->storedItems($invoiceId);
        $this->assertStockLocked($this->forceEdit($supplierId, $invoiceId, [$items[1]]));

        $items = $this->storedItems($invoiceId);
        $items[1]['description'] = 'Doprava';
        $items[1]['unit_price_without_vat'] = 99;
        $res = $this->forceEdit($supplierId, $invoiceId, $items);
        self::assertSame(200, $res->getStatusCode(), 'Neskladový řádek jde upravit dál: ' . $res->getBody());
    }

    /**
     * Role s právem úpravy vystavené faktury (ne správce) upraví popis a cenu, ale
     * skladová pojistka platí pro ni stejně jako pro správce.
     */
    public function testEditIssuedPermissionKeepsStockGuard(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'EDIT-ROLE');
        $this->receiveStock($supplierId, $whId, $itemId, '10.000', 10.0);
        $invoiceId = $this->issuedInvoice($supplierId, [[$itemId, $whId, '2.000']]);
        $editor = new EffectiveRole(90, 'Úprava vystavených', 'staff', true, [
            'invoices' => AccessLevel::WRITE->value,
            'invoices.edit_issued' => AccessLevel::WRITE->value,
        ]);

        $items = $this->storedItems($invoiceId);
        $items[0]['description'] = 'Opravený popis';
        $res = $this->forceEdit($supplierId, $invoiceId, $items, $editor);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());

        $items = $this->storedItems($invoiceId);
        $items[0]['quantity'] = 3;
        $this->assertStockLocked($this->forceEdit($supplierId, $invoiceId, $items, $editor));

        $withoutRight = new EffectiveRole(91, 'Fakturace', 'staff', true, ['invoices' => AccessLevel::WRITE->value]);
        $items = $this->storedItems($invoiceId);
        $items[0]['description'] = 'Další oprava';
        $res = $this->forceEdit($supplierId, $invoiceId, $items, $withoutRight);
        self::assertSame(409, $res->getStatusCode(), (string) $res->getBody());
        self::assertStringContainsString('not_editable', (string) $res->getBody());
    }

    public function testRepositoryReplaceItemsKeepsIssueLink(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'EDIT-REPO');
        $this->receiveStock($supplierId, $whId, $itemId, '10.000', 10.0);
        $invoiceId = $this->issuedInvoice($supplierId, [[$itemId, $whId, '4.000']]);

        /** @var InvoiceRepository $repo */
        $repo = $this->container->get(InvoiceRepository::class);
        $repo->replaceItems($invoiceId, $this->storedItems($invoiceId));

        self::assertSame([(int) $this->storedItems($invoiceId)[0]['id']], $this->issueLineLinks($supplierId, $invoiceId));
    }

    // ── pomocníci ────────────────────────────────────────────────────────────

    /**
     * Vystavená faktura s posted výdejkou.
     *
     * @param list<array{0:?int,1:?int,2:string}> $lines [karta, sklad, množství]
     */
    private function issuedInvoice(int $supplierId, array $lines): int
    {
        $invoiceId = $this->invoiceDraft($supplierId, $this->client($supplierId), 'invoice', [
            'varsymbol' => 'FV' . random_int(10000000, 99999999),
        ]);
        foreach ($lines as $index => [$stockItemId, $warehouseId, $qty]) {
            $this->invoiceItem($invoiceId, $stockItemId, $warehouseId, $qty, 100.0, $index);
        }
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT id, supplier_id, invoice_type, parent_invoice_id, issue_date, tax_date, varsymbol FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $row['id'] = $invoiceId;
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        try {
            $this->issue->issueForInvoice($supplierId, $row, $this->userId);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$invoiceId]);
        self::assertNotSame([], $this->issueLineLinks($supplierId, $invoiceId), 'Předpoklad: výdejka vznikla.');
        return $invoiceId;
    }

    /** @return list<array<string,mixed>> položky tak, jak je editor dostane a pošle zpět */
    private function storedItems(int $invoiceId): array
    {
        /** @var InvoiceRepository $repo */
        $repo = $this->container->get(InvoiceRepository::class);
        return array_values(array_filter(
            (array) ($repo->find($invoiceId)['items'] ?? []),
            static fn (array $item): bool => ($item['item_kind'] ?? 'standard') !== 'discount',
        ));
    }

    /** @param list<array<string,mixed>> $items */
    private function forceEdit(int $supplierId, int $invoiceId, array $items, ?EffectiveRole $role = null): ResponseInterface
    {
        /** @var UpdateInvoiceAction $action */
        $action = $this->container->get(UpdateInvoiceAction::class);
        $request = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/invoices/' . $invoiceId)
            ->withQueryParams(['force' => '1'])
            ->withParsedBody(['items' => $items])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
        if ($role !== null) {
            $request = $request->withAttribute('auth.effective_role', $role);
        }
        return $action($request, new Psr7Response(), ['id' => (string) $invoiceId]);
    }

    /** @return list<?int> invoice_item_id řádků výdejek k faktuře */
    private function issueLineLinks(int $supplierId, int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.invoice_item_id
               FROM stock_document_lines l
               JOIN stock_documents d ON d.id = l.document_id
              WHERE d.supplier_id = ? AND d.invoice_id = ? AND d.status = "posted"
              ORDER BY l.id'
        );
        $stmt->execute([$supplierId, $invoiceId]);
        return array_map(static fn ($id): ?int => $id !== null ? (int) $id : null, $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<array<string,mixed>> */
    private function reserved(int $supplierId, int $itemId): array
    {
        /** @var InTransitRepository $repo */
        $repo = $this->container->get(InTransitRepository::class);
        return $repo->reservedForItems($supplierId, [$itemId]);
    }

    private function assertStockLocked(ResponseInterface $res): void
    {
        self::assertSame(409, $res->getStatusCode(), (string) $res->getBody());
        $body = json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('stock_items_locked', $body['error']['code'] ?? null);
    }
}
