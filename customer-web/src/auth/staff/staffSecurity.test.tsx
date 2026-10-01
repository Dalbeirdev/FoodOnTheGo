/** Staff MFA enrolment, password reset and account-security screens. The network is stubbed with the backend's answers. */
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../../api/client'
import AccountSecurityPage from './AccountSecurityPage'
import { MfaSetup } from './MfaSetup'
import { ForgotPasswordPage, ResetPasswordPage } from './StaffPasswordPages'
import { StaffAuthGate, useStaffSession } from './StaffSession'

vi.mock('qrcode', () => ({ default: { toDataURL: vi.fn(async (text: string) => 'data:image/png;base64,QR:' + btoa(text)) } }))

const PRINCIPAL = { principal_type: 'ADMIN_USER', id: 'a-1', name: 'Kenji Watanabe', email: 'kenji.watanabe@foodonthego.example', status: 'ACTIVE', mfa_enabled: false, last_login_at: null, roles: [{ code: 'FINANCE_ADMIN', name: 'Finance admin', scope: null }], permissions: ['admin.refunds.view'] }
const SECRET = 'JBSWY3DPEHPK3PXP'; const URI = `otpauth://totp/FoodOnTheGo:kenji?secret=${SECRET}&issuer=FoodOnTheGo`; const CODES = ['aaaa-bbbb-1111', 'cccc-dddd-2222']
type Call = { method: string; path: string; body: Record<string, unknown> | null; auth: string | null }
let calls: Call[] = []; let routes: Record<string, (c: Call) => { status?: number; body?: unknown }>
const json = (status: number, body: unknown) => new Response(body === undefined ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
beforeEach(() => {
  calls = []; localStorage.clear(); sessionStorage.clear(); localStorage.setItem('fotg.auth.mode', 'api')
  routes = {
    'POST /auth/mfa/totp/setup': () => ({ body: { secret: SECRET, otpauth_uri: URI } }),
    'POST /auth/mfa/totp/confirm': (c) => (c.body?.code === '123456' ? { body: { recovery_codes: CODES, principal: { ...PRINCIPAL, mfa_enabled: true }, ...(c.auth === 'Bearer enrol-token' ? { token: 'full-token' } : {}) } } : { status: 422, body: { error: { code: 'mfa_code_invalid', message: 'That code is not correct.' } } }),
  }
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const c: Call = { method: init.method ?? 'GET', path: new URL(url).pathname.replace(/^\/api\/v1/, ''), body: init.body ? JSON.parse(String(init.body)) : null, auth: (init.headers as Record<string, string>).Authorization ?? null }
    calls.push(c); const r = routes[`${c.method} ${c.path}`]?.(c) ?? { status: 404, body: { error: { code: 'not_found', message: 'Not found' } } }
    return json(r.status ?? 200, r.body)
  }))
})
afterEach(() => { vi.unstubAllGlobals(); tokens.set('admin', null); tokens.set('restaurant', null); window.history.pushState({}, '', '/'); localStorage.clear(); sessionStorage.clear() })
const stored = () => JSON.stringify({ ...localStorage }) + JSON.stringify({ ...sessionStorage })

describe('MfaSetup', () => {
  it('shows the QR code and key, turns MFA on with a code and shows the recovery codes once — nothing is stored', async () => {
    const user = userEvent.setup(); const onDone = vi.fn(); tokens.set('admin', 'session-token')
    const write = vi.spyOn(navigator.clipboard, 'writeText')
    render(<MfaSetup context="admin" onDone={onDone} />)
    expect(await screen.findByTestId('mfa-secret')).toHaveTextContent('JBSW Y3DP EHPK 3PXP')
    await waitFor(() => expect(screen.getByTestId('mfa-qr')).toHaveAttribute('src', 'data:image/png;base64,QR:' + btoa(URI)))   // drawn in the browser from the otpauth URI
    expect(screen.getByTestId('mfa-confirm')).toBeDisabled()
    await user.type(screen.getByTestId('mfa-code'), '00x0000'); await user.click(screen.getByTestId('mfa-confirm'))
    expect(await screen.findByTestId('mfa-error')).toHaveTextContent('That code is not correct.')
    await user.clear(screen.getByTestId('mfa-code')); await user.type(screen.getByTestId('mfa-code'), '123456'); await user.click(screen.getByTestId('mfa-confirm'))
    const list = await screen.findByTestId('mfa-recovery'); for (const c of CODES) expect(list).toHaveTextContent(c)
    expect(screen.queryByTestId('mfa-secret')).toBeNull(); expect(screen.getByTestId('mfa-finish')).toBeDisabled(); expect(onDone).not.toHaveBeenCalled()
    await user.click(screen.getByTestId('mfa-copy')); expect(write).toHaveBeenCalledWith(CODES.join('\n'))
    await user.click(screen.getByTestId('mfa-saved')); await user.click(screen.getByTestId('mfa-finish'))
    expect(onDone).toHaveBeenCalledWith(expect.objectContaining({ mfaEnabled: true }))
    expect(calls.map((c) => [c.method, c.path, c.auth])).toEqual([['POST', '/auth/mfa/totp/setup', 'Bearer session-token'], ['POST', '/auth/mfa/totp/confirm', 'Bearer session-token'], ['POST', '/auth/mfa/totp/confirm', 'Bearer session-token']])
    for (const secret of [SECRET, ...CODES]) expect(stored()).not.toContain(secret)
  })
})

