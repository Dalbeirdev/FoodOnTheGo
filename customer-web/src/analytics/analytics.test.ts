/**
 * The behavioural-event emitter of the Customer Web (Module 38): when it is on, the session it makes up, what leaves
 * and in which shape, what never leaves, and that nothing it does — or fails to do — reaches a page.
 * The network is a stub; the clock is a fake one.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { API_BASE_URL, tokens } from '../api/client'
import { orderMode } from '../order/orderMode'
import {
  EVENTS, FLUSH_DELAY_MS, MAX_BATCH, MAX_QUEUE, RETRY_DELAY_MS, SESSION_IDLE_MS, SESSION_MAX_AGE_MS,
  analyticsConsent, analyticsEnabled, analyticsMode, flush, paymentMethodOf, resetAnalyticsForTests, restaurantRef, setAnalyticsConsent, track,
} from './index'
import { admit } from './registry'

const ENDPOINT = `${API_BASE_URL}/analytics/events`
const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/
/** What the backend takes as the time of an event (EventIngestService::parse). */
const SERVER_TIME = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/
const R1 = '0a000000-0000-4000-8000-0000000000a1'
const J1 = '6e000000-0000-4000-8000-000000000001'
const I1 = '1b000000-0000-4000-8000-000000000001'
const CK = '9c000000-0000-4000-8000-000000000001'
const START = Date.parse('2026-10-13T09:15:00.000Z')

type WireEvent = { id: string; name: string; occurred_at: string; refs: Record<string, string>; properties: Record<string, unknown> }
type Sent = { url: string; init: RequestInit; headers: Record<string, string>; body: { session: string; platform: string; events: WireEvent[] } & Record<string, unknown> }
let sent: Sent[] = []
/** What the endpoint does with the next requests: an HTTP status, or 'network' for a request that never arrives. */
let answer: (n: number) => number | 'network' | 'throws' | 'garbage' = () => 202
const respond = (url: string, init: RequestInit = {}) => {
  sent.push({ url, init, headers: (init.headers ?? {}) as Record<string, string>, body: JSON.parse(init.body as string) })
  const a = answer(sent.length)
  if (a === 'throws') throw new Error('fetch is not usable')
  if (a === 'network') return Promise.reject(new TypeError('Failed to fetch'))
  if (a === 'garbage') return undefined as unknown as Promise<Response>
  return Promise.resolve(new Response(a === 202 ? JSON.stringify({ accepted: 1, duplicates: 0, rejected: [], stored: true, session: { idle_minutes: 30, max_hours: 12 } }) : '<html>not json</html>', { status: a }))
}
const fetchStub = vi.fn(respond)
const on = () => localStorage.setItem('fotg.analytics.mode', 'api')
/** Lets the promises of the requests that were made settle. */
const settle = () => vi.advanceTimersByTimeAsync(0)
const events = () => sent.flatMap((s) => s.body.events)
const sessionNow = () => (JSON.parse(sessionStorage.getItem('fotg.analytics.session') ?? 'null') as { id: string; startedAt: number; lastActivityAt: number } | null)

beforeEach(() => {
  sent = []; answer = () => 202; fetchStub.mockReset(); fetchStub.mockImplementation(respond)
  localStorage.clear(); sessionStorage.clear(); tokens.set('customer', null)
  resetAnalyticsForTests()
  vi.useFakeTimers(); vi.setSystemTime(START)
  vi.stubGlobal('fetch', fetchStub)
})
afterEach(() => { resetAnalyticsForTests(); vi.useRealTimers(); vi.unstubAllGlobals(); vi.unstubAllEnvs(); vi.restoreAllMocks() })

