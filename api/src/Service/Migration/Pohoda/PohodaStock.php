<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

/**
 * Sklad z datového souboru POHODY (`92_sklad.xml`, skupina `sklad` exportního nástroje):
 * sklady, členění skladu, karty zásob, ceníky a ocenění stavu karty.
 *
 * Karta zásoby je v POHODĚ vedená zvlášť v každém skladu (`SKz.IDS` + `RefSklad`), v MyÚčtu
 * je karta jedna pro firmu a stav se drží po skladech. Hodnota stavu je ocenění po
 * posledním pohybu karty (`SKzStav.KcOceneni`, nástroj ho dopočítá z `SKzPoh`); součin
 * stavu a průměrné ceny je jen náhradní cesta pro kartu bez pohybu - průměrná cena
 * je v POHODĚ zaokrouhlená a hodnotu skladu by o haléře až koruny posunula.
 *
 * Číselníky se čtou jedním průchodem souborem; položky ceníků (`SKzCn`, u velkých agend
 * statisíce řádků) až druhým průchodem v {@see priceRows()}.
 */
final class PohodaStock
{
    public const TYPE_CARD = 1;
    public const TYPE_TEXT = 2;
    public const TYPE_SERVICE = 3;
    public const TYPE_PACKAGE = 4;
    public const TYPE_SET = 5;
    public const TYPE_PRODUCT = 6;

    /** Druh zásoby (účtování způsobem A): ověřeno na reálných agendách, XSD POHODY ho čísluje jinak. */
    public const KIND_GOODS = 2;
    public const KIND_MATERIAL = 3;

    /** Sloupce karty, které převod potřebuje - zbytek (popisy, e-shop) se v paměti nedrží. */
    private const CARD_COLUMNS = ['ID', 'IDS', 'Nazev', 'RefSklad', 'RefStruct', 'RelSkTyp', 'RelSkDruh', 'RelSKzVC', 'MJ', 'MJ2', 'MJ2Koef',
        'MJ3', 'MJ3Koef', 'EAN', 'StavZ', 'VNakup', 'NakupC', 'ProdejKc', 'MinLim', 'Hmotnost', 'RelDPHp', 'RefAD'];

    /**
     * @param array<string,array{id:string,code:string,name:string}> $warehouses id => sklad
     * @param array<string,list<string>> $structure id uzlu členění => názvy větví od kořene
     * @param list<array<string,string>> $cards
     * @param array<string,array{value:float,date:string,moves:int}> $valuations id karty => ocenění po posledním pohybu
     * @param list<array{id:string,code:string,name:string,type:int,vat_included:bool,currency:string,discount:float}> $priceLists
     * @param array<string,int> $components id karty => počet položek kusovníku
     */
    private function __construct(
        public readonly string $file,
        public readonly ?string $exportedOn,
        public readonly array $warehouses,
        public readonly array $structure,
        public readonly array $cards,
        public readonly array $valuations,
        public readonly array $priceLists,
        public readonly array $components,
        public readonly ?string $lastMovement,
    ) {}

