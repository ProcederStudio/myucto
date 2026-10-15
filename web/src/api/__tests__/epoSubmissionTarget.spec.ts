import { describe, expect, it, vi } from 'vitest'

const { get } = vi.hoisted(() => ({ get: vi.fn().mockResolvedValue({ data: { data: [] } }) }))
vi.mock('../client', () => ({ api: { get } }))
import { epoSubmissionsApi } from '../epoSubmissions'

describe('odkaz na archivované podání', () => {
  it('předá přesný identifikátor serverovému filtru', async () => {
    await epoSubmissionsApi.list({ submission_id: 990, scope: 'tax', limit: 50 })
    expect(get).toHaveBeenLastCalledWith('/reports/submissions', {
      params: expect.objectContaining({ submission_id: 990, scope: 'tax', limit: 50 }),
    })
  })
})
