-- Klientské vytěžení je oddělené od skenování e-mailové schránky.
ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS client_ai_extraction_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER ai_eu_residency_required,
  ADD COLUMN IF NOT EXISTS client_ai_extraction_monthly_limit INT UNSIGNED NOT NULL DEFAULT 100 AFTER client_ai_extraction_enabled;

CREATE TABLE IF NOT EXISTS client_ai_extraction_usage (
  supplier_id INT UNSIGNED NOT NULL,
  period CHAR(7) NOT NULL,
  used INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (supplier_id, period),
  CONSTRAINT fk_client_ai_extraction_usage_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zachovat přístup účetních i vlastních rolí. Existující nové oprávnění nepřepisovat.
INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT role_id, 'purchase_invoices.extract', access_level
FROM role_permissions WHERE permission_key = 'purchase_invoices.scan';

INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT id, 'purchase_invoices.extract', 2 FROM roles WHERE system_key = 'client';
