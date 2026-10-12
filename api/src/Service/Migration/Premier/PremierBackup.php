<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\Pohoda\PartnerImporter;
use MyInvoice\Service\Migration\Premier\Dbf\CabinetExtractor;
use MyInvoice\Service\Migration\Premier\Dbf\DbfTable;

/**
 * Rozbalená záloha dat PREMIER („Správce → Záloha dat", soubor `.izip` nebo `.icab`).
 *
 * Záloha je databáze Visual FoxPro jedné firmy: ploché tabulky `.dbf` (+ memo `.fpt`)
 * se VŠEMI účetními roky najednou - rok záznamu určuje jeho datum (deník `PUB_UCTO.DATUM`),
 * osnova nese sloupec `ROK`. Převod proto nahrává jednu zálohu a převádí z ní rok po roku.
 *
 * Tabulky, ze kterých převod čte:
 *
 * | tabulka      | obsah                                                        |
 * |--------------|--------------------------------------------------------------|
 * | `SET_GLOB`   | nastavení firmy (IČO `aico`, DIČ `adic`, název `adress(1)`)   |
 * | `OSNOVA`     | účtová osnova po letech                                      |
 * | `PUB_UCTO`   | účetní deník všech řad dokladů                               |
 * | `KODY_DPH`   | číselník kódů DPH s řádky přiznání a oddíly KH               |
 * | `PARTNERY`   | adresář                                                      |
 * | `FA_OUT`, `POLOZKY` | vydané faktury a zálohové listy s položkami           |
 * | `FA_IN`, `POLOZ_IN` | přijaté faktury a zálohové listy s položkami          |
 * | `DOKL_PU`    | řady dokladů deníku (banky, pokladny, interní doklady)       |
 * | `VAZBY`      | vazby mezi doklady (úhrada v deníku ↔ faktura)               |
 * | `MAJETEK`, `ODPISY_U`, `MAJ_POH` | dlouhodobý majetek, odpisy, pohyby       |
 * | `D_KHDPH1`, `D_KHDPHP1`, `D_PO1`, `D_PO2` | podání KH a DPPO (kontrola převodu) |
 */
final class PremierBackup
{
    /** Tabulky, bez kterých převod nemá z čeho vycházet. */
    public const REQUIRED = ['SET_GLOB', 'PUB_UCTO', 'OSNOVA', 'KODY_DPH'];

    private const MAX_ENTRIES = 20000;
    private const MAX_UNPACKED = 4 * 1024 * 1024 * 1024;
    private const MIN_YEAR = 1990;
    private const NAME_PATTERN = '/^[A-Za-z0-9_]{1,40}\.(dbf|fpt)$/i';

    /** @var array<string,array<string,mixed>> */
    private ?array $settings = null;

    /** @var list<int>|null */
    private ?array $years = null;

    /** @var array{0:?string}|null poslední měsíc mezd, obalený kvůli rozlišení „nespočítáno" a „bez mezd" */
    private ?array $lastPayrollPeriod = null;

    public readonly string $ico;
    public readonly string $dic;
    public readonly string $company;
    public readonly string $homeCurrency;

    private function __construct(public readonly string $dir)
    {
        $this->ico = PartnerImporter::ico((string) $this->setting('aico'));
        $this->dic = strtoupper(str_replace(' ', '', (string) $this->setting('adic')));
        $this->company = trim((string) ($this->setting('adress(1)') ?? $this->setting('a_funame') ?? ''));
        $currency = strtoupper(trim((string) $this->setting('a_mena')));
        $this->homeCurrency = preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'CZK';
    }

    public static function open(string $dir): self
    {
        foreach (self::REQUIRED as $table) {
            if (!is_file($dir . DIRECTORY_SEPARATOR . $table . '.DBF')) {
                throw new PremierException('backup_incomplete', "Záloha neobsahuje tabulku {$table} - nejde o zálohu dat PREMIER, nebo je neúplná.", ['table' => $table]);
            }
        }
        return new self($dir);
    }

    /**
     * Rozbalí zálohu (`.izip` = ZIP, `.icab` = Microsoft Cabinet) do `$targetDir`. Bere jen
     * tabulky `.dbf` a memo `.fpt`, jména sjednotí na velká písmena (Linux rozlišuje
     * velikost písmen, PREMIER ji nedodržuje) a hlídá počet i velikost souborů.
     */
    public static function extractArchive(string $archivePath, string $targetDir): void
    {
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new PremierException('storage_not_writable', 'Úložiště pro zálohy není zapisovatelné.', [], 500);
        }
        $seen = [];
        $accept = static function (string $name) use (&$seen): ?string {
            $base = basename(str_replace('\\', '/', $name));
            if (preg_match(self::NAME_PATTERN, $base) !== 1) {
                return null;
            }
            $target = strtoupper($base);
            if (isset($seen[$target])) {
                return null; // druhá kopie téže tabulky (přebalený archiv) - platí první
            }
            $seen[$target] = true;
            return $target;
        };

