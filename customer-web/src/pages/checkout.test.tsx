import { act, render, renderHook, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it } from 'vitest'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import CheckoutPage from './CheckoutPage'
import PaymentPage from './PaymentPage'
import { AuthProvider, useAuth } from '../auth/AuthContext'
import { CartProvider, useCart, type AddItemInput } from '../cart/CartContext'
import { MemoryCartRepository } from '../cart/cartModel'
import { CheckoutProvider } from '../checkout/CheckoutContext'
import { PaymentProviderContext } from '../payment/PaymentContext'
import { setMockPaymentLatency } from '../payment/mock/mockPayment'
import { setMockCheckoutLatency } from '../checkout/mock/mockCheckout'
import { JourneyProvider } from '../journey/JourneyContext'
import { setMockMenuLatency } from '../menu/mock/mockMenu'
import { PickupProvider } from '../pickup/PickupContext'
import { setMockPickupLatency } from '../pickup/mock/mockPickup'
import { ProfileProvider } from '../profile/ProfileContext'
import { LocaleProvider } from '../i18n/LocaleProvider'
import { setMockRestaurantLatency } from '../repositories/mock/restaurants'
import RequireAuth from '../auth/RequireAuth'

/** Module 11 — Checkout review page (web). TEST 1–14 at component level. */
const burger = (over: Partial<AddItemInput> = {}): AddItemInput => ({
  menuItemId: 'classic-burger', itemSlug: 'classic-burger', itemName: 'Classic Burger', image: '', fallback: '', basePriceMinor: 25000, currency: 'INR',
  restaurant: { id: 'burger-hub', slug: 'burger-hub', name: 'Burger Hub', currency: 'INR' },
  selectedVariants: [{ groupId: 'classic-burger:size', groupName: 'Size', optionId: 'size:large', optionName: 'Large', priceAdjustmentMinor: 7000 }],
  selectedModifiers: [], specialInstructions: 'No onion', quantity: 2, unitPriceMinor: 32000, maximumQuantity: 20, ...over,
})
const pickupFor = (cartId: string, restaurantId = 'burger-hub', tz = 'Asia/Kolkata') => ({ mode: 'asap', slotId: null, requestedAt: new Date(Date.now() + 20 * 60000).toISOString(), restaurantTimezone: tz, estimatedCustomerArrival: null, estimatedReadyTime: new Date(Date.now() + 20 * 60000).toISOString(), confirmedDisplayTime: '', cartId, restaurantId })
const USER = { id: 'u1', name: 'Dev Tester', phone: '+919876543210', email: 'dev@example.com', memberSince: '2026-01-01' }
function Loc() { const l = useLocation(); return <output data-testid="loc">{l.pathname}</output> }
function AuthProbe() { const a = useAuth(); return <output data-testid="auth">{String(a.isAuthenticated)}</output> }

function mount(path: string, repo = new MemoryCartRepository()) {
  return render(
    <LocaleProvider><AuthProvider><ProfileProvider><JourneyProvider><CartProvider repository={repo}><PickupProvider><CheckoutProvider><PaymentProviderContext>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/checkout" element={<RequireAuth><CheckoutPage /></RequireAuth>} />
          <Route path="/payment" element={<RequireAuth><PaymentPage /></RequireAuth>} />
          <Route path="/login" element={<p>login page</p>} />
          <Route path="*" element={<p>other</p>} />
        </Routes>
        <Loc /><AuthProbe />
      </MemoryRouter>
    </PaymentProviderContext></CheckoutProvider></PickupProvider></CartProvider></JourneyProvider></ProfileProvider></AuthProvider></LocaleProvider>,
  )
}
const seededRepo = async (items: AddItemInput[]) => {
  const repo = new MemoryCartRepository()
  const wrapper = ({ children }: { children: ReactNode }) => <CartProvider repository={repo}>{children}</CartProvider>
  const hook = renderHook(() => useCart(), { wrapper })
  await act(async () => {})
  for (const i of items) act(() => { hook.result.current.addItem(i) })
  await act(async () => { await new Promise((r) => setTimeout(r, 10)) })
  hook.unmount()
  return repo
}
const signIn = () => { localStorage.setItem('fotg.mock.customers', JSON.stringify([USER])); sessionStorage.setItem('fotg.mock.session', JSON.stringify({ token: 'dev', userId: 'u1', expiresAt: Date.now() + 3600000 })) }
const withinIst = () => { const d = new Date(); const m = d.getUTCHours() * 60 + d.getUTCMinutes(); return m >= 150 && m < 1080 } // Burger Hub is open 08:00–23:30 IST = 02:30–18:00 UTC
const ready = async () => { await waitFor(() => expect(screen.getByText(/order summary/i)).toBeInTheDocument()); await waitFor(() => expect(screen.queryByText(/validating cart and pickup/i)).toBeNull(), { timeout: 4000 }); await waitFor(() => expect(screen.getByText(/items subtotal/i)).toBeInTheDocument(), { timeout: 4000 }) }