    public static function read(string $file): self
    {
        $info = PohodaXml::packInfo($file);
        $warehouses = [];
        $nodes = [];
        $cards = [];
        $valuations = [];
        $lists = [];
        $currencies = [];
        $components = [];
        $last = null;
        foreach (PohodaXml::scan($file, ['sSklad', 'SkSt', 'SKz', 'SKzStav', 'SkCeny', 'sCMeny', 'SKzPol']) as $tag => $row) {
            switch ($tag) {
                case 'sSklad':
                    $id = PohodaXml::text($row, 'ID');
                    $warehouses[$id] = ['id' => $id, 'code' => PohodaXml::text($row, 'IDS'), 'name' => PohodaXml::text($row, 'SText')];
                    break;
                case 'SkSt':
                    $path = [];
                    for ($i = 1; $i <= 7; $i++) {
                        $branch = PohodaXml::text($row, 'Vetev' . $i);
                        if ($branch === '') {
                            break;
                        }
                        $path[] = $branch;
                    }
                    $nodes[PohodaXml::text($row, 'ID')] = $path;
                    break;
                case 'SKz':
                    $card = [];
                    foreach (self::CARD_COLUMNS as $column) {
                        $card[$column] = PohodaXml::text($row, $column);
                    }
                    $cards[] = $card;
                    break;
                case 'SKzStav':
                    $date = substr(PohodaXml::text($row, 'Datum'), 0, 10);
                    $valuations[PohodaXml::text($row, 'RefSKz')] = ['value' => PohodaXml::num($row, 'KcOceneni'), 'date' => $date, 'moves' => (int) PohodaXml::text($row, 'Pohybu')];
                    if ($date !== '' && ($last === null || $date > $last)) {
                        $last = $date;
                    }
                    break;
                case 'SkCeny':
                    $lists[] = $row;
                    break;
                case 'sCMeny':
                    $currencies[PohodaXml::text($row, 'ID')] = strtoupper(PohodaXml::text($row, 'Kod'));
                    break;
                case 'SKzPol':
                    $owner = PohodaXml::text($row, 'RefAg');
                    $components[$owner] = ($components[$owner] ?? 0) + 1;
                    break;
            }
        }
        $priceLists = [];
        foreach ($lists as $row) {
            $currency = PohodaXml::text($row, 'RefCM');
            $priceLists[] = [
                'id' => PohodaXml::text($row, 'ID'),
                'code' => PohodaXml::text($row, 'IDS'),
                'name' => PohodaXml::text($row, 'SText'),
                'type' => (int) PohodaXml::text($row, 'RelTpCeny'),
                'vat_included' => self::flag(PohodaXml::text($row, 'SDph')),
                'currency' => $currency === '' || $currency === '0' ? 'CZK' : ($currencies[$currency] ?? ''),
                'discount' => PohodaXml::num($row, 'Sleva'),
            ];
        }
        $created = $info['created'] ?? '';
        return new self($file, preg_match('/^\d{4}-\d{2}-\d{2}/', $created) === 1 ? substr($created, 0, 10) : null,
            $warehouses, $nodes, $cards, $valuations, $priceLists, $components, $last);
    }

    /**
     * Přehled pro náhled průvodce: sklady s počty karet a navrženou volbou převodu.
     * Konsignační sklad (zásoba jiného vlastníka) se předvolí jako „nepřevádět".
     *
     * @return array{warehouses:list<array{code:string,name:string,cards:int,stocked:int,without_kind:int,suggested:string}>,cards:int,price_lists:int,date:?string}
     */
    public static function summary(string $file, int $year): array
    {
        $stock = self::read($file);
        $byWarehouse = [];
        foreach ($stock->cards as $card) {
            $w = $card['RefSklad'];
            $byWarehouse[$w] ??= ['cards' => 0, 'stocked' => 0, 'without_kind' => 0];
            $byWarehouse[$w]['cards']++;
            if ((float) $card['StavZ'] > 0) {
                $byWarehouse[$w]['stocked']++;
            }
            if ((int) $card['RelSkTyp'] === self::TYPE_CARD && self::kindOf($card) === null) {
                $byWarehouse[$w]['without_kind']++;
            }
        }
        $warehouses = [];
        foreach ($stock->warehouses as $id => $w) {
            $n = $byWarehouse[$id] ?? ['cards' => 0, 'stocked' => 0, 'without_kind' => 0];
            $warehouses[] = ['code' => $w['code'], 'name' => $w['name']] + $n
                + ['suggested' => preg_match('/konsign/iu', $w['code'] . ' ' . $w['name']) === 1 ? 'skip' : 'goods'];
        }
        return [
            'warehouses' => $warehouses,
            'cards' => count($stock->cards),
            'price_lists' => count(array_filter($stock->priceLists, static fn (array $l): bool => $l['type'] !== 0)),
            'date' => $stock->stockDate($year),
        ];
    }

