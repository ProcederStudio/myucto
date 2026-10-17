import { seg } from './tool-shared.mjs';

const str = (description, extra = {}) => ({ type: 'string', description, ...extra });
const int = (description) => ({ type: 'integer', minimum: 1, description });
const bool = (description) => ({ type: 'boolean', description });
const date = (description) => str(description, { format: 'date' });
const schema = (properties = {}, required = []) => ({ type: 'object', properties, required, additionalProperties: false });
const flag = (value) => value === undefined ? undefined : value ? 1 : 0;
const scope = str('Jedna firma, nebo dostupné firmy ve skupině pro globální dimenzi.', { enum: ['company', 'group'] });
const range = { from: date('Počátek rozsahu.'), to: date('Konec rozsahu.') };

export const DIMENSION_FILTER = {
  dimension_value_id: int('ID hodnoty dimenze z `list_dimensions`; omezuje sestavu na tuto hodnotu.'),
  dimension_descendants: bool('Zahrnout podřízené hodnoty dimenze. Výchozí ano; false znamená jen přesnou hodnotu.'),
};

export const dimensionQuery = (args) => ({
  dimension_value_id: args.dimension_value_id,
  dimension_descendants: flag(args.dimension_descendants),
});

const read = (name, title, description, properties, required, run) => ({
  name, title, description, inputSchema: schema(properties, required), write: false, run,
});

