import { act, render, renderHook, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it } from 'vitest'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import PickupTimePage from './PickupTimePage'
import { CartProvider, useCart, type AddItemInput } from '../cart/CartContext'
import { MemoryCartRepository } from '../cart/cartModel'
import { AuthProvider } from '../auth/AuthContext'
import { JourneyProvider } from '../journey/JourneyContext'
import { PickupProvider, usePickup } from '../pickup/PickupContext'
import { setMockPickupLatency } from '../pickup/mock/mockPickup'
import { LocaleProvider } from '../i18n/LocaleProvider'
import { setMockMenuLatency } from '../menu/mock/mockMenu'
import { setMockRestaurantLatency } from '../repositories/mock/restaurants'

/** Module 10 — Pickup time page (web). Component-level TEST 1, 2, 4, 6, 7, 8 + state preservation. */
const burger = (over: Partial<AddItemInput> = {}): AddItemInput => ({
  menuItemId: 'classic-burger', itemSlug: 'classic-burger', itemName: 'Classic Burger', image: '', fallback: '', basePriceMinor: 25000, currency: 'INR',
  restaurant: { id: 'burger-hub', slug: 'burger-hub', name: 'Burger Hub', currency: 'INR' },
  selectedVariants: [{ groupId: 'classic-burger:size', groupName: 'Size', optionId: 'size:regular', optionName: 'Regular', priceAdjustmentMinor: 0 }],
  selectedModifiers: [], specialInstructions: '', quantity: 1, unitPriceMinor: 25000, maximumQuantity: 20, ...over,
})
function Loc() { const l = useLocation(); return <output data-testid="loc">{l.pathname}</output> }
function PickupProbe() { const p = usePickup(); return <output data-testid="pk">{JSON.stringify({ status: p.status, mode: p.selection?.mode ?? null, slot: p.selection?.slotId ?? null, at: p.selection?.requestedAt ?? null })}</output> }
const probe = () => JSON.parse(screen.getAllByTestId('pk').slice(-1)[0].textContent || '{}') as { status: string; mode: string | null; slot: string | null; at: string | null }

