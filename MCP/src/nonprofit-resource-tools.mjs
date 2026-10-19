import { seg } from './tool-shared.mjs';
const id = { type: 'integer', minimum: 1 };
const page = { after_id: { type: 'integer', minimum: 0 }, limit: { type: 'integer', minimum: 1, maximum: 100 } };
const read = (name, title, description, properties, required, run) => ({
  name, title, description,
  inputSchema: { type: 'object', properties, required, additionalProperties: false },
  write: false, run,
});
const funding = 'Zaznamenané a hashovým řetězcem ověřené revize účelových zdrojů bez osobních a právních textů. ID odkazuje na první revizi zdroje; current_revision_id na poslední zaznamenanou revizi. Zachovejte null pro source_current, usage_count a částky použití. purpose_decision=unknown, usage_links=not_available a eligibility=not_assessed nejsou právní ani daňový závěr. Zaznamenané částky nejsou oprávnění k čerpání.';
const capital = 'Současné kanonicky ověřené zaznamenané zdroje 901/911 a jejich použití. Bez podkladů, osob a interního proof JSON. pending_coverage=not_assessed znamená neúplné pokrytí, nikoli nulové čekající operace. Zachovejte null, proof_status a findings; výsledek neopravňuje k finanční mutaci.';
export const NONPROFIT_RESOURCE_TOOLS = [
  read('list_nonprofit_funding', 'Účelové granty a dary', funding, page, [],
    (c, a, tool) => c.get('/accounting/catalog/nonprofit-funding', { after_id: a.after_id, limit: a.limit }, tool)),
  read('get_nonprofit_funding', 'Potvrzený účelový zdroj', funding, { id }, ['id'],
    (c, a, tool) => c.get(`/accounting/catalog/nonprofit-funding/${seg(a.id)}`, {}, tool)),
  read('list_nonprofit_capital', 'Investiční zdroje a účelové fondy', capital, page, [],
    (c, a, tool) => c.get('/accounting/catalog/nonprofit-capital', { after_id: a.after_id, limit: a.limit }, tool)),
  read('get_nonprofit_capital', 'Potvrzený kapitálový zdroj', capital, { id }, ['id'],
    (c, a, tool) => c.get(`/accounting/catalog/nonprofit-capital/${seg(a.id)}`, {}, tool)),
  read('list_nonprofit_capital_events', 'Použití a storna kapitálového zdroje', capital, { id, ...page }, ['id'],
    (c, a, tool) => c.get(`/accounting/catalog/nonprofit-capital/${seg(a.id)}/events`, { after_id: a.after_id, limit: a.limit }, tool)),
];
