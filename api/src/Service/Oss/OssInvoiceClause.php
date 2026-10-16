<?php

declare(strict_types=1);

namespace MyInvoice\Service\Oss;

use MyInvoice\Service\Invoice\InvoiceItemKind;

/**
 * Doložka o odvodu daně v režimu jednoho správního místa (OSS) na dokladu.
 *
 * Doklad s řádky v OSS nese cizí sazbu daně, ale nikde na něm nestálo, PROČ tam ta sazba
 * je a kdo tu daň odvádí. Odběratel i účetní si to bez doložky přečtou jako českou daň
 * ve špatné sazbě — stejný důvod, proč doklad nese doložku o přenesení daňové povinnosti
 * (§ 92a / čl. 196). OSS doložka je jejím protějškem pro B2C plnění do jiného členského
 * státu (§ 110a a násl. zákona o DPH, zvláštní režim dle čl. 369a a násl. směrnice
 * 2006/112/ES).
 *
 * Třída je záměrně čistá (žádná DB) a je JEDINÝM zdrojem rozhodnutí „nese doklad OSS
 * doložku a za které státy" — tiskne ji PDF šablona i veřejný HTML náhled, a ty se
 * nesmí rozejít.
 */
final class OssInvoiceClause
{
    /**
     * Postaví podklad pro doložku, nebo null, když doklad žádný OSS řádek nemá.
     *
     * `all_items` odlišuje doklad, který je celý v OSS, od smíšeného (část plnění je
     * tuzemská). U smíšeného se nesmí tvrdit, že se daň odvádí ve státě spotřeby —
     * platí to jen o části řádků, a šablona pro ten případ volí opatrnější větu.
     *
     * Seznam států je buď ÚPLNÝ, nebo prázdný: kdyby některý OSS řádek zemi spotřeby
     * neměl (legacy import), vypsaný neúplný výčet by na dokladu lhal. Prázdný seznam
     * šablona vyřeší obecnou větou bez jmenování států.
     *
     * @param list<array<string,mixed>>|array<mixed> $items Položky dokladu
     * @param array<string,array{name_cs?:string|null,name_en?:string|null}> $countryNames ISO2 → názvy státu
     * @return array{all_items:bool, countries:list<array{iso2:string,name_cs:string,name_en:string}>}|null
     */
    public static function build(array $items, array $countryNames = []): ?array
    {
        $hasOss = false;
        $supplyLines = 0;
        $ossSupplyLines = 0;
        $countries = [];
        $countryMissing = false;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $isOss = !empty($item['oss_applicable']);
            // Slevový řádek nemá vlastní plnění — kopíruje režim dokladu. Kdyby se počítal
            // mezi řádky plnění, udělal by ze zcela OSS dokladu smíšený.
            // Textový řádek (InvoiceItemKind::TEXT) plnění není vůbec.
            if (InvoiceItemKind::isText($item)) {
                continue;
            }
            if ((string) ($item['item_kind'] ?? 'standard') !== 'discount') {
                $supplyLines++;
                if ($isOss) {
                    $ossSupplyLines++;
                }
            }
            if (!$isOss) {
                continue;
            }
            $hasOss = true;

            $iso2 = strtoupper(trim((string) ($item['oss_consumer_country'] ?? '')));
            if (preg_match('/^[A-Z]{2}$/', $iso2) !== 1) {
                $countryMissing = true;
                continue;
            }
            $countries[$iso2] = true;
        }

        if (!$hasOss) {
            return null;
        }

        $list = [];
        if (!$countryMissing) {
            $codes = array_keys($countries);
            sort($codes, SORT_STRING);
            foreach ($codes as $iso2) {
                $names = $countryNames[$iso2] ?? [];
                $cs = trim((string) ($names['name_cs'] ?? ''));
                $en = trim((string) ($names['name_en'] ?? ''));
                $list[] = [
                    'iso2'    => $iso2,
                    // Bez názvu v číselníku je pravdivější vytisknout ISO kód než nic —
                    // doložka pořád jmenuje konkrétní stát.
                    'name_cs' => $cs !== '' ? $cs : $iso2,
                    'name_en' => $en !== '' ? $en : ($cs !== '' ? $cs : $iso2),
                ];
            }
        }

        return [
            'all_items' => $supplyLines === 0 || $ossSupplyLines === $supplyLines,
            'countries' => $list,
        ];
    }

    /**
     * ISO2 kódy států spotřeby na dokladu — pro dotaz do číselníku zemí.
     *
     * @param list<array<string,mixed>>|array<mixed> $items
     * @return list<string>
     */
    public static function consumerCountryCodes(array $items): array
    {
        $codes = [];
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['oss_applicable'])) {
                continue;
            }
            $iso2 = strtoupper(trim((string) ($item['oss_consumer_country'] ?? '')));
            if (preg_match('/^[A-Z]{2}$/', $iso2) === 1) {
                $codes[$iso2] = true;
            }
        }
        return array_keys($codes);
    }
}
