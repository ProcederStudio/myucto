-- 1995 — AI shrnutí a AI vytěžení u dokumentů.
--
-- Shrnutí (krátké a dlouhé) se ukládá k dokumentu a je fulltextově prohledávatelné
-- vlastním indexem; vytěžení dokladu (odpověď, pole, návrh zápisů) se drží jako JSON.
SET NAMES utf8mb4;

ALTER TABLE documents
  ADD COLUMN IF NOT EXISTS ai_summary_short TEXT NULL,
  ADD COLUMN IF NOT EXISTS ai_summary_long MEDIUMTEXT NULL,
  ADD COLUMN IF NOT EXISTS ai_summary_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS ai_analysis_json LONGTEXT NULL,
  ADD COLUMN IF NOT EXISTS ai_analysis_at DATETIME NULL;

ALTER TABLE documents
  ADD FULLTEXT INDEX IF NOT EXISTS ft_documents_ai_summary (ai_summary_short, ai_summary_long);
