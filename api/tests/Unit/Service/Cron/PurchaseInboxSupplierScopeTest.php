<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Cron;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Cron\PurchaseInboxSupplierScope;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PurchaseInboxSupplierScopeTest extends TestCase
{
    private function database(array $ids): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY)');
        $stmt = $pdo->prepare('INSERT INTO supplier (id) VALUES (?)');
        foreach ($ids as $id) {
            $stmt->execute([$id]);
        }
        return $pdo;
    }

    public static function selections(): iterable
    {
        yield 'jediná firma s ID 2' => [[2], [], 2];
        yield 'žádné firmy' => [[], [], null];
        yield 'null vlastník je nenastavený' => [[2], ['purchase_invoice' => ['cron_supplier_id' => null]], 2];
        yield 'explicitní vlastník' => [[1, 2], ['purchase_invoice' => ['cron_supplier_id' => 2]], 2];
        yield 'řetězcové ID' => [[1, 2], ['purchase_invoice' => ['cron_supplier_id' => '2']], 2];
        yield 'nový vlastník má přednost' => [[1, 2], [
            'purchase_invoice' => ['cron_supplier_id' => 2],
            'app' => ['default_supplier_id' => 1],
        ], 2];
        yield 'nový vlastník přebije i neplatný starý klíč' => [[1, 2], [
            'purchase_invoice' => ['cron_supplier_id' => 2],
            'app' => ['default_supplier_id' => 'invalid'],
        ], 2];
        yield 'starý explicitní vlastník' => [[1, 2], ['app' => ['default_supplier_id' => 2]], 2];
        yield 'starý řetězcový vlastník' => [[1, 2], ['app' => ['default_supplier_id' => '2']], 2];
        yield 'scan all s jedinou firmou' => [[2], ['app' => ['scan_all_suppliers' => true]], 2];
        yield 'scan all bez firem' => [[], ['app' => ['scan_all_suppliers' => true]], null];
        yield 'scan all ignoruje starý vlastník' => [[2], ['app' => ['scan_all_suppliers' => true, 'default_supplier_id' => 1]], 2];
        yield 'scan all s novým vlastníkem skenuje jen jeho' => [[1, 2], [
            'purchase_invoice' => ['cron_supplier_id' => 2],
            'app' => ['scan_all_suppliers' => true, 'default_supplier_id' => 1],
        ], 2];
    }

    #[DataProvider('selections')]
    public function testResolvesSingleOwner(array $ids, array $config, ?int $expected): void
    {
        self::assertSame($expected, (new PurchaseInboxSupplierScope())->resolve($this->database($ids), new Config($config)));
    }

    public static function ambiguousConfigurations(): iterable
    {
        yield 'bez vlastníka' => [[]];
        yield 'scan all' => [['app' => ['scan_all_suppliers' => true]]];
        yield 'scan all nesmí použít starého vlastníka' => [['app' => ['scan_all_suppliers' => true, 'default_supplier_id' => 2]]];
    }

    #[DataProvider('ambiguousConfigurations')]
    public function testAmbiguousInboxRequiresOwner(array $config): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('purchase_invoice.cron_supplier_id');
        (new PurchaseInboxSupplierScope())->resolve($this->database([1, 2, 3]), new Config($config));
    }

    public static function invalidOwners(): iterable
    {
        foreach (['' , 0, -1, '0', '-1', '2foo', true, false, 2.0, '2.0', '2e0', [], '9999999999999999999999999999'] as $i => $value) {
            yield "nový neplatný vlastník {$i}" => [['purchase_invoice' => ['cron_supplier_id' => $value], 'app' => ['default_supplier_id' => 2]]];
            yield "starý neplatný vlastník {$i}" => [['app' => ['default_supplier_id' => $value]]];
        }
        yield 'nová firma neexistuje' => [['purchase_invoice' => ['cron_supplier_id' => 3], 'app' => ['default_supplier_id' => 2]]];
        yield 'stará firma neexistuje' => [['app' => ['default_supplier_id' => 3]]];
        yield 'scan all neobejde neplatného vlastníka' => [['purchase_invoice' => ['cron_supplier_id' => 3], 'app' => ['scan_all_suppliers' => true]]];
    }

    #[DataProvider('invalidOwners')]
    public function testInvalidExplicitOwnerNeverFallsBack(array $config): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('purchase_invoice.cron_supplier_id');
        (new PurchaseInboxSupplierScope())->resolve($this->database([2]), new Config($config));
    }

    public function testDatabaseFailureNeverReturnsFallback(): void
    {
        $pdo = $this->database([2]);
        $pdo->exec('DROP TABLE supplier');
        $this->expectException(PDOException::class);
        (new PurchaseInboxSupplierScope())->resolve($pdo, new Config([]));
    }
}
