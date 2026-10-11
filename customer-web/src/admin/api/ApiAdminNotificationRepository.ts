import { api } from '../../api/client'
import { MockAdminNotificationRepository } from '../mock/mockAdmin'
import type { AdminNotification, AdminNotificationDetail, AdminNotificationRepository, Announcement, NotificationDeliveryRow, NotificationRegistryType, NotificationTemplate, Page } from '../types'

/**
 * Notifications for administrators, from the backend (Module 33): the delivery diagnostics (every customer notification
 * with its deliveries and attempts — never a token, an address or a phone), the registry (domain event → type →
 * channels → template version), a controlled resend of a failed delivery (audited on the server) and the system health.
 *
 * Operational alerts and announcements stay the development mock: campaigns and admin alerts are not part of Module 33.
 */
export type DeliveryDto = {
  id: string; channel: 'IN_APP' | 'PUSH' | 'EMAIL' | 'SMS'; provider: string; status: string; policy_result: string | null; attempt_count: number; next_retry_at: string | null
  last_error: { code: string; category: string | null } | null; provider_message_reference: string | null; sent_at: string | null; delivered_at: string | null; failed_at: string | null; created_at: string | null
  device: { id: string; platform: string; push_provider: string; active: boolean; revoked_at: string | null; revoke_reason: string | null; token_state: string } | null; retryable: boolean
  attempts?: Array<{ number: number; result: string; provider_code: string | null; error_category: string | null; safe_error: string | null; latency_ms: number | null; payload_bytes: number | null; started_at: string | null; finished_at: string | null }>
}
export type AdminNotificationDto = {
  id: string; category: string; type: string; priority: 'NORMAL' | 'HIGH'; transactional: boolean; title: string; body: string
  action: { target: string; reference: string | null }; subject: { type: string | null; id: string | null; reference: string | null }; read: boolean; read_at: string | null; created_at: string | null
  template: { key: string; version: number; locale: string }; dedupe_key: string; expires_at: string | null; customer: { id: string; display_name: string } | null
  delivery_summary: Record<string, Record<string, number>>; deliveries?: DeliveryDto[]
}
type PageDto<T> = { data: T[]; meta: { current_page: number; per_page: number; total: number } }

export const toDeliveryRow = (d: DeliveryDto, n?: { id: string; type: string; title: string }): NotificationDeliveryRow => ({
  id: d.id, channel: d.channel, provider: d.provider, status: d.status, policyResult: d.policy_result, attemptCount: d.attempt_count, nextRetryAt: d.next_retry_at,
  lastError: d.last_error ? { code: d.last_error.code, category: d.last_error.category } : null, providerReference: d.provider_message_reference,
  sentAt: d.sent_at, failedAt: d.failed_at, createdAt: d.created_at, retryable: d.retryable,
  device: d.device ? { id: d.device.id, platform: d.device.platform, provider: d.device.push_provider, active: d.device.active, tokenState: d.device.token_state, revokeReason: d.device.revoke_reason } : null,
  attempts: (d.attempts ?? []).map((a) => ({ number: a.number, result: a.result, providerCode: a.provider_code, errorCategory: a.error_category, safeError: a.safe_error, latencyMs: a.latency_ms, payloadBytes: a.payload_bytes, finishedAt: a.finished_at })),
  notification: n ?? null,
})
export const toAdminNotification = (d: AdminNotificationDto): AdminNotificationDetail => ({
  id: d.id, category: d.category, type: d.type, priority: d.priority, transactional: d.transactional, title: d.title, body: d.body,
  action: d.action, subject: d.subject, read: d.read, createdAt: d.created_at, template: d.template, dedupeKey: d.dedupe_key, expiresAt: d.expires_at,
  customer: d.customer, deliverySummary: d.delivery_summary, deliveries: (d.deliveries ?? []).map((x) => toDeliveryRow(x, { id: d.id, type: d.type, title: d.title })),
})

export class ApiAdminNotificationRepository implements AdminNotificationRepository {
  private readonly dev = new MockAdminNotificationRepository()
  readonly deliveriesAreBackend = true

  alerts(): Promise<AdminNotification[]> { return this.dev.alerts() }
  markRead(id: string): Promise<void> { return this.dev.markRead(id) }
  markAllRead(): Promise<void> { return this.dev.markAllRead() }
  templates(): Promise<NotificationTemplate[]> { return this.dev.templates() }
  announcements(): Promise<Announcement[]> { return this.dev.announcements() }
  saveAnnouncement(a: Omit<Announcement, 'id' | 'createdAt'> & { id?: string }, actor: string): Promise<Announcement> { return this.dev.saveAnnouncement(a, actor) }

  async notifications(f: { q?: string; status?: string; channel?: string; category?: string; type?: string; page?: number; pageSize?: number }): Promise<Page<AdminNotificationDetail>> {
    const qs = new URLSearchParams()
    if (f.q) qs.set('q', f.q)
    if (f.status && f.status !== 'all') qs.set('status', f.status)
    if (f.channel && f.channel !== 'all') qs.set('channel', f.channel)
    if (f.category && f.category !== 'all') qs.set('category', f.category)
    if (f.type) qs.set('type', f.type)
    qs.set('page', String(f.page ?? 1)); qs.set('per_page', String(f.pageSize ?? 25))
    const r = await api<PageDto<AdminNotificationDto>>(`/admin/notifications?${qs}`, { context: 'admin' })
    return { items: r.data.map(toAdminNotification), total: r.meta.total, page: r.meta.current_page, pageSize: r.meta.per_page }
  }
  async notification(id: string): Promise<AdminNotificationDetail> {
    const r = await api<{ notification: AdminNotificationDto }>(`/admin/notifications/${encodeURIComponent(id)}`, { context: 'admin' })
    return toAdminNotification(r.notification)
  }
  async deliveries(f: { status?: string; channel?: string; page?: number; pageSize?: number }): Promise<Page<NotificationDeliveryRow>> {
    const qs = new URLSearchParams()
    if (f.status && f.status !== 'all') qs.set('status', f.status)
    if (f.channel && f.channel !== 'all') qs.set('channel', f.channel)
    qs.set('page', String(f.page ?? 1)); qs.set('per_page', String(f.pageSize ?? 25))
    const r = await api<PageDto<DeliveryDto & { notification: { id: string; type: string; title: string } }>>(`/admin/notifications/deliveries?${qs}`, { context: 'admin' })
    return { items: r.data.map((d) => toDeliveryRow(d, d.notification)), total: r.meta.total, page: r.meta.current_page, pageSize: r.meta.per_page }
  }
  async retryDelivery(id: string, reason: string): Promise<NotificationDeliveryRow> {
    const r = await api<{ delivery: DeliveryDto }>(`/admin/notifications/deliveries/${encodeURIComponent(id)}/retry`, { method: 'POST', body: { reason }, context: 'admin' })
    return toDeliveryRow(r.delivery)
  }
  async registry(): Promise<{ types: NotificationRegistryType[]; defaultLocale: string; variables: string[] }> {
    const r = await api<{ types: Array<{ type: string; category: string; subject: string; priority: string; target: string; transactional: boolean; channels: Record<string, string>; template_version: number; locales: string[]; events: string[] }>; default_locale: string; variables: string[] }>('/admin/notifications/templates', { context: 'admin' })
    return { types: r.types.map((x) => ({ type: x.type, category: x.category, priority: x.priority, target: x.target, transactional: x.transactional, channels: x.channels, templateVersion: x.template_version, locales: x.locales, events: x.events })), defaultLocale: r.default_locale, variables: r.variables }
  }
}
