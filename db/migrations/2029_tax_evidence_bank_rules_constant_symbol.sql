-- Konstantní symbol jako samostatné kritérium pravidel bankovních pohybů v daňové evidenci.
-- NULL zachovává chování stávajících pravidel; KS může být jedinou podmínkou.

ALTER TABLE tax_evidence_bank_rules
  ADD COLUMN IF NOT EXISTS constant_symbol VARCHAR(4) NULL AFTER variable_symbol;

ALTER TABLE tax_evidence_bank_rules
  DROP CONSTRAINT IF EXISTS chk_tebr_criteria;
ALTER TABLE tax_evidence_bank_rules
  ADD CONSTRAINT chk_tebr_criteria CHECK (counterparty_account IS NOT NULL OR variable_symbol IS NOT NULL
                                         OR constant_symbol IS NOT NULL OR text_contains IS NOT NULL);
