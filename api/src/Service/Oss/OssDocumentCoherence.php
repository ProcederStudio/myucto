<?php

declare(strict_types=1);

namespace MyInvoice\Service\Oss;

use MyInvoice\Service\Invoice\InvoiceItemKind;

/**
 * SOUDRŽNOST DOKLADU: leží týž doklad zároveň v OSS podání a v tuzemském přiznání?
 *
 * ── Proč je pravidlo tady, a ne v importu ───────────────────────────────────────────
 * {@see OssItemDeriver} rozhoduje o ŘÁDKU a o jiných řádcích téhož dokladu nic neví —
 * z jeho pohledu je každé z obou rozhodnutí samo o sobě správné. Rozpor je vlastnost
 * DOKLADU a vidět je až tam, kde jsou pohromadě všechny jeho položky.
 *
 * Napsané to bylo jako privátní metoda uvnitř `InvoiceImportService`, takže platilo
 * VÝHRADNĚ pro import: editor i API vytvořily rozpadlý doklad úplně tiše. To je přesně
 * ta třída chyby, před kterou varuje AGENTS.md — pravidlo implementované na jedné větvi
 * a nepropagované na druhou. Pravidlo proto bydlí tady, kde ho umí ZAVOLAT každý kanál,
 * který doklad zakládá, a import ho volá stejně jako uložení faktury z editoru.
 *
 * ── Nezamítá se ────────────────────────────────────────────────────────────────────
 * Smíšený doklad umí vzniknout legitimně: plnění s místem plnění v tuzemsku a zásilka
 * do jiného členského státu se dají vyfakturovat na jedné faktuře. Odmítnutí by tenhle
 * případ zavřelo do slepé uličky (u importu uživatel nemá jak doklad v cizím exportu
 * rozdělit, u editoru by mu API vrátilo 400 na doklad, který zákon dovoluje), kdežto
 * varování ho jen pošle na kontrolu. Platí to na OBOU kanálech schválně: kdyby ruční
 * zadání blokovalo to, co import pustí, uživatel by tentýž doklad uložil importem a
 * kontrola by se stala obcházitelnou formalitou.
 *
 * ── Příznak dostanou ZDANĚNÉ řádky OBOU stran, ne jen tuzemské ──────────────────────
 * Tuzemský řádek je sice podezřelejší, ale sám o sobě neviditelný: náhled OSS podání
 * čte VÝHRADNĚ řádky s `oss_applicable = 1`
 * ({@see \MyInvoice\Service\Report\VatLedgerService::ossRows()}), takže označení jen
 * tuzemské strany by po zavření reportu (resp. po zavření hlášky v editoru) nikde
 * nesvítilo — a to je přesně vada, kterou příznak řeší. Označí se proto obě strany
 * rozporu: OSS řádek jako ta polovina, kterou uživatel uvidí před odesláním podání,
 * tuzemský jako ta, kterou má opravdu prověřit. Řádky s NULOVOU sazbou (zaokrouhlení,
 * poštovné, osvobozené plnění) se vynechávají — rozpor netvoří a označit je znamená
 * hlásit ho na každé druhé faktuře.
 */
final class OssDocumentCoherence
{
    /**
     * @param list<int|string> $affectedKeys      klíče řádků, které tvoří rozpor
     * @param list<string>     $consumerCountries státy spotřeby na OSS straně
     * @param list<string>     $domesticRates      tuzemské sazby jako text ('12', '21')
     */
    private function __construct(
        public readonly array $affectedKeys,
        public readonly array $consumerCountries,
        public readonly array $domesticRates,
    ) {}

    /**
     * Rozpor nad NORMALIZOVANÝMI řádky, nebo `null`, když doklad soudržný je.
     *
     * Klíče vstupu se zachovávají, aby volající uměl označit tytéž řádky, které poslal —
     * import je adresuje pořadím v plánu, editor pořadím v payloadu.
     *
     * @param array<int|string, array{applicable: bool, country: ?string, rate: float}> $lines
     */
    public static function detect(array $lines): ?self
    {
        $consumerCountries = [];
        $domesticRates = [];
        $affected = [];

        foreach ($lines as $key => $line) {
            if ($line['applicable']) {
                $consumerCountries[strtoupper(trim((string) ($line['country'] ?? '')))] = true;
                $affected[] = $key;
                continue;
            }
            if ($line['rate'] <= OssItemDeriver::EPSILON) {
                continue;
            }
            $domesticRates[self::fmtPercent($line['rate'])] = true;
            $affected[] = $key;
        }

        if ($consumerCountries === [] || $domesticRates === []) {
            return null;
        }

        $countries = array_keys($consumerCountries);
        // Zpátky na řetězce: PHP z číselného klíče pole udělá int, takže '21' by se
        // v odpovědi API objevilo jako číslo a '12,5' vedle něj jako text.
        $rates = array_map(strval(...), array_keys($domesticRates));
        sort($countries);
        sort($rates);

        return new self($affected, $countries, $rates);
    }

