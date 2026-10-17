<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Stock\StockDocumentService;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockIssueService;
use PHPUnit\Framework\Attributes\Group;

/**
 * Epic SKLAD, plán §8.2 — scénáře 1 (post/reverse idempotence + storno storna),
 * 2 (souběh na poslední kusy, B3), 3 (insufficient_stock nepropálí číslo řady),
 * 9 (převodka hodnotově neutrální / nedostatek), 10 (backdating reprice + storno
 * převodky nepodporováno), 11 (storno příjemky po výdejích → 409), 17 (tenant
 * izolace dokladů/skladů/karet) a regresní test pro reverzní neutralitu pod
 * backdatingem (review CRITICAL 2, §3.2/§4.4).
 */
#[Group('integration')]
final class StockDocumentLifecycleTest extends StockTestCase
{
    // ── 1) post/reverse idempotence ─────────────────────────────────────────

    public function testDoublePostIsIdempotentNoOp(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'LC-1');

        $first = $this->receiveStock($supplierId, $whId, $itemId, '10.000', 10.0);
        self::assertSame('posted', $first['status']);
        $docNumber = $first['doc_number'];

        $second = $this->documents->post($supplierId, (int) $first['id'], $this->userId);
        self::assertSame('posted', $second['status']);
        self::assertSame($docNumber, $second['doc_number'], 'druhý post() nesmí přeúčtovat ani vydat nové číslo.');
        self::assertSame([], $second['warnings']);

