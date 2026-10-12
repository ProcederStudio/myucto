-- MyÚčto.cz — daňová podání (DPH, KH, SH, DPPO/DPFO) datovou schránkou.
--
-- 1) Číselník datových schránek finančních úřadů.
--    Kód příjemce `fu_<c_ufo>` váže schránku na kód finančního úřadu z EPO
--    číselníku `ufo` (https://adisspr.mfcr.cz/dpr/epo_ciselnik?C=ufo), tedy na
--    hodnotu, kterou firma už má v `supplier.financial_office_code` a kterou
--    generátory XML píší do `c_ufo`. ID schránek jsou ze seznamu Finanční správy
--    „Identifikační kódy FŘ a FÚ" (stav k 1. 7. 2023), ověřeno 12. 10. 2026.
--    Podání se činí u místně příslušného správce daně (§ 73 odst. 1 DŘ), proto
--    se seedují jen krajské FÚ a Specializovaný FÚ, ne územní pracoviště. Všechny
--    mají shodné IČ 72080043, rozliší je jedině ID schránky. Před odesláním se
--    schránka stejně ověřuje dotazem do ISDS.
--
-- 2) Směrování podle druhu výkazu (`tax_submission_settings`). Prázdná hodnota
--    znamená výchozí schránku FÚ podle `financial_office_code`; vyplněná
--    přepisuje cíl pro DPH, KH, SH nebo daň z příjmů zvlášť.
--
-- 3) Důkazní artefakty podání datovou schránkou: doručenka (ZFO) a příchozí
--    zpráva finančního úřadu (ZFO), které se k podání váží.
--
-- Idempotentní: INSERT … WHERE NOT EXISTS + UPDATE na kanonické hodnoty,
-- ADD COLUMN / FOREIGN KEY IF NOT EXISTS, MODIFY ENUM na cílovou definici.

SET NAMES utf8mb4;

INSERT INTO submission_recipients
  (supplier_id, code, name, business_id, address, kind, isds_box_id,
   source_url, source_note, is_active)
SELECT seed.supplier_id, seed.code, seed.name, seed.business_id, seed.address,
       'tax_office', seed.isds_box_id,
       'https://financnisprava.gov.cz/assets/cs/prilohy/de-datove-schranky/Identifikacni_kody_FR_a_FU.pdf',
       'Seznam ID kódů orgánů Finanční správy (stav 1. 7. 2023), ověřeno 2026-10-12; kód c_ufo z EPO číselníku ufo',
       1
FROM (
  SELECT NULL AS supplier_id, 'fu_451' AS code,
         'Finanční úřad pro hlavní město Prahu' AS name, '72080043' AS business_id,
         'Štěpánská 619/28, 112 33 Praha 1' AS address, '7nyn2d9' AS isds_box_id
  UNION ALL SELECT NULL, 'fu_452', 'Finanční úřad pro Středočeský kraj', '72080043',
         'Na Pankráci 1685/17, 140 21 Praha 4', '6sxny3p'
  UNION ALL SELECT NULL, 'fu_453', 'Finanční úřad pro Jihočeský kraj', '72080043',
         'Mánesova 1803/3a, 371 87 České Budějovice', 'scdnz6b'
  UNION ALL SELECT NULL, 'fu_454', 'Finanční úřad pro Plzeňský kraj', '72080043',
         'Hálkova 14, 305 72 Plzeň', 'gf9n2e3'
  UNION ALL SELECT NULL, 'fu_455', 'Finanční úřad pro Karlovarský kraj', '72080043',
         'Krymská 2a, 360 01 Karlovy Vary', 'q9in2ev'
  UNION ALL SELECT NULL, 'fu_456', 'Finanční úřad pro Ústecký kraj', '72080043',
         'Velká hradební 39/61, 400 21 Ústí nad Labem', 'qjfn2bj'
  UNION ALL SELECT NULL, 'fu_457', 'Finanční úřad pro Liberecký kraj', '72080043',
         '1. máje 97, 460 02 Liberec', 'zcqn2bf'
  UNION ALL SELECT NULL, 'fu_458', 'Finanční úřad pro Královéhradecký kraj', '72080043',
         'Horova 17, 500 02 Hradec Králové', 'fj8ny4i'
  UNION ALL SELECT NULL, 'fu_459', 'Finanční úřad pro Pardubický kraj', '72080043',
         'Boženy Němcové 2625, 530 02 Pardubice', '95zn2bb'
  UNION ALL SELECT NULL, 'fu_460', 'Finanční úřad pro Kraj Vysočina', '72080043',
         'Tolstého 2, 586 01 Jihlava', 'tqjn2cy'
  UNION ALL SELECT NULL, 'fu_461', 'Finanční úřad pro Jihomoravský kraj', '72080043',
         'náměstí Svobody 4, 602 00 Brno', 'qdhny4c'
  UNION ALL SELECT NULL, 'fu_462', 'Finanční úřad pro Olomoucký kraj', '72080043',
         'Lazecká 545/22, 779 00 Olomouc', '25nnz67'
  UNION ALL SELECT NULL, 'fu_463', 'Finanční úřad pro Moravskoslezský kraj', '72080043',
         'Na Jízdárně 3162/3, 709 00 Ostrava', '4hun2cu'
  UNION ALL SELECT NULL, 'fu_464', 'Finanční úřad pro Zlínský kraj', '72080043',
         'třída Tomáše Bati 21, 761 86 Zlín', 'n69nz5y'
  UNION ALL SELECT NULL, 'fu_13', 'Specializovaný finanční úřad', '72080043',
         'nábřeží Kpt. Jaroše 1000/7, 170 00 Praha 7', '7cs8cge'
) AS seed
WHERE NOT EXISTS (
  SELECT 1 FROM submission_recipients recipient
   WHERE recipient.supplier_id IS NULL AND recipient.code = seed.code
);