/* ------------------------------------------------------------------ mode */
describe('when the events are on', () => {
  it('is off in unit tests — whatever the other modes say: nothing is queued, stored or requested', async () => {
    expect(analyticsMode()).toBe('off')
    for (const k of ['market', 'restaurant', 'journey', 'cart', 'pickup', 'checkout', 'payment', 'order']) localStorage.setItem(`fotg.${k}.mode`, 'api')
    expect(orderMode()).toBe('api')
    expect([analyticsMode(), analyticsEnabled()]).toEqual(['off', false])

    expect(track('journey_search_started')).toBe(false)
    expect(track('restaurant_viewed', { restaurant: R1 }, { origin: 'DIRECT' }, `restaurant_viewed:${R1}`)).toBe(false)
    flush()
    await vi.advanceTimersByTimeAsync(60_000)
    document.dispatchEvent(new Event('visibilitychange')); window.dispatchEvent(new Event('pagehide'))
    await settle()
    expect(fetchStub).not.toHaveBeenCalled()
    expect(sessionStorage.getItem('fotg.analytics.session')).toBeNull()
    expect(sessionStorage.getItem('fotg.analytics.once')).toBeNull()
    expect(vi.getTimerCount()).toBe(0)
  })

  it('follows VITE_ANALYTICS_MODE when it is set, the order mode otherwise, and never runs in a share build', () => {
    const modes = (value: string) => { for (const k of ['CART', 'PICKUP', 'CHECKOUT', 'PAYMENT', 'ORDER']) vi.stubEnv(`VITE_${k}_MODE`, value) }
    vi.stubEnv('MODE', 'development'); vi.stubEnv('VITE_SHARE_BUILD', ''); vi.stubEnv('VITE_ANALYTICS_MODE', '')
    modes('mock')
    expect([orderMode(), analyticsMode()]).toEqual(['mock', 'off'])
    modes('api')
    expect([orderMode(), analyticsMode()]).toEqual(['api', 'api'])
    vi.stubEnv('VITE_ANALYTICS_MODE', 'off')
    expect(analyticsMode()).toBe('off')
    modes('mock'); vi.stubEnv('VITE_ANALYTICS_MODE', 'api')
    expect(analyticsMode()).toBe('api')
    vi.stubEnv('VITE_ANALYTICS_MODE', 'yes') // not a value: the order mode decides
    expect(analyticsMode()).toBe('off')
    modes('api'); vi.stubEnv('VITE_ANALYTICS_MODE', 'api'); vi.stubEnv('VITE_SHARE_BUILD', '1')
    expect(analyticsMode()).toBe('off')
    // One device can be switched by hand, as with every other mode.
    localStorage.setItem('fotg.analytics.mode', 'api'); expect(analyticsMode()).toBe('api')
    vi.stubEnv('VITE_SHARE_BUILD', ''); localStorage.setItem('fotg.analytics.mode', 'off'); expect(analyticsMode()).toBe('off')
  })
})

