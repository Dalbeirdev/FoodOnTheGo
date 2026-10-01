/**
 * Audit trail written by the backend (GET /admin/audit-events): who changed what, when and why, with the values
 * before and after. Today the backend audits market and geography changes; other admin areas are still mock and
 * keep their development log until their modules are built.
 *
 * Only changes that were made are recorded — a refused attempt is not an audit event — so every row here is a
 * success. The backend limits the list to the markets the administrator's audit permission covers.
 */
import { api } from '../../api/client'
import type { AdminAuditRepository, AuditEvent, AuditFilter, Page } from '../types'

type AuditEventDto = {
  id: string; action: string; actor_type: string | null; actor_id: string | null; actor_name: string | null
  target_type: string; target_id: string; target_label: string | null; reason: string | null
  changes: Record<string, { from?: unknown; to?: unknown }>; request_id: string | null; occurred_at: string
}
type AuditPageDto = { data: AuditEventDto[]; meta: { total: number; current_page: number; per_page: number }; facets: { actions: string[]; target_types: string[] } }

const ACTOR: Record<string, string> = { admin_user: 'Administrator', restaurant_user: 'Restaurant user', customer: 'Customer' }
const show = (v: unknown) => (v === null || v === undefined ? '—' : typeof v === 'object' ? JSON.stringify(v) : String(v))

export function toAuditEvent(d: AuditEventDto): AuditEvent {
  const fields = Object.entries(d.changes ?? {})
  return {
    id: d.id, at: d.occurred_at,
    actor: d.actor_name ?? d.actor_id ?? 'System', actorRole: d.actor_type ? ACTOR[d.actor_type] ?? d.actor_type : 'System',
    action: d.action, targetType: d.target_type, targetRef: d.target_label ?? d.target_id,
    description: fields.map(([k, v]) => `${k}: ${show(v.from)} → ${show(v.to)}`).join('; '),
    result: 'SUCCESS', ip: null,
    before: Object.fromEntries(fields.map(([k, v]) => [k, v.from ?? null])), after: Object.fromEntries(fields.map(([k, v]) => [k, v.to ?? null])),
    reason: d.reason, requestId: d.request_id,
  }
}

export class ApiAdminAuditRepository implements AdminAuditRepository {
  private facets = { actions: [] as string[], target_types: [] as string[] }
  private seen = new Map<string, AuditEvent>()

  async list(f: AuditFilter): Promise<Page<AuditEvent>> {
    const pageSize = f.pageSize ?? 15
    const dto = await api<AuditPageDto>('/admin/audit-events', { context: 'admin', query: {
      q: f.query?.trim() || undefined, from: f.from || undefined, to: f.to || undefined,
      'filter[action]': f.action && f.action !== 'all' ? f.action : undefined,
      'filter[target_type]': f.targetType && f.targetType !== 'all' ? f.targetType : undefined,
      'page[number]': f.page ?? 1, 'page[size]': pageSize,
    } })
    this.facets = dto.facets
    const items = dto.data.map(toAuditEvent)
    for (const e of items) this.seen.set(e.id, e)
    return { items, total: dto.meta.total, page: dto.meta.current_page, pageSize }
  }
  /** Events are opened from a list that was just loaded. */
  async get(id: string) { return this.seen.get(id) ?? null }
  actions() { return this.facets.actions }
  targetTypes() { return this.facets.target_types }
}
