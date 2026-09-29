/**
 * MockOrderTrackingService (Module 14). Deterministic scripted scenarios — never random, never a real restaurant.
 *
 * Controls (sessionStorage):
 *   fotg.mock.tracking = normal | delay | rejected | cancelled | payment_pending | ready | picked_up | completed | network_loss
 *   (captured per order the first time it is tracked, so refreshes replay the same script)
 * Development-only helpers: `advance()` (next scripted step), `injectDuplicate()`, `injectStale()`. Auto-advance runs every
 * `setMockTrackingInterval(ms)` (0 = manual only, used by tests).
 */
import type { Order, OrderEvent, OrderRepository } from '../repositories'
import { eventForStep, nextStepIndex, SCENARIO_STEPS, type OrderTrackingService, type TrackingListener, type TrackingScenario } from '../tracking'

export const SCENARIO_KEY = 'fotg.mock.tracking'
const perOrderKey = (n: string) => `fotg.tracking.scenario.${n}`
let interval = 6000
export const setMockTrackingInterval = (ms: number) => { interval = ms }
export const readScenario = (): TrackingScenario => { try { const v = sessionStorage.getItem(SCENARIO_KEY) as TrackingScenario | null; return v && v in SCENARIO_STEPS ? v : 'normal' } catch { return 'normal' } }
export const setScenario = (s: TrackingScenario) => { try { sessionStorage.setItem(SCENARIO_KEY, s) } catch { /* ignore */ } }

export class MockOrderTrackingService implements OrderTrackingService {
  private timers = new Map<string, ReturnType<typeof setTimeout>>()
  private listeners = new Map<string, TrackingListener>()
  private current = new Map<string, Order>()
  private orders: OrderRepository
  constructor(orders: OrderRepository) { this.orders = orders }

  scenarioFor(order: Order): TrackingScenario {
    try {
      const k = perOrderKey(order.orderNumber); const saved = sessionStorage.getItem(k) as TrackingScenario | null
      if (saved && saved in SCENARIO_STEPS) return saved
      const s = order.paymentStatus === 'PAYMENT_PENDING' ? 'payment_pending' : readScenario(); sessionStorage.setItem(k, s); return s
    } catch { return 'normal' }
  }
  /** Development: re-script an order (only meaningful before it reaches a terminal state). */
  setScenarioFor(order: Order, s: TrackingScenario) { try { sessionStorage.setItem(perOrderKey(order.orderNumber), s) } catch { /* ignore */ } }

  subscribe(order: Order, listener: TrackingListener): () => void {
    const n = order.orderNumber
    this.listeners.set(n, listener); this.current.set(n, order)
    listener.onConnection('LIVE')
    this.schedule(n)
    return () => { this.listeners.delete(n); const t = this.timers.get(n); if (t) clearTimeout(t); this.timers.delete(n) }
  }
  private schedule(n: string) {
    const t = this.timers.get(n); if (t) clearTimeout(t)
    let ms = interval; try { const v = sessionStorage.getItem('fotg.mock.tracking.interval'); if (v !== null) ms = Number(v) } catch { /* ignore */ } // dev / e2e override
    if (ms <= 0) return
    this.timers.set(n, setTimeout(() => { void this.advance(n).then(() => { if (this.listeners.has(n)) this.schedule(n) }) }, ms))
  }
  private chain = Promise.resolve<OrderEvent | null>(null)
  /** Emits the next scripted event. Serialized per service so a double tap can never emit the same step twice (idempotent per step; nothing after the script ends). */
  advance(n: string): Promise<OrderEvent | null> { const next = this.chain.then(() => this.advanceNow(n), () => this.advanceNow(n)); this.chain = next; return next }
  private async advanceNow(n: string): Promise<OrderEvent | null> {
    const order = this.current.get(n); const l = this.listeners.get(n)
    if (!order) return null
    const scenario = this.scenarioFor(order)
    const i = nextStepIndex(order, scenario)
    if (i < 0) return null
    const step = SCENARIO_STEPS[scenario][i]
    const e = eventForStep(order, step)
    const updated = await this.orders.applyEvents(n, [e])
    if (updated) this.current.set(n, updated)
    if (!l) return e
    if (step.dropConnection) { l.onConnection('STALE'); setTimeout(() => { const ll = this.listeners.get(n); if (ll) { ll.onConnection('LIVE'); ll.onEvent(e) } }, Math.max(1500, interval)) ; return e }
    l.onEvent(e)
    return e
  }
  /** Development: re-send the last event (TEST 8 — must be ignored). */
  injectDuplicate(n: string) { const o = this.current.get(n); const l = this.listeners.get(n); const last = o?.events[o.events.length - 1]; if (last && l) l.onEvent({ ...last }) }
  /** Development: send an older event with a fresh id (TEST 9 — must not regress the status). */
  injectStale(n: string) { const o = this.current.get(n); const l = this.listeners.get(n); if (!o || !l) return; const old = o.events.find((e) => e.type === 'SENT_TO_RESTAURANT') ?? o.events[0]; l.onEvent({ ...old, eventId: old.eventId + '-replay', sequence: Math.max(0, old.sequence - 1) }) }
  async refresh(orderNumber: string, customerId: string) { const o = await this.orders.getByOrderNumber(orderNumber, customerId); if (o) this.current.set(orderNumber, o); return o }
  currentOf(n: string) { return this.current.get(n) ?? null }
}
