import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AppShell } from '../App'
import { AuthProvider } from './AuthContext'
import { ATTEMPTS_ALLOWED, DEV_OTP, MockAuthRepository, OTP_TTL_MS, TEST_NUMBERS } from './mock/MockAuthRepository'
import { AuthError } from './repository'
import { formatPhone, maskPhone, toE164, validatePhone, DEFAULT_COUNTRY } from './phone'

// Fast mock (no artificial latency) with a controllable clock.
let clock = 1_700_000_000_000
const makeRepo = () => new MockAuthRepository(0, () => clock)
const mount = (route: string, repo = makeRepo()) => render(<MemoryRouter initialEntries={[route]}><AuthProvider repository={repo}><AppShell /></AuthProvider></MemoryRouter>)

const enterOtp = async (user: ReturnType<typeof userEvent.setup>, code: string) => {
  const boxes = screen.getAllByRole('textbox', { name: /digit \d of 6/i })
  await user.click(boxes[0])
  await user.keyboard(code)
}

describe('phone helpers', () => {
  it('validates and normalises Indian mobile numbers', () => {
    expect(validatePhone(DEFAULT_COUNTRY, '')).toMatch(/enter your mobile/i)
    expect(validatePhone(DEFAULT_COUNTRY, '12345')).toMatch(/10-digit/i)
    expect(validatePhone(DEFAULT_COUNTRY, '1234567890')).toMatch(/valid/i) // must start 6-9
    expect(validatePhone(DEFAULT_COUNTRY, '98765 43210')).toBeNull()
    expect(toE164(DEFAULT_COUNTRY, '98765-43210')).toBe('+919876543210')
    expect(toE164(DEFAULT_COUNTRY, '+91 98765 43210')).toBe('+919876543210')
    expect(maskPhone('+919876543210')).toBe('+91 ••••••3210')
    expect(formatPhone('+919876543210')).toBe('+91 98765 43210')
  })
})

describe('MockAuthRepository (development-only OTP)', () => {
  beforeEach(() => { localStorage.clear(); sessionStorage.clear(); clock = 1_700_000_000_000 })

  it('issues the dev OTP, verifies it, and distinguishes new from existing customers', async () => {
    const repo = makeRepo()
    const req = await repo.requestOtp('+919000000001')
    expect(req.devOtp).toBe(DEV_OTP)
    const first = await repo.verifyOtp('+919000000001', DEV_OTP)
    expect(first.status).toBe('setup_required')
    const user = await repo.completeSetup((first as { setupToken: string }).setupToken, { name: 'Priya Verma', acceptTerms: true })
    expect(user.phone).toBe('+919000000001')
    expect(await repo.getCurrentUser()).toMatchObject({ name: 'Priya Verma' })

    await repo.logout()
    expect(await repo.getCurrentUser()).toBeNull()
    await repo.requestOtp(TEST_NUMBERS.existingCustomer)
    expect((await repo.verifyOtp(TEST_NUMBERS.existingCustomer, DEV_OTP)).status).toBe('authenticated')
  })

  it('rejects wrong codes with attempts left, then locks; expires after the TTL; simulates failures', async () => {
    const repo = makeRepo()
    await repo.requestOtp('+919000000002')
    for (let i = 1; i < ATTEMPTS_ALLOWED; i++) {
      await expect(repo.verifyOtp('+919000000002', '000000')).rejects.toMatchObject({ code: 'invalid_otp', attemptsLeft: ATTEMPTS_ALLOWED - i })
    }
    await expect(repo.verifyOtp('+919000000002', '000000')).rejects.toMatchObject({ code: 'too_many_attempts' })
    await expect(repo.verifyOtp('+919000000002', DEV_OTP)).rejects.toMatchObject({ code: 'expired_otp' })

    await repo.requestOtp('+919000000003')
    clock += OTP_TTL_MS + 1
    await expect(repo.verifyOtp('+919000000003', DEV_OTP)).rejects.toMatchObject({ code: 'expired_otp' })

    await expect(repo.requestOtp(TEST_NUMBERS.sendFailure)).rejects.toMatchObject({ code: 'send_failed' })
    await expect(repo.requestOtp(TEST_NUMBERS.networkDown)).rejects.toMatchObject({ code: 'network' })
    await expect(repo.requestOtp(TEST_NUMBERS.unexpected)).rejects.toBeInstanceOf(AuthError)
  })

  it('reports an expired session', async () => {
    const repo = makeRepo()
    await repo.requestOtp(TEST_NUMBERS.existingCustomer)
    await repo.verifyOtp(TEST_NUMBERS.existingCustomer, DEV_OTP)
    repo.expireSessionNow()
    await expect(repo.getCurrentUser()).rejects.toMatchObject({ code: 'session_expired' })
  })
})

