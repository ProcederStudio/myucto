SET NAMES utf8mb4;

-- Začátek účetního roku firmy (hospodářský rok § 3 odst. 2 ZoÚ, § 21a ZDP).
-- 1 = kalendářní rok (výchozí, dosavadní chování). Jiný měsíc = hospodářský rok
-- od 1. dne toho měsíce. Platí, dokud firma nemá vlastní účetní období: jakmile je
-- má, tvar roku určují ta. Nastavení se proto zadává PŘEDEM — před prvním převodem
-- z jiného programu nebo před aktivací účetnictví.
ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS fiscal_year_start_month TINYINT UNSIGNED NOT NULL DEFAULT 1
    COMMENT 'Měsíc začátku účetního roku (1 = kalendářní rok, 2–12 = hospodářský rok).';

ALTER TABLE supplier DROP CONSTRAINT IF EXISTS chk_supplier_fiscal_year_start_month;
ALTER TABLE supplier
  ADD CONSTRAINT chk_supplier_fiscal_year_start_month
    CHECK (fiscal_year_start_month BETWEEN 1 AND 12);

-- Firmy, které si období hospodářského roku už založily ručně, dostanou nastavení
-- podle jejich tvaru (stejné pravidlo jako FiscalCalendar::isFiscalYearShape: začíná
-- 1. dnem měsíce ≠ leden, nekončí 31. 12., délka ~12 měsíců). Jinak by nastavení tvrdilo
-- kalendářní rok a odporovalo obdobím, podle kterých firma účtuje.
UPDATE supplier s
  JOIN (
    SELECT supplier_id, MONTH(starts_on) AS start_month,
           ROW_NUMBER() OVER (PARTITION BY supplier_id ORDER BY starts_on DESC) AS rn
      FROM accounting_periods
     WHERE DAY(starts_on) = 1 AND MONTH(starts_on) <> 1
       AND DATE_FORMAT(ends_on, '%m-%d') <> '12-31'
       AND DATEDIFF(ends_on, starts_on) BETWEEN 350 AND 380
  ) p ON p.supplier_id = s.id AND p.rn = 1
   SET s.fiscal_year_start_month = p.start_month
 WHERE s.fiscal_year_start_month = 1;
