<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Ares;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Ares\AresClient;
use MyInvoice\Service\Ares\CrpDphClient;
use MyInvoice\Service\Ares\ViesClient;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Regrese: české OSVČ mají historické DIČ = "CZ" + rodné číslo (9–10 číslic),
 * což NENÍ jejich 8místné IČO (reálný příklad CZ8901311870 = Ing. Jiří Zikmund,
 * aktivní plátce). ViesClient ale pro CZ posílal číselnou část DIČ do ARES jako
 * IČO → ARES vrátil "nenalezeno" → uživateli svítilo "DIČ není platné nebo
 * neexistuje", přestože je to platný plátce ověřitelný přes VIES.
 *
 * Oprava: na ARES routujeme CZ jen když číselná část = 8 číslic (IČO); jinak
 * (a když ARES subjekt nenajde) propadneme na autoritativní VIES. ARES "nenalezeno"
 * už nesmí být tvrdý negativ se source 'ares'.
 *
 * Test běží offline: DB (vies_cache) je mockované PDO bez záznamu, VIES REST/SOAP
 * jsou vypnuté prázdnou konfigurací → lookup propadne až na source 'error'. Pointa
 * není výsledek "error", ale že source NENÍ 'ares' (tj. nevznikl tvrdý negativ).
 */
final class ViesClientCzRoutingTest extends TestCase
{
    private function makeClient(): ViesClient
    {
        $config = new Config([
            'vies' => ['rest_api' => '', 'wsdl' => '', 'cache_ttl' => 86400, 'timeout' => 8],
            'ares' => ['api' => '', 'timeout' => 5],
        ]);

        // Connection s mockovaným PDO → fromCache nevrátí žádný řádek, cache() je no-op.
        $stmt = $this->createStub(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(false);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $connection = new Connection($config);
        $ref = new \ReflectionProperty(Connection::class, 'pdo');
        $ref->setValue($connection, $pdo);

        $ares = new AresClient($config, $connection, new NullLogger());
        // CRPDPH bez nakonfigurovaného endpointu vrací source='error' → ViesClient
        // z toho udělá měkké „služba nedostupná", nikdy tvrdé invalid.
        $crpdph = new CrpDphClient($config, $connection, new NullLogger());

        return new ViesClient($config, $connection, new NullLogger(), $ares, $crpdph);
    }

    public function testCzOsvcDicWithRodneCisloDoesNotHardNegateViaAres(): void
    {
        // 10místné DIČ (rodné číslo) — NESMÍ skončit jako tvrdý ARES negativ.
        $r = $this->makeClient()->lookup('CZ8901311870');
        self::assertNotSame('ares', $r['source'], 'OSVČ DIČ nemá projít přes ARES-dle-IČO');
        // Offline (VIES vypnuté) → propadne na 'error'; v produkci by VIES vrátil valid.
        self::assertSame('error', $r['source']);
    }

    public function testCzNineDigitDicAlsoSkipsAres(): void
    {
        // 9místné DIČ (kratší rodné číslo) — stejné chování.
        $r = $this->makeClient()->lookup('CZ123456789');
        self::assertNotSame('ares', $r['source']);
    }

    /**
     * Skupinová registrace DPH (DIČ s kmenem 699xxxxxx) se NESMÍ ověřovat přes VIES —
     * ten české DPH skupiny vůbec neeviduje a vracel by trvalý false-negative
     * „DIČ není platné". Autoritativní je registr plátců DPH (CRPDPH).
     */
    public function testCzGroupRegistrationGoesToVatPayerRegistryNotVies(): void
    {
        $r = $this->makeClient()->lookup('CZ699000123');

        // Endpoint registru není v testu nakonfigurovaný → měkké „služba nedostupná".
        // Podstatné je, že se NESÁHLO na VIES ani ARES a že to NENÍ tvrdý negativ.
        self::assertSame('error', $r['source'], 'Bez endpointu registru = měkký error, ne invalid z VIES.');
        self::assertNotSame('rest', $r['source']);
        self::assertNotSame('soap', $r['source']);
        self::assertNotSame('ares', $r['source']);
    }

    /**
     * 8místné „69900012" je běžné IČO, ne skupina — kmen 699 sám o sobě nesmí doklad
     * odklonit do registru skupin. Rozhoduje až délka 9 (tvar DIČ skupiny CZ699xxxxxx).
     */
    public function testEightDigitIcoStartingWith699StillRoutesToAres(): void
    {
        $m = new \ReflectionMethod(ViesClient::class, 'tryCrpDph');
        self::assertTrue($m->isPrivate(), 'tryCrpDph je interní větev pro skupiny.');

        // 8 číslic → ARES větev; bez I/O vrátí null a spadne na VIES (zde 'error').
        $r = $this->makeClient()->lookup('CZ69900012');
        self::assertNotSame('crpdph', $r['source'], 'Kmen 699 rozhoduje jen u 9místného DIČ skupiny.');
    }

    public function testTryAresReturnsNullWhenSubjectNotFound(): void
    {
        // tryAres na "nenalezeno" (zde díky non-8místnému vstupu, který AresClient
        // odmítne bez I/O) musí vrátit null → fallback na VIES, ne tvrdý negativ.
        $client = $this->makeClient();
        $m = new \ReflectionMethod(ViesClient::class, 'tryAres');
        $result = $m->invoke($client, '123', 'CZ123');
        self::assertNull($result);
    }

    public function testGenericAddressAcceptsTwoLetterCountryPrefixInZip(): void
    {
        $m = new \ReflectionMethod(ViesClient::class, 'parseAddress');
        $client = $this->makeClient();

        self::assertSame(
            ['street' => 'Richard-Strauss-Straße 34', 'city' => 'Wien', 'zip' => 'AT-1230'],
            $m->invoke($client, "Richard-Strauss-Straße 34\nAT-1230 Wien", 'AT'),
        );
        self::assertSame('L-1234', $m->invoke($client, "Rue 1\nL-1234 Luxembourg", 'LU')['zip']);
        self::assertSame('10115', $m->invoke($client, "Straße 1\n10115 Berlin", 'DE')['zip']);
    }
}
