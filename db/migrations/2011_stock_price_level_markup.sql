-- Cenové hladiny: přirážka vedle slevy.
--
-- PROČ: část firem má v ceníku pro některé skupiny zboží nebo typy zákazníků
-- přirážku nad standardní cenu (například díly s nízkou ceníkovou cenou pro
-- koncové zákazníky +45 %, urgentní objednávka +5 %). Hladina dosud znala jen
-- slevu 0–100 %. Přirážka je záporná sleva: cena = standardní × (1 − pct/100),
-- −45 % tedy dává 145 % standardní ceny. Spodní mez −999,999 je rozsah
-- sloupce DECIMAL(6,3).
--
-- Idempotence: CHECK MariaDB s IF NOT EXISTS neumí, proto se zahodí a přidá znovu.

SET NAMES utf8mb4;

ALTER TABLE stock_price_levels
  DROP CONSTRAINT IF EXISTS chk_spl_default_discount;
ALTER TABLE stock_price_levels
  ADD CONSTRAINT chk_spl_default_discount CHECK (default_discount_pct >= -999.999 AND default_discount_pct <= 100);

ALTER TABLE stock_price_level_rules
  DROP CONSTRAINT IF EXISTS chk_splr_value;
ALTER TABLE stock_price_level_rules
  ADD CONSTRAINT chk_splr_value CHECK (
    (rule_type = 'fixed' AND match_type = 'product' AND fixed_price IS NOT NULL AND fixed_price >= 0 AND currency_code IS NOT NULL)
    OR (rule_type = 'discount_pct' AND discount_pct IS NOT NULL AND discount_pct >= -999.999 AND discount_pct <= 100));
