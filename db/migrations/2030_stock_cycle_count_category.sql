-- Cyklická inventura může mít rozsah „kategorie včetně podkategorií“.
--
-- PROČ: průběžná kontrola skladu se v praxi dělá po skupinách sortimentu, ne po
-- jednotlivých kartách. Výběr karet podle kategorie se dělá jednou při
-- založení (do vstupu přípravné úlohy), sloupec
-- slouží jen k zobrazení rozsahu v seznamu a detailu a jako auditní stopa.
-- Uzávěrka pracuje výhradně s řádky inventury, karty mimo rozsah nemění.
--
-- FK ON DELETE SET NULL: smazání kategorie inventuru neblokuje ani nemaže,
-- jen ztratí odkaz na rozsah. Jednosloupcový FK na stock_categories(id) jako
-- u ostatních odkazů na kategorie, příslušnost k firmě ověřuje služba.
--
-- Idempotence: ADD COLUMN / KEY / FOREIGN KEY IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE stock_cycle_counts
  ADD COLUMN IF NOT EXISTS category_id BIGINT UNSIGNED NULL DEFAULT NULL
      COMMENT 'rozsah inventury: kategorie včetně podkategorií, NULL = bez omezení kategorií'
      AFTER location_id,
  ADD KEY IF NOT EXISTS ix_cycle_category (category_id);

ALTER TABLE stock_cycle_counts
  ADD CONSTRAINT fk_scc_category FOREIGN KEY IF NOT EXISTS (category_id)
    REFERENCES stock_categories(id) ON DELETE SET NULL;
