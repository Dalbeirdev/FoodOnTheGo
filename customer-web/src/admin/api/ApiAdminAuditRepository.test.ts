/** Backend audit trail adapter. The network is stubbed with the payload GET /admin/audit-events returns (OpenAPI: AuditEventPage). */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../../api/client'
import { ApiAdminAuditRepository, toAuditEvent } from './ApiAdminAuditRepository'

const EVENT = { id: 'e-1', action: 'city.updated', actor_type: 'admin_user', actor_id: 'a-1', actor_name: 'Alex Morgan', target_type: 'cities', target_id: 'c-1', target_label: 'Jaipur', reason: 'Highway closure on NH48', changes: { status: { from: 'ACTIVE', to: 'PAUSED' }, launched_at: { from: null, to: '2026-09-10T00:00:00+00:00' } }, request_id: 'req-123', occurred_at: '2026-10-01T08:00:00+00:00' }
let urls: string[] = []; let auth: Array<string | null> = []
beforeEach(() => {
  urls = []; auth = []; tokens.set('admin', 'admin-token')
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    urls.push(decodeURIComponent(url.replace('http://127.0.0.1:8001/api/v1', ''))); auth.push((init.headers as Record<string, string>).Authorization ?? null)
    return new Response(JSON.stringify({ data: [EVENT, { ...EVENT, id: 'e-2', action: 'service_area.updated', actor_name: null, target_label: null, target_type: 'service_areas', target_id: 'sa-9', reason: null, changes: { geometry: { from: 'replaced', to: { type: 'Polygon', positions: 5 } } } }], links: {}, meta: { total: 42, current_page: 2, per_page: 15 }, facets: { actions: ['city.updated', 'service_area.updated'], target_types: ['cities', 'service_areas'] } }), { status: 200, headers: { 'Content-Type': 'application/json' } })
  }))
})
afterEach(() => { vi.unstubAllGlobals(); tokens.set('admin', null) })

describe('ApiAdminAuditRepository', () => {
  it('maps a backend event onto the audit row the screen shows', () => {
    expect(toAuditEvent(EVENT)).toEqual({
      id: 'e-1', at: '2026-10-01T08:00:00+00:00', actor: 'Alex Morgan', actorRole: 'Administrator', action: 'city.updated', targetType: 'cities', targetRef: 'Jaipur',
      description: 'status: ACTIVE → PAUSED; launched_at: — → 2026-09-10T00:00:00+00:00', result: 'SUCCESS', ip: null,
      before: { status: 'ACTIVE', launched_at: null }, after: { status: 'PAUSED', launched_at: '2026-09-10T00:00:00+00:00' }, reason: 'Highway closure on NH48', requestId: 'req-123',
    })
  })

  it('falls back to the ids when the account or the record no longer exists; object values are shown as JSON', async () => {
    const page = await new ApiAdminAuditRepository().list({})
    expect(page.items[1]).toMatchObject({ actor: 'a-1', targetRef: 'sa-9', reason: null, description: 'geometry: replaced → {"type":"Polygon","positions":5}' })
    expect(toAuditEvent({ ...EVENT, actor_type: null, actor_id: null, actor_name: null })).toMatchObject({ actor: 'System', actorRole: 'System' })
  })

  it('sends the filters as query parameters with the admin token, and exposes paging and the filter choices', async () => {
    const repo = new ApiAdminAuditRepository()
    const page = await repo.list({ query: '  flooding ', action: 'city.updated', targetType: 'cities', result: 'DENIED', from: '2026-09-01', to: '2026-09-30', page: 2, pageSize: 15 })
    expect(urls[0]).toBe('/admin/audit-events?q=flooding&from=2026-09-01&to=2026-09-30&filter[action]=city.updated&filter[target_type]=cities&page[number]=2&page[size]=15')
    expect(auth[0]).toBe('Bearer admin-token')
    expect(page).toMatchObject({ total: 42, page: 2, pageSize: 15 }); expect(page.items.length).toBe(2)
    expect(repo.actions()).toEqual(['city.updated', 'service_area.updated']); expect(repo.targetTypes()).toEqual(['cities', 'service_areas'])
    expect((await repo.get('e-2'))?.action).toBe('service_area.updated'); expect(await repo.get('nope')).toBeNull()

    await repo.list({ action: 'all', targetType: 'all', query: '' })
    expect(urls[1]).toBe('/admin/audit-events?page[number]=1&page[size]=15')
  })
})