/* ------------------------------------------------------------------ session */
describe('the session', () => {
  beforeEach(on)

  it('is a random UUID v4 made up on the first event, kept with its two times, and shared by the events that follow', async () => {
    expect(sessionNow()).toBeNull()
    expect(track('journey_search_started')).toBe(true)
    const s = sessionNow()!
    expect(s.id).toMatch(UUID_V4)
    expect([s.startedAt, s.lastActivityAt]).toEqual([START, START])

    await vi.advanceTimersByTimeAsync(10 * 60 * 1000)
    track('journey_search_started')
    expect(sessionNow()).toEqual({ id: s.id, startedAt: START, lastActivityAt: START + 10 * 60 * 1000 })
    flush(); await settle()
    expect(sent.map((x) => x.body.session)).toEqual([s.id, s.id])
    // Only what the emitter needs is kept — nothing about the browser or the device.
    expect(Object.keys(JSON.parse(sessionStorage.getItem('fotg.analytics.session')!))).toEqual(['id', 'startedAt', 'lastActivityAt'])
  })

  it('makes its identifier without crypto.randomUUID too (older browsers, plain http)', () => {
    vi.stubGlobal('crypto', { getRandomValues: (b: Uint8Array) => { for (let i = 0; i < b.length; i++) b[i] = (i * 37 + 11) % 256; return b } })
    track('journey_search_started')
    expect(sessionNow()!.id).toMatch(UUID_V4)
    vi.stubGlobal('crypto', undefined)
    resetAnalyticsForTests(); sessionStorage.clear()
    track('journey_search_started')
    expect(sessionNow()!.id).toMatch(UUID_V4)
  })

  it('is replaced after 30 minutes without an event — not a moment earlier', async () => {
    track('journey_search_started')
    const first = sessionNow()!.id
    await vi.advanceTimersByTimeAsync(SESSION_IDLE_MS)
    track('journey_search_started')
    expect(sessionNow()!.id).toBe(first)
    await vi.advanceTimersByTimeAsync(SESSION_IDLE_MS + 1)
    track('journey_search_started')
    const second = sessionNow()!
    expect(second.id).not.toBe(first)
    expect(second.id).toMatch(UUID_V4)
    expect(second.startedAt).toBe(Date.now())
    await vi.advanceTimersByTimeAsync(FLUSH_DELAY_MS)
    expect(sent.map((x) => x.body.session)).toEqual([first, first, second.id])
  })

  it('is replaced when it is older than 12 hours, however busy it was', async () => {
    track('journey_search_started')
    const first = sessionNow()!.id
    const step = 20 * 60 * 1000
    for (let t = step; t <= SESSION_MAX_AGE_MS; t += step) { await vi.advanceTimersByTimeAsync(step); track('journey_search_started'); expect(sessionNow()!.id).toBe(first) }
    await vi.advanceTimersByTimeAsync(step)
    track('journey_search_started')
    expect(sessionNow()!.id).not.toBe(first)
    expect(sessionNow()!.startedAt).toBe(START + SESSION_MAX_AGE_MS + step)
  })

  it('survives a reload of the tab and does not change when the customer signs in or out', async () => {
    track('journey_search_started')
    const id = sessionNow()!.id
    tokens.set('customer', 'customer-session')
    track('journey_search_started')
    tokens.set('customer', null)
    track('journey_search_started')
    flush(); await settle()
    resetAnalyticsForTests() // a page load: the memory is gone, the tab's storage is not
    track('journey_search_started')
    flush(); await settle()
    expect(sessionNow()!.id).toBe(id)
    expect(sent.map((x) => [x.body.session, x.body.events.length])).toEqual([[id, 3], [id, 1]])
  })

  it('works in a browser that offers no storage at all', async () => {
    localStorage.clear()
    vi.stubEnv('MODE', 'development'); vi.stubEnv('VITE_SHARE_BUILD', ''); vi.stubEnv('VITE_ANALYTICS_MODE', 'api')
    const blocked = () => { throw new DOMException('The operation is insecure.', 'SecurityError') }
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(blocked)
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(blocked)
    vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(blocked)

    expect(track('restaurant_viewed', { restaurant: R1 }, { origin: 'DIRECT' }, `restaurant_viewed:${R1}`)).toBe(true)
    expect(track('restaurant_viewed', { restaurant: R1 }, { origin: 'DIRECT' }, `restaurant_viewed:${R1}`)).toBe(false)
    expect(track('menu_viewed', { restaurant: R1 }, undefined, `menu_viewed:${R1}`)).toBe(true)
    await vi.advanceTimersByTimeAsync(FLUSH_DELAY_MS)
    expect(sent).toHaveLength(1)
    expect(sent[0].body.session).toMatch(UUID_V4)
    expect(sent[0].body.events.map((e) => e.name)).toEqual(['restaurant_viewed', 'menu_viewed'])
    expect(sent[0].headers.Authorization).toBeUndefined()
    // Opting out holds for the page even though it could not be written down.
    setAnalyticsConsent(false)
    expect([analyticsConsent(), track('journey_search_started')]).toEqual([false, false])
  })
})

