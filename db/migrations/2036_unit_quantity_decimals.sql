-- Počet desetinných míst množství podle měrné jednotky.
--
-- PROČ: u zboží, které se prodává po celých kusech, šlo omylem zadat 1,5 ks
-- a doklad se tak i vystavil. Správce instalace teď může u jednotky v číselníku
-- nastavit, kolik desetinných míst množství smí mít (0 až 3). Editory to hlídají
-- a API přesnější vstup odmítne (400), nic se potichu nezaokrouhluje.
--
-- NULL = jako dosud (bez omezení, množství se ukládá až na 3 místa). Žádné výchozí
-- hodnoty se nenastavují: chování se změní jen tam, kde správce volbu vyplní.
-- Jednotka na skladové kartě i na řádku dokladu je volný text, s číselníkem se
-- páruje podle kódu bez ohledu na velikost písmen ({@see UnitPrecision}).
--
-- Idempotence: ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE units
  ADD COLUMN IF NOT EXISTS quantity_decimals TINYINT UNSIGNED NULL DEFAULT NULL
      COMMENT 'max. počet desetinných míst množství (0 až 3), NULL = bez omezení'
      CHECK (quantity_decimals IS NULL OR quantity_decimals <= 3)
      AFTER display_order;
