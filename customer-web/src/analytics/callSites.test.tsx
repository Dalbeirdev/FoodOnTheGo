/**
 * The ten call sites of the behavioural events (Module 38), each on the real page / provider / hook:
 *   - the event is recorded when the step happened, with backend references only, ONCE where the contract says once;
 *   - the page does exactly what it does anyway — whether the endpoint accepts the events, cannot be reached or
 *     answers with an error. Every scenario below runs under all three, with the same expectations of the page.
 * Only the analytics endpoint is on the (stubbed) network; any other request fails the test.
 */
import { act, cleanup, render, renderHook, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { API_BASE_URL, tokens } from '../api/client'
import { AuthProvider } from '../auth/AuthContext'
import { CartError, toCart, type ApiCartGateway, type CartDto } from '../cart/api/apiCart'
import { CartContext, useCart, type AddItemInput, type AddResult, type CartApi } from '../cart/cartApi'
import { ServerCartProvider } from '../cart/ServerCartProvider'
import { toCheckout, type ApiCheckoutGateway, type CheckoutDto } from '../checkout/api/apiCheckout'
import { CheckoutCtx, useCheckout, type CheckoutApi } from '../checkout/checkoutApi'
import type { CheckoutRequest } from '../checkout/repositories'
import { ServerCheckoutProvider } from '../checkout/ServerCheckoutProvider'
import { useDiscovery } from '../discovery/useDiscovery'
import { LocaleProvider } from '../i18n/LocaleProvider'
import { JourneyProvider } from '../journey/JourneyContext'
import { menuRepository } from '../menu/menuRepository'
import { setMockMenuLatency } from '../menu/mock/mockMenu'
import BackendPaymentPage from '../pages/BackendPaymentPage'
import ItemDetailPage from '../pages/ItemDetailPage'
import PickupTimePage from '../pages/PickupTimePage'
import PlanJourneyPage from '../pages/PlanJourneyPage'
import RestaurantDetailPage from '../pages/RestaurantDetailPage'
import { toPayment, type ApiPaymentGateway, type PaymentDto } from '../payment/api/apiPayment'
import type { ApiPickupGateway, PickupAvailabilityDto, PickupChoice, PickupSelectionDto } from '../pickup/api/apiPickup'
import { PickupCtx, type PickupApi } from '../pickup/pickupApi'
import { ServerPickupProvider } from '../pickup/ServerPickupProvider'
import { ProfileProvider } from '../profile/ProfileContext'
import { restaurantRepository } from '../repositories'
import { setMockRestaurantLatency } from '../repositories/mock/restaurants'
import type { JourneyLike, ResultPage } from '../repositories/types'
import { renderPage } from '../test/render'
import { flush, resetAnalyticsForTests } from './index'

const ENDPOINT = `${API_BASE_URL}/analytics/events`
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/
const R1 = '0a000000-0000-4000-8000-0000000000a1'
const R2 = '0a000000-0000-4000-8000-0000000000b2'
const ITEM = '1b000000-0000-4000-8000-000000000001'
const LINE = '2c000000-0000-4000-8000-000000000001'
const CART = '5f000000-0000-4000-8000-000000000001'
const J1 = '6e000000-0000-4000-8000-000000000001'
const J2 = '6e000000-0000-4000-8000-000000000002'
const CK = '9c000000-0000-4000-8000-000000000001'
const CK2 = '9c000000-0000-4000-8000-000000000002'
const PAY = '7d000000-0000-4000-8000-000000000001'
const TZ = 'Asia/Kolkata'
const FP = 'a'.repeat(64)
/** Fixture times are built around the moment the test runs: the pickup page shows them, it does not judge them. */
const NOW = Date.now()
const at = (minutes: number) => new Date(NOW + minutes * 60000).toISOString().replace(/\.\d{3}Z$/, '+00:00')
const local = (minutes: number) => `${new Date(NOW + minutes * 60000 + 330 * 60000).toISOString().slice(0, 19)}+05:30`
const inr = (amount: number) => ({ amount, currency: 'INR' })

type WireEvent = { id: string; name: string; occurred_at: string; refs: Record<string, string>; properties: Record<string, unknown> }
let batches: Array<{ session: string; platform: string; events: WireEvent[] }> = []
let unexpected: string[] = []
let endpoint: 202 | 500 | 'network' = 202
/** Every event offered to the endpoint so far, each once (a batch the network lost is offered a second time, with the same ids). */
const offered = () => [...new Map(batches.flatMap((b) => b.events).map((e) => [e.id, e])).values()]
/** Sends what is waiting and returns the events that were offered to the endpoint: [name, refs, properties]. */
const recorded = async () => { flush(); await act(async () => { await Promise.resolve() }); return offered().map((e) => [e.name, e.refs, e.properties]) }
const named = async (name: string) => (await recorded()).filter((e) => e[0] === name)

const cartDto = (restaurant: string = R1, quantity = 2, owner: CartDto['owner'] = 'guest'): CartDto => ({
  id: CART, version: 4, status: 'ACTIVE', owner, currency: 'INR', restaurant: { id: restaurant, slug: restaurant === R1 ? 'burger-hub' : 'pizza-point', name: restaurant === R1 ? 'Burger Hub' : 'Pizza Point', visible: true }, journey: null,
  items: [{
    id: LINE, item: { id: ITEM, slug: 'classic-burger', name: 'Classic Burger', image: null, version: 3 }, name: 'Classic Burger', quantity, min_quantity: 1, max_quantity: 20, special_instructions: null, variants: [], modifiers: [],
    base_price_minor: 20000, unit_price_minor: 20000, line_subtotal_minor: 20000 * quantity, currency: 'INR', snapshot: { unit_price_minor: 20000, base_price_minor: 20000, item_version: 3, taken_at: at(0) }, price_changed: false, priced_from: 'menu',
    included_in_subtotal: true, availability: { orderable: true, reason: null }, issues: [],
  }],
  item_count: quantity, line_count: 1, subtotal_minor: 20000 * quantity, validation: { status: 'VALID', can_proceed: true, issues: [], menu_changed: false, catalog_version: 7, checked_at: at(0) },
  expires_at: at(10000), created_at: at(-5), updated_at: at(-5),
})
const fakeCart = (owner: CartDto['owner'] = 'guest'): CartApi => ({
  mode: 'api', hydrated: true, cart: toCart(cartDto(R1, 2, owner)), count: 2, subtotalMinor: 40000, discountMinor: 0, currency: 'INR', lines: [], status: 'active', note: '', setNote: vi.fn(), promo: null,
  loadFailed: false, lastError: null, flash: null, conflict: null, lastAdded: null, reload: vi.fn(async () => {}), clearCart: vi.fn(),
} as unknown as CartApi)
const USER = { id: 'u1', name: 'Asha Verma', phone: '+919876543212', email: '', memberSince: '2026-01-01' }
const signIn = () => { localStorage.setItem('fotg.mock.customers', JSON.stringify([USER])); sessionStorage.setItem('fotg.mock.session', JSON.stringify({ token: 'dev', userId: 'u1', expiresAt: Date.now() + 3600000 })) }

describe.each([
  ['accepts the events', 202],
  ['cannot be reached', 'network'],
  ['answers with an error', 500],
] as const)('the call sites — when the endpoint %s', (_label, behaviour) => {
  beforeEach(() => {
    batches = []; unexpected = []; endpoint = behaviour
    localStorage.clear(); sessionStorage.clear(); tokens.set('customer', null)
    resetAnalyticsForTests(); setMockRestaurantLatency(0); setMockMenuLatency(0)
    localStorage.setItem('fotg.analytics.mode', 'api')
    vi.stubGlobal('fetch', vi.fn((url: string, init: RequestInit = {}) => {
      if (url !== ENDPOINT || init.method !== 'POST') { unexpected.push(`${init.method ?? 'GET'} ${url}`); return Promise.reject(new TypeError('No stub for this request')) }
      batches.push(JSON.parse(init.body as string))
      return endpoint === 'network' ? Promise.reject(new TypeError('Failed to fetch')) : Promise.resolve(new Response(endpoint === 202 ? '{"accepted":1,"duplicates":0,"rejected":[],"stored":true}' : 'Server Error', { status: endpoint }))
    }))
  })
  afterEach(() => {
    // Whatever was sent is the contract's body: one made-up session, WEB, events with ids — and nothing else on the network.
    for (const b of batches) { expect(Object.keys(b)).toEqual(['session', 'platform', 'events']); expect(b.session).toMatch(UUID); expect(b.platform).toBe('WEB'); for (const e of b.events) expect(e.id).toMatch(UUID) }
    expect(new Set(batches.map((b) => b.session)).size).toBeLessThanOrEqual(1)
    // An event is offered once — twice at most, when the network lost its batch. Never more.
    const times = new Map<string, number>(); for (const e of batches.flatMap((b) => b.events)) times.set(e.id, (times.get(e.id) ?? 0) + 1)
    expect(Math.max(0, ...times.values())).toBeLessThanOrEqual(behaviour === 'network' ? 2 : 1)
    expect(unexpected).toEqual([])
    cleanup(); resetAnalyticsForTests(); vi.unstubAllGlobals(); vi.restoreAllMocks()
  })

  it('1 · journey_search_started — one per submission of the journey form', async () => {
    const user = userEvent.setup()
    renderPage(<PlanJourneyPage />, { route: '/plan-journey' })
    const submit = () => screen.getByRole('button', { name: /find restaurants on route/i })
    expect(await recorded()).toEqual([])
    await user.click(submit())
    // The page goes on as ever: nothing was entered, so it asks for the two places.
    await waitFor(() => expect(screen.getAllByRole('alert').length).toBeGreaterThan(0))
    expect(submit()).toBeEnabled()
    await user.click(submit())
    expect(await recorded()).toEqual([['journey_search_started', {}, {}], ['journey_search_started', {}, {}]])
  })

  it('2 · journey_results_viewed — once per planned journey, with the number of results, also for an empty list', async () => {
    const journey = (id: string): JourneyLike => ({
      id, origin: { name: 'Delhi', lat: 28.6, lng: 77.2, countryCode: 'IN', timezone: TZ }, destination: { name: 'Jaipur', lat: 26.9, lng: 75.8, countryCode: 'IN', timezone: TZ }, departureAt: null,
      route: { geometry: [[28.6, 77.2], [26.9, 75.8]], distanceKm: 270, durationMin: 300 }, backend: true,
      search: { corridorM: 5000, corridorMinM: 1000, corridorMaxM: 20000, maxDetourMin: 15, maxDetourMaxMin: 60, candidateCap: 50, pageSize: 6, sorts: ['recommended'] },
    })
    const page = (total: number): ResultPage => ({ items: [], nextCursor: null, total, corridorM: 5000, emptyReason: total === 0 ? 'corridor' : null })
    const totals: Record<string, number> = { [J1]: 0, [J2]: 612 }
    const discover = vi.spyOn(restaurantRepository, 'getRestaurantsForJourney').mockImplementation(async (j) => page(totals[j.id]))
    const first = journey(J1), second = journey(J2)

    const hook = renderHook(({ j }) => useDiscovery(j, true), { initialProps: { j: first } })
    await waitFor(() => expect(hook.result.current.status).toBe('ready'))
    expect(hook.result.current).toMatchObject({ total: 0, emptyReason: 'corridor', items: [] })
    // Filters, sorting, a retry and "load more" ask the backend again; the list was viewed once.
    totals[J1] = 4
    await act(async () => { await hook.result.current.retry() })
    await act(async () => { await hook.result.current.loadMore() })
    expect(hook.result.current.total).toBe(4)
    expect(discover.mock.calls.length).toBeGreaterThanOrEqual(3)
    expect(await recorded()).toEqual([['journey_results_viewed', { journey: J1 }, { results: 0 }]])

    hook.rerender({ j: second })
    await waitFor(() => expect(hook.result.current.total).toBe(612))
    expect(await recorded()).toEqual([['journey_results_viewed', { journey: J1 }, { results: 0 }], ['journey_results_viewed', { journey: J2 }, { results: 500 }]])
  })

  it('2 · journey_results_viewed — nothing for a list without a planned journey of the backend', async () => {
    vi.spyOn(restaurantRepository, 'getRestaurants').mockResolvedValue({ items: [], nextCursor: null, total: 3, corridorM: null })
    const hook = renderHook(() => useDiscovery(null, true, { countryCode: 'IN', adminArea: 'Punjab', lat: null, lng: null, label: 'Punjab', source: 'manual' } as never))
    await waitFor(() => expect(hook.result.current.status).toBe('ready'))
    expect(await recorded()).toEqual([])
  })

  it('3 + 4 · restaurant_viewed and menu_viewed — once per restaurant, however often the page and its tabs are opened', async () => {
    const user = userEvent.setup()
    const view = renderPage(<RestaurantDetailPage />, { route: '/restaurants/burger-hub', path: '/restaurants/:id' })
    expect((await screen.findAllByText('Burger Hub')).length).toBeGreaterThan(0)
    expect((await screen.findAllByText('Classic Burger')).length).toBeGreaterThan(0)
    const restaurant = (await restaurantRepository.getRestaurantBySlug('burger-hub'))!
    const ref = UUID.test(restaurant.publicId) ? restaurant.publicId : 'burger-hub'
    const expected = [['restaurant_viewed', { restaurant: ref }, { origin: 'DIRECT' }], ['menu_viewed', { restaurant: ref }, {}]]
    // The page reports from effects that run after its text is on screen: the events are waited for, not read at once.
    await waitFor(async () => expect(await recorded()).toEqual(expected))

    await user.click(screen.getByRole('tab', { name: /info/i }))
    await user.click(screen.getByRole('tab', { name: /menu/i }))
    expect((await screen.findAllByText('Classic Burger')).length).toBeGreaterThan(0)
    view.unmount()
    renderPage(<RestaurantDetailPage />, { route: '/restaurants/burger-hub', path: '/restaurants/:id' })
    expect((await screen.findAllByText('Classic Burger')).length).toBeGreaterThan(0)
    expect(await recorded()).toEqual(expected)
  })

  it('5 · item_viewed — once per item, with the backend ids; an item without one is not reported', async () => {
    // The development menu has no backend ids: the first item below is given one, the second keeps its own.
    const detail = menuRepository.getItemDetail.bind(menuRepository)
    vi.spyOn(menuRepository, 'getItemDetail').mockImplementation(async (restaurantId, slug) => { const d = await detail(restaurantId, slug); return d && slug === 'tandoori-roti-2-0' ? { ...d, publicId: ITEM } : d })
    const open = (slug: string) => renderPage(<ItemDetailPage />, { route: `/restaurants/dhaba-junction-ropar/item/${slug}`, path: '/restaurants/:rid/item/:itemId' })

    const first = open('tandoori-roti-2-0')
    expect(await screen.findByRole('heading', { level: 1 })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /add to cart/i })).toBeEnabled()
    expect(await recorded()).toEqual([['item_viewed', { restaurant: 'dhaba-junction-ropar', item: ITEM }, {}]])
    first.unmount()
    const again = open('tandoori-roti-2-0')
    expect(await screen.findByRole('heading', { level: 1 })).toBeInTheDocument()
    again.unmount()

    const other = (await menuRepository.getItems('dhaba-junction-ropar', { limit: 12 })).items.find((i) => i.slug !== 'tandoori-roti-2-0')!
    open(other.slug)
    expect(await screen.findByRole('heading', { level: 1, name: other.name })).toBeInTheDocument()
    expect(await recorded()).toEqual([['item_viewed', { restaurant: 'dhaba-junction-ropar', item: ITEM }, {}]])
  })

  it('6 · item_added_to_cart — one per add the backend accepted (also with a replaced cart); not for an edit, not for a refusal', async () => {
    class Gateway {
      cart: CartDto | null = null
      refuse: CartError | null = null
      log: string[] = []
      private answer(lineId: string | null) { return { cart: toCart(this.cart), lineId, merged: false, replaced: null, priceChangesAccepted: 0 } }
      async load() { return toCart(this.cart) }
      async add(restaurant: string, item: { quantity: number }) { this.log.push('add'); if (this.refuse) throw this.refuse; this.cart = cartDto(restaurant, item.quantity); return this.answer(LINE) }
      async update(lineId: string, change: { quantity?: number }) { this.log.push('update'); this.cart = cartDto(this.cart!.restaurant.id, change.quantity ?? 1); return this.answer(lineId) }
      async switchRestaurant(restaurant: string, item: { quantity: number }) { this.log.push('switch'); this.cart = cartDto(restaurant, item.quantity); return this.answer(LINE) }
      hasGuestToken() { return false }
      useSession() { /* a guest */ }
    }
    const input = (patch: Partial<AddItemInput> = {}): AddItemInput => ({
      menuItemId: ITEM, itemSlug: 'classic-burger', itemName: 'Classic Burger', image: '', fallback: 'CL', basePriceMinor: 20000, currency: 'INR', restaurant: { id: 'burger-hub', slug: 'burger-hub', name: 'Burger Hub', currency: 'INR', publicId: R1 },
      selectedVariants: [], selectedModifiers: [], specialInstructions: 'No onions, please', quantity: 2, unitPriceMinor: 20000, ...patch,
    } as AddItemInput)
    const gateway = new Gateway()
    const wrapper = ({ children }: { children: ReactNode }) => (
      <MemoryRouter><LocaleProvider locale="en-IN"><AuthProvider><JourneyProvider><ServerCartProvider gateway={gateway as unknown as ApiCartGateway}>{children}</ServerCartProvider></JourneyProvider></AuthProvider></LocaleProvider></MemoryRouter>
    )
    const { result } = renderHook(() => useCart(), { wrapper })
    await waitFor(() => expect(result.current.hydrated).toBe(true))

    let answer: AddResult | undefined
    await act(async () => { answer = await result.current.addItem(input()) })
    expect(answer).toMatchObject({ ok: true })
    expect(result.current.count).toBe(2)
    expect(await recorded()).toEqual([['item_added_to_cart', { restaurant: R1, item: ITEM }, { quantity: 2 }]])

    // Editing the line is not an add.
    await act(async () => { answer = await result.current.editItem(LINE, input({ quantity: 5 })) })
    expect(answer).toMatchObject({ ok: true }); expect(result.current.count).toBe(5)
    // An add the backend refused is not one either.
    gateway.refuse = new CartError('item_unavailable', 'Classic Burger is sold out.')
    await act(async () => { answer = await result.current.addItem(input()) })
    expect(answer).toMatchObject({ ok: false, reason: 'unavailable', message: 'Classic Burger is sold out.' })
    gateway.refuse = null
    expect(await named('item_added_to_cart')).toHaveLength(1)

    // Another restaurant: the customer is asked first; the add counts once the backend replaced the cart.
    const pizza = input({ quantity: 1, restaurant: { id: 'pizza-point', slug: 'pizza-point', name: 'Pizza Point', currency: 'INR', publicId: R2 } })
    await act(async () => { answer = await result.current.addItem(pizza) })
    expect(answer).toMatchObject({ ok: false, reason: 'restaurant_conflict' })
    expect(await named('item_added_to_cart')).toHaveLength(1)
    act(() => { result.current.confirmReplace() })
    await waitFor(() => expect(result.current.cart?.server?.restaurantPublicId).toBe(R2))
    expect(gateway.log).toEqual(['add', 'update', 'add', 'switch'])
    const all = await recorded()
    expect(all).toEqual([['item_added_to_cart', { restaurant: R1, item: ITEM }, { quantity: 2 }], ['item_added_to_cart', { restaurant: R2, item: ITEM }, { quantity: 1 }]])
    expect(JSON.stringify(batches)).not.toMatch(/onions|Burger|20000|INR/) // no note, no name, no amount
  })

  it('7 + 8 · pickup_viewed once per restaurant; pickup_selected for every choice the backend accepted — and none it refused', async () => {
    const slot = (minutes: number, patch: Partial<PickupAvailabilityDto['slots'][number]> = {}): PickupAvailabilityDto['slots'][number] => ({
      slot_key: `ps1_${minutes}`, starts_at: at(minutes), ends_at: at(minutes + 15), local_starts_at: local(minutes), local_date: local(30).slice(0, 10), available: true, capacity_state: 'AVAILABLE', recommended: false, reason: null, arrival_vs_pickup_delta_seconds: null, ...patch,
    })
    const availability: PickupAvailabilityDto = {
      restaurant: { id: R1, slug: 'burger-hub', name: 'Burger Hub', timezone: TZ }, timezone: TZ, cart_version: 4, pickup_enabled: true, can_select: true,
      methods: [{ method: 'COUNTER', instructions: null, requires_vehicle_info: false }], method: 'COUNTER', preparation: { estimated_minutes: 20, buffer_minutes: 0, minimum_lead_minutes: 15, ready_in_minutes: 20, is_estimate: true }, earliest_pickup_at: at(20),
      asap: { enabled: true, available: true, pickup_at: at(20), local_pickup_at: local(20), estimated_ready_in_minutes: 20, recommended: true, reason: null, arrival_vs_pickup_delta_seconds: null },
      scheduled: { enabled: true, interval_minutes: 15, horizon_minutes: 1440, horizon_ends_at: at(1440), slot_count: 2, available_slot_count: 2, truncated: false }, slots: [slot(30), slot(45)],
      recommended: { mode: 'ASAP', slot_key: null, pickup_at: at(20), local_pickup_at: local(20), basis: 'KITCHEN', arrival_vs_pickup_delta_seconds: null }, journey: null, selection: null, issues: [], generated_at: at(0), revalidate_after: at(1),
    }
    const selection = (choice: PickupChoice): PickupSelectionDto => ({
      id: '7a000000-0000-4000-8000-000000000001', mode: choice.mode === 'asap' ? 'ASAP' : 'SCHEDULED', method: 'COUNTER', pickup_at: choice.mode === 'asap' ? at(20) : at(30), local_pickup_at: choice.mode === 'asap' ? local(20) : local(30), local_date: local(30).slice(0, 10),
      slot_key: choice.slotKey ?? null, slot_ends_at: choice.mode === 'asap' ? null : at(45), timezone: TZ, cart_version: 4, status: 'SELECTED', valid: true, reserved: false, issues: [], estimated_prep_minutes: 20, traveler_arrival_at: null,
      arrival_vs_pickup_delta_seconds: null, selected_at: at(0), validated_at: at(0), expires_at: at(75), revalidate_after: at(1), version: 1,
    })
    class Gateway {
      stored: PickupSelectionDto | null = null
      refuse = false
      async availability() { return { ...availability, selection: this.stored } }
      async selection() { return this.stored }
      async select(choice: PickupChoice) { if (this.refuse) throw new Error('This time has just filled up.'); this.stored = selection(choice); return this.stored }
      async clear() { this.stored = null }
    }
    const gateway = new Gateway()
    const user = userEvent.setup()
    const page = () => render(
      <MemoryRouter initialEntries={['/pickup-time']}><LocaleProvider locale="en-IN"><AuthProvider><JourneyProvider><CartContext.Provider value={fakeCart()}><ServerPickupProvider gateway={gateway as unknown as ApiPickupGateway}>
        <Routes><Route path="/pickup-time" element={<PickupTimePage />} /><Route path="*" element={<p>other page</p>} /></Routes>
      </ServerPickupProvider></CartContext.Provider></JourneyProvider></AuthProvider></LocaleProvider></MemoryRouter>,
    )
    const view = page()
    await user.click(await screen.findByTestId('pk-asap-choose'))
    await waitFor(() => expect(screen.getByTestId('pk-summary')).toHaveAttribute('data-mode', 'asap'))
    expect(await recorded()).toEqual([['pickup_viewed', { restaurant: R1 }, {}], ['pickup_selected', { restaurant: R1 }, { mode: 'ASAP', method: 'COUNTER', recommended: true }]])

    await user.click(screen.getByRole('radio', { name: /Schedule for later/i }))
    await user.click(screen.getAllByTestId('pk-slot')[0])
    await waitFor(() => expect(screen.getByTestId('pk-summary')).toHaveAttribute('data-mode', 'scheduled'))
    expect(await named('pickup_selected')).toEqual([
      ['pickup_selected', { restaurant: R1 }, { mode: 'ASAP', method: 'COUNTER', recommended: true }],
      ['pickup_selected', { restaurant: R1 }, { mode: 'SCHEDULED', method: 'COUNTER', recommended: false }],
    ])

    // A choice the backend refuses is told to the customer as ever, and is not an event.
    gateway.refuse = true
    await user.click(screen.getAllByTestId('pk-slot')[1])
    expect(await screen.findByTestId('pk-notice')).toBeInTheDocument()
    expect(screen.getByTestId('pk-summary')).toHaveAttribute('data-mode', 'scheduled')
    expect(await named('pickup_selected')).toHaveLength(2)

    // Opening the page again in the session is not a second view.
    view.unmount()
    page()
    expect(await screen.findByTestId('pk-summary')).toBeInTheDocument()
    expect(await named('pickup_viewed')).toEqual([['pickup_viewed', { restaurant: R1 }, {}]])
  })

  it('9 · checkout_started — once per checkout the backend opened, however often it is asked for', async () => {
    const LEGAL = [{ type: 'TERMS', version: 'draft-2026-09', effective_date: null, status: 'DRAFT_PENDING_APPROVAL', slug: 'terms' }, { type: 'PRIVACY', version: 'draft-2026-09', effective_date: null, status: 'DRAFT_PENDING_APPROVAL', slug: 'privacy' }]
    const checkoutDto = (id: string): CheckoutDto => ({
      id, status: 'DRAFT', status_reason: null, version: 1, expires_at: at(15), server_time: at(0), created_at: at(0), cart_version: 4, restaurant: { id: R1, slug: 'burger-hub', name: 'Burger Hub', timezone: TZ },
      pickup: { mode: 'SCHEDULED', method: 'COUNTER', pickup_at: at(75), local_pickup_at: local(75), local_date: local(75).slice(0, 10), slot_starts_at: at(75), slot_ends_at: at(90), timezone: TZ, estimated_prep_minutes: 20, traveler_arrival_at: null, hold: { held: false, expires_at: null } },
      items: [{ id: LINE, item: { id: ITEM, slug: 'classic-burger', name: 'Classic Burger', image: null, version: 3 }, name: 'Classic Burger', quantity: 2, special_instructions: null, variants: [], modifiers: [], unit_price_minor: 20000, line_subtotal_minor: 40000, currency: 'INR' }],
      item_count: 2,
      pricing: { currency: 'INR', items_subtotal: inr(40000), discounts: [], discount_total: inr(0), taxes: [], tax_total: inr(0), tax_included_total: inr(0), fees: [], fee_total: inr(0), total: inr(40000), payment_required: true, fingerprint: FP, configuration: { tax: 'NOT_CONFIGURED', fees: 'NOT_CONFIGURED' } },
      promotion: null, customer: { name: 'Asha Verma', phone: '+919876543212', phone_verified: true, email: null }, order_note: null, legal: { required: LEGAL, accepted: [], final_approval: false },
      payment_methods: { provider_status: 'READY', available: [{ type: 'UPI', status: 'PLANNED' }, { type: 'CARD', status: 'PLANNED' }], saved: [], selected: null, cash_at_pickup: 'NOT_APPROVED' }, payment: null, issues: [], changes: [],
    } as CheckoutDto)
    const gateway = { id: CK, open: vi.fn(async () => toCheckout(checkoutDto(gateway.id))) }
    const pickup = { source: 'api', status: 'SELECTED', ready: true, busy: false, selection: null, clear: vi.fn(), validate: vi.fn(async () => ({ ok: true })) } as unknown as PickupApi
    const cart = fakeCart('customer')
    signIn()
    const wrapper = ({ children }: { children: ReactNode }) => (
      <LocaleProvider><AuthProvider><ProfileProvider><JourneyProvider><CartContext.Provider value={cart}><PickupCtx.Provider value={pickup}>
        <ServerCheckoutProvider gateway={gateway as unknown as ApiCheckoutGateway}>{children}</ServerCheckoutProvider>
      </PickupCtx.Provider></CartContext.Provider></JourneyProvider></ProfileProvider></AuthProvider></LocaleProvider>
    )
    const { result } = renderHook(() => useCheckout(), { wrapper })
    // The session is restored a moment after the first render: checkout starts once the customer is known.
    await waitFor(async () => { await act(async () => { await result.current.prepare() }); expect(result.current.status).toBe('READY') })
    await act(async () => { await result.current.prepare() }) // the cart, the pickup time or the tab changed: asked again
    await act(async () => { await result.current.prepare() })
    expect(gateway.open.mock.calls.length).toBeGreaterThanOrEqual(3)
    expect(result.current).toMatchObject({ status: 'READY', server: { id: CK, pricing: { totalMinor: 40000 } } })
    expect(await recorded()).toEqual([['checkout_started', { checkout: CK, restaurant: R1 }, {}]])

    gateway.id = CK2 // the backend replaced the checkout (something changed): that one is new
    await act(async () => { await result.current.prepare() })
    expect(result.current.server?.id).toBe(CK2)
    const all = await recorded()
    expect(all).toEqual([['checkout_started', { checkout: CK, restaurant: R1 }, {}], ['checkout_started', { checkout: CK2, restaurant: R1 }, {}]])
    expect(JSON.stringify(batches)).not.toMatch(/Asha|9876543212|40000|INR/) // no name, no phone, no amount
  })

  it('10 · payment_started — once per checkout: paying again after a cancelled attempt is not a second start', async () => {
    const paymentDto: PaymentDto = {
      id: PAY, checkout_id: CK, attempt_number: 1, status: 'CANCELLED', amount: inr(40000), provider: { name: 'fake', mode: 'FAKE' }, requested_method_type: 'UPI', method: null,
      failure: { category: 'CUSTOMER_CANCELLED', code: 'PAYMENT_CANCELLED', message: 'The payment was cancelled. Nothing was charged.', retryable: true }, message: 'The payment was cancelled. Nothing was charged.',
      next_action: 'RETRY_PAYMENT', retry_after_seconds: null, provider_session: null, fulfillment: { status: 'NOT_APPLICABLE', order_id: null, order_number: null }, support_reference: 'PAY-7D00000000',
      expires_at: at(15), server_time: at(0), created_at: at(0), updated_at: at(0), verified_at: null,
    }
    const gateway = { start: vi.fn(async () => toPayment(paymentDto)), show: vi.fn(async () => toPayment(paymentDto)) }
    const request = {
      idempotencyKey: `pay_${'c'.repeat(40)}`, customerId: 'u1', cartId: 'cart-1', restaurantId: 'burger-hub',
      pickupSelection: { mode: 'scheduled', slotId: 'k', requestedAt: at(75), restaurantTimezone: TZ, estimatedCustomerArrival: null, estimatedReadyTime: at(75), confirmedDisplayTime: '', cartId: 'cart-1', restaurantId: 'burger-hub' },
      promoCode: null, currency: 'INR', orderNote: '', termsAccepted: true, termsVersion: 'draft-2026-09', privacyVersion: 'draft-2026-09', acceptedAt: at(0), paymentMethodId: 'upi', displayedTotalMinor: 40000, createdAt: at(0), checkoutId: CK, pricingFingerprint: FP,
    } as CheckoutRequest
    const checkout = { source: 'api', status: 'PAYMENT_READY', restaurant: null, online: true, request, server: null, clearRequest: vi.fn(), revalidate: async () => true } as unknown as CheckoutApi
    const cart = { mode: 'api', cart: { restaurantName: 'Burger Hub', items: [{ quantity: 2 }] }, hydrated: true, count: 2 } as unknown as CartApi
    const user = userEvent.setup()
    signIn(); tokens.set('customer', 'customer-session')
    render(
      <LocaleProvider><AuthProvider><ProfileProvider><JourneyProvider><CartContext.Provider value={cart}><CheckoutCtx.Provider value={checkout}>
        <MemoryRouter initialEntries={['/payment']}><Routes><Route path="/payment" element={<BackendPaymentPage gateway={gateway as unknown as ApiPaymentGateway} />} /><Route path="*" element={<p>other page</p>} /></Routes></MemoryRouter>
      </CheckoutCtx.Provider></CartContext.Provider></JourneyProvider></ProfileProvider></AuthProvider></LocaleProvider>,
    )
    const main = () => screen.getByTestId('pay-backend')
    await waitFor(() => expect(screen.getByTestId('pay-submit')).toBeEnabled())
    expect(await recorded()).toEqual([]) // looking at the payment page is not a payment started

    await user.click(screen.getByTestId('pay-submit'))
    await waitFor(() => expect(main()).toHaveAttribute('data-view', 'CANCELLED'))
    expect(screen.getByTestId('pay-text')).toHaveTextContent('The payment was cancelled. Nothing was charged.')
    expect(screen.getByTestId('pay-total')).toHaveAttribute('data-amount', '40000')
    expect(await recorded()).toEqual([['payment_started', { checkout: CK }, { method: 'UPI' }]])

    await user.click(screen.getByTestId('pay-retry'))
    await waitFor(() => expect(gateway.start).toHaveBeenCalledTimes(2))
    await waitFor(() => expect(screen.getByTestId('pay-retry')).toBeEnabled())
    expect(main()).toHaveAttribute('data-view', 'CANCELLED')
    expect(gateway.start.mock.calls.map((c) => c.slice(0, 3))).toEqual([[CK, FP, 'UPI'], [CK, FP, 'UPI']])
    expect(await recorded()).toEqual([['payment_started', { checkout: CK }, { method: 'UPI' }]])
    expect(JSON.stringify(batches)).not.toMatch(/customer-session|40000|INR|Asha/) // the token travels in a header only; no amount, no name
    tokens.set('customer', null)
  })
})
