import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
import type { DimensionType, DimensionValue } from '@/api/dimensions'
import type { JointVersion } from '@/api/jointAllocations'
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, locale: { value: 'en' } }) }))
import JointAllocationKeyEditor from '../JointAllocationKeyEditor.vue'
import DateInput from '@/components/ui/DateInput.vue'

describe('Immutable joint key editor', () => {
  it('requires explicit system choices and copies a complete reactive version into a new key', async () => {
    const types = [
      { id: 1, name: 'Activity', system_key: 'activity', is_active: true },
      { id: 2, name: 'Kind', system_key: 'activity_kind', is_active: true },
      { id: 3, name: 'Tax', system_key: 'tax_regime', is_active: true },
      { id: 9, name: 'Rooms', system_key: null, is_active: true },
    ] as DimensionType[]
    const values = [{ id: 11, type_id: 1, name: 'Synthetic main', is_active: true }, { id: 21, type_id: 2, name: 'Synthetic sport', is_active: true },
      { id: 31, type_id: 3, name: 'Synthetic tax', is_active: true }, { id: 91, type_id: 9, name: 'Synthetic room', is_active: true }] as DimensionValue[]
    const original = reactive({ id: 5, key_id: 2, name: 'Synthetic original', code: 'SOURCE', version_no: 1,
      valid_from: '2096-01-01', valid_to: '2097-01-01', basis: 'fixed', explanation: 'Synthetic evidence', allocation_mode: 'explicit_amounts', cell_ids: [],
      cells: [{ activity: 11, activity_kind: 21, tax_regime: 31, weight: '1', additional_dimensions: { 9: 91 } }] }) as JointVersion
    const wrapper = mount(JointAllocationKeyEditor, { props: { types, values, versions: [original], busy: false }, global: { stubs: { DateInput: true } } })
    const axisSelects = wrapper.findAll('select').slice(-3)
    expect(axisSelects.map(select => (select.element as HTMLSelectElement).value)).toEqual(['0', '0', '0'])
    wrapper.vm.copy(original)
    await wrapper.vm.$nextTick()
    await wrapper.find('input[pattern]').setValue('COPY')
    await wrapper.find('form').trigger('submit')
    const saved = wrapper.emitted('save')![0]![0] as { keyId: number; code: string; version: JointVersion }
    expect(saved.keyId).toBe(0)
    expect(saved.code).toBe('COPY')
    expect(saved.version.cells).toEqual(original.cells)
    expect(saved.version.allocation_mode).toBe('explicit_amounts')
    saved.version.cells[0]!.additional_dimensions![9] = 92
    expect(original.cells[0]!.additional_dimensions![9]).toBe(91)
  })
})


describe('Native key form validity', () => {
  it('does not emit a saved version with an invalid visible date', async () => {
    const wrapper = mount(JointAllocationKeyEditor, { props: { types: [], values: [], versions: [], busy: false } })
    await wrapper.find('input[pattern]').setValue('COPY')
    await wrapper.find('input[maxlength="190"]').setValue('Synthetic key')
    await wrapper.find('textarea').setValue('Synthetic documented basis')
    const dates = wrapper.findAllComponents(DateInput)
    await dates[0]!.find('input[type="text"]').setValue('01/01/2096')
    const date = dates[1]!.find('input[type="text"]')
    await date.setValue('01/01/2097'); await date.trigger('blur')
    await date.setValue('invalid'); await date.trigger('blur')
    expect((date.element as HTMLInputElement).validity.valid).toBe(false)
    await wrapper.find('form').trigger('submit')
    expect(wrapper.emitted('save')).toBeUndefined()
    wrapper.unmount()
  })
})
