/**
 * Restaurant-user and admin-user authentication against the backend (Module 21): e-mail + password, with an
 * authenticator-app code when the account has multi-factor authentication.
 *
 * The permission lists returned here only decide what the dashboards show. Every protected action is decided by
 * the backend again, whatever the screen offers.
 */
import { ApiError, api, tokens } from '../../api/client'
import type { AdminPermission, AdminRoleId } from '../../admin/types'
import type { Permission as RestaurantPermission, RoleId as RestaurantRoleId } from '../../dashboard/types'

export type StaffContext = 'restaurant' | 'admin'
export type StaffRole = { code: string; name: string; scope: { type: 'organization' | 'location' | 'market'; id: string } | null }
export type StaffPrincipal = { id: string; name: string; email: string; status: string; mfaEnabled: boolean; lastLoginAt: string | null; roles: StaffRole[]; permissions: string[] }

type PrincipalDto = { principal_type: string; id: string; name: string; email: string; status: string; mfa_enabled: boolean; last_login_at: string | null; roles: StaffRole[]; permissions: string[] }
type LoginDto = { mfa_required: boolean; mfa_challenge?: string; mfa_enrollment_required?: boolean; token?: string; principal?: PrincipalDto }

/** enrolToken: a token that can do nothing but enrol in MFA. It is kept in memory only — it is not a session. */
export type StaffLoginResult = { kind: 'signed_in'; principal: StaffPrincipal } | { kind: 'mfa_required'; challenge: string } | { kind: 'mfa_enrollment_required'; enrolToken: string | null }
export type StaffDeviceSession = { id: string; device: string | null; current: boolean; createdAt: string | null; lastUsedAt: string | null; expiresAt: string | null }
export type MfaSetup = { secret: string; otpauthUri: string }
type SessionDto = { id: string; device: string | null; current: boolean; created_at: string | null; last_used_at: string | null; expires_at: string | null }

const toPrincipal = (p: PrincipalDto): StaffPrincipal => ({ id: p.id, name: p.name, email: p.email, status: p.status, mfaEnabled: p.mfa_enabled, lastLoginAt: p.last_login_at, roles: p.roles, permissions: p.permissions })
const expected = { restaurant: 'RESTAURANT_USER', admin: 'ADMIN_USER' } as const

/** Customer-safe text for a failed staff sign-in; never reveals whether the e-mail exists. */
export function staffErrorMessage(e: unknown): string {
  if (!(e instanceof ApiError)) return 'Something went wrong. Please try again.'
  if (e.kind === 'network') return 'Cannot reach FoodOnTheGo right now. Check your connection and try again.'
  if (e.kind === 'rate_limited') return e.code === 'mfa_attempts_exceeded' ? 'Too many incorrect codes. Please sign in again.' : `Too many attempts. ${e.retryAfterSeconds ? `Try again in ${e.retryAfterSeconds} seconds.` : 'Please wait a moment and try again.'}`
  switch (e.code) {
    case 'invalid_credentials': return 'The e-mail or password is incorrect.'
    case 'account_not_active': return 'This account cannot sign in. Please contact your administrator.'
    case 'mfa_code_invalid': return 'That code is not correct.'
    case 'mfa_challenge_invalid': return 'This sign-in attempt has expired. Please sign in again.'
  }
  switch (e.code) {
    case 'reset_token_invalid': return 'This reset link is invalid or has expired. Request a new one.'
    case 'mfa_already_enabled': return 'Multi-factor authentication is already enabled.'
    case 'mfa_setup_not_started': return 'Start the setup again.'
  }
  if (e.kind === 'validation') return e.field('current_password') ?? e.field('email') ?? e.field('password') ?? e.field('code') ?? e.field('token') ?? 'Please check what you entered.'
  return e.kind === 'server' ? 'Something went wrong on our side. Please try again.' : e.message
}

