<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Anonymization;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Anonymization\OwnedCloneTarget;
use PDO;
use PHPUnit\Framework\TestCase;

final class OwnedCloneTargetTest extends TestCase
{
    private Config $config;

    private PDO $first;

    private PDO $second;

    /** @var list<string> */
    private array $schemas = [];

    protected function setUp(): void
    {
        $this->config = Config::load(Bootstrap::rootDir());
        self::assertContains($this->config->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        $database = (string) $this->config->get('db.name');
        self::assertStringEndsWith('_test', $database);
        self::assertSame(getenv('MYINVOICE_DB_NAME'), $database);
        $this->first = $this->server();
        $this->second = $this->server();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->schemas) as $schema) {
            self::assertMatchesRegularExpression('/^myucto_clone_owner_[a-f0-9]{12}_test$/D', $schema);
            $this->second->exec('DROP DATABASE IF EXISTS ' . DatabaseCloner::quote($schema));
        }
    }

    public function testExistingTargetIsNotOwnedOrDropped(): void
    {
        $schema = $this->schema();
        $this->second->exec('CREATE DATABASE ' . DatabaseCloner::quote($schema));
        $this->second->exec('CREATE TABLE ' . DatabaseCloner::quote($schema) . '.sentinel (id INT PRIMARY KEY)');
        $run = OwnedCloneTarget::acquire($this->first, $schema);

        try {
            try {
                $run->create('utf8mb4', 'utf8mb4_unicode_ci');
                self::fail('Existing target was accepted as owned.');
            } catch (\PDOException) {
                self::assertFalse($run->dropIfOwned());
            }
        } finally {
            $run->release();
        }

        self::assertSame('1', $this->exists($schema));
        self::assertSame('1', $this->tableExists($schema, 'sentinel'));
    }

    public function testTargetCreatedAfterLeaseAcquisitionSurvivesFailedCreate(): void
    {
        $schema = $this->schema();
        $run = OwnedCloneTarget::acquire($this->first, $schema);
        $this->second->exec('CREATE DATABASE ' . DatabaseCloner::quote($schema));
        $this->second->exec('CREATE TABLE ' . DatabaseCloner::quote($schema) . '.sentinel (id INT PRIMARY KEY)');

        try {
            try {
                $run->create('utf8mb4', 'utf8mb4_unicode_ci');
                self::fail('Raced target was accepted as owned.');
            } catch (\PDOException) {
                self::assertFalse($run->dropIfOwned());
            }
        } finally {
            $run->release();
        }

        self::assertSame('1', $this->exists($schema));
        self::assertSame('1', $this->tableExists($schema, 'sentinel'));
    }

    public function testRecreatedTargetWithForeignTokenCannotBeDropped(): void
    {
        $schema = $this->schema();
        $run = OwnedCloneTarget::acquire($this->first, $schema);
        $run->create('utf8mb4', 'utf8mb4_unicode_ci');
        $this->second->exec('DROP DATABASE ' . DatabaseCloner::quote($schema));
        $this->second->exec('CREATE DATABASE ' . DatabaseCloner::quote($schema));
        $this->second->exec('CREATE TABLE ' . DatabaseCloner::quote($schema) . '.' . DatabaseCloner::quote(OwnedCloneTarget::OWNERSHIP_TABLE)
            . ' (singleton TINYINT UNSIGNED NOT NULL PRIMARY KEY, run_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL)');
        $this->second->prepare('INSERT INTO ' . DatabaseCloner::quote($schema) . '.' . DatabaseCloner::quote(OwnedCloneTarget::OWNERSHIP_TABLE)
            . ' (singleton, run_token) VALUES (1, ?)')->execute([str_repeat('f', 64)]);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('vlastnictví');
            $run->dropIfOwned();
        } finally {
            $run->release();
        }
    }

    public function testOnlyCurrentRunCanDropItsTarget(): void
    {
        $schema = $this->schema();
        $run = OwnedCloneTarget::acquire($this->first, $schema);
        try {
            $run->create('utf8mb4', 'utf8mb4_unicode_ci');
            self::assertTrue($run->dropIfOwned());
        } finally {
            $run->release();
        }

        self::assertSame('0', $this->exists($schema));
    }

    private function schema(): string
    {
        $schema = 'myucto_clone_owner_' . bin2hex(random_bytes(6)) . '_test';
        $this->schemas[] = $schema;

        return $schema;
    }

    private function server(): PDO
    {
        return new PDO(
            'mysql:host=' . $this->config->get('db.host') . ';port=' . $this->config->get('db.port', 3306) . ';charset=utf8mb4',
            (string) $this->config->get('db.user'),
            (string) $this->config->get('db.pass', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
    }

    private function exists(string $schema): string
    {
        $statement = $this->second->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $statement->execute([$schema]);

        return (string) $statement->fetchColumn();
    }

    private function tableExists(string $schema, string $table): string
    {
        $statement = $this->second->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
        $statement->execute([$schema, $table]);

        return (string) $statement->fetchColumn();
    }
}
