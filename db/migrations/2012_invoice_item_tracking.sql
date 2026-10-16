-- Řádek faktury nese vybraná sériová čísla nebo šarže sledované karty.
--
-- PROČ: kartu se sledováním (motocykl s VIN, zboží v šaržích) nešlo prodat přímo
-- fakturou. Automatická výdejka při vystavení neměla odkud vzít konkrétní kusy
-- a vystavení skončilo chybou tracking_allocations_required. Výběr se ukládá na
-- řádek faktury: přežije úpravy konceptu, převezme ho dobropis a výdejka, vratka
-- i obnovení po stornu z něj vycházejí.
--
-- Identita je TEXT (serial_number / lot_code + expires_on), bez stock_tracking_unit_id
-- a bez umístění ve skladu: id se při obnově archivu do jiné firmy mění a kus se může
-- přestěhovat, umístění proto dohledá až výdej. Tvar JSON pole:
-- [{quantity, serial_number, lot_code, expires_on}] v základní jednotce karty,
-- normalizuje TrackingAllocationService::normalizeDraftInput(). NULL = řádek bez výběru.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE invoice_items
  ADD COLUMN IF NOT EXISTS tracking_json LONGTEXT NULL
      COMMENT 'vybraná sériová čísla / šarže sledované karty (JSON pole), NULL = bez výběru'
      CHECK (tracking_json IS NULL OR JSON_VALID(tracking_json))
      AFTER warehouse_id;