        $level = $this->level($supplierId, $whId, $itemId);
        self::assertSame(10000, $level['qtyT'], 'stav se dvojklikem nesmí zdvojit.');
        self::assertSame(10000, $level['valueC']);
    }

    public function testReverseCreatesCounterDocumentInOriginalValueAndCompensatesLevels(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'LC-2');

        $receipt = $this->receiveStock($supplierId, $whId, $itemId, '5.000', 100.0);
        self::assertSame('posted', $receipt['status']);

        $result = $this->documents->reverse($supplierId, (int) $receipt['id'], ['reason' => 'test storno'], $this->userId);

        self::assertSame('reversed', $result['original']['status']);
        self::assertSame('issue', $result['reversal']['doc_type']);
        self::assertSame('posted', $result['reversal']['status']);
        self::assertSame($result['original']['doc_date'], $result['reversal']['doc_date']);
        self::assertSame($whId, $result['reversal']['warehouse_id']);
        self::assertNotNull($result['original']['reversal_document_id']);
        self::assertSame((int) $result['reversal']['id'], (int) $result['original']['reversal_document_id']);

        self::assertCount(1, $result['reversal']['lines']);
        self::assertSame('5.000', $result['reversal']['lines'][0]['qty']);
        self::assertSame('500.00', $result['reversal']['lines'][0]['value_total'], 'protidoklad v PŮVODNÍ ceně (hodnotová neutralita §4.4).');

        $level = $this->level($supplierId, $whId, $itemId);
        self::assertSame(0, $level['qtyT'], 'storno kompenzuje stav zpět na nulu.');
        self::assertSame(0, $level['valueC']);
    }

    public function testReversedDocumentCannotBeReversedAgain(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'LC-3');

        $receipt = $this->receiveStock($supplierId, $whId, $itemId, '3.000', 50.0);
        $this->documents->reverse($supplierId, (int) $receipt['id'], [], $this->userId);

        $this->expectExceptionStockErrorCode('already_reversed', function () use ($supplierId, $receipt): void {
            $this->documents->reverse($supplierId, (int) $receipt['id'], [], $this->userId);
        });
    }

    // ── 3) insufficient_stock na issue nepropálí číslo řady ─────────────────

    public function testInsufficientStockOnIssueLeavesDraftWithoutBurningDocNumber(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'LC-4');
        // Žádný stav — karta je prázdná.

        $draft = $this->documents->create($supplierId, [
            'doc_type'     => 'issue',
            'origin'       => 'manual',
            'warehouse_id' => $whId,
            'doc_date'     => '2099-03-01',
            'description'  => 'Test výdej nad rámec',
            'lines'        => [['stock_item_id' => $itemId, 'qty' => '5.000']],
        ], $this->userId);

        try {
            $this->documents->post($supplierId, (int) $draft['id'], $this->userId);
            self::fail('post() měl vyhodit insufficient_stock.');
        } catch (StockException $e) {
            self::assertSame('insufficient_stock', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
            self::assertNotEmpty($e->details);
            // Okno nedostatku v aplikaci ukazuje sklad a jednotku (aditivní klíče).
            self::assertSame($whId, $e->details[0]['warehouse_id']);
            self::assertSame('Sklad HLAVNI', $e->details[0]['warehouse_name']);
            self::assertSame('ks', $e->details[0]['unit']);
        }

        $stillDraft = $this->docsRepo->find($supplierId, (int) $draft['id']);
        self::assertSame('draft', $stillDraft['status']);
        self::assertNull($stillDraft['doc_number'], 'neúspěšný post nesmí propálit číslo řady (B3).');

        $seriesRow = $this->db->pdo()->prepare(
            "SELECT next_number FROM accounting_document_series WHERE supplier_id = ? AND series_code = 'stock_out'"
        );
        $seriesRow->execute([$supplierId]);
        self::assertFalse($seriesRow->fetchColumn(), 'řádek řady VYD nesmí vzniknout, dokud se nevydá první reálné číslo.');

        // Úspěšný pohyb po neúspěchu dostane číslo 0001 — nic se nepropálilo.
        $this->receiveStock($supplierId, $whId, $itemId, '5.000', 10.0);
        $issueDraft = $this->documents->create($supplierId, [
            'doc_type'     => 'issue',
            'origin'       => 'manual',
            'warehouse_id' => $whId,
            'doc_date'     => '2099-03-02',
            'description'  => 'OK výdej',
            'lines'        => [['stock_item_id' => $itemId, 'qty' => '2.000']],
        ], $this->userId);
        $posted = $this->documents->post($supplierId, (int) $issueDraft['id'], $this->userId);
        self::assertStringEndsWith('-0001', (string) $posted['doc_number']);
    }

    // ── 9) převodka: hodnotová neutralita + nedostatek ──────────────────────

    public function testTransferIsValueNeutralAcrossWarehouses(): void
    {
        $supplierId = $this->createSupplier();
        $whA = $this->warehouse($supplierId, 'A');
        $whB = $this->warehouse($supplierId, 'B', false);
        $itemId = $this->item($supplierId, 'TR-1');

        $this->receiveStock($supplierId, $whA, $itemId, '10.000', 10.0);

        $draft = $this->documents->create($supplierId, [
            'doc_type'        => 'transfer',
            'origin'          => 'manual',
            'warehouse_id'    => $whA,
            'warehouse_to_id' => $whB,
            'doc_date'        => '2099-04-01',
            'description'     => 'Test převodka',
            'lines'           => [['stock_item_id' => $itemId, 'qty' => '4.000']],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $draft['id'], $this->userId);

        $levelA = $this->level($supplierId, $whA, $itemId);
        $levelB = $this->level($supplierId, $whB, $itemId);
        self::assertSame(6000, $levelA['qtyT']);
        self::assertSame(6000, $levelA['valueC']);
        self::assertSame(4000, $levelB['qtyT']);
        self::assertSame(4000, $levelB['valueC']);
        self::assertSame(10000, $levelA['valueC'] + $levelB['valueC'], 'Σ value obou skladů beze změny (převodka je hodnotově neutrální).');
    }

    public function testTransferOverAvailableFails409(): void
    {
        $supplierId = $this->createSupplier();
        $whA = $this->warehouse($supplierId, 'A');
        $whB = $this->warehouse($supplierId, 'B', false);
        $itemId = $this->item($supplierId, 'TR-2');
        $this->receiveStock($supplierId, $whA, $itemId, '3.000', 10.0);

        $draft = $this->documents->create($supplierId, [
            'doc_type'        => 'transfer',
            'origin'          => 'manual',
            'warehouse_id'    => $whA,
            'warehouse_to_id' => $whB,
            'doc_date'        => '2099-04-05',
            'description'     => 'Převodka nad rámec',
            'lines'           => [['stock_item_id' => $itemId, 'qty' => '9.000']],
        ], $this->userId);

        $this->expectExceptionStockErrorCode('insufficient_stock', function () use ($supplierId, $draft): void {
            $this->documents->post($supplierId, (int) $draft['id'], $this->userId);
        });
    }

    // ── 10) backdating: reprice pozdějších výdejů + storno převodky odmítnuto ─

    public function testBackdatedReceiptRepricesLaterIssueAndLevels(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'BD-1');

        $this->receiveStock($supplierId, $whId, $itemId, '10.000', 10.0, '2099-02-01');
        $issue = $this->issueManual($supplierId, $whId, $itemId, '5.000', '2099-02-05');
        self::assertSame('50.00', $issue['lines'][0]['value_total'], 'avg 10/ks × 5 ks = 50 Kč před backdatingem.');

        // Backdatovaný příjem PŘED oběma existujícími pohyby → replay přepočte
        // celou kartu chronologicky a přecení pozdější výdej.
        $backdated = $this->receiveStock($supplierId, $whId, $itemId, '10.000', 20.0, '2099-01-15');
        self::assertSame('posted', $backdated['status']);

        $level = $this->level($supplierId, $whId, $itemId);
        // 01-15: +10@20=200; 02-01: +10@10=100 → 20 ks / 300 Kč (avg 15);
        // 02-05: výdej 5 ks @ 15 = 75 → zbývá 15 ks / 225 Kč.
        self::assertSame(15000, $level['qtyT']);
        self::assertSame(22500, $level['valueC']);

        $repricedIssueLines = $this->docsRepo->lines($supplierId, (int) $issue['id']);
        self::assertSame('75.00', $repricedIssueLines[0]['value_total'], 'výdej se musí přecenit na nový klouzavý průměr (§3.2).');
        self::assertSame('15.000000', $repricedIssueLines[0]['unit_cost']);
    }

    public function testBackdatedReceiptRepricingExistingTransferIsRejected(): void
    {
        $supplierId = $this->createSupplier();
        $whA = $this->warehouse($supplierId, 'A');
        $whB = $this->warehouse($supplierId, 'B', false);
        $itemId = $this->item($supplierId, 'BD-2');

        $this->receiveStock($supplierId, $whA, $itemId, '10.000', 10.0, '2099-01-10');
        $transferDraft = $this->documents->create($supplierId, [
            'doc_type'        => 'transfer',
            'origin'          => 'manual',
            'warehouse_id'    => $whA,
            'warehouse_to_id' => $whB,
            'doc_date'        => '2099-01-15',
            'description'     => 'Převodka před backdatingem',
            'lines'           => [['stock_item_id' => $itemId, 'qty' => '4.000']],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $transferDraft['id'], $this->userId);

        $levelABefore = $this->level($supplierId, $whA, $itemId);
        $levelBBefore = $this->level($supplierId, $whB, $itemId);

        // Backdatovaný příjem na whA PŘED 01-10 změní průměr, kterým byla
        // převodka oceněna → replay musí converDku odmítnout (v1 nepodporuje
        // kaskádové přecenění cílové karty).
        $draft = $this->documents->create($supplierId, [
            'doc_type'     => 'receipt',
            'origin'       => 'manual',
            'warehouse_id' => $whA,
            'doc_date'     => '2099-01-05',
            'description'  => 'Backdatovaný příjem měnící ocenění převodky',
            'lines'        => [['stock_item_id' => $itemId, 'qty' => '10.000', 'unit_cost' => '20.000000']],
        ], $this->userId);

        $this->expectExceptionStockErrorCode('stock_backdate_transfer_unsupported', function () use ($supplierId, $draft): void {
            $this->documents->post($supplierId, (int) $draft['id'], $this->userId);
        });

        // Celá transakce (vč. nového dokladu) se musí vrátit — draft zůstává draft,
        // stavy obou skladů beze změny.
        $stillDraft = $this->docsRepo->find($supplierId, (int) $draft['id']);
        self::assertSame('draft', $stillDraft['status']);
        self::assertSame($levelABefore, $this->level($supplierId, $whA, $itemId));
        self::assertSame($levelBBefore, $this->level($supplierId, $whB, $itemId));
    }

    /**
     * Regrese CRITICAL 2 (review): storno-pár (výdej + protidoklad ve STEJNÉ
     * uložené hodnotě) musí zůstat hodnotově neutrální i po zpětném pohybu,
     * který by jinak změnil klouzavý průměr karty.
     */
    public function testReversalNeutralityUnderBackdate(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'BD-3');

        $this->receiveStock($supplierId, $whId, $itemId, '10.000', 10.0, '2099-01-10');
        $issue = $this->issueManual($supplierId, $whId, $itemId, '4.000', '2099-01-15');
        self::assertSame('40.00', $issue['lines'][0]['value_total']);

        $reversed = $this->documents->reverse($supplierId, (int) $issue['id'], [], $this->userId);
        self::assertSame('40.00', $reversed['reversal']['lines'][0]['value_total']);

        // Backdatovaný příjem PŘED 01-10 spustí plný replay karty.
        $backdated = $this->receiveStock($supplierId, $whId, $itemId, '5.000', 8.0, '2099-01-05');
        self::assertSame('posted', $backdated['status']);

        $level = $this->level($supplierId, $whId, $itemId);
        // 01-05 +5@8=40; 01-10 +10@10=100 → 15 ks/140 Kč; storno-pár (výdej −40 /
        // vratka +40) je transparentní → koncový stav = jen oba příjmy = 15 ks/140 Kč.
        self::assertSame(15000, $level['qtyT']);
        self::assertSame(14000, $level['valueC']);

        $issueLines = $this->docsRepo->lines($supplierId, (int) $issue['id']);
        $reversalLines = $this->docsRepo->lines($supplierId, (int) $reversed['reversal']['id']);
        self::assertSame('40.00', $issueLines[0]['value_total'], 'storno-výdej se replayem NESMÍ přecenit (fixní hodnota, §4.4).');
        self::assertSame('40.00', $reversalLines[0]['value_total']);
    }

    // ── 11) storno příjemky po výdejích → 409 (non-negative) ────────────────

    public function testReverseReceiptAfterIssuesFailsWhenItWouldGoNegative(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'ST-1');

        $receipt = $this->receiveStock($supplierId, $whId, $itemId, '10.000', 10.0, '2099-01-10');
        $this->issueManual($supplierId, $whId, $itemId, '8.000', '2099-01-12');
        // Zbývá jen 2 ks — storno příjemky (vrátit 10 ks výdejem) by šlo do minusu.

        $this->expectExceptionStockErrorCode('insufficient_stock', function () use ($supplierId, $receipt): void {
            $this->documents->reverse($supplierId, (int) $receipt['id'], [], $this->userId);
        });

        $stillPosted = $this->docsRepo->find($supplierId, (int) $receipt['id']);
        self::assertSame('posted', $stillPosted['status'], 'neúspěšné storno nesmí originál označit jako reversed.');
    }

    // ── 17) tenant izolace ────────────────────────────────────────────────

    public function testCrossTenantAccessToWarehouseItemAndDocumentDoesNotLeak(): void
    {
        $supplierA = $this->createSupplier();
        $supplierB = $this->createSupplier();

        $whA = $this->warehouse($supplierA);
        $itemA = $this->item($supplierA, 'TEN-1');
        $doc = $this->receiveStock($supplierA, $whA, $itemA, '3.000', 10.0);

        self::assertNull($this->warehousesRepo->find($supplierB, $whA), 'cizí sklad nesmí být vidět z jiného supplieru.');
        self::assertNull($this->itemsRepo->find($supplierB, $itemA), 'cizí karta nesmí být vidět z jiného supplieru.');
        self::assertNull($this->docsRepo->find($supplierB, (int) $doc['id']), 'cizí doklad nesmí být vidět z jiného supplieru.');

        $foreignLevel = $this->level($supplierB, $whA, $itemA);
        self::assertSame(0, $foreignLevel['qtyT'], 'čtení stavu cizí (neexistující v rámci supplieru B) dvojice vrací 0, ne cizí data.');
    }

    // ── B10: deaktivovaná karta ──────────────────────────────────────────────

    public function testInactiveCardBlocksNewDraftLineButExistingDraftStillPosts(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'INA-1');

        $draft = $this->documents->create($supplierId, [
            'doc_type'     => 'receipt',
            'origin'       => 'manual',
            'warehouse_id' => $whId,
            'doc_date'     => '2099-05-01',
            'description'  => 'Draft před deaktivací',
            'lines'        => [['stock_item_id' => $itemId, 'qty' => '2.000', 'unit_cost' => '10.000000']],
        ], $this->userId);

        $this->itemsRepo->deactivate($supplierId, $itemId);

        // Nový řádek na NOVÉM draftu s neaktivní kartou → 422 (B10).
        try {
            $this->documents->create($supplierId, [
                'doc_type'     => 'receipt',
                'origin'       => 'manual',
                'warehouse_id' => $whId,
                'doc_date'     => '2099-05-02',
                'description'  => 'Nový draft s neaktivní kartou',
                'lines'        => [['stock_item_id' => $itemId, 'qty' => '1.000', 'unit_cost' => '10.000000']],
            ], $this->userId);
            self::fail('create() s neaktivní kartou měl vyhodit invalid_document.');
        } catch (StockException $e) {
            self::assertSame('invalid_document', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }

        // Zaúčtování EXISTUJÍCÍHO draftu s (mezitím deaktivovanou) kartou projde,
        // jen s warningem.
        $posted = $this->documents->post($supplierId, (int) $draft['id'], $this->userId);
        self::assertSame('posted', $posted['status']);
        self::assertNotEmpty($posted['warnings']);
    }

    // ── desetinná místa množství podle jednotky karty (2036) ────────────────

    /**
     * BEZ ZMĚNY PADÁ: ručně zadaný doklad uložil 1,5 ks, i když jednotka dovoluje
     * jen celé kusy. Kontrola platí jen pro ruční zadání (`checkUnitDecimals`),
     * automatické doklady z faktur, importy a převody se nemění.
     */
    public function testManualDraftRejectsQuantityOverUnitDecimals(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'DEC-1');
        $code = 'sd' . substr(bin2hex(random_bytes(4)), 0, 6);
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO units (code, label_cs, label_en, quantity_decimals) VALUES (?, ?, ?, 0)')
            ->execute([$code, $code, $code]);
        $pdo->prepare('UPDATE stock_items SET unit = ? WHERE id = ?')->execute([strtoupper($code), $itemId]);

        try {
            $body = [
                'doc_type'     => 'receipt',
                'origin'       => 'manual',
                'warehouse_id' => $whId,
                'doc_date'     => '2099-05-03',
                'description'  => 'Příjem po kusech',
                'lines'        => [
                    ['stock_item_id' => $itemId, 'qty' => '2.000', 'unit_cost' => '10'],
                    ['stock_item_id' => $itemId, 'qty' => '1.5', 'unit_cost' => '10'],
                ],
            ];
            try {
                $this->documents->create($supplierId, $body, $this->userId, checkUnitDecimals: true);
                self::fail('create() s 1,5 ks měl vyhodit quantity_decimals.');
            } catch (StockException $e) {
                self::assertSame('quantity_decimals', $e->errorCode);
                self::assertSame(400, $e->httpStatus);
                self::assertSame([['index' => 1, 'unit' => strtoupper($code), 'decimals' => 0]], $e->details);
            }

            // Automatické cesty (výdejka k faktuře, import) kontrolu nemají, beze změny.
            $auto = $this->documents->create($supplierId, $body, $this->userId);
            self::assertSame('draft', $auto['status']);

            // Úprava draftu: poslané řádky se kontrolují, vynechané (uložené) ne.
            try {
                $this->documents->updateDraft($supplierId, (int) $auto['id'], ['lines' => $body['lines']], $this->userId, checkUnitDecimals: true);
                self::fail('updateDraft() s 1,5 ks měl vyhodit quantity_decimals.');
            } catch (StockException $e) {
                self::assertSame('quantity_decimals', $e->errorCode);
            }
            $kept = $this->documents->updateDraft($supplierId, (int) $auto['id'], ['description' => 'Jen popis'], $this->userId, checkUnitDecimals: true);
            self::assertSame('Jen popis', $kept['description']);

            $ok = $this->documents->create($supplierId, array_merge($body, [
                'lines' => [['stock_item_id' => $itemId, 'qty' => '3.000', 'unit_cost' => '10']],
            ]), $this->userId, checkUnitDecimals: true);
            self::assertSame('3.000', $ok['lines'][0]['qty']);
        } finally {
            $pdo->prepare('DELETE FROM units WHERE code = ?')->execute([$code]);
        }
    }

    // ── 2) souběh na poslední kusy (B3) ──────────────────────────────────────

    /**
     * Ověřuje CONTRACT lock-orderu B3 na poslední jednotce: dvě soupeřící
     * výdejky o stejnou (poslední) jednotku karty nesmí nikdy obě uspět a stav
     * nesmí nikdy klesnout pod nulu. Druhé reálné DB spojení (samostatný
     * Bootstrap::buildApp() container → vlastní PDO) ověřuje navíc, že čtení
     * pod READ COMMITTED vidí ČERSTVĚ commitnutý stav napříč spojeními
     * (review CRITICAL 1), ne stale snapshot.
     *
     * POZNÁMKA K ROZSAHU: skutečný multi-vláknový race (dvě transakce
     * blokující se navzájem přes FOR UPDATE + reálný deadlock-retry) by
     * vyžadoval multi-procesový harness (na Windows bez pcntl nedostupný
     * v tomto repu) — tenhle test ověřuje výsledný KONTRAKT (vzájemná
     * exkluzivita + čistá 409 + žádný záporný/nekonzistentní stav) sekvenčně
     * přes dvě nezávislá DB spojení, ne skutečné souběžné blokování.
     */
    public function testLastUnitRaceContractAcrossTwoConnections(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'RACE-1');
        $this->receiveStock($supplierId, $whId, $itemId, '1.000', 100.0);

        // Nesdílená zóna: testovací běh jinak recykluje jedno PDO přes všechny Connection,
        // takže by obě „strany" race seděly v téže DB session a kontrakt napříč spojeními
        // by se vůbec neměřil. Assert pod tím to hlídá.
        // Connection MUSÍ vzniknout uvnitř zóny — rozhodnutí „smím sdílet PDO?" padá
        // v jeho konstruktoru. Od chvíle, kdy se middleware resolvují líně (deferred),
        // by ho samotné buildApp() nevytvořilo a konstrukce by spadla až za zónu.
        $container2 = Connection::withoutSharedTestConnection(static function () {
            $c = Bootstrap::buildContainer();
            $c->get(Connection::class);

            return $c;
        });
        /** @var Connection $db2 */
        $db2 = $container2->get(Connection::class);
        self::assertNotSame($this->db->pdo(), $db2->pdo(), 'test vyžaduje dvě nezávislá DB spojení.');
        /** @var StockDocumentService $documents2 */
        $documents2 = $container2->get(StockDocumentService::class);

        $draftA = $this->documents->create($supplierId, [
            'doc_type'     => 'issue',
            'origin'       => 'manual',
            'warehouse_id' => $whId,
            'doc_date'     => '2099-06-01',
            'description'  => 'Race A',
            'lines'        => [['stock_item_id' => $itemId, 'qty' => '1.000']],
        ], $this->userId);
        $draftB = $documents2->create($supplierId, [
            'doc_type'     => 'issue',
            'origin'       => 'manual',
            'warehouse_id' => $whId,
            'doc_date'     => '2099-06-01',
            'description'  => 'Race B',
            'lines'        => [['stock_item_id' => $itemId, 'qty' => '1.000']],
        ], $this->userId);

        $postedA = $this->documents->post($supplierId, (int) $draftA['id'], $this->userId);
        self::assertSame('posted', $postedA['status'], 'první výdejka na poslední kus musí uspět.');

        // Druhé spojení musí přes READ COMMITTED vidět ČERSTVĚ commitnutý
        // nulový stav (ne stale snapshot) a dostat čistou 409.
        try {
            $documents2->post($supplierId, (int) $draftB['id'], $this->userId);
            self::fail('druhá výdejka na stejnou (už vyčerpanou) poslední jednotku měla dostat insufficient_stock.');
        } catch (StockException $e) {
            self::assertSame('insufficient_stock', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }

        $level = $this->level($supplierId, $whId, $itemId);
        self::assertSame(0, $level['qtyT'], 'konečný stav nesmí být záporný ani nekonzistentní.');
        self::assertGreaterThanOrEqual(0, $level['valueC']);

        $loserDoc = $this->docsRepo->find($supplierId, (int) $draftB['id']);
        self::assertSame('draft', $loserDoc['status'], 'prohraná výdejka zůstává draft, žádný osiřelý posted doklad.');

        $db2->close();
    }

    // ── pomocníci ────────────────────────────────────────────────────────────

    /** @return array<string,mixed> zaúčtovaný výdej */
    private function issueManual(int $supplierId, int $warehouseId, int $stockItemId, string $qty, string $docDate): array
    {
        $draft = $this->documents->create($supplierId, [
            'doc_type'     => 'issue',
            'origin'       => 'manual',
            'warehouse_id' => $warehouseId,
            'doc_date'     => $docDate,
            'description'  => 'Test výdej',
            'lines'        => [['stock_item_id' => $stockItemId, 'qty' => $qty]],
        ], $this->userId);
        return $this->documents->post($supplierId, (int) $draft['id'], $this->userId);
    }

    private function expectExceptionStockErrorCode(string $errorCode, callable $fn): void
    {
        try {
            $fn();
            self::fail("očekávaná StockException('$errorCode') nebyla vyhozena.");
        } catch (StockException $e) {
            self::assertSame($errorCode, $e->errorCode);
        }
    }
}
