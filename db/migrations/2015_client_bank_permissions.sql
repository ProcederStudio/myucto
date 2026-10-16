-- Ignorování plateb dosud používalo obecné právo zápisu do banky. Při jeho
-- oddělení zachováme existujícím interním rolím stejnou možnost; klientské
-- role získávají bankovní práva až výslovným přidělením administrátorem.
-- INSERT IGNORE zachová i explicitní zákaz při opakovaném spuštění migrace.
INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT rp.role_id, 'bank.ignore', 2
FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
WHERE r.role_type = 'staff'
  AND rp.permission_key = 'bank'
  AND rp.access_level = 2;