describe('sign-in when MFA enrolment is required', () => {
  function Who() { const s = useStaffSession(); return s.mode === 'api' ? <p data-testid="who">{s.principal.name} mfa:{String(s.principal.mfaEnabled)}</p> : null }
  it('the enrol-only token is never stored: the person enrols and only then gets a session', async () => {
    const user = userEvent.setup()
    routes['POST /auth/admin/login'] = () => ({ body: { mfa_required: false, mfa_enrollment_required: true, token: 'enrol-token', principal: PRINCIPAL } })
    render(<MemoryRouter><StaffAuthGate context="admin"><Who /></StaffAuthGate></MemoryRouter>)
    await user.type(await screen.findByTestId('staff-login-email'), PRINCIPAL.email); await user.type(screen.getByTestId('staff-login-password'), 'a-correct-passphrase'); await user.click(screen.getByTestId('staff-login-submit'))
    expect(await screen.findByTestId('staff-enrol')).toHaveTextContent('must use multi-factor authentication'); expect(screen.queryByTestId('who')).toBeNull()
    expect(tokens.get('admin')).toBeNull(); expect(stored()).not.toContain('enrol-token')
    await user.type(await screen.findByTestId('mfa-code'), '123456'); await user.click(screen.getByTestId('mfa-confirm'))
    await user.click(await screen.findByTestId('mfa-saved')); await user.click(screen.getByTestId('mfa-finish'))
    expect(await screen.findByTestId('who')).toHaveTextContent('Kenji Watanabe mfa:true'); expect(tokens.get('admin')).toBe('full-token')
    expect(calls.filter((c) => c.path.startsWith('/auth/mfa')).every((c) => c.auth === 'Bearer enrol-token')).toBe(true)
  })

  it('the sign-in form links to "Forgot your password?"', async () => {
    render(<MemoryRouter><StaffAuthGate context="restaurant"><p>in</p></StaffAuthGate></MemoryRouter>)
    expect(await screen.findByTestId('staff-forgot-link')).toHaveAttribute('href', '/restaurant-dashboard/forgot-password')
  })
})

