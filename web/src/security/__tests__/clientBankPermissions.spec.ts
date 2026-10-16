import { describe, expect, it } from 'vitest'
import { CLIENT_BANK_ACTION_KEYS, isClientPermission, setRolePermission, type PermissionValue } from '../permissions'

describe('client bank role permissions', () => {
  it('allows the four limited bank permissions in client roles', () => {
    for (const key of ['bank', ...CLIENT_BANK_ACTION_KEYS] as const) expect(isClientPermission(key)).toBe(true)
    for (const key of ['bank.post', 'bank.unpost', 'bank.rules', 'settings.bank_accounts'] as const) expect(isClientPermission(key)).toBe(false)
  })

  it.each(CLIENT_BANK_ACTION_KEYS)('enables read when assigning %s and clears all actions when read is removed', key => {
    const permissions: Record<string, PermissionValue> = {}
    setRolePermission(permissions, 'client', key, 2)
    expect(permissions).toMatchObject({ bank: 1, [key]: 2 })
    setRolePermission(permissions, 'client', 'bank', 0)
    expect(permissions.bank).toBe(0)
    for (const action of CLIENT_BANK_ACTION_KEYS) expect(permissions[action]).toBe(0)
  })

  it('limits clients to bank read without restricting staff bank writes', () => {
    const permissions: Record<string, PermissionValue> = {}
    setRolePermission(permissions, 'client', 'bank', 2)
    expect(permissions.bank).toBe(1)
    setRolePermission(permissions, 'staff', 'bank', 2)
    expect(permissions.bank).toBe(2)
  })
})
