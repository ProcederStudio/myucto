<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

/**
 * Daňový původ zápisu deníku: storno (a storno storna) přebírá `source_type`/`source_id`
 * zápisu, který ruší, takže technický uzávěrkový zápis zůstane vyloučený i po stornu.
 *
 * CTE nese JEN zápisy v řetězcích storen — kořen je zápis, který má `reversed_by` a sám
 * stornem není. Všechny ostatní zápisy mají původ samy v sobě, proto se CTE připojuje
 * přes {@see join()} (LEFT JOIN) a {@see sourceTypeSql()}/{@see sourceIdSql()} při
 * chybějícím řádku vezmou sloupce zápisu. Dřívější tvar materializoval celý deník firmy
 * (u desítek tisíc zápisů desítky ms na každý dotaz) a optimalizátor pak řídil dotaz
 * přes tuhle tabulku bez indexu místo přes datum a účet.
 */
final class JournalTaxOrigin
{
    public static function cte(int $supplierId, bool $currentRead = false): string
    {
        $parentLock = $currentRead ? ' FOR UPDATE' : '';
        return self::fromRoots($supplierId, "SELECT e.id, e.source_type, e.source_id, e.reversed_by
              FROM journal_entries e
             WHERE e.supplier_id = {$supplierId}
               AND e.reversed_by IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM journal_entries parent
                    WHERE parent.supplier_id = {$supplierId} AND parent.reversed_by = e.id{$parentLock}
               )", $currentRead);
    }

    public static function provisionsBeforeCte(int $supplierId, bool $currentRead = false): string
    {
        return self::fromRoots($supplierId, "SELECT e.id, e.source_type, e.source_id, e.reversed_by
              FROM journal_entries e
             WHERE e.supplier_id = {$supplierId} AND e.source_type = 'provision'
               AND e.source_id IS NOT NULL AND e.entry_date < ?", $currentRead);
    }

    private static function fromRoots(int $supplierId, string $roots, bool $currentRead = false): string
    {
        $open = $currentRead ? '(' : '';
        $close = $currentRead ? ' FOR UPDATE)' : '';
        return "tax_journal_origins AS (
            {$open}{$roots}{$close}
            UNION ALL
            {$open}SELECT reversal.id, origin.source_type, origin.source_id, reversal.reversed_by
              FROM tax_journal_origins origin
              JOIN journal_entries reversal ON reversal.id = origin.reversed_by
             WHERE reversal.supplier_id = {$supplierId}{$close}
        )";
    }

    /** Připojení původu k zápisu `$entryAlias` — jen s CTE z {@see cte()}. */
    public static function join(string $entryAlias = 'e'): string
    {
        return "LEFT JOIN tax_journal_origins tax_origin ON tax_origin.id = {$entryAlias}.id";
    }

    public static function sourceTypeSql(string $entryAlias = 'e'): string
    {
        return "CASE WHEN tax_origin.id IS NULL THEN {$entryAlias}.source_type ELSE tax_origin.source_type END";
    }

    public static function sourceIdSql(string $entryAlias = 'e'): string
    {
        return "CASE WHEN tax_origin.id IS NULL THEN {$entryAlias}.source_id ELSE tax_origin.source_id END";
    }

    /**
     * CAST parametru: výraz CASE nemá afinitu sloupce, takže by SQLite porovnalo číslo
     * s textově předaným parametrem jako text (a skladový slot by tiše vypadl).
     */
    public static function includedSql(string $entryAlias = 'e'): string
    {
        return '(' . self::sourceTypeSql($entryAlias) . " <> 'closing' OR " . self::sourceIdSql($entryAlias) . ' >= CAST(? AS SIGNED))';
    }
}