describe('password reset pages', () => {
  it('forgot password: validates the e-mail, posts to the right context without a token, and always answers the same', async () => {
    const user = userEvent.setup(); routes['POST /auth/restaurant/password/forgot'] = () => ({ body: { message: 'If an account exists for this e-mail, a reset link has been sent.' } })
    render(<MemoryRouter><ForgotPasswordPage context="restaurant" /></MemoryRouter>)
    await user.type(screen.getByTestId('forgot-email'), 'not-an-email'); await user.click(screen.getByTestId('forgot-submit'))
    expect(screen.getByTestId('forgot-error')).toBeInTheDocument(); expect(calls).toEqual([])
    await user.clear(screen.getByTestId('forgot-email')); await user.type(screen.getByTestId('forgot-email'), ' john@riverside.example '); await user.click(screen.getByTestId('forgot-submit'))
    expect(await screen.findByTestId('forgot-sent')).toHaveTextContent('If an account exists')
    expect(calls).toEqual([{ method: 'POST', path: '/auth/restaurant/password/forgot', body: { email: 'john@riverside.example' }, auth: null }])
  })

  it('reset password: takes the token out of the address bar, checks the password, and shows a refused link', async () => {
    const user = userEvent.setup(); const TOKEN = 'b'.repeat(64); let ok = false
    routes['POST /auth/admin/password/reset'] = () => (ok ? { body: { message: 'Your password has been changed. Please sign in again.' } } : { status: 422, body: { error: { code: 'reset_token_invalid', message: 'This reset link is invalid or has expired. Request a new one.' } } })
    window.history.pushState({}, '', '/admin/reset-password#' + TOKEN)
    render(<MemoryRouter><ResetPasswordPage context="admin" /></MemoryRouter>)
    expect(window.location.hash).toBe('')
    await user.type(screen.getByTestId('reset-password'), 'short'); await user.type(screen.getByTestId('reset-repeat'), 'short'); await user.click(screen.getByTestId('reset-submit'))
    expect(screen.getByTestId('reset-error')).toHaveTextContent('at least 12 characters'); expect(calls).toEqual([])
    await user.clear(screen.getByTestId('reset-password')); await user.type(screen.getByTestId('reset-password'), 'a-brand-new-passphrase'); await user.clear(screen.getByTestId('reset-repeat')); await user.type(screen.getByTestId('reset-repeat'), 'a-brand-new-passphrase')
    await user.click(screen.getByTestId('reset-submit')); expect(await screen.findByTestId('reset-error')).toHaveTextContent('invalid or has expired')
    ok = true; await user.click(screen.getByTestId('reset-submit'))
    expect(await screen.findByTestId('reset-done')).toHaveTextContent('every device has been signed out')
    expect(calls.at(-1)).toEqual({ method: 'POST', path: '/auth/admin/password/reset', body: { token: TOKEN, password: 'a-brand-new-passphrase', password_confirmation: 'a-brand-new-passphrase' }, auth: null })
    expect(stored()).not.toContain('a-brand-new-passphrase')
  })

  it('an incomplete reset link sends nothing', () => {
    window.history.pushState({}, '', '/admin/reset-password#nope')
    render(<MemoryRouter><ResetPasswordPage context="admin" /></MemoryRouter>)
    expect(screen.getByTestId('reset-invalid')).toBeInTheDocument(); expect(calls).toEqual([])
  })
})

