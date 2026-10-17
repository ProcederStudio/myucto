<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Export;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Export\MoneyS3XmlExporter;
use PDO;
use PHPUnit\Framework\TestCase;

final class MoneyS3XmlExporterTest extends TestCase
{
    private MoneyS3XmlExporter $exporter;

    protected function setUp(): void
    {
        /** @var InvoiceRepository $repo */
        $repo = (new \ReflectionClass(InvoiceRepository::class))->newInstanceWithoutConstructor();
        /** @var Connection $db */
        $db = (new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor();

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE tax_constants (year INTEGER PRIMARY KEY, data TEXT NOT NULL)');
        $config = $this->createStub(\MyInvoice\Infrastructure\Config\Config::class);
        $taxConstantsDb = new Connection($config);
        $prop = (new \ReflectionClass($taxConstantsDb))->getProperty('pdo');
        $prop->setValue($taxConstantsDb, $pdo);

        $this->exporter = new MoneyS3XmlExporter($repo, $db, new TaxConstantsRepository($taxConstantsDb));
    }

    public function testDocumentEnvelopeAndSingleRateBucketsMatchRealSample(): void
    {
        // Shape mirrors a genuine Money S3 export sample: a lone 21%-taxed line
        // fills the standard bucket (Zaklad22/DPH22, legacy name) and drives
        // SazbaDPH2, while the unused reduced slot SazbaDPH1 falls back to the
        // current default reduced rate (12) — exactly as in the real sample.
        $xml = $this->exporter->buildXml([$this->invoice()]);

        self::assertSame('0', $this->xpathOne($xml, '/MoneyData/@VyberZaznamu'));
        self::assertSame('CZ', $this->xpathOne($xml, '/MoneyData/@JazykVerze'));
        self::assertSame('1026001', $this->xpathOne($xml, '//FaktVyd/Doklad'));
        self::assertSame('2026-06-16', $this->xpathOne($xml, '//FaktVyd/Vystaveno'));
        self::assertSame('2026-06-30', $this->xpathOne($xml, '//FaktVyd/Splatno'));
        self::assertSame('12.00', $this->xpathOne($xml, '//FaktVyd/SazbaDPH1'));
        self::assertSame('21.00', $this->xpathOne($xml, '//FaktVyd/SazbaDPH2'));
        self::assertSame('0.00', $this->xpathOne($xml, '//SouhrnDPH/Zaklad5'));
        self::assertSame('0.00', $this->xpathOne($xml, '//SouhrnDPH/DPH5'));
        self::assertSame('32526.00', $this->xpathOne($xml, '//SouhrnDPH/Zaklad22'));
        self::assertSame('6830.46', $this->xpathOne($xml, '//SouhrnDPH/DPH22'));
        self::assertSame('39356.46', $this->xpathOne($xml, '//FaktVyd/Celkem'));
        self::assertSame('39356.46', $this->xpathOne($xml, '//FaktVyd/Proplatit'));
    }

    public function testMixedReducedAndStandardRatesSplitIntoBothBuckets(): void
    {
        $xml = $this->exporter->buildXml([$this->invoice([
            'items' => [
                $this->item([
                    'description' => 'Zbozi se sazbou 12',
                    'vat_rate_snapshot' => 12.0,
                    'total_without_vat' => 1000.0,
                    'total_vat' => 120.0,
                    'total_with_vat' => 1120.0,
                ]),
                $this->item([
                    'description' => 'Zbozi se sazbou 21',
                    'vat_rate_snapshot' => 21.0,
                    'total_without_vat' => 2000.0,
                    'total_vat' => 420.0,
                    'total_with_vat' => 2420.0,
                ]),
            ],
            'total_with_vat' => 3540.0,
            'amount_to_pay' => 3540.0,
        ])]);

        self::assertSame('12.00', $this->xpathOne($xml, '//FaktVyd/SazbaDPH1'));
        self::assertSame('21.00', $this->xpathOne($xml, '//FaktVyd/SazbaDPH2'));
        self::assertSame('1000.00', $this->xpathOne($xml, '//SouhrnDPH/Zaklad5'));
        self::assertSame('120.00', $this->xpathOne($xml, '//SouhrnDPH/DPH5'));
        self::assertSame('2000.00', $this->xpathOne($xml, '//SouhrnDPH/Zaklad22'));
        self::assertSame('420.00', $this->xpathOne($xml, '//SouhrnDPH/DPH22'));
    }

    public function testZeroRateItemLandsInZeroBucketAlongsideStandardRate(): void
    {
        $xml = $this->exporter->buildXml([$this->invoice([
            'items' => [
                $this->item([
                    'description' => 'Pouzite zbozi (par. 90)',
                    'vat_rate_snapshot' => 0.0,
                    'total_without_vat' => 500.0,
                    'total_vat' => 0.0,
                    'total_with_vat' => 500.0,
                ]),
                $this->item([
                    'description' => 'Bezne zbozi',
                    'vat_rate_snapshot' => 21.0,
                    'total_without_vat' => 1000.0,
                    'total_vat' => 210.0,
                    'total_with_vat' => 1210.0,
                ]),
            ],
            'total_with_vat' => 1710.0,
            'amount_to_pay' => 1710.0,
        ])]);

        self::assertSame('500.00', $this->xpathOne($xml, '//SouhrnDPH/Zaklad0'));
        self::assertSame('1000.00', $this->xpathOne($xml, '//SouhrnDPH/Zaklad22'));
    }

    public function testHistoricalReducedRateExportsIntoReducedBucket(): void
    {
        // A pre-2024 reduced rate (15% for 2013–2023) must export, not hard-
        // fail: rates are derived per document, so a lone 15% line fills the
        // reduced bucket and drives SazbaDPH1 while the standard slot keeps its
        // default (21).
        $xml = $this->exporter->buildXml([$this->invoice([
            'items' => [$this->item([
                'vat_rate_snapshot' => 15.0,
                'total_without_vat' => 1000.0,
                'total_vat' => 150.0,
                'total_with_vat' => 1150.0,
            ])],
            'total_with_vat' => 1150.0,
            'amount_to_pay' => 1150.0,
        ])]);

        self::assertSame('15.00', $this->xpathOne($xml, '//FaktVyd/SazbaDPH1'));
        self::assertSame('21.00', $this->xpathOne($xml, '//FaktVyd/SazbaDPH2'));
        self::assertSame('1000.00', $this->xpathOne($xml, '//SouhrnDPH/Zaklad5'));
        self::assertSame('150.00', $this->xpathOne($xml, '//SouhrnDPH/DPH5'));
        self::assertSame('0.00', $this->xpathOne($xml, '//SouhrnDPH/Zaklad22'));
        self::assertSame('0.00', $this->xpathOne($xml, '//SouhrnDPH/DPH22'));
    }

    public function testHistoricalReducedAndStandardPairSplitsIntoBothBuckets(): void
    {
        // 2013–2023 pair 15%/21% — both slots derived from the document, lower
        // rate to reduced (Zaklad5), higher to standard (Zaklad22).
        $xml = $this->exporter->buildXml([$this->invoice([
            'items' => [
                $this->item([
                    'vat_rate_snapshot' => 15.0,
                    'total_without_vat' => 1000.0,
                    'total_vat' => 150.0,
                    'total_with_vat' => 1150.0,
                ]),
                $this->item([
                    'vat_rate_snapshot' => 21.0,
                    'total_without_vat' => 2000.0,
                    'total_vat' => 420.0,
                    'total_with_vat' => 2420.0,
                ]),
            ],
            'total_with_vat' => 3570.0,
            'amount_to_pay' => 3570.0,
        ])]);

        self::assertSame('15.00', $this->xpathOne($xml, '//FaktVyd/SazbaDPH1'));
        self::assertSame('21.00', $this->xpathOne($xml, '//FaktVyd/SazbaDPH2'));
        self::assertSame('1000.00', $this->xpathOne($xml, '//SouhrnDPH/Zaklad5'));
        self::assertSame('150.00', $this->xpathOne($xml, '//SouhrnDPH/DPH5'));
        self::assertSame('2000.00', $this->xpathOne($xml, '//SouhrnDPH/Zaklad22'));
        self::assertSame('420.00', $this->xpathOne($xml, '//SouhrnDPH/DPH22'));
    }

    public function testMoreThanTwoNonZeroRatesFailsExport(): void
    {
        // Money S3's two-bucket model can't hold three distinct non-zero rates
        // on one document (e.g. the 2015–2023 10/15/21 mix) — hard-fail rather
        // than silently drop a rate.
        $this->expectException(\RuntimeException::class);

        $this->exporter->buildXml([$this->invoice([
            'items' => [
                $this->item(['vat_rate_snapshot' => 10.0, 'total_without_vat' => 100.0, 'total_vat' => 10.0]),
                $this->item(['vat_rate_snapshot' => 15.0, 'total_without_vat' => 100.0, 'total_vat' => 15.0]),
                $this->item(['vat_rate_snapshot' => 21.0, 'total_without_vat' => 100.0, 'total_vat' => 21.0]),
            ],
        ])]);
    }

    /**
     * Textový řádek: nulová položka se sazbou 0, do rekapitulace DPH ani do počtu
     * sazeb dokladu nevstupuje (jinak by jeho sazba třetí nenulovou sazbou export
     * shodila) a popis dokladu bere z jediné skutečné položky.
     */
    public function testTextRowIsZeroItemOutsideVatBuckets(): void
    {
        $xml = $this->exporter->buildXml([$this->invoice([
            'items' => [
                $this->item(['item_kind' => 'text', 'description' => 'Zakazka 42', 'quantity' => 0.0, 'unit' => '',
                    'unit_price_without_vat' => 0.0, 'vat_rate_snapshot' => 15.0,
                    'total_without_vat' => 0.0, 'total_vat' => 0.0, 'total_with_vat' => 0.0]),
                $this->item(['vat_rate_snapshot' => 12.0, 'total_without_vat' => 1000.0, 'total_vat' => 120.0]),
                $this->item(['description' => 'doprava', 'vat_rate_snapshot' => 21.0, 'total_without_vat' => 100.0, 'total_vat' => 21.0]),
            ],
        ])]);

        self::assertSame('12.00', $this->xpathOne($xml, '//FaktVyd/SazbaDPH1'));
        self::assertSame('21.00', $this->xpathOne($xml, '//FaktVyd/SazbaDPH2'));
        self::assertSame('0.00', $this->xpathOne($xml, '//FaktVyd/SouhrnDPH/Zaklad0'));
        self::assertSame('Zakazka 42', $this->xpathOne($xml, '//SeznamPolozek/Polozka[1]/Popis'));
        self::assertSame('0.00', $this->xpathOne($xml, '//SeznamPolozek/Polozka[1]/PocetMJ'));
        self::assertSame('0.00', $this->xpathOne($xml, '//SeznamPolozek/Polozka[1]/SazbaDPH'));
        self::assertSame('0.00', $this->xpathOne($xml, '//SeznamPolozek/Polozka[1]/Cena'));
        self::assertSame('2', $this->xpathOne($xml, '//SeznamPolozek/Polozka[2]/Poradi'));
        self::assertSame('prodej zboží a služeb', $this->xpathOne($xml, '//FaktVyd/Popis'));

        $single = $this->exporter->buildXml([$this->invoice([
            'items' => [
                $this->item(['item_kind' => 'text', 'description' => 'Zakazka 42', 'quantity' => 0.0,
                    'unit_price_without_vat' => 0.0, 'total_without_vat' => 0.0, 'total_vat' => 0.0, 'total_with_vat' => 0.0]),
                $this->item(),
            ],
        ])]);
        self::assertSame('prodej zbozi', $this->xpathOne($single, '//FaktVyd/Popis'));
    }

    public function testBuyerPartyUsesClientSnapshotAndSupplierUsesOwnCompany(): void
    {
        $xml = $this->exporter->buildXml([$this->invoice()]);

        self::assertSame('Kamody s.r.o.', $this->xpathOne($xml, '//FaktVyd/DodOdb/Nazev'));
        self::assertSame('Sokolska 217', $this->xpathOne($xml, '//FaktVyd/DodOdb/Adresa/Ulice'));
        self::assertSame('27467392', $this->xpathOne($xml, '//FaktVyd/DodOdb/ICO'));
        self::assertSame('Kamody s.r.o.', $this->xpathOne($xml, '//FaktVyd/KonecPrij/Nazev'));
        self::assertSame('SPORT s.r.o.', $this->xpathOne($xml, '//FaktVyd/MojeFirma/Nazev'));
        self::assertSame('12345678', $this->xpathOne($xml, '//FaktVyd/MojeFirma/ICO'));
        self::assertSame('CZK', $this->xpathOne($xml, '//FaktVyd/MojeFirma/MenaKod'));
    }

    public function testForeignCurrencySetsValutyPropFlag(): void
    {
        $xml = $this->exporter->buildXml([$this->invoice(['currency' => 'EUR'])]);

        self::assertSame('1', $this->xpathOne($xml, '//FaktVyd/ValutyProp'));
        self::assertSame('EUR', $this->xpathOne($xml, '//FaktVyd/MojeFirma/MenaKod'));
    }

    public function testItemLinePerUnitBucketsMatchLineTotalsForSingleUnit(): void
    {
        $xml = $this->exporter->buildXml([$this->invoice()]);

        self::assertSame('32526.00', $this->xpathOne($xml, '//SeznamPolozek/Polozka/SouhrnDPH/Zaklad_MJ'));
        self::assertSame('6830.46', $this->xpathOne($xml, '//SeznamPolozek/Polozka/SouhrnDPH/DPH_MJ'));
        self::assertSame('32526.00', $this->xpathOne($xml, '//SeznamPolozek/Polozka/SouhrnDPH/Zaklad'));
        self::assertSame('6830.46', $this->xpathOne($xml, '//SeznamPolozek/Polozka/SouhrnDPH/DPH'));
    }

    public function testMinuteDurationUsesExactHoursAndPreciseHourlyAmounts(): void
    {
        $xml = $this->exporter->buildXml([$this->invoice([
            'items' => [$this->item([
                'duration_minutes' => 1,
                'quantity' => 0.017,
                'unit' => 'h',
                'unit_price_without_vat' => 1000.123456,
                'total_without_vat' => 16.67,
                'total_vat' => 3.50,
                'total_with_vat' => 20.17,
            ])],
            'total_with_vat' => 20.17,
            'amount_to_pay' => 20.17,
        ])]);

        self::assertSame('0.016666666667', $this->xpathOne($xml, '//SeznamPolozek/Polozka/PocetMJ'));
        self::assertSame('1000.123456', $this->xpathOne($xml, '//SeznamPolozek/Polozka/SouhrnDPH/Zaklad_MJ'));
        self::assertSame('210.00', $this->xpathOne($xml, '//SeznamPolozek/Polozka/SouhrnDPH/DPH_MJ'));
        self::assertSame('16.67', $this->xpathOne($xml, '//SeznamPolozek/Polozka/SouhrnDPH/Zaklad'));
    }

    private function xpathOne(string $xml, string $expr): ?string
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml), 'Money S3 XML neni well-formed.');
        $xp = new \DOMXPath($dom);
        $node = $xp->query($expr)->item(0);

        return $node?->textContent;
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function invoice(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'invoice_type' => 'invoice',
            'varsymbol' => '1026001',
            'issue_date' => '2026-06-16',
            'tax_date' => '2026-06-16',
            'due_date' => '2026-06-30',
            'currency' => 'CZK',
            'payment_method' => 'bank_transfer',
            'created_by' => 7,
            'created_by_name' => 'Chytra Lola',
            'advance_paid_amount' => 0.0,
            'total_with_vat' => 39356.46,
            'amount_to_pay' => 39356.46,
            'constant_symbol' => '0008',
            'client_snapshot' => [
                'company_name' => 'Kamody s.r.o.',
                'street' => 'Sokolska 217',
                'city' => 'Usti nad Orlici',
                'zip' => '56204',
                'country_iso2' => 'CZ',
                'ic' => '27467392',
                'dic' => 'CZ27467392',
            ],
            'supplier_snapshot' => [
                'company_name' => 'SPORT s.r.o.',
                'street' => 'Merhautova 128',
                'city' => 'Brno-Cerna Pole',
                'zip' => '61300',
                'country_iso2' => 'CZ',
                'ic' => '12345678',
                'dic' => 'CZ12345678',
                'email' => 'info@demosport.cz',
                'is_vat_payer' => true,
            ],
            'bank_snapshot' => [
                'account_number' => '7098760359',
                'bank_code' => '0100',
                'bank_name' => 'Komercni banka, a.s.',
            ],
            'items' => [$this->item()],
        ], $overrides);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function item(array $overrides = []): array
    {
        return array_merge([
            'description' => 'prodej zbozi',
            'quantity' => 1.0,
            'unit' => 'ks',
            'unit_price_without_vat' => 32526.0,
            'vat_rate_snapshot' => 21.0,
            'total_without_vat' => 32526.0,
            'total_vat' => 6830.46,
            'total_with_vat' => 39356.46,
        ], $overrides);
    }
}
