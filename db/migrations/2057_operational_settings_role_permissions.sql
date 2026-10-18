-- Rozdělení původního klientského balíku a interních firemních nastavení.
-- Jednorázový marker zachová pozdější odvolání i při odstranění nulových grantů.
INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, areas.permission_key, 2
FROM roles r
JOIN role_permissions source ON source.role_id = r.id
CROSS JOIN (
    SELECT 'settings.email_profiles' AS permission_key
    UNION ALL SELECT 'settings.email_templates'
    UNION ALL SELECT 'settings.payment_qr'
    UNION ALL SELECT 'settings.branding'
    UNION ALL SELECT 'settings.signing_profiles'
) areas
WHERE source.access_level = 2
  AND ((r.role_type = 'client' AND source.permission_key = 'settings.company')
    OR (r.role_type = 'staff' AND source.permission_key = 'settings.company.write'
        AND areas.permission_key IN ('settings.email_profiles', 'settings.email_templates', 'settings.payment_qr')))
  AND NOT EXISTS (SELECT 1 FROM app_meta WHERE k = 'rbac_operational_settings_initialized');

INSERT IGNORE INTO app_meta (k, v)
VALUES ('rbac_operational_settings_initialized', '1');
