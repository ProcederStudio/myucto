<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Service\Ares\AresClient;
use MyInvoice\Service\Ares\CrpDphClient;
use MyInvoice\Service\Ares\VendorVatPayerResolver;
use MyInvoice\Service\Ares\ViesClient;
use MyInvoice\Service\Import\ClientResolver;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Firma s více kartami se stejným IČO (pobočky, provozovny, karta dealera vedle staré
 * karty firmy). Import dokladu dřív bral `LIMIT 1` bez řazení, takže doklad dostala
 * libovolná z karet bez ohledu na odběratele uvedeného na dokladu. Teď má přednost
 * karta se shodným názvem, jinak nejstarší karta (deterministicky). Totéž u DIČ.
 *
 * Skládání resolveru a izolace dat jsou stejné jako v
 * {@see ClientResolverDuplicateNormalizationTest}: offline ARES/VIES, transakce
 * rollbacknutá v tearDown, syntetická data.
 */
#[Group('integration')]
final class ClientResolverSameIdentifierCardsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private ClientResolver $resolver;
    private ClientRepository $clients;

    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
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

        $offlineConfig = new Config([
            'ares'   => ['api' => '', 'timeout' => 5, 'cache_ttl' => 86400],
            'vies'   => ['rest_api' => '', 'wsdl' => '', 'timeout' => 8, 'cache_ttl' => 86400],
            'crpdph' => ['endpoint' => '', 'timeout' => 8, 'cache_ttl' => 86400],
        ]);
        $ares = new AresClient($offlineConfig, $this->db, new NullLogger());
        $crpdph = new CrpDphClient($offlineConfig, $this->db, new NullLogger());
        $vies = new ViesClient($offlineConfig, $this->db, new NullLogger(), $ares, $crpdph);
        $vatPayer = new VendorVatPayerResolver($ares, $vies, $this->clients);
        $this->resolver = new ClientResolver($this->db, $this->clients, $ares, $vies, $vatPayer);

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0) {
            $this->markTestSkipped('Chybí základní data (supplier).');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);

        // Izolovaný dodavatel je klon jen řádku `supplier` — měny jsou per tenant
        // (ClientRepository::create() dohledává currency_default_id přes CZK kód).
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

    public function testSameIcPrefersCardWithMatchingName(): void
    {
        $main = $this->card('Testovací holding s.r.o.', ic: '27082440');
        $branch = $this->card('Testovací holding s.r.o. – pobočka Brno', ic: '27082440');

        $result = $this->resolver->resolve(['company_name' => 'Testovací holding s.r.o. – pobočka Brno', 'ic' => '27082440'], $this->supplierId);
        self::assertFalse($result['created']);
        self::assertSame($branch, $result['id'], 'Doklad pobočky musí dostat kartu pobočky.');

        $result = $this->resolver->resolve(['company_name' => 'Testovací holding s.r.o.', 'ic' => '27082440'], $this->supplierId);
        self::assertSame($main, $result['id']);
    }

    public function testSameIcWithoutNameMatchFallsBackToOldestCard(): void
    {
        $oldest = $this->card('Testovací firma A s.r.o.', ic: '27082440');
        $this->card('Testovací firma B s.r.o.', ic: '27082440');

        $result = $this->resolver->resolve(['company_name' => 'Úplně jiný název', 'ic' => '27082440'], $this->supplierId);
        self::assertFalse($result['created'], 'Neznámý název nesmí založit třetí kartu se stejným IČO.');
        self::assertSame($oldest, $result['id']);
    }

    public function testActiveCardWinsOverArchivedCardWithMatchingName(): void
    {
        $archived = $this->card('Testovací firma A s.r.o.', ic: '27082440');
        $active = $this->card('Testovací firma A s.r.o. (nová karta)', ic: '27082440');
        $this->db->pdo()->prepare('UPDATE clients SET archived_at = NOW() WHERE id = ?')->execute([$archived]);

        $result = $this->resolver->resolve(['company_name' => 'Testovací firma A s.r.o.', 'ic' => '27082440'], $this->supplierId);
        self::assertFalse($result['created']);
        self::assertSame($active, $result['id'], 'Archivovaná karta nesmí dostat nový doklad, když existuje aktivní.');

        $this->db->pdo()->prepare('UPDATE clients SET archived_at = NOW() WHERE id = ?')->execute([$active]);
        $result = $this->resolver->resolve(['company_name' => 'Testovací firma A s.r.o.', 'ic' => '27082440'], $this->supplierId);
        self::assertFalse($result['created'], 'Bez aktivní karty se dál použije archivovaná, nezakládá se nová.');
        self::assertSame($archived, $result['id']);
    }

    public function testSameDicPrefersCardWithMatchingName(): void
    {
        $this->card('Test Lieferant GmbH', dic: 'DE123456789');
        $branch = $this->card('Test Lieferant GmbH Niederlassung', dic: 'DE123456789');

        $result = $this->resolver->resolve(['company_name' => 'Test Lieferant GmbH Niederlassung', 'dic' => 'DE 123456789'], $this->supplierId);
        self::assertFalse($result['created']);
        self::assertSame($branch, $result['id']);
    }

    private function card(string $name, ?string $ic = null, ?string $dic = null): int
    {
        return $this->clients->create([
            'company_name' => $name,
            'ic'           => $ic,
            'dic'          => $dic,
            'street'       => 'Ulice 1',
            'city'         => 'Praha',
            'zip'          => '11000',
        ], $this->supplierId);
    }
}
