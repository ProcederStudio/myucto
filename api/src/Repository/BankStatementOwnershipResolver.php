<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Jediné místo, které rozhoduje, zda bankovní výpis (a tím i jeho transakce)
 * patří danému supplieru. Nahrazuje rozsypaný predikát „EXISTS currencies se
 * stejným account_number", který byl v BankStatementAction opakovaný ~10×.
 *
 * SEC-01: starý predikát rozhodoval vlastnictví POUZE shodou čísla účtu, takže
 * uživatel firmy A si stačilo do `currencies.account_number` zapsat (veřejně
 * známé) číslo účtu firmy B a četl i mutoval její výpisy. Navíc
 * `bs.bank_code IS NULL OR cur.bank_code IS NULL` fungovalo jako wildcard —
 * neúplná konfigurace útok ještě usnadňovala.
 *
 * Pravidla (fail-closed, vzor {@see CashJournalRepository::matchingStatementIds()}):
 *
 *   1. `bank_statements.supplier_id IS NOT NULL` → autoritativní údaj (migrace
 *      1078/1079, od té doby ho plní i StatementImporter). Vyžadujeme PŘESNOU
 *      shodu, ŽÁDNÝ fallback podle čísla účtu.
 *   2. `supplier_id IS NULL` (legacy import) → přístup jen tehdy, když
 *      normalizovaný účet výpisu patří PRÁVĚ JEDNÉ firmě. Dva a více kandidátů
 *      (přesně scénář útoku) = odepřít oběma.
 *   3. Kód banky se porovnává striktně: buď je prázdný na OBOU stranách, nebo
 *      se musí rovnat. Jednostranné NULL už není wildcard.
 *
 * Jednoznačné legacy řádky backfilluje migrace 1136 (tam je hledání kandidátů
 * širší — bere i IBAN a jednostranný bank_code —, ale vyžaduje jednoznačnost
 * napříč VŠEMI firmami, takže je to bezpečná jednorázová „kolaudace" dat).
 */
final class BankStatementOwnershipResolver
{
    /** Počet `?` placeholderů, které {@see sql()} do dotazu přidá. */
    public const PARAM_COUNT = 2;

    public function __construct(private readonly Connection $db) {}

    /**
     * SQL boolean výraz „výpis $alias patří supplieru". Do dotazu přidává
     * {@see PARAM_COUNT} placeholderů — hodnoty dodá {@see params()} ve stejném
     * pořadí, v jakém se výraz v SQL objeví.
     */
    public static function sql(string $alias = 'bs', bool $currentRead = false): string
    {
        return self::predicate('?', $alias, $currentRead);
    }

    /**
     * Varianta {@see sql()} pro dotazy, kde supplier nepřichází parametrem, ale
     * je to sloupec jiné tabulky (např. `i.supplier_id` u faktury). Nepřidává
     * žádný placeholder.
     */
    public static function sqlForColumn(string $supplierColumn, string $alias = 'bs'): string
    {
        return self::predicate($supplierColumn, $alias);
    }

    private static function predicate(string $supplier, string $alias, bool $currentRead = false): string
    {
        $lock = $currentRead ? ' FOR UPDATE' : '';
        $stmtAccount = self::normalizedAccount($alias . '.account_number');
        $curAccount  = self::normalizedAccount('bso_cur.account_number');
        $allAccount  = self::normalizedAccount('bso_own.account_number');

        return "(CASE WHEN $alias.supplier_id IS NOT NULL THEN $alias.supplier_id = $supplier ELSE (
                    $stmtAccount <> ''
                    AND EXISTS (
                        SELECT 1 FROM currencies bso_cur
                         WHERE bso_cur.supplier_id = $supplier
                           AND $curAccount = $stmtAccount
                           AND " . self::bankCodeMatch('bso_cur', $alias) . $lock . "
                    )
                    AND (
                        SELECT COUNT(DISTINCT bso_own.supplier_id) FROM currencies bso_own
                         WHERE bso_own.supplier_id IS NOT NULL
                           AND $allAccount = $stmtAccount
                           AND " . self::bankCodeMatch('bso_own', $alias) . $lock . "
                    ) = 1
                ) END)";
    }

    /**
     * Hodnoty pro placeholdery z {@see sql()}.
     *
     * @return list<int>
     */
    public static function params(int $supplierId): array
    {
        return [$supplierId, $supplierId];
    }

    /** Patří výpis #$statementId supplieru? Prázdné/neplatné id = ne. */
    public function statementOwned(int $statementId, int $supplierId): bool
    {
        if ($statementId <= 0 || $supplierId <= 0) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM bank_statements bs WHERE bs.id = ? AND ' . self::sql() . ' LIMIT 1'
        );
        $stmt->execute(array_merge([$statementId], self::params($supplierId)));

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Patří bankovní transakce supplieru? `bank_transactions` vlastní sloupec
     * supplier_id nemá — vlastnictví se dědí z hlavičky výpisu.
     */
    public function transactionOwned(int $transactionId, int $supplierId, bool $currentRead = false): bool
    {
        if ($transactionId <= 0 || $supplierId <= 0) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.id = ? AND ' . self::sql('bs', $currentRead) . ' LIMIT 1' . ($currentRead ? ' FOR UPDATE' : '')
        );
        $stmt->execute(array_merge([$transactionId], self::params($supplierId)));

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Dávkový read-only odkaz z pohybů na přístupné výpisy (např. peněžní deník).
     * Stejné vlastnictví jako detail výpisu; žádné účetní částky se zde nepočítají.
     *
     * @param list<int> $transactionIds
     * @return array<int,int> ID transakce => ID výpisu
     */
    public function statementIdsForTransactions(int $supplierId, array $transactionIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $transactionIds), static fn (int $id): bool => $id > 0)));
        if ($supplierId <= 0 || $ids === []) {
            return [];
        }

        $result = [];
        // Omezení počtu parametrů i pro deník s desítkami tisíc pohybů.
        foreach (array_chunk($ids, 1000) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare(
                'SELECT bt.id, bt.statement_id FROM bank_transactions bt
                  JOIN bank_statements bs ON bs.id = bt.statement_id
                 WHERE bt.id IN (' . $placeholders . ') AND ' . self::sql() . ' ORDER BY bt.id'
            );
            $stmt->execute([...$chunk, ...self::params($supplierId)]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[(int) $row['id']] = (int) $row['statement_id'];
            }
        }
        return $result;
    }

    /**
     * ID všech výpisů supplieru — pro dotazy, kde se predikát nedá inlinovat
     * (agregace nad více zdroji). Prázdné pole = žádný přístupný výpis.
     *
     * @return list<int>
     */
    public function ownedStatementIds(int $supplierId): array
    {
        if ($supplierId <= 0) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT bs.id FROM bank_statements bs WHERE ' . self::sql()
        );
        $stmt->execute(self::params($supplierId));

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Je číslo účtu (nebo IBAN) už registrované u JINÉ firmy? Používá
     * SettingsAction před uložením currencies.account_number/iban — bez toho
     * si útočník může „nárokovat" cizí účet a rozbít jednoznačnost legacy
     * výpisů (SEC-01, krok 1 útoku).
     */
    public function accountClaimedByOtherSupplier(int $supplierId, ?string $accountNumber, ?string $iban = null): bool
    {
        $keys = self::canonicalKeys($accountNumber, $iban);
        if ($keys === []) {
            return false;
        }

        $conditions = [];
        $params = [];
        // supplierId <= 0 = zakládá se nová firma (createSupplier / SetupAction), která
        // ještě nemá id — pak se porovnává proti VŠEM firmám bez výjimky.
        $exclude = '';
        if ($supplierId > 0) {
            $exclude = ' AND bso_cur.supplier_id <> ?';
            $params[] = $supplierId;
        }
        foreach ($keys as $key) {
            // Cizí účet může být u druhé firmy evidovaný jen IBANem (#109) — porovnáváme
            // proto i domácí část IBANu, ne jen account_number.
            $conditions[] = '(' . self::normalizedAccount('bso_cur.account_number') . ' = ?'
                . ' OR ' . self::normalizedIbanAccount('bso_cur.iban') . " = ?)";
            $params[] = $key;
            $params[] = $key;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM currencies bso_cur
              WHERE bso_cur.supplier_id IS NOT NULL' . $exclude . '
                AND (' . implode(' OR ', $conditions) . ')
              LIMIT 1'
        );
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Tie-break proti „squattingu" (SEC-01, 2. kolo). {@see accountClaimedByOtherSupplier()}
     * je first-come-first-served: dokud si účet nikdo nezapsal do `currencies`, může si ho
     * zabrat kdokoliv — a tím se podle pravidla 2 (jediný kandidát) stát vlastníkem legacy
     * výpisů, které k účtu už v DB leží. Proto navíc odmítáme claim, pokud pro daný účet:
     *
     *   a) existuje výpis s `supplier_id` JINÉ firmy (autoritativní vlastník je znám), nebo
     *   b) existují legacy výpisy (`supplier_id IS NULL`) a žadatel na tom účtu zatím žádný
     *      vlastní (`supplier_id = $supplierId`) výpis nemá — čili si nárokuje historii,
     *      ke které se nijak neváže.
     *
     * Běžný scénář „zakládám nový účet, data k němu ještě nejsou" neblokuje: bez výpisů
     * v DB vrací false.
     */
    public function accountBlockedByForeignStatements(int $supplierId, ?string $accountNumber, ?string $iban = null): bool
    {
        $keys = self::canonicalKeys($accountNumber, $iban);
        if ($keys === []) {
            return false;
        }

        $match = [];
        $params = [];
        foreach ($keys as $key) {
            $match[] = self::normalizedAccount('bs.account_number') . ' = ?';
            $params[] = $key;
        }
        $where = '(' . implode(' OR ', $match) . ')';

        // Kolik výpisů na tomto účtu patří někomu jinému / nikomu / žadateli.
        $sql = 'SELECT
                    SUM(bs.supplier_id IS NOT NULL AND bs.supplier_id <> ?) AS foreign_cnt,
                    SUM(bs.supplier_id IS NULL)                             AS legacy_cnt,
                    SUM(bs.supplier_id = ?)                                 AS own_cnt
                  FROM bank_statements bs
                 WHERE ' . $where;
        $stmt = $this->db->pdo()->prepare($sql);
        // supplierId <= 0 (nová firma) nikdy nematchne → foreign/legacy se počítají správně,
        // own_cnt vyjde 0, což je pro novou firmu pravda.
        $stmt->execute(array_merge([$supplierId, $supplierId], $params));
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        if ((int) ($row['foreign_cnt'] ?? 0) > 0) {
            return true;
        }

        return (int) ($row['legacy_cnt'] ?? 0) > 0 && (int) ($row['own_cnt'] ?? 0) === 0;
    }

    /**
     * Kanonická (normalizovaná) čísla účtu z dvojice account_number/IBAN.
     *
     * @return list<string>
     */
    private static function canonicalKeys(?string $accountNumber, ?string $iban): array
    {
        $keys = [];
        foreach ([$accountNumber, $iban] as $raw) {
            $canonical = \MyInvoice\Service\Bank\AccountNumberNormalizer::canonical($raw, $iban);
            if ($canonical !== null && $canonical !== '') {
                $keys[$canonical] = true;
            }

            // Guard MUSÍ vidět přesně to, co uvidí SQL predikát. canonical() je
            // přísnější než {@see normalizedAccount()}: pro vstup tvaru dvě písmena
            // + alfanumerika (např. „XX1000000005") přeskočí národní parsing a jako
            // ne-CZ-IBAN vrátí null. Bez tohohle klíče by guard takovou hodnotu
            // propustil, SQL by ji normalizoval na „1000000005" a namatchoval na
            // cizí účet — čili SEC-01 znovu otevřené přes legacy výpisy.
            $sqlKey = ltrim((string) preg_replace('/[^0-9]/', '', (string) $raw), '0');
            if ($sqlKey !== '') {
                $keys[$sqlKey] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * Normalizace čísla účtu v SQL — shodná s
     * {@see \MyInvoice\Service\Bank\AccountNumberNormalizer::normalize()}
     * (strip non-digits + odstranění vodicích nul), aby GPC zero-padded
     * `0000001000000005` matchlo `1000000005` z currencies.
     */
    private static function normalizedAccount(string $column): string
    {
        return "TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL($column, ''), '[^0-9]', ''))";
    }

    /**
     * Domácí část CZ IBANu jako normalizované číslo účtu. CZ IBAN je
     * `CZkk BBBB PPPPPP NNNNNNNNNN` → po odstranění nečíslic je prvních 6 cifer
     * kontrolní číslo + kód banky, zbytek (16 cifer) je domácí účet. Nečeský
     * (nebo prázdný) IBAN dá '' a nikdy nematchne (porovnává se s neprázdným klíčem).
     */
    private static function normalizedIbanAccount(string $column): string
    {
        return "TRIM(LEADING '0' FROM
                    CASE WHEN UPPER(REGEXP_REPLACE(IFNULL($column, ''), '[^A-Za-z0-9]', '')) REGEXP '^CZ[0-9]{22}\$'
                         THEN SUBSTRING(REGEXP_REPLACE(IFNULL($column, ''), '[^0-9]', ''), 7)
                         ELSE '' END)";
    }

    /**
     * Striktní shoda kódu banky: prázdný na obou stranách, nebo shodný.
     * Jednostranné NULL/'' už NEmatchuje (zrušený wildcard, SEC-01) — takový
     * výpis se dá zpřístupnit jen doplněním bank_statements.supplier_id.
     */
    private static function bankCodeMatch(string $currencyAlias, string $statementAlias): string
    {
        return "COALESCE(NULLIF(TRIM(IFNULL($currencyAlias.bank_code, '')), ''), '')"
             . " = COALESCE(NULLIF(TRIM(IFNULL($statementAlias.bank_code, '')), ''), '')";
    }
}
