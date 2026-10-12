SET NAMES utf8mb4;

-- Názvy akcí přerostly původních 50 znaků (např. `purchase_invoice.structured_import_without_buyer_ic`,
-- `payroll.employment.termination_work_injury_compensation_created`). Zápis do auditu
-- pak padal na `Data too long for column 'action'` a s ním celý request.
-- Rozšíření nemění uložené hodnoty, hash auditního řetězu tedy zůstává platný.
ALTER TABLE activity_log
  MODIFY COLUMN action VARCHAR(100) NOT NULL;
