SET NAMES utf8mb4;

-- Číslování přijatých dokladů podle typu dokladu.
--
-- `shared` (výchozí) = dosavadní chování: jedna řada pro všechny typy podle
-- `purchase_invoice_number_format`. `by_kind` = každý typ dokladu má vlastní řadu;
-- faktury dál jedou podle `purchase_invoice_number_format`, ostatní typy podle
-- vlastní šablony (NULL = vestavěná výchozí šablona typu, viz PurchaseNumberSeries).
ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS purchase_invoice_numbering ENUM('shared','by_kind') NOT NULL DEFAULT 'shared'
    COMMENT 'Číslování přijatých dokladů: shared = jedna řada, by_kind = řada podle typu dokladu.'
    AFTER purchase_invoice_number_format,
  ADD COLUMN IF NOT EXISTS purchase_receipt_number_format VARCHAR(60) NULL DEFAULT NULL
    COMMENT 'Šablona interního čísla účtenky (by_kind). NULL = vestavěná výchozí.'
    AFTER purchase_invoice_numbering,
  ADD COLUMN IF NOT EXISTS purchase_credit_note_number_format VARCHAR(60) NULL DEFAULT NULL
    COMMENT 'Šablona interního čísla přijatého dobropisu (by_kind). NULL = vestavěná výchozí.'
    AFTER purchase_receipt_number_format,
  ADD COLUMN IF NOT EXISTS purchase_advance_number_format VARCHAR(60) NULL DEFAULT NULL
    COMMENT 'Šablona interního čísla přijaté zálohy (by_kind). NULL = vestavěná výchozí.'
    AFTER purchase_credit_note_number_format,
  ADD COLUMN IF NOT EXISTS purchase_tax_document_number_format VARCHAR(60) NULL DEFAULT NULL
    COMMENT 'Šablona interního čísla daňového dokladu k záloze (by_kind). NULL = vestavěná výchozí.'
    AFTER purchase_advance_number_format;

-- Řada typu dokladu se v počítadle odliší předponou klíče období (`receipt:202610`).
-- Faktury a společná řada drží klíč beze změny, takže přepnutí režimu na nich nic nemění.
ALTER TABLE purchase_invoice_counters
  MODIFY COLUMN IF EXISTS period VARCHAR(24) NOT NULL;
