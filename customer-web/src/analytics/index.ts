/**
 * Behavioural events of the Customer Web (Module 38) — first-party product telemetry for the company's own funnel
 * report: which steps a session reached on the way to an order. Sent to the backend's own endpoint and nowhere else.
 *
 * What a page may rely on:
 *   - `track()` returns at once and never throws. Nothing a customer does waits for it, depends on it or changes
 *     because of it: a lost connection, a refusal or a broken answer is swallowed here (no message, no retry loop).
 *   - Nothing is sent that `registry.ts` does not list (ten events, backend references, a few bounded properties).
 *   - Off — a no-op — in mock mode, in unit tests and in share builds (`analyticsMode.ts`), and when the person has
 *     opted out (`setAnalyticsConsent(false)`).
 *
 * The session is a random identifier this page makes up (crypto.randomUUID): not a device id, nothing from hardware,
 * nothing shared with another site or another tab. It is replaced after 30 minutes without an event or 12 hours of
 * age; signing in or out does not change it (the backend links a session to a customer by the token on the request).
 *
 * Events wait in memory and go out in small batches a few seconds after the first one, and when the page is hidden
 * or left (only that last request is `keepalive`). Nothing is kept across page loads except the session and its "already sent once" keys.
 */
import { API_BASE_URL, tokens } from '../api/client'
import { analyticsMode } from './analyticsMode'
import { admit, isUuid, type AnalyticsEventName, type EventProperties, type EventRefs, type RefKind } from './registry'

export { analyticsMode, type AnalyticsMode } from './analyticsMode'
export { EVENTS, paymentMethodOf, restaurantRef, type AnalyticsEventName, type EventProperties, type EventRefs } from './registry'

/** The session is replaced after this long without an event … */
export const SESSION_IDLE_MS = 30 * 60 * 1000
/** … and in any case when it is this old. */
export const SESSION_MAX_AGE_MS = 12 * 60 * 60 * 1000
/** A batch leaves this long after the first event that is waiting. */
export const FLUSH_DELAY_MS = 3000
/** A batch the network lost is offered once more after this long — and dropped if that fails too. */
export const RETRY_DELAY_MS = 15000
/** Events per request (the backend takes 25). */
export const MAX_BATCH = 20
/** Events waiting at most; the oldest are dropped first. */
export const MAX_QUEUE = 100
/** "Already sent once" keys kept per session at most. */
export const MAX_ONCE_KEYS = 500

const SESSION_KEY = 'fotg.analytics.session'
const ONCE_KEY = 'fotg.analytics.once'
const CONSENT_KEY = 'fotg.analytics.consent'

type Session = { id: string; startedAt: number; lastActivityAt: number }
type WireEvent = { id: string; name: AnalyticsEventName; occurred_at: string; refs: Partial<Record<RefKind, string>>; properties: Record<string, string | number | boolean> }
type Batch = { session: string; events: WireEvent[] }

let session: Session | null = null
let once: Set<string> | null = null
let queue: Array<{ session: string; event: WireEvent }> = []
/** Batches the network lost once: they get one more attempt. */
let lost: Batch[] = []
let timer: ReturnType<typeof setTimeout> | null = null
let timerDue = 0
let listening = false
/** What this page was told about consent — it holds even where the browser cannot store it. */
let consentHere: boolean | null = null

const debug = (message: string) => { if (import.meta.env.DEV && import.meta.env.MODE !== 'test') console.debug(`[analytics] ${message}`) }

