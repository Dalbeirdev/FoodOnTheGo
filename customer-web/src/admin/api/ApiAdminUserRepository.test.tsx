/** Administrator accounts on the backend + the public "accept invitation" page. The network is stubbed (OpenAPI: AdminUserPage, AdminRoleList). */
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../../api/client'
import AcceptInvitationPage from '../pages/AcceptInvitationPage'
import { ApiAdminUserRepository } from './ApiAdminUserRepository'

const USER = { id: 'u-1', name: 'Nina Patel', email: 'nina.patel@foodonthego.example', status: 'ACTIVE', mfa_enabled: true, is_self: false, roles: [{ code: 'OPERATIONS_ADMIN', name: 'Operations admin', market_id: 'm-in', market: 'IN' }], last_login_at: '2026-09-30T10:00:00+00:00', created_at: '2026-09-01T00:00:00+00:00' }
type Call = { method: string; path: string; body: Record<string, unknown> | null; auth: string | null }
let calls: Call[] = []; let respond: (c: Call) => { status?: number; body: unknown }
const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
beforeEach(() => {
  calls = []; tokens.set('admin', 'admin-token')
  respond = (c) => (c.path === '/admin/roles' ? { body: { data: [{ code: 'SUPER_ADMIN', name: 'Super admin', system: true, permissions: ['admin.users.view', 'admin.users.manage', 'admin.markets.view', 'restaurant.menu.view'] }] } } : c.method === 'GET' ? { body: { data: [USER, { ...USER, id: 'u-2', name: 'No Role', roles: [], mfa_enabled: false, status: 'INVITED', last_login_at: null }] } } : { body: USER })
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const c: Call = { method: init.method ?? 'GET', path: new URL(url).pathname.replace(/^\/api\/v1/, ''), body: init.body ? JSON.parse(String(init.body)) : null, auth: (init.headers as Record<string, string>).Authorization ?? null }
    calls.push(c); const r = respond(c); return json(r.status ?? 200, r.body)
  }))
})
afterEach(() => { vi.unstubAllGlobals(); tokens.set('admin', null); window.history.pushState({}, '', '/') })

describe('ApiAdminUserRepository', () => {
  it('lists accounts and roles with the admin token and maps them', async () => {
    const repo = new ApiAdminUserRepository(); const users = await repo.list()
    expect(calls.map((c) => c.path).sort()).toEqual(['/admin/roles', '/admin/users']); expect(calls.every((c) => c.auth === 'Bearer admin-token')).toBe(true)
    expect(users[0]).toEqual({ id: 'u-1', name: 'Nina Patel', email: 'nina.patel@foodonthego.example', role: 'operations_admin', status: 'ACTIVE', lastLoginAt: '2026-09-30T10:00:00+00:00', createdAt: '2026-09-01T00:00:00+00:00', mfaEnrolled: true, hasRole: true, roleMarket: 'IN' })
    expect(users[1]).toMatchObject({ hasRole: false, roleMarket: null, status: 'INVITED', mfaEnrolled: false, lastLoginAt: null })
    expect(repo.roles()).toEqual([{ id: 'super_admin', permissions: ['admin_users.view', 'admin_users.manage', 'markets.view'] }])
  })

  it('still lists accounts when the caller may not read roles', async () => {
    respond = (c) => (c.path === '/admin/roles' ? { status: 403, body: { error: { code: 'forbidden', message: 'You are not allowed to do this.' } } } : { body: { data: [USER] } })
    const repo = new ApiAdminUserRepository(); expect((await repo.list()).length).toBe(1); expect(repo.roles()).toEqual([])
  })

  it('sends invitation, status, role and resend to the backend; the actor is the token, never a field', async () => {
    const repo = new ApiAdminUserRepository()
    await repo.invite({ name: ' Priya Nair ', email: ' priya@foodonthego.example ', role: 'support_admin' })
    await repo.update('u-1', { status: 'SUSPENDED' }, 'ignored-actor', ' Left the team ')
    await repo.update('u-1', { role: 'finance_admin' }, 'ignored-actor', 'Moves to finance')
    await repo.resendInvitation('u-2')
    expect(calls.map((c) => [c.method, c.path, c.body])).toEqual([
      ['POST', '/admin/users', { name: 'Priya Nair', email: 'priya@foodonthego.example', role: 'SUPPORT_ADMIN' }],
      ['PATCH', '/admin/users/u-1/status', { status: 'SUSPENDED', reason: 'Left the team' }],
      ['PUT', '/admin/users/u-1/role', { role: 'FINANCE_ADMIN', reason: 'Moves to finance' }],
      ['POST', '/admin/users/u-2/invitation', null],
    ])
    expect(JSON.stringify(calls)).not.toContain('ignored-actor')
  })

  it('passes a backend refusal on as an error with its message', async () => {
    respond = () => ({ status: 409, body: { error: { code: 'last_administrator', message: 'This is the last active administrator who can manage accounts and roles. Give that role to another administrator first.' } } })
    await expect(new ApiAdminUserRepository().update('u-1', { status: 'SUSPENDED' }, 'x', 'Why')).rejects.toMatchObject({ code: 'last_administrator', kind: 'conflict' })
  })
})

