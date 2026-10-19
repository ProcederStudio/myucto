<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Anonymization;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Anonymization\AnonymizationService;
use MyInvoice\Service\Anonymization\CloneProtectedRows;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Anonymization\FinancialArchiveProtectedTable;
use MyInvoice\Service\Anonymization\Pseudonymizer;
use MyInvoice\Service\Anonymization\TextScrubber;
use PDO;
use PHPUnit\Framework\TestCase;

final class AnonymizationProtectedRowsTest extends TestCase
{
    private Config $config;

    private PDO $server;

    private string $schema;

    protected function setUp(): void
    {
        $this->config = Config::load(Bootstrap::rootDir());
        self::assertContains($this->config->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        self::assertStringEndsWith('_test', (string) $this->config->get('db.name'));
        self::assertSame(getenv('MYINVOICE_DB_NAME'), $this->config->get('db.name'));
        $this->schema = 'myucto_protected_pass_' . bin2hex(random_bytes(6)) . '_test';
        $this->server = new PDO(
            'mysql:host=' . $this->config->get('db.host') . ';port=' . $this->config->get('db.port', 3306) . ';charset=utf8mb4',
            (string) $this->config->get('db.user'),
            (string) $this->config->get('db.pass', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
        $this->server->exec('CREATE DATABASE ' . DatabaseCloner::quote($this->schema));
        $this->server->exec('USE ' . DatabaseCloner::quote($this->schema));
        $this->server->exec('CREATE TABLE tax_submissions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, notes TEXT NULL)');
        $this->server->exec("INSERT INTO tax_submissions (notes) VALUES ('alpha@synthetic.invalid'),('protected@synthetic.invalid'),('omega@synthetic.invalid')");
    }

    protected function tearDown(): void
    {
        self::assertMatchesRegularExpression('/^myucto_protected_pass_[a-f0-9]{12}_test$/D', $this->schema);
        $this->server->exec('DROP DATABASE IF EXISTS ' . DatabaseCloner::quote($this->schema));
    }

    public function testGenericPassLeavesOnlyExactProtectedPrimaryKeyUntouched(): void
    {
        $protected = CloneProtectedRows::create($this->server);
        $protected->protect(FinancialArchiveProtectedTable::TAX_SUBMISSION, 2);
        $service = new AnonymizationService($this->config);
        $method = new \ReflectionMethod($service, 'processTable');
        $changed = $method->invoke(
            $service,
            $this->server,
            new TextScrubber(new Pseudonymizer('synthetic-protected-pass')),
            'tax_submissions',
            ['notes' => 'text'],
            ['notes' => ['nullable' => true, 'type' => 'text', 'max' => null, 'json' => false, 'on_update' => false]],
            [['name' => 'id', 'integer' => true]],
            $protected,
        );

        $rows = $this->server->query('SELECT id,notes FROM tax_submissions ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame(2, $changed);
        self::assertNotSame('alpha@synthetic.invalid', $rows[1]);
        self::assertSame('protected@synthetic.invalid', $rows[2]);
        self::assertNotSame('omega@synthetic.invalid', $rows[3]);
        $protected->drop();
    }
}
