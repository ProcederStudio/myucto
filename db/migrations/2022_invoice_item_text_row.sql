-- Textový řádek mezi položkami vydaného dokladu (item_kind = 'text').
--
-- PROČ: doklad potřebuje nadpis nebo poznámku MEZI položkami (název zakázky, místo
-- montáže, oddíl „Materiál" a „Práce"). Poznámka nad a pod položkami (note_above_items,
-- note_below_items) to neumí, protože stojí mimo tabulku.
--
-- Textový řádek nese jen popis a sazbu DPH. Sazba je vyplněná proto, že sloupec je
-- NOT NULL a řada dotazů na `vat_rates` dělá INNER JOIN. Množství, ceny i totály jsou
-- nula; sklad, majetek, OSS, klasifikace DPH, výnosový účet ani časové rozlišení
-- zůstávají prázdné. Do součtů, rekapitulace DPH, výkazů DPH, OSS, zaúčtování ani
-- skladu nevstupuje (InvoiceItemKind). Dokud textový řádek nikdo nepoužije, nemění se
-- nic: výchozí hodnota zůstává 'standard'.
--
-- Šablona pravidelné fakturace dostává týž sloupec, aby se textový řádek přenesl
-- do vygenerovaného dokladu. Slevu šablona jako řádek nikdy nedrží (generuje ji
-- až replaceItems() faktury), proto má jen 'standard' a 'text'.
--
-- Idempotence: MODIFY COLUMN je opakovatelný, ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE invoice_items
  MODIFY COLUMN item_kind ENUM('standard','discount','text') NOT NULL DEFAULT 'standard';

ALTER TABLE recurring_invoice_template_items
  ADD COLUMN IF NOT EXISTS item_kind ENUM('standard','text') NOT NULL DEFAULT 'standard'
    COMMENT 'text = textový řádek bez částky, přenáší se do vygenerované faktury'
    AFTER order_index;
