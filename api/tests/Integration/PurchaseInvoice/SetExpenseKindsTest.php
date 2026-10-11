<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\DismissExtractionWarningAction;
use MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceExpenseKindsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Import\AiExpenseKindProposal;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Repository\AccountingPeriodRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * PUT /api/purchase-invoices/{id}/expense-kinds — kontrolní okno po AI importu potvrzuje
 * druh nákladu po řádcích, i u dokladu, který import rovnou označil jako zaplacený.
 *
 * Vše v transakci s rollbackem; data jsou syntetická.
 */
#[Group('integration')]
final class SetExpenseKindsTest extends TestCase
{
    private const ISSUE_DATE = '2096-04-10';

    private Connection $db;
    private CreatePurchaseInvoiceAction $create;
    private SetPurchaseInvoiceExpenseKindsAction $setKinds;
    private DismissExtractionWarningAction $dismiss;
    private PurchaseInvoiceRepository $repo;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db       = $c->get(Connection::class);
            $this->create   = $c->get(CreatePurchaseInvoiceAction::class);
            $this->setKinds = $c->get(SetPurchaseInvoiceExpenseKindsAction::class);
            $this->dismiss  = $c->get(DismissExtractionWarningAction::class);
            $this->repo     = $c->get(PurchaseInvoiceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId             = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0 || $this->vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
        $this->currencyId = (int) ($pdo->query(
            "SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND is_active = 1
              ORDER BY (code = 'CZK') DESC, is_default DESC, id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($this->currencyId === 0) {
            $this->markTestSkipped('Dodavatel nemá aktivní měnu.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, language, currency_default_id, is_vendor, is_vat_payer)
             VALUES (?, "TEST expense kinds dodavatel (PHPUnit)", "Testovaci 1", "Praha", "11000", ?,
                     "expense-kinds-vendor@example.test", "cs", ?, 1, 1)'
        )->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->vendorId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testSetsKindsAndClearsOnlyAutomatedAccount(): void
    {
        [$id, $auto, $manual] = $this->createInvoice();
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE purchase_invoice_items SET expense_kind = 'material', expense_account_code = '501100',
                          expense_classification_source = 'rule', expense_rule_id = 7 WHERE id = ?")->execute([$auto]);
        $pdo->prepare("UPDATE purchase_invoice_items SET expense_account_code = '548100' WHERE id = ?")->execute([$manual]);

        $res = $this->put($id, ['items' => [
            ['id' => $auto, 'expense_kind' => 'fixed_asset'],
            ['id' => $manual, 'expense_kind' => 'service'],
        ]]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        $rows = $this->items($id);
        self::assertSame(['fixed_asset', '1', null, null, null], [
            $rows[$auto]['expense_kind'], (string) $rows[$auto]['is_fixed_asset'], $rows[$auto]['expense_account_code'],
            $rows[$auto]['expense_classification_source'], $rows[$auto]['expense_rule_id'],
        ], 'Účet dosazený automatem musí s ruční volbou druhu zmizet, jinak by ji přebil.');
        self::assertSame(['service', '0', '548100'], [
            $rows[$manual]['expense_kind'], (string) $rows[$manual]['is_fixed_asset'], $rows[$manual]['expense_account_code'],
        ], 'Ručně zvolený účet zůstává.');
    }

    public function testNullClearsKind(): void
    {
        [$id, $item] = $this->createInvoice();
        $this->put($id, ['items' => [['id' => $item, 'expense_kind' => 'service']]]);

        $res = $this->put($id, ['items' => [['id' => $item, 'expense_kind' => null]]]);
        self::assertSame(200, $res['status']);
        self::assertNull($this->items($id)[$item]['expense_kind']);
    }

    public function testExplicitAccountIsSavedWithUnchangedKindAndCleared(): void
    {
        [$id, $item] = $this->createInvoice();
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, is_active)
            VALUES (?, '518.987651', 'TEST hosting analytic', 'expense', 'debit', 0, 1)
            ON DUPLICATE KEY UPDATE is_active = 1")->execute([$this->supplierId]);
        $pdo->prepare("UPDATE purchase_invoice_items SET expense_kind = 'service', expense_classification_source = 'keyword' WHERE id = ?")->execute([$item]);

        $res = $this->put($id, ['items' => [['id' => $item, 'expense_kind' => 'service', 'expense_account_code' => '518.987651']]]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('518.987651', $this->items($id)[$item]['expense_account_code']);
        self::assertNull($this->items($id)[$item]['expense_classification_source']);

        $res = $this->put($id, ['items' => [['id' => $item, 'expense_kind' => 'service', 'expense_account_code' => null]]]);
        self::assertSame(200, $res['status']);
        self::assertNull($this->items($id)[$item]['expense_account_code']);
    }

    public function testRejectsInvalidExpenseAccountWithoutChangingKind(): void
    {
        [$id, $item] = $this->createInvoice();
        $this->db->pdo()->prepare("INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, is_active)
            VALUES (?, '518.987652', 'TEST inactive analytic', 'expense', 'debit', 0, 0)
            ON DUPLICATE KEY UPDATE is_active = 0")->execute([$this->supplierId]);
        foreach (['518.987652', '518.987653', '321', ['518.987651']] as $account) {
            $res = $this->put($id, ['items' => [['id' => $item, 'expense_kind' => 'service', 'expense_account_code' => $account]]]);
            self::assertSame(400, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
            self::assertNull($this->items($id)[$item]['expense_kind']);
        }
    }

    public function testRejectsAccountBelongingOnlyToAnotherTenant(): void
    {
        [$id, $item] = $this->createInvoice();
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
            SELECT "TEST foreign account tenant", "Testovací", "Praha", "11000", country_id,
                "foreign-expense-account@example.test", default_currency_id, default_vat_rate_id FROM supplier WHERE id = ?')
            ->execute([$this->supplierId]);
        $foreignSupplierId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, is_active)
            VALUES (?, '518.987654', 'TEST foreign analytic', 'expense', 'debit', 0, 1)")->execute([$foreignSupplierId]);

        $res = $this->put($id, ['items' => [['id' => $item, 'expense_kind' => 'service', 'expense_account_code' => '518.987654']]]);
        self::assertSame(400, $res['status']);
        self::assertNull($this->items($id)[$item]['expense_account_code']);
        self::assertNull($this->items($id)[$item]['expense_kind']);
    }

    public function testAccountOnlyChangeRepostsExistingJournalWithAnalytic(): void
    {
        [$id, $item] = $this->createInvoice();
        $pdo = $this->db->pdo();
        $container = Bootstrap::buildContainer();
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', accounting_enabled = 1 WHERE id = ?")
            ->execute([$this->supplierId]);
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $periods = $container->get(AccountingPeriodRepository::class);
        if ($periods->findForDate($this->supplierId, self::ISSUE_DATE) === null) {
            $periods->create($this->supplierId, 2096, '2096-01-01', '2096-12-31');
        }
        $pdo->prepare("INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, is_active)
            VALUES (?, '518.987650', 'TEST original analytic', 'expense', 'debit', 0, 1),
                   (?, '518.987651', 'TEST hosting analytic', 'expense', 'debit', 0, 1)
            ON DUPLICATE KEY UPDATE is_active = 1")->execute([$this->supplierId, $this->supplierId]);
        $pdo->prepare("UPDATE purchase_invoice_items SET expense_kind = 'service', expense_account_code = '518.987650'
            WHERE purchase_invoice_id = ?")->execute([$id]);
        $pdo->prepare("UPDATE purchase_invoices SET status = 'received' WHERE id = ?")->execute([$id]);
        $posting = $container->get(PostingService::class);
        $entryId = $posting->postDocument($this->supplierId, 'purchase_invoice', $id,
            $posting->buildFromPurchaseInvoice($this->supplierId, $id), [
                'entry_date' => self::ISSUE_DATE, 'posted' => true, 'posted_by' => $this->userId,
            ]);
        $request = $this->request('PUT', ['items' => [[
            'id' => $item, 'expense_kind' => 'service', 'expense_account_code' => '518.987651',
        ]]])->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
        $res = self::decode(($this->setKinds)($request, new Psr7Response(), ['id' => (string) $id]));

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame($entryId, $res['body']['_repost']['entry_id'] ?? null);
        self::assertSame('service', $this->items($id)[$item]['expense_kind']);
        $stmt = $pdo->prepare('SELECT coa.account_code, SUM(jel.amount) AS amount
            FROM journal_entry_lines jel JOIN chart_of_accounts coa ON coa.id = jel.account_id AND coa.supplier_id = jel.supplier_id
            WHERE jel.entry_id = ? AND jel.supplier_id = ? AND jel.side = "debit" GROUP BY coa.account_code');
        $stmt->execute([$entryId, $this->supplierId]);
        $amounts = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        self::assertSame(1000.0, (float) ($amounts['518.987651'] ?? 0));
        self::assertSame(250.0, (float) ($amounts['518.987650'] ?? 0));
    }

    public function testRejectsInvalidKindAndForeignItem(): void
    {
        [$id, $item] = $this->createInvoice();
        [$otherId, $foreign] = $this->createInvoice();

        self::assertSame(400, $this->put($id, ['items' => [['id' => $item, 'expense_kind' => 'rocket']]])['status']);
        self::assertSame(400, $this->put($id, ['items' => [['id' => $foreign, 'expense_kind' => 'service']]])['status']);
        self::assertSame(400, $this->put($id, ['items' => []])['status']);
        self::assertNull($this->items($otherId)[$foreign]['expense_kind'], 'Cizí položka se nesmí změnit.');
    }

    /** Zaplacený doklad z importu: účetní ho opraví bez vynucené úpravy, klient z portálu ne. */
    public function testNonDraftIsEditableByStaffButNotByClient(): void
    {
        [$id, $item] = $this->createInvoice();
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ?")
            ->execute([self::ISSUE_DATE, $id]);

        $denied = $this->put($id, ['items' => [['id' => $item, 'expense_kind' => 'service']]], 'client');
        self::assertContains($denied['status'], [403, 409], json_encode($denied['body'], JSON_UNESCAPED_UNICODE));
        self::assertNull($this->items($id)[$item]['expense_kind']);

        $ok = $this->put($id, ['items' => [['id' => $item, 'expense_kind' => 'service']]], 'accountant');
        self::assertSame(200, $ok['status'], json_encode($ok['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('service', $this->items($id)[$item]['expense_kind']);
    }

    public function testCancelledIsImmutable(): void
    {
        [$id, $item] = $this->createInvoice();
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'cancelled' WHERE id = ?")->execute([$id]);

        self::assertSame(409, $this->put($id, ['items' => [['id' => $item, 'expense_kind' => 'service']]])['status']);
    }

    public function testReviewIsReturnedAndClearedWithWarning(): void
    {
        [$id] = $this->createInvoice();
        $review = ['expense_kinds' => [['order_index' => 0, 'kind' => 'service', 'confidence' => 0.4, 'reason' => 'AI z dokladu']]];
        $this->repo->appendExtractionWarning($id, $this->supplierId, 'AI navrhuje druh nákladu u 1 řádků');
        $this->repo->setExtractionReview($id, $this->supplierId, $review);

        self::assertSame($review, $this->repo->find($id, $this->supplierId)['extraction_review']);

        $res = self::decode(($this->dismiss)($this->request('POST', []), new Psr7Response(), ['id' => (string) $id]));
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $after = $this->repo->find($id, $this->supplierId);
        self::assertNull($after['extraction_warning']);
        self::assertNull($after['extraction_review']);
    }

    /** Trello MCUAD #10: odrážky hlášení mizí postupně, jak se řádky řeší. */
    public function testWarningBulletsDisappearAsRowsGetKind(): void
    {
        [$id, $first, $second] = $this->createInvoice();
        $this->seedWarning($id);

        $this->put($id, ['items' => [['id' => $first, 'expense_kind' => 'service']]]);
        $inv = $this->repo->find($id, $this->supplierId);
        self::assertStringContainsString(self::RC_SECTION, (string) $inv['extraction_warning'], 'Jiný bod hlášení zůstává.');
        self::assertStringNotContainsString('řádek 1', (string) $inv['extraction_warning']);
        self::assertStringContainsString('u 1 řádků', (string) $inv['extraction_warning']);
        self::assertStringContainsString('řádek 2', (string) $inv['extraction_warning']);
        self::assertSame([1], array_column($inv['extraction_review']['expense_kinds'], 'order_index'));

        $this->put($id, ['items' => [['id' => $second, 'expense_kind' => 'small_asset']]]);
        $inv = $this->repo->find($id, $this->supplierId);
        self::assertSame(self::RC_SECTION, $inv['extraction_warning'], 'Po vyřešení všech řádků zmizí celá sekce.');
        self::assertNull($inv['extraction_review']);
    }

    /** Trello MCUAD #10: hlášení z doby před `extraction_review` nese návrhy jen jako text. */
    public function testTextOnlyWarningBulletsDisappearAsRowsGetKind(): void
    {
        [$id, $first, $second] = $this->createInvoice();
        $this->seedWarning($id);
        $this->repo->setExtractionReview($id, $this->supplierId, null);

        $this->put($id, ['items' => [['id' => $first, 'expense_kind' => 'service']]]);
        $inv = $this->repo->find($id, $this->supplierId);
        self::assertStringContainsString(self::RC_SECTION, (string) $inv['extraction_warning']);
        self::assertStringNotContainsString('řádek 1', (string) $inv['extraction_warning']);
        self::assertStringContainsString('u 1 řádků', (string) $inv['extraction_warning']);
        self::assertStringContainsString('řádek 2', (string) $inv['extraction_warning']);

        $this->put($id, ['items' => [['id' => $second, 'expense_kind' => 'small_asset']]]);
        self::assertSame(self::RC_SECTION, $this->repo->find($id, $this->supplierId)['extraction_warning']);
    }

    /** Druh nastavený dřív, než hlášení umělo mizet: potvrzení beze změny ho dočistí. */
    public function testUnchangedSaveStillPrunesResolvedBullets(): void
    {
        [$id, $first, $second] = $this->createInvoice();
        $this->seedWarning($id);
        $this->db->pdo()->prepare("UPDATE purchase_invoice_items SET expense_kind = 'service' WHERE purchase_invoice_id = ?")
            ->execute([$id]);

        $res = $this->put($id, ['items' => [['id' => $first, 'expense_kind' => 'service'], ['id' => $second, 'expense_kind' => 'service']]]);
        self::assertSame(200, $res['status']);
        self::assertSame(self::RC_SECTION, $this->repo->find($id, $this->supplierId)['extraction_warning']);
    }

    /** Uložení v editoru (replaceItems) řeší odrážky stejně jako kontrolní okno. */
    public function testEditorSaveAlsoPrunesBullets(): void
    {
        [$id] = $this->createInvoice();
        $this->seedWarning($id);
        $items = $this->repo->find($id, $this->supplierId)['items'];
        $items[0]['expense_kind'] = 'material';
        $items[1]['expense_kind'] = 'service';

        $this->repo->replaceItems($id, $items);

        self::assertSame(self::RC_SECTION, $this->repo->find($id, $this->supplierId)['extraction_warning']);
    }

    public function testDismissSingleSectionKeepsTheRest(): void
    {
        [$id] = $this->createInvoice();
        $this->seedWarning($id);

        $res = $this->dismiss($id, ['section' => self::RC_SECTION]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $inv = $this->repo->find($id, $this->supplierId);
        self::assertStringStartsWith('AI navrhuje druh nákladu', (string) $inv['extraction_warning']);
        self::assertCount(2, $inv['extraction_review']['expense_kinds']);

        self::assertSame(409, $this->dismiss($id, ['section' => 'Neexistující bod'])['status']);

        $this->dismiss($id, ['section' => (string) $inv['extraction_warning']]);
        $inv = $this->repo->find($id, $this->supplierId);
        self::assertNull($inv['extraction_warning'], 'Poslední sekce → doklad přestane být ke kontrole.');
        self::assertNull($inv['extraction_review']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private const RC_SECTION = 'Reverse charge (přijetí služby ze 3. země): zkontrolujte povahu plnění.';

    private function seedWarning(int $id): void
    {
        $entries = [
            ['order_index' => 0, 'kind' => 'service', 'confidence' => 0.4, 'reason' => 'AI z dokladu'],
            ['order_index' => 1, 'kind' => 'small_asset', 'confidence' => 0.9, 'reason' => 'text obsahuje „monitor"'],
        ];
        $this->repo->appendExtractionWarning($id, $this->supplierId, self::RC_SECTION);
        $this->repo->appendExtractionWarning($id, $this->supplierId, (string) AiExpenseKindProposal::warningTextFromReview(
            $entries, [0 => 'Předplatné (PHPUnit)', 1 => 'Monitor (PHPUnit)'],
        ));
        $this->repo->setExtractionReview($id, $this->supplierId, ['expense_kinds' => $entries]);
    }

    /**
     * @param  array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function dismiss(int $id, array $body): array
    {
        return self::decode(($this->dismiss)($this->request('POST', $body), new Psr7Response(), ['id' => (string) $id]));
    }

    /** @return list<int> [invoiceId, itemId1, itemId2] */
    private function createInvoice(): array
    {
        $item = fn (string $d, float $p): array => [
            'description' => $d, 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => $p, 'vat_rate_id' => $this->vatRateId,
        ];
        $created = self::decode(($this->create)($this->request('POST', [
            'vendor_id'             => $this->vendorId,
            'vendor_invoice_number' => 'KINDS-' . bin2hex(random_bytes(3)),
            'document_kind'         => 'invoice',
            'issue_date'            => self::ISSUE_DATE,
            'tax_date'              => self::ISSUE_DATE,
            'due_date'              => '2096-05-10',
            'received_at'           => self::ISSUE_DATE,
            'currency_id'           => $this->currencyId,
            'reverse_charge'        => false,
            'prices_include_vat'    => false,
            'items'                 => [$item('Předplatné (PHPUnit)', 1000.0), $item('Monitor (PHPUnit)', 250.0)],
        ]), new Psr7Response()));
        self::assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
        $id = (int) $created['body']['id'];

        return [$id, ...array_keys($this->items($id))];
    }

    /** @return array<int, array<string,mixed>> */
    private function items(int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, expense_kind, is_fixed_asset, expense_account_code, expense_classification_source, expense_rule_id
               FROM purchase_invoice_items WHERE purchase_invoice_id = ? ORDER BY order_index, id'
        );
        $stmt->execute([$id]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }

    /**
     * @param  array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function put(int $id, array $body, string $role = 'admin'): array
    {
        return self::decode(
            ($this->setKinds)($this->request('PUT', $body, $role), new Psr7Response(), ['id' => (string) $id])
        );
    }

    /** @param array<string,mixed> $body */
    private function request(string $method, array $body, string $role = 'admin'): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/purchase-invoices')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withParsedBody($body);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
