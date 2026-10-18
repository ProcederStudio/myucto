import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { TripTemplate } from '@/api/logbook'

const m = vi.hoisted(() => ({
  listTemplates: vi.fn(),
  createTemplate: vi.fn(),
  updateTemplate: vi.fn(),
  deleteTemplate: vi.fn(),
  listCars: vi.fn(),
  listCategories: vi.fn(),
  tripPurposes: vi.fn(),
  tripPlaces: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/logbook', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/logbook')>()),
  logbookApi: { ...m },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.toastSuccess, error: m.toastError }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

import TemplatesTab from '@/pages/logbook/TemplatesTab.vue'

const template: TripTemplate = {
  id: 7, car_id: null, car_registration: null, car_name: null, label: 'Domov → kancelář', origin: 'Domov',
  destination: 'Kancelář', purpose: 'Jednání', distance_km: 38.4, category_id: 1, category_label: 'Služební',
  category_is_private: false, time_start: '07:30', time_end: '08:10', display_order: 10, is_archived: false,
}

describe('TemplatesTab.vue', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.listTemplates.mockResolvedValue([template])
    m.listCars.mockResolvedValue([])
    m.listCategories.mockResolvedValue([{ id: 1, code: 'business', label: 'Služební', is_private: false, is_default: true, display_order: 10, is_archived: false }])
    m.tripPurposes.mockResolvedValue([])
    m.tripPlaces.mockResolvedValue([])
    m.createTemplate.mockResolvedValue({ ...template, id: 8 })
  })

  it('lists regular trips', async () => {
    const w = mount(TemplatesTab)
    await flushPromises()

    expect(w.text()).toContain('Domov → kancelář')
    expect(w.text()).toContain('logbook.tpl_any_car')
  })

  it('creates a regular trip with the label suggested from the route', async () => {
    const w = mount(TemplatesTab)
    await flushPromises()
    await w.findAll('button').find(b => b.text().includes('logbook.tpl_new'))!.trigger('click')

    const inputs = w.findAll('form input[type="text"]')
    await inputs[0].setValue('Domov')
    await inputs[0].trigger('blur')
    await inputs[1].setValue('Klient')
    await inputs[1].trigger('blur')
    await w.find('form input[type="number"]').setValue(12.5)
    await w.find('form').trigger('submit')
    await flushPromises()

    expect(m.createTemplate).toHaveBeenCalledWith(expect.objectContaining({
      label: 'Domov → Klient', origin: 'Domov', destination: 'Klient', distance_km: 12.5, category_id: 1, car_id: null,
    }))
    expect(m.listTemplates).toHaveBeenCalledTimes(2)
  })

  it('requires a distance', async () => {
    const w = mount(TemplatesTab)
    await flushPromises()
    await w.findAll('button').find(b => b.text().includes('logbook.tpl_new'))!.trigger('click')
    await w.findAll('form input[type="text"]')[2].setValue('Bez km')
    await w.find('form').trigger('submit')
    await flushPromises()

    expect(m.createTemplate).not.toHaveBeenCalled()
    expect(m.toastError).toHaveBeenCalledWith('logbook.tpl_required')
  })
})