function mount(path: string, repo = new MemoryCartRepository()) {
  return render(
    <LocaleProvider><AuthProvider><JourneyProvider><CartProvider repository={repo}><PickupProvider>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/pickup-time" element={<PickupTimePage />} />
          <Route path="/checkout" element={<p>checkout page</p>} />
          <Route path="/cart" element={<p>cart page</p>} />
          <Route path="*" element={<p>other</p>} />
        </Routes>
        <Loc /><PickupProbe />
      </MemoryRouter>
    </PickupProvider></CartProvider></JourneyProvider></AuthProvider></LocaleProvider>,
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
const withinIst = () => { const d = new Date(); const m = d.getUTCHours() * 60 + d.getUTCMinutes(); return m >= 150 && m < 1080 } // Burger Hub is open 08:00–23:30 IST = 02:30–18:00 UTC

describe('Pickup time page (web)', () => {
  beforeEach(() => { setMockPickupLatency(0); setMockMenuLatency(0); setMockRestaurantLatency(0); localStorage.clear(); sessionStorage.clear() })

  it('redirects to the cart when it is empty', async () => {
    mount('/pickup-time')
    await waitFor(() => expect(screen.getByTestId('loc')).toHaveTextContent('/cart'))
  })

  it('TEST 4 — no journey: context, restaurant zone, ASAP / scheduled modes and slots load', async () => {
    const repo = await seededRepo([burger()])
    mount('/pickup-time', repo)
    expect(await screen.findByRole('heading', { level: 2, name: 'Burger Hub' })).toBeInTheDocument()
    expect(screen.getByText(/no journey planned/i)).toBeInTheDocument()
    expect(await screen.findByText(/Asia\/Kolkata/)).toBeInTheDocument()
    await waitFor(() => expect(['AVAILABLE', 'SELECTED']).toContain(probe().status))
    expect(screen.getByRole('radio', { name: /as soon as possible/i })).toBeInTheDocument()
    expect(screen.getByRole('radio', { name: /schedule for later/i })).toBeInTheDocument()
    expect(screen.getByText(/pickup instructions/i)).toHaveTextContent(/pickup counter/i)
    expect(screen.getByRole('button', { name: /continue to checkout/i })).toBeDisabled()
  })

  it('TEST 1 — ASAP: earliest estimate shown, selecting it fills the summary and enables Continue (when open)', async () => {
    const user = userEvent.setup()
    const repo = await seededRepo([burger()])
    mount('/pickup-time', repo)
    await screen.findByRole('heading', { level: 2, name: 'Burger Hub' })
    await waitFor(() => expect(['AVAILABLE', 'SELECTED']).toContain(probe().status))
    if (!withinIst()) { expect(screen.getByText(/not taking orders for immediate pickup/i)).toBeInTheDocument(); return }
    expect(screen.getByText(/ready in about/i)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: /pick up as soon as possible/i }))
    expect(probe()).toMatchObject({ status: 'SELECTED', mode: 'asap' })
    expect(screen.getByText(/estimated ready/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /continue to checkout/i })).toBeEnabled()
    await user.click(screen.getByRole('button', { name: /continue to checkout/i }))
    await waitFor(() => expect(screen.getByTestId('loc')).toHaveTextContent('/checkout'))
    expect(JSON.parse(sessionStorage.getItem('fotg.pickup.selection')!)).toMatchObject({ mode: 'asap', restaurantId: 'burger-hub' })
  })

  it('TEST 2 / 7 — scheduled: date chips, data-driven slots, full slots disabled, selection + Continue → checkout', async () => {
    const user = userEvent.setup()
    const repo = await seededRepo([burger()])
    mount('/pickup-time', repo)
    await screen.findByRole('heading', { level: 2, name: 'Burger Hub' })
    await waitFor(() => expect(['AVAILABLE', 'SELECTED']).toContain(probe().status))
    await user.click(screen.getByRole('radio', { name: /schedule for later/i }))
    const days = screen.getByRole('radiogroup', { name: /pickup date/i })
    expect(within(days).getAllByRole('radio').length).toBeGreaterThanOrEqual(2)
    expect(within(days).getByRole('radio', { name: /today/i })).toBeChecked()
    // choose a day with a full slot list: tomorrow always has the whole schedule
    await user.click(within(days).getAllByRole('radio')[1])
    const slotsGroup = await screen.findByRole('radiogroup', { name: /pickup time/i })
    const slots = within(slotsGroup).getAllByRole('radio')
    expect(slots.length).toBeGreaterThan(10)
    const full = slots.find((s) => /full/i.test(s.getAttribute('aria-label') || ''))!
    expect(full).toBeDisabled()
    expect(within(slotsGroup).getByText(/recommended/i)).toBeInTheDocument()
    const pick = slots.find((s) => !s.hasAttribute('disabled'))!
    await user.click(pick)
    expect(pick).toHaveAttribute('aria-checked', 'true')
    expect(probe()).toMatchObject({ status: 'SELECTED', mode: 'scheduled' })
    expect(probe().slot).toMatch(/^burger-hub:/)
    await user.click(screen.getByRole('button', { name: /continue to checkout/i }))
    await waitFor(() => expect(screen.getByTestId('loc')).toHaveTextContent('/checkout'))
  })

  it('TEST 8 — stale slot: validation fails, customer must choose another time (nothing substituted)', async () => {
    const user = userEvent.setup()
    const repo = await seededRepo([burger()])
    mount('/pickup-time', repo)
    await screen.findByRole('heading', { level: 2, name: 'Burger Hub' })
    await waitFor(() => expect(['AVAILABLE', 'SELECTED']).toContain(probe().status))
    await user.click(screen.getByRole('radio', { name: /schedule for later/i }))
    await user.click(within(screen.getByRole('radiogroup', { name: /pickup date/i })).getAllByRole('radio')[1])
    const slotsGroup = await screen.findByRole('radiogroup', { name: /pickup time/i })
    const pick = within(slotsGroup).getAllByRole('radio').find((s) => !s.hasAttribute('disabled'))!
    await user.click(pick)
    const chosen = probe().slot
    sessionStorage.setItem('fotg.mock.stale', 'slot')
    await user.click(screen.getByRole('button', { name: /continue to checkout/i }))
    expect(await screen.findByText(/no longer available/i)).toBeInTheDocument()
    expect(probe().status).toBe('STALE')
    expect(probe().slot).toBe(chosen) // never silently substituted
    expect(screen.getByTestId('loc')).toHaveTextContent('/pickup-time')
    sessionStorage.removeItem('fotg.mock.stale')
    await user.click(screen.getByRole('button', { name: /choose another time/i }))
    await waitFor(() => expect(probe().status).toBe('AVAILABLE'))
    expect(probe().slot).toBeNull()
  })

  it('TEST 6 — restaurant not accepting orders shows a distinct blocking state (no slots offered)', async () => {
    sessionStorage.setItem('fotg.mock.stale', 'not_accepting')
    const repo = await seededRepo([burger()])
    mount('/pickup-time', repo)
    expect((await screen.findAllByRole('heading', { level: 2 })).length).toBeGreaterThan(0)
    await waitFor(() => expect(screen.getByRole('main').textContent!.slice(0, 900)).toMatch(/not accepting orders right now, so no pickup time/i), { timeout: 3000 })
    expect(screen.queryByRole('radiogroup', { name: /pickup mode/i })).toBeNull()
    expect(screen.getByRole('button', { name: /continue to checkout/i })).toBeDisabled()
  })

  it('TEST 11 (state) — a stored selection survives a remount (login round trip) and is dropped for another cart', async () => {
    const repo = await seededRepo([burger()])
    sessionStorage.setItem('fotg.pickup.selection', JSON.stringify({ mode: 'asap', slotId: null, requestedAt: new Date(Date.now() + 20 * 60000).toISOString(), restaurantTimezone: 'Asia/Kolkata', estimatedCustomerArrival: null, estimatedReadyTime: new Date(Date.now() + 20 * 60000).toISOString(), confirmedDisplayTime: '', cartId: (await repo.load())!.id, restaurantId: 'burger-hub' }))
    mount('/pickup-time', repo)
    await screen.findByRole('heading', { level: 2, name: 'Burger Hub' })
    await waitFor(() => expect(probe()).toMatchObject({ status: 'SELECTED', mode: 'asap' }))
    expect(screen.getByText(/estimated ready/i)).toBeInTheDocument()
    // another cart → selection dropped
    const other = await seededRepo([burger({ menuItemId: 'french-fries', itemSlug: 'french-fries', itemName: 'French Fries' })])
    mount('/pickup-time', other)
    await waitFor(() => expect(sessionStorage.getItem('fotg.pickup.selection')).toBeNull())
  })
})