/* ------------------------------------------------------------------ once */
describe('events that are sent once', () => {
  beforeEach(on)

  it('records a key the first time it is seen in the session and never again in it', async () => {
    expect(track('restaurant_viewed', { restaurant: R1 }, { origin: 'JOURNEY' }, `restaurant_viewed:${R1}`)).toBe(true)
    expect(track('restaurant_viewed', { restaurant: R1 }, { origin: 'BROWSE' }, `restaurant_viewed:${R1}`)).toBe(false)
    expect(track('menu_viewed', { restaurant: R1 }, undefined, `menu_viewed:${R1}`)).toBe(true) // another key
    expect(track('restaurant_viewed', { restaurant: 'pizza-point' }, { origin: 'BROWSE' }, 'restaurant_viewed:pizza-point')).toBe(true)
    flush(); await settle()
    resetAnalyticsForTests() // a reload of the tab does not make a second "first time"
    expect(track('restaurant_viewed', { restaurant: R1 }, { origin: 'DIRECT' }, `restaurant_viewed:${R1}`)).toBe(false)
    flush(); await settle()
    expect(events().map((e) => [e.name, e.refs.restaurant, e.properties.origin])).toEqual([['restaurant_viewed', R1, 'JOURNEY'], ['menu_viewed', R1, undefined], ['restaurant_viewed', 'pizza-point', 'BROWSE']])
  })

  it('a second "checkout opened" or "pay" for the same checkout is not a second event — until the session is a new one', async () => {
    track('checkout_started', { checkout: CK, restaurant: R1 }, undefined, `checkout_started:${CK}`)
    track('checkout_started', { checkout: CK, restaurant: R1 }, undefined, `checkout_started:${CK}`)
    track('payment_started', { checkout: CK }, { method: 'UPI' }, `payment_started:${CK}`)
    track('payment_started', { checkout: CK }, { method: 'UPI' }, `payment_started:${CK}`) // pay again
    await vi.advanceTimersByTimeAsync(FLUSH_DELAY_MS)
    expect(events().map((e) => e.name)).toEqual(['checkout_started', 'payment_started'])
    const first = sent[0].body.session

    await vi.advanceTimersByTimeAsync(SESSION_IDLE_MS + 1)
    expect(track('checkout_started', { checkout: CK, restaurant: R1 }, undefined, `checkout_started:${CK}`)).toBe(true)
    await vi.advanceTimersByTimeAsync(FLUSH_DELAY_MS)
    expect(sent).toHaveLength(2)
    expect(sent[1].body.session).not.toBe(first)
    expect(JSON.parse(sessionStorage.getItem('fotg.analytics.once')!)).toEqual({ session: sent[1].body.session, keys: [`checkout_started:${CK}`] })
  })

  it('does not use a key up on an event that could not be recorded; events without a key go every time', () => {
    expect(track('item_viewed', { restaurant: R1, item: 'itm_burger' }, undefined, 'item_viewed:x')).toBe(false) // no backend id for the item
    expect(track('item_viewed', { restaurant: R1, item: I1 }, undefined, 'item_viewed:x')).toBe(true)
    expect(track('item_added_to_cart', { restaurant: R1, item: I1 }, { quantity: 1 })).toBe(true)
    expect(track('item_added_to_cart', { restaurant: R1, item: I1 }, { quantity: 1 })).toBe(true)
    expect(track('pickup_selected', { restaurant: R1 }, { mode: 'ASAP' })).toBe(true)
    expect(track('pickup_selected', { restaurant: R1 }, { mode: 'ASAP' })).toBe(true)
  })
})