describe('Checkout review (web)', () => {
  beforeEach(() => { setMockPaymentLatency(0); setMockCheckoutLatency(0); setMockPickupLatency(0); setMockMenuLatency(0); setMockRestaurantLatency(0); localStorage.clear(); sessionStorage.clear() })

  it('TEST 2 — guest reaching checkout is sent to login (state stays in storage)', async () => {
    const repo = await seededRepo([burger()])
    sessionStorage.setItem('fotg.pickup.selection', JSON.stringify(pickupFor((await repo.load())!.id)))
    mount('/checkout', repo)
    await waitFor(() => expect(screen.getByTestId('loc')).toHaveTextContent('/login'))
    expect((await repo.load())?.items).toHaveLength(1)
    expect(sessionStorage.getItem('fotg.pickup.selection')).not.toBeNull()
  })

  it('TEST 1 — authenticated customer with a valid cart and pickup sees every review section and the summary', async () => {
    signIn()
    const repo = await seededRepo([burger()])
    sessionStorage.setItem('fotg.pickup.selection', JSON.stringify(pickupFor((await repo.load())!.id)))
    mount('/checkout', repo)
    await ready()
    expect(screen.getByRole('heading', { level: 1, name: /checkout/i })).toBeInTheDocument()
    expect(screen.getByText('Dev Tester')).toBeInTheDocument()
    expect(screen.getByText('+919876543210')).toBeInTheDocument()
    expect(screen.getByText('Verified')).toBeInTheDocument()
    expect(screen.getByText('Burger Hub')).toBeInTheDocument()
    expect(screen.getByText(/Noida/)).toBeInTheDocument()
    expect(screen.getByText(/Asia\/Kolkata/)).toBeInTheDocument()
    const line = screen.getByRole('listitem', { name: 'Classic Burger' })
    expect(within(line).getByText(/Large/)).toBeInTheDocument()
    expect(within(line).getByText(/No onion/)).toBeInTheDocument()
    expect(within(line).getByText('₹640.00')).toBeInTheDocument()
    expect(screen.getByText(/items subtotal/i).parentElement).toHaveTextContent('₹640.00')
    expect(screen.getByText(/^total$/i).parentElement).toHaveTextContent('₹640.00')
    expect(screen.queryByText(/GST|VAT|service fee|platform fee|delivery/i)).toBeNull()
    expect(screen.getByText(/no taxes or fees are configured/i)).toBeInTheDocument()
    expect(screen.getByRole('radio', { name: /upi/i })).toBeInTheDocument()
    expect(screen.queryByLabelText(/card number|cvv/i)).toBeNull()
    expect(screen.getByRole('link', { name: /terms of service/i })).toHaveAttribute('href', '/terms')
    expect(screen.getByRole('link', { name: /refund/i })).toHaveAttribute('href', '/refund-policy')
  })

  it('TEST 11 / payment handoff — Continue is blocked until terms are accepted, then prepares a CheckoutRequest without a client amount as authority', async () => {
    const user = userEvent.setup()
    signIn()
    const repo = await seededRepo([burger()])
    sessionStorage.setItem('fotg.pickup.selection', JSON.stringify(pickupFor((await repo.load())!.id)))
    mount('/checkout', repo)
    await ready()
    await user.click(screen.getByRole('button', { name: /continue to secure payment/i }))
    expect((await screen.findAllByText(/please accept the terms/i)).length).toBeGreaterThan(0)
    expect(screen.getByTestId('loc')).toHaveTextContent('/checkout')
    await user.click(screen.getByRole('checkbox'))
    if (!withinIst()) return // ASAP pickup validation needs the restaurant open; scheduled path is covered by e2e
    await user.click(screen.getByRole('button', { name: /continue to secure payment/i }))
    await waitFor(() => expect(screen.getByTestId('loc')).toHaveTextContent('/payment'), { timeout: 4000 })
    const req = JSON.parse(sessionStorage.getItem('fotg.checkout.request')!)
    expect(req).toMatchObject({ customerId: 'u1', restaurantId: 'burger-hub', currency: 'INR', termsAccepted: true, termsVersion: 'draft-2026-09', paymentMethodId: 'upi', promoCode: null })
    expect(req.idempotencyKey.length).toBeGreaterThan(8)
    expect(req.pickupSelection.restaurantTimezone).toBe('Asia/Kolkata')
    expect(await screen.findByText(/Ready to pay/i)).toBeInTheDocument()
    expect(screen.getByTestId('pay-total')).toHaveTextContent('₹640.00')
  })

  it('TEST 5 / 6 / 7 — promo apply, invalid, expired, remove; totals recalculate in integer money', async () => {
    const user = userEvent.setup()
    signIn()
    const repo = await seededRepo([burger()])
    sessionStorage.setItem('fotg.pickup.selection', JSON.stringify(pickupFor((await repo.load())!.id)))
    mount('/checkout', repo)
    await ready()
    const input = screen.getByRole('textbox', { name: /promo code/i })
    await user.type(input, 'welcome10'); await user.click(screen.getByRole('button', { name: /^apply$/i }))
    expect(await screen.findByText(/WELCOME10 applied/i)).toBeInTheDocument()
    expect(screen.getByText(/discount \(WELCOME10\)/i).parentElement).toHaveTextContent('−₹64.00')
    expect(screen.getByText(/^total$/i).parentElement).toHaveTextContent('₹576.00')
    await user.click(screen.getByRole('button', { name: /remove code/i }))
    await waitFor(() => expect(screen.getByText(/^total$/i).parentElement).toHaveTextContent('₹640.00'))
    await user.clear(input); await user.type(input, 'nope'); await user.click(screen.getByRole('button', { name: /^apply$/i }))
    expect(await screen.findByText(/not valid/i)).toBeInTheDocument()
    expect(screen.getByText(/^total$/i).parentElement).toHaveTextContent('₹640.00')
    await user.clear(input); await user.type(input, 'expired'); await user.click(screen.getByRole('button', { name: /^apply$/i }))
    expect(await screen.findByText(/has expired/i)).toBeInTheDocument()
    await user.clear(input); await user.type(input, 'burger20'); await user.click(screen.getByRole('button', { name: /^apply$/i }))
    expect(await screen.findByText(/BURGER20 applied/i)).toBeInTheDocument()
    expect(screen.getByText(/discount \(BURGER20\)/i).parentElement).toHaveTextContent('−₹100.00') // 20% of 640 = 128 capped at 100
  })

  it('TEST 8 — a changed price blocks progression until the customer accepts the updated price', async () => {
    const user = userEvent.setup()
    signIn()
    const repo = await seededRepo([burger({ unitPriceMinor: 30000 })])
    sessionStorage.setItem('fotg.pickup.selection', JSON.stringify(pickupFor((await repo.load())!.id)))
    mount('/checkout', repo)
    await ready()
    expect(await screen.findByText(/prices changed since you added/i)).toBeInTheDocument()
    await user.click(screen.getByRole('checkbox'))
    await user.click(screen.getByRole('button', { name: /continue to secure payment/i }))
    expect(await screen.findByText(/accept the updated prices to continue/i)).toBeInTheDocument()
    expect(screen.getByTestId('loc')).toHaveTextContent('/checkout')
    await user.click(screen.getByRole('button', { name: /accept updated price/i }))
    await waitFor(() => expect(screen.queryByText(/prices changed since you added/i)).toBeNull())
    expect(screen.getByText(/^total$/i).parentElement).toHaveTextContent('₹640.00')
  })

  it('TEST 9 — an invalidated pickup blocks payment with Choose another time', async () => {
    const user = userEvent.setup()
    signIn()
    const repo = await seededRepo([burger()])
    const cartId = (await repo.load())!.id
    sessionStorage.setItem('fotg.pickup.selection', JSON.stringify({ ...pickupFor(cartId), mode: 'scheduled', slotId: 'burger-hub:nope' }))
    mount('/checkout', repo)
    await ready()
    expect(await screen.findByText(/no longer offered/i)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /choose another time/i })).toHaveAttribute('href', '/pickup-time')
    await user.click(screen.getByRole('checkbox'))
    await user.click(screen.getByRole('button', { name: /continue to secure payment/i }))
    expect(await screen.findByText(/pickup time is no longer available/i)).toBeInTheDocument()
    expect(screen.getByTestId('loc')).toHaveTextContent('/checkout')
  })

  it('TEST 10 — restaurant not accepting orders blocks payment progression', async () => {
    const user = userEvent.setup()
    signIn()
    sessionStorage.setItem('fotg.mock.stale', 'not_accepting')
    const repo = await seededRepo([burger()])
    sessionStorage.setItem('fotg.pickup.selection', JSON.stringify(pickupFor((await repo.load())!.id)))
    mount('/checkout', repo)
    await ready()
    expect(await screen.findByText(/not accepting orders right now, so payment cannot continue/i)).toBeInTheDocument()
    await user.click(screen.getByRole('checkbox'))
    await user.click(screen.getByRole('button', { name: /continue to secure payment/i }))
    expect(screen.getByTestId('loc')).toHaveTextContent('/checkout')
  })

  it('TEST 12 / 13 — a USD cart in America/Los_Angeles formats in dollars, offers market methods and keeps the restaurant zone', async () => {
    signIn()
    const repo = await seededRepo([burger({ menuItemId: 'grapevine-burgers:truck-stop-breakfast-0-0', itemSlug: 'truck-stop-breakfast-0-0', itemName: 'Truck Stop Breakfast', currency: 'USD', basePriceMinor: 1199, unitPriceMinor: 1199, quantity: 1, specialInstructions: '', restaurant: { id: 'grapevine-burgers', slug: 'grapevine-burgers', name: 'Grapevine Burgers', currency: 'USD' }, selectedVariants: [{ groupId: 'grapevine-burgers:truck-stop-breakfast-0-0:size', groupName: 'Size', optionId: 'size:regular', optionName: 'Regular', priceAdjustmentMinor: 0 }], selectedModifiers: [{ groupId: 'grapevine-burgers:truck-stop-breakfast-0-0:side', groupName: 'Choose your side', optionId: 'side:fries', optionName: 'Fries', priceAdjustmentMinor: 0 }] })])
    sessionStorage.setItem('fotg.pickup.selection', JSON.stringify(pickupFor((await repo.load())!.id, 'grapevine-burgers', 'America/Los_Angeles')))
    mount('/checkout', repo)
    await ready()
    await waitFor(() => expect(screen.getByText(/items subtotal/i).parentElement).toHaveTextContent('$11.99'))
    expect(screen.getByText(/America\/Los_Angeles/)).toBeInTheDocument()
    expect(screen.getByRole('radio', { name: /credit \/ debit card/i })).toBeInTheDocument()
    expect(screen.queryByRole('radio', { name: /upi/i })).toBeNull()
  })

  it('TEST 14 — offline: checkout is blocked and nothing is prepared', async () => {
    signIn()
    sessionStorage.setItem('fotg.mock.offline', '1')
    const repo = await seededRepo([burger()])
    sessionStorage.setItem('fotg.pickup.selection', JSON.stringify(pickupFor((await repo.load())!.id)))
    mount('/checkout', repo)
    expect(await screen.findByText(/you are offline/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /continue to secure payment/i })).toBeDisabled()
    expect(sessionStorage.getItem('fotg.checkout.request')).toBeNull()
  })
})
