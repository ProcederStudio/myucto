-- MyÚčto — platební příkazy pro daně firmy.
--
-- Položka příkazu může kromě přijaté faktury a vratky odběrateli mířit i na daň
-- finančnímu úřadu: DPH z podaného přiznání (tax_submissions) nebo předpis zálohy
-- na daň z příjmů (tax_advance_schedules, druh tax). Právě jeden cíl je vyplněný.
--
-- Mzdové odvody (daň ze závislé činnosti, srážková daň, pojistné) se dál platí
-- mzdovými dávkami, ne těmito příkazy.

SET NAMES utf8mb4;

ALTER TABLE payment_order_items
  ADD COLUMN IF NOT EXISTS tax_submission_id INT UNSIGNED NULL AFTER invoice_id,
  ADD COLUMN IF NOT EXISTS tax_advance_schedule_id INT UNSIGNED NULL AFTER tax_submission_id,
  ADD INDEX IF NOT EXISTS idx_poi_tax_submission (tax_submission_id),
  ADD INDEX IF NOT EXISTS idx_poi_tax_advance (tax_advance_schedule_id);

-- Předpis zálohy cizí klíč nemá: plánované předpisy se při přepočtu přiznání mažou
-- a zakládají znovu (TaxAdvanceScheduleService::replacePlanned) a RESTRICT by přepočet
-- zablokoval. Položka nese vlastní snímek příjemce, VS i částky, odkaz je jen stopa.
ALTER TABLE payment_order_items
  ADD CONSTRAINT fk_poi_tax_submission FOREIGN KEY IF NOT EXISTS (tax_submission_id)
    REFERENCES tax_submissions(id) ON DELETE RESTRICT;

ALTER TABLE payment_order_items DROP CONSTRAINT IF EXISTS chk_poi_single_target;
ALTER TABLE payment_order_items
  ADD CONSTRAINT chk_poi_single_target CHECK (
    (purchase_invoice_id IS NOT NULL) + (invoice_id IS NOT NULL)
      + (tax_submission_id IS NOT NULL) + (tax_advance_schedule_id IS NOT NULL) = 1
  );
