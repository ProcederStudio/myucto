<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\DimensionAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\ApiScopeMiddleware;
use MyInvoice\Middleware\ApiVersionRewriteMiddleware;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Tests\Support\OtherDocumentsPostingScenarios;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Dimenze konceptu ostatní pohledávky nebo závazku přes veřejné API s tokenem
 * (MCP `set_other_item_dimensions`). Token smí jen koncept: u zaúčtovaného
 * dokladu by uložení přerazítkovalo řádky deníku, a to zůstává webovému rozhraní.
 */
#[Group('integration')]
final class OtherItemDimensionsPublicApiTest extends TestCase
{
    private ContainerInterface $container;
    private Connection $db;
    private OtherDocumentsPostingScenarios $scenarios;
    private DimensionService $dimensions;
    private int $userId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->db->pdo()->beginTransaction();
        $this->dimensions = $this->container->get(DimensionService::class);
        $this->scenarios = new OtherDocumentsPostingScenarios($this->container);
        $this->scenarios->run();
        $this->userId = (int) $this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testTokenSetsDimensionsOfDraft(): void
    {
        $draft = $this->draft();
        [$cc, $project] = [$this->scenarios->types()['cost_center'], $this->scenarios->types()['project']];
        $header = [$cc => $this->value($cc, 'REGR-C1'), $project => $this->value($project, 'REGR-P1')];

        $res = $this->call('PUT', '/api/v1/accounting/dimensions/documents/other-items/' . $draft, ['header' => $header]);

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE) ?: '');
        $saved = $this->dimensions->documentDimensions($this->scenarios->supplierId, 'other_item', $draft)['header'];
        ksort($saved);
        ksort($header);
        self::assertSame($header, $saved);

        $read = $this->call('PUT', '/api/v1/accounting/dimensions/documents/other-items/' . $draft, ['header' => $header], 'read');
        self::assertSame('insufficient_scope', $read['body']['error']['code'] ?? null);
    }

    public function testTokenCannotTagPostedItemOrOtherDocuments(): void
    {
        $cc = $this->scenarios->types()['cost_center'];
        $header = [$cc => $this->value($cc, 'REGR-C1')];
        $posted = $this->scenarios->ids['other_payable'];
        $before = $this->dimensions->documentDimensions($this->scenarios->supplierId, 'other_item', $posted)['header'];

        $res = $this->call('PUT', '/api/v1/accounting/dimensions/documents/other-items/' . $posted, ['header' => $header]);
        self::assertSame(403, $res['status']);
        self::assertSame('token_write_forbidden', $res['body']['error']['code'] ?? null);
        self::assertSame($before, $this->dimensions->documentDimensions($this->scenarios->supplierId, 'other_item', $posted)['header']);

        foreach (['purchase-invoices', 'assets', 'bank-transactions'] as $doc) {
            $other = $this->call('PUT', "/api/v1/accounting/dimensions/documents/$doc/1", ['header' => $header]);
            self::assertSame(403, $other['status'], $doc);
            self::assertSame('token_write_forbidden', $other['body']['error']['code'] ?? null, $doc);
        }
        $preview = $this->call('POST', '/api/v1/accounting/dimensions/documents/other-items/' . $posted . '/preview', ['header' => $header]);
        self::assertSame('token_write_forbidden', $preview['body']['error']['code'] ?? null);

        // Webové rozhraní zaúčtovaný doklad přerazítkovat smí; guard je jen pro token.
        $session = $this->call('PUT', '/api/accounting/dimensions/documents/other-items/' . $posted, ['header' => $header], 'read_write', 'session');
        self::assertSame(200, $session['status'], json_encode($session['body'], JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function testTokenNeedsOtherItemsWritePermission(): void
    {
        $draft = $this->draft();
        $cc = $this->scenarios->types()['cost_center'];
        $res = $this->call('PUT', '/api/v1/accounting/dimensions/documents/other-items/' . $draft,
            ['header' => [$cc => $this->value($cc, 'REGR-C1')]], 'read_write', 'bearer', 'readonly');
        self::assertSame(403, $res['status']);
        self::assertSame([], $this->dimensions->documentDimensions($this->scenarios->supplierId, 'other_item', $draft)['header']);
    }

    private function draft(): int
    {
        $item = $this->container->get(OtherItemService::class)->create($this->scenarios->supplierId, [
            'side' => 'receivable', 'kind' => 'loan', 'title' => 'Syntetická zápůjčka',
            'issued_on' => OtherDocumentsPostingScenarios::YEAR . '-05-01',
            'due_on' => OtherDocumentsPostingScenarios::YEAR . '-12-31', 'amount' => 50000,
            'counter_account_code' => '221',
        ], null);
        return (int) $item['id'];
    }

    private function value(int $typeId, string $code): int
    {
        return $this->scenarios->value($typeId, $code);
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
        string $auth = 'bearer',
        string $role = 'accountant',
    ): array {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->scenarios->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $auth)
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['scope' => $scope])
            ->withParsedBody($body);

        $action = $this->container->get(DimensionAction::class);
        $router = new class ($action) implements RequestHandlerInterface {
            public function __construct(private readonly DimensionAction $action) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $path = $request->getUri()->getPath();
                if (preg_match('#^/api/accounting/dimensions/documents/([a-z-]+)/([0-9]+)$#', $path, $m) === 1
                    && $request->getMethod() === 'PUT') {
                    return $this->action->saveDocument($request, new Psr7Response(), ['doc' => $m[1], 'id' => $m[2]]);
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
}