describe('Account security page', () => {
  const SESSIONS = [{ id: 's-1', device: 'web', current: true, created_at: '2026-10-01T08:00:00+00:00', last_used_at: '2026-10-01T09:00:00+00:00', expires_at: '2026-10-01T16:00:00+00:00' }, { id: 's-2', device: 'web', current: false, created_at: '2026-09-30T08:00:00+00:00', last_used_at: null, expires_at: null }]
  const mount = async (mfa: boolean) => {
    let me = { ...PRINCIPAL, mfa_enabled: mfa }; tokens.set('admin', 'session-token')
    routes['GET /auth/me'] = () => ({ body: me }); routes['GET /auth/sessions'] = () => ({ body: SESSIONS })
    routes['DELETE /auth/mfa/totp'] = (c) => { if (c.body?.password !== 'the-current-passphrase') return { status: 422, body: { error: { code: 'validation_failed', message: 'The submitted data is invalid.', details: { fields: { current_password: ['The current password is not correct.'] } } } } }; me = { ...me, mfa_enabled: false }; return { status: 204 } }
    render(<MemoryRouter><StaffAuthGate context="admin"><AccountSecurityPage context="admin" /></StaffAuthGate></MemoryRouter>)
    await screen.findByTestId('account-security')
  }

  it('turns MFA off only with the current password and a code, and reflects the new state from the backend', async () => {
    const user = userEvent.setup(); await mount(true)
    expect(screen.getByTestId('mfa-state')).toHaveTextContent('On')
    await user.click(screen.getByTestId('mfa-disable-start')); await user.type(screen.getByTestId('mfa-off-password'), 'wrong-passphrase'); await user.type(screen.getByTestId('mfa-off-code'), '123456'); await user.click(screen.getByTestId('mfa-off-submit'))
    expect(await screen.findByTestId('mfa-disable-error')).toHaveTextContent('The current password is not correct.')
    await user.clear(screen.getByTestId('mfa-off-password')); await user.type(screen.getByTestId('mfa-off-password'), 'the-current-passphrase'); await user.click(screen.getByTestId('mfa-off-submit'))
    await waitFor(() => expect(screen.getByTestId('mfa-state')).toHaveTextContent('Off'))
    expect(calls.filter((c) => c.method === 'DELETE').at(-1)).toMatchObject({ path: '/auth/mfa/totp', body: { password: 'the-current-passphrase', code: '123456' }, auth: 'Bearer session-token' })
  })

  it('offers the e-mail language only when there is a choice, saves it on the backend and shows what the backend stored', async () => {
    const user = userEvent.setup(); await mount(false)
    expect(screen.queryByTestId('sec-language')).toBeNull() // the backend offers a single language: nothing to choose
    cleanup()
    let me: typeof PRINCIPAL & { preferred_locale: string | null; notice_locales: string[] } = { ...PRINCIPAL, preferred_locale: null, notice_locales: ['en', 'hi'] }
    routes['GET /auth/me'] = () => ({ body: me })
    routes['PUT /auth/language'] = (c) => { if (c.body?.locale === 'en') return { status: 422, body: { error: { code: 'validation_failed', message: 'The submitted data is invalid.' } } }; me = { ...me, preferred_locale: (c.body?.locale ?? null) as string | null }; return { body: me } }
    render(<MemoryRouter><StaffAuthGate context="admin"><AccountSecurityPage context="admin" /></StaffAuthGate></MemoryRouter>)
    const select = await screen.findByTestId('language-select') as HTMLSelectElement
    expect(select).toHaveValue(''); expect(Array.from(select.options).map((o) => o.textContent)).toEqual(['No preference — screens in English, e-mails in every language (English + हिन्दी (Hindi))', 'English', 'हिन्दी (Hindi)'])
    await user.selectOptions(select, 'hi')
    expect(await screen.findByTestId('language-done')).toBeInTheDocument(); expect(screen.getByTestId('language-select')).toHaveValue('hi')
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('खाते की सुरक्षा')) // the screen itself switches to Hindi
    expect(screen.getByTestId('language-done')).toHaveTextContent('सेव हो गया।'); expect(localStorage.getItem('fotg.staff.lang')).toBe('hi')
    expect(calls.filter((c) => c.method === 'PUT').at(-1)).toMatchObject({ path: '/auth/language', body: { locale: 'hi' }, auth: 'Bearer session-token' })
    await user.selectOptions(screen.getByTestId('language-select'), 'en') // refused: the stored choice stays on screen
    expect(await screen.findByTestId('language-error')).toBeInTheDocument(); expect(screen.getByTestId('language-select')).toHaveValue('hi')
    await user.selectOptions(screen.getByTestId('language-select'), '')
    await waitFor(() => expect(screen.getByTestId('language-select')).toHaveValue('')); expect(calls.filter((c) => c.method === 'PUT').at(-1)?.body).toEqual({ locale: null })
  })

  it('changes the password after local checks and shows a wrong current password from the backend', async () => {
    const user = userEvent.setup(); await mount(false); let accept = false
    routes['POST /auth/password'] = () => (accept ? { body: { message: 'Password changed. Other devices have been signed out.' } } : { status: 422, body: { error: { code: 'validation_failed', message: 'The submitted data is invalid.', details: { fields: { current_password: ['The current password is not correct.'] } } } } })
    await user.type(screen.getByTestId('pw-current'), 'the-current-passphrase'); await user.type(screen.getByTestId('pw-next'), 'a-brand-new-passphrase'); await user.type(screen.getByTestId('pw-repeat'), 'a-brand-new-passphrasX'); await user.click(screen.getByTestId('pw-submit'))
    expect(screen.getByTestId('password-error')).toHaveTextContent('not the same'); expect(calls.some((c) => c.path === '/auth/password')).toBe(false)
    await user.clear(screen.getByTestId('pw-repeat')); await user.type(screen.getByTestId('pw-repeat'), 'a-brand-new-passphrase'); await user.click(screen.getByTestId('pw-submit'))
    expect(await screen.findByTestId('password-error')).toHaveTextContent('The current password is not correct.')
    accept = true; await user.click(screen.getByTestId('pw-submit'))
    expect(await screen.findByTestId('password-done')).toBeInTheDocument(); expect(screen.getByTestId('pw-current')).toHaveValue('')
    expect(calls.at(-1)).toMatchObject({ path: '/auth/password', body: { current_password: 'the-current-passphrase', password: 'a-brand-new-passphrase', password_confirmation: 'a-brand-new-passphrase' } })
    expect(stored()).not.toContain('passphrase')
  })

  it('lists the signed-in devices, signs another one out, and "everywhere" ends this session too', async () => {
    const user = userEvent.setup(); await mount(false)
    routes['DELETE /auth/sessions/s-2'] = () => ({ status: 204 }); routes['POST /auth/logout-all'] = () => ({ status: 204 })
    const table = await screen.findByTestId('sessions-table'); expect(table).toHaveTextContent('This device')
    expect(screen.queryByTestId('session-revoke-s-1')).toBeNull()   // not the current one
    await user.click(screen.getByTestId('session-revoke-s-2')); await waitFor(() => expect(calls.some((c) => c.method === 'DELETE' && c.path === '/auth/sessions/s-2')).toBe(true))
    await user.click(screen.getByTestId('sessions-all'))
    expect(await screen.findByTestId('staff-login-admin')).toBeInTheDocument(); expect(tokens.get('admin')).toBeNull()
  })
})
