import { describe, expect, it } from 'vitest'
import { CLIENT_STOCK_ACTION_KEYS, isClientPermission, setRolePermission, type PermissionValue } from '../permissions'

describe('client stock delegation', () => {
  it.each(CLIENT_STOCK_ACTION_KEYS)('requires stock read when enabling %s and clears it on revocation', key => {
    const permissions: Record<string, PermissionValue> = {}
    expect(isClientPermission(key)).toBe(true)
    setRolePermission(permissions, 'client', key, 2)
    expect(permissions.stock).toBe(1)
    expect(permissions[key]).toBe(2)
    setRolePermission(permissions, 'client', 'stock', 0)
    expect(permissions.stock).toBe(0)
    expect(CLIENT_STOCK_ACTION_KEYS.map(action => permissions[action])).toEqual([0, 0])
  })

  it('caps the client module grant at read and preserves staff write', () => {
    const permissions: Record<string, PermissionValue> = {}
    setRolePermission(permissions, 'client', 'stock', 2)
    expect(permissions.stock).toBe(1)
    setRolePermission(permissions, 'staff', 'stock', 2)
    expect(permissions.stock).toBe(2)
    expect(isClientPermission('stock.take')).toBe(false)
    expect(isClientPermission('stock.close')).toBe(false)
  })
})
