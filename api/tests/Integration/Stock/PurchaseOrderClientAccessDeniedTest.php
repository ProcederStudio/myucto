<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\ClientStockRoutePolicy;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\PermissionChecker;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Autorizace objednávek dodavatelům (Epic SKLAD „na cestě", rozhodnutí #11 —
 * samostatná role „nákupčí" se nezavádí, stačí `stock.orders.write`).
 *
 * Vzor {@see StockClientAccessDeniedTest}: čistě nad permission mapou, bez DB.
 * `allows()` si nejdřív vynutí, že routa je vůbec namapovaná — bez toho by test
 * svítil zeleně i tehdy, kdyby se pravidlo omylem smazalo a routa spadla mimo mapu.
 */
#[Group('integration')]
final class PurchaseOrderClientAccessDeniedTest extends TestCase
{
    private RoutePermissionMap $routes;
    private PermissionChecker $checker;
    private EffectiveRole $client;

    /** Role s plným skladem, ale BEZ práva na objednávky. */
    private EffectiveRole $stockOnly;

    /** Role s právem na objednávky. */
    private EffectiveRole $buyer;

    protected function setUp(): void
    {
        $this->routes  = new RoutePermissionMap();
        $this->checker = new PermissionChecker(new PermissionCatalog());
        $this->client  = new EffectiveRole(4, 'Klient', 'client', true, [
            'invoices'          => 2,
            'purchase_invoices' => 2,
            'stock'             => 2,
        ]);
        $this->stockOnly = new EffectiveRole(5, 'Skladník', 'staff', true, [
            'stock'                  => 2,
            'stock.documents.write'  => 2,
        ]);
        $this->buyer = new EffectiveRole(6, 'Nákupčí', 'staff', true, [
            'stock'              => 2,
            'stock.orders.write' => 2,
        ]);
    }

    public function testClientCannotReachAnyPurchaseOrderRoute(): void
    {
        foreach (self::allRoutes() as [$method, $path]) {
            self::assertFalse($this->allows($method, $path, $this->client), "$method $path");
        }
    }

    public function testWriteRoutesRequireOrdersPermission(): void
    {
        foreach (self::writeRoutes() as [$method, $path]) {
            $route = $this->routes->match($method, $path);
            self::assertNotNull($route, "$method $path není v permission mapě.");
            self::assertSame('stock.orders.write', $route->key, "$method $path");
            self::assertSame(AccessLevel::WRITE, $route->minimum, "$method $path");

            self::assertFalse($this->allows($method, $path, $this->stockOnly), "$method $path — samotný `stock` nesmí stačit.");
            self::assertTrue($this->allows($method, $path, $this->buyer), "$method $path");
        }
    }

    /**
     * Regrese na pořadí pravidel: `^/api/stock/.*\/close$` je v mapě dřív než
     * catch-all, takže bez explicitního pravidla PŘED ním by „zavřít nedodaný
     * zbytek objednávky" spadlo pod skladovou UZÁVĚRKU (`stock.close`) — jiný
     * modul, jiné právo, a nikdo by si toho nevšiml, protože obojí je „stock".
     */
    public function testCloseOrderIsNotConfusedWithStockClosing(): void
    {
        $route = $this->routes->match('POST', '/api/stock/purchase-orders/7/close');
        self::assertNotNull($route);
        self::assertSame('stock.orders.write', $route->key);
        self::assertNotSame('stock.close', $route->key);
    }

    public function testReadRoutesFallBackToPlainStockPermission(): void
    {
        foreach ([
            ['GET', '/api/stock/purchase-orders'],
            ['GET', '/api/stock/purchase-orders/1'],
            ['GET', '/api/stock/purchase-orders/1/pdf'],
        ] as [$method, $path]) {
            $route = $this->routes->match($method, $path);
            self::assertNotNull($route, "$method $path");
            self::assertSame('stock', $route->key, "$method $path");
            self::assertTrue($this->allows($method, $path, $this->stockOnly), "$method $path");
            self::assertFalse($this->allows($method, $path, $this->client), "$method $path");
        }
    }

    public function testSuperadminBypassesTheMatrix(): void
    {
        $superadmin = new EffectiveRole(1, 'Superadmin', 'superadmin', true, [], 'superadmin');
        self::assertTrue($this->allows('POST', '/api/stock/purchase-orders', $superadmin));
        self::assertTrue($this->allows('POST', '/api/stock/purchase-orders/1/close', $superadmin));
    }

    /** @return list<array{0:string,1:string}> */
    private static function writeRoutes(): array
    {
        return [
            ['POST', '/api/stock/purchase-orders'],
            ['PUT', '/api/stock/purchase-orders/1'],
            ['DELETE', '/api/stock/purchase-orders/1'],
            ['POST', '/api/stock/purchase-orders/1/send'],
            ['POST', '/api/stock/purchase-orders/1/confirm'],
            ['POST', '/api/stock/purchase-orders/1/cancel'],
            ['POST', '/api/stock/purchase-orders/1/close'],
            ['POST', '/api/stock/purchase-orders/1/reopen'],
        ];
    }

    /** @return list<array{0:string,1:string}> */
    private static function allRoutes(): array
    {
        return array_merge(self::writeRoutes(), [
            ['GET', '/api/stock/purchase-orders'],
            ['GET', '/api/stock/purchase-orders/1'],
            ['GET', '/api/stock/purchase-orders/1/pdf'],
        ]);
    }

    private function allows(string $method, string $path, EffectiveRole $role): bool
    {
        $route = $this->routes->match($method, $path);
        self::assertNotNull($route, "$method $path");

        return $route->key !== null && $this->checker->allows($role, $route->key, $route->minimum)
            && (!$role->isClientType() || ClientStockRoutePolicy::allows($method, $path, $route->key));
    }
}