    /**
     * Den, ke kterému stav platí: den exportu, nebo poslední pohyb, je-li pozdější (doklad
     * s datem dopředu); nejpozději konec roku agendy - starší agenda vede stav ke konci roku.
     * `$periodEnd` = konec účetního období agendy u firmy (hospodářský rok); bez něj 31. 12.
     * roku agendy (náhled průvodce firmu nezná).
     */
    public function stockDate(int $year, ?string $periodEnd = null): string
    {
        $end = $periodEnd ?? sprintf('%04d-12-31', $year);
        $date = max($this->exportedOn ?? $end, $this->lastMovement ?? '');
        return min($date, $end);
    }

    /** Druh zásoby z karty (`goods` / `material`); `null` = karta ho nenese a rozhodne volba skladu. */
    public static function kindOf(array $card): ?string
    {
        return match ((int) $card['RelSkDruh']) {
            self::KIND_GOODS => 'goods',
            self::KIND_MATERIAL => 'material',
            default => null,
        };
    }

    /**
     * Hodnota stavu karty v haléřích: ocenění po posledním pohybu, bez pohybu stav × průměrná cena.
     */
    public function valueCents(array $card): int
    {
        $valuation = $this->valuations[$card['ID']] ?? null;
        $value = $valuation !== null && $valuation['moves'] > 0
            ? $valuation['value']
            : (float) $card['StavZ'] * (float) $card['VNakup'];
        return (int) round($value * 100);
    }

    /** Názvy větví členění skladu od kořene; kořen skladu bez názvu = prázdný seznam. */
    public function categoryPath(array $card): array
    {
        return $this->structure[$card['RefStruct']] ?? [];
    }

    /**
     * Položky ceníků (druhý průchod souborem): id ceníku => id karty => cena.
     *
     * @param array<string,true> $listIds
     * @return \Generator<int,array{list:string,card:string,price:float}>
     */
    public function priceRows(array $listIds): \Generator
    {
        foreach (PohodaXml::records($this->file, 'SKzCn') as $row) {
            $list = PohodaXml::text($row, 'RefSkCeny');
            if (!isset($listIds[$list])) {
                continue;
            }
            yield ['list' => $list, 'card' => PohodaXml::text($row, 'RefAg'), 'price' => PohodaXml::num($row, 'ProdejC')];
        }
    }

    /**
     * Poměr alternativní jednotky k základní (`MJ2Koef` = kolik základních jednotek je
     * v jedné alternativní) jako zlomek; `null` = koeficient chybí nebo není kladný.
     *
     * @return array{0:int,1:int}|null
     */
    public static function unitRatio(string $koef): ?array
    {
        if (!is_numeric($koef) || (float) $koef <= 0) {
            return null;
        }
        $scaled = (int) round((float) $koef * 1000);
        if ($scaled <= 0 || abs($scaled / 1000 - (float) $koef) > 1e-9) {
            return null;
        }
        $gcd = self::gcd($scaled, 1000);
        return [intdiv($scaled, $gcd), intdiv(1000, $gcd)];
    }

    /** EAN s platnou kontrolní číslicí (EAN-8, UPC-A, EAN-13, GTIN-14), jinak `null`. */
    public static function ean(string $value): ?string
    {
        $ean = trim($value);
        if (preg_match('/^[0-9]{8}$|^[0-9]{12,14}$/D', $ean) !== 1) {
            return null;
        }
        $sum = 0;
        $parity = strlen($ean) % 2;
        for ($i = 0, $n = strlen($ean) - 1; $i < $n; $i++) {
            $sum += (int) $ean[$i] * (($i % 2) === $parity ? 3 : 1);
        }
        return ((10 - ($sum % 10)) % 10) === (int) $ean[-1] ? $ean : null;
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        return $a;
    }

    private static function flag(string $value): bool
    {
        return in_array(strtolower($value), ['1', '-1', 'true'], true);
    }
}
