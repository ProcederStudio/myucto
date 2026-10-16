-- Převzaté mzdy: čerpání ročních košů nepeněžních plnění v převzatém měsíci.
--
-- PROČ: limity osvobození § 6 odst. 9 písm. d) ZDP (zdravotní plnění do průměrné
-- mzdy, rekreace, sport, kultura a vzdělávání do její poloviny) platí za celý
-- zdaňovací rok. Firma, která začne vést mzdy v průběhu roku, musí převzít
-- i čerpání z měsíců u předchozího programu, jinak MyÚčto ve zbytku roku
-- osvobodí znovu celý limit. Spoření na stáří nese sloupec z migrace 1992.
-- NULL = zdroj údaj nenese.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS; CHECK MariaDB s IF NOT EXISTS neumí,
-- proto se nejdřív zahodí a přidá znovu.

SET NAMES utf8mb4;

ALTER TABLE payroll_migration_reference_totals
  ADD COLUMN IF NOT EXISTS non_cash_health_benefit_minor BIGINT NULL
    COMMENT 'nepeněžní zdravotní plnění § 6 odst. 9 písm. d) bod 1 ZDP v haléřích; NULL = zdroj nevydal'
    AFTER old_age_savings_contribution_minor,
  ADD COLUMN IF NOT EXISTS non_cash_leisure_benefit_minor BIGINT NULL
    COMMENT 'nepeněžní plnění na rekreaci, sport, kulturu a vzdělávání § 6 odst. 9 písm. d) bod 2 ZDP v haléřích; NULL = zdroj nevydal'
    AFTER non_cash_health_benefit_minor;

ALTER TABLE payroll_migration_reference_totals
  DROP CONSTRAINT IF EXISTS chk_pmrt_non_cash_benefits;
ALTER TABLE payroll_migration_reference_totals
  ADD CONSTRAINT chk_pmrt_non_cash_benefits CHECK (
    (non_cash_health_benefit_minor IS NULL OR non_cash_health_benefit_minor >= 0)
    AND (non_cash_leisure_benefit_minor IS NULL OR non_cash_leisure_benefit_minor >= 0)
  );
