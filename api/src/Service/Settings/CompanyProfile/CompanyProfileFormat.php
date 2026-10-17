<?php

declare(strict_types=1);

namespace MyInvoice\Service\Settings\CompanyProfile;

/**
 * Formát profilu firmy: ručně vybudované nastavení, které převod z jiného programu
 * nezaloží a smazání či nový převod firmy by ho ztratily.
 *
 * Profil je JSON s verzí formátu a sekcemi. Všechny odkazy jsou přirozené klíče (kód
 * účtu, kód dimenze, IČO klienta, kód verze výkazu), nikdy id z databáze, profil tak
 * jde nahrát do znovu založené firmy i do jiné instalace.
 *
 *   {
 *     "format": "myucto.company-profile",
 *     "version": 1,
 *     "exported_at": "2026-01-31T12:00:00+01:00",
 *     "app_version": "6.x.y",
 *     "company": {"name": "…", "ic": "…"},
 *     "sections": { "<sekce>": … }
 *   }
 *
 * Sekce a jejich sémantika při nahrání:
 *   - company, tax_profile, accounting_settings: přepíšou se jen uvedené volby,
 *   - statement_overrides: výjimky mapování: celá sada každé uvedené verze výkazu
 *     se nahradí sadou z profilu (jako „Uložit" v editoru výjimek),
 *   - dimensions, dimension_defaults, dimension_rules, posting_rules, bank_rule_templates,
 *     bank_posting_rules: doplní chybějící a upraví existující položky podle klíče;
 *     položky, které profil nezná (typicky založené převodem), zůstanou.
 *
 * Nová sekce je aditivní a verzi formátu nemění: starší aplikace ji při nahrání
 * přeskočí s upozorněním ({@see unknownSections()}).
 */
final class CompanyProfileFormat
{
    public const FORMAT = 'myucto.company-profile';
    public const VERSION = 1;

    /** Pořadí je i pořadí nahrání: dimenze před výchozími dimenzemi a pravidly dimenzí, předkontace před šablonami a pravidly banky. */
    public const SECTIONS = [
        'company',
        'tax_profile',
        'accounting_settings',
        'statement_overrides',
        'dimensions',
        'dimension_defaults',
        'dimension_rules',
        'posting_rules',
        'bank_rule_templates',
        'bank_posting_rules',
    ];

    /** Oprávnění, které sekce vyžaduje (export úroveň čtení, nahrání úroveň zápisu). */
    public const SECTION_PERMISSIONS = [
        'company' => 'settings.company.write',
        'tax_profile' => 'settings.company.write',
        'accounting_settings' => 'accounting',
        'statement_overrides' => 'accounting',
        'dimensions' => 'accounting',
        'dimension_defaults' => 'accounting',
        'dimension_rules' => 'accounting',
        'posting_rules' => 'accounting',
        'bank_rule_templates' => 'bank.rules',
        'bank_posting_rules' => 'accounting',
    ];

    /** Volby firmy (tabulka supplier), které převod nenastavuje. */
    public const COMPANY_COLUMNS = ['stock_enabled', 'stock_auto_issue', 'stock_in_transit_from', 'dimensions_enabled'];

    /** Údaje pro daňová přiznání (tabulka supplier), které z účetnictví odvodit nejde. */
    public const TAX_PROFILE_COLUMNS = [
        'epo_taxpayer_code', 'tax_entity_status', 'tax_entity_status_date', 'tax_accounting_decree',
        'tax_investment_incentive', 'tax_atad_cfc', 'tax_public_benefit', 'tax_cooperating_person',
        'tax_foreign_income_credit', 'financial_office_code', 'workplace_code', 'cz_nace_code',
        'sest_jmeno', 'sest_prijmeni', 'sest_telefon', 'sest_email', 'sest_funkce',
        'opr_jmeno', 'opr_prijmeni', 'opr_postaveni', 'person_title',
    ];

    /**
     * Účetní politiky a volby výkazů (accounting_supplier_settings). Bez zámku účtování
     * a automatiky: ty jsou stav firmy, ne nastavení, a převod je řídí sám.
     */
    public const ACCOUNTING_SETTINGS_COLUMNS = [
        'avg_employees', 'statement_scope_override', 'statutory_audit', 'manual_doc_series',
        'fx_reversal_at_open', 'fx_rate_mode', 'small_asset_accrual_mode', 'small_asset_accrual_pct',
        'net_turnover_extra_rows', 'comparative_from_prior_year', 'tax_authority_offset',
        'tax_authority_offset_from_year', 'single_analytic_redirect', 'fuel_account_code',
        'vehicle_repair_account_code',
    ];

    /**
     * Ověří obálku profilu a vrátí jeho sekce (jen známé, v pořadí nahrání).
     *
     * @param mixed $profile dekódovaný JSON
     * @param list<string>|null $only nahrát jen tyto sekce (null = všechny v profilu)
     * @return array<string,mixed> sekce => obsah
     */
    public static function sections(mixed $profile, ?array $only = null): array
    {
        if (!is_array($profile)) {
            throw new CompanyProfileException('invalid_profile', 'Soubor není profil firmy (očekává se objekt JSON).');
        }
        if (($profile['format'] ?? null) !== self::FORMAT) {
            throw new CompanyProfileException('invalid_profile', 'Soubor není profil firmy MyÚčta (chybí „format": "' . self::FORMAT . '").');
        }
        $version = $profile['version'] ?? null;
        if (!is_int($version) || $version < 1) {
            throw new CompanyProfileException('invalid_profile', 'Profil firmy nemá platnou verzi formátu.');
        }
        if ($version > self::VERSION) {
            throw new CompanyProfileException('unsupported_version', sprintf(
                'Profil má verzi formátu %d, tahle verze aplikace umí nejvýš %d. Aktualizujte aplikaci.',
                $version,
                self::VERSION,
            ));
        }
        $sections = $profile['sections'] ?? null;
        if (!is_array($sections)) {
            throw new CompanyProfileException('invalid_profile', 'Profil firmy nemá sekce.');
        }
        if ($only !== null) {
            foreach ($only as $name) {
                if (!in_array($name, self::SECTIONS, true)) {
                    throw new CompanyProfileException('invalid_section', 'Neznámá sekce profilu „' . $name . '".');
                }
            }
        }
        $out = [];
        foreach (self::SECTIONS as $name) {
            if (!array_key_exists($name, $sections) || ($only !== null && !in_array($name, $only, true))) {
                continue;
            }
            if (!is_array($sections[$name])) {
                throw new CompanyProfileException('invalid_profile', 'Sekce „' . $name . '" profilu musí být objekt nebo seznam.', $name);
            }
            $out[$name] = $sections[$name];
        }

        return $out;
    }

    /**
     * Sekce z profilu, které aplikace nezná (novější formát se stejným číslem verze
     * nebo ruční úprava). Nahrání je přeskočí a ohlásí.
     *
     * @return list<string>
     */
    public static function unknownSections(mixed $profile): array
    {
        $sections = is_array($profile) && is_array($profile['sections'] ?? null) ? $profile['sections'] : [];

        return array_values(array_diff(array_map('strval', array_keys($sections)), self::SECTIONS));
    }
}
