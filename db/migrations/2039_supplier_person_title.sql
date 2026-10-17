-- Nepovinný titul daňového subjektu FO pro EPO (DPH/KH/SH/DPFO: nejvýš 10 znaků).
ALTER TABLE supplier ADD COLUMN IF NOT EXISTS person_title VARCHAR(10) NULL DEFAULT NULL AFTER opr_prijmeni;
