<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Mail\EmailTemplateCatalog;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('architecture')]
final class EmailTemplateLabelsI18nTest extends TestCase
{
    /**
     * Seznam e-mailových šablon v administraci se skládá z kódů, které vrátí API
     * ({@see EmailTemplateCatalog} `CODES`), a popisek hledá dynamicky sestaveným
     * klíčem `users.et_known_codes.${code}`. Chybějící překlad se vypíše jako syrový
     * klíč a statická i18n brána ho nevidí.
     */
    public function testEveryKnownTemplateHasCzechAndEnglishLabel(): void
    {
        $codes = EmailTemplateCatalog::CODES;
        self::assertIsArray($codes);
        self::assertNotEmpty($codes);

        $root = dirname(__DIR__, 3);
        foreach (['cs', 'en'] as $locale) {
            $messages = json_decode(
                (string) file_get_contents($root . "/web/src/i18n/{$locale}.json"),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            foreach ($codes as $code) {
                $label = $messages['users']['et_known_codes'][$code] ?? null;
                self::assertIsString($label, "Chybí users.et_known_codes.{$code} v {$locale}.json");
                self::assertNotSame('', trim($label), "Prázdný users.et_known_codes.{$code} v {$locale}.json");
            }
        }
    }
}
