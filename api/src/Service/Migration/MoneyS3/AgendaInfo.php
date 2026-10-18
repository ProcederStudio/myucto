<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

/**
 * Náhled agendy Money S3 ze zálohy — co průvodce ukáže před importem: firma, verze
 * Money, účetní roky a kolik čeho v nich je. Nic nezapisuje.
 */
final class AgendaInfo
{
    /** Verze Money, na kterých je čtení formátu ověřené proti sestavám z Money. */
    public const VERIFIED_VERSIONS = ['26.600'];

    public const TEXT_TAX_RECORDS_NOT_SUPPORTED = 'Agenda vede v Money daňovou evidenci (peněžní deník), ne podvojné účetnictví (účetní deník). '
        . 'Převod z Money S3 přenáší jen účetní deník podvojného účetnictví, daňovou evidenci tímto průvodcem převést zatím nedokáže.';

    /**
     * @param list<array{dir:string,fiscal_year:?int,journal_rows:int,cashbook_rows:int,opening_rows:int,first_date:?string,last_date:?string,purchase_invoices:int,issued_invoices:int,cash_documents:int,bank_documents:int}> $years
     * @param list<array{code:string,message:string}> $warnings
     */
    public function __construct(
        public readonly string $name,
        public readonly string $ico,
        public readonly string $dic,
        public readonly string $street,
        public readonly string $city,
        public readonly string $zip,
        public readonly string $version,
        public readonly string $backupAt,
        public readonly array $years,
        public readonly int $partners,
        public readonly array $warnings,
    ) {}

    public static function fromBackup(Ms3Backup $backup): self
    {
        $ini = $backup->agendaInfoIni();
        $company = $backup->agendaCompany();
        $warnings = [];

        $years = [];
        foreach ($backup->yearDirs() as $dir) {
            $journal = $backup->table('UcDenik', $dir);
            $summary = Ms3Journal::summarize($journal !== null && $journal->hasData() ? $journal->rows(['Zdroj', 'Datum', 'Popis']) : []);
            $years[] = [
                'dir' => basename($dir),
                'fiscal_year' => $summary['fiscal_year'],
                'journal_rows' => $summary['rows'],
                // Počet živých (nesmazaných) řádků peněžního deníku roku (`UcPenDen`).
                // Pokud rok daňové evidence ho má nenulový a `journal_rows` nulový,
                // pak Money v něm vede peněžní deník místo podvojného účetnictví.
                'cashbook_rows' => self::count($backup, 'UcPenDen', $dir),
                'opening_rows' => $summary['opening_rows'],
                'first_date' => $summary['first_date'],
                'last_date' => $summary['last_date'],
                'purchase_invoices' => self::count($backup, 'PFaktury', $dir),
                'issued_invoices' => self::count($backup, 'VFaktury', $dir),
                'cash_documents' => self::count($backup, 'PoklKnih', $dir),
                'bank_documents' => self::count($backup, 'BankKnih', $dir),
            ];
        }

        $partners = 0;
        $address = $backup->table('AdresarF');
        if ($address !== null && $address->hasData()) {
            $partners = $address->countRows();
        }

        $ico = $company['ico'] !== '' ? $company['ico'] : $ini['ico'];
        if ($years === []) {
            $warnings[] = ['code' => 'no_years', 'message' => 'Záloha neobsahuje žádný účetní rok.'];
        }
        foreach ($years as $y) {
            if ($y['journal_rows'] === 0 && $y['cashbook_rows'] > 0) {
                $warnings[] = ['code' => 'tax_records_year', 'message' => "Rok v adresáři {$y['dir']} nemá účetní deník, ale peněžní deník "
                    . '(daňová evidence). Peněžní deník převod z Money S3 zatím nepodporuje, rok se nepřevede.'];
            } elseif ($y['journal_rows'] === 0) {
                $warnings[] = ['code' => 'empty_year', 'message' => "Rok v adresáři {$y['dir']} nemá účetní deník."];
            }
        }
        $seen = [];
        foreach ($years as $y) {
            if ($y['fiscal_year'] === null) {
                continue;
            }
            if (isset($seen[$y['fiscal_year']])) {
                $warnings[] = ['code' => 'duplicate_year', 'message' => "Účetní rok {$y['fiscal_year']} je v záloze dvakrát."];
            }
            $seen[$y['fiscal_year']] = true;
        }
        if ($ini['version'] !== '' && !in_array($ini['version'], self::VERIFIED_VERSIONS, true)) {
            $warnings[] = [
                'code' => 'version_not_verified',
                'message' => 'Záloha je z Money S3 ' . $ini['version'] . '. Čtení je ověřené na verzi '
                    . implode(', ', self::VERIFIED_VERSIONS) . '; výsledek o to pečlivěji porovnejte se sestavami z Money.',
            ];
        }

        return new self(
            $company['name'] !== '' ? $company['name'] : $ini['name'],
            $ico,
            strtoupper(str_replace(' ', '', $company['dic'])),
            $company['street'],
            $company['city'],
            str_replace(' ', '', $company['zip']),
            $ini['version'],
            $ini['backup_at'],
            $years,
            $partners,
            $warnings,
        );
    }

