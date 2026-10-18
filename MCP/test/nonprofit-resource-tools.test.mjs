import assert from 'node:assert/strict';
import test from 'node:test';
import { NONPROFIT_RESOURCE_TOOLS } from '../src/nonprofit-resource-tools.mjs';
import { TOOLS_BY_NAME } from '../src/tools.mjs';
import { validateToolArguments } from '../src/tool-args.mjs';

for (const candidate of NONPROFIT_RESOURCE_TOOLS) {
  test(`${candidate.name}: přesná registrace a argumenty, jen čtení`, async () => {
    const tool = TOOLS_BY_NAME.get(candidate.name);
    assert.ok(tool);
    assert.equal(tool.write, false);
    assert.equal(tool.inputSchema.additionalProperties, false);
    const args = tool.inputSchema.required.includes('id') ? { id: 7 } : { after_id: 0, limit: 1 };
    if (candidate.name.endsWith('_events')) Object.assign(args, { after_id: 2, limit: 1 });
    assert.deepEqual(validateToolArguments(tool, args), args);
    let request;
    const result = { items: [{ available_cents: null }], coverage: { complete: false, pending_coverage: 'not_assessed' }, financial_authorization: false };
    assert.equal(await tool.run({ get: async (path, query, name) => { request = { path, query, name }; return result; } }, args, candidate.name), result);
    const kind = candidate.name.includes('funding') ? 'funding' : 'capital';
    const suffix = candidate.name.endsWith('_events') ? '/7/events' : args.id ? '/7' : '';
    const query = args.after_id === undefined ? {} : { after_id: args.after_id, limit: args.limit };
    assert.deepEqual(request, { path: `/accounting/catalog/nonprofit-${kind}${suffix}`, query, name: candidate.name });
    for (const extra of [{ supplier_id: 9 }, { date: '2026-01-01' }, { known_at: '2026-01-01T00:00:00Z' }, { payload: {} }, { confirm: true }]) assert.throws(() => validateToolArguments(tool, { ...args, ...extra }));
    if (args.id) assert.throws(() => validateToolArguments(tool, { ...args, id: 0 }));
    else for (const extra of [{ limit: 101 }, { after_id: -1 }]) assert.throws(() => validateToolArguments(tool, { ...args, ...extra }));
  });
}
