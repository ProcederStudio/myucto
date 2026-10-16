-- MyÚčto.cz — indexovaný klíč variabilního symbolu pro párování bankovních pohybů.
--
-- Párování porovnávalo VS pohybu s doklady výrazem
-- CAST(REGEXP_REPLACE(sloupec, '[^0-9]', '') AS UNSIGNED), který index nepoužije:
-- každý pohyb tak procházel všechny doklady firmy. U firmy s 30 000 fakturami to je
-- kolem 25 ms na pohyb a výpis s tisíci platbami se páruje minuty.
--
-- Klíč = číslice dokladu bez vodicích nul, NULL když žádná nenulová číslice není
-- (VariableSymbolNormalizer::matchKey). Uložené generované sloupce s indexem
-- (supplier_id, klíč); aditivní, idempotentní (ADD COLUMN / INDEX IF NOT EXISTS).

SET NAMES utf8mb4;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS varsymbol_match_key VARCHAR(20)
    AS (NULLIF(TRIM(LEADING '0' FROM REGEXP_REPLACE(varsymbol, '[^0-9]', '')), '')) STORED
    COMMENT 'Klíč VS pro párování plateb: číslice čísla dokladu bez vodicích nul',
  ADD COLUMN IF NOT EXISTS payment_variable_symbol_match_key VARCHAR(10)
    AS (NULLIF(TRIM(LEADING '0' FROM REGEXP_REPLACE(payment_variable_symbol, '[^0-9]', '')), '')) STORED
    COMMENT 'Klíč VS pro párování plateb: platební VS bez vodicích nul';

ALTER TABLE invoices
  ADD INDEX IF NOT EXISTS idx_inv_supplier_vs_key (supplier_id, varsymbol_match_key),
  ADD INDEX IF NOT EXISTS idx_inv_supplier_payment_vs_key (supplier_id, payment_variable_symbol_match_key);

ALTER TABLE purchase_invoices
  ADD COLUMN IF NOT EXISTS varsymbol_match_key VARCHAR(20)
    AS (NULLIF(TRIM(LEADING '0' FROM REGEXP_REPLACE(varsymbol, '[^0-9]', '')), '')) STORED
    COMMENT 'Klíč VS pro párování plateb: číslice interního čísla bez vodicích nul',
  ADD COLUMN IF NOT EXISTS payment_variable_symbol_match_key VARCHAR(20)
    AS (NULLIF(TRIM(LEADING '0' FROM REGEXP_REPLACE(payment_variable_symbol, '[^0-9]', '')), '')) STORED
    COMMENT 'Klíč VS pro párování plateb: platební VS bez vodicích nul',
  ADD COLUMN IF NOT EXISTS vendor_invoice_number_match_key VARCHAR(50)
    AS (NULLIF(TRIM(LEADING '0' FROM REGEXP_REPLACE(vendor_invoice_number, '[^0-9]', '')), '')) STORED
    COMMENT 'Klíč VS pro párování plateb: číslice čísla dokladu dodavatele bez vodicích nul';

ALTER TABLE purchase_invoices
  ADD INDEX IF NOT EXISTS idx_pi_supplier_vs_key (supplier_id, varsymbol_match_key),
  ADD INDEX IF NOT EXISTS idx_pi_supplier_payment_vs_key (supplier_id, payment_variable_symbol_match_key),
  ADD INDEX IF NOT EXISTS idx_pi_supplier_vendor_no_key (supplier_id, vendor_invoice_number_match_key);
