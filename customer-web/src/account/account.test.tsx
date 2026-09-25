import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { AppShell } from '../App'
import { defaultAuthRepository } from '../auth/AuthContext'
import { DEV_OTP, MockAuthRepository, TEST_NUMBERS } from '../auth/mock/MockAuthRepository'
import { MockAddressRepository, MockFavoriteRepository, MockNotificationRepository, MockPaymentMethodRepository, MockProfileRepository, setMockLatency } from './mock/mockRepositories'

const RAHUL = 'cust-rahul'
const mount = (route: string) => render(<MemoryRouter initialEntries={[route]}><AppShell /></MemoryRouter>)

/** Signs the seeded customer in through the mock auth repository (shared sessionStorage). */
async function signInRahul() {
  const auth = new MockAuthRepository(0)
  await auth.requestOtp(TEST_NUMBERS.existingCustomer)
  await auth.verifyOtp(TEST_NUMBERS.existingCustomer, DEV_OTP)
}

beforeEach(() => { localStorage.clear(); sessionStorage.clear(); setMockLatency(0) })
afterEach(() => { sessionStorage.removeItem('fotg.mock.fail') })

describe('Mock account repositories', () => {
  it('favorites: seeded customer has favorites, new customers none; add/remove persist', async () => {
    const repo = new MockFavoriteRepository()
    expect((await repo.list(RAHUL)).length).toBe(4)
    expect(await repo.list('cust-new')).toEqual([])
    await repo.add('cust-new', 'healthy-bites')
    expect((await repo.list('cust-new')).map((f) => f.restaurantId)).toEqual(['healthy-bites'])
    await repo.remove('cust-new', 'healthy-bites')
    expect(await repo.list('cust-new')).toEqual([])
  })

  it('addresses: add, edit, delete keep exactly one default', async () => {
    const repo = new MockAddressRepository()
    const base = { label: 'Home', kind: 'home' as const, line1: '12 Lake View', line2: '', locality: 'Sector 50', city: 'Noida', state: 'Uttar Pradesh', pincode: '201301', lat: null, lng: null }
    let list = await repo.save('cust-new', base)
    expect(list[0].isDefault).toBe(true)
    list = await repo.save('cust-new', { ...base, label: 'Work', kind: 'work' })
    expect(list.filter((a) => a.isDefault).length).toBe(1)
    list = await repo.save('cust-new', { ...list[1], id: list[1].id, label: 'Office' })
    expect(list[1].label).toBe('Office')
    list = await repo.setDefault('cust-new', list[1].id)
    expect(list.find((a) => a.isDefault)?.label).toBe('Office')
    list = await repo.remove('cust-new', list[1].id)
    expect(list.length).toBe(1)
    expect(list[0].isDefault).toBe(true)
  })

  it('payment methods hold provider references only and never a card number or CVV', async () => {
    const repo = new MockPaymentMethodRepository()
    const list = await repo.list(RAHUL)
    const json = JSON.stringify(list) + JSON.stringify(localStorage)
    expect(json).not.toMatch(/\b\d{13,19}\b/) // no PAN anywhere
    expect(json.toLowerCase()).not.toContain('cvv')
    expect(list.find((m) => m.type === 'card')).toMatchObject({ providerRef: expect.stringMatching(/^token_/), last4: '3456' })
    await expect(repo.remove(RAHUL, 'pm-cash')).rejects.toThrow(/cannot be removed/i)
    const after = await repo.remove(RAHUL, 'pm-card-1')
    expect(after.some((m) => m.id === 'pm-card-1')).toBe(false)
    expect(after.filter((m) => m.isDefault).length).toBe(1)
  })

  it('notifications: read/unread state and preferences persist', async () => {
    const repo = new MockNotificationRepository()
    const list = await repo.list(RAHUL)
    expect(list.filter((n) => !n.read).length).toBe(3)
    expect((await repo.markRead(RAHUL, 'n1')).find((n) => n.id === 'n1')?.read).toBe(true)
    expect((await repo.markAllRead(RAHUL)).every((n) => n.read)).toBe(true)
    expect((await repo.updatePreferences(RAHUL, { promotions: true })).promotions).toBe(true)
    expect((await repo.getPreferences(RAHUL)).promotions).toBe(true)
  })

  it('profile: updates, avatar and deletion request; email edits reset verification', async () => {
    const repo = new MockProfileRepository()
    const p = await repo.get(RAHUL, { name: 'Rahul Sharma', phone: '+919876543210', email: 'rahul.sharma@example.com', memberSince: '2025-01-12' })
    expect(p.phoneVerified).toBe(true)
    expect(p.emailVerified).toBe(false)
    expect((await repo.update(RAHUL, { name: 'Rahul S.', email: 'new@example.com' })).emailVerified).toBe(false)
    expect((await repo.setAvatar(RAHUL, 'data:image/png;base64,AAAA')).avatarUrl).toContain('data:image/png')
    await expect(repo.setAvatar(RAHUL, 'x'.repeat(2_600_000))).rejects.toThrow(/too large/i)
    expect((await repo.requestDeletion(RAHUL)).deletionRequestedAt).not.toBeNull()
  })

  it('simulated failures surface as repository errors', async () => {
    sessionStorage.setItem('fotg.mock.fail', 'favorites')
    await expect(new MockFavoriteRepository().list(RAHUL)).rejects.toThrow(/favorites/i)
  })
})