describe('Authentication flows (web UI)', () => {
  // The app shell wires its own mock instance on the real clock; tests share its sessionStorage.
  beforeEach(() => { localStorage.clear(); sessionStorage.clear(); clock = Date.now() })
  afterEach(() => vi.useRealTimers())

  it('TEST 1 — new customer: mobile → OTP → setup → signed in and returned to the requested page', async () => {
    const user = userEvent.setup()
    mount('/favorites')
    expect(await screen.findByRole('heading', { name: /sign in with your mobile/i })).toBeInTheDocument()
    await user.type(screen.getByLabelText(/mobile number/i), '9000011111')
    await user.click(screen.getByRole('button', { name: /send otp/i }))
    expect(await screen.findByRole('heading', { name: /enter the code/i })).toBeInTheDocument()
    expect(screen.getByText(/\+91 ••••••1111/)).toBeInTheDocument()
    await enterOtp(user, DEV_OTP)
    await user.click(screen.getByRole('button', { name: /^verify$/i }))
    expect(await screen.findByRole('heading', { name: /tell us your name/i })).toBeInTheDocument()
    await user.type(screen.getByLabelText(/full name/i), 'Priya Verma')
    await user.click(screen.getByRole('checkbox'))
    await user.click(screen.getByRole('button', { name: /continue/i }))
    expect(await screen.findByRole('heading', { name: /favorite restaurants/i })).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: /my account/i }).length).toBeGreaterThan(0)
    expect(screen.queryByRole('link', { name: /^login$/i })).toBeNull()
  })

  it('TEST 2 — existing customer goes straight to the account area', async () => {
    const user = userEvent.setup()
    mount('/login')
    await user.type(screen.getByLabelText(/mobile number/i), '98765 43210')
    await user.click(screen.getByRole('button', { name: /send otp/i }))
    await screen.findByRole('heading', { name: /enter the code/i })
    await enterOtp(user, DEV_OTP)
    await user.click(screen.getByRole('button', { name: /^verify$/i }))
    expect(await screen.findByRole('heading', { name: 'Rahul Sharma' })).toBeInTheDocument()
  })

  it('TEST 3 — wrong OTP shows attempts left, clears the boxes, and the correct code still works', async () => {
    const user = userEvent.setup()
    mount('/login')
    await user.type(screen.getByLabelText(/mobile number/i), '98765 43210')
    await user.click(screen.getByRole('button', { name: /send otp/i }))
    await screen.findByRole('heading', { name: /enter the code/i })
    await enterOtp(user, '111111')
    await user.click(screen.getByRole('button', { name: /^verify$/i }))
    expect(await screen.findByRole('alert')).toHaveTextContent(/incorrect code\. 2 attempts left/i)
    await enterOtp(user, DEV_OTP)
    await user.click(screen.getByRole('button', { name: /^verify$/i }))
    expect(await screen.findByRole('heading', { name: 'Rahul Sharma' })).toBeInTheDocument()
  })

  it('TEST 4 — expired OTP disables Verify and Resend issues a fresh code', async () => {
    const user = userEvent.setup()
    mount('/login')
    await user.type(screen.getByLabelText(/mobile number/i), '98765 43210')
    await user.click(screen.getByRole('button', { name: /send otp/i }))
    await screen.findByRole('heading', { name: /enter the code/i })
    // Age the pending code in storage so the next verification is rejected as expired.
    const pendingOtp = JSON.parse(sessionStorage.getItem('fotg.mock.otp')!) as { expiresAt: number }
    sessionStorage.setItem('fotg.mock.otp', JSON.stringify({ ...pendingOtp, expiresAt: Date.now() - OTP_TTL_MS }))
    await enterOtp(user, DEV_OTP)
    await user.click(screen.getByRole('button', { name: /^verify$/i }))
    expect(await screen.findByRole('alert')).toHaveTextContent(/expired/i)
    await user.click(screen.getByRole('button', { name: /resend otp/i }))
    expect(await screen.findByRole('status')).toHaveTextContent(/new code was sent/i)
    await enterOtp(user, DEV_OTP)
    await user.click(screen.getByRole('button', { name: /^verify$/i }))
    expect(await screen.findByRole('heading', { name: 'Rahul Sharma' })).toBeInTheDocument()
  })

  it('TEST 5 — guest on My Orders is asked to sign in and returns to My Orders', async () => {
    const user = userEvent.setup()
    mount('/my-orders')
    expect(await screen.findByRole('heading', { name: /sign in with your mobile/i })).toBeInTheDocument()
    await user.type(screen.getByLabelText(/mobile number/i), '98765 43210')
    await user.click(screen.getByRole('button', { name: /send otp/i }))
    await screen.findByRole('heading', { name: /enter the code/i })
    await enterOtp(user, DEV_OTP)
    await user.click(screen.getByRole('button', { name: /^verify$/i }))
    expect(await screen.findByRole('heading', { name: /your orders/i })).toBeInTheDocument()
  })

  it('TEST 6 — logout asks for confirmation, then protected pages are inaccessible', async () => {
    const user = userEvent.setup()
    const repo = makeRepo()
    await repo.requestOtp(TEST_NUMBERS.existingCustomer)
    await repo.verifyOtp(TEST_NUMBERS.existingCustomer, DEV_OTP)
    mount('/my-profile', repo)
    expect(await screen.findByRole('heading', { name: 'Rahul Sharma' })).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: /logout/i }))
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: /sign out/i }))
    expect(await screen.findByRole('heading', { name: /sign in with your mobile/i })).toBeInTheDocument()
    expect(await screen.findByText(/you have been signed out/i)).toBeInTheDocument()
    await waitFor(async () => expect(await repo.getCurrentUser()).toBeNull())
  })

  it('invalid phone, send failure and network errors are explained inline', async () => {
    const user = userEvent.setup()
    mount('/login')
    await user.type(screen.getByLabelText(/mobile number/i), '12345')
    await user.click(screen.getByRole('button', { name: /send otp/i }))
    expect(await screen.findByRole('alert')).toHaveTextContent(/10-digit/i)
    await user.clear(screen.getByLabelText(/mobile number/i))
    await user.type(screen.getByLabelText(/mobile number/i), '9999900000')
    await user.click(screen.getByRole('button', { name: /send otp/i }))
    expect(await screen.findByRole('alert')).toHaveTextContent(/couldn't send/i)
    await user.clear(screen.getByLabelText(/mobile number/i))
    await user.type(screen.getByLabelText(/mobile number/i), '9999900001')
    await user.click(screen.getByRole('button', { name: /send otp/i }))
    expect(await screen.findByRole('alert')).toHaveTextContent(/no internet/i)
  })

  it('pasting a code fills all six boxes', async () => {
    const user = userEvent.setup()
    mount('/login')
    await user.type(screen.getByLabelText(/mobile number/i), '98765 43210')
    await user.click(screen.getByRole('button', { name: /send otp/i }))
    await screen.findByRole('heading', { name: /enter the code/i })
    const boxes = screen.getAllByRole('textbox', { name: /digit \d of 6/i })
    await user.click(boxes[0])
    await user.paste('123456')
    expect(boxes.map((b) => (b as HTMLInputElement).value).join('')).toBe('123456')
  })

  it('an expired session sends the customer to login with the expiry message', async () => {
    const repo = makeRepo()
    await repo.requestOtp(TEST_NUMBERS.existingCustomer)
    await repo.verifyOtp(TEST_NUMBERS.existingCustomer, DEV_OTP)
    repo.expireSessionNow()
    mount('/my-orders', repo)
    expect(await screen.findByRole('alert')).toHaveTextContent(/session has expired/i)
    await waitFor(() => expect(screen.getByRole('heading', { name: /sign in with your mobile/i })).toBeInTheDocument())
  })
})
