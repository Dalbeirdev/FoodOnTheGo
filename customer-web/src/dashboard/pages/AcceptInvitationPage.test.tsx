/**
 * /restaurant-dashboard/accept-invitation#<token> — an invited staff member joins a restaurant (Module 23).
 * The network is stubbed; the backend answers are the ones of POST /auth/restaurant/invitation/accept.
 */
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../../api/client'
import AcceptStaffInvitationPage from './AcceptInvitationPage'

type Call = { method: string; path: string; body: Record<string, unknown> | null; auth: string | null }
let calls: Call[] = []; let respond: (c: Call) => { status?: number; body: unknown }
const TOKEN = 'b'.repeat(64)
const NEEDS_PASSWORD = { status: 422, body: { error: { code: 'validation_failed', message: 'Please check the highlighted fields.', details: { fields: { password: ['Choose a password to activate your account.'] } } } } }
const mount = (hash: string) => { window.history.pushState({}, '', '/restaurant-dashboard/accept-invitation' + hash); return render(<MemoryRouter><AcceptStaffInvitationPage /></MemoryRouter>) }

beforeEach(() => {
  calls = []; tokens.set('restaurant', 'someone-elses-session')
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const c: Call = { method: init.method ?? 'GET', path: new URL(url).pathname.replace(/^\/api\/v1/, ''), body: init.body ? JSON.parse(String(init.body)) : null, auth: (init.headers as Record<string, string>).Authorization ?? null }
    calls.push(c); const r = respond(c)
    return new Response(JSON.stringify(r.body), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
})
afterEach(() => { vi.unstubAllGlobals(); tokens.set('restaurant', null); window.history.pushState({}, '', '/') })

describe('AcceptStaffInvitationPage', () => {
  it('without a complete link it explains what to do and sends nothing', () => {
    mount('#short')
    expect(screen.getByTestId('accept-invalid')).toBeInTheDocument(); expect(screen.queryByTestId('accept-submit')).not.toBeInTheDocument(); expect(calls).toEqual([])
  })

  it('someone who already has an account accepts with one click — the token leaves the address bar and no session token is sent', async () => {
    const user = userEvent.setup(); respond = () => ({ body: { message: 'ok', restaurant: 'Riverside Hospitality Group' } })
    mount('#' + TOKEN)
    expect(window.location.hash).toBe('')
    expect(screen.queryByTestId('accept-password')).not.toBeInTheDocument()
    await user.click(screen.getByTestId('accept-submit'))
    expect(await screen.findByTestId('accept-done')).toHaveTextContent('You are now part of Riverside Hospitality Group')
    expect(calls).toEqual([{ method: 'POST', path: '/auth/restaurant/invitation/accept', body: { token: TOKEN }, auth: null }])
  })

  it('a new account is asked to choose a password first; nothing is accepted until it is valid', async () => {
    const user = userEvent.setup(); respond = (c) => (c.body?.password ? { body: { message: 'ok', restaurant: 'Riverside Hospitality Group' } } : NEEDS_PASSWORD)
    mount('#' + TOKEN)
    await user.click(screen.getByTestId('accept-submit'))
    expect(await screen.findByTestId('accept-password')).toBeInTheDocument(); expect(screen.queryByTestId('accept-done')).not.toBeInTheDocument()
    expect(screen.queryByTestId('accept-error')).not.toBeInTheDocument() // being asked for a password is not an error
    expect(screen.getByTestId('accept-submit')).toBeDisabled()

    await user.type(screen.getByTestId('accept-password'), 'short'); await user.type(screen.getByTestId('accept-repeat'), 'short'); await user.click(screen.getByTestId('accept-submit'))
    expect(screen.getByTestId('accept-error')).toHaveTextContent('at least 12 characters')
    await user.clear(screen.getByTestId('accept-password')); await user.type(screen.getByTestId('accept-password'), 'a-long-new-passphrase')
    await user.clear(screen.getByTestId('accept-repeat')); await user.type(screen.getByTestId('accept-repeat'), 'a-long-new-passphrasX'); await user.click(screen.getByTestId('accept-submit'))
    expect(screen.getByTestId('accept-error')).toHaveTextContent('not the same')
    expect(calls).toHaveLength(1) // only the first attempt reached the backend

    await user.clear(screen.getByTestId('accept-repeat')); await user.type(screen.getByTestId('accept-repeat'), 'a-long-new-passphrase'); await user.click(screen.getByTestId('accept-submit'))
    expect(await screen.findByTestId('accept-done')).toBeInTheDocument()
    expect(calls[1]).toEqual({ method: 'POST', path: '/auth/restaurant/invitation/accept', body: { token: TOKEN, password: 'a-long-new-passphrase', password_confirmation: 'a-long-new-passphrase' }, auth: null })
    // neither the password nor the invitation token is kept in the browser
    const stored = JSON.stringify({ ...localStorage }) + JSON.stringify({ ...sessionStorage })
    expect(stored).not.toContain('a-long-new-passphrase'); expect(stored).not.toContain(TOKEN)
  })

  it('shows the backend refusal for a used, expired or revoked link — and for a weak password', async () => {
    const user = userEvent.setup()
    respond = () => ({ status: 422, body: { error: { code: 'invitation_invalid', message: 'This invitation link is invalid or has expired. Ask the restaurant to send a new one.', details: {} } } })
    mount('#' + TOKEN)
    await user.click(screen.getByTestId('accept-submit'))
    expect(await screen.findByTestId('accept-error')).toHaveTextContent('invalid or has expired'); expect(screen.queryByTestId('accept-done')).not.toBeInTheDocument()
  })

  it('a password the backend refuses is explained in its own words', async () => {
    const user = userEvent.setup()
    respond = (c) => (c.body?.password ? { status: 422, body: { error: { code: 'validation_failed', message: 'Please check the highlighted fields.', details: { fields: { password: ['The password is too common. Choose another one.'] } } } } } : NEEDS_PASSWORD)
    mount('#' + TOKEN)
    await user.click(screen.getByTestId('accept-submit'))
    await user.type(await screen.findByTestId('accept-password'), 'password12345'); await user.type(screen.getByTestId('accept-repeat'), 'password12345'); await user.click(screen.getByTestId('accept-submit'))
    expect(await screen.findByTestId('accept-error')).toHaveTextContent('too common'); expect(screen.queryByTestId('accept-done')).not.toBeInTheDocument()
  })
})
