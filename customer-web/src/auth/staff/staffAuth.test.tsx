import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api, tokens } from '../../api/client'
import { adminPermissionsFromApi, adminRoleFromApi, restaurantPermissionsFromApi, restaurantRoleFromApi } from './staffAuth'
import { StaffAuthGate, useStaffSession } from './StaffSession'

const json = (status: number, body: unknown) => new Response(body === null ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
const fetchMock = vi.fn<typeof fetch>()
const principal = (over: Record<string, unknown> = {}) => ({ principal_type: 'ADMIN_USER', id: 'a0000000-0000-4000-8000-000000000001', status: 'ACTIVE', name: 'Kenji Watanabe', email: 'kenji.watanabe@foodonthego.example', mfa_enabled: false, last_login_at: null, roles: [{ code: 'FINANCE_ADMIN', name: 'Finance admin', scope: null }], permissions: ['admin.refunds.issue', 'admin.payments.view'], ...over })
const signedIn = { mfa_required: false, mfa_enrollment_required: false, token: '7|admin-token', token_type: 'Bearer', expires_at: null, principal: principal() }

function Inside() {
  const s = useStaffSession()
  return s.mode === 'api'
    ? <div><p data-testid="who">{s.principal.name} · {s.principal.permissions.join(',')}</p><button onClick={() => { void s.logout() }}>out</button><button onClick={() => { void api('/admin/markets', { context: 'admin' }).catch(() => {}) }}>call</button></div>
    : <p data-testid="who">fixture mode</p>
}
const mount = (context: 'admin' | 'restaurant' = 'admin') => render(<MemoryRouter><StaffAuthGate context={context}><Inside /></StaffAuthGate></MemoryRouter>)
const signIn = (email = 'kenji.watanabe@foodonthego.example', password = 'a-correct-passphrase') => {
  fireEvent.change(screen.getByTestId('staff-login-email'), { target: { value: email } })
  fireEvent.change(screen.getByTestId('staff-login-password'), { target: { value: password } })
  fireEvent.click(screen.getByTestId('staff-login-submit'))
}

beforeEach(() => { fetchMock.mockReset(); vi.stubGlobal('fetch', fetchMock); sessionStorage.clear(); localStorage.clear(); localStorage.setItem('fotg.auth.mode', 'api') })
afterEach(() => { cleanup(); vi.unstubAllGlobals() })

describe('Staff sign-in gate (Restaurant Dashboard / Platform Admin)', () => {
  it('mock mode (tests, share builds): no gate, the dashboards keep the fixture switch', () => {
    localStorage.setItem('fotg.auth.mode', 'mock')
    mount()
    expect(screen.getByTestId('who').textContent).toBe('fixture mode')
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('renders nothing of the dashboard without a session and signs in with e-mail + password', async () => {
    mount()
    await screen.findByTestId('staff-login-admin')
    expect(screen.queryByTestId('who')).toBeNull()
    expect(screen.getByRole('heading', { name: 'Platform Admin' })).toBeTruthy()

    fetchMock.mockResolvedValueOnce(json(200, signedIn))
    signIn()
    await waitFor(() => expect(screen.getByTestId('who').textContent).toContain('Kenji Watanabe'))
    expect(String(fetchMock.mock.calls[0][0])).toMatch(/\/auth\/admin\/login$/)
    expect(JSON.parse(String((fetchMock.mock.calls[0][1] as RequestInit).body))).toEqual({ email: 'kenji.watanabe@foodonthego.example', password: 'a-correct-passphrase', device_name: 'web' })
    expect(tokens.get('admin')).toBe('7|admin-token')
    expect(tokens.get('customer')).toBeNull(); expect(tokens.get('restaurant')).toBeNull()
    expect(JSON.stringify({ ...localStorage })).not.toContain('admin-token')
  })

  it('the restaurant context signs in on its own endpoint and keeps its own token', async () => {
    mount('restaurant')
    await screen.findByTestId('staff-login-restaurant')
    fetchMock.mockResolvedValueOnce(json(200, { ...signedIn, token: '9|restaurant-token', principal: principal({ principal_type: 'RESTAURANT_USER', name: 'John Doe', roles: [{ code: 'OWNER', name: 'Owner', scope: { type: 'organization', id: 'org-a' } }], permissions: ['restaurant.orders.view'] }) }))
    signIn('john@riverside.example')
    await waitFor(() => expect(screen.getByTestId('who').textContent).toContain('John Doe'))
    expect(String(fetchMock.mock.calls[0][0])).toMatch(/\/auth\/restaurant\/login$/)
    expect(tokens.get('restaurant')).toBe('9|restaurant-token'); expect(tokens.get('admin')).toBeNull()
  })

  it('wrong credentials, a blocked account and throttling show generic, useful messages', async () => {
    mount()
    await screen.findByTestId('staff-login-admin')
    fetchMock.mockResolvedValueOnce(json(401, { error: { code: 'invalid_credentials', message: 'The e-mail or password is incorrect.' } }))
    signIn()
    expect((await screen.findByTestId('staff-login-error')).textContent).toBe('The e-mail or password is incorrect.')

    fetchMock.mockResolvedValueOnce(json(403, { error: { code: 'account_not_active', message: 'x' } }))
    signIn()
    await waitFor(() => expect(screen.getByTestId('staff-login-error').textContent).toMatch(/cannot sign in/))

    fetchMock.mockResolvedValueOnce(json(429, { error: { code: 'rate_limited', message: 'x', details: { retry_after_seconds: 37 } } }))
    signIn()
    await waitFor(() => expect(screen.getByTestId('staff-login-error').textContent).toMatch(/Try again in 37 seconds/))
    expect(screen.queryByTestId('who')).toBeNull(); expect(tokens.get('admin')).toBeNull()
  })

  it('with MFA the password step leads to a code step; the token only arrives after the code', async () => {
    mount()
    await screen.findByTestId('staff-login-admin')
    fetchMock.mockResolvedValueOnce(json(200, { mfa_required: true, mfa_challenge: 'c'.repeat(64), methods: ['totp', 'recovery_code'] }))
    signIn()
    const code = await screen.findByTestId('staff-login-code')
    expect(tokens.get('admin')).toBeNull()

    fetchMock.mockResolvedValueOnce(json(422, { error: { code: 'mfa_code_invalid', message: 'That code is not correct.' } }))
    fireEvent.change(code, { target: { value: '000000' } }); fireEvent.click(screen.getByTestId('staff-login-submit'))
    expect((await screen.findByTestId('staff-login-error')).textContent).toBe('That code is not correct.')

    fetchMock.mockResolvedValueOnce(json(200, signedIn))
    fireEvent.change(screen.getByTestId('staff-login-code'), { target: { value: '123456' } }); fireEvent.click(screen.getByTestId('staff-login-submit'))
    await waitFor(() => expect(screen.getByTestId('who')).toBeTruthy())
    expect(JSON.parse(String((fetchMock.mock.calls[2][1] as RequestInit).body))).toEqual({ mfa_challenge: 'c'.repeat(64), code: '123456' })
  })

  it('restores an existing session on load and rejects a token of the wrong principal type', async () => {
    tokens.set('admin', '7|admin-token')
    fetchMock.mockResolvedValueOnce(json(200, principal()))
    mount()
    await waitFor(() => expect(screen.getByTestId('who').textContent).toContain('Kenji Watanabe'))
    cleanup()

    fetchMock.mockResolvedValueOnce(json(200, principal({ principal_type: 'CUSTOMER' })))
    mount()
    await screen.findByTestId('staff-login-admin')
    expect(tokens.get('admin')).toBeNull()
  })

  it('401 on a later call returns to sign-in once; 403 does not sign out; sign out revokes the session', async () => {
    tokens.set('admin', '7|admin-token')
    fetchMock.mockResolvedValueOnce(json(200, principal()))
    mount()
    await screen.findByTestId('who')

    fetchMock.mockResolvedValueOnce(json(403, { error: { code: 'forbidden', message: 'You are not allowed to do this.' } }))
    fireEvent.click(screen.getByText('call'))
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2))
    expect(screen.getByTestId('who')).toBeTruthy(); expect(tokens.get('admin')).toBe('7|admin-token')

    fetchMock.mockResolvedValueOnce(json(401, { error: { code: 'unauthenticated', message: 'Authentication is required.' } }))
    fireEvent.click(screen.getByText('call'))
    await screen.findByTestId('staff-login-admin')
    expect(screen.getByRole('status').textContent).toMatch(/session has ended/)
    expect(tokens.get('admin')).toBeNull()
    expect(fetchMock).toHaveBeenCalledTimes(3) // no retry loop

    fetchMock.mockResolvedValueOnce(json(200, signedIn)); signIn()
    await screen.findByTestId('who')
    fetchMock.mockResolvedValueOnce(json(204, null))
    fireEvent.click(screen.getByText('out'))
    await screen.findByTestId('staff-login-admin')
    expect(String(fetchMock.mock.calls[4][0])).toMatch(/\/auth\/logout$/)
    expect(tokens.get('admin')).toBeNull()
  })

  it('a backend that is unreachable shows a retry, not a broken dashboard', async () => {
    tokens.set('admin', '7|admin-token')
    fetchMock.mockRejectedValueOnce(new TypeError('offline'))
    mount()
    expect((await screen.findByRole('alert')).textContent).toMatch(/Cannot reach FoodOnTheGo/)
    expect(tokens.get('admin')).toBe('7|admin-token')
    fetchMock.mockResolvedValueOnce(json(200, principal()))
    fireEvent.click(screen.getByText('Try again'))
    await screen.findByTestId('who')
  })

  it('maps backend permission codes and roles onto the dashboard vocabulary (display only)', () => {
    expect(adminPermissionsFromApi(['admin.refunds.issue', 'admin.users.manage', 'admin.roles.manage', 'restaurant.menu.manage', 'admin.unknown.thing']).sort()).toEqual(['admin_users.manage', 'refunds.issue'])
    expect(adminRoleFromApi([{ code: 'FINANCE_ADMIN', name: '', scope: null }])).toBe('finance_admin')
    expect(adminRoleFromApi([])).toBe('analyst')
    expect(restaurantPermissionsFromApi(['restaurant.menu.manage', 'restaurant.pickup_settings.manage', 'restaurant.profile.manage', 'admin.refunds.issue']).sort()).toEqual(['menu.edit', 'pickup.settings.edit', 'restaurant.profile.edit'])
    expect(restaurantRoleFromApi([{ code: 'ORDER_STAFF', name: '', scope: null }])).toBe('order_staff')
  })
})
