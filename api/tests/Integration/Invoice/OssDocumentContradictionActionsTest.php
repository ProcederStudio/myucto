<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\BulkReissueAction;
use MyInvoice\Action\Invoice\CancelInvoiceAction;
use MyInvoice\Action\Invoice\CreateInvoiceAction;
use MyInvoice\Action\Invoice\GetInvoiceAction;
use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Support\PaymentMethods;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * SOUDRŽNOST DOKLADU a přenos příznaku „k ručnímu posouzení" na cestách, které NEJSOU
 * import: ruční zadání z editoru, hromadné klonování a dobropis.
 *
 * Review našla tři díry jedné příčiny — kontrola i příznak žily jen v importu:
 *
 *   1. `CreateInvoiceAction` / `UpdateInvoiceAction` vyrobily doklad rozpadlý mezi OSS
 *      podání a tuzemské přiznání úplně tiše (kontrola byla privátní metoda uvnitř
 *      `InvoiceImportService`, viz {@see \MyInvoice\Service\Oss\OssDocumentCoherence}).
 *   2. `BulkReissueAction` příznak neklonoval — klon označeného dokladu byl zase tichý,
 *      a to zrovna u dokladů, které se opakují každý měsíc.
 *   3. `CancelInvoiceAction` ho nepřenášel na dobropis, takže na opravném dokladu zhasl.
 *
 * Každý test je proto psaný tak, aby BEZ OPRAVY PADAL: 1. na chybějícím varování
 * i příznaku, 2. a 3. na nule ve zkopírovaném řádku.
 *
 * ── Proč tenhle test NEBĚŽÍ v obalové transakci ──────────────────────────────────────
 * Akce končí přepočtem revenue cache, který si otevírá VLASTNÍ transakci, a PDO nad
 * MariaDB vnořenou transakci neumí. Uklízí se proto ručně — stejně jako
 * {@see OssManualReviewEditorApiTest}. Data jsou syntetická; tearDown maže přesně to,
 * co setUp a testy založily.
 */
#[Group('integration')]
final class OssDocumentContradictionActionsTest extends TestCase
{
    private const TAX_DATE = '2096-05-15';
    private const DUE_DATE = '2096-06-15';

    private Connection $db;
    private CreateInvoiceAction $create;
    private UpdateInvoiceAction $update;
    private GetInvoiceAction $get;
    private BulkReissueAction $reissue;
    private CancelInvoiceAction $cancel;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $ossVatRateId = 0;
    private int $domesticVatRateId = 0;
    private bool $vatRateCreated = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db      = $c->get(Connection::class);
            $this->create  = $c->get(CreateInvoiceAction::class);
            $this->update  = $c->get(UpdateInvoiceAction::class);
            $this->get     = $c->get(GetInvoiceAction::class);
            $this->reissue = $c->get(BulkReissueAction::class);
            $this->cancel  = $c->get(CancelInvoiceAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        if (!$this->db->hasColumn('invoice_items', 'oss_needs_manual_review')) {
            $this->markTestSkipped('Chybí migrace 1293.');
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier / users).');
        }

        $this->currencyId = $this->currency();
        $this->clientId = $this->client();
        $this->ossVatRateId = $this->consumerVatRate('PL', 23.0);
        $this->domesticVatRateId = $this->domesticVatRate();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();

