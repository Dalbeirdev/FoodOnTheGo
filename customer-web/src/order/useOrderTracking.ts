import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { ApiOrderRepository, ApiOrderTrackingService } from './api/apiOrder'
import { MockOrderTrackingService } from './mock/mockTracking'
import { refundOpen } from './history'
import { orderMode } from './orderMode'
import type { Order, OrderEvent, OrderRepository, PickupVerification, PickupVerificationRepository } from './repositories'
import { reduceOrder, TERMINAL_ORDER, type OrderTrackingService, type TrackingConnection, type TransportState } from './tracking'
import { orderRepositories } from './useOrderConfirmation'

/**
 * Centralized tracking state (Module 14). Connection state (LOADING / LIVE / STALE / OFFLINE / ERROR / COMPLETED) is kept
 * apart from the order status. Events go through `reduceOrder`, so duplicates and stale sequences never regress the UI.
 * `refresh()` re-reads the order (polling fallback / manual refresh) and never creates events.
 */
export type TrackingDeps = { orders?: OrderRepository; verifications?: PickupVerificationRepository; tracking?: OrderTrackingService & Partial<MockOrderTrackingService> }
export type TrackingLoadError = 'not_found' | 'load_failed' | null
// Module 31: against the backend the order is read again every few seconds (Module 32 brings the event stream); the mock keeps its scripts.
const defaultTracking: OrderTrackingService & Partial<MockOrderTrackingService> = orderMode() === 'api' && orderRepositories.orders instanceof ApiOrderRepository ? new ApiOrderTrackingService(orderRepositories.orders) : new MockOrderTrackingService(orderRepositories.orders)
export const trackingService = defaultTracking
const isOffline = () => { try { if (sessionStorage.getItem('fotg.mock.offline') === '1') return true } catch { /* ignore */ } return typeof navigator !== 'undefined' && navigator.onLine === false }