export const DIMENSION_TOOLS = [
  read('nonprofit_tax_assessment', 'Daňové posouzení činností',
    'Účetní podklady 504 a poslední doložené posouzení. Jde o klasifikační most před obecnými úpravami základu daně, nikoli o výpočet nebo podání DPPO.',
    { period_id: int('ID účetního období.') }, ['period_id'],
    (c, a, tool) => c.get(`/accounting/periods/${seg(a.period_id)}/nonprofit-tax`, null, tool)),
  read('nonprofit_tax_assessment_history', 'Historie daňových posouzení',
    'Neměnné potvrzené a odvolané revize daňového posouzení po druzích činností.',
    { period_id: int('ID účetního období.') }, ['period_id'],
    (c, a, tool) => c.get(`/accounting/periods/${seg(a.period_id)}/nonprofit-tax/history`, null, tool)),
  read('export_nonprofit_tax_assessment', 'Podklady daňového posouzení',
    'Strukturovaný export konkrétní revize včetně původních podkladů, rozhodnutí a kontroly aktuálnosti. Historická revize není automaticky aktuálním potvrzením.',
    { assessment_id: int('ID revize z historie posouzení.') }, ['assessment_id'],
    (c, a, tool) => c.get(`/accounting/nonprofit-tax-assessments/${seg(a.assessment_id)}/export`, null, tool)),
  read('list_joint_allocation_keys', 'Klíče společných alokací',
    'Neměnné verze doložených klíčů, účinnost a společné kombinace dimenzí. Předběžné daňové zařazení není potvrzeným daňovým posouzením.',
    {}, [], (c, _a, tool) => c.get('/accounting/joint-allocations/keys', null, tool)),
  read('list_joint_allocation_candidates', 'Zdroje společné alokace',
    'Poradní výběr výsledkových řádků pro cílové datum. Pokračujte přes next_before_id i po prázdné stránce; zápis znovu ověřuje aktuální podklady.',
    { target_date: date('Datum přeúčtování do otevřeného období rámce 504.'), ...range,
      search: str('Text v čísle dokladu, popisu nebo účtu.'), before_id: int('Kurzor next_before_id z předchozí stránky.') },
    ['target_date'], (c, a, tool) => c.get('/accounting/joint-allocations/candidates',
      { target_date: a.target_date, from: a.from, to: a.to, search: a.search, before_id: a.before_id }, tool)),
  read('list_joint_allocations', 'Historie společných alokací',
    'Historie zaúčtovaných a stornovaných alokací. Stránka má nejvýše 100 položek; další stránku načtěte s before_id poslední položky.',
    { before_id: int('ID poslední položky předchozí stránky.') }, [],
    (c, a, tool) => c.get('/accounting/joint-allocations/batches', { before_id: a.before_id }, tool)),
  read('get_joint_allocation', 'Detail společné alokace',
    'Neměnné zdrojové a cílové řádky, částky v CZK i cizí měně, dokladové podklady, audit a případné storno.',
    { id: int('ID alokace z list_joint_allocations.') }, ['id'],
    (c, a, tool) => c.get(`/accounting/joint-allocations/batches/${seg(a.id)}`, null, tool)),
  read('list_dimensions', 'Dimenze a jejich hodnoty',
    'Typy a hierarchie dimenzí dostupné pro firmu: střediska, projekty, vozidla a vlastní dimenze. Vrací ID pro filtry sestav i informaci, zda jsou dimenze zapnuté.',
    {}, [], (c, _a, tool) => c.get('/accounting/dimensions', null, tool)),
  read('dimension_profit', 'Zisk podle dimenze',
    'Účetní výnosy, náklady a zisk podle hodnot zvoleného typu dimenze. U globálních dimenzí lze scope=group sečíst přes dostupné firmy skupiny. Vrací i nepřiřazené částky; nemění účetnictví.',
    { type_id: int('ID typu dimenze z `list_dimensions`.'), ...range, scope,
      value_id: int('Volitelná větev hierarchie dimenze.'), responsible_user_id: int('Omezit na odpovědnou osobu.'),
      accounts: bool('Přidat rozpad po účetních účtech.'), companies: bool('Přidat rozpad po firmách.') },
    ['type_id', 'from', 'to'], (c, a, tool) => c.get('/accounting/reports/dimension-profit', {
      type_id: a.type_id, from: a.from, to: a.to, scope: a.scope === 'group' ? 'group' : undefined,
      value_id: a.value_id, responsible_user_id: a.responsible_user_id, accounts: flag(a.accounts), companies: flag(a.companies),
    }, tool)),
  read('dimension_analytics', 'Roční statistika dimenzí',
    'Měsíční a kumulované výnosy, náklady a zisk podle dimenze, včetně srovnání s minulým rokem. Odpovídá stránce Statistiky dimenzí. scope=group zahrne dostupné firmy skupiny u globálního typu.',
    { type_id: int('ID typu dimenze.'), year: { type: 'integer', minimum: 2000, maximum: 2100, description: 'Rok.' }, scope },
    ['type_id', 'year'], (c, a, tool) => c.get('/accounting/reports/dimension-analytics', {
      type_id: a.type_id, year: a.year, supplier_id: a.scope === 'group' ? 'all' : undefined,
    }, tool)),
  read('dimension_cash_flow', 'Cash flow podle dimenze',
    'Manažerský peněžní tok nepřímou metodou z hospodářského výsledku a změn rozvahových účtů. Vrací také skutečný pohyb peněz, nepřiřazený rozdíl a kontrolu shody. scope=group u globální hodnoty zahrne dostupné firmy skupiny; bez hodnoty dimenze vrací pouze zvolenou firmu.',
    { ...range, ...DIMENSION_FILTER, scope }, ['from', 'to'], (c, a, tool) => c.get('/accounting/reports/dimension-cash-flow', {
      from: a.from, to: a.to, ...dimensionQuery(a), scope: a.scope === 'group' ? 'group' : undefined,
    }, tool)),
  read('statement_accounts', 'Rozvaha a výsledovka po účtech',
    'Rozvaha, výsledovka a hospodářský výsledek v členění po účtech. Lze omezit na hodnotu dimenze.',
    { period_id: int('ID účetního období.'), as_of: date('Stav k datu; výchozí dřívější z dneška a konce období.'), ...DIMENSION_FILTER },
    ['period_id'], (c, a, tool) => c.get('/accounting/reports/statement-accounts', {
      period_id: a.period_id, as_of: a.as_of, ...dimensionQuery(a),
    }, tool)),
  read('income_statement_by_function', 'Účelová výsledovka',
    'Výkaz zisku a ztráty v účelovém členění podle nastavení účetních účtů firmy. Podporuje filtr dimenze.',
    { period_id: int('ID účetního období.'), as_of: date('Stav k datu.'), ...DIMENSION_FILTER,
      scope: str('Rozsah výkazu.', { enum: ['auto', 'full', 'small', 'micro'] }) },
    ['period_id'], (c, a, tool) => c.get('/accounting/reports/income-statement-by-function', {
      period_id: a.period_id, as_of: a.as_of, scope: a.scope, ...dimensionQuery(a),
    }, tool)),
  read('get_document_dimensions', 'Dimenze dokladu', 'Přiřazené dimenze na hlavičce, položkách a rozdělení dokladu.',
    { document_type: str('Typ dokladu.', { enum: ['purchase-invoices', 'invoices', 'cash-documents', 'bank-transactions', 'journal-templates', 'other-items'] }), id: int('ID dokladu.') },
    ['document_type', 'id'], (c, a, tool) => c.get(`/accounting/dimensions/documents/${seg(a.document_type)}/${seg(a.id)}`, null, tool)),
  read('get_journal_dimensions', 'Dimenze účetního zápisu', 'Dimenze přiřazené jednotlivým řádkům účetního zápisu.',
    { id: int('ID účetního zápisu.') }, ['id'], (c, a, tool) => c.get(`/accounting/dimensions/journal/${seg(a.id)}`, null, tool)),
  read('get_client_dimensions', 'Výchozí dimenze odběratele', 'Výchozí hodnoty dimenzí na kartě odběratele.',
    { id: int('ID odběratele.') }, ['id'], (c, a, tool) => c.get(`/clients/${seg(a.id)}/dimensions`, null, tool)),
  read('get_project_dimensions', 'Výchozí dimenze zakázky', 'Výchozí hodnoty dimenzí na kartě zakázky.',
    { id: int('ID zakázky.') }, ['id'], (c, a, tool) => c.get(`/projects/${seg(a.id)}/dimensions`, null, tool)),
  read('list_dimension_rules', 'Pravidla dimenzí', 'Pravidla povinného vyplnění a předvyplnění dimenzí podle účtů.',
    {}, [], (c, _a, tool) => c.get('/accounting/dimensions/rules', null, tool)),
  ...[
    ['dimension_rule_audit', 'Chybějící povinné dimenze', 'Zaúčtované řádky porušující pravidla povinných dimenzí.', 'audit'],
    ['dimension_rule_coverage', 'Pokrytí účtů dimenzemi', 'Pokrytí zaúčtovaných řádků dimenzemi pro návrh pravidel.', 'coverage'],
  ].map(([name, title, description, endpoint]) => read(name, title, description,
    { date_from: date('Od data; výchozí začátek aktuálního roku.'), date_to: date('Do data; výchozí konec aktuálního roku.') }, [],
    (c, a, tool) => c.get(`/accounting/dimensions/rules/${endpoint}`, { date_from: a.date_from, date_to: a.date_to }, tool))),
];
