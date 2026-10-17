-- Úsporná černobílá faktura: per-supplier přepínač vzhledu PDF faktury a výkazu.
--
-- PROČ: doklad se tiskne i na černobílé laserové tiskárně a ukládá do archivu.
-- Plné fialové plochy (hlavička tabulky, řádek Celkem, platební panel) pálí toner
-- a v odstínech šedi ztrácí kontrast. Zapnutá volba připojí místo barevného akcentu
-- styles/invoice-mono.css: bez plných ploch, černý text, tenké čáry.
--
-- Výchozí 0 = PDF beze změny. Hodnota se čte z živého dodavatele (snapshot dokladu
-- ji nenese). Po změně se zneplatní PDF konceptů; už vygenerovaná PDF vystavených
-- dokladů zůstávají, dokud se nevytvoří znovu.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS invoice_pdf_monochrome TINYINT(1) NOT NULL DEFAULT 0 AFTER pdf_logo_show_name;
