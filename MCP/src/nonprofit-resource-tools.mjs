import { seg } from './tool-shared.mjs';
const id = { type: 'integer', minimum: 1 };
const page = { after_id: { type: 'integer', minimum: 0 }, limit: { type: 'integer', minimum: 1, maximum: 100 } };
const read = (name, title, description, properties, required, run) => ({
  name, title, description,
  inputSchema: { type: 'object', properties, required, additionalProperties: false },
  write: false, run,
});
const funding = 'Současné potvrzené účelové zdroje bez osobních a právních textů. ID odkazuje na první potvrzenou revizi zdroje; current_revision_id na aktuální revizi. Zachovejte null, source_current, proof_status, pending_coverage a findings. Úplné použití ani čekající operace nejsou pokryté; částky nejsou oprávnění k čerpání.';
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
