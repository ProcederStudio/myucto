SET NAMES utf8mb4;

-- Systémový „koncový zákazník" firmy pro zjednodušený daňový doklad (§ 30 ZDPH).
-- Zjednodušený doklad nemusí nést údaje o odběrateli; když ho uživatel vystaví bez
-- klienta, doklad se naváže na tohoto skrytého klienta (jeden na firmu). V seznamu
-- klientů ani ve vyhledávání se neukazuje a na PDF se jako odběratel netiskne.
ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS is_walk_in TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Systémový koncový zákazník pro zjednodušené doklady (§ 30 ZDPH), jeden na firmu.';

CREATE INDEX IF NOT EXISTS idx_clients_walk_in ON clients (supplier_id, is_walk_in);
