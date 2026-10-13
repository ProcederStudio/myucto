-- MyÚčto — jedna schránka pro více firem a přesun příchozího dokladu do jiné firmy.
--
-- IMAP účet firmy může doklady a výpisy rozdělovat mezi všechny firmy, do kterých
-- smí zapisovat uživatel, který rozdělování zapnul (`distribute_user_id`). Výpis
-- patří firmě, které patří číslo účtu, doklad firmě, které patří IČO nebo DIČ
-- odběratele. Co nejde přiřadit, zůstává ve firmě, které schránka patří.
--
-- Oprávnění se neukládají: skener je ověřuje při každém běhu podle aktuálních rolí
-- uživatele. Odebraný přístup k firmě tak rozdělování do ní hned zastaví.

SET NAMES utf8mb4;

ALTER TABLE bank_email_imap_settings
  ADD COLUMN IF NOT EXISTS distribute_to_suppliers TINYINT(1) NOT NULL DEFAULT 0 AFTER ingest_pdf_statements,
  ADD COLUMN IF NOT EXISTS distribute_user_id BIGINT UNSIGNED NULL AFTER distribute_to_suppliers;

ALTER TABLE bank_email_imap_settings
  ADD CONSTRAINT fk_beis_distribute_user FOREIGN KEY IF NOT EXISTS (distribute_user_id)
    REFERENCES users(id) ON DELETE SET NULL;

-- Firma, do které příloha nebo avízo skutečně odešlo. NULL = firma schránky.
ALTER TABLE bank_email_attachment_ingests
  ADD COLUMN IF NOT EXISTS target_supplier_id INT UNSIGNED NULL AFTER submission_id,
  ADD INDEX IF NOT EXISTS idx_beai_target_supplier (target_supplier_id);

ALTER TABLE bank_email_attachment_ingests
  ADD CONSTRAINT fk_beai_target_supplier FOREIGN KEY IF NOT EXISTS (target_supplier_id)
    REFERENCES supplier(id) ON DELETE SET NULL;

ALTER TABLE bank_email_processed_messages
  ADD COLUMN IF NOT EXISTS target_supplier_id INT UNSIGNED NULL AFTER supplier_id,
  ADD INDEX IF NOT EXISTS idx_bepm_target_supplier (target_supplier_id);

ALTER TABLE bank_email_processed_messages
  ADD CONSTRAINT fk_bepm_target_supplier FOREIGN KEY IF NOT EXISTS (target_supplier_id)
    REFERENCES supplier(id) ON DELETE SET NULL;

-- Odběratel, kterého na dokladu našlo vytěžení (ISDOC nebo AI). Neprojde-li vytěžení
-- proto, že doklad patří jiné firmě, fronta podle něj nabídne přesun.
ALTER TABLE purchase_invoice_submissions
  ADD COLUMN IF NOT EXISTS detected_buyer_ic VARCHAR(20) NULL AFTER extraction_error,
  ADD COLUMN IF NOT EXISTS detected_buyer_dic VARCHAR(20) NULL AFTER detected_buyer_ic;