    /**
     * Rozpor nad položkami tak, jak je posílá editor / API — a rovnou označení dotčených
     * řádků příznakem „k ručnímu posouzení".
     *
     * Označení je součástí TÉHOŽ volání schválně: kdyby si každý kanál sám rozhodoval,
     * které z vrácených řádků označí, rozešly by se v tom, co uživatel po uložení najde
     * v datech — a právě to (příznak jen na jedné straně rozporu) je vada, kvůli které
     * pravidlo vzniklo.
     *
     * Sazba se bere z mapy `vat_rates` ({@see \MyInvoice\Repository\InvoiceRepository::vatRateMap()}),
     * protože payload nese jen `vat_rate_id`; neznámé ID vyjde jako 0 % a chová se tedy
     * jako nezdaněný řádek — takový doklad stejně neprojde validací sazby.
     *
     * Systémové slevové řádky (`item_kind = 'discount'`) se přeskakují: `replaceItems()`
     * je zahazuje a generuje znovu z hlavičky, takže příznak by na nich stejně neskončil
     * a do hlášky by přidaly sazbu, kterou už nese jejich zdrojová skupina.
     *
     * ── Proč se značí DVĚMA klíči ──────────────────────────────────────────────────
     * `oss_needs_manual_review` je příznak z odvození MÍSTA PLNĚNÍ a zápis položky ho
     * u ne-OSS řádku záměrně ignoruje — vypnutí OSS je rozhodnutí člověka, kterým
     * nejistota končí ({@see \MyInvoice\Repository\InvoiceRepository::ossItemParams()}).
     * Rozpor dokladu ale musí označit i řádek TUZEMSKÝ, takže se veze vlastním klíčem
     * `oss_document_contradiction`: ten nepochází z payloadu, dosazuje ho server při
     * každém uložení znovu, a proto se z něj nemůže stát nesmazatelný příznak.
     *
     * @param array<int|string, array<string,mixed>> $items  MĚNÍ SE: dotčené řádky dostanou
     *                                                       `oss_needs_manual_review = true`
     *                                                       a `oss_document_contradiction = true`
     * @param array<int, float>                      $vatRateMap `vat_rates.id` → procento
     */
    public static function flagItems(array &$items, array $vatRateMap): ?self
    {
        $lines = [];
        foreach ($items as $key => $item) {
            // Textový řádek (InvoiceItemKind::TEXT) není plnění, tuzemskou stranu nedělá.
            if (!is_array($item) || InvoiceItemKind::of($item) !== InvoiceItemKind::STANDARD) {
                continue;
            }
            $lines[$key] = [
                'applicable' => !empty($item['oss_applicable']),
                'country' => (string) ($item['oss_consumer_country'] ?? ''),
                'rate' => (float) ($vatRateMap[(int) ($item['vat_rate_id'] ?? 0)] ?? 0.0),
            ];
        }

        $contradiction = self::detect($lines);
        if ($contradiction === null) {
            return null;
        }

        foreach ($contradiction->affectedKeys as $key) {
            $items[$key]['oss_needs_manual_review'] = true;
            $items[$key]['oss_document_contradiction'] = true;
        }

        return $contradiction;
    }

    /**
     * Věta do reportu importu i do odpovědi API. JEDNA pro oba kanály: dvě znění téhož
     * nálezu by se rozešla a uživatel by u importu a u editoru četl o jiném problému.
     */
    public function warning(string $domesticCountry): string
    {
        return sprintf(
            'Doklad si protiřečí: část řádků je zařazená do OSS (stát spotřeby %s) a část zůstala '
                . 'zdaněná tuzemsky sazbou %s %% (země dodavatele %s), takže jeden a týž doklad leží '
                . 've dvou různých přiznáních. Doklad jsme uložili — na jedné faktuře můžou obě věci '
                . 'stát legitimně (plnění s místem plnění v tuzemsku a zásilka do jiného členského '
                . 'státu) —, ale je to výjimka, ne běžný stav. Dotčené řádky jsme označili K RUČNÍMU '
                . 'POSOUZENÍ; zkontrolujte na dokladu sazby: patří-li tuzemské řádky taky do OSS, '
                . 'mají nést sazbu státu spotřeby.',
            implode(', ', $this->consumerCountries),
            implode(', ', $this->domesticRates),
            $domesticCountry,
        );
    }

    /**
     * Detail nálezu pro `_warning_meta` v odpovědi API. Kód varování překládá frontend,
     * ale strojově čitelné strany rozporu (a hotová česká věta pro klienty, kteří si
     * vlastní text neskládají) se do odpovědi vezou s ním.
     *
     * @return array{consumer_countries: list<string>, domestic_rates: list<string>,
     *               domestic_country: string, affected_items: int, message: string}
     */
    public function meta(string $domesticCountry): array
    {
        return [
            'consumer_countries' => $this->consumerCountries,
            'domestic_rates' => $this->domesticRates,
            'domestic_country' => $domesticCountry,
            'affected_items' => count($this->affectedKeys),
            'message' => $this->warning($domesticCountry),
        ];
    }

    /** Shodné s {@see \MyInvoice\Service\Import\InvoiceImportService} i `VatRateResolver`. */
    public static function fmtPercent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 2, ',', ' '), '0'), ',');
    }
}
