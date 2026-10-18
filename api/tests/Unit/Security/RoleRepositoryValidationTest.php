<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvalidPermission;
use MyInvoice\Repository\PermissionNotAllowedForRoleType;
use MyInvoice\Repository\RoleRepository;
use MyInvoice\Security\PermissionCatalog;
use PHPUnit\Framework\TestCase;

final class RoleRepositoryValidationTest extends TestCase
{
    public function testUnknownPermissionIsRejected(): void
    {
        $repo = new RoleRepository($this->createStub(Connection::class), new PermissionCatalog());
        $method = new \ReflectionMethod($repo, 'validatePermissions');
        $this->expectException(InvalidPermission::class);
        $method->invoke($repo, 'staff', ['made.up' => 2]);
    }

    public function testStaffOnlyPermissionIsRejectedForClient(): void
    {
        $repo = new RoleRepository($this->createStub(Connection::class), new PermissionCatalog());
        $method = new \ReflectionMethod($repo, 'validatePermissions');
        $this->expectException(PermissionNotAllowedForRoleType::class);
        $method->invoke($repo, 'client', ['accounting' => 1]);
    }

    public function testClientSafePermissionIsAccepted(): void
    {
        $repo = new RoleRepository($this->createStub(Connection::class), new PermissionCatalog());
        $method = new \ReflectionMethod($repo, 'validatePermissions');
        self::assertSame(['invoices' => 2], $method->invoke($repo, 'client', ['invoices' => 'write']));
    }

    public function testClientStockHasReadAccessAndExplicitActionsOnly(): void
    {
        $repo = new RoleRepository($this->createStub(Connection::class), new PermissionCatalog());
        $method = new \ReflectionMethod($repo, 'validatePermissions');
        self::assertSame(['stock' => 1], $method->invoke($repo, 'client', ['stock' => 2]));
        foreach (['stock.items.write', 'stock.fulfillment.write'] as $action) {
            self::assertSame([$action => 2, 'stock' => 1], $method->invoke($repo, 'client', [$action => 2]));
            self::assertSame([$action => 1, 'stock' => 1], $method->invoke($repo, 'client', [$action => 1]),
                'Normalizace nesmí změnit čtecí grant na zápis.');
        }
        self::assertSame([], $method->invoke($repo, 'client', ['stock' => 0, 'stock.items.write' => 0, 'stock.fulfillment.write' => 0]));
        self::assertSame(['stock' => 2], $method->invoke($repo, 'staff', ['stock' => 2]));
    }
}