export function staffAuth(context: StaffContext) {
  const finish = (dto: LoginDto): StaffLoginResult => {
    if (dto.mfa_required && dto.mfa_challenge) return { kind: 'mfa_required', challenge: dto.mfa_challenge }
    // A token that can only enrol in MFA is not a dashboard session: it is handed to the enrolment screen and never stored.
    if (dto.mfa_enrollment_required || !dto.token || !dto.principal) return { kind: 'mfa_enrollment_required', enrolToken: dto.token ?? null }
    tokens.set(context, dto.token)
    return { kind: 'signed_in', principal: toPrincipal(dto.principal) }
  }
  return {
    hasSession: () => tokens.get(context) !== null,
    async login(email: string, password: string): Promise<StaffLoginResult> {
      return finish(await api<LoginDto>(`/auth/${context}/login`, { method: 'POST', auth: false, body: { email, password, device_name: 'web' } }))
    },
    async verifyMfa(challenge: string, code: string): Promise<StaffLoginResult> {
      const recovery = !/^\d{6}$/.test(code.trim())
      return finish(await api<LoginDto>(`/auth/${context}/mfa/verify`, { method: 'POST', auth: false, body: { mfa_challenge: challenge, ...(recovery ? { recovery_code: code.trim() } : { code: code.trim() }) } }))
    },
    /** The signed-in principal, or null when there is no (valid) session. A 403 means the account was suspended. */
    async me(): Promise<StaffPrincipal | null> {
      if (!tokens.get(context)) return null
      try {
        const p = await api<PrincipalDto>('/auth/me', { context })
        if (p.principal_type !== expected[context]) { tokens.set(context, null); return null }
        return toPrincipal(p)
      } catch (e) {
        if (e instanceof ApiError && (e.kind === 'unauthenticated' || e.kind === 'forbidden')) { tokens.set(context, null); return null }
        throw e
      }
    },
    /* ---- password reset (public; the answer never reveals whether the e-mail has an account) ---- */
    async forgotPassword(email: string): Promise<void> { await api(`/auth/${context}/password/forgot`, { method: 'POST', auth: false, body: { email } }) },
    async resetPassword(token: string, password: string): Promise<void> { await api(`/auth/${context}/password/reset`, { method: 'POST', auth: false, body: { token, password, password_confirmation: password } }) },

    /* ---- credentials of the signed-in user. `enrolToken` is used instead of the session while enrolling at sign-in. ---- */
    async mfaSetup(enrolToken?: string): Promise<MfaSetup> {
      const d = await api<{ secret: string; otpauth_uri: string }>('/auth/mfa/totp/setup', { method: 'POST', context, token: enrolToken })
      return { secret: d.secret, otpauthUri: d.otpauth_uri }
    },
    /** Completes enrolment. Returns the recovery codes (shown once). An enrol-only token is replaced by a real session. */
    async mfaConfirm(code: string, enrolToken?: string): Promise<{ recoveryCodes: string[]; principal: StaffPrincipal }> {
      const d = await api<{ recovery_codes: string[]; principal: PrincipalDto; token?: string }>('/auth/mfa/totp/confirm', { method: 'POST', context, token: enrolToken, body: { code: code.trim() } })
      if (d.token) tokens.set(context, d.token)
      return { recoveryCodes: d.recovery_codes, principal: toPrincipal(d.principal) }
    },
    async mfaDisable(password: string, code: string): Promise<void> { await api('/auth/mfa/totp', { method: 'DELETE', context, body: { password, code: code.trim() } }) },
    async changePassword(current: string, password: string): Promise<void> { await api('/auth/password', { method: 'POST', context, body: { current_password: current, password, password_confirmation: password } }) },
    async sessions(): Promise<StaffDeviceSession[]> {
      const d = await api<SessionDto[] | { data: SessionDto[] }>('/auth/sessions', { context })
      return (Array.isArray(d) ? d : d.data ?? []).map((s) => ({ id: s.id, device: s.device, current: s.current, createdAt: s.created_at, lastUsedAt: s.last_used_at, expiresAt: s.expires_at }))
    },
    async revokeSession(id: string): Promise<void> { await api(`/auth/sessions/${id}`, { method: 'DELETE', context }) },
    /** Ends every session of the account, this one included. */
    async logoutAll(): Promise<void> { try { await api('/auth/logout-all', { method: 'POST', context }) } finally { tokens.set(context, null) } },
    async logout(): Promise<void> {
      try { if (tokens.get(context)) await api('/auth/logout', { method: 'POST', context }) } catch { /* the local session ends either way */ }
      tokens.set(context, null)
    },
  }
}