/* ------------------------------------------------------------------ queue, batches, payload */
describe('sending', () => {
  beforeEach(on)

  it('waits a few seconds after the first event and sends what gathered in one request', async () => {
    track('journey_search_started')
    await vi.advanceTimersByTimeAsync(FLUSH_DELAY_MS - 1000)
    track('journey_results_viewed', { journey: J1 }, { results: 7 }, `journey_results_viewed:${J1}`)
    track('restaurant_viewed', { restaurant: R1, journey: J1 }, { origin: 'JOURNEY' }, `restaurant_viewed:${R1}`)
    await vi.advanceTimersByTimeAsync(999)
    expect(fetchStub).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(1)
    expect(fetchStub).toHaveBeenCalledTimes(1)
    expect(events().map((e) => e.name)).toEqual(['journey_search_started', 'journey_results_viewed', 'restaurant_viewed'])
    // Nothing more goes out by itself; the next event starts the next wait.
    await vi.advanceTimersByTimeAsync(60_000)
    expect(fetchStub).toHaveBeenCalledTimes(1)
    track('menu_viewed', { restaurant: R1, journey: J1 }, undefined, `menu_viewed:${R1}`)
    await vi.advanceTimersByTimeAsync(FLUSH_DELAY_MS)
    expect(sent.map((s) => s.body.events.length)).toEqual([3, 1])
  })

  it('sends exactly the body of the contract — session, platform, events — to the first-party endpoint', async () => {
    track('journey_search_started')
    await vi.advanceTimersByTimeAsync(1500)
    track('pickup_selected', { restaurant: 'burger-hub' }, { mode: 'SCHEDULED', method: 'CURBSIDE', recommended: false })
    track('item_added_to_cart', { restaurant: R1, item: I1 }, { quantity: 3 })
    flush(); await settle()

    expect(sent).toHaveLength(1)
    const [{ url, init, headers, body }] = sent
    expect(url).toBe(ENDPOINT)
    expect([init.method, init.keepalive, init.credentials]).toEqual(['POST', undefined, undefined]) // an ordinary send is a plain request; keepalive is for the page being left
    expect(headers).toEqual({ 'Content-Type': 'application/json', Accept: 'application/json' })
    expect(body).toEqual({
      session: sessionNow()!.id, platform: 'WEB',
      events: [
        { id: expect.stringMatching(UUID_V4), name: 'journey_search_started', occurred_at: '2026-10-13T09:15:00.000Z', refs: {}, properties: {} },
        { id: expect.stringMatching(UUID_V4), name: 'pickup_selected', occurred_at: '2026-10-13T09:15:01.500Z', refs: { restaurant: 'burger-hub' }, properties: { mode: 'SCHEDULED', method: 'CURBSIDE', recommended: false } },
        { id: expect.stringMatching(UUID_V4), name: 'item_added_to_cart', occurred_at: '2026-10-13T09:15:01.500Z', refs: { restaurant: R1, item: I1 }, properties: { quantity: 3 } },
      ],
    })
    expect(Object.keys(body)).toEqual(['session', 'platform', 'events']) // no app_version (the web has none), no consent flag, nothing else
    expect(new Set(body.events.map((e) => e.id)).size).toBe(3)
    for (const e of body.events) expect(e.occurred_at).toMatch(SERVER_TIME)
    expect(init.body as string).toBe(JSON.stringify(body))
  })

  it('reads the answer to its end — a body nobody reads keeps the request open in the browser', async () => {
    const answers: Response[] = []
    fetchStub.mockImplementation((url: string, init: RequestInit = {}) => (respond(url, init) as Promise<Response>).then((r) => { answers.push(r); return r }))
    track('journey_search_started'); flush(); await settle()
    answer = () => 500
    track('journey_search_started'); flush(); await settle()
    expect(answers.map((r) => [r.status, r.bodyUsed])).toEqual([[202, true], [500, true]])
  })

  it('attaches the customer\'s token when there is one — and no other', async () => {
    tokens.set('restaurant', 'staff-token'); tokens.set('admin', 'admin-token')
    track('journey_search_started'); flush(); await settle()
    expect(sent[0].headers.Authorization).toBeUndefined()
    tokens.set('customer', 'customer-session')
    track('journey_search_started'); flush(); await settle()
    expect(sent[1].headers).toEqual({ 'Content-Type': 'application/json', Accept: 'application/json', Authorization: 'Bearer customer-session' })
    expect(JSON.stringify(sent[1].body)).not.toContain('customer-session')
    tokens.set('restaurant', null); tokens.set('admin', null)
  })

  it('splits a long queue into requests of at most 20 events, in order', async () => {
    for (let i = 0; i < 45; i++) track('journey_results_viewed', { journey: J1 }, { results: i })
    await vi.advanceTimersByTimeAsync(FLUSH_DELAY_MS)
    expect(sent.map((s) => s.body.events.length)).toEqual([MAX_BATCH, MAX_BATCH, 5])
    expect(events().map((e) => e.properties.results)).toEqual(Array.from({ length: 45 }, (_, i) => i))
    expect(MAX_BATCH).toBeLessThanOrEqual(25) // the backend refuses a larger batch as a whole
  })

  it('flush() sends at once and leaves nothing for the timer', async () => {
    track('journey_search_started')
    flush()
    expect(fetchStub).toHaveBeenCalledTimes(1)
    flush() // nothing is waiting: no request
    await vi.advanceTimersByTimeAsync(60_000)
    expect(fetchStub).toHaveBeenCalledTimes(1)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('sends what is waiting when the page is hidden or left, with keepalive — and only then', async () => {
    const visibility = vi.spyOn(document, 'visibilityState', 'get')
    track('journey_search_started')
    visibility.mockReturnValue('visible'); document.dispatchEvent(new Event('visibilitychange'))
    expect(fetchStub).not.toHaveBeenCalled() // coming back to the tab is not a reason to send
    visibility.mockReturnValue('hidden'); document.dispatchEvent(new Event('visibilitychange'))
    expect(fetchStub).toHaveBeenCalledTimes(1)
    expect(sent[0].init.keepalive).toBe(true)

    track('menu_viewed', { restaurant: R1 })
    window.dispatchEvent(new Event('pagehide'))
    expect(fetchStub).toHaveBeenCalledTimes(2)
    expect(sent[1].init.keepalive).toBe(true)
    window.dispatchEvent(new Event('pagehide')); document.dispatchEvent(new Event('visibilitychange'))
    await vi.advanceTimersByTimeAsync(60_000)
    expect(fetchStub).toHaveBeenCalledTimes(2)

    track('pickup_viewed', { restaurant: R1 })
    await vi.advanceTimersByTimeAsync(FLUSH_DELAY_MS) // the timer's own send, with the page still there
    expect(fetchStub).toHaveBeenCalledTimes(3)
    expect(sent[2].init.keepalive).toBeUndefined()
  })

  it('keeps at most 100 events waiting and lets the oldest go first', async () => {
    for (let i = 0; i < MAX_QUEUE + 30; i++) track('journey_results_viewed', { journey: J1 }, { results: i })
    flush(); await settle()
    expect(sent.map((s) => s.body.events.length)).toEqual([20, 20, 20, 20, 20])
    expect(events().map((e) => e.properties.results)).toEqual(Array.from({ length: MAX_QUEUE }, (_, i) => i + 30))
  })
})

/* ------------------------------------------------------------------ registry */
describe('what may be sent', () => {
  beforeEach(on)

  it('is the ten events of the contract and nothing else', () => {
    expect(Object.keys(EVENTS)).toEqual(['journey_search_started', 'journey_results_viewed', 'restaurant_viewed', 'menu_viewed', 'item_viewed', 'item_added_to_cart', 'pickup_viewed', 'pickup_selected', 'checkout_started', 'payment_started'])
    const facts = ['payment_verified', 'order_created', 'order_completed', 'refund_completed', 'journey_search_succeeded', 'restaurant_impression', 'constructor', 'toString']
    for (const name of facts) expect(track(name as never)).toBe(false)
    flush()
    expect(fetchStub).not.toHaveBeenCalled()
  })

  it('refuses a property or a kind of reference the event does not list', () => {
    expect(track('journey_search_started', undefined, { query: 'Delhi to Jaipur' })).toBe(false)
    expect(track('journey_search_started', { journey: J1 })).toBe(false)
    expect(track('restaurant_viewed', { restaurant: R1 }, { origin: 'DIRECT', name: 'Burger Hub' })).toBe(false)
    expect(track('item_added_to_cart', { restaurant: R1 }, { quantity: 1, amount: 24900 })).toBe(false)
    expect(track('payment_started', { checkout: CK, restaurant: R1 })).toBe(false)
    expect(track('pickup_selected', { restaurant: R1, customer: 'u1' } as never, { mode: 'ASAP' })).toBe(false)
    expect(track('checkout_started', { checkout: CK }, { phone: '+919876543210' })).toBe(false)
    flush()
    expect(fetchStub).not.toHaveBeenCalled()
  })

  it('refuses an event without the reference it needs — a reference is a backend id (or a restaurant slug), never a text', () => {
    expect(track('journey_results_viewed', undefined, { results: 3 })).toBe(false)
    expect(track('journey_results_viewed', { journey: 'jrn_local_1' }, { results: 3 })).toBe(false)
    expect(track('restaurant_viewed', { restaurant: 'Burger Hub, MG Road' }, { origin: 'DIRECT' })).toBe(false)
    expect(track('restaurant_viewed', { restaurant: '28.6139,77.2090' }, { origin: 'DIRECT' })).toBe(false)
    expect(track('item_viewed', { restaurant: R1 })).toBe(false)
    expect(track('checkout_started', { checkout: 'CS-12345678' })).toBe(false)
    expect(track('payment_started', { checkout: null })).toBe(false)
    flush()
    expect(fetchStub).not.toHaveBeenCalled()
  })

  it('leaves out an optional reference that is not a backend id and a value outside its rule — the event still goes', async () => {
    track('restaurant_viewed', { restaurant: 'burger-hub', journey: 'jrn_local_1' }, { origin: 'SEARCH' })
    track('item_added_to_cart', { restaurant: R1, item: 'classic-burger' }, { quantity: 51 })
    track('item_added_to_cart', { restaurant: R1, item: I1 }, { quantity: 2.5 })
    track('pickup_selected', { restaurant: 'Burger Hub' }, { mode: 'asap', method: 'DELIVERY', recommended: 'yes' as never })
    track('pickup_selected', { restaurant: undefined }, { mode: 'ASAP', method: 'COUNTER', recommended: undefined })
    track('payment_started', { checkout: CK }, { method: 'CASH' })
    track('journey_results_viewed', { journey: J1 }, { results: 501 })
    flush(); await settle()
    expect(events().map((e) => [e.name, e.refs, e.properties])).toEqual([
      ['restaurant_viewed', { restaurant: 'burger-hub' }, {}],
      ['item_added_to_cart', { restaurant: R1 }, {}],
      ['item_added_to_cart', { restaurant: R1, item: I1 }, {}],
      ['pickup_selected', {}, {}],
      ['pickup_selected', {}, { mode: 'ASAP', method: 'COUNTER' }],
      ['payment_started', { checkout: CK }, {}],
      ['journey_results_viewed', { journey: J1 }, {}],
    ])
  })

  it('admit() says why, and the two helpers of the call sites speak the registry\'s language', () => {
    expect(admit('order_completed')).toEqual({ ok: false, reason: 'unknown event' })
    expect(admit('menu_viewed', { restaurant: R1 }, { tab: 'menu' })).toEqual({ ok: false, reason: 'property "tab" is not one of this event' })
    expect(admit('menu_viewed', {})).toEqual({ ok: false, reason: 'reference "restaurant" is required' })
    expect(admit('pickup_viewed')).toEqual({ ok: true, refs: {}, properties: {}, notes: [] })
    expect(admit('pickup_selected', { restaurant: R1 }, { mode: 'ASAP', recommended: true })).toEqual({ ok: true, refs: { restaurant: R1 }, properties: { mode: 'ASAP', recommended: true }, notes: [] })
    expect(restaurantRef({ slug: 'burger-hub', publicId: R1 })).toBe(R1)
    expect(restaurantRef({ slug: 'burger-hub', publicId: 'rst_burger_hub' })).toBe('burger-hub')
    expect(restaurantRef({ slug: 'burger-hub' })).toBe('burger-hub')
    expect(['UPI', 'CARD', 'NET_BANKING', 'WALLET', 'EMI', '', null, undefined].map(paymentMethodOf)).toEqual(['UPI', 'CARD', 'NET_BANKING', 'WALLET', 'OTHER', undefined, undefined, undefined])
  })
})

/* ------------------------------------------------------------------ consent */
describe('consent', () => {
  beforeEach(on)

  it('is on by default; opting out forgets what was waiting and the session, and sends nothing from then on', async () => {
    expect([analyticsConsent(), analyticsEnabled()]).toEqual([true, true])
    track('journey_search_started')
    track('restaurant_viewed', { restaurant: R1 }, { origin: 'DIRECT' }, `restaurant_viewed:${R1}`)
    expect(sessionNow()).not.toBeNull()

    setAnalyticsConsent(false)
    expect([analyticsConsent(), analyticsEnabled()]).toEqual([false, false])
    expect(localStorage.getItem('fotg.analytics.consent')).toBe('off')
    expect(sessionNow()).toBeNull()
    expect(sessionStorage.getItem('fotg.analytics.once')).toBeNull()
    expect(track('journey_search_started')).toBe(false)
    flush()
    document.dispatchEvent(new Event('visibilitychange')); window.dispatchEvent(new Event('pagehide'))
    await vi.advanceTimersByTimeAsync(60_000)
    expect(fetchStub).not.toHaveBeenCalled()
    expect(sessionNow()).toBeNull()

    resetAnalyticsForTests() // the choice is the device's: it holds after a reload
    expect(track('journey_search_started')).toBe(false)
    expect(fetchStub).not.toHaveBeenCalled()
  })

  it('opting in again starts afresh: a new session, nothing from before', async () => {
    track('journey_search_started')
    const before = sessionNow()!.id
    setAnalyticsConsent(false)
    setAnalyticsConsent(true)
    expect(localStorage.getItem('fotg.analytics.consent')).toBeNull()
    expect(track('menu_viewed', { restaurant: R1 })).toBe(true)
    await vi.advanceTimersByTimeAsync(FLUSH_DELAY_MS)
    expect(sent).toHaveLength(1)
    expect(sent[0].body.session).not.toBe(before)
    expect(sent[0].body.events.map((e) => e.name)).toEqual(['menu_viewed'])
  })
})

/* ------------------------------------------------------------------ failures */
describe('when the endpoint cannot be reached or refuses', () => {
  beforeEach(on)

  it('keeps a batch the network lost for ONE later attempt — the same events — and then drops it', async () => {
    const errors = vi.spyOn(console, 'error').mockImplementation(() => {})
    const warnings = vi.spyOn(console, 'warn').mockImplementation(() => {})
    answer = () => 'network'
    expect(track('journey_search_started')).toBe(true)
    expect(track('menu_viewed', { restaurant: R1 })).toBe(true)
    await vi.advanceTimersByTimeAsync(FLUSH_DELAY_MS)
    expect(fetchStub).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(RETRY_DELAY_MS - 1)
    expect(fetchStub).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(1)
    expect(fetchStub).toHaveBeenCalledTimes(2)
    expect(sent[1].body).toEqual(sent[0].body) // same session, same event ids: the backend stores an event id once
    await vi.advanceTimersByTimeAsync(10 * 60 * 1000)
    window.dispatchEvent(new Event('pagehide')); flush(); await settle()
    expect(fetchStub).toHaveBeenCalledTimes(2) // no third attempt, no loop
    expect(vi.getTimerCount()).toBe(0)
    expect(errors).not.toHaveBeenCalled(); expect(warnings).not.toHaveBeenCalled()
  })

  it('the one later attempt can succeed, and travels with whatever is new', async () => {
    answer = (n) => (n === 1 ? 'network' : 202)
    track('journey_search_started')
    await vi.advanceTimersByTimeAsync(FLUSH_DELAY_MS)
    track('menu_viewed', { restaurant: R1 }) // a new event does not wait for the retry's own delay
    await vi.advanceTimersByTimeAsync(FLUSH_DELAY_MS)
    expect(sent.map((s) => s.body.events.map((e) => e.name))).toEqual([['journey_search_started'], ['journey_search_started'], ['menu_viewed']])
    expect(sent[1].body.events[0].id).toBe(sent[0].body.events[0].id)
    await vi.advanceTimersByTimeAsync(10 * 60 * 1000)
    expect(fetchStub).toHaveBeenCalledTimes(3)
  })

  it('never offers a refused batch again: 4xx, 5xx and an answer that is not JSON are simply over', async () => {
    const errors = vi.spyOn(console, 'error').mockImplementation(() => {})
    for (const status of [422, 429, 401, 500, 503]) {
      answer = () => status
      expect(track('journey_search_started')).toBe(true)
      await vi.advanceTimersByTimeAsync(FLUSH_DELAY_MS)
    }
    await vi.advanceTimersByTimeAsync(10 * 60 * 1000)
    expect(fetchStub).toHaveBeenCalledTimes(5)
    expect(errors).not.toHaveBeenCalled()
    // A refused token is not this module's to act on: the customer stays signed in.
    tokens.set('customer', 'customer-session'); answer = () => 401
    track('journey_search_started'); flush(); await settle()
    expect(tokens.get('customer')).toBe('customer-session')
  })

  it('nothing reaches the caller when sending itself breaks', async () => {
    answer = () => 'throws'
    expect(() => { track('journey_search_started'); flush() }).not.toThrow()
    answer = () => 'garbage'
    expect(() => { track('journey_search_started'); flush() }).not.toThrow()
    vi.stubGlobal('fetch', undefined)
    expect(() => { track('journey_search_started'); flush() }).not.toThrow()
    await vi.advanceTimersByTimeAsync(10 * 60 * 1000)
    expect(vi.getTimerCount()).toBe(0)
    // The emitter goes on working afterwards.
    vi.stubGlobal('fetch', fetchStub); answer = () => 202
    const before = sent.length
    expect(track('journey_search_started')).toBe(true)
    await vi.advanceTimersByTimeAsync(FLUSH_DELAY_MS)
    expect(sent.length).toBe(before + 1)
  })

  it('drops a lost batch instead of offering it again once the person has opted out', async () => {
    answer = () => 'network'
    track('journey_search_started')
    flush()
    setAnalyticsConsent(false)
    await vi.advanceTimersByTimeAsync(10 * 60 * 1000)
    expect(fetchStub).toHaveBeenCalledTimes(1)
  })
})
