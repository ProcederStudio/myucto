import { beforeEach, describe, expect, it, vi } from 'vitest'
const request = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }))
vi.mock('../client', () => ({ api: request }))
import { settingsApi } from '../settings'

describe('signing API scope', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    Object.values(request).forEach(fn => fn.mockResolvedValue({ data: {} }))
  })
  it('keeps staff routes while routing client operations through the client boundary', async () => {
    await settingsApi.listSigningProfiles()
    expect(request.get).toHaveBeenLastCalledWith('/settings/signing/profiles')
    await settingsApi.listSigningProfiles(true)
    expect(request.get).toHaveBeenLastCalledWith('/settings/client/signing/profiles')
    await settingsApi.getSigningProfileCredential(12, true)
    expect(request.get).toHaveBeenLastCalledWith('/settings/client/signing/profiles/12/credentials/certificate')
    await settingsApi.getPdfSigningSettings(true)
    expect(request.get).toHaveBeenLastCalledWith('/settings/client/pdf-signing')
    await settingsApi.updatePdfSignatureOutputSettings([{ output_type: 'invoice', default_profile_id: 12 }], true)
    expect(request.put).toHaveBeenLastCalledWith('/settings/client/pdf-signing/output-settings', { settings: [{ output_type: 'invoice', default_profile_id: 12 }] })
    await settingsApi.testPdfSigning('invoice', true)
    expect(request.post).toHaveBeenLastCalledWith('/settings/client/pdf-signing/test', { output_type: 'invoice' })
    await settingsApi.deleteSigningProfile(12, true)
    expect(request.delete).toHaveBeenLastCalledWith('/settings/client/signing/profiles/12')
  })
  it('uploads the client certificate as encrypted storage without personal vault routes', async () => {
    const file = new File(['synthetic'], 'synthetic.p12')
    await settingsApi.uploadSigningProfileCredential(12, file, 'synthetic-passphrase', 'encrypted_store', null, true)
    const [url, data] = request.post.mock.calls[0]!
    expect(url).toBe('/settings/client/signing/profiles/12/credentials/certificate')
    expect(data.get('file')).toBe(file)
    expect(data.get('password')).toBe('synthetic-passphrase')
    expect(data.get('passphrase_policy')).toBe('encrypted_store')
    expect(data.has('passphrase_profile_id')).toBe(false)
  })
})
