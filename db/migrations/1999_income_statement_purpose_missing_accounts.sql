-- Účelová VZZ: doplnění účtů, které přibyly do druhové mapy až po migraci 1161.
--
-- 1161 zkopírovala globální mapu druhové VZZ do účelové jen jednou. Prefixy přidané
-- do druhové mapy později (600, 640, 667) v účelové chyběly, výkaz je hlásil jako
-- nepřiřazené a účelové členění se nesestavilo (function_map_incomplete) — u 667
-- (výnosy z derivátů) dokonce s výzvou přiřadit výnosový účet funkci nákladů.
--
-- Překódování řádků je totožné s 1161. Idempotentní: doplní jen prefixy, které
-- v účelové mapě dosud nejsou, takže zachytí i případné další budoucí rozdíly.

SET @vf := (SELECT id FROM statement_versions WHERE statement_type = 'income_statement_purpose' LIMIT 1);
SET @vd := (SELECT id FROM statement_versions WHERE statement_type = 'income_statement' LIMIT 1);

INSERT INTO statement_account_map (version_id, row_code, account_prefix, target, balance_condition, sign)
SELECT @vf, t.new_code, m.account_prefix, m.target, m.balance_condition, m.sign
  FROM statement_account_map m
  JOIN (
              SELECT 'I.'     AS old_code, 'I.'   AS new_code
    UNION ALL SELECT 'II.',    'I.'
    UNION ALL SELECT 'III.1.', 'II.'
    UNION ALL SELECT 'III.2.', 'II.'
    UNION ALL SELECT 'III.3.', 'II.'
    UNION ALL SELECT 'F.1.',   'D.'
    UNION ALL SELECT 'F.2.',   'D.'
    UNION ALL SELECT 'F.3.',   'D.'
    UNION ALL SELECT 'F.4.',   'D.'
    UNION ALL SELECT 'F.5.',   'D.'
    UNION ALL SELECT 'IV.',    'III.'
    UNION ALL SELECT 'G.',     'E.'
    UNION ALL SELECT 'V.',     'IV.'
    UNION ALL SELECT 'H.',     'F.'
    UNION ALL SELECT 'VI.',    'V.'
    UNION ALL SELECT 'I.n',    'G.'
    UNION ALL SELECT 'J.',     'H.'
    UNION ALL SELECT 'VII.',   'VI.'
    UNION ALL SELECT 'K.',     'I.f'
    UNION ALL SELECT 'L.1.',   'J.1.'
    UNION ALL SELECT 'L.2.',   'J.2.'
    UNION ALL SELECT 'M.',     'K.'
  ) AS t ON t.old_code = m.row_code
 WHERE m.version_id = @vd
   AND @vf IS NOT NULL
   AND NOT EXISTS (
       SELECT 1 FROM statement_account_map p
        WHERE p.version_id = @vf AND p.account_prefix = m.account_prefix
   );
