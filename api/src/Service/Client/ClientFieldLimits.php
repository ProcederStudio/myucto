<?php

declare(strict_types=1);

namespace MyInvoice\Service\Client;

use MyInvoice\Support\CompanyIdNormalizer;

/**
 * Délky textových sloupců `clients`, jediný zdroj pro obě cesty zápisu karty:
 *
 * - {@see errors()} pro ruční zadání (UI, REST API): delší hodnota je chyba
 *   uživatele a vrací se jako `validation_failed`, ne jako pád uložení na DB.
 * - {@see fit()} pro cizí data (AI vytěžení, importy, ARES/VIES, e-shop):
 *   hodnota se upraví tak, aby se vešla. Volá ji přímo {@see \MyInvoice\Repository\ClientRepository}
 *   při create/update, takže žádná importní cesta nemůže shodit request na
 *   SQLSTATE 22001 (Data too long).
 *
 * Délky odpovídají `db/schema.snapshot.json`; hlídá to ClientFieldLimitsSchemaTest.
 */
final class ClientFieldLimits
{
    public const MAX_LENGTHS = [
        'company_name'       => 190,
        'first_name'         => 60,
        'last_name'          => 60,
        'ic'                 => 20,
        'dic'                => 20,
        'tax_number'         => 30,
        'street'             => 190,
        'city'               => 120,
        'zip'                => 10,
        'main_email'         => 190,
        'phone'              => 40,
        'related_party_note' => 255,
    ];

    /**
     * @param array<string,mixed> $data
     * @return array<string,string[]>
     */
    public static function errors(array $data): array
    {
        $err = [];
        foreach (self::MAX_LENGTHS as $field => $max) {
            $value = $data[$field] ?? null;
            if (!is_scalar($value) || mb_strlen(trim((string) $value)) <= $max) {
                continue;
            }
            $err[$field][] = $field === 'zip'
                ? "PSČ může mít nejvýše {$max} znaků (město patří do pole Město)"
                : "Text je příliš dlouhý (nejvýše {$max} znaků)";
        }
        return $err;
    }

    /**
     * Hodnoty, které se vejdou, zůstávají beze změny. Identifikátory (IČO, DIČ) se
     * nejdřív normalizují; když se ani pak nevejdou, jsou zjevně špatně vytěžené
     * a zahodí se, protože useknuté DIČ by vypadalo jako platné cizí DIČ. PSČ
     * přijde o mezery a zkrátí se, ostatní texty se jen zkrátí.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function fit(array $data): array
    {
        foreach (self::MAX_LENGTHS as $field => $max) {
            $value = $data[$field] ?? null;
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if (mb_strlen($value) <= $max) {
                continue;
            }
            $data[$field] = match ($field) {
                'dic'   => self::fitIdentifier($value, $max, '/^[A-Z]{2}[0-9A-Z]+$/'),
                'ic'    => self::fitIdentifier($value, $max, '/^\d+$/'),
                'zip'   => mb_substr(self::withoutWhitespace($value), 0, $max),
                default => mb_substr($value, 0, $max),
            };
        }
        return $data;
    }

    /**
     * Normalizace smí odstranit jen mezery a oddělovače (tečka, pomlčka, lomítko).
     * Kdyby odstraňovala i popisky („DIČ:", „IČO") a závorky jako
     * {@see CompanyIdNormalizer}, slepila by z vytěženého řádku identifikátor, který
     * se vejde, ale patří někomu jinému nebo nikomu.
     */
    private static function fitIdentifier(string $value, int $max, string $shape): ?string
    {
        $compact = self::withoutWhitespace($value);
        if (mb_strlen($compact) <= $max) {
            return $compact;
        }
        $normalized = strtoupper(preg_replace('/[.\-\/_]+/', '', $compact) ?? $compact);
        return mb_strlen($normalized) <= $max && preg_match($shape, $normalized) === 1 ? $normalized : null;
    }

    private static function withoutWhitespace(string $value): string
    {
        return preg_replace('/[\s\x{00A0}]+/u', '', $value) ?? $value;
    }
}
