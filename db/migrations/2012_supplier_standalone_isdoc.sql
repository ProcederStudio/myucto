-- Samostatný ISDOC na web faktuře a v e-mailech je nezávislý na vložení do PDF.
ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS public_isdoc_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER embed_isdoc,
  ADD COLUMN IF NOT EXISTS email_attach_isdoc TINYINT(1) NOT NULL DEFAULT 0 AFTER public_isdoc_enabled;
