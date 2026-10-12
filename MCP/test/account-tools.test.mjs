import assert from 'node:assert/strict';
import test from 'node:test';

import { TOOLS_BY_NAME } from '../src/tools.mjs';

class FakeClient {
  constructor() {
    this.calls = [];
  }

  async get(path, query, tool) {
    this.calls.push({ method: 'GET', path, query, tool });
    return { ok: true };
  }

  async post(path, body, tool) {
    this.calls.push({ method: 'POST', path, body, tool });
    return { ok: true };
  }

  async patch(path, body, tool) {
    this.calls.push({ method: 'PATCH', path, body, tool });
    return { ok: true };
  }

  async del(path, tool) {
    this.calls.push({ method: 'DELETE', path, tool });
    return { ok: true };
  }
}

const tool = (name) => {
  const found = TOOLS_BY_NAME.get(name);
  assert.ok(found, `Nástroj ${name} musí existovat.`);
  return found;
};

test('nástroje osnovy jsou zápisové a mazání účtu nenabízejí', () => {
  for (const name of ['create_analytic_account', 'update_analytic_account']) {
    const entry = tool(name);
    assert.equal(entry.write, true, name);
    assert.notEqual(entry.destructive, true, name);
    assert.equal(entry.inputSchema.additionalProperties, false, name);
    for (const required of entry.inputSchema.required) {
      assert.ok(Object.hasOwn(entry.inputSchema.properties, required), `${name}.${required}`);
    }
  }
  assert.equal(tool('chart_of_accounts').write, false);
  assert.equal(TOOLS_BY_NAME.has('delete_account'), false);
  assert.equal(TOOLS_BY_NAME.has('delete_analytic_account'), false);
});

test('založení analytiky odvodí syntetiku z čísla účtu', async () => {
  const cases = [
    [{ account_code: '378.800', name: 'Zápůjčka' }, '378'],
    [{ account_code: '378810', name: 'Úroky' }, '378'],
    [{ account_code: '378.800', name: 'Zápůjčka', parent_code: '379' }, '379'],
  ];
  for (const [args, parent] of cases) {
    const client = new FakeClient();
    await tool('create_analytic_account').run(client, args, 'create_analytic_account');
    assert.deepEqual(client.calls, [{
      method: 'POST',
      path: '/accounting/accounts',
      body: { parent_code: parent, account_code: args.account_code, name: args.name },
      tool: 'create_analytic_account',
    }]);
  }
});

test('založení analytiky odmítne neplatný vstup ještě před zápisem', async () => {
  const client = new FakeClient();
  const run = (args) => tool('create_analytic_account').run(client, args, 'x');
  await assert.rejects(run({ account_code: '37', name: 'Krátké' }), /3 až 10 znaků/);
  await assert.rejects(run({ account_code: '378.8/0', name: 'Lomítko' }), /3 až 10 znaků/);
  await assert.rejects(run({ account_code: '378.800', name: '   ' }), /název účtu/);
  await assert.rejects(run({ account_code: 'ABC', name: 'Bez rodiče' }), /parent_code/);
  assert.equal(client.calls.length, 0);
});

test('úprava analytiky pošle PATCH jen se změněnými poli', async () => {
  const client = new FakeClient();
  await tool('update_analytic_account').run(client, { id: 42, is_active: false }, 'update_analytic_account');
  await tool('update_analytic_account').run(client, { id: 42, name: 'Zápůjčka společníka' }, 'update_analytic_account');
  assert.deepEqual(client.calls, [
    { method: 'PATCH', path: '/accounting/accounts/42', body: { is_active: false }, tool: 'update_analytic_account' },
    { method: 'PATCH', path: '/accounting/accounts/42', body: { name: 'Zápůjčka společníka' }, tool: 'update_analytic_account' },
  ]);

  const empty = new FakeClient();
  await assert.rejects(tool('update_analytic_account').run(empty, { id: 42 }, 'u'), /není co měnit/);
  assert.equal(empty.calls.length, 0);
});
