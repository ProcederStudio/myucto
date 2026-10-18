-- Správa ceníku je samostatná; nové právo nepřebírá široký zápis faktur.
-- Marker chrání i zákaz uložený editorem jako chybějící řádek oprávnění.
INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT id, 'price_list', CASE WHEN system_key IN ('admin', 'admin_plus') THEN 2 ELSE 1 END
FROM roles
WHERE system_key IN ('admin', 'admin_plus', 'accountant', 'readonly')
  AND NOT EXISTS (SELECT 1 FROM app_meta WHERE k = 'rbac_price_list_defaults_initialized');

INSERT IGNORE INTO app_meta (k, v)
VALUES ('rbac_price_list_defaults_initialized', '1');
