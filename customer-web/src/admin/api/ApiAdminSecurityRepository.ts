/**
 * Security overview from the backend (Module 21 security events): GET /admin/security/summary and
 * GET /admin/security-events. Read-only. The backend returns no code, password, token, phone number or e-mail;
 * an event names the account by its display name when the account is known.
 */
import { api } from '../../api/client'
import type { Page } from '../types'

export type BackendSecurityEventType = 'OTP_REQUESTED' | 'OTP_FAILED' | 'OTP_VERIFIED' | 'LOGIN_SUCCESS' | 'LOGIN_FAILED' | 'LOGOUT' | 'SESSION_REVOKED' | 'PASSWORD_RESET_REQUESTED' | 'PASSWORD_CHANGED' | 'ACCOUNT_STATUS_CHANGED' | 'PERMISSION_CHANGED' | 'MFA_CHALLENGE_FAILED' | 'MFA_ENABLED' | 'MFA_DISABLED'
export type BackendSecurityEvent = {
  id: string; event: BackendSecurityEventType; severity: 'low' | 'medium' | 'high'; at: string
  accountType: 'customer' | 'restaurant_user' | 'admin_user' | null; accountName: string | null
  /** false = the attempt named an identifier that matched no account. */
  knownAccount: boolean
  ip: string | null; userAgent: string | null; requestId: string | null; details: Record<string, unknown>
}
export type BackendSecuritySummary = {
  failedAdminSignIns24h: number; failedSignIns24h: number; failedCodes24h: number; permissionChanges7d: number; accountStatusChanges7d: number
  blockedAccounts: { admin: number; restaurant: number; customer: number }; adminMfa: { enrolled: number; total: number }
  alerts: Array<{ kind: 'repeated_failures'; ip: string; failures: number; windowMinutes: number; lastAt: string }>; generatedAt: string
}
export type BackendSecurityFilter = { event?: string | 'all'; accountType?: string | 'all'; failedOnly?: boolean; from?: string; to?: string; page?: number; pageSize?: number }
export interface BackendSecurityRepository { summary(): Promise<BackendSecuritySummary>; events(f: BackendSecurityFilter): Promise<Page<BackendSecurityEvent>>; eventTypes(): string[] }

type EventDto = { id: string; event: BackendSecurityEventType; severity: 'low' | 'medium' | 'high'; principal_type: BackendSecurityEvent['accountType']; principal_name: string | null; known_account: boolean; ip: string | null; user_agent: string | null; request_id: string | null; details: Record<string, unknown>; occurred_at: string }
type PageDto = { data: EventDto[]; meta: { total: number; current_page: number }; facets: { events: string[] } }
type SummaryDto = { failed_admin_sign_ins_24h: number; failed_sign_ins_24h: number; failed_codes_24h: number; permission_changes_7d: number; account_status_changes_7d: number; blocked_accounts: { admin: number; restaurant: number; customer: number }; admin_mfa: { enrolled: number; total: number }; alerts: Array<{ kind: 'repeated_failures'; ip: string; failures: number; window_minutes: number; last_at: string }>; generated_at: string }

export const toSecurityEvent = (d: EventDto): BackendSecurityEvent => ({ id: d.id, event: d.event, severity: d.severity, at: d.occurred_at, accountType: d.principal_type, accountName: d.principal_name, knownAccount: d.known_account, ip: d.ip, userAgent: d.user_agent, requestId: d.request_id, details: d.details ?? {} })

export class ApiAdminSecurityRepository implements BackendSecurityRepository {
  private types: string[] = []

  async summary(): Promise<BackendSecuritySummary> {
    const d = await api<SummaryDto>('/admin/security/summary', { context: 'admin' })
    return {
      failedAdminSignIns24h: d.failed_admin_sign_ins_24h, failedSignIns24h: d.failed_sign_ins_24h, failedCodes24h: d.failed_codes_24h, permissionChanges7d: d.permission_changes_7d, accountStatusChanges7d: d.account_status_changes_7d,
      blockedAccounts: d.blocked_accounts, adminMfa: d.admin_mfa, alerts: d.alerts.map((a) => ({ kind: a.kind, ip: a.ip, failures: a.failures, windowMinutes: a.window_minutes, lastAt: a.last_at })), generatedAt: d.generated_at,
    }
  }
  async events(f: BackendSecurityFilter): Promise<Page<BackendSecurityEvent>> {
    const pageSize = f.pageSize ?? 15
    const d = await api<PageDto>('/admin/security-events', { context: 'admin', query: {
      'filter[event]': f.event && f.event !== 'all' ? f.event : undefined, 'filter[principal_type]': f.accountType && f.accountType !== 'all' ? f.accountType : undefined,
      outcome: f.failedOnly ? 'failed' : undefined, from: f.from || undefined, to: f.to || undefined, 'page[number]': f.page ?? 1, 'page[size]': pageSize,
    } })
    this.types = d.facets.events
    return { items: d.data.map(toSecurityEvent), total: d.meta.total, page: d.meta.current_page, pageSize }
  }
  eventTypes() { return this.types }
}