export function useOrderTracking(orderNumber: string, deps: TrackingDeps = {}) {
  const orders = deps.orders ?? orderRepositories.orders; const verifications = deps.verifications ?? orderRepositories.verifications; const tracking = deps.tracking ?? defaultTracking
  const auth = useAuth()
  const customerId = auth.user?.id ?? null
  const [order, setOrder] = useState<Order | null>(null)
  const [verification, setVerification] = useState<PickupVerification | null>(null)
  const [connection, setConnection] = useState<TrackingConnection>('LOADING')
  // Module 32: how the updates arrive (socket, reconnecting, polling) — apart from whether the status is current.
  const [transport, setTransport] = useState<TransportState>('OFF')
  const [loadError, setLoadError] = useState<TrackingLoadError>(null)
  const [lastUpdated, setLastUpdated] = useState<string | null>(null)
  const [ignored, setIgnored] = useState<{ duplicate: number; stale: number }>({ duplicate: 0, stale: 0 })
  const [tick, setTick] = useState(0)
  const orderRef = useRef<Order | null>(null)
  const offline = useRef(isOffline())

  const applyEvent = useCallback((e: OrderEvent) => {
    const cur = orderRef.current; if (!cur || offline.current) return // offline: last known status only; reconnect re-reads the order
    const r = reduceOrder(cur, e)
    if (!r.applied) { setIgnored((x) => ({ duplicate: x.duplicate + (r.reason === 'duplicate' ? 1 : 0), stale: x.stale + (r.reason === 'stale' ? 1 : 0) })); return }
    orderRef.current = r.order; setOrder(r.order); setLastUpdated(new Date().toISOString())
    if (TERMINAL_ORDER.has(r.order.orderStatus)) setConnection('COMPLETED')
  }, [])

  // Module 35: what the events do not carry — the refunds, their amounts, whether the order may be cancelled — is taken
  // from the order the tracking service just read again. The order's status still changes only through its events.
  const applyFresh = useCallback((fresh: Order) => {
    const cur = orderRef.current; if (!cur || offline.current) return
    const next: Order = { ...cur, paymentStatus: fresh.paymentStatus, payment: fresh.payment, refunds: fresh.refunds, cancellation: fresh.cancellation, cancellationMessage: fresh.cancellationMessage, version: fresh.version }
    if (JSON.stringify([cur.paymentStatus, cur.payment, cur.refunds, cur.cancellation]) === JSON.stringify([next.paymentStatus, next.payment, next.refunds, next.cancellation])) return
    orderRef.current = next; setOrder(next); setLastUpdated(new Date().toISOString())
  }, [])

  useEffect(() => {
    let on = true; let unsubscribe: (() => void) | null = null
    setConnection('LOADING'); setLoadError(null)
    ;(async () => {
      if (!customerId) return
      try {
        const o = await orders.getByOrderNumber(orderNumber, customerId)
        if (!on) return
        if (!o) { setLoadError('not_found'); setConnection('ERROR'); return }
        orderRef.current = o; setOrder(o); setLastUpdated(new Date().toISOString())
        // Subscribe before any further fetch so no live event (or dev step) can be lost in between.
        // A finished order is not followed — unless a refund of it is still open (backend orders): that can change.
        const followRefund = TERMINAL_ORDER.has(o.orderStatus) && refundOpen(o.paymentStatus) && tracking instanceof ApiOrderTrackingService && !offline.current
        if (TERMINAL_ORDER.has(o.orderStatus)) setConnection('COMPLETED')
        if (followRefund) unsubscribe = tracking.subscribe(o, { onEvent: applyEvent, onConnection: () => { /* a finished order stays COMPLETED */ }, onOrder: applyFresh })
        else if (TERMINAL_ORDER.has(o.orderStatus)) { /* nothing more can happen */ }
        else if (offline.current) setConnection('OFFLINE')
        else unsubscribe = tracking.subscribe(o, { onEvent: applyEvent, onConnection: (c) => { if (!offline.current && !TERMINAL_ORDER.has(orderRef.current?.orderStatus ?? 'CONFIRMED')) setConnection(c) }, onOrder: applyFresh, onTransport: (t) => { if (on) setTransport(t) } })
        const pv = await verifications.getForOrder(o).catch(() => null)
        if (!on) return
        setVerification(pv)
      } catch { if (on) { setLoadError('load_failed'); setConnection('ERROR') } }
    })()
    return () => { on = false; unsubscribe?.() }
  }, [orderNumber, customerId, tick, orders, verifications, tracking, applyEvent, applyFresh])

  // Browser connectivity → OFFLINE / back to LIVE via a refresh (TEST 10 / 11).
  useEffect(() => {
    const down = () => { offline.current = true; setConnection((c) => (c === 'COMPLETED' ? c : 'OFFLINE')) }
    const up = () => { offline.current = false; setTick((x) => x + 1) }
    window.addEventListener('offline', down); window.addEventListener('online', up)
    return () => { window.removeEventListener('offline', down); window.removeEventListener('online', up) }
  }, [])

  /** Manual / polling refresh: re-reads the order; idempotent. */
  const refresh = useCallback(async () => {
    if (!customerId) return
    offline.current = isOffline()
    if (offline.current) { setConnection((c) => (c === 'COMPLETED' ? c : 'OFFLINE')); return }
    try {
      const o = await tracking.refresh(orderNumber, customerId)
      if (o) { orderRef.current = o; setOrder(o); setLastUpdated(new Date().toISOString()); setConnection(TERMINAL_ORDER.has(o.orderStatus) ? 'COMPLETED' : 'LIVE') }
    } catch { setConnection('STALE') }
  }, [tracking, orderNumber, customerId])

  const reconnect = useCallback(() => { offline.current = isOffline(); setTick((x) => x + 1) }, [])
  // Development helpers (mock only)
  const advance = useCallback(async () => { if (tracking.advance) await tracking.advance(orderNumber) }, [tracking, orderNumber])
  const injectDuplicate = useCallback(() => tracking.injectDuplicate?.(orderNumber), [tracking, orderNumber])
  const injectStale = useCallback(() => tracking.injectStale?.(orderNumber), [tracking, orderNumber])

  return useMemo(() => ({ order, verification, connection, transport, loadError, lastUpdated, ignored, refresh, reconnect, advance, injectDuplicate, injectStale }), [order, verification, connection, transport, loadError, lastUpdated, ignored, refresh, reconnect, advance, injectDuplicate, injectStale])
}
