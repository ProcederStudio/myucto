<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Repository;

use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\Expense\ExpenseKind;
use PHPUnit\Framework\TestCase;

/**
 * PurchaseInvoiceRepository::defaultClassificationCode — auto-klasifikace
 * přijatých dokladů podle sazby + RC + země dodavatele, nově s parametrem
 * základní sazby z číselníku daňových konstant (dřív natvrdo 21).
 */
final class DefaultClassificationCodeTest extends TestCase
{
    public function testDomesticByRate(): void
    {
        $f = fn (float $r, bool $rc = false, ?string $iso = 'CZ') =>
            PurchaseInvoiceRepository::defaultClassificationCode($r, $rc, $iso);

        $this->assertSame('40', $f(21.0), 'tuzemská základní');
        $this->assertSame('41', $f(12.0), 'tuzemská snížená');
        $this->assertSame('41', $f(15.0), 'historická snížená 15');
        $this->assertSame('5',  $f(21.0, rc: true), 'tuzemský RC');
        $this->assertNull($f(0.0), 'nulová sazba CZ → user vybere');
        $this->assertNull($f(19.0), 'cizí sazba (např. DE 19 %) se nemapuje');
    }

    public function testForeignVendorZeroRate(): void
    {
        $f = fn (string $iso) =>
            PurchaseInvoiceRepository::defaultClassificationCode(0.0, false, $iso);

        // Zahraniční 0 % = reverse charge SLUŽBA (nejčastější: digitální předplatná).
        // EU → 24e (ř.5), 3. země → 24 (ř.12). Zboží (ř.3/ř.7) ze sazby nepoznáme →
        // u zboží vybírá kód AI/uživatel.
        $this->assertSame('24e', $f('DE'), 'EU vendor 0 % → služba z EU (ř.5)');
        $this->assertSame('24e', $f('IE'));
        $this->assertSame('24', $f('US'), '3. země 0 % → služba ze 3. země (ř.12)');
        $this->assertSame('24', $f('GB'));
    }

    public function testForeignReverseChargeStandardRateByNature(): void
    {
        $f = fn (string $iso, ?bool $goods) => PurchaseInvoiceRepository::defaultClassificationCode(
            21.0, true, $iso, 21.0, false, true, $goods
        );

        // issue #150: služba z EU s 21 % a přenesením dostávala '23' a padala na ř. 3.
        $this->assertSame('24e', $f('DE', null), 'EU + RC + 21 %, povaha neznámá → služba z EU (ř. 5)');
        $this->assertSame('24e', $f('DE', false), 'EU služba → ř. 5');
        $this->assertSame('23', $f('DE', true), 'EU zboží → pořízení z JČS (ř. 3)');
        $this->assertSame('24', $f('US', null), '3. země, povaha neznámá → služba (ř. 12)');
        $this->assertSame('24', $f('US', false));
        $this->assertSame('25', $f('US', true), '3. země zboží → dovoz (ř. 7)');
        $this->assertSame('5', $f('CZ', true), 'tuzemský § 92a se povahou plnění neřídí');
    }

    public function testSupplyIsGoods(): void
    {
        $this->assertTrue(PurchaseInvoiceRepository::supplyIsGoods(null, true), 'skladová karta');
        $this->assertTrue(PurchaseInvoiceRepository::supplyIsGoods(ExpenseKind::Material, false));
        $this->assertTrue(PurchaseInvoiceRepository::supplyIsGoods(ExpenseKind::SmallAsset, false));
        $this->assertFalse(PurchaseInvoiceRepository::supplyIsGoods(ExpenseKind::Service, false));
        $this->assertFalse(PurchaseInvoiceRepository::supplyIsGoods(ExpenseKind::SmallIntangible, false));
        $this->assertNull(PurchaseInvoiceRepository::supplyIsGoods(ExpenseKind::FixedAsset, false));
        $this->assertNull(PurchaseInvoiceRepository::supplyIsGoods(null, false));
        $this->assertTrue(PurchaseInvoiceRepository::supplyIsGoods(ExpenseKind::Service, true), 'skladová karta má přednost');
    }

