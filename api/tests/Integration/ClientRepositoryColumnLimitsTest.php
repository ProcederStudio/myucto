<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Karta klienta založená z cizích dat (AI vytěžení PDF, importy, e-shop) nesmí
 * shodit request na SQLSTATE 22001 „Data too long" u `zip`, `dic` ani ostatních
 * textových sloupců. Repository hodnoty upraví přes ClientFieldLimits::fit().
 *
 * Běží se striktním sql_mode jako CI; bez něj by MariaDB hodnotu tiše usekla.
 */
#[Group('integration')]
final class ClientRepositoryColumnLimitsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private ClientRepository $clients;

    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db      = $c->get(Connection::class);
            $this->clients = $c->get(ClientRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $pdo->exec("SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',STRICT_ALL_TABLES')");
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0) {
            $this->markTestSkipped('Chybí základní data (supplier).');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, "CZK", "CZK", "Kč", "CZK", "CZK", 2, 1, 1)'
        )->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testCreateFitsExtractedValuesIntoColumns(): void
    {
        $id = $this->clients->create([
            'company_name' => str_repeat('Syntetická firma ', 20),
            'first_name'   => str_repeat('Jan', 30),
            'dic'          => 'DIČ: CZ 12345678 (plátce DPH)',
            'tax_number'   => str_repeat('9', 40),
            'street'       => str_repeat('Dlouhá ', 40),
            'city'         => str_repeat('Město ', 30),
            'zip'          => '110 00 Praha 1',
            'main_email'   => str_repeat('a', 200) . '@example.invalid',
            'phone'        => '+420 000 000 000, +420 000 000 001, +420 000 000 002',
            'is_vendor'    => true,
        ], $this->supplierId);

        $row = $this->clients->find($id);
        self::assertNotNull($row);
        self::assertSame('11000Praha', $row['zip']);
        self::assertNull($row['dic'], 'DIČ, které se nevejde ani po normalizaci, se zahodí.');
        self::assertSame(190, mb_strlen((string) $row['company_name']));
        self::assertSame(60, mb_strlen((string) $row['first_name']));
        self::assertSame(30, mb_strlen((string) $row['tax_number']));
        self::assertSame(190, mb_strlen((string) $row['street']));
        self::assertSame(120, mb_strlen((string) $row['city']));
        self::assertSame(190, mb_strlen((string) $row['main_email']));
        self::assertSame(40, mb_strlen((string) $row['phone']));
    }

    public function testCreateNormalizesDicThatFitsOnlyWithoutSeparators(): void
    {
        $id = $this->clients->create([
            'company_name' => 'Syntetická EU firma',
            'dic'          => 'nl-1-2-3-4-5-6-7-8-9-b01',
            'street'       => 'Teststraat 1',
            'city'         => 'Amsterdam',
            'zip'          => '1000 AA',
            'country_iso2' => 'NL',
        ], $this->supplierId);

        $row = $this->clients->find($id);
        self::assertSame('NL123456789B01', $row['dic']);
        self::assertSame('1000 AA', $row['zip'], 'Hodnota, která se vejde, zůstává beze změny.');
    }

    public function testUpdateFitsTooLongZip(): void
    {
        $id = $this->clients->create([
            'company_name' => 'Syntetická firma',
            'street' => 'Dlouhá 1', 'city' => 'Praha', 'zip' => '11000',
        ], $this->supplierId);

        $this->clients->update($id, ['zip' => 'AT-1230 Wien Liesing']);

        self::assertSame('AT-1230Wie', $this->clients->find($id)['zip']);
    }
}