        if (CabinetExtractor::isCabinet($archivePath)) {
            $cab = new CabinetExtractor($archivePath);
            if (count($cab->entries()) > self::MAX_ENTRIES) {
                throw new PremierException('backup_too_many_files', 'Záloha obsahuje příliš mnoho souborů.');
            }
            $cab->extract($targetDir, $accept, self::MAX_UNPACKED);
        } else {
            self::extractZip($archivePath, $targetDir, $accept);
        }
        if (!is_file($targetDir . DIRECTORY_SEPARATOR . 'PUB_UCTO.DBF')) {
            throw new PremierException('backup_not_premier', 'Soubor není záloha dat PREMIER (chybí účetní deník PUB_UCTO). Nahrajte soubor .izip nebo .icab z „Správce → Záloha dat".');
        }
    }

    /**
     * Přehled zálohy pro průvodce: firma a roky s účetními zápisy. Tvar položek je stejný
     * jako přehled agend exportu z POHODY (rok = agenda), průvodce je tak zobrazí stejně.
     *
     * @return list<array{dir:string,ico:string,dic:string,company:string,year:int,entries:int,has_accounting:bool,has_payroll:bool}>
     */
    public static function overview(string $dir): array
    {
        $backup = self::open($dir);
        $counts = $backup->journalCountsByYear();
        $payroll = $backup->hasRows('MZDY');
        $payrollLast = $payroll ? $backup->lastPayrollPeriod() : null;
        $out = [];
        foreach ($counts as $year => $count) {
            $out[] = [
                'dir' => '.',
                'ico' => $backup->ico,
                'dic' => $backup->dic,
                'company' => $backup->company,
                'year' => $year,
                'entries' => $count,
                'has_accounting' => true,
                'has_payroll' => $payroll,
                'payroll_last' => $payrollLast,
            ];
        }
        return $out;
    }

    /**
     * Záloha s údaji, které spočítal přehled nahrání ({@see overview()} v `meta.json`):
     * roky deníku a poslední měsíc mezd. Kontrola před převodem pak nečte celý deník ani
     * mzdy znovu - v náhledu průvodce to byl u každého roku zálohy průchod celou zálohou.
     * Přehled nahraný dřív poslední měsíc mezd nemá; ten se pak dočte ze zálohy.
     *
     * @param list<array<string,mixed>> $agendas agendy z `meta.json`
     */
    public function withOverview(array $agendas): self
    {
        $years = [];
        $known = true;
        $last = null;
        foreach ($agendas as $agenda) {
            if ((string) ($agenda['ico'] ?? '') !== $this->ico) {
                continue;
            }
            $years[] = (int) $agenda['year'];
            if ((bool) ($agenda['has_payroll'] ?? false)) {
                if (!array_key_exists('payroll_last', $agenda)) {
                    $known = false;
                }
                $last = $agenda['payroll_last'] ?? null;
            }
        }
        if ($years === []) {
            return $this;
        }
        sort($years);
        $this->years = array_values(array_unique($years));
        if ($known) {
            $this->lastPayrollPeriod = [$last !== null ? (string) $last : null];
        }
        return $this;
    }

    /** Poslední měsíc zpracovaných mezd v záloze (`MZDY`, `YYYY-MM`), nebo `null`. */
    public function lastPayrollPeriod(): ?string
    {
        if ($this->lastPayrollPeriod !== null) {
            return $this->lastPayrollPeriod[0];
        }
        $last = 0;
        if ($this->hasRows('MZDY')) {
            foreach ($this->rows('MZDY') as $row) {
                $year = (int) ($row['ROK'] ?? 0);
                $month = (int) ($row['MESIC'] ?? 0);
                if ($year >= 1990 && $month >= 1 && $month <= 12) {
                    $last = max($last, $year * 100 + $month);
                }
            }
        }
        $this->lastPayrollPeriod = [$last === 0 ? null : sprintf('%04d-%02d', intdiv($last, 100), $last % 100)];
        return $this->lastPayrollPeriod[0];
    }

    public function hasTable(string $name): bool
    {
        return is_file($this->path($name));
    }

    public function hasRows(string $name): bool
    {
        if (!$this->hasTable($name)) {
            return false;
        }
        foreach ($this->table($name)->rows() as $_) {
            return true;
        }
        return false;
    }

    public function table(string $name): DbfTable
    {
        $path = $this->path($name);
        if (!is_file($path)) {
            throw new PremierException('table_missing', 'Záloha neobsahuje tabulku ' . strtoupper($name) . '.', ['table' => strtoupper($name)]);
        }
        return new DbfTable($path);
    }

    /**
     * Záznamy tabulky; chybějící volitelná tabulka = žádné záznamy.
     *
     * @return \Generator<int,array<string,mixed>>
     */
    public function rows(string $name): \Generator
    {
        if (!$this->hasTable($name)) {
            return;
        }
        yield from $this->table($name)->rows();
    }

    /** @return list<array<string,mixed>> */
    public function all(string $name): array
    {
        return iterator_to_array($this->rows($name), false);
    }

    /** Hodnota globálního nastavení (`SET_GLOB.PROMEN`), textová, číselná nebo datum. */
    public function setting(string $name): mixed
    {
        if ($this->settings === null) {
            $this->settings = [];
            foreach ($this->rows('SET_GLOB') as $r) {
                $key = strtolower(trim((string) ($r['PROMEN'] ?? '')));
                if ($key !== '' && !isset($this->settings[$key])) {
                    $this->settings[$key] = $r;
                }
            }
        }
        $r = $this->settings[strtolower($name)] ?? null;
        if ($r === null) {
            return null;
        }
        $text = trim((string) ($r['C_SET'] ?? ''));
        if ($text !== '') {
            return $text;
        }
        if (!empty($r['D_SET'])) {
            return $r['D_SET'];
        }
        return $r['N_SET'] ?? null;
    }

    /** @return list<int> roky, ve kterých má deník zápisy, vzestupně */
    public function years(): array
    {
        return $this->years ??= array_keys($this->journalCountsByYear());
    }

    /**
     * Účetní roky firmy (label = rok začátku období), ve kterých má deník zápisy. Kalendářní
     * firma dostane {@see years()} beze změny, hospodářský rok se počítá z dat deníku.
     *
     * @return list<int>
     */
    public function fiscalYears(FiscalCalendar $calendar): array
    {
        if ($calendar->isCalendar()) {
            return $this->years();
        }
        $years = [];
        foreach ($this->journalDates() as $date) {
            $years[$calendar->fiscalYearOfDate($date)] = true;
        }
        $out = array_keys($years);
        sort($out);
        return $out;
    }

    /** @return array<int,int> rok => počet řádků deníku */
    public function journalCountsByYear(): array
    {
        $counts = [];
        foreach ($this->journalDates() as $date) {
            $year = (int) substr($date, 0, 4);
            $counts[$year] = ($counts[$year] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    /**
     * Data zápisů deníku (`PUB_UCTO.DATUM`) v rozsahu let, které převod bere.
     *
     * @return \Generator<int,string>
     */
    public function journalDates(): \Generator
    {
        $max = (int) date('Y') + 1;
        foreach ($this->rows('PUB_UCTO') as $r) {
            $date = (string) ($r['DATUM'] ?? '');
            $year = (int) substr($date, 0, 4);
            if ($year >= self::MIN_YEAR && $year <= $max) {
                yield $date;
            }
        }
    }

    private function path(string $name): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . strtoupper($name) . '.DBF';
    }

    /** @param callable(string):?string $accept */
    private static function extractZip(string $zipPath, string $targetDir, callable $accept): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
            throw new PremierException('backup_not_archive', 'Soubor není záloha PREMIER (ani iZIP, ani iCAB). Nahrajte soubor z „Správce → Záloha dat".');
        }
        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new PremierException('backup_too_many_files', 'Záloha obsahuje příliš mnoho souborů.');
            }
            // Mělčí cesta má přednost (přebalený archiv může nést kopii o složku hloub).
            $order = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat !== false && !str_ends_with((string) $stat['name'], '/')) {
                    $order[] = ['i' => $i, 'name' => (string) $stat['name'], 'size' => (int) $stat['size'], 'depth' => substr_count(str_replace('\\', '/', (string) $stat['name']), '/')];
                }
            }
            usort($order, static fn (array $a, array $b): int => ($a['depth'] <=> $b['depth']) ?: ($a['i'] <=> $b['i']));
            $total = 0;
            foreach ($order as $entry) {
                $target = $accept($entry['name']);
                if ($target === null) {
                    continue;
                }
                $total += $entry['size'];
                if ($total > self::MAX_UNPACKED) {
                    throw new PremierException('backup_too_large', 'Rozbalená záloha by byla příliš velká.');
                }
                $in = $zip->getStream($zip->getNameIndex($entry['i']) ?: '');
                $out = @fopen($targetDir . DIRECTORY_SEPARATOR . $target, 'wb');
                if ($in === false || $out === false) {
                    throw new PremierException('backup_corrupted', 'Soubor ' . basename($entry['name']) . ' zálohy nejde rozbalit.');
                }
                $copied = stream_copy_to_stream($in, $out, $entry['size'] + 1);
                fclose($in);
                fclose($out);
                if ($copied !== $entry['size']) {
                    throw new PremierException('backup_corrupted', 'Soubor ' . basename($entry['name']) . ' zálohy je poškozený.');
                }
            }
        } finally {
            $zip->close();
        }
    }
}
