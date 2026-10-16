-- Dohody o srážkách ze mzdy: kolik z limitu dohody srazil předchozí mzdový program.
--
-- PROČ: firma, která začne vést mzdy v MyÚčtu v průběhu trvání dohody (třeba od
-- září), má část limitu už sraženou předchozím programem. Ledger srážek nese jen
-- pohyby vlastních běhů (invariant M5: withheld_total_minor = součet ledgeru),
-- takže převzatá část žije ve vlastním sloupci a čerpá limit dohody vedle něj.
-- Bez ní MyÚčto srazí celý limit znovu.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE payroll_deduction_agreements
  ADD COLUMN IF NOT EXISTS taken_over_withheld_minor BIGINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'sraženo předchozím mzdovým programem v haléřích; čerpá limit dohody mimo ledger'
    AFTER withheld_total_minor;

ALTER TABLE payroll_deduction_agreement_versions
  ADD COLUMN IF NOT EXISTS taken_over_withheld_minor BIGINT UNSIGNED NOT NULL DEFAULT 0
    AFTER withheld_total_minor;
