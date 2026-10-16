<?php

declare(strict_types=1);

namespace MyInvoice\Service\Codebook;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\TimeBilling;

/**
 * Počet desetinných míst množství podle měrné jednotky (migrace 2018).
 *
 * `units` je globální číselník, jednotka na řádku dokladu i na skladové kartě je
 * volný text. Párují se podle kódu bez ohledu na velikost písmen a okolní mezery.
 * Jednotka bez nastavení (`quantity_decimals` NULL) a jednotka mimo číselník
 * nejsou omezené vůbec, takže bez nastavení se nic nemění.
 *
 * Třída nic nezaokrouhluje. Jen řekne, které řádky mají víc desetinných míst,
 * než jejich jednotka dovoluje; volající je odmítne a uživatel opraví vstup sám.
 *
 * Platí pro ruční zadání (editor faktury, ruční skladový doklad). Importy,
 * opakovaná fakturace a automatické skladové doklady ji záměrně nevolají:
 * převádějí data, která už jednou vznikla, a odmítnutí by je zastavilo uprostřed.
 */
final class UnitPrecision
{
    /** Nejvyšší povolená hodnota, víc míst nemá `invoice_items.quantity` (DECIMAL(10,3)). */
    public const MAX_DECIMALS = 3;

    /** Hláška odmítnutí (bez proměnných, aby ji našel {@see \MyInvoice\I18n\ErrorCatalog}). */
    public const ERROR_MESSAGE = 'Množství má víc desetinných míst, než dovoluje měrná jednotka.';

    public function __construct(private readonly Connection $db) {}

    /**
     * Jednotky s nastaveným počtem míst, klíčem je {@see key()}.
     *
     * @return array<string,int>
     */
    public function decimalsByUnit(): array
    {
        $rows = $this->db->pdo()->query(
            'SELECT code, quantity_decimals FROM units WHERE quantity_decimals IS NOT NULL'
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $map = [];
        foreach ($rows as $r) {
            $map[self::key((string) $r['code'])] = (int) $r['quantity_decimals'];
        }
        return $map;
    }

    /**
     * Řádky, jejichž množství má víc míst, než dovoluje jednotka.
     *
     * @param array<int|string,mixed> $rows
     * @return list<array{index:int, unit:string, decimals:int}>
     */
    public function check(array $rows, string $qtyKey = 'quantity'): array
    {
        $hasUnit = false;
        foreach ($rows as $row) {
            if (is_array($row) && trim((string) ($row['unit'] ?? '')) !== '') {
                $hasUnit = true;
                break;
            }
        }
        return $hasUnit ? self::violations($rows, $this->decimalsByUnit(), $qtyKey) : [];
    }

    /**
     * Čistá varianta {@see check()} nad hotovou mapou jednotek.
     *
     * Přeskakuje časové řádky ({@see isTimeRow()}) a slevové řádky, které generuje server.
     *
     * @param array<int|string,mixed> $rows
     * @param array<string,int>       $map
     * @return list<array{index:int, unit:string, decimals:int}>
     */
    public static function violations(array $rows, array $map, string $qtyKey = 'quantity'): array
    {
        $out = [];
        $index = 0;
        foreach ($rows as $row) {
            $i = $index++;
            if (!is_array($row)) {
                continue;
            }
            if (($row['item_kind'] ?? null) === 'discount') {
                continue;
            }
            if (self::isTimeRow($row)) {
                continue;
            }
            $unit = (string) ($row['unit'] ?? '');
            $allowed = $map[self::key($unit)] ?? null;
            if ($allowed === null) {
                continue;
            }
            $places = self::decimalPlaces($row[$qtyKey] ?? null);
            if ($places !== null && $places > $allowed) {
                $out[] = ['index' => $i, 'unit' => $unit, 'decimals' => $allowed];
            }
        }
        return $out;
    }

    /**
     * Časový řádek: délka v minutách, nebo hodinová jednotka bez skladové karty.
     * Editor ho zadává jako dobu (h:mm), množství z ní jen vychází (20 min = 0,333 h),
     * proto ho kontrola míst vynechává. Stejné pravidlo jako `isTimeItem()` v editoru.
     *
     * @param array<string,mixed> $row
     */
    private static function isTimeRow(array $row): bool
    {
        if (($row['duration_minutes'] ?? null) !== null && $row['duration_minutes'] !== '') {
            return true;
        }
        return empty($row['stock_item_id']) && TimeBilling::isHourUnit($row['unit'] ?? null);
    }

    /**
     * Počet platných desetinných míst čísla (koncové nuly se nepočítají).
     * Pro nečíselný vstup null, ten řeší jiná validace.
     */
    public static function decimalPlaces(mixed $value): ?int
    {
        if (is_int($value)) {
            return 0;
        }
        if (is_string($value)) {
            $s = str_replace(',', '.', trim($value));
            if (preg_match('/^[+-]?\d*(?:\.(\d*))?$/D', $s, $m) === 1 && $s !== '' && $s !== '.') {
                return strlen(rtrim($m[1] ?? '', '0'));
            }
            if (!is_numeric($s)) {
                return null;
            }
            $value = (float) $s;
        }
        if (!is_float($value) || !is_finite($value)) {
            return null;
        }
        // Float z JSONu nese šum sčítání (0.1 + 0.2). Devět míst je daleko za
        // přesností sloupce (3), takže šum odřízne a skutečná místa nechá.
        $fraction = explode('.', sprintf('%.9F', abs($value)))[1] ?? '';
        return strlen(rtrim($fraction, '0'));
    }

    /**
     * Počet míst pro tisk množství. Bez nastavení null (šablona tiskne adaptivně).
     * S nastavením pevně tolik míst, kolik jednotka dovoluje; množství uložené dřív,
     * než jednotka nastavení dostala, ale nikdy neschová (tisk nezaokrouhluje).
     */
    public static function printDecimals(mixed $quantity, ?int $setting): ?int
    {
        if ($setting === null) {
            return null;
        }
        return max($setting, min(self::MAX_DECIMALS, self::decimalPlaces($quantity) ?? 0));
    }

    /** Klíč pro párování kódu jednotky: bez okolních mezer a bez ohledu na velikost písmen. */
    public static function key(string $unit): string
    {
        return mb_strtolower(trim($unit));
    }

    /**
     * Ověří hodnotu z formuláře číselníku. `null` a prázdný řetězec = bez omezení.
     *
     * @return array{ok:bool, value:?int}
     */
    public static function parseSetting(mixed $value): array
    {
        if ($value === null || $value === '') {
            return ['ok' => true, 'value' => null];
        }
        if (is_string($value) && preg_match('/^\d$/D', trim($value)) === 1) {
            $value = (int) trim($value);
        }
        if (!is_int($value) || $value < 0 || $value > self::MAX_DECIMALS) {
            return ['ok' => false, 'value' => null];
        }
        return ['ok' => true, 'value' => $value];
    }
}
