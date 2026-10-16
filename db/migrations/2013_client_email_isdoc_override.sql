-- NULL přebírá nastavení firmy, 0/1 má pro e-mail odběratele přednost.
ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS email_attach_isdoc TINYINT(1) NULL DEFAULT NULL AFTER email_attachment_name_format;
