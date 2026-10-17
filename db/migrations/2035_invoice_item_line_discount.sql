-- Ceníková cena a sleva u položky faktury.
--
-- PROČ: zákazník s individuální cenou nebo cenovou hladinou dostal na faktuře jen
-- výslednou cenu. Kolik ze ceníku ušetřil, z dokladu nevyčetl. Řádek si proto
-- pamatuje cenu před slevou a firma si může zapnout její rozpis na PDF.
--
-- Je to INFORMAČNÍ údaj. Částky dokladu (cena za jednotku, základ, DPH, součty)
-- dál určuje `unit_price_without_vat`, exporty (ISDOC, POHODA) ho nečtou.
--
-- list_unit_price    cena za jednotku řádku před slevou, ve stejném základu jako
--                    unit_price_without_vat (v režimu „ceny s DPH" brutto). NULL = neznámá.
-- line_discount_pct  sleva v % dopočtená serverem z obou cen (LineDiscount::percent),
--                    záporná = přirážka. NULL = bez slevy (shoda, chybějící ceník).
-- supplier.invoice_pdf_show_line_discount  rozpis slevy na PDF faktury, výchozí vypnuto.
--
-- Hlavičková sleva dokladu (invoices.discount_percent) zůstává samostatným
-- slevovým řádkem a s touhle slevou se nesčítá.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE invoice_items
  ADD COLUMN IF NOT EXISTS list_unit_price DECIMAL(16,6) NULL
      COMMENT 'ceníková cena za jednotku před slevou (informační), NULL = neznámá'
      AFTER unit_price_without_vat,
  ADD COLUMN IF NOT EXISTS line_discount_pct DECIMAL(7,3) NULL
      COMMENT 'sleva z ceníkové ceny v %, záporná = přirážka, NULL = bez slevy'
      AFTER list_unit_price;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS invoice_pdf_show_line_discount TINYINT(1) NOT NULL DEFAULT 0;
