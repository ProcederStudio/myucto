-- Úprava vystavené faktury (?force=1) byla vyhrazená správci firmy. Nové právo
-- invoices.edit_issued ji zpřístupní i vlastní interní roli, kterou správce v editoru
-- rolí vybere. Správcovské role ho dostanou do matice, aby editor rolí ukazoval jejich
-- skutečný rozsah; ostatní role (včetně výchozí účetní) zůstávají beze změny.
-- INSERT IGNORE je bezpečný při opakovaném spuštění.
INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, 'invoices.edit_issued', 2
FROM roles r
WHERE r.system_key IN ('admin', 'admin_plus');
