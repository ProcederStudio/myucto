-- Klientské vytěžení je oddělené od skenování e-mailové schránky.
ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS client_ai_extraction_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER ai_eu_residency_required;

-- Zachovat přístup účetních i vlastních rolí. Existující nové oprávnění nepřepisovat.
INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT role_id, 'purchase_invoices.extract', access_level
FROM role_permissions WHERE permission_key = 'purchase_invoices.scan';

INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT id, 'purchase_invoices.extract', 2 FROM roles WHERE system_key = 'client';
