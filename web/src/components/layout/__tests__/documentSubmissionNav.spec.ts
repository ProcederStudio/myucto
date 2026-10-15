import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { computed, defineComponent, h, ref, type ComputedRef } from 'vue'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import ts from 'typescript'
import { canUsePurchaseAiExtraction } from '@/security/purchaseAiExtraction'

type NavSection = { key: string; items: { to: string; label: string }[] }
type Role = 'superadmin' | 'admin' | 'accountant' | 'client'

const profiles = {
  superadmin: { isSuperadmin: true, isClientRole: false, isCompanyAdminRole: true, isAccountantRole: false },
  admin: { isSuperadmin: false, isClientRole: false, isCompanyAdminRole: true, isAccountantRole: false },
  accountant: { isSuperadmin: false, isClientRole: false, isCompanyAdminRole: false, isAccountantRole: true },
  client: { isSuperadmin: false, isClientRole: true, isCompanyAdminRole: false, isAccountantRole: false },
}

const layout = readFileSync(resolve(process.cwd(), 'src/components/layout/AppLayout.vue'), 'utf8')
const source = ts.createSourceFile('AppLayout.ts', layout.slice(layout.indexOf('>') + 1, layout.indexOf('</script>')), ts.ScriptTarget.Latest, true)
const required = new Set(['navSections', 'accountantFirst', 'navPermission', 'filterNavigation', 'canExtractPurchase'])
const declarations = source.statements.filter(statement => {
  if (ts.isFunctionDeclaration(statement)) return required.has(statement.name?.text ?? '')
  return ts.isVariableStatement(statement) && statement.declarationList.declarations.some(declaration =>
    ts.isIdentifier(declaration.name) && required.has(declaration.name.text))
})
if (declarations.length !== required.size) throw new Error('AppLayout navigation declarations missing')
const navigationCode = ts.transpileModule(declarations.map(declaration => declaration.getText(source)).join('\n'), {
  compilerOptions: { target: ts.ScriptTarget.ESNext, module: ts.ModuleKind.None },
}).outputText

function renderNavigation(role: Role) {
  const profile = profiles[role]
  const can = (permission: string) => profile.isSuperadmin || (permission === 'documents.submit' ? profile.isClientRole : true)
  const auth = {
    ...profile,
    isAdminPlusRole: false, hasCommercialFeatures: true, hasPayrollFeatures: true,
    isManagedInstallation: false, isDemo: false, can, canRead: can, canWrite: can,
  }
  const evaluate = new Function('computed', 'auth', 'supplierStore', 'clientExperience', 't', 'ICONS',
    'approvalCount', 'accountantSigningProfilesEnabled', 'hostingAttention', 'automationStore', 'canUsePurchaseAiExtraction', `${navigationCode}\nreturn navSections;`)
  const sections = evaluate(computed, auth, { currentSupplier: { accounting_mode: 'double_entry', accounting_enabled: true } },
    ref(auth.isClientRole), (key: string) => key, new Proxy({}, { get: (_, key) => String(key) }),
    { pending: ref(0) }, ref(false), ref(null), { actionable: 0 }, canUsePurchaseAiExtraction) as ComputedRef<NavSection[]>
  return mount(defineComponent({
    setup: () => () => h('nav', sections.value.map(section => h('section', { 'data-section': section.key },
      section.items.map(item => h('a', { href: item.to }, item.label))))),
  }))
}

describe('AppLayout document submission navigation', () => {
  it.each<Role>(['superadmin', 'client'])('%s sees one document submission link', role => {
    const wrapper = renderNavigation(role)
    expect(wrapper.findAll('a[href="/portal/purchase-invoice-submissions"]')).toHaveLength(1)
    expect(wrapper.get('[data-section="documents"] a[href="/portal/purchase-invoice-submissions"]').text()).toBe('nav.submit_documents')
  })

  it.each<Role>(['admin', 'accountant'])('%s keeps the staff navigation without client document submission', role => {
    const wrapper = renderNavigation(role)
    expect(wrapper.findAll('a[href="/portal/purchase-invoice-submissions"]')).toHaveLength(0)
    expect(wrapper.find('a[href="/purchase-invoices/incoming"]').exists()).toBe(true)
  })
})
