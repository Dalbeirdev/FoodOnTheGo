import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useCart } from '../cart/cartApi'
import { ApiPickupGateway, toAnswer, toPickupError, toSelection, toSelectionState, toValidation, type PickupAnswer, type PickupChoice, type PickupSelectionDto, type ServerPickupState } from './api/apiPickup'
import { PickupCtx, type PickupApi, type PickupStatus } from './pickupApi'
import type { PickupEstimate, PickupMode, PickupSelection, PickupSettings, PickupSlot, PickupValidation } from './repositories'
import { localDateOf } from './time'
import { track } from '../analytics'

/**
 * Pickup time selection on the backend (Module 28), behind the same interface the pages already use.
 *
 * Nothing here calculates a time. The backend decides what can be chosen (on its clock, in the restaurant's time
 * zone), which slot is full, what is recommended, and whether an earlier choice still holds; this provider asks,
 * shows the answer and sends the customer's choice — a mode, a method and a slot key.
 *
 * The choice lives with the cart on the server, so it survives a reload and signing in. It is read again whenever the
 * cart changes (another item, another quantity: the preparation time may have moved), and before checkout goes on.
 * A selection is a choice, not a reservation: the page never says a time is "reserved".
 */
export function ServerPickupProvider({ children, gateway: given }: { children: ReactNode; gateway?: ApiPickupGateway }) {
  const cart = useCart()
  const [gateway] = useState(() => given ?? new ApiPickupGateway())
  const [status, setStatus] = useState<PickupStatus>('NOT_SELECTED')
  const [mode, setModeState] = useState<PickupMode>('asap')
  const [date, setDateState] = useState<string | null>(null)
  const [answer, setAnswer] = useState<PickupAnswer | null>(null)
  const [selection, setSelection] = useState<PickupSelection | null>(null)
  const [selectionState, setSelectionState] = useState<ServerPickupState['selection']>(null)
  const [error, setError] = useState<string | null>(null)
  const [staleReason, setStaleReason] = useState<PickupApi['staleReason']>(null)
  const [busy, setBusy] = useState(false)
  const [method, setMethodState] = useState<string | null>(null)
  // The cart (id and version) whose stored choice has been read — or could not be read, which is an answer too.
  const [knownFor, setKnownFor] = useState<string | null>(null)
  const seq = useRef(0)
  const first = useRef(true)

  const c = cart.cart
  const cartId = c?.id ?? null
  const cartVersion = c?.server?.version ?? null
  const signature = cartId ? `${cartId}:${cartVersion}` : 'no-cart'
  const signatureRef = useRef(signature)
  const cartRef = useRef<{ id: string; restaurantId: string } | null>(null)
  const answerRef = useRef<PickupAnswer | null>(null)
  const methodRef = useRef<string | null>(null)
  useEffect(() => { cartRef.current = c ? { id: c.id, restaurantId: c.restaurantId } : null; answerRef.current = answer; methodRef.current = method; signatureRef.current = signature })

  const applySelection = useCallback((dto: PickupSelectionDto | null) => {
    setSelection(toSelection(dto, cartRef.current ?? { id: '', restaurantId: '' }))
    setSelectionState(toSelectionState(dto))
    const result = toValidation(dto)
    setStaleReason(dto && !result.ok ? result.reason : null)
  }, [])

  const forget = useCallback(() => { seq.current++; first.current = true; setAnswer(null); setSelection(null); setSelectionState(null); setStaleReason(null); setError(null); setStatus('NOT_SELECTED') }, [])

  /** Asks the backend what can be chosen now. Everything the page shows afterwards is that answer. */
  const fetchAvailability = useCallback(async (forMethod: string | null = methodRef.current) => {
    const my = ++seq.current
    setStatus((s) => (s === 'SELECTED' || s === 'STALE' ? s : 'LOADING_SLOTS')); setError(null)
    try {
      const dto = await gateway.availability(forMethod)
      if (my !== seq.current) return
      const next = toAnswer(dto, cartRef.current ?? { id: '', restaurantId: '' })
      setAnswer(next); applySelection(dto.selection); setMethodState(dto.method); setKnownFor(signatureRef.current)
      const offered: PickupMode[] = [...(dto.asap.enabled ? (['asap'] as const) : []), ...(dto.scheduled.enabled ? (['scheduled'] as const) : [])]
      const chosen: PickupMode | null = dto.selection ? (dto.selection.mode === 'ASAP' ? 'asap' : 'scheduled') : null
      // The first answer decides the tab: the chosen mode, else ASAP when it is possible, else the slots. Later answers
      // leave the tab the customer is on alone. Either way never a mode the restaurant does not offer (any more):
      // with one mode there is no switch on the page to leave it by.
      const opening = first.current
      first.current = false
      setModeState((prev) => {
        const wanted: PickupMode = opening ? chosen ?? (dto.asap.available || !dto.scheduled.enabled ? 'asap' : 'scheduled') : prev
        return offered.includes(wanted) ? wanted : offered[0] ?? prev
      })
      const days = [...new Set(dto.slots.map((s) => s.local_date))]
      setDateState((prev) => (prev && days.includes(prev) ? prev : dto.selection && days.includes(dto.selection.local_date) ? dto.selection.local_date : days[0] ?? null))
      setStatus(dto.selection ? (dto.selection.valid ? 'SELECTED' : 'STALE') : dto.can_select ? 'AVAILABLE' : 'UNAVAILABLE')
    } catch (e) {
      if (my !== seq.current) return
      const err = toPickupError(e)
      // No cart, or nothing in it: there is nothing to choose a time for (the page sends the customer to the cart).
      if (err.code === 'no_cart' || err.code === 'cart_empty') { forget(); return }
      setError(err.message); setStatus('ERROR')
    }
  }, [gateway, applySelection, forget])

  // The choice belongs to the cart: read it again when the cart appears, changes or goes — checkout needs it after a
  // reload or a sign-in, and a changed cart may have made the chosen time unreachable.
  useEffect(() => {
    if (!cart.hydrated) return
    let alive = true
    const id = setTimeout(() => {
      if (!cartId) { forget(); setKnownFor(signature); return }
      gateway.selection().then((dto) => {
        if (!alive) return
        applySelection(dto); setKnownFor(signature)
        setStatus((s) => {
          if (s === 'LOADING_SLOTS' || s === 'VALIDATING') return s
          if (dto) return dto.valid ? 'SELECTED' : 'STALE'
          if (s !== 'SELECTED' && s !== 'STALE') return s
          return answerRef.current ? (answerRef.current.server.canSelect ? 'AVAILABLE' : 'UNAVAILABLE') : 'NOT_SELECTED'
        })
      }).catch(() => { if (alive) setKnownFor(signature) /* the pickup page reports a failed load itself; checkout validates again */ })
    }, 0)
    return () => { alive = false; clearTimeout(id) }
  }, [cart.hydrated, cartId, cartVersion, signature, gateway, applySelection, forget])

  const load = useCallback(async () => { await fetchAvailability() }, [fetchAvailability])
  const refresh = useCallback(async () => { await fetchAvailability() }, [fetchAvailability])

  const choose = useCallback(async (choice: PickupChoice) => {
    if (busy) return
    setBusy(true); setError(null)
    try {
      const dto = await gateway.select({ ...choice, method: methodRef.current, cartVersion: answerRef.current?.server.cartVersion ?? null })
      applySelection(dto); setModeState(choice.mode); setStatus('SELECTED')
      // Module 38 (telemetry, never awaited): the backend accepted the choice. "Recommended" only as far as the last answer on the screen said so.
      const offered = answerRef.current?.server
      track('pickup_selected', { restaurant: cart.cart?.server?.restaurantPublicId ?? cart.cart?.restaurantSlug }, { mode: dto.mode, method: dto.method, recommended: choice.mode === 'asap' ? offered?.asap.recommended : offered?.allSlots.find((s) => s.id === choice.slotKey)?.recommended })
    } catch (e) {
      const err = toPickupError(e)
      // The cart moved on under the page: show the cart the backend has, then what can be chosen for it.
      if (err.code === 'cart_changed' || err.code === 'cart_invalid' || err.code === 'cart_expired') void cart.reload()
      // A slot that just filled up (or passed) must be seen as such: ask again, then say what happened.
      if (err.code !== 'offline') await fetchAvailability()
      setError(err.message)
    } finally { setBusy(false) }
  }, [busy, gateway, applySelection, fetchAvailability, cart])

  const selectSlot = useCallback((slot: PickupSlot) => { if (slot.available) void choose({ mode: 'scheduled', slotKey: slot.id }) }, [choose])
  const selectAsap = useCallback(() => { void choose({ mode: 'asap' }) }, [choose])

  /** Before checkout goes on: the backend judges the stored choice again (cart, restaurant, capacity, clock). */
  const validate = useCallback(async (): Promise<PickupValidation> => {
    setStatus('VALIDATING')
    try {
      const dto = await gateway.selection()
      applySelection(dto)
      const result = toValidation(dto)
      setStatus(result.ok ? 'SELECTED' : dto ? 'STALE' : (answerRef.current?.server.canSelect === false ? 'UNAVAILABLE' : 'AVAILABLE'))
      if (!result.ok) void fetchAvailability()
      return result
    } catch (e) {
      // Without an answer nothing is confirmed: an old selection is never taken for a valid one.
      const err = toPickupError(e)
      setError(err.message); setStatus('ERROR')
      throw err
    }
  }, [gateway, applySelection, fetchAvailability])

  const clear = useCallback(() => {
    setSelection(null); setSelectionState(null); setStaleReason(null)
    setStatus(answerRef.current ? (answerRef.current.server.canSelect ? 'AVAILABLE' : 'UNAVAILABLE') : 'NOT_SELECTED')
    // (After an order the cart is gone before this runs: nothing left to clear is not an error.)
    gateway.clear().catch((e) => { const err = toPickupError(e); if (err.code !== 'no_cart' && err.code !== 'cart_empty') setError(err.message) })
  }, [gateway])

  const setMode = useCallback((m: PickupMode) => { setModeState(m) }, [])
  const setDate = useCallback((d: string) => { setDateState(d) }, [])
  const setMethod = useCallback((m: string) => { setMethodState(m); void fetchAvailability(m) }, [fetchAvailability])

  const api = useMemo<PickupApi>(() => {
    const server: ServerPickupState | null = answer ? { ...answer.server, selection: selectionState } : null
    const days = server ? [...new Set(server.allSlots.map((s) => s.localDate))] : []
    const today = server ? localDateOf(server.generatedAt, server.timezone) : null
    const settings: PickupSettings | null = answer && server ? {
      restaurantId: c?.restaurantId ?? '', timezone: server.timezone, intervalMinutes: server.scheduled.intervalMinutes ?? 15,
      minimumLeadMinutes: answer.preparation?.minimum_lead_minutes ?? 0, maximumScheduleAheadMinutes: 0, bufferMinutes: answer.preparation?.buffer_minutes ?? 0, acceptanceCutoffMinutes: 0,
      modes: [...(server.asap.enabled ? (['asap'] as const) : []), ...(server.scheduled.enabled ? (['scheduled'] as const) : [])],
      methods: answer.methods, instructions: answer.methods.find((m) => m.id === server.method)?.instructions,
    } : null
    const earliest = server?.asap.pickupAt ?? answer?.earliestPickupAt ?? null
    const estimate: PickupEstimate | null = answer?.preparation && server && earliest
      ? { prepMinutes: answer.preparation.estimated_minutes, bufferMinutes: answer.preparation.buffer_minutes, earliestPickupAt: earliest, restaurantTimezone: server.timezone, source: 'backend' } : null
    const shown = date ?? days[0] ?? today
    return {
      source: 'api', status, mode, date: shown, days: days.length > 0 ? days : today ? [today] : [],
      slots: server ? server.allSlots.filter((s) => s.localDate === shown) : [],
      settings, estimate, eta: server?.journey?.arrivalAt ?? null, selection, error, staleReason, server, busy, method, ready: cart.hydrated && knownFor === signature,
      load, refresh, setMode, setDate, setMethod, selectSlot, selectAsap, validate, clear,
    }
  }, [answer, selectionState, c?.restaurantId, date, status, mode, selection, error, staleReason, busy, method, cart.hydrated, knownFor, signature, load, refresh, setMode, setDate, setMethod, selectSlot, selectAsap, validate, clear])

  return <PickupCtx.Provider value={api}>{children}</PickupCtx.Provider>
}
