-- „Vystavil" na faktuře: kdo doklad vystavil a volba, zda to tisknout na PDF.
--
-- PROČ: firmy s více fakturanty potřebují na dokladu vidět, kdo ho vystavil
-- (dotazy odběratele, interní kontrola). Autor konceptu (`created_by`) to není:
-- koncept často připraví jiný člověk, než kdo ho vystaví.
--
-- `issued_by` = uživatel, který doklad vystavil (vystavení z detailu, vystavení po
-- schválení výkazu uživatelem, ručně spuštěná pravidelná fakturace, interní storno).
-- Automat (cron, schválení klientem přes odkaz), import, převod a koncepty ho nechávají
-- NULL a PDF pak tiskne autora dokladu.
-- `issued_by_name` = jméno v okamžiku vystavení. Snapshot proto, aby pozdější
-- přejmenování nebo smazání uživatele nezměnilo už vystavený doklad.
-- `supplier.invoice_pdf_show_issued_by` = tisknout řádek „Vystavil" na PDF (výchozí
-- vypnuto, dosavadní vzhled faktur se nemění).
--
-- Idempotence: ADD COLUMN / ADD FOREIGN KEY IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS issued_by BIGINT UNSIGNED NULL
      COMMENT 'uživatel, který doklad vystavil; NULL = neznámý (import, starší doklad)'
      AFTER created_by,
  ADD COLUMN IF NOT EXISTS issued_by_name VARCHAR(120) NULL
      COMMENT 'jméno vystavitele v okamžiku vystavení (snapshot)'
      AFTER issued_by,
  ADD FOREIGN KEY IF NOT EXISTS fk_inv_issued_by (issued_by)
      REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS invoice_pdf_show_issued_by TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'tisknout na PDF faktury řádek Vystavil'
      AFTER invoice_pdf_show_base_qty;