/** A random UUID v4 (the backend takes nothing else as a session or event id). */
function uuid(): string {
  const c = typeof crypto !== 'undefined' ? crypto : undefined
  if (c && typeof c.randomUUID === 'function') return c.randomUUID()
  // Older browsers, and pages not served over https: the same random bytes, laid out by hand.
  const bytes = new Uint8Array(16)
  if (c && typeof c.getRandomValues === 'function') c.getRandomValues(bytes); else for (let i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256)
  bytes[6] = (bytes[6] & 0x0f) | 0x40; bytes[8] = (bytes[8] & 0x3f) | 0x80
  const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0'))
  return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10).join('')}`
}

/* ------------------------------------------------------------------ consent */
/** false: the person opted out on this device — nothing is queued or sent. On by default (first-party operational telemetry). */
export function analyticsConsent(): boolean {
  if (consentHere === false) return false
  try { return localStorage.getItem(CONSENT_KEY) !== 'off' } catch { return consentHere ?? true }
}

/** Records the person's choice on this device. Opting out also forgets what was waiting and the session itself. */
export function setAnalyticsConsent(granted: boolean): void {
  consentHere = granted
  try { if (granted) localStorage.removeItem(CONSENT_KEY); else localStorage.setItem(CONSENT_KEY, 'off') } catch { /* storage unavailable: `consentHere` holds for this page */ }
  if (granted) return
  forget()
  session = null; once = null
  try { sessionStorage.removeItem(SESSION_KEY); sessionStorage.removeItem(ONCE_KEY) } catch { /* storage unavailable */ }
}

/** true when an event would be queued now: the events are on for this build / device and the person has not opted out. */
export function analyticsEnabled(): boolean {
  try { return analyticsMode() === 'api' && analyticsConsent() } catch { return false }
}

/* ------------------------------------------------------------------ session + once-keys */
function storedSession(): Session | null {
  try {
    const raw = sessionStorage.getItem(SESSION_KEY)
    const v = raw ? (JSON.parse(raw) as Partial<Session>) : null
    return v && isUuid(v.id) && Number.isFinite(v.startedAt) && Number.isFinite(v.lastActivityAt) ? { id: v.id, startedAt: v.startedAt as number, lastActivityAt: v.lastActivityAt as number } : null
  } catch { return null }
}
function storedOnce(sessionId: string): Set<string> {
  try {
    const raw = sessionStorage.getItem(ONCE_KEY)
    const v = raw ? (JSON.parse(raw) as { session?: unknown; keys?: unknown }) : null
    return new Set(v && v.session === sessionId && Array.isArray(v.keys) ? v.keys.filter((k): k is string => typeof k === 'string') : [])
  } catch { return new Set() }
}
const keep = (key: string, value: unknown) => { try { sessionStorage.setItem(key, JSON.stringify(value)) } catch { /* storage unavailable: the page's own memory holds it */ } }

/** The session an event of this moment belongs to: the current one, or a new one when that has been idle or lived too long. */
function touchSession(now: number): Session {
  const current = session ?? storedSession()
  const over = current === null || now - current.lastActivityAt > SESSION_IDLE_MS || now - current.startedAt > SESSION_MAX_AGE_MS
  if (over) {
    session = { id: uuid(), startedAt: now, lastActivityAt: now }
    once = new Set()
    keep(ONCE_KEY, { session: session.id, keys: [] })
  } else {
    session = { ...current, lastActivityAt: now }
    once ??= storedOnce(session.id)
  }
  keep(SESSION_KEY, session)
  return session
}

/** true the first time a key is seen in the session. */
function firstTime(key: string, sessionId: string): boolean {
  const keys = (once ??= storedOnce(sessionId))
  if (keys.has(key)) return false
  keys.add(key)
  for (const oldest of keys) { if (keys.size <= MAX_ONCE_KEYS) break; keys.delete(oldest) }
  keep(ONCE_KEY, { session: sessionId, keys: [...keys] })
  return true
}

/* ------------------------------------------------------------------ queue + sending */
function forget() {
  queue = []; lost = []
  if (timer !== null) { clearTimeout(timer); timer = null }
}

function schedule(delay: number) {
  const due = Date.now() + delay
  if (timer !== null && timerDue <= due) return
  if (timer !== null) clearTimeout(timer)
  timerDue = due
  timer = setTimeout(() => { timer = null; flush() }, delay)
}

const onHidden = () => { if (document.visibilityState === 'hidden') drain(true) }
const onLeave = () => { drain(true) }
/** The page being hidden or left is the last moment to send what is waiting. Registered with the first event only. */
function listen() {
  if (listening || typeof document === 'undefined' || typeof window === 'undefined') return
  listening = true
  document.addEventListener('visibilitychange', onHidden)
  window.addEventListener('pagehide', onLeave)
}

/**
 * One request. Its outcome changes nothing anywhere else; only a lost connection earns the batch its one later attempt.
 * `leaving`: the page is being hidden or left — the request must outlive it (`keepalive`). An ordinary send is a plain
 * request: the browser keeps a small quota for keepalive bodies, and it is for that last one.
 */
function send(batch: Batch, again: boolean, leaving: boolean) {
  const headers: Record<string, string> = { 'Content-Type': 'application/json', Accept: 'application/json' }
  const token = tokens.get('customer')
  if (token) headers.Authorization = `Bearer ${token}`
  let request: Promise<Response>
  try {
    // Called at once (a page being left has no later), wrapped so that whatever comes back is handled below.
    request = Promise.resolve(fetch(`${API_BASE_URL}/analytics/events`, { method: 'POST', headers, body: JSON.stringify({ session: batch.session, platform: 'WEB', events: batch.events }), ...(leaving ? { keepalive: true } : {}) }))
  } catch { debug('no way to send from this browser'); return }
  request.then(
    (res) => {
      if (!res.ok) debug(`a batch of ${batch.events.length} was refused (${res.status})`)
      // The answer is read to its end and thrown away: a body nobody reads keeps the request (and its connection) open.
      try { void res.text().catch(() => { /* never a page's concern */ }) } catch { /* never a page's concern */ }
    },
    () => {
      if (!again || !analyticsEnabled()) { debug(`a batch of ${batch.events.length} was dropped`); return }
      lost.push(batch)
      while (lost.length > 1 && lost.reduce((n, b) => n + b.events.length, 0) > MAX_QUEUE) lost.shift()
      schedule(RETRY_DELAY_MS)
    },
  ).catch(() => { /* never a page's concern */ })
}

/** Sends what is waiting now (the pages never need to: it happens by itself). Returns at once; never throws. */
export function flush(): void { drain(false) }

function drain(leaving: boolean) {
  try {
    if (timer !== null) { clearTimeout(timer); timer = null }
    if (!analyticsEnabled()) { forget(); return }
    const second = lost; lost = []
    const waiting = queue; queue = []
    for (const batch of second) send(batch, false, leaving)
    // One request carries the events of one session, in order, MAX_BATCH at most.
    let batch: Batch | null = null
    for (const { session: id, event } of waiting) {
      if (batch === null || batch.session !== id || batch.events.length >= MAX_BATCH) { if (batch) send(batch, true, leaving); batch = { session: id, events: [] } }
      batch.events.push(event)
    }
    if (batch) send(batch, true, leaving)
  } catch { /* never a page's concern */ }
}

/**
 * Records that a step of the funnel happened. Returns whether the event was queued (pages ignore it).
 *
 * `onceKey`: the event is recorded the first time that key is seen in the session, and never again in it
 * (e.g. `restaurant_viewed:<id>`) — the steps of a page run more than once (reloads, filters, retries).
 */
export function track(name: AnalyticsEventName, refs?: EventRefs, properties?: EventProperties, onceKey?: string): boolean {
  try {
    if (!analyticsEnabled()) return false
    const admitted = admit(name, refs, properties)
    if (!admitted.ok) { debug(`${String(name)} was not recorded: ${admitted.reason}`); return false }
    for (const note of admitted.notes) debug(`${name}: ${note}`)
    const now = Date.now()
    const current = touchSession(now)
    if (onceKey !== undefined && !firstTime(onceKey, current.id)) return false
    queue.push({ session: current.id, event: { id: uuid(), name, occurred_at: new Date(now).toISOString(), refs: admitted.refs, properties: admitted.properties } })
    if (queue.length > MAX_QUEUE) queue.splice(0, queue.length - MAX_QUEUE)
    listen()
    schedule(FLUSH_DELAY_MS)
    return true
  } catch { return false }
}

/** Unit tests only: the emitter as a freshly loaded page has it (nothing waiting, no session in memory, no listeners). */
export function resetAnalyticsForTests(): void {
  forget()
  session = null; once = null; consentHere = null; timerDue = 0
  if (listening) { document.removeEventListener('visibilitychange', onHidden); window.removeEventListener('pagehide', onLeave); listening = false }
}