describe('AcceptInvitationPage', () => {
  const TOKEN = 'a'.repeat(64)
  const mount = (hash: string) => { window.history.pushState({}, '', '/admin/accept-invitation' + hash); return render(<MemoryRouter><AcceptInvitationPage /></MemoryRouter>) }

  it('without a complete link it explains what to do and sends nothing', () => {
    mount('#short'); expect(screen.getByTestId('accept-invalid')).toBeInTheDocument(); expect(calls).toEqual([])
  })

  it('takes the token out of the address bar, checks the password and activates the account without a session token', async () => {
    const user = userEvent.setup(); respond = () => ({ body: { message: 'Your password is set. You can sign in now.' } })
    mount('#' + TOKEN)
    expect(window.location.hash).toBe('')
    await user.type(screen.getByTestId('accept-password'), 'short'); await user.type(screen.getByTestId('accept-repeat'), 'short'); await user.click(screen.getByTestId('accept-submit'))
    expect(screen.getByTestId('accept-error')).toHaveTextContent('at least 12 characters'); expect(calls).toEqual([])
    await user.clear(screen.getByTestId('accept-password')); await user.type(screen.getByTestId('accept-password'), 'a-long-new-passphrase'); await user.clear(screen.getByTestId('accept-repeat')); await user.type(screen.getByTestId('accept-repeat'), 'a-long-new-passphrasX')
    await user.click(screen.getByTestId('accept-submit')); expect(screen.getByTestId('accept-error')).toHaveTextContent('not the same'); expect(calls).toEqual([])
    await user.clear(screen.getByTestId('accept-repeat')); await user.type(screen.getByTestId('accept-repeat'), 'a-long-new-passphrase'); await user.click(screen.getByTestId('accept-submit'))
    expect(await screen.findByTestId('accept-done')).toBeInTheDocument()
    expect(calls).toEqual([{ method: 'POST', path: '/auth/admin/invitation/accept', body: { token: TOKEN, password: 'a-long-new-passphrase', password_confirmation: 'a-long-new-passphrase' }, auth: null }])
    expect(JSON.stringify({ ...localStorage }) + JSON.stringify({ ...sessionStorage })).not.toContain('a-long-new-passphrase')
  })

  it('shows the backend refusal for a used or expired link', async () => {
    const user = userEvent.setup(); respond = () => ({ status: 422, body: { error: { code: 'invitation_invalid', message: 'This invitation link is invalid or has expired. Ask an administrator to send a new one.' } } })
    mount('#' + TOKEN)
    await user.type(screen.getByTestId('accept-password'), 'a-long-new-passphrase'); await user.type(screen.getByTestId('accept-repeat'), 'a-long-new-passphrase'); await user.click(screen.getByTestId('accept-submit'))
    expect(await screen.findByTestId('accept-error')).toHaveTextContent('invalid or has expired'); expect(screen.queryByTestId('accept-done')).toBeNull()
  })
})