UPDATE submission_recipients recipient
JOIN (
  SELECT 'fu_451' AS code, 'Finanční úřad pro hlavní město Prahu' AS name,
         'Štěpánská 619/28, 112 33 Praha 1' AS address, '7nyn2d9' AS isds_box_id
  UNION ALL SELECT 'fu_452', 'Finanční úřad pro Středočeský kraj',
         'Na Pankráci 1685/17, 140 21 Praha 4', '6sxny3p'
  UNION ALL SELECT 'fu_453', 'Finanční úřad pro Jihočeský kraj',
         'Mánesova 1803/3a, 371 87 České Budějovice', 'scdnz6b'
  UNION ALL SELECT 'fu_454', 'Finanční úřad pro Plzeňský kraj',
         'Hálkova 14, 305 72 Plzeň', 'gf9n2e3'
  UNION ALL SELECT 'fu_455', 'Finanční úřad pro Karlovarský kraj',
         'Krymská 2a, 360 01 Karlovy Vary', 'q9in2ev'
  UNION ALL SELECT 'fu_456', 'Finanční úřad pro Ústecký kraj',
         'Velká hradební 39/61, 400 21 Ústí nad Labem', 'qjfn2bj'
  UNION ALL SELECT 'fu_457', 'Finanční úřad pro Liberecký kraj',
         '1. máje 97, 460 02 Liberec', 'zcqn2bf'
  UNION ALL SELECT 'fu_458', 'Finanční úřad pro Královéhradecký kraj',
         'Horova 17, 500 02 Hradec Králové', 'fj8ny4i'
  UNION ALL SELECT 'fu_459', 'Finanční úřad pro Pardubický kraj',
         'Boženy Němcové 2625, 530 02 Pardubice', '95zn2bb'
  UNION ALL SELECT 'fu_460', 'Finanční úřad pro Kraj Vysočina',
         'Tolstého 2, 586 01 Jihlava', 'tqjn2cy'
  UNION ALL SELECT 'fu_461', 'Finanční úřad pro Jihomoravský kraj',
         'náměstí Svobody 4, 602 00 Brno', 'qdhny4c'
  UNION ALL SELECT 'fu_462', 'Finanční úřad pro Olomoucký kraj',
         'Lazecká 545/22, 779 00 Olomouc', '25nnz67'
  UNION ALL SELECT 'fu_463', 'Finanční úřad pro Moravskoslezský kraj',
         'Na Jízdárně 3162/3, 709 00 Ostrava', '4hun2cu'
  UNION ALL SELECT 'fu_464', 'Finanční úřad pro Zlínský kraj',
         'třída Tomáše Bati 21, 761 86 Zlín', 'n69nz5y'
  UNION ALL SELECT 'fu_13', 'Specializovaný finanční úřad',
         'nábřeží Kpt. Jaroše 1000/7, 170 00 Praha 7', '7cs8cge'
) canonical ON canonical.code = recipient.code
SET recipient.name = canonical.name,
    recipient.business_id = '72080043',
    recipient.address = canonical.address,
    recipient.kind = 'tax_office',
    recipient.isds_box_id = canonical.isds_box_id,
    recipient.source_url = 'https://financnisprava.gov.cz/assets/cs/prilohy/de-datove-schranky/Identifikacni_kody_FR_a_FU.pdf',
    recipient.source_note = 'Seznam ID kódů orgánů Finanční správy (stav 1. 7. 2023), ověřeno 2026-10-12; kód c_ufo z EPO číselníku ufo',
    recipient.is_active = 1
WHERE recipient.supplier_id IS NULL;

ALTER TABLE tax_submission_settings
  ADD COLUMN IF NOT EXISTS isds_recipient_vat_id BIGINT UNSIGNED NULL
    COMMENT 'Příjemce přiznání k DPH datovou schránkou; NULL = FÚ podle financial_office_code',
  ADD COLUMN IF NOT EXISTS isds_recipient_kh_id BIGINT UNSIGNED NULL
    COMMENT 'Příjemce kontrolního hlášení datovou schránkou; NULL = FÚ podle financial_office_code',
  ADD COLUMN IF NOT EXISTS isds_recipient_sh_id BIGINT UNSIGNED NULL
    COMMENT 'Příjemce souhrnného hlášení datovou schránkou; NULL = FÚ podle financial_office_code',
  ADD COLUMN IF NOT EXISTS isds_recipient_income_id BIGINT UNSIGNED NULL
    COMMENT 'Příjemce DPPO/DPFO datovou schránkou; NULL = FÚ podle financial_office_code';

ALTER TABLE tax_submission_settings
  ADD CONSTRAINT fk_tss_isds_vat FOREIGN KEY IF NOT EXISTS (isds_recipient_vat_id)
    REFERENCES submission_recipients (id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_tss_isds_kh FOREIGN KEY IF NOT EXISTS (isds_recipient_kh_id)
    REFERENCES submission_recipients (id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_tss_isds_sh FOREIGN KEY IF NOT EXISTS (isds_recipient_sh_id)
    REFERENCES submission_recipients (id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_tss_isds_income FOREIGN KEY IF NOT EXISTS (isds_recipient_income_id)
    REFERENCES submission_recipients (id) ON DELETE SET NULL;

ALTER TABLE tax_submission_artifacts
  MODIFY COLUMN artifact_kind ENUM(
    'source_xml','epo_xml','signed_submission_p7s','confirmation_p7s',
    'confirmation_xml','epo_echo',
    'confirmation_signer_cert','submission_signer_cert',
    'epo_error_xml','epo_status_xml','receipt_pdf','other',
    'isds_delivery_zfo','isds_response_zfo'
  ) NOT NULL;
