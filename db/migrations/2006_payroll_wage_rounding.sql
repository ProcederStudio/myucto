-- MyÚčto.cz — zaokrouhlení krácené mzdy a náhrad mzdy podle volby firmy.
--
-- Výchozí 'ceil' drží § 142 odst. 2 ZP (na celé koruny nahoru). 'half_up'
-- zaokrouhluje matematicky jako předchozí mzdový program, ze kterého firma
-- přešla, aby navazující měsíce seděly s jeho výsledky.

SET NAMES utf8mb4;

ALTER TABLE payroll_employer_settings
  ADD COLUMN IF NOT EXISTS wage_rounding VARCHAR(16) NOT NULL DEFAULT 'ceil'
    AFTER payroll_contact_phone;

ALTER TABLE payroll_employer_settings
  DROP CONSTRAINT IF EXISTS chk_payroll_employer_wage_rounding;

ALTER TABLE payroll_employer_settings
  ADD CONSTRAINT chk_payroll_employer_wage_rounding
    CHECK (wage_rounding IN ('ceil', 'half_up'));
