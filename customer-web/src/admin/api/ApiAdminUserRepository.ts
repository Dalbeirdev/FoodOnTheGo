/**
 * Administrator accounts on the backend: list, roles, invitation, status and role changes.
 *
 * The backend decides who may do what and enforces the rules no permission overrides (not your own account, never
 * the last full administrator, no role beyond your own permissions). The acting administrator is the signed-in
 * token — the `actor` argument of the interface is ignored. A refusal reaches the screen as an ApiError.
 */
import { api } from '../../api/client'
import { adminPermissionsFromApi } from '../../auth/staff/staffAuth'
import type { AdminRole, AdminRoleId, AdminRoleMarket, AdminStatus, AdminUser, AdminUserQuery, AdminUserRepository, Page } from '../types'

type RoleDto = { code: string; name: string; market_id: string | null; market: string | null }
type AdminUserDto = { id: string; name: string; email: string; status: AdminStatus; mfa_enabled: boolean; is_self: boolean; roles: RoleDto[]; last_login_at: string | null; created_at: string | null }
type RoleListDto = { data: Array<{ code: string; name: string; system: boolean; permissions: string[] }> }

const roleId = (code: string) => code.toLowerCase() as AdminRoleId
export const toAdminUser = (d: AdminUserDto): AdminUser => ({
  id: d.id, name: d.name, email: d.email, role: d.roles[0] ? roleId(d.roles[0].code) : 'analyst', status: d.status, lastLoginAt: d.last_login_at, createdAt: d.created_at ?? '', mfaEnrolled: d.mfa_enabled,
  hasRole: d.roles.length > 0, roleMarket: d.roles[0]?.market ?? null, roleMarketId: d.roles[0]?.market_id ?? null,
})

export class ApiAdminUserRepository implements AdminUserRepository {
  private roleList: AdminRole[] = []
  private rolesLoaded = false
  private marketList: AdminRoleMarket[] | null = null

  async list(): Promise<AdminUser[]> {
    const [users, roles] = await Promise.all([
      api<{ data: AdminUserDto[] }>('/admin/users', { context: 'admin', query: { 'page[size]': 100 } }),
      // The roles tab needs its own permission; without it the accounts are still listed.
      api<RoleListDto>('/admin/roles', { context: 'admin' }).catch(() => ({ data: [] }) as RoleListDto),
    ])
    this.roleList = roles.data.map((r) => ({ id: roleId(r.code), permissions: adminPermissionsFromApi(r.permissions) })); this.rolesLoaded = true
    return users.data.map(toAdminUser)
  }

  /** One page, searched (name or e-mail) and filtered by the backend. The roles are read once. */
  async page(q: AdminUserQuery): Promise<Page<AdminUser>> {
    const query: Record<string, string | number> = { 'page[number]': q.page, 'page[size]': q.pageSize }
    if (q.q.trim()) query.q = q.q.trim()
    if (q.status !== 'all') query['filter[status]'] = q.status
    const [users] = await Promise.all([
      api<{ data: AdminUserDto[]; meta: { total: number; current_page: number; per_page: number } }>('/admin/users', { context: 'admin', query }),
      this.rolesLoaded ? null : api<RoleListDto>('/admin/roles', { context: 'admin' }).catch(() => ({ data: [] }) as RoleListDto).then((roles) => { this.roleList = roles.data.map((r) => ({ id: roleId(r.code), permissions: adminPermissionsFromApi(r.permissions) })); this.rolesLoaded = true }),
    ])
    return { items: users.data.map(toAdminUser), total: users.meta.total, page: users.meta.current_page, pageSize: users.meta.per_page }
  }

  /** Needs the markets permission; without it roles can only be given platform-wide. */
  async markets(): Promise<AdminRoleMarket[]> {
    this.marketList ??= await api<{ data: Array<{ id: string; country_code: string; name: string }> }>('/admin/markets', { context: 'admin' }).then((r) => r.data.map((m) => ({ id: m.id, code: m.country_code, name: m.name }))).catch(() => [])
    return this.marketList
  }
  roles() { return this.roleList }

  /** Creates the account INVITED, without a password; the backend sends the single-use link. */
  async invite(u: { name: string; email: string; role: AdminRoleId; marketId?: string | null }): Promise<AdminUser> {
    return toAdminUser(await api<AdminUserDto>('/admin/users', { method: 'POST', context: 'admin', body: { name: u.name.trim(), email: u.email.trim(), role: u.role.toUpperCase(), ...(u.marketId ? { market_id: u.marketId } : {}) } }))
  }
  /** A role is given platform-wide unless `roleMarketId` names one market; it replaces the account's previous role. */
  async update(id: string, patch: Partial<Pick<AdminUser, 'role' | 'status' | 'roleMarketId'>>, _actor: string, reason: string): Promise<AdminUser> {
    if (patch.status) return toAdminUser(await api<AdminUserDto>(`/admin/users/${id}/status`, { method: 'PATCH', context: 'admin', body: { status: patch.status, reason: reason.trim() } }))
    if (patch.role) return toAdminUser(await api<AdminUserDto>(`/admin/users/${id}/role`, { method: 'PUT', context: 'admin', body: { role: patch.role.toUpperCase(), ...(patch.roleMarketId ? { market_id: patch.roleMarketId } : {}), reason: reason.trim() } }))
    throw new Error('nothing_to_update')
  }
  /** The enrolment is deleted, never returned; the account is signed out on every device. */
  async resetMfa(id: string, reason: string): Promise<AdminUser> { return toAdminUser(await api<AdminUserDto>(`/admin/users/${id}/mfa/reset`, { method: 'POST', context: 'admin', body: { reason: reason.trim() } })) }
  async resendInvitation(id: string): Promise<void> { await api(`/admin/users/${id}/invitation`, { method: 'POST', context: 'admin' }) }
}

/** The invited administrator chooses a password with the single-use link (public call, no token). */
export async function acceptAdminInvitation(token: string, password: string): Promise<void> {
  await api('/auth/admin/invitation/accept', { method: 'POST', auth: false, body: { token, password, password_confirmation: password } })
}
