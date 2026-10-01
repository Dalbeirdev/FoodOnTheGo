/** Backend security events adapter. The network is stubbed with the payloads of the real API (OpenAPI: SecurityEventPage, SecuritySummary). */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../../api/client'
import { ApiAdminSecurityRepository } from './ApiAdminSecurityRepository'

const EVENT = { id: '0a1b2c3d4e5f6a7b8c9d', event: 'LOGIN_FAILED', severity: 'medium', principal_type: 'admin_user', principal_name: 'Alex Morgan', known_account: true, ip: '203.0.113.7', user_agent: 'Chrome', request_id: 'req-1', details: { method: 'password', context: 'admin', reason: 'invalid_credentials' }, occurred_at: '2026-10-01T08:00:00+00:00' }
const SUMMARY = { failed_admin_sign_ins_24h: 2, failed_sign_ins_24h: 5, failed_codes_24h: 3, permission_changes_7d: 1, account_status_changes_7d: 0, blocked_accounts: { admin: 1, restaurant: 1, customer: 1 }, admin_mfa: { enrolled: 0, total: 6 }, alerts: [{ kind: 'repeated_failures', ip: '203.0.113.7', failures: 5, window_minutes: 60, last_at: '2026-10-01T08:00:00+00:00' }], generated_at: '2026-10-01T08:01:00+00:00' }
let urls: string[] = []; let auth: Array<string | null> = []
beforeEach(() => {
  urls = []; auth = []; tokens.set('admin', 'admin-token')
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const path = decodeURIComponent(url.replace('http://127.0.0.1:8001/api/v1', '')); urls.push(path); auth.push((init.headers as Record<string, string>).Authorization ?? null)
    const body = path.startsWith('/admin/security/summary') ? SUMMARY : { data: [EVENT, { ...EVENT, id: 'ffff', principal_type: null, principal_name: null, known_account: false }], links: {}, meta: { total: 31, current_page: 3 }, facets: { events: ['LOGIN_FAILED', 'LOGIN_SUCCESS'] } }
    return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } })
  }))
})
afterEach(() => { vi.unstubAllGlobals(); tokens.set('admin', null) })

describe('ApiAdminSecurityRepository', () => {
  it('reads the summary with the admin token', async () => {
    const s = await new ApiAdminSecurityRepository().summary()
    expect(urls).toEqual(['/admin/security/summary']); expect(auth).toEqual(['Bearer admin-token'])
    expect(s).toEqual({ failedAdminSignIns24h: 2, failedSignIns24h: 5, failedCodes24h: 3, permissionChanges7d: 1, accountStatusChanges7d: 0, blockedAccounts: { admin: 1, restaurant: 1, customer: 1 }, adminMfa: { enrolled: 0, total: 6 },
      alerts: [{ kind: 'repeated_failures', ip: '203.0.113.7', failures: 5, windowMinutes: 60, lastAt: '2026-10-01T08:00:00+00:00' }], generatedAt: '2026-10-01T08:01:00+00:00' })
  })

  it('lists events with the filters as query parameters and maps them', async () => {
    const repo = new ApiAdminSecurityRepository()
    const page = await repo.events({ event: 'LOGIN_FAILED', accountType: 'admin_user', failedOnly: true, from: '2026-09-01', to: '2026-09-30', page: 3 })
    expect(urls[0]).toBe('/admin/security-events?filter[event]=LOGIN_FAILED&filter[principal_type]=admin_user&outcome=failed&from=2026-09-01&to=2026-09-30&page[number]=3&page[size]=15')
    expect(page).toMatchObject({ total: 31, page: 3, pageSize: 15 })
    expect(page.items[0]).toEqual({ id: '0a1b2c3d4e5f6a7b8c9d', event: 'LOGIN_FAILED', severity: 'medium', at: '2026-10-01T08:00:00+00:00', accountType: 'admin_user', accountName: 'Alex Morgan', knownAccount: true, ip: '203.0.113.7', userAgent: 'Chrome', requestId: 'req-1', details: { method: 'password', context: 'admin', reason: 'invalid_credentials' } })
    expect(page.items[1]).toMatchObject({ accountType: null, accountName: null, knownAccount: false })
    expect(repo.eventTypes()).toEqual(['LOGIN_FAILED', 'LOGIN_SUCCESS'])

    await repo.events({ event: 'all', accountType: 'all' })
    expect(urls[1]).toBe('/admin/security-events?page[number]=1&page[size]=15')
  })
})
