-- § 33a — auditní stopa se pečetí až po commitu zapisující transakce.
--
-- Hlava řetězu se dřív zamykala uvnitř obchodní transakce a držela se do jejího commitu:
-- všechny auditované zápisy instance se tím řadily za sebe a pod REPEATABLE READ se
-- snapshot isolation končily chybou 1020. Záznam teď vzniká v transakci bez hashe
-- a pečetí ho krátká samostatná transakce po commitu. Pořadí řetězu proto určuje
-- pořadí zapečetění (`seal_seq`), ne `id`: id se přidělují při insertu, commitují se
-- v jiném pořadí a po rollbacku v nich zůstávají díry.
SET NAMES utf8mb4;

ALTER TABLE activity_log
    ADD COLUMN IF NOT EXISTS seal_seq BIGINT UNSIGNED NULL
        COMMENT 'pořadí v hash řetězu; NULL = nezapečetěno',
    ADD UNIQUE KEY IF NOT EXISTS uq_activity_seal_seq (seal_seq);

ALTER TABLE activity_log_chain_head
    ADD COLUMN IF NOT EXISTS last_seq BIGINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'seal_seq posledního zapečetěného záznamu',
    ADD COLUMN IF NOT EXISTS deferred_from_id BIGINT UNSIGNED NULL
        COMMENT 'od tohoto id se pečetí po commitu; starší záznamy bez hashe jsou nechráněná historie';

INSERT IGNORE INTO activity_log_chain_head (id, last_id, last_hash) VALUES (1, NULL, NULL);

-- Dosavadní řetěz vznikal v pořadí id, takže v tom pořadí dostane i seal_seq.
-- Hash se nemění, seal_seq do něj nevstupuje.
UPDATE activity_log a
  JOIN (
        SELECT id,
               ROW_NUMBER() OVER (ORDER BY id)
                 + (SELECT COALESCE(MAX(seal_seq), 0) FROM activity_log) AS seq
          FROM activity_log
         WHERE hash IS NOT NULL AND seal_seq IS NULL
       ) n ON n.id = a.id
   SET a.seal_seq = n.seq;

UPDATE activity_log_chain_head
   SET last_seq = COALESCE((SELECT MAX(seal_seq) FROM activity_log), 0)
 WHERE id = 1;

-- Záznamy bez hashe pod hranicí se zpětně nepečetí: hash spočtený dnes nad daty,
-- která mohla být kdykoli změněna, by nic nedokazoval.
UPDATE activity_log_chain_head
   SET deferred_from_id = COALESCE((SELECT MAX(id) + 1 FROM activity_log), 1)
 WHERE id = 1
   AND deferred_from_id IS NULL;
