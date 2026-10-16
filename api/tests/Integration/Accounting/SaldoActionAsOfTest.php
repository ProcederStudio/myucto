<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\Reports\SaldoAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Task #3 (D6/2): SaldoAction dřív validoval `as_of` proti hranicím vybraného
 * `period_id` (422 'as_of musí ležet uvnitř zvoleného období'), takže si účetní
 * nemohla zobrazit saldokonto k libovolnému datu napříč obdobími — typicky
 * 31.12. uzavřeného roku při pohledu z aktuálního otevřeného období. Ověřuje
 * na úrovni Action (HTTP validace), že požadavek s asOf MIMO vybrané období
 * dnes projde (200), a že SaldoService dohledané `as_of_period` vrátí v těle
 * odpovědi. Formát data se dál validuje beze změny (422 pro nevalidní vstup).
 */
#[Group('integration')]
final class SaldoActionAsOfTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private SaldoAction $action;
    private AccountingPeriodRepository $periods;
    private ChartOfAccountsSeeder $seeder;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $currentPeriodId = 0;
    private int $prevPeriodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db      = $container->get(Connection::class);
            $this->action  = $container->get(SaldoAction::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $this->seeder  = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $baseSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId   = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($baseSupplierId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $iso = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             SELECT ?, "Testovací", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id
               FROM supplier WHERE id = ?'
        );
        $iso->execute(['Saldo asOf test s.r.o.', 'saldo-asof@example.com', $baseSupplierId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        // SaldoAction::get() gatuje na requireDoubleEntry — INSERT výše nekopíruje
        // accounting_mode, sloupec by jinak spadl na DEFAULT 'tax_evidence' (1001).
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);

        $this->seeder->seedForSupplier($this->supplierId);

        $this->prevPeriodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->currentPeriodId = $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        // Předchozí rok uzavřený/schválený — přesně scénář z hlášení účetní.
        // row_version po create() je 1 (DEFAULT 1005), ne 0.
        $ok = $this->periods->setStatusCas($this->prevPeriodId, $this->supplierId, 'closed', 1, $this->userId);
        self::assertTrue($ok, 'Fixture: uzavření testovacího období selhalo (CAS).');
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

    public function testAsOfOutsideSelectedPeriodIsNoLongerRejected(): void
    {
        $req = $this->req([
            'period_id' => (string) $this->currentPeriodId,
            'as_of'     => self::YEAR . '-12-31', // spadá do $this->prevPeriodId, ne do vybraného
            'account'   => '311',
        ]);
        $resp = $this->action->get($req, new Psr7Response());

        self::assertSame(200, $resp->getStatusCode(), 'as_of mimo vybrané období už nesmí být 422.');
        $body = $this->decode($resp);
        self::assertSame(self::YEAR . '-12-31', $body['as_of'] ?? null);
        self::assertSame($this->currentPeriodId, $body['period']['id'] ?? null, 'Vybrané období v odpovědi odpovídá period_id.');
        self::assertNotNull($body['as_of_period'] ?? null, 'as_of_period musí být dohledané.');
        self::assertSame($this->prevPeriodId, $body['as_of_period']['id'] ?? null);
        self::assertSame(self::YEAR, $body['as_of_period']['fiscal_year'] ?? null);
        self::assertSame('closed', $body['as_of_period']['status'] ?? null);
    }

    public function testMalformedAsOfIsStillRejected(): void
    {
        $req = $this->req([
            'period_id' => (string) $this->currentPeriodId,
            'as_of'     => 'not-a-date',
            'account'   => '311',
        ]);
        $resp = $this->action->get($req, new Psr7Response());

        self::assertSame(422, $resp->getStatusCode(), 'Formát data se dál validuje beze změny.');
    }

    public function testPdfExportWithAgingBucketsForBanks(): void
    {
        $resp = $this->action->export($this->req([
            'period_id' => (string) $this->currentPeriodId,
            'account'   => '311',
            'format'    => 'pdf',
            'aging'     => '120,30,60',
            'detail'    => '0',
        ]), new Psr7Response());

        self::assertSame(200, $resp->getStatusCode());
        $resp->getBody()->rewind();
        self::assertStringStartsWith('%PDF', (string) $resp->getBody());
    }

    public function testPdfExportRejectsInvalidAgingBoundaries(): void
    {
        $resp = $this->action->export($this->req([
            'period_id' => (string) $this->currentPeriodId,
            'format'    => 'pdf',
            'aging'     => '30,abc',
        ]), new Psr7Response());

        self::assertSame(422, $resp->getStatusCode());
    }

    /** @param array<string,string> $query */
    private function req(array $query): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/accounting/reports/saldo')
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);
    }

    /** @return array<string,mixed> */
    private function decode(\Psr\Http\Message\ResponseInterface $resp): array
    {
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
