<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Repository\DocumentLinkRepository;
use MyInvoice\Service\Import\LlmProviderRegistry;
use MyInvoice\Service\Validation\PurchaseInvoiceValidation;
use MyInvoice\Support\PaymentMethods;
use PHPUnit\Framework\TestCase;

/**
 * Enumy v `openapi.yaml` musí odpovídat doméně, kterou vynucuje kód nebo databáze.
 *
 * Audit spec našel pět rozejitých výčtů — `journal_entries.source_type` měl ve spec
 * 9 z 22 hodnot, `payment_method` 4 ze 7 (chybělo `direct_debit`, na kterém stojí
 * ochrana proti dvojí platbě), a podobně `invoice_type`, `document_kind`,
 * `vat_deduction` a typy dokumentových vazeb. Striktní klient podle takové spec
 * odmítne platnou odpověď serveru.
 *
 * Test bere doménu z jediného zdroje pravdy (PHP konstanta, jinak ENUM v migraci)
 * a hlídá, že spec neuvádí míň. Víc uvádět smí — zúžení proti kódu je chyba,
 * rozšíření o hodnoty, které kód teprve dostane, ne.
 */
final class OpenApiEnumSourceOfTruthTest extends TestCase
{
    public function testPaymentMethodEnumsCoverWholeDomain(): void
    {
        $this->assertEveryEnumNamed('payment_method', PaymentMethods::ALL);
    }

    public function testDocumentLinkEntityTypesCoverWholeDomain(): void
    {
        // `entity_type` nese i AiPostingSuggestion, ale s vlastní doménou (zná
        // `cash_transaction`) — rozliší se tím, že neobsahuje `client`.
        $this->assertEveryEnumNamed(
            'entity_type',
            DocumentLinkRepository::ENTITY_TYPES,
            static fn (array $values): bool => in_array('client', $values, true),
        );
    }

    public function testPurchaseDocumentKindsCoverWholeDomain(): void
    {
        $this->assertEveryEnumNamed('document_kind', PurchaseInvoiceValidation::ALLOWED_DOC_KINDS);
    }

