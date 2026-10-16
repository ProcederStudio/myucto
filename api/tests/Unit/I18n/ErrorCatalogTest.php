<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\I18n;

use MyInvoice\I18n\ErrorCatalog;
use MyInvoice\I18n\Locale;
use PHPUnit\Framework\TestCase;

final class ErrorCatalogTest extends TestCase
{
    public function testCsLocaleReturnsInputUnchanged(): void
    {
        self::assertSame(
            'Vystavenou fakturu nelze editovat.',
            ErrorCatalog::lookup('Vystavenou fakturu nelze editovat.', 'cs'),
        );
    }

    public function testEnLocaleTranslatesKnownMessage(): void
    {
        self::assertSame(
            'An issued invoice cannot be edited.',
            ErrorCatalog::lookup('Vystavenou fakturu nelze editovat.', 'en'),
        );
    }

    public function testCanonicalHostnameConflictHasEnglishTranslation(): void
    {
        self::assertSame(
            'The hostname configured in app.url cannot be used as a company custom domain. Enter a different hostname.',
            ErrorCatalog::lookup(
                'Hostname nastavený v app.url nelze použít jako vlastní doménu firmy. Zadejte jiný hostname.',
                'en',
            ),
        );
    }

    public function testNewDomainBoundaryErrorsHaveEnglishTranslations(): void
    {
        $messages = [
            'Canonical hostname koliduje s vlastní doménou firmy.'
                => 'The canonical hostname conflicts with a company custom domain.',
            'Canonical přechod není pro tuto cestu povolený.'
                => 'Canonical handoff is not allowed for this path.',
            'DNS nebo HTTPS ověření domény selhalo.'
                => 'DNS or HTTPS domain verification failed.',
            'Doména musí mít čerstvě ověřené DNS a HTTPS.'
                => 'The domain must have freshly verified DNS and HTTPS.',
            'Doména se během ověření změnila; spusť kontrolu znovu.'
                => 'The domain changed during verification; run the check again.',
            'Doména se před ověřením změnila; spusť kontrolu znovu.'
                => 'The domain changed before verification; run the check again.',
        ];

        foreach ($messages as $cs => $en) {
            self::assertSame($en, ErrorCatalog::lookup($cs, 'en'), $cs);
        }
    }

    public function testSystemDimensionAndProvenanceErrorsHaveEnglishCatalogEntries(): void
    {
        $messages = [
            'Systémovou dimenzi ani význam použité hodnoty nelze změnit. Pro nový význam založte novou hodnotu.' => 'A system dimension or the meaning of a used value cannot be changed. Create a new value for a new meaning.',
            'Kód systémové dimenze již používá vlastní typ. Vlastní typ nelze automaticky převést.' => 'A custom type already uses the system dimension code. A custom type cannot be converted automatically.',
            'Kód systémové hodnoty již používá vlastní hodnota.' => 'A custom value already uses the system value code.',
            'Zápis nelze zaúčtovat bez povinných systémových dimenzí.' => 'An entry cannot be posted without the required system dimensions.',
            'Systémová dimenze nepatří firmě.' => 'The system dimension does not belong to this company.',
            'Rozdělení více systémových dimenzí musí mít společné alokační řádky.' => 'Splitting multiple system dimensions requires shared allocation rows.',
            'Hodnota systémové dimenze nepatří firmě nebo typu.' => 'The system dimension value does not belong to this company or type.',
            'Uzavřenou hodnotu systémové dimenze nelze nově přiřadit.' => 'A closed system dimension value cannot be assigned to a new row.',
            'Převod účetnictví je podporován pouze pro období vedená celé v rámci vyhlášky 500.' => 'Accounting migration is supported only for periods maintained entirely under Decree 500.',
            'Neplatný původ přiřazení dimenze.' => 'Invalid dimension assignment origin.',
            'Vlastní účet nemá ověřenou vazbu na účetní rámec.' => 'The custom account has no verified binding to the accounting framework.',
            'Protizápis neodpovídá živému zaúčtovanému zdroji.' => 'The reversal does not match a live posted source entry.',
            'Konec intervalu nesmí předcházet začátku.' => 'The interval end must not precede its start.',
        ];
        foreach ($messages as $cs => $en) {
            self::assertSame($en, ErrorCatalog::lookup($cs, 'en'), $cs);
            self::assertSame($cs, ErrorCatalog::lookup($cs, 'cs'));
        }
    }

    public function testEnLocaleReturnsInputForUnknownMessage(): void
    {
        self::assertSame(
            'Tohle není v katalogu',
            ErrorCatalog::lookup('Tohle není v katalogu', 'en'),
        );
    }

    public function testEmptyStringReturnsEmpty(): void
    {
        self::assertSame('', ErrorCatalog::lookup('', 'en'));
    }

    public function testPrefixMatchForExceptionMessages(): void
    {
        // V katalogu: "Email se nepodařilo odeslat: " => "Failed to send email: "
        // Volání s konkatenovanou příčinou musí prefix přeložit.
        self::assertSame(
            'Failed to send email: connection timeout',
            ErrorCatalog::lookup('Email se nepodařilo odeslat: connection timeout', 'en'),
        );
    }

    public function testLocaleStateGlobal(): void
    {
        Locale::set('en');
        self::assertSame('en', Locale::current());
        Locale::set('cs');
        self::assertSame('cs', Locale::current());
        // Reset na default po testu, aby neovlivnil další testy
    }

    public function testLocaleSetUnknownFallsBackToCs(): void
    {
        Locale::set('xx');
        self::assertSame('cs', Locale::current());
    }
}