        if ($this->clientId > 0) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE client_id = ?)')
                ->execute([$this->clientId]);
            // Nejdřív navázané doklady (dobropis drží parent_invoice_id na původní faktuře),
            // teprve pak zbytek — jinak by mazání spadlo na cizím klíči.
            $pdo->prepare('DELETE FROM invoices WHERE client_id = ? AND parent_invoice_id IS NOT NULL')
                ->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM invoices WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM client_revenue_cache WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        if ($this->vatRateCreated && $this->ossVatRateId > 0) {
            $pdo->prepare('DELETE FROM vat_rates WHERE id = ?')->execute([$this->ossVatRateId]);
        }

        $this->db->close();
    }

    // ── 0. textový řádek ────────────────────────────────────────────────────

    /**
     * Textový řádek (item_kind 'text') není plnění. Na čistě OSS dokladu ho derivace
     * místa plnění nesmí přepnout do OSS a kontrola soudržnosti ho nesmí brát jako
     * tuzemský řádek: doklad by jinak dostal falešné varování a příznak k posouzení.
     */
    public function testTextRowOnOssDocumentIsNeitherOssNorContradiction(): void
    {
        $created = $this->post($this->payload([
            $this->ossItem(),
            ['item_kind' => 'text', 'description' => 'Zásilka č. 1', 'vat_rate_id' => $this->domesticVatRateId, 'order_index' => 1],
        ]));

        self::assertSame(201, $created['status'], json_encode($created['body']));
        $id = (int) $created['body']['id'];
        self::assertSame([], $created['body']['_warnings'] ?? [], 'Žádné varování o smíšeném dokladu.');
        self::assertSame([0, 0], $this->storedFlags($id), 'Žádný řádek k ručnímu posouzení.');
        $stmt = $this->db->pdo()->prepare('SELECT item_kind, oss_applicable FROM invoice_items WHERE invoice_id = ? ORDER BY order_index, id');
        $stmt->execute([$id]);
        self::assertSame([['standard', 1], ['text', 0]], array_map(
            static fn (array $r): array => [(string) $r['item_kind'], (int) $r['oss_applicable']],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    // ── 1. ruční zadání a editor ─────────────────────────────────────────────

    /**
     * DÍRA 1: doklad rozpadlý mezi OSS a tuzemské přiznání zadaný RUKOU.
     *
     * BEZ OPRAVY PADÁ: odpověď neobsahovala žádné varování a oba řádky měly příznak 0 —
     * kontrola existovala jen v importu.
     *
     * Uložení se NEBLOKUJE schválně: smíšený doklad je legitimní (tuzemské plnění
     * + zásilka do jiného členského státu na jedné faktuře) a 400 na ruční zadání by
     * uživatele poslalo naimportovat totéž jinudy, kde stejná situace projde s varováním.
     */
    public function testContradictoryDocumentSavedFromTheEditorWarnsAndFlagsBothLines(): void
    {
        $created = $this->post($this->payload([$this->ossItem(), $this->domesticItem()]));

        self::assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
        self::assertContains('oss_document_contradiction', $created['body']['_warnings'] ?? [],
            'Doklad ve dvou přiznáních se uložil bez jediného slova.');

        $meta = $created['body']['_warning_meta']['oss_document_contradiction'] ?? [];
        self::assertSame(['PL'], $meta['consumer_countries'] ?? null);
        self::assertSame(2, $meta['affected_items'] ?? null);
        self::assertStringContainsString('K RUČNÍMU POSOUZENÍ', (string) ($meta['message'] ?? ''));

        self::assertSame([1, 1], $this->storedFlags((int) $created['body']['id']),
            'Označit se musí obě strany rozporu — tuzemský řádek je ten, který má člověk prověřit.');
    }

    /**
     * Příznak se počítá při KAŽDÉM uložení, ne jen při prvním. Payload editoru u ne-OSS
     * řádku posílá `false` (tak to dělá `InvoiceEditor.vue`), takže kdyby se kontrola
     * pouštěla jen při založení, druhé uložení téhož dokladu by tuzemskou stranu odznačilo.
     */
    public function testResavingTheContradictoryDocumentKeepsBothLinesFlagged(): void
    {
        $id = (int) $this->post($this->payload([$this->ossItem(), $this->domesticItem()]))['body']['id'];

        $resaved = $this->put($id, $this->payload([$this->ossItem(), $this->domesticItem()]));

        self::assertSame(200, $resaved['status'], json_encode($resaved['body'], JSON_UNESCAPED_UNICODE));
        self::assertContains('oss_document_contradiction', $resaved['body']['_warnings'] ?? []);
        self::assertSame([1, 1], $this->storedFlags($id));
    }

    /**
     * A druhá strana téhož: když uživatel rozpor OPRAVÍ (celý doklad zůstane tuzemský),
     * varování zmizí a tuzemské řádky se odznačí. Příznak, který nejde zhasnout, by
     * uživatele naučil ignorovat ho.
     */
    public function testFixingTheDocumentClearsTheWarningAndTheFlag(): void
    {
        $id = (int) $this->post($this->payload([$this->ossItem(), $this->domesticItem()]))['body']['id'];
        self::assertSame([1, 1], $this->storedFlags($id), 'Předpoklad testu: doklad je označený.');

        $fixed = $this->put($id, $this->payload([$this->domesticItem(), $this->domesticItem()]));

        self::assertSame(200, $fixed['status'], json_encode($fixed['body'], JSON_UNESCAPED_UNICODE));
        self::assertNotContains('oss_document_contradiction', $fixed['body']['_warnings'] ?? []);
        self::assertSame([0, 0], $this->storedFlags($id));
    }

    // ── 2. hromadné klonování ────────────────────────────────────────────────

    /**
     * DÍRA 2: `BulkReissueAction` příznak neklonoval.
     *
     * BEZ OPRAVY PADÁ: klon měl na obou řádcích 0, protože INSERT sloupec vůbec neuváděl.
     * Klon je přitom kopie dokladu i s jeho nejistotou — přeúčtováním do dalšího měsíce
     * se sporné místo plnění nevyřeší.
     */
    public function testCloneKeepsTheManualReviewFlag(): void
    {
        $sourceId = (int) $this->post($this->payload([$this->ossItem(), $this->domesticItem()]))['body']['id'];
        self::assertSame([1, 1], $this->storedFlags($sourceId), 'Předpoklad testu: zdroj je označený.');

        $cloneId = $this->reissue->cloneOne($sourceId, self::TAX_DATE, false, $this->userId);

        self::assertSame([1, 1], $this->storedFlags($cloneId),
            'Klon označeného dokladu je zase tichý — kontrola po prvním hromadném klonování zmizí.');
    }

    // ── 3. dobropis ──────────────────────────────────────────────────────────

    /**
     * DÍRA 3: dobropis příznak nepřenášel.
     *
     * BEZ OPRAVY PADÁ: opravný doklad měl 0. Dobropis přitom jde do TÉHOŽ přiznání jako
     * opravovaná faktura — je-li sporné místo plnění u ní, je sporné i u něj, jen se
     * záporným znaménkem.
     */
    public function testCreditNoteInheritsTheManualReviewFlag(): void
    {
        $timeItem = $this->domesticItem();
        $timeItem['quantity'] = 0.5;
        $timeItem['duration_minutes'] = 30;
        $timeItem['unit'] = 'h';
        $timeItem['unit_price_without_vat'] = 1000.123456;
        $sourceId = (int) $this->post($this->payload([$this->ossItem(), $timeItem]))['body']['id'];
        self::assertSame([1, 1], $this->storedFlags($sourceId), 'Předpoklad testu: zdroj je označený.');
        $this->markIssued($sourceId);

        $response = ($this->cancel)(
            $this->request('POST', ['mode' => 'credit_note', 'reason' => 'PHPUnit']),
            new Psr7Response(),
            ['id' => (string) $sourceId],
        );
        $decoded = self::decode($response);

        self::assertSame(201, $decoded['status'], json_encode($decoded['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame([1, 1], $this->storedFlags((int) $decoded['body']['credit_note_id']),
            'Na opravném dokladu příznak zhasl — v náhledu podání zůstane označená jen kladná polovina opravy.');

        $item = $this->storedItem((int) $decoded['body']['credit_note_id'], 1);
        self::assertSame(-30, (int) $item['duration_minutes']);
        self::assertSame(-0.5, (float) $item['quantity']);
        self::assertSame(1000.123456, (float) $item['unit_price_without_vat']);
    }

    // ── payload ──────────────────────────────────────────────────────────────

    /**
     * OSS řádek do Polska. Příznak se ZÁMĚRNĚ posílá `false` — tvrzením testu je, že si
     * ho doplní backend z kontroly soudržnosti, ne že ho payload donese.
     *
     * @return array<string,mixed>
     */
    private function ossItem(): array
    {
        return [
            'description' => 'TEST OSS položka (PHPUnit)',
            'quantity' => 1,
            'unit' => 'ks',
            'unit_price_without_vat' => 1000,
            'vat_rate_id' => $this->ossVatRateId,
            'order_index' => 0,
            'oss_applicable' => true,
            'oss_consumer_country' => 'PL',
            'oss_rate_type' => 'standard',
            'oss_supply_type' => 'goods',
            'oss_needs_manual_review' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function domesticItem(): array
    {
        return [
            'description' => 'TEST tuzemská položka (PHPUnit)',
            'quantity' => 1,
            'unit' => 'ks',
            'unit_price_without_vat' => 500,
            'vat_rate_id' => $this->domesticVatRateId,
            'order_index' => 1,
            'oss_applicable' => false,
            'oss_consumer_country' => null,
            'oss_rate_type' => null,
            'oss_supply_type' => null,
            'oss_needs_manual_review' => false,
        ];
    }

    /**
     * @param  list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function payload(array $items): array
    {
        return [
            'invoice_type' => 'invoice',
            'client_id' => $this->clientId,
            'issue_date' => self::TAX_DATE,
            'tax_date' => self::TAX_DATE,
            'due_date' => self::DUE_DATE,
            'currency_id' => $this->currencyId,
            'reverse_charge' => false,
            'prices_include_vat' => false,
            'payment_method' => PaymentMethods::DEFAULT,
            'language' => 'cs',
            'items' => array_values($items),
        ];
    }

    // ── HTTP ─────────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function post(array $body): array
    {
        return self::decode(($this->create)($this->request('POST', $body), new Psr7Response()));
    }

    /**
     * @param  array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function put(int $id, array $body): array
    {
        return self::decode(($this->update)($this->request('PUT', $body), new Psr7Response(), ['id' => (string) $id]));
    }

    /** @param array<string,mixed> $body */
    private function request(string $method, array $body = []): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/invoices')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);

        return $body !== [] ? $request->withParsedBody($body) : $request;
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return [
            'status' => $response->getStatusCode(),
            'body' => is_array($decoded) ? $decoded : [],
        ];
    }

    // ── data ─────────────────────────────────────────────────────────────────

    /** @return list<int> příznaky položek v pořadí, ve kterém jsou na dokladu */
    private function storedFlags(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT oss_needs_manual_review FROM invoice_items
              WHERE invoice_id = ? ORDER BY order_index, id'
        );
        $stmt->execute([$invoiceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        self::assertNotEmpty($rows, 'Doklad nemá ani jednu položku — pak netvrdí nic ani zbytek testu.');

        return array_map(intval(...), $rows);
    }

    /** @return array<string,mixed> */
    private function storedItem(int $invoiceId, int $orderIndex): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT quantity, duration_minutes, unit_price_without_vat FROM invoice_items
              WHERE invoice_id = ? AND order_index = ? LIMIT 1'
        );
        $stmt->execute([$invoiceId, $orderIndex]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** Dobropis lze vystavit jen k VYSTAVENÉ faktuře — číslo musí být unikátní v řadě. */
    private function markIssued(int $invoiceId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE invoices SET status = "issued", varsymbol = ? WHERE id = ?'
        )->execute(['T96' . str_pad((string) $invoiceId, 8, '0', STR_PAD_LEFT), $invoiceId]);
    }

    /** Měna se REUSUJE — zakládat druhou CZK stejnému dodavateli by rozbilo `is_default`. */
    private function currency(): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM currencies WHERE supplier_id = ? AND is_active = 1
              ORDER BY (code = 'CZK') DESC, is_default DESC, id LIMIT 1"
        );
        $stmt->execute([$this->supplierId]);
        $id = (int) $stmt->fetchColumn();
        if ($id === 0) {
            self::markTestSkipped('Dodavatel nemá aktivní měnu.');
        }

        return $id;
    }

    private function client(): int
    {
        $pdo = $this->db->pdo();
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'PL' LIMIT 1")->fetchColumn() ?: 0);
        if ($countryId === 0) {
            self::markTestSkipped('Stát PL není v číselníku zemí.');
        }

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Testowy Odbiorca sp. z o.o.", "Ulica 1", "Warszawa", "00-001", ?,
                     "odberatel@example.test", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $countryId, $this->currencyId]);

        return (int) $pdo->lastInsertId();
    }

    /** Sazba státu spotřeby v globální `vat_rates` — zakládá ji uživatel, tady tedy test. */
    private function consumerVatRate(string $country, float $percent): int
    {
        $code = strtoupper($country) . '-' . rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');
        $pdo = $this->db->pdo();

        $probe = $pdo->prepare('SELECT id FROM vat_rates WHERE code = ?');
        $probe->execute([$code]);
        $this->vatRateCreated = ((int) $probe->fetchColumn()) === 0;

        $pdo->prepare(
            'INSERT INTO vat_rates (code, rate_percent, country, label_cs, label_en, is_default,
                                    is_reverse_charge, valid_from, valid_to, display_order)
             VALUES (?, ?, ?, ?, ?, 0, 0, "2090-01-01", NULL, 900)
             ON DUPLICATE KEY UPDATE rate_percent = VALUES(rate_percent), country = VALUES(country),
                                     valid_from = VALUES(valid_from), valid_to = VALUES(valid_to)'
        )->execute([$code, $percent, strtoupper($country), $code, $code]);

        $stmt = $pdo->prepare('SELECT id FROM vat_rates WHERE code = ?');
        $stmt->execute([$code]);

        return (int) $stmt->fetchColumn();
    }

    /** Tuzemská sazba platná k DUZP — druhá strana rozporu. */
    private function domesticVatRate(): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM vat_rates
              WHERE country = 'CZ' AND is_reverse_charge = 0 AND rate_percent > 0
                AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)
           ORDER BY rate_percent DESC LIMIT 1"
        );
        $stmt->execute([self::TAX_DATE, self::TAX_DATE]);
        $id = (int) $stmt->fetchColumn();
        if ($id === 0) {
            self::markTestSkipped('Číselník sazeb DPH nemá tuzemskou sazbu k ' . self::TAX_DATE . '.');
        }

        return $id;
    }
}
