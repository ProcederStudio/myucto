<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

/**
 * Jediný formát hodnoty parametru karty pro zobrazení ve sloupci (seznam karet
 * i sestava Stav zásob): popisek volby, číslo bez koncových nul, ano/ne neutrálně
 * jako 'true'/'false' (překládá klient nebo export), text beze změny. Víc hodnot
 * jedné karty se spojí čárkou v pořadí karty, u voleb se shodným pořadím rozhoduje
 * pořadí volby v parametru.
 */
final class StockAttributeDisplayValue
{
    /** Agregace přes aliasy `siav` (hodnoty), `sa` (parametr) a `sao` (volba) z {@see JOINS_SQL}. */
    public const AGGREGATE_SQL =
        "GROUP_CONCAT(
            CASE sa.data_type
                WHEN 'enum' THEN sao.label
                WHEN 'number' THEN TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(siav.value_num AS CHAR)))
                WHEN 'bool' THEN CASE siav.value_bool WHEN 1 THEN 'true' WHEN 0 THEN 'false' END
                ELSE siav.value_text
            END
            ORDER BY siav.display_order, sao.display_order, siav.id SEPARATOR ', ')";

    /** Připojí k `stock_item_attribute_values siav` parametr a volbu v rámci téhož tenanta. */
    public const JOINS_SQL =
        ' JOIN stock_attributes sa ON sa.id = siav.attribute_id AND sa.supplier_id = siav.supplier_id
          LEFT JOIN stock_attribute_options sao ON sao.id = siav.option_id AND sao.supplier_id = siav.supplier_id';
}
