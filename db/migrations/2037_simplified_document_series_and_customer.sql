-- Zjednodušený daňový doklad (§ 30 ZDPH): vlastní číselná řada a volba netisknout odběratele.
--
-- PROČ: pokladní prodej se vystavuje jako zjednodušený daňový doklad a firma ho chce
-- číslovat zvlášť od běžných faktur (účtenky jedné řady, faktury druhé). Zjednodušený
-- doklad navíc údaje o odběrateli nevyžaduje (§ 30a), takže je firma smí vynechat
-- i tehdy, když prodej eviduje na konkrétního klienta (statistiky, cenová hladina).
--
--   supplier.simplified_number_format          šablona řady; NULL = sdílí řadu faktur
--                                              (dosavadní chování, včetně řady klienta
--                                              a kategorie tržby)
--   supplier.simplified_hide_customer_default  výchozí hodnota volby na novém dokladu
--   invoices.hide_customer_on_pdf              volba na dokladu: vystavené PDF se změnou
--                                              nastavení firmy nemění; platí jen pro
--                                              zjednodušený doklad, běžná faktura
--                                              odběratele nést musí (§ 29)
--   invoice_counters.invoice_type + 'simplified'  počítadlo vlastní řady
--
-- Idempotence: ADD COLUMN IF NOT EXISTS, MODIFY je opakovatelný.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS simplified_number_format VARCHAR(60) NULL
      COMMENT 'šablona řady zjednodušených daňových dokladů, NULL = řada faktur'
      AFTER credit_note_number_format,
  ADD COLUMN IF NOT EXISTS simplified_hide_customer_default TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'výchozí netisknout odběratele na zjednodušeném dokladu';

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS hide_customer_on_pdf TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'zjednodušený doklad bez údajů odběratele na PDF a webové faktuře'
      AFTER is_simplified;

ALTER TABLE invoice_counters
  MODIFY invoice_type ENUM('invoice','proforma','credit_note','simplified') NOT NULL;