describe('Account flows (web UI)', () => {
  it('TEST 1 — profile: edit name, save, updated state shown', async () => {
    await signInRahul()
    const user = userEvent.setup()
    mount('/my-profile')
    const name = await screen.findByLabelText(/full name/i)
    expect(name).toHaveValue('Rahul Sharma')
    await user.clear(name)
    await user.type(name, 'Rahul Sharma Jr')
    await user.click(screen.getByRole('button', { name: /save changes/i }))
    expect(await screen.findByRole('heading', { name: 'Rahul Sharma Jr' })).toBeInTheDocument()
    expect(await screen.findByText(/profile updated/i)).toBeInTheDocument()
  })

  it('TEST 1b — profile validation blocks an invalid email and shows the phone-change rule', async () => {
    await signInRahul()
    const user = userEvent.setup()
    mount('/my-profile')
    const email = await screen.findByLabelText(/email address/i)
    await user.clear(email)
    await user.type(email, 'not-an-email')
    await user.click(screen.getByRole('button', { name: /save changes/i }))
    expect(await screen.findByRole('alert')).toHaveTextContent(/valid email/i)
    expect(screen.getByLabelText(/mobile number/i)).toHaveAttribute('readonly')
    await user.click(screen.getByRole('button', { name: /change number/i }))
    expect(await screen.findByRole('dialog')).toHaveTextContent(/OTP VERIFICATION = BACKEND PENDING/i)
  })

  it('TEST 2 — favorites: add from the restaurant page, see it, remove it', async () => {
    await signInRahul()
    const user = userEvent.setup()
    const detail = mount('/restaurants/healthy-bites')
    const heart = await screen.findByRole('button', { name: /add healthy bites to favorites/i })
    await user.click(heart)
    await waitFor(() => expect(screen.getByRole('button', { name: /remove healthy bites from favorites/i })).toBeInTheDocument())
    detail.unmount()
    mount('/favorites')
    expect((await screen.findAllByRole('heading', { name: 'Healthy Bites' })).length).toBeGreaterThan(0)
    const removeButtons = await screen.findAllByRole('button', { name: /remove healthy bites from favorites/i })
    await user.click(removeButtons[0])
    await waitFor(() => expect(screen.queryByRole('heading', { name: 'Healthy Bites' })).toBeNull())
  })

  it('TEST 2b — favorites empty, loading and error states', async () => {
    const auth = new MockAuthRepository(0)
    await auth.requestOtp('+919000000777')
    const r = await auth.verifyOtp('+919000000777', DEV_OTP)
    await auth.completeSetup((r as { setupToken: string }).setupToken, { name: 'New Customer', acceptTerms: true })
    mount('/favorites')
    expect(await screen.findByRole('heading', { name: /no favorite restaurants yet/i })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /explore restaurants/i })).toBeInTheDocument()

    sessionStorage.setItem('fotg.mock.fail', 'favorites')
    cleanup()
    mount('/favorites')
    expect((await screen.findAllByRole('alert'))[0]).toHaveTextContent(/couldn't load your favorites/i)
    sessionStorage.removeItem('fotg.mock.fail')
    const user = userEvent.setup()
    await user.click(screen.getAllByRole('button', { name: /try again/i })[0])
    expect((await screen.findAllByRole('heading', { name: /no favorite restaurants yet/i })).length).toBeGreaterThan(0)
  })

  it('TEST 3 — address: add Home, edit, delete with confirmation', async () => {
    const auth = new MockAuthRepository(0)
    await auth.requestOtp('+919000000778')
    const r = await auth.verifyOtp('+919000000778', DEV_OTP)
    await auth.completeSetup((r as { setupToken: string }).setupToken, { name: 'Address Tester', acceptTerms: true })
    const user = userEvent.setup()
    mount('/addresses')
    expect(await screen.findByRole('heading', { name: /no saved addresses yet/i })).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: /add new address/i }))
    const dialog = screen.getByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: /save address/i }))
    expect((await within(dialog).findAllByRole('alert')).length).toBeGreaterThan(0)
    await user.type(within(dialog).getByLabelText(/^label/i), 'Home')
    await user.type(within(dialog).getByLabelText(/address line 1/i), 'A-203 Green Valley')
    await user.type(within(dialog).getByLabelText(/locality/i), 'Sector 62')
    await user.type(within(dialog).getByLabelText(/^city/i), 'Noida')
    await user.type(within(dialog).getByLabelText(/pin code/i), '201309')
    await user.click(within(dialog).getByRole('button', { name: /save address/i }))
    expect(await screen.findByRole('heading', { name: /home/i })).toBeInTheDocument()
    expect(screen.getByText(/default start/i)).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /^edit$/i }))
    const edit = screen.getByRole('dialog')
    const label = within(edit).getByLabelText(/^label/i)
    await user.clear(label)
    await user.type(label, 'Home (Noida)')
    await user.click(within(edit).getByRole('button', { name: /save changes/i }))
    expect(await screen.findByRole('heading', { name: /home \(noida\)/i })).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /^delete$/i }))
    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: /^delete$/i }))
    expect(await screen.findByRole('heading', { name: /no saved addresses yet/i })).toBeInTheDocument()
  })

  it('TEST 4 — payment methods show provider references and no way to type a card number', async () => {
    await signInRahul()
    const user = userEvent.setup()
    mount('/payment-methods')
    expect(await screen.findByRole('heading', { name: /credit \/ debit card/i })).toBeInTheDocument()
    expect(screen.getByText(/•••• •••• •••• 3456/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: /add new payment method/i }))
    const dialog = await screen.findByRole('dialog')
    expect(dialog).toHaveTextContent(/RAZORPAY CONNECTION = NOT STARTED/i)
    expect(within(dialog).queryByRole('textbox')).toBeNull()
    expect(JSON.stringify(localStorage).toLowerCase()).not.toContain('cvv')
  })

  it('TEST 5 — notifications: unread item, mark read, mark all, preferences', async () => {
    await signInRahul()
    const user = userEvent.setup()
    mount('/notifications')
    const unread = await screen.findAllByLabelText('Unread')
    expect(unread.length).toBe(3)
    await user.click(screen.getByRole('button', { name: /special offer just for you/i }))
    await waitFor(() => expect(screen.getAllByLabelText('Unread').length).toBe(2))
    await user.click(screen.getByRole('button', { name: /mark all as read/i }))
    await waitFor(() => expect(screen.queryAllByLabelText('Unread').length).toBe(0))
    await user.click(screen.getByRole('button', { name: /preferences/i }))
    const promo = await screen.findByLabelText(/offers & promotions/i)
    expect(promo).not.toBeChecked()
    await user.click(promo)
    await waitFor(() => expect(screen.getByLabelText(/offers & promotions/i)).toBeChecked())
  })

  it('TEST 6 — guest on /favorites is asked to sign in and returns; logout blocks all account pages', async () => {
    const user = userEvent.setup()
    mount('/favorites')
    await waitFor(() => expect(screen.getByRole('heading', { name: /sign in with your mobile/i })).toBeInTheDocument(), { timeout: 4000 })
    await user.type(screen.getByLabelText(/mobile number/i), '98765 43210')
    await user.click(screen.getByRole('button', { name: /send otp/i }))
    await screen.findByRole('heading', { name: /enter the code/i })
    const boxes = screen.getAllByRole('textbox', { name: /digit \d of 6/i })
    await user.click(boxes[0])
    await user.keyboard(DEV_OTP)
    await user.click(screen.getByRole('button', { name: /^verify$/i }))
    expect(await screen.findByRole('heading', { name: /favorite restaurants/i })).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /logout/i }))
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: /sign out/i }))
    await screen.findByText(/you have been signed out/i)
    await waitFor(async () => expect(await defaultAuthRepository.getCurrentUser()).toBeNull())
    for (const route of ['/my-profile', '/favorites', '/addresses', '/payment-methods', '/notifications']) {
      cleanup()
      mount(route)
      expect((await screen.findAllByRole('heading', { name: /sign in with your mobile/i })).length).toBeGreaterThan(0)
    }
  })
})
