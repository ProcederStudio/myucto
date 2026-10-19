ALTER TABLE anonymized_clone_submission_provenance
  ADD COLUMN IF NOT EXISTS outbox_attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER outbox_count;
