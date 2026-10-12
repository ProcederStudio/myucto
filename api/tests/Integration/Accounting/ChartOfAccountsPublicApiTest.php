<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\ChartOfAccountsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\ApiScopeMiddleware;
use MyInvoice\Middleware\ApiVersionRewriteMiddleware;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Analytický účet osnovy přes veřejné API (`/api/v1/accounting/accounts`) s API
 * tokenem, jak ho volá MCP server. Požadavek prochází přepisem verze a scope
 * middlewarem až do téže akce, kterou používá webové rozhraní, takže validace
 * (rodič, tvar kódu, jedinečnost) je jediná.
 */
#[Group('integration')]
final class ChartOfAccountsPublicApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;
    private ChartOfAccountsAction $action;
    private bool $inTx = false;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $syntheticId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->pdo = $this->db->pdo();
            $this->action = $container->get(ChartOfAccountsAction::class);
            $seeder = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $source = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier nebo uživatel.');
        }

        $this->pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
        $seeder->seedForSupplier($this->supplierId);

        $this->syntheticId = $this->accountId('378');
        if ($this->syntheticId === 0) {
            $this->pdo->prepare(
                "INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, is_active)
                 VALUES (?, '378', 'Jiné pohledávky', 'asset', 'debit', 1, 1)"
            )->execute([$this->supplierId]);
            $this->syntheticId = (int) $this->pdo->lastInsertId();
        }
    }

    protected function tearDown(): void
    {
        if ($this->inTx && isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testBearerCreatesAnalyticUnderSyntheticGivenByCode(): void
    {
        $res = $this->call('POST', '/api/v1/accounting/accounts', [
            'parent_code' => '378', 'account_code' => '378.800', 'name' => 'Zápůjčka',
        ]);

        self::assertSame(201, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE) ?: '');
        $synthetic = $this->account($this->syntheticId);
        self::assertSame('378.800', $res['body']['account_code']);
        self::assertSame('Zápůjčka', $res['body']['name']);
        self::assertSame($this->syntheticId, (int) $res['body']['parent_id']);
        self::assertFalse((bool) $res['body']['is_synthetic']);
        self::assertTrue((bool) $res['body']['is_active']);
        self::assertSame($synthetic['account_type'], $res['body']['account_type']);
        self::assertSame($synthetic['normal_side'], $res['body']['normal_side']);

        // Kód bez tečky doplní táž normalizace jako ve webovém rozhraní.
        $dotless = $this->call('POST', '/api/v1/accounting/accounts', [
            'parent_id' => $this->syntheticId, 'account_code' => '378810', 'name' => 'Úroky ze zápůjčky',
        ]);
        self::assertSame(201, $dotless['status']);
        self::assertSame('378.810', $dotless['body']['account_code']);
    }

    public function testValidationOfTheSharedActionApplies(): void
    {
        $missing = $this->call('POST', '/api/v1/accounting/accounts', [
            'parent_code' => '9Z9', 'account_code' => '9Z9.100', 'name' => 'Bez rodiče',
        ]);
        self::assertSame(404, $missing['status']);
        self::assertSame('not_found', $missing['body']['error']['code'] ?? null);
        self::assertSame(0, $this->accountId('9Z9.100'));

        $noParent = $this->call('POST', '/api/v1/accounting/accounts', [
            'account_code' => '378.801', 'name' => 'Bez rodiče',
        ]);
        self::assertSame(422, $noParent['status']);
        self::assertSame('validation_failed', $noParent['body']['error']['code'] ?? null);

        // ID a kód rodiče si musí odpovídat, jinak by šlo jedním zakrýt druhé.
        $mismatch = $this->call('POST', '/api/v1/accounting/accounts', [
            'parent_id' => $this->syntheticId, 'parent_code' => '311', 'account_code' => '378.802', 'name' => 'Rozpor',
        ]);
        self::assertSame(404, $mismatch['status']);

        // Delší název by striktní sql_mode odmítl pětistovkou, jinak by se tiše ořízl.
        $tooLong = $this->call('POST', '/api/v1/accounting/accounts', [
            'parent_code' => '378', 'account_code' => '378.803', 'name' => str_repeat('ž', 191),
        ]);
        self::assertSame(422, $tooLong['status']);
        self::assertSame(0, $this->accountId('378.803'));

        $first = $this->call('POST', '/api/v1/accounting/accounts', [
            'parent_code' => '378', 'account_code' => '378.800', 'name' => 'Zápůjčka',
        ]);
        self::assertSame(201, $first['status']);
        $duplicate = $this->call('POST', '/api/v1/accounting/accounts', [
            'parent_code' => '378', 'account_code' => '378800', 'name' => 'Zápůjčka znovu',
        ]);
        self::assertSame(409, $duplicate['status']);
        self::assertSame('duplicate_account', $duplicate['body']['error']['code'] ?? null);
    }

    public function testTokenScopeAndUserPermissionStillApply(): void
    {
        $body = ['parent_code' => '378', 'account_code' => '378.800', 'name' => 'Zápůjčka'];

        $readScope = $this->call('POST', '/api/v1/accounting/accounts', $body, 'read');
        self::assertSame(403, $readScope['status']);
        self::assertSame('insufficient_scope', $readScope['body']['error']['code'] ?? null);

        $readonlyUser = $this->call('POST', '/api/v1/accounting/accounts', $body, 'read_write', 'readonly');
        self::assertSame(403, $readonlyUser['status']);
        self::assertSame('forbidden', $readonlyUser['body']['error']['code'] ?? null);

        self::assertSame(0, $this->accountId('378.800'));

        // Mazání účtu zůstává ve webovém rozhraní.
        $delete = $this->call('DELETE', '/api/v1/accounting/accounts/' . $this->syntheticId, []);
        self::assertSame(403, $delete['status']);
        self::assertSame('token_write_forbidden', $delete['body']['error']['code'] ?? null);
    }

    public function testBearerUpdatesAnalyticButNotSynthetic(): void
    {
        $created = $this->call('POST', '/api/v1/accounting/accounts', [
            'parent_code' => '378', 'account_code' => '378.800', 'name' => 'Zápůjčka',
        ]);
        self::assertSame(201, $created['status']);
        $id = (int) $created['body']['id'];

        $renamed = $this->call('PATCH', '/api/v1/accounting/accounts/' . $id, [
            'name' => 'Zápůjčka společníka', 'is_active' => false,
        ]);
        self::assertSame(200, $renamed['status']);
        self::assertSame('Zápůjčka společníka', $renamed['body']['name']);
        self::assertFalse((bool) $renamed['body']['is_active']);

        $synthetic = $this->call('PATCH', '/api/v1/accounting/accounts/' . $this->syntheticId, ['is_active' => false]);
        self::assertSame(403, $synthetic['status']);
        self::assertSame('token_write_forbidden', $synthetic['body']['error']['code'] ?? null);
        self::assertTrue((bool) $this->account($this->syntheticId)['is_active']);
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(
        string $method,
        string $path,
        array $body,
        string $scope = 'read_write',
        string $role = 'accountant',
    ): array {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['scope' => $scope])
            ->withParsedBody($body);

        $action = $this->action;
        $router = new class ($action) implements RequestHandlerInterface {
            public function __construct(private readonly ChartOfAccountsAction $action) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $path = $request->getUri()->getPath();
                $method = $request->getMethod();
                if ($path === '/api/accounting/accounts' && $method === 'POST') {
                    return $this->action->create($request, new Psr7Response());
                }
                if (preg_match('#^/api/accounting/accounts/([0-9]+)$#', $path, $m) === 1 && $method === 'PATCH') {
                    return $this->action->update($request, new Psr7Response(), ['id' => $m[1]]);
                }
                if (preg_match('#^/api/accounting/accounts/([0-9]+)$#', $path, $m) === 1 && $method === 'DELETE') {
                    return $this->action->delete($request, new Psr7Response(), ['id' => $m[1]]);
                }
                return (new ResponseFactory())->createResponse(404);
            }
        };
        $scopeHandler = new class ($router) implements RequestHandlerInterface {
            public function __construct(private readonly RequestHandlerInterface $next) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ApiScopeMiddleware(new ResponseFactory()))->process($request, $this->next);
            }
        };

        $response = (new ApiVersionRewriteMiddleware())->process($request, $scopeHandler);
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function accountId(string $code): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $stmt->execute([$this->supplierId, $code]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /** @return array<string,mixed> */
    private function account(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM chart_of_accounts WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$this->supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }
}