    public function testJournalSourceTypesCoverWholeDomain(): void
    {
        $snapshot = json_decode($this->read('db/schema.snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
        $definition = $snapshot['tables']['journal_entries']['columns']['source_type'] ?? null;
        self::assertIsString($definition);
        self::assertSame(1, preg_match('/^enum\(([^)]+)\)/i', $definition, $matches));
        preg_match_all("/'([^']+)'/", $matches[1], $values);
        $domain = $values[1];
        self::assertGreaterThan(20, count($domain), 'Doména source_type se nenačetla z aktuálního otisku migrací.');
        // Report dávkového účtování má vlastní `source_type` jen pro doklady, ze
        // kterých se účtuje — pozná se tím, že nezná ruční zápis.
        $this->assertEveryEnumNamed(
            'source_type',
            $domain,
            static fn (array $values): bool => in_array('manual', $values, true),
        );
    }

    public function testVatDeductionCoversWholeDomain(): void
    {
        $domain = $this->enumFromMigration('1038_vat_reduced_deduction_coefficients.sql', 'vat_deduction');
        self::assertNotEmpty($domain, 'Doména vat_deduction se nenačetla z migrace.');
        $this->assertEveryEnumNamed('vat_deduction', $domain);
    }

    public function testInvoiceResponseTypeCoversWholeDomain(): void
    {
        $domain = $this->enumFromMigration('1170_simplified_doc_and_payment_calendar.sql', 'invoice_type');
        self::assertNotEmpty($domain, 'Doména invoice_type se nenačetla z migrace.');
        // Jen odpověď (`Invoice`): zápisová schémata vědomě nabízejí užší výběr,
        // protože `cancellation` a `tax_document` vznikají vlastními endpointy.
        self::assertSame(
            [],
            array_values(array_diff($domain, $this->enumAfter('    Invoice:', 'invoice_type'))),
            'Invoice.invoice_type ve spec neuvádí celou doménu sloupce.',
        );
    }

    public function testAiProvidersCoverWholeDomain(): void
    {
        $this->assertEveryEnumNamed('dpa_confirm', LlmProviderRegistry::PROVIDERS);
        $this->assertEveryEnumNamed('dpa_revoke', LlmProviderRegistry::PROVIDERS);
        self::assertSame(
            [],
            array_values(array_diff(LlmProviderRegistry::PROVIDERS, $this->enumAfter('    AiAssistSettings:', 'provider'))),
            'AiAssistSettings.provider ve spec neuvádí všechny poskytovatele.',
        );
    }

    /**
     * Každý výčet pojmenovaný `$field` musí obsahovat celou doménu.
     *
     * Pár jmen nese víc než jednu doménu (`entity_type`, `source_type`) — pro ty
     * se předá `$applies`, které rozhodne, jestli konkrétní výskyt do kontroly patří.
     *
     * @param list<string>                 $domain
     * @param null|callable(list<string>): bool $applies
     */
    private function assertEveryEnumNamed(string $field, array $domain, ?callable $applies = null): void
    {
        $found = 0;
        foreach ($this->enums() as [$line, $name, $values]) {
            if ($name !== $field) {
                continue;
            }
            if ($applies !== null && !$applies($values)) {
                continue;
            }
            $found++;
            $missing = array_values(array_diff($domain, $values));
            self::assertSame([], $missing, sprintf(
                'Výčet `%s` na řádku %d neuvádí: %s',
                $field,
                $line,
                implode(', ', $missing),
            ));
        }
        self::assertGreaterThan(0, $found, sprintf('Ve spec nebyl nalezen žádný výčet `%s`.', $field));
    }

    /**
     * Výčty ve spec jako trojice (řádek, název pole, hodnoty).
     *
     * Název se bere ze stejného řádku (inline zápis `pole: { … enum: […] }` nebo
     * `name: …` u parametru), jinak z posledního klíče nad ním — víceřádkový zápis
     * má `enum:` až pod názvem pole. `filter[x]` se normalizuje na `x`.
     *
     * @return list<array{0:int,1:string,2:list<string>}>
     */
    private function enums(): array
    {
        // Klíče jazyka samotného — nesmí přepsat zapamatovaný název pole, jinak by
        // se víceřádkový zápis (`pole:` / `type: string` / `enum: […]`) jmenoval `type`.
        static $noise = [
            'type', 'format', 'description', 'default', 'items', 'properties', 'schema',
            'example', 'minimum', 'maximum', 'maxLength', 'minLength', 'pattern', 'title',
            'required', 'allOf', 'oneOf', 'anyOf', 'nullable', 'readOnly', 'writeOnly',
            'additionalProperties', 'in', 'content', 'enum',
        ];

        // \R kvůli CRLF v pracovním stromu (git autocrlf na Windows).
        $lines = preg_split('/\R/', $this->read('api/openapi.yaml')) ?: [];
        $out = [];
        $lastKey = '';

        foreach ($lines as $i => $line) {
            $hasEnum = preg_match('/enum:\s*\[([^\]]+)\]/', $line, $enumMatch) === 1;

            $name = null;
            if (preg_match('/\bname:\s*([^,}\s]+)/', $line, $m) === 1) {
                $name = $m[1];
            } elseif (preg_match('/^\s*-?\s*(\w+):/', $line, $m) === 1 && $m[1] !== 'enum') {
                $name = $m[1];
            }
            if ($name !== null) {
                $name = trim($name, " \t\"'");
                if (preg_match('/^filter\[(\w+)\]$/', $name, $m) === 1) {
                    $name = $m[1];
                }
                if (in_array($name, $noise, true)) {
                    $name = null;
                } else {
                    $lastKey = $name;
                }
            }

            if (!$hasEnum) {
                continue;
            }
            $values = array_map(
                static fn (string $v): string => trim($v, " \t\"'"),
                explode(',', $enumMatch[1]),
            );
            $out[] = [$i + 1, $name ?? $lastKey, $values];
        }

        return $out;
    }

    /**
     * Výčet daného pole uvnitř pojmenovaného schématu (podporuje i víceřádkový zápis).
     *
     * @return list<string>
     */
    private function enumAfter(string $schemaLine, string $property): array
    {
        // \R kvůli CRLF v pracovním stromu (git autocrlf na Windows).
        $lines = preg_split('/\R/', $this->read('api/openapi.yaml')) ?: [];
        $inSchema = false;
        $inProperty = false;
        foreach ($lines as $line) {
            if (rtrim($line) === $schemaLine) {
                $inSchema = true;
                continue;
            }
            if ($inSchema && preg_match('/^    \S/', $line) === 1) {
                break; // další schéma
            }
            if (!$inSchema) {
                continue;
            }
            if (preg_match('/^        ' . preg_quote($property, '/') . ':/', $line) === 1) {
                $inProperty = true;
            }
            if ($inProperty && preg_match('/enum:\s*\[([^\]]+)\]/', $line, $m) === 1) {
                return array_map(
                    static fn (string $v): string => trim($v, " \t\"'"),
                    explode(',', $m[1]),
                );
            }
        }

        return [];
    }

    /**
     * Hodnoty ENUM(...) daného sloupce z migrace.
     *
     * @return list<string>
     */
    private function enumFromMigration(string $file, string $column): array
    {
        $sql = $this->read('db/migrations/' . $file);
        if (preg_match('/' . preg_quote($column, '/') . '\s+ENUM\s*\(([^)]+)\)/i', $sql, $m) !== 1) {
            return [];
        }
        preg_match_all("/'([^']+)'/", $m[1], $vals);

        return $vals[1] ?? [];
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
        self::assertIsString($contents, $relativePath);

        return $contents;
    }
}
