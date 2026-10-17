-- Výchozí režim zaokrouhlení nových faktur podle firmy.
--
-- PROČ: režim zaokrouhlení se dosud volil u každé faktury zvlášť a nová faktura
-- dostala vždy `auto` (jen hotovost). Firma, která chce celé koruny na všech
-- dokladech, ho musela přepínat ručně a faktury z API, MCP nebo pravidelné
-- fakturace dostaly `auto` bez možnosti volby.
--
-- `supplier.default_rounding_mode` = režim, který dostane nová faktura, když ho
-- volající nepošle (editor ho předvyplní; API, MCP, pravidelná fakturace a faktura
-- z objednávky ho převezmou). Hodnoty = InvoiceRounding::MODES. Výpočet se dál řídí
-- jen režimem uloženým na dokladu, stávající doklady se nemění. Výchozí `auto`
-- drží dosavadní chování.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS default_rounding_mode ENUM('auto', 'none', 'whole_czk') NOT NULL DEFAULT 'auto'
      COMMENT 'režim zaokrouhlení nové faktury (InvoiceRounding::MODES)'
      AFTER default_prices_include_vat;
