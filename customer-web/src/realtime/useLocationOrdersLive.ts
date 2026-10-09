import { useEffect, useRef, useState } from 'react'
import { echoFor, watchTransport, type TransportState } from './echo'

/**
 * The restaurant dashboard's live order feed (Module 32): one private channel per location
 * (restaurant.location.{location id}), a notice per order change, and polling as the fallback — a new order never
 * depends on the socket to appear. The notice is not the order: `onChange` re-reads the queue (debounced), so a
 * duplicate, a late or a missed notice never creates a second card or a wrong state. A state change made by a
 * colleague arrives the same way.
 */
export type LocationOrderNotice = { order_public_id: string; order_number: string; version: number; status: string; event_type: string; event_sequence: number | null; event_id: string | null; occurred_at: string; item_count?: number }

export function useLocationOrdersLive(locationUuid: string | null, onChange: (notice: LocationOrderNotice | null) => void, options: { pollMs?: number; enabled?: boolean } = {}) {
  const [transport, setTransport] = useState<TransportState>('OFF')
  const [lastNotice, setLastNotice] = useState<LocationOrderNotice | null>(null)
  const [newOrders, setNewOrders] = useState(0)
  const seen = useRef(new Set<string>())
  const change = useRef(onChange)
  change.current = onChange
  const enabled = options.enabled ?? true
  const pollMs = options.pollMs ?? 20000

  useEffect(() => {
    if (!locationUuid || !enabled) { setTransport('OFF'); return }
    let stopped = false
    let debounce: number | null = null
    let poll: number | null = null
    const echo = echoFor('restaurant')
    const refresh = (notice: LocationOrderNotice | null) => {
      if (debounce !== null) window.clearTimeout(debounce)
      debounce = window.setTimeout(() => { if (!stopped) change.current(notice) }, notice ? 250 : 0)
    }
    const startPolling = (t: TransportState) => { setTransport(t); if (poll === null) poll = window.setInterval(() => { if (document.visibilityState === 'visible') refresh(null) }, pollMs) }
    const stopPolling = () => { if (poll !== null) { window.clearInterval(poll); poll = null } }
    let unwatch: (() => void) | null = null
    if (echo) {
      setTransport('CONNECTING')
      echo.private(`restaurant.location.${locationUuid}`).listen('.order.updated', (notice: LocationOrderNotice) => {
        if (stopped) return
        // Dedupe by event id: a notice delivered twice is one change.
        const key = notice.event_id ?? `${notice.order_public_id}:${notice.version}`
        if (seen.current.has(key)) return
        seen.current.add(key)
        if (seen.current.size > 500) seen.current = new Set([...seen.current].slice(-250))
        setLastNotice(notice)
        if (notice.event_type === 'order.created') setNewOrders((n) => n + 1)
        refresh(notice)
      })
      unwatch = watchTransport(echo, (t) => {
        if (stopped) return
        if (t === 'CONNECTED') { stopPolling(); setTransport('CONNECTED'); refresh(null) }
        else if (t === 'DISCONNECTED' || t === 'RECONNECTING') startPolling('FALLBACK_POLLING')
        else setTransport(t)
      })
    } else {
      startPolling('FALLBACK_POLLING')
    }
    // Even with a live socket the queue is read again now and then: an order must never be lost operationally.
    const safety = window.setInterval(() => { if (document.visibilityState === 'visible') refresh(null) }, Math.max(pollMs, 60000))
    const wake = () => { if (document.visibilityState === 'visible') refresh(null) }
    document.addEventListener('visibilitychange', wake); window.addEventListener('online', wake)
    return () => {
      stopped = true
      stopPolling(); window.clearInterval(safety); unwatch?.()
      if (debounce !== null) window.clearTimeout(debounce)
      document.removeEventListener('visibilitychange', wake); window.removeEventListener('online', wake)
      if (echo) { try { echo.leave(`restaurant.location.${locationUuid}`) } catch { /* gone */ } }
    }
  }, [locationUuid, enabled, pollMs])

  return { transport, lastNotice, newOrders, clearNewOrders: () => setNewOrders(0) }
}