    /**
     * Náhled agendy z `meta.json` ({@see toArray()}), který spočítal job nahrání - náhled
     * průvodce tak zálohu znovu nečte.
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['name'] ?? ''),
            (string) ($data['ico'] ?? ''),
            (string) ($data['dic'] ?? ''),
            (string) ($data['street'] ?? ''),
            (string) ($data['city'] ?? ''),
            (string) ($data['zip'] ?? ''),
            (string) ($data['version'] ?? ''),
            (string) ($data['backup_at'] ?? ''),
            array_values((array) ($data['years'] ?? [])),
            (int) ($data['partners'] ?? 0),
            array_values((array) ($data['warnings'] ?? [])),
        );
    }

    /**
     * Náhled z `meta.json` má všechny údaje, které dnes {@see toArray()} ukládá (nahrání
     * starší verzí je mít nemusí - pak se náhled čte znovu ze zálohy).
     */
    public static function isComplete(mixed $data): bool
    {
        if (!is_array($data) || !is_array($data['years'] ?? null) || !is_array($data['warnings'] ?? null)) {
            return false;
        }
        foreach (['name', 'ico', 'dic', 'street', 'city', 'zip', 'version', 'backup_at', 'partners'] as $key) {
            if (!array_key_exists($key, $data)) {
                return false;
            }
        }
        foreach ($data['years'] as $y) {
            if (!is_array($y) || array_diff(['dir', 'fiscal_year', 'journal_rows', 'cashbook_rows', 'opening_rows', 'first_date', 'last_date', 'purchase_invoices', 'issued_invoices', 'cash_documents', 'bank_documents'], array_keys($y)) !== []) {
                return false;
            }
        }
        return true;
    }

    /**
     * Agenda vede v Money jen daňovou evidenci: žádný rok nemá účetní deník a aspoň jeden
     * má peněžní deník. Převod z Money S3 přenáší jen podvojné účetnictví.
     */
    public function taxRecordsOnly(): bool
    {
        $cashbook = false;
        foreach ($this->years as $y) {
            if ((int) ($y['journal_rows'] ?? 0) > 0) {
                return false;
            }
            $cashbook = $cashbook || (int) ($y['cashbook_rows'] ?? 0) > 0;
        }
        return $cashbook;
    }

    /** @return list<int> */
    public function fiscalYears(): array
    {
        $out = [];
        foreach ($this->years as $y) {
            if ($y['fiscal_year'] !== null) {
                $out[] = (int) $y['fiscal_year'];
            }
        }
        sort($out);
        return array_values(array_unique($out));
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'ico' => $this->ico,
            'dic' => $this->dic,
            'street' => $this->street,
            'city' => $this->city,
            'zip' => $this->zip,
            'version' => $this->version,
            'version_verified' => $this->version === '' || in_array($this->version, self::VERIFIED_VERSIONS, true),
            'backup_at' => $this->backupAt,
            'years' => $this->years,
            'partners' => $this->partners,
            'warnings' => $this->warnings,
        ];
    }

    private static function count(Ms3Backup $backup, string $table, string $dir): int
    {
        $t = $backup->table($table, $dir);
        if ($t === null || !$t->hasData()) {
            return 0;
        }
        return $t->countRows();
    }
}
