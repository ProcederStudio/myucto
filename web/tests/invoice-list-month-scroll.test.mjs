import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import test from 'node:test'

// Po měsících nemá desktop vlastní scroll seznamu. Cíl další stránky proto nesmí být
// oříznutý třídou `pointer-fine-hidden`, kterou useScrollLoadMore na desktopu ignoruje.
for (const page of ['invoices/InvoiceList.vue', 'purchase-invoices/InvoiceList.vue']) {
  test(`${page}: po měsících načítá další stránku při scrollu i na desktopu`, () => {
    const source = readFileSync(join(import.meta.dirname, '../src/pages', page), 'utf8')
    const target = source.match(/<div[^>]*ref="loadMoreTarget"[^>]*>/)?.[0]
    assert.ok(target, 'cíl další stránky chybí')
    assert.doesNotMatch(target, /(?<!:)\bclass="[^"]*pointer-fine-hidden/)
    assert.match(target, /:class="groupByMonth \? '' : 'pointer-fine-hidden'"/)
  })
}
