import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { MockOrderTrackingService } from './mock/mockTracking'
import type { Order, OrderEvent, OrderRepository, PickupVerification, PickupVerificationRepository } from './repositories'
import { reduceOrder, TERMINAL_ORDER, type OrderTrackingService, type TrackingConnection } from './tracking'
import { orderRepositories } from './useOrderConfirmation'

/**
 * Centralized tracking state (Module 14). Connection state (LOADING / LIVE / STALE / OFFLINE / ERROR / COMPLETED) is kept
 * apart from the order status. Events go through `reduceOrder`, so duplicates and stale sequences never regress the UI.
 * `refresh()` re-reads the order (polling fallback / manual refresh) and never creates events.
 */
export type TrackingDeps = { orders?: OrderRepository; verifications?: PickupVerificationRepository; tracking?: OrderTrackingService & Partial<MockOrderTrackingService> }
export type TrackingLoadError = 'not_found' | 'load_failed' | null
const defaultTracking = new MockOrderTrackingService(orderRepositories.orders)
export const trackingService = defaultTracking
const isOffline = () => { try { if (sessionStorage.getItem('fotg.mock.offline') === '1') return true } catch { /* ignore */ } return typeof navigator !== 'undefined' && navigator.onLine === false }

export function useOrderTracking(orderNumber: string, deps: TrackingDeps = {}) {
  const orders = deps.orders ?? orderRepositories.orders; const verifications = deps.verifications ?? orderRepositories.verifications; const tracking = deps.tracking ?? defaultTracking
  const auth = useAuth()
  const customerId = auth.user?.id ?? null
  const [order, setOrder] = useState<Order | null>(null)
  const [verification, setVerification] = useState<PickupVerification | null>(null)
  const [connection, setConnection] = useState<TrackingConnection>('LOADING')
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
        if (TERMINAL_ORDER.has(o.orderStatus)) setConnection('COMPLETED')
        else if (offline.current) setConnection('OFFLINE')
        else unsubscribe = tracking.subscribe(o, { onEvent: applyEvent, onConnection: (c) => { if (!offline.current && !TERMINAL_ORDER.has(orderRef.current?.orderStatus ?? 'CONFIRMED')) setConnection(c) } })
        const pv = await verifications.getForOrder(o).catch(() => null)
        if (!on) return
        setVerification(pv)
      } catch { if (on) { setLoadError('load_failed'); setConnection('ERROR') } }
    })()
    return () => { on = false; unsubscribe?.() }
  }, [orderNumber, customerId, tick, orders, verifications, tracking, applyEvent])

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

  return useMemo(() => ({ order, verification, connection, loadError, lastUpdated, ignored, refresh, reconnect, advance, injectDuplicate, injectStale }), [order, verification, connection, loadError, lastUpdated, ignored, refresh, reconnect, advance, injectDuplicate, injectStale])
}