    public function testStandardRateParameterFromTaxConstants(): void
    {
        // Hypotetická budoucí změna základní sazby na 22 % — klasifikace se musí
        // řídit parametrem (číselník daňových konstant), ne zadrátovanou 21.
        $f = fn (float $r, float $std) =>
            PurchaseInvoiceRepository::defaultClassificationCode($r, false, 'CZ', $std);

        $this->assertSame('40', $f(22.0, 22.0), 'nová základní 22 → tuzemská základní');
        $this->assertNull($f(21.0, 22.0), 'stará 21 už není základní ani snížená (16–21) → user vybere');
        $this->assertSame('41', $f(12.0, 22.0), 'snížená beze změny');
        // RC varianty respektují tentýž parametr
        $this->assertSame('5', PurchaseInvoiceRepository::defaultClassificationCode(22.0, true, 'CZ', 22.0));
        $this->assertSame('24e', PurchaseInvoiceRepository::defaultClassificationCode(22.0, true, 'DE', 22.0));
        $this->assertNull(PurchaseInvoiceRepository::defaultClassificationCode(21.0, true, 'DE', 22.0, isGoods: true), 'stará 21 už není základní');
    }

    public function testPublicAuthorityFeeStaysOutOfScope(): void
    {
        // issue #30: soudní poplatek německému soudu. Orgán veřejné moci není osobou
        // povinnou k dani (§ 5 odst. 4 ZDPH) → žádné samovyměření § 9/1, žádný kód.
        $f = fn (string $iso, bool $fee) =>
            PurchaseInvoiceRepository::defaultClassificationCode(0.0, false, $iso, 21.0, $fee);

        $this->assertNull($f('DE', true), 'soudní poplatek zahraničnímu soudu → mimo předmět daně');
        $this->assertNull($f('CZ', true), 'tuzemský soudní poplatek → mimo předmět daně');
        $this->assertNull($f('US', true));
        $this->assertSame('24e', $f('DE', false), 'běžná EU služba se samovyměřuje dál');
    }

    public function testDomesticReverseChargeZeroRate(): void
    {
        // Tuzemský § 92a doklad je bez vyčíslené daně (0 %) — plátci patří na ř.10 + KH B.1.
        $this->assertSame(
            '5',
            PurchaseInvoiceRepository::defaultClassificationCode(0.0, true, 'CZ'),
            'plátce v tuzemském režimu přenesené povinnosti'
        );
    }

    public function testNonPayerNeverGetsDomesticReverseCharge(): void
    {
        // § 92a funguje jen mezi plátci. Identifikovaná osoba ani neplátce v něm být
        // nemůže → kód '5' by jí vyrobil samovyměření na ř.10 a větu KH B.1, které nedluží.
        $f = fn (float $rate) => PurchaseInvoiceRepository::defaultClassificationCode(
            $rate, true, 'CZ', 21.0, false, tenantIsVatPayer: false
        );

        // 21 % propadne na tuzemské '40' — do přiznání neplátce stejně nevstoupí
        // (VatLedgerService filtruje podle plátcovství k DUZP), jen se nevyrobí ř.10/KH B.1.
        $this->assertSame('40', $f(21.0), 'neplátce → běžné tuzemské plnění, ne § 92a');
        $this->assertNull($f(0.0), 'nulová sazba u neplátce → bez klasifikace');
        // Zahraniční samovyměření (§ 108) se identifikované osoby naopak TÝKÁ.
        $this->assertSame(
            '24e',
            PurchaseInvoiceRepository::defaultClassificationCode(0.0, true, 'DE', 21.0, false, tenantIsVatPayer: false),
            'identifikovaná osoba přijímající službu z EU samovyměřuje dál'
        );
    }
}
