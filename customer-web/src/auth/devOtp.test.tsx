import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AppShell } from '../App'
import { ApiAuthRepository } from './api/ApiAuthRepository'
import { AuthProvider } from './AuthContext'
import { MockAuthRepository } from './mock/MockAuthRepository'
import { apiSignInHintAllowed, developmentHintsAllowed, developmentOtp, rememberOtpDelivery } from './devOtp'

/**
 * Module 39 (C-03): the development code and the fixture phone numbers never appear in a production build, and in a
 * development build the API code appears only for a challenge the backend itself calls "development".
 */
const NOW = Date.parse('2026-10-14T10:00:00Z')
const challenge = (delivery: 'live' | 'development') => ({ challenge_id: 'ch-1', phone_masked: '+91 ******3210', expires_at: '2026-10-14T10:05:00+00:00', resend_available_at: '2026-10-14T10:00:30+00:00', attempts_allowed: 5, server_time: '2026-10-14T10:00:00+00:00', delivery })
const fetchMock = vi.fn()
const answer = (body: unknown) => Promise.resolve(new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } }))
const production = () => { vi.stubEnv('DEV', false); vi.stubEnv('PROD', true) }

beforeEach(() => { sessionStorage.clear(); localStorage.clear(); fetchMock.mockReset(); vi.stubGlobal('fetch', fetchMock); vi.useFakeTimers({ now: NOW, toFake: ['Date'] }) })
afterEach(() => { vi.unstubAllGlobals(); vi.unstubAllEnvs(); vi.useRealTimers(); sessionStorage.clear(); localStorage.clear() })

describe('development OTP (Module 39)', () => {
  it('the code: development build + a "development" challenge only; never in a production build', () => {
    expect(developmentOtp('development', { DEV: true, PROD: false, VITE_DEV_OTP: '123456' })).toBe('123456')
    expect(developmentOtp('live', { DEV: true, PROD: false, VITE_DEV_OTP: '123456' })).toBeUndefined()
    expect(developmentOtp('development', { DEV: true, PROD: false, VITE_DEV_OTP: '' })).toBeUndefined()
    expect(developmentOtp('development', { DEV: false, PROD: true, VITE_DEV_OTP: '123456' })).toBeUndefined()
    expect(developmentHintsAllowed({ DEV: false, PROD: true })).toBe(false)
    expect(developmentHintsAllowed({ DEV: false, PROD: true, VITE_SHARE_BUILD: '1' })).toBe(true)
  })

  it('a production build never hands the code over, even with VITE_DEV_OTP set and a "development" backend', async () => {
    production(); vi.stubEnv('VITE_DEV_OTP', '123456')
    fetchMock.mockImplementation(() => answer(challenge('development')))
    const otp = await new ApiAuthRepository().requestOtp('+919876543210')
    expect(otp.devOtp).toBeUndefined()
    // The development build, same answer: the code is shown.
    vi.unstubAllEnvs(); vi.stubEnv('VITE_DEV_OTP', '123456')
    expect((await new ApiAuthRepository().requestOtp('+919876543210')).devOtp).toBe('123456')
  })

  it('the login hint (API): only after the backend answered "development" in this tab, and never in a production build', () => {
    const dev = { DEV: true, PROD: false, VITE_DEV_OTP: '123456' }
    expect(apiSignInHintAllowed(dev)).toBe(false)
    rememberOtpDelivery('live'); expect(apiSignInHintAllowed(dev)).toBe(false)
    rememberOtpDelivery('development'); expect(apiSignInHintAllowed(dev)).toBe(true)
    expect(apiSignInHintAllowed({ ...dev, DEV: false, PROD: true })).toBe(false)
  })

  it('the login page of a production build shows no development note (mock code, test numbers)', async () => {
    const mount = () => render(<MemoryRouter initialEntries={['/login']}><AuthProvider repository={new MockAuthRepository(0)}><AppShell /></AuthProvider></MemoryRouter>)
    const first = mount()
    expect(await screen.findByRole('heading', { name: /sign in with your mobile/i })).toBeInTheDocument()
    expect(screen.getByRole('note')).toHaveTextContent(/123456/)
    first.unmount()
    production()
    mount()
    expect(await screen.findByRole('heading', { name: /sign in with your mobile/i })).toBeInTheDocument()
    expect(screen.queryByRole('note')).toBeNull()
    expect(document.body.textContent).not.toMatch(/123456|98765 43210/)
  })
})
