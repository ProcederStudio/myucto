<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Eshop\PackagingUnitAction;
use MyInvoice\Action\Stock\StockItemAction;
use MyInvoice\Action\Stock\StockItemQuoteAction;
use MyInvoice\Action\Stock\StockTrackingAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InTransitRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\StockItemPromoPriceRepository;
use MyInvoice\Repository\StockTrackingRepository;
use MyInvoice\Service\Eshop\Pricing\EffectivePriceResolver;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockItemPackagingService;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Balení skladové karty (issue #17): číselník, balení karty s EAN, pojistka
 * řádků faktur a hlavně přepočet na ZÁKLADNÍ jednotku všude, kde se čte
 * množství skladového řádku faktury — výdej, kontrola dostupnosti, vratka
 * z dobropisu, příjem z přijaté faktury, rezervace, čerpání akce, PDF, nacenění.
 *
 * Vzorová karta: základní jednotka ks, balení KT = 8 ks (`is_sales_unit = 1`).
 */
#[Group('integration')]
final class StockPackagingTest extends StockTestCase
{
    private StockItemPackagingService $packaging;
    private StockTrackingRepository $tracking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packaging = $this->container->get(StockItemPackagingService::class);
        $this->tracking = $this->container->get(StockTrackingRepository::class);
    }

    // ── výdej z faktury ─────────────────────────────────────────────────────

    public function testInvoiceIssueOfPackagedLineWritesBaseQuantity(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->packagedItem($sid, 'KT-ISSUE');
        $plain = $this->item($sid, 'KT-PLAIN');
        $this->receiveStock($sid, $wh, $item, '100.000', 10.0);
        $this->receiveStock($sid, $wh, $plain, '10.000', 10.0);

        $inv = $this->invoiceDraft($sid, $this->client($sid));
        // Velikost písmen jednotky nerozhoduje; neznámá jednotka zůstává 1:1.
        $this->setUnit($this->invoiceItem($inv, $item, $wh, '10.000', 800.0, 0), 'Kt');
        $this->setUnit($this->invoiceItem($inv, $plain, $wh, '3.000', 100.0, 1), 'balík');

        $this->inTx(fn () => $this->issue->issueForInvoice($sid, $this->invoiceRow($inv, $sid), $this->userId));

        $docs = $this->docsRepo->listByInvoice($sid, $inv);
        self::assertCount(1, $docs);
        $qty = array_column($this->docsRepo->lines($sid, (int) $docs[0]['id']), 'qty', 'stock_item_id');
        self::assertSame('80.000', (string) $qty[$item], '10 KT po 8 ks musí vydat 80 ks.');
        self::assertSame('3.000', (string) $qty[$plain], 'Neznámá jednotka zůstává 1:1.');
        self::assertSame(20000, $this->level($sid, $wh, $item)['qtyT']);
    }

    public function testAvailabilityCheckComparesBaseQuantity(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->packagedItem($sid, 'KT-AVAIL');
        $this->receiveStock($sid, $wh, $item, '50.000', 10.0);
        $inv = $this->invoiceDraft($sid, $this->client($sid));
        $line = $this->invoiceItem($inv, $item, $wh, '10.000', 800.0);
        $this->setUnit($line, 'KT');

        try {
            $this->issue->assertAvailableForInvoice($sid, $this->invoiceRow($inv, $sid));
            self::fail('10 KT = 80 ks se z 50 ks vydat nedá.');
        } catch (StockException $e) {
            self::assertSame('insufficient_stock', $e->errorCode);
            self::assertSame('80.000', $e->details[0]['requested']);
            self::assertSame('50.000', $e->details[0]['available']);
        }

        $this->db->pdo()->prepare('UPDATE invoice_items SET quantity = 6 WHERE id = ?')->execute([$line]);
        $this->issue->assertAvailableForInvoice($sid, $this->invoiceRow($inv, $sid));
        $this->addToAssertionCount(1);
    }

    public function testCreditNoteReturnConvertsToBaseQuantity(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->packagedItem($sid, 'KT-CREDIT');
        $this->receiveStock($sid, $wh, $item, '100.000', 10.0);
        $client = $this->client($sid);

        $parent = $this->invoiceDraft($sid, $client);
        $this->setUnit($this->invoiceItem($parent, $item, $wh, '5.000', 800.0), 'KT');
        $this->inTx(fn () => $this->issue->issueForInvoice($sid, $this->invoiceRow($parent, $sid), $this->userId));

        $credit = $this->invoiceDraft($sid, $client, 'credit_note', ['parent_invoice_id' => $parent]);
        $this->setUnit($this->invoiceItem($credit, $item, $wh, '2.000', 800.0), 'KT');
        $this->inTx(fn () => $this->issue->returnForCreditNote($sid, [
            'id' => $credit, 'parent_invoice_id' => $parent, 'issue_date' => '2099-06-20', 'varsymbol' => '2099901',
        ], $this->userId));

        $docs = $this->docsRepo->listByInvoice($sid, $credit);
        self::assertCount(1, $docs);
        $lines = $this->docsRepo->lines($sid, (int) $docs[0]['id']);
        self::assertSame('16.000', (string) $lines[0]['qty'], 'Vratka 2 KT = 16 ks.');
        self::assertSame('10.000000', (string) $lines[0]['unit_cost'], 'Vratka v původní ceně výdeje za kus.');
        self::assertSame(76000, $this->level($sid, $wh, $item)['qtyT']);
    }

    // ── příjem z přijaté faktury ────────────────────────────────────────────

    public function testPurchaseInvoiceReceiptConvertsQuantityAndUnitCost(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->packagedItem($sid, 'KT-RECEIPT');
        $pi = $this->purchaseInvoice($sid, $this->client($sid, 'Dodavatel balení'));
        $piItem = $this->purchaseInvoiceItem($pi, $item, '10.000', 800.0);
        $this->setUnit($piItem, 'KT', 'purchase_invoice_items');

        $proposal = $this->receipts->proposeForPurchaseInvoice($sid, $pi);
        $line = $proposal['lines'][0];
        self::assertSame('80.000', $line['quantity']);
        self::assertSame('80.000', $line['remaining_qty']);
        self::assertSame('KT', $line['invoice_unit']);
        // Hodnota řádku (bez DPH u plátce, s DPH u neplátce) se rozloží na 80 ks.
        self::assertContains(round((float) $line['unit_cost'] * 80, 2), [8000.0, 9680.0], 'Pořizovací cena musí být za kus, ne za karton.');

        try {
            $this->receipts->createReceipt($sid, $pi, [
                'warehouse_id' => $wh, 'doc_date' => '2099-06-02',
                'lines' => [['purchase_invoice_item_id' => $piItem, 'quantity' => '81.000']],
            ], $this->userId);
            self::fail('Přes 80 ks z faktury na 10 KT přijmout nejde.');
        } catch (StockException $e) {
            self::assertSame('over_receipt', $e->errorCode);
        }

        $receipt = $this->receipts->createReceipt($sid, $pi, [
            'warehouse_id' => $wh, 'doc_date' => '2099-06-02',
            'lines' => [['purchase_invoice_item_id' => $piItem, 'quantity' => '80.000']],
        ], $this->userId);
        self::assertSame('80.000', (string) $receipt['lines'][0]['qty']);
        self::assertSame($line['unit_cost'], (string) $receipt['lines'][0]['unit_cost']);
    }

    // ── rezervace a čerpání akce ────────────────────────────────────────────

    public function testReservationCountsBaseQuantity(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->packagedItem($sid, 'KT-RESERVE');
        $inv = $this->invoiceDraft($sid, $this->client($sid));
        $this->setUnit($this->invoiceItem($inv, $item, $wh, '10.000', 800.0), 'kt');
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$inv]);

        $rows = $this->container->get(InTransitRepository::class)->reservedForItems($sid, [$item]);
        self::assertCount(1, $rows);
        self::assertSame('80.000', $rows[0]['qty_reserved']);
    }

    public function testPromoQuotaIsConsumedInBaseUnits(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->packagedItem($sid, 'KT-PROMO');
        $this->itemsRepo->setSalePrice($sid, $item, '100.00');
        $this->container->get(StockItemPromoPriceRepository::class)->insert($sid, $item, [
            'currency_code' => 'CZK', 'promo_price' => '90.00', 'label' => 'Akce', 'valid_from' => '2099-01-01',
            'valid_to' => null, 'qty_mode' => 'limited', 'qty_limit' => '100.000', 'is_active' => true, 'note' => null,
        ]);
        $inv = $this->invoiceDraft($sid, $this->client($sid));
        // 10 KT × 720 Kč = 90 Kč/ks → akční cena, čerpá 80 ks z rozpočtu 100 ks.
        $this->setUnit($this->invoiceItem($inv, $item, $wh, '10.000', 720.0), 'KT');
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$inv]);

        $r = $this->container->get(EffectivePriceResolver::class)->resolve($sid, $item, 'CZK', '1', '2099-06-15');
        self::assertTrue($r['promo_applied']);
        self::assertSame('20.000', $r['promo_qty_available']);
    }

    // ── balení karty ────────────────────────────────────────────────────────

    public function testTrackingUnitsAndPackagingManageDisjointSets(): void
    {
        $sid = $this->createSupplier();
        $item = $this->packagedItem($sid, 'DISJOINT', '8', '8590000000017');
        $trackingAction = $this->container->get(StockTrackingAction::class);

        // Editor šarží spravuje jen své převodní jednotky — balení (i jeho EAN) nechá být.
        $res = $trackingAction->replaceUnits($this->request('PUT', $sid, ['units' => [['unit_code' => 'bal', 'numerator' => 5, 'denominator' => 1]]]), new Psr7Response(), ['id' => (string) $item]);
        self::assertSame(200, $res->getStatusCode());
        $sales = $this->tracking->units($sid, $item, true);
        self::assertSame(['KT', '8590000000017', 8], [$sales[0]['unit_code'], $sales[0]['ean'], $sales[0]['numerator']]);
        self::assertSame(['bal'], array_column($this->tracking->units($sid, $item, false), 'unit_code'));

        // Editor balení spravuje jen balení — převodní jednotku šarží nesmaže.
        $this->packaging->save($sid, $item, ['units' => []]);
        self::assertSame([], $this->tracking->units($sid, $item, true));
        self::assertSame(['bal'], array_column($this->tracking->units($sid, $item, false), 'unit_code'));
        self::assertNull($this->packaging->get($sid, $item)['default_sale_unit']);

        // Kolize kódu mezi sadami → 422 z obou stran.
        $this->packaging->save($sid, $item, ['units' => [['unit_code' => 'KT', 'factor' => '8']]]);
        $clash = $trackingAction->replaceUnits($this->request('PUT', $sid, ['units' => [['unit_code' => 'kt', 'numerator' => 6, 'denominator' => 1]]]), new Psr7Response(), ['id' => (string) $item]);
        self::assertSame(422, $clash->getStatusCode());
        self::assertSame('packaging_unit_code_clash', $this->body($clash)['error']['code']);
        try {
            $this->packaging->save($sid, $item, ['units' => [['unit_code' => 'BAL', 'factor' => '5']]]);
            self::fail('Kód převodní jednotky šarží nesmí být balením.');
        } catch (StockException $e) {
            self::assertSame('packaging_unit_code_clash', $e->errorCode);
        }
    }

    public function testPackagingEanMustBeUniqueWithinCompany(): void
    {
        $sid = $this->createSupplier();
        $cardWithEan = $this->item($sid, 'EAN-CARD');
        $this->db->pdo()->prepare('UPDATE stock_items SET ean = ? WHERE id = ?')->execute(['8590000000024', $cardWithEan]);
        $other = $this->packagedItem($sid, 'EAN-OTHER', '8', '8590000000031');
        $item = $this->item($sid, 'EAN-NEW');

        foreach (['8590000000024', '8590000000031'] as $ean) {
            try {
                $this->packaging->save($sid, $item, ['units' => [['unit_code' => 'KT', 'factor' => '8', 'ean' => $ean]]]);
                self::fail("EAN $ean už ve firmě existuje.");
            } catch (StockException $e) {
                self::assertSame('ean_duplicate', $e->errorCode);
            }
        }

        // Vlastní EAN karty při opakovaném uložení nevadí; cizí firma ho mít smí.
        $this->packaging->save($sid, $other, ['units' => [['unit_code' => 'KT', 'factor' => '8', 'ean' => '8590000000031']]]);
        $this->packagedItem($this->createSupplier(), 'EAN-FOREIGN', '8', '8590000000031');
        $this->addToAssertionCount(1);
    }

    public function testCodeUsedByAnyInvoiceLineCannotBeRemovedOrChanged(): void
    {
        $sid = $this->createSupplier();
        $this->db->pdo()->prepare("INSERT INTO stock_packaging_units (supplier_id, code, name) VALUES (?, 'BAL', 'Balík')")->execute([$sid]);
        $item = $this->packagedItem($sid, 'GUARD');
        // Balení s přesným zlomkem 1/3 (přes numerator/denominator).
        $this->packaging->save($sid, $item, ['units' => [['unit_code' => 'KT', 'factor' => '8'], ['unit_code' => 'BAL', 'numerator' => 1, 'denominator' => 3]]]);
        $inv = $this->invoiceDraft($sid, $this->client($sid));
        // I DRAFT řádek drží balení — poměr se pod ním nesmí změnit.
        $this->setUnit($this->invoiceItem($inv, $item, null, '2.000', 800.0, 0), 'KT');
        $this->setUnit($this->invoiceItem($inv, $item, null, '3.000', 10.0, 1), 'bal');

        foreach ([
            'změna poměru' => [['unit_code' => 'KT', 'factor' => '6'], ['unit_code' => 'BAL', 'factor' => '0.333']],
            'odebrání'     => [['unit_code' => 'BAL', 'factor' => '0.333']],
        ] as $case => $units) {
            try {
                $this->packaging->save($sid, $item, ['units' => $units]);
                self::fail("$case balení na řádku faktury musí selhat.");
            } catch (StockException $e) {
                self::assertSame('packaging_unit_in_use', $e->errorCode, $case);
                self::assertSame('KT', $e->details['unit_code'], $case);
            }
        }

        // Uložení beze změny projde; zaokrouhlené „0.333" zachová přesný zlomek 1/3.
        $saved = $this->packaging->save($sid, $item, ['units' => [['unit_code' => 'KT', 'factor' => '8'], ['unit_code' => 'BAL', 'factor' => '0.333']]]);
        $byCode = array_column($saved['units'], null, 'unit_code');
        self::assertSame([1, 3], [$byCode['BAL']['numerator'], $byCode['BAL']['denominator']]);
        self::assertTrue($byCode['KT']['in_use']);
    }

    public function testUnitCodeMustComeFromActiveCodebookUnlessAlreadyOnCard(): void
    {
        $sid = $this->createSupplier();
        $item = $this->packagedItem($sid, 'CODES');
        $this->db->pdo()->prepare("INSERT INTO stock_packaging_units (supplier_id, code, name, is_active) VALUES (?, 'PAL', 'Paleta', 0)")->execute([$sid]);

        foreach (['PAL' => 'packaging_unit_unknown', 'XYZ' => 'packaging_unit_unknown', 'KS' => 'validation_failed'] as $code => $error) {
            try {
                $this->packaging->save($sid, $item, ['units' => [['unit_code' => $code, 'factor' => '40']]]);
                self::fail("Kód $code nesmí projít.");
            } catch (StockException $e) {
                self::assertSame($error, $e->errorCode, $code);
            }
        }

        // Balení, jehož kód se mezitím v číselníku deaktivoval, na kartě zůstává.
        $this->db->pdo()->prepare("UPDATE stock_packaging_units SET is_active = 0 WHERE supplier_id = ? AND code = 'KT'")->execute([$sid]);
        $saved = $this->packaging->save($sid, $item, ['default_sale_unit' => 'kt', 'units' => [['unit_code' => 'kt', 'factor' => '8']]]);
        self::assertSame('KT', $saved['units'][0]['unit_code'], 'Kód se ukládá v zápisu karty.');
        self::assertSame('KT', $saved['default_sale_unit']);

        try {
            $this->packaging->save($sid, $item, ['default_sale_unit' => 'PAL', 'units' => [['unit_code' => 'KT', 'factor' => '8']]]);
            self::fail('Výchozí prodejní jednotka musí být balení karty.');
        } catch (StockException $e) {
            self::assertSame('validation_failed', $e->errorCode);
        }
    }

    // ── číselník balení (HTTP) ──────────────────────────────────────────────

    public function testPackagingCodebookCrudAndGuards(): void
    {
        $sid = $this->createSupplier();
        $action = $this->container->get(PackagingUnitAction::class);

        $created = $action->create($this->request('POST', $sid, ['code' => 'KT', 'name' => 'Karton']), new Psr7Response());
        self::assertSame(201, $created->getStatusCode());
        $id = (int) $this->body($created)['id'];

        $dup = $action->create($this->request('POST', $sid, ['code' => 'kt', 'name' => 'Karton 2']), new Psr7Response());
        self::assertSame(409, $dup->getStatusCode());
        self::assertSame('packaging_unit_code_taken', $this->body($dup)['error']['code']);

        $item = $this->item($sid, 'CODEBOOK');
        $this->packaging->save($sid, $item, ['units' => [['unit_code' => 'KT', 'factor' => '8']]]);
        $list = $this->body($action->list($this->request('GET', $sid), new Psr7Response()));
        self::assertSame(1, $list[0]['usage_count']);

        $rename = $action->update($this->request('PUT', $sid, ['code' => 'KRT']), new Psr7Response(), ['id' => (string) $id]);
        self::assertSame(422, $rename->getStatusCode());
        self::assertSame('packaging_unit_code_in_use', $this->body($rename)['error']['code']);

        $deactivate = $action->update($this->request('PUT', $sid, ['is_active' => false]), new Psr7Response(), ['id' => (string) $id]);
        self::assertSame(200, $deactivate->getStatusCode());
        self::assertFalse($this->body($deactivate)['is_active']);

        $delete = $action->delete($this->request('DELETE', $sid), new Psr7Response(), ['id' => (string) $id]);
        self::assertSame(409, $delete->getStatusCode());
        self::assertSame('packaging_unit_in_use', $this->body($delete)['error']['code']);

        $pal = (int) $this->body($action->create($this->request('POST', $sid, ['code' => 'PAL', 'name' => 'Paleta']), new Psr7Response()))['id'];
        self::assertSame(200, $action->delete($this->request('DELETE', $sid), new Psr7Response(), ['id' => (string) $pal])->getStatusCode());
    }

    // ── našeptávač, nacenění, PDF ───────────────────────────────────────────

    public function testSearchFindsCardByPackagingEan(): void
    {
        $sid = $this->createSupplier();
        $item = $this->packagedItem($sid, 'SCAN', '8', '8590000000048');
        $action = $this->container->get(StockItemAction::class);

        $rows = $this->body($action->search($this->request('GET', $sid, null, ['q' => '8590000000048']), new Psr7Response()));
        self::assertCount(1, $rows);
        self::assertSame($item, $rows[0]['id']);
        self::assertSame('KT', $rows[0]['matched_unit']);
        self::assertSame('KT', $rows[0]['default_sale_unit']);
        self::assertSame('8', $rows[0]['units'][0]['factor']);
        self::assertSame('Karton', $rows[0]['units'][0]['name']);

        $bySku = $this->body($action->search($this->request('GET', $sid, null, ['q' => 'SCAN']), new Psr7Response()));
        self::assertNull($bySku[0]['matched_unit']);
    }

    public function testSearchReturnsStockLevelPerWarehouse(): void
    {
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HLAVNI');
        $shop = $this->warehouse($sid, 'PRODEJNA', false);
        $item = $this->item($sid, 'LVL-STOCK');
        $empty = $this->item($sid, 'LVL-EMPTY');
        $service = $this->item($sid, 'LVL-SERVICE');
        $this->db->pdo()->prepare('UPDATE stock_items SET is_stocked = 0 WHERE id = ?')->execute([$service]);
        $this->receiveStock($sid, $main, $item, '5.000', 10.0);
        $this->receiveStock($sid, $shop, $item, '3.000', 10.0);
        $action = $this->container->get(StockItemAction::class);

        $all = array_column($this->body($action->search($this->request('GET', $sid, null, ['q' => 'LVL-']), new Psr7Response())), null, 'id');
        self::assertSame('8.000', $all[$item]['qty'], 'Bez skladu = součet prodejních skladů (jako /stock/availability).');
        self::assertTrue($all[$item]['is_stocked']);
        self::assertSame('0.000', $all[$empty]['qty'], 'Skladová karta bez pohybu má stav nula, ne null.');
        self::assertFalse($all[$service]['is_stocked']);
        self::assertNull($all[$service]['qty'], 'Neskladová karta stav nemá.');

        $inShop = array_column($this->body($action->search($this->request('GET', $sid, null, ['q' => 'LVL-', 'warehouse_id' => (string) $shop]), new Psr7Response())), null, 'id');
        self::assertSame('3.000', $inShop[$item]['qty']);
        self::assertSame('0.000', $inShop[$empty]['qty']);
    }

    public function testQuotePricesSelectedUnit(): void
    {
        $sid = $this->createSupplier();
        $item = $this->packagedItem($sid, 'QUOTE');
        $this->itemsRepo->setSalePrice($sid, $item, '100.00');
        $client = $this->client($sid);
        $this->db->pdo()->prepare(
            "INSERT INTO stock_item_customer_prices (supplier_id, stock_item_id, client_id, currency_code, price_type, discount_pct)
             VALUES (?, ?, ?, 'CZK', 'discount_pct', 10.000)"
        )->execute([$sid, $item, $client]);
        $action = $this->container->get(StockItemQuoteAction::class);
        $lines = [
            ['key' => 'a', 'stock_item_id' => $item, 'unit' => 'KT', 'quantity' => '10'],
            ['key' => 'b', 'stock_item_id' => $item, 'unit' => null, 'quantity' => '3'],
            ['key' => 'c', 'stock_item_id' => $item, 'unit' => 'bal', 'quantity' => '2'],
        ];

        $res = $this->body($action->quote($this->request('POST', $sid, ['client_id' => null, 'currency' => 'CZK', 'date' => '2099-06-15', 'lines' => $lines]), new Psr7Response()));
        [$kt, $base, $unknown] = $res['lines'];
        self::assertSame(['a', 'KT', 'ks', '80.000', '100.00', '800.00', 'standard', null], [$kt['key'], $kt['unit'], $kt['base_unit'], $kt['base_quantity'], $kt['base_unit_price'], $kt['unit_price'], $kt['price_source'], $kt['discount_pct']]);
        self::assertSame(['ks', '3.000', '100.00'], [$base['unit'], $base['base_quantity'], $base['unit_price']]);
        self::assertSame(['ks', '2.000'], [$unknown['unit'], $unknown['base_quantity']], 'Neznámá jednotka se nacení jako základní.');

        $withClient = $this->body($action->quote($this->request('POST', $sid, ['client_id' => $client, 'currency' => 'CZK', 'date' => '2099-06-15', 'lines' => $lines]), new Psr7Response()));
        self::assertSame('720.00', $withClient['lines'][0]['unit_price']);
        self::assertSame('90.00', $withClient['lines'][0]['base_unit_price']);
        self::assertSame('customer_discount', $withClient['lines'][0]['price_source']);
        self::assertSame('10.000', $withClient['lines'][0]['discount_pct']);
        self::assertSame(['800.00', '100.00'], [$withClient['lines'][0]['list_unit_price'], $withClient['lines'][1]['list_unit_price']], 'Ceníková cena za zvolenou jednotku.');
        self::assertSame('800.00', $kt['list_unit_price']);
        self::assertIsInt($withClient['lines'][0]['customer_price_id']);

        $foreign = $action->quote($this->request('POST', $sid, ['client_id' => $this->client($this->createSupplier()), 'lines' => $lines]), new Psr7Response());
        self::assertSame(422, $foreign->getStatusCode());
        self::assertSame('invalid_client', $this->body($foreign)['error']['code']);
    }

    public function testInvoicePdfShowsBaseQuantityUnlessDisabled(): void
    {
        $sid = $this->createSupplier();
        $item = $this->packagedItem($sid, 'PDF');
        $inv = $this->invoiceDraft($sid, $this->client($sid));
        $this->setUnit($this->invoiceItem($inv, $item, null, '10.000', 800.0), 'KT');
        $invoices = $this->container->get(InvoiceRepository::class);
        $renderer = $this->container->get(InvoicePdfRenderer::class);

        $html = $renderer->renderHtml($invoices->find($inv), includeCss: false, includeWorkReport: false);
        self::assertStringContainsString('(celkem 80', $html);

        $this->db->pdo()->prepare('UPDATE supplier SET invoice_pdf_show_base_qty = 0 WHERE id = ?')->execute([$sid]);
        $html = $renderer->renderHtml($invoices->find($inv), includeCss: false, includeWorkReport: false);
        self::assertStringNotContainsString('(celkem', $html);
    }

    // ── pomocníci ───────────────────────────────────────────────────────────

    private function packagedItem(int $sid, string $sku, string $factor = '8', ?string $ean = null): int
    {
        $this->db->pdo()->prepare("INSERT IGNORE INTO stock_packaging_units (supplier_id, code, name) VALUES (?, 'KT', 'Karton')")->execute([$sid]);
        $item = $this->item($sid, $sku);
        $this->packaging->save($sid, $item, ['default_sale_unit' => 'KT', 'units' => [['unit_code' => 'KT', 'factor' => $factor, 'ean' => $ean]]]);
        return $item;
    }

    private function setUnit(int $lineId, string $unit, string $table = 'invoice_items'): void
    {
        $this->db->pdo()->prepare("UPDATE {$table} SET unit = ? WHERE id = ?")->execute([$unit, $lineId]);
    }

    /** @return array<string,mixed> */
    private function invoiceRow(int $invoiceId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, supplier_id, invoice_type, parent_invoice_id, issue_date, tax_date, varsymbol FROM invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$invoiceId, $supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $row['id'] = (int) $row['id'];
        return $row;
    }

    private function inTx(callable $fn): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        try {
            $fn();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string,mixed>|null $body @param array<string,string> $query */
    private function request(string $method, int $supplierId, ?array $body = null, array $query = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/test')
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        return $body !== null ? $request->withParsedBody($body) : $request;
    }

    /** @return array<mixed> */
    private function body(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
