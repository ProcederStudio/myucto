<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\UserSettings;

use MyInvoice\Action\UserSettings\SavedFilterAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Integrační testy ukládaných filtrů (Epic F5 §3.2/§3.4).
 *
 * Action volaná přímo z DI kontejneru s ATTR_USER / ATTR_CURRENT_ID; jedna
 * transakce, rollback v tearDown; soft-skip bez cfg.php.
 */
#[Group('integration')]
final class SavedFilterActionTest extends TestCase
{
    private Connection $db;
    private SavedFilterAction $action;

    private int $userId = 0;
    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container    = Bootstrap::buildContainer();
            $this->db     = $container->get(Connection::class);
            $this->action = $container->get(SavedFilterAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
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

    public function testCreateAndListRoundtrip(): void
    {
        $payload = ['status' => 'overdue', 'currency' => 'CZK'];
        $c = $this->create(['page_key' => 'invoices', 'name' => 'Po splatnosti CZK', 'payload' => $payload]);
        self::assertSame(201, $c['status']);
        self::assertSame('invoices', $c['body']['page_key']);
        self::assertSame($payload, $c['body']['payload']);
        self::assertFalse($c['body']['is_default']);

        $withKey = $this->call('list', 'GET', ['query' => ['page_key' => 'invoices']]);
        self::assertSame(200, $withKey['status']);
        $item = $this->pick($withKey['body'], $c['body']['id']);
        self::assertNotNull($item);
        self::assertSame($payload, $item['payload']);

        $noKey = $this->call('list', 'GET');
        self::assertNotNull($this->pick($noKey['body'], $c['body']['id']));
    }

    public function testListWithoutPageKeyReturnsAll(): void
    {
        $a = $this->create(['page_key' => 'invoices', 'name' => 'A', 'payload' => ['x' => '1']]);
        $b = $this->create(['page_key' => 'journal', 'name' => 'B', 'payload' => ['y' => '2']]);

        $all = $this->call('list', 'GET');
        self::assertSame(200, $all['status']);
        self::assertNotNull($this->pick($all['body'], $a['body']['id']));
        self::assertNotNull($this->pick($all['body'], $b['body']['id']));
    }

    public function testUpdateRenameAndPayload(): void
    {
        $c = $this->create(['page_key' => 'invoices', 'name' => 'Původní', 'payload' => ['a' => '1']]);
        $id = $c['body']['id'];

        $u = $this->call('update', 'PUT', [
            'args' => ['id' => (string) $id],
            'body' => ['name' => 'Nový název', 'payload' => ['b' => '2'], 'sort_order' => 5, 'page_key' => 'journal'],
        ]);
        self::assertSame(200, $u['status']);
        self::assertSame('Nový název', $u['body']['name']);
        self::assertSame(['b' => '2'], $u['body']['payload']);
        self::assertSame(5, $u['body']['sort_order']);
        self::assertSame('invoices', $u['body']['page_key'], 'page_key je immutable.');
    }

    public function testDelete(): void
    {
        $c = $this->create(['page_key' => 'invoices', 'name' => 'Smaž mě', 'payload' => ['a' => '1']]);
        $d = $this->call('delete', 'DELETE', ['args' => ['id' => (string) $c['body']['id']]]);
        self::assertSame(200, $d['status']);
        self::assertTrue($d['body']['deleted']);

        $again = $this->call('delete', 'DELETE', ['args' => ['id' => (string) $c['body']['id']]]);
        self::assertSame(404, $again['status']);
        self::assertSame('not_found', $again['body']['error']['code']);
    }

    public function testDeleteForeignReturns404(): void
    {
        $c = $this->create(['page_key' => 'invoices', 'name' => 'Cizí', 'payload' => ['a' => '1']]);
        $d = $this->call('delete', 'DELETE', [
            'args' => ['id' => (string) $c['body']['id']],
            'user' => $this->foreignUser(),
        ]);
        self::assertSame(404, $d['status']);
    }

    public function testUserIsolation(): void
    {
        $c = $this->create(['page_key' => 'invoices', 'name' => 'A vlastní', 'payload' => ['a' => '1']]);
        $id = $c['body']['id'];
        $other = $this->foreignUser();

        $get = $this->call('list', 'GET', ['user' => $other]);
        self::assertNull($this->pick($get['body'], $id), 'User B nevidí filtr usera A.');

        $put = $this->call('update', 'PUT', ['args' => ['id' => (string) $id], 'body' => ['name' => 'hack'], 'user' => $other]);
        self::assertSame(404, $put['status']);

        $del = $this->call('delete', 'DELETE', ['args' => ['id' => (string) $id], 'user' => $other]);
        self::assertSame(404, $del['status']);
    }

    public function testSupplierScopeIsolation(): void
    {
        $c = $this->create(['page_key' => 'invoices', 'name' => 'Firma 1', 'payload' => ['a' => '1']]);
        $id = $c['body']['id'];
        $otherSupplier = $this->supplierId + 99999;

        $get = $this->call('list', 'GET', ['supplier' => $otherSupplier, 'query' => ['page_key' => 'invoices']]);
        self::assertNull($this->pick($get['body'], $id), 'Filtr firmy 1 není vidět pod jinou firmou.');

        $put = $this->call('update', 'PUT', ['args' => ['id' => (string) $id], 'body' => ['name' => 'x'], 'supplier' => $otherSupplier]);
        self::assertSame(404, $put['status']);

        $del = $this->call('delete', 'DELETE', ['args' => ['id' => (string) $id], 'supplier' => $otherSupplier]);
        self::assertSame(404, $del['status']);
    }

    public function testDefaultIsExclusiveViaCreate(): void
    {
        $f1 = $this->create(['page_key' => 'invoices', 'name' => 'D1', 'payload' => ['a' => '1'], 'is_default' => true]);
        $f2 = $this->create(['page_key' => 'invoices', 'name' => 'D2', 'payload' => ['a' => '2'], 'is_default' => true]);
        $j  = $this->create(['page_key' => 'journal', 'name' => 'DJ', 'payload' => ['a' => '3'], 'is_default' => true]);

        $all = $this->call('list', 'GET');
        self::assertFalse($this->pick($all['body'], $f1['body']['id'])['is_default'], 'Starý default zhasnul.');
        self::assertTrue($this->pick($all['body'], $f2['body']['id'])['is_default']);
        self::assertTrue($this->pick($all['body'], $j['body']['id'])['is_default'], 'Default jiné stránky zůstává.');
    }

    public function testDefaultIsExclusiveViaUpdate(): void
    {
        $f1 = $this->create(['page_key' => 'invoices', 'name' => 'U1', 'payload' => ['a' => '1'], 'is_default' => true]);
        $f2 = $this->create(['page_key' => 'invoices', 'name' => 'U2', 'payload' => ['a' => '2']]);

        $u = $this->call('update', 'PUT', ['args' => ['id' => (string) $f2['body']['id']], 'body' => ['is_default' => true]]);
        self::assertSame(200, $u['status']);
        self::assertTrue($u['body']['is_default']);

        $all = $this->call('list', 'GET', ['query' => ['page_key' => 'invoices']]);
        self::assertFalse($this->pick($all['body'], $f1['body']['id'])['is_default']);
        self::assertTrue($this->pick($all['body'], $f2['body']['id'])['is_default']);
    }

    public function testDuplicateNameConflict409(): void
    {
        $this->create(['page_key' => 'invoices', 'name' => 'Stejné', 'payload' => ['a' => '1']]);
        $dup = $this->create(['page_key' => 'invoices', 'name' => 'Stejné', 'payload' => ['a' => '2']]);
        self::assertSame(409, $dup['status']);
        self::assertSame('filter_name_exists', $dup['body']['error']['code']);

        $other = $this->create(['page_key' => 'journal', 'name' => 'Stejné', 'payload' => ['a' => '3']]);
        self::assertSame(201, $other['status'], 'Stejné jméno na jiné stránce projde.');
    }

    /**
     * Duplicitní název je ošetřený stav (409), v produkčním logu nesmí stát ERROR.
     * Vlastní spojení s vlastním loggerem: sdílené testovací PDO má logger toho,
     * kdo ho otevřel první.
     */
    public function testDuplicateNameOnCreateAndRenameIsNotLoggedAsError(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<array{0:string,1:string}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message];
            }
        };
        $config = Bootstrap::buildContainer()->get(\MyInvoice\Infrastructure\Config\Config::class);
        $connection = Connection::withoutSharedTestConnection(static fn () => new Connection($config, $logger));
        $action = new SavedFilterAction($connection);
        $pdo = $connection->pdo();
        $pdo->beginTransaction();
        try {
            $call = function (string $method, string $httpMethod, array $body, array $args = []) use ($action): array {
                $req = (new ServerRequestFactory())
                    ->createServerRequest($httpMethod, '/api/user/filters')
                    ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
                    ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
                    ->withParsedBody($body);
                $resp = $args === []
                    ? $action->{$method}($req, new Psr7Response())
                    : $action->{$method}($req, new Psr7Response(), $args);
                $resp->getBody()->rewind();
                $decoded = json_decode((string) $resp->getBody(), true);
                return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
            };

            $first = $call('create', 'POST', ['page_key' => 'invoices', 'name' => 'Log A', 'payload' => ['a' => '1']]);
            $second = $call('create', 'POST', ['page_key' => 'invoices', 'name' => 'Log B', 'payload' => ['a' => '2']]);
            self::assertSame(201, $first['status']);
            self::assertSame(201, $second['status']);

            $dup = $call('create', 'POST', ['page_key' => 'invoices', 'name' => 'Log A', 'payload' => ['a' => '3']]);
            self::assertSame(409, $dup['status']);

            $rename = $call('update', 'PUT', ['name' => 'Log A'], ['id' => (string) $second['body']['id']]);
            self::assertSame(409, $rename['status']);
            self::assertSame('filter_name_exists', $rename['body']['error']['code']);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->close();
        }

        $errors = array_filter($logger->records, static fn (array $r): bool => $r[0] === 'error');
        self::assertSame([], array_values($errors));
    }

    public function testInvalidPageKey422(): void
    {
        $c = $this->create(['page_key' => 'nonexistent', 'name' => 'X', 'payload' => ['a' => '1']]);
        self::assertSame(422, $c['status']);
        self::assertSame('invalid_page_key', $c['body']['error']['code']);

        $l = $this->call('list', 'GET', ['query' => ['page_key' => 'nonexistent']]);
        self::assertSame(422, $l['status']);
        self::assertSame('invalid_page_key', $l['body']['error']['code']);
    }

    public function testNameLengthValidation422(): void
    {
        $empty = $this->create(['page_key' => 'invoices', 'name' => '   ', 'payload' => ['a' => '1']]);
        self::assertSame(422, $empty['status']);
        self::assertSame('validation_failed', $empty['body']['error']['code']);

        $long = $this->create(['page_key' => 'invoices', 'name' => str_repeat('x', 101), 'payload' => ['a' => '1']]);
        self::assertSame(422, $long['status']);
        self::assertSame('validation_failed', $long['body']['error']['code']);
    }

    public function testPayloadTooLarge422(): void
    {
        $c = $this->create(['page_key' => 'invoices', 'name' => 'Velký', 'payload' => ['x' => str_repeat('a', 16400)]]);
        self::assertSame(422, $c['status']);
        self::assertSame('payload_too_large', $c['body']['error']['code']);
    }

    public function testPayloadTooDeep422(): void
    {
        $c = $this->create(['page_key' => 'invoices', 'name' => 'Hluboký', 'payload' => ['a' => ['b' => ['c' => ['d' => 'e']]]]]);
        self::assertSame(422, $c['status']);
        self::assertSame('payload_too_deep', $c['body']['error']['code']);
    }

    public function testPayloadScalarRejected422(): void
    {
        $c = $this->create(['page_key' => 'invoices', 'name' => 'Skalár', 'payload' => 'jenom-string']);
        self::assertSame(422, $c['status']);
        self::assertSame('validation_failed', $c['body']['error']['code']);
    }

    public function testFilterCountLimit422(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $r = $this->create(['page_key' => 'invoices', 'name' => 'F' . $i, 'payload' => ['i' => (string) $i]]);
            self::assertSame(201, $r['status'], "Filtr #$i má projít.");
        }
        $over = $this->create(['page_key' => 'invoices', 'name' => 'F30', 'payload' => ['i' => '30']]);
        self::assertSame(422, $over['status']);
        self::assertSame('filter_limit_reached', $over['body']['error']['code']);

        $otherPage = $this->create(['page_key' => 'journal', 'name' => 'J0', 'payload' => ['i' => '0']]);
        self::assertSame(201, $otherPage['status'], 'Jiná stránka má vlastní limit (žádný globální strop).');
    }

    public function testReadonlyRoleCanCrud(): void
    {
        $c = $this->create(['page_key' => 'invoices', 'name' => 'RO', 'payload' => ['a' => '1']], 'readonly');
        self::assertSame(201, $c['status']);
        $id = $c['body']['id'];

        $u = $this->call('update', 'PUT', ['args' => ['id' => (string) $id], 'body' => ['name' => 'RO2'], 'role' => 'readonly']);
        self::assertSame(200, $u['status']);

        $d = $this->call('delete', 'DELETE', ['args' => ['id' => (string) $id], 'role' => 'readonly']);
        self::assertSame(200, $d['status']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function create(array $body, string $role = 'accountant'): array
    {
        return $this->call('create', 'POST', ['body' => $body, 'role' => $role]);
    }

    private function foreignUser(): int
    {
        return $this->userId + 999000;
    }

    /**
     * @param array<int,array<string,mixed>> $list
     * @return array<string,mixed>|null
     */
    private function pick(array $list, int $id): ?array
    {
        foreach ($list as $item) {
            if (($item['id'] ?? null) === $id) {
                return $item;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $opts
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(string $method, string $httpMethod, array $opts = []): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/user/filters')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $opts['supplier'] ?? $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $opts['user'] ?? $this->userId, 'role' => $opts['role'] ?? 'accountant']);
        if (isset($opts['query'])) {
            $req = $req->withQueryParams($opts['query']);
        }
        if (array_key_exists('body', $opts)) {
            $req = $req->withParsedBody($opts['body']);
        }
        $args = $opts['args'] ?? [];
        $resp = $args === []
            ? $this->action->{$method}($req, new Psr7Response())
            : $this->action->{$method}($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