/* ---- backend permission codes → the permission keys the dashboards already use (display only) ---- */
const ADMIN_RENAMES: Record<string, AdminPermission> = { 'users.view': 'admin_users.view', 'users.manage': 'admin_users.manage' }
const ADMIN_KEYS = new Set<string>(['restaurants.view', 'restaurants.approve', 'restaurants.suspend', 'customers.view', 'customers.manage', 'orders.view', 'orders.override', 'payments.view', 'refunds.view', 'refunds.issue', 'settlements.view', 'reviews.view', 'reviews.moderate', 'promotions.view', 'promotions.manage', 'support.view', 'support.manage', 'markets.view', 'markets.manage', 'configuration.manage', 'cities.view', 'cities.manage', 'service_areas.view', 'service_areas.manage', 'market_configuration.view', 'market_configuration.manage', 'market_features.manage', 'notifications.manage', 'admin_users.view', 'admin_users.manage', 'audit.view', 'security.view', 'analytics.view', 'system.view', 'settings.manage'])
export function adminPermissionsFromApi(codes: string[]): AdminPermission[] {
  const out = new Set<AdminPermission>()
  for (const code of codes) {
    if (!code.startsWith('admin.')) continue
    const key = code.slice('admin.'.length)
    const mapped = ADMIN_RENAMES[key] ?? (ADMIN_KEYS.has(key) ? (key as AdminPermission) : null)
    if (mapped) out.add(mapped)
  }
  return [...out]
}
const ADMIN_ROLES: AdminRoleId[] = ['super_admin', 'operations_admin', 'restaurant_onboarding', 'support_admin', 'finance_admin', 'moderation_admin', 'analyst']
export const adminRoleFromApi = (roles: StaffRole[]): AdminRoleId => ADMIN_ROLES.find((r) => roles.some((x) => x.code.toLowerCase() === r)) ?? 'analyst'

const RESTAURANT_MAP: Record<string, RestaurantPermission> = {
  'restaurant.profile.view': 'restaurant.profile.view', 'restaurant.profile.manage': 'restaurant.profile.edit', 'restaurant.hours.manage': 'hours.edit', 'restaurant.pickup_settings.manage': 'pickup.settings.edit',
  'restaurant.menu.view': 'menu.view', 'restaurant.menu.manage': 'menu.edit', 'restaurant.orders.view': 'orders.view', 'restaurant.orders.update': 'orders.update', 'restaurant.pickup.verify': 'pickup.verify',
  'restaurant.reviews.view': 'reviews.view', 'restaurant.reviews.respond': 'reviews.respond', 'restaurant.staff.view': 'staff.view', 'restaurant.staff.manage': 'staff.manage',
  'restaurant.analytics.view': 'analytics.view', 'restaurant.notifications.view': 'notifications.view', 'restaurant.settings.manage': 'settings.manage',
}
export const restaurantPermissionsFromApi = (codes: string[]): RestaurantPermission[] => [...new Set(codes.map((c) => RESTAURANT_MAP[c]).filter((p): p is RestaurantPermission => !!p))]
const RESTAURANT_ROLES: RestaurantRoleId[] = ['owner', 'manager', 'order_staff', 'menu_manager', 'viewer']
export const restaurantRoleFromApi = (roles: StaffRole[]): RestaurantRoleId => RESTAURANT_ROLES.find((r) => roles.some((x) => x.code.toLowerCase() === r)) ?? 'viewer'
