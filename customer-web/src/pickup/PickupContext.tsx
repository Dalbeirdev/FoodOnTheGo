import { createContext, useCallback, useContext, useMemo, useRef, useState, type ReactNode } from 'react'
import type { Restaurant } from '../repositories/types'
import type { JourneyLike } from '../repositories/types'
import type { Cart } from '../cart/cartModel'
import { etaService as defaultEta, pickupRepository as defaultRepo } from './mock/mockPickup'
import type { EtaService, PickupEstimate, PickupMode, PickupRepository, PickupSelection, PickupSettings, PickupSlot, PickupValidation } from './repositories'
import { addLocalDays, localDateOf } from './time'

/**
 * Centralized pickup state (Module 10). NOT_SELECTED → LOADING_SLOTS → AVAILABLE → SELECTED → VALIDATING → (STALE | UNAVAILABLE | ERROR).
 * The selection is kept in sessionStorage so it survives the login / OTP round trip before checkout.
 */
export type PickupStatus = 'NOT_SELECTED' | 'LOADING_SLOTS' | 'AVAILABLE' | 'SELECTED' | 'VALIDATING' | 'STALE' | 'UNAVAILABLE' | 'ERROR'

type LoadInput = { restaurant: Restaurant; cart: Cart; journey: JourneyLike | null; date?: string; prepMinutes: number; nowIso?: string }

type PickupApi = {
  status: PickupStatus
  mode: PickupMode
  date: string | null
  days: string[]
  slots: PickupSlot[]
  settings: PickupSettings | null
  estimate: PickupEstimate | null
  eta: string | null
  selection: PickupSelection | null
  error: string | null
  staleReason: Exclude<PickupValidation, { ok: true }>['reason'] | null
  load: (input: LoadInput) => Promise<void>
  setMode: (mode: PickupMode) => void
  setDate: (date: string) => void
  selectSlot: (slot: PickupSlot) => void
  selectAsap: () => void
  validate: (restaurant: Restaurant, nowIso?: string) => Promise<PickupValidation>
  clear: () => void
}

const KEY = 'fotg.pickup.selection'
const PickupCtx = createContext<PickupApi | null>(null)
const readStored = (): PickupSelection | null => { try { const raw = sessionStorage.getItem(KEY); return raw ? (JSON.parse(raw) as PickupSelection) : null } catch { return null } }
const store = (s: PickupSelection | null) => { try { if (s) sessionStorage.setItem(KEY, JSON.stringify(s)); else sessionStorage.removeItem(KEY) } catch { /* ignore */ } }

export function PickupProvider({ children, repository = defaultRepo, eta = defaultEta }: { children: ReactNode; repository?: PickupRepository; eta?: EtaService }) {
  const [status, setStatus] = useState<PickupStatus>(() => (readStored() ? 'SELECTED' : 'NOT_SELECTED'))
  const [mode, setModeState] = useState<PickupMode>(() => readStored()?.mode ?? 'asap')
  const [date, setDateState] = useState<string | null>(null)
  const [days, setDays] = useState<string[]>([])
  const [slots, setSlots] = useState<PickupSlot[]>([])
  const [settings, setSettings] = useState<PickupSettings | null>(null)
  const [estimate, setEstimate] = useState<PickupEstimate | null>(null)
  const [etaIso, setEtaIso] = useState<string | null>(null)
  const [selection, setSelectionState] = useState<PickupSelection | null>(readStored)
  const [error, setError] = useState<string | null>(null)
  const [staleReason, setStaleReason] = useState<PickupApi['staleReason']>(null)
  const seq = useRef(0)
  const lastInput = useRef<LoadInput | null>(null)

  const setSelection = useCallback((s: PickupSelection | null) => { setSelectionState(s); store(s) }, [])

  const load = useCallback(async (input: LoadInput) => {
    const my = ++seq.current
    lastInput.current = input
    const { restaurant, cart, journey, prepMinutes } = input
    const nowIso = input.nowIso ?? new Date().toISOString()
    // A selection made for another cart / restaurant is dropped (never silently reused).
    const stored = readStored()
    if (stored && (stored.cartId !== cart.id || stored.restaurantId !== restaurant.id)) { setSelection(null); setStatus('NOT_SELECTED') }
    setStatus((s) => (s === 'SELECTED' || s === 'STALE' ? s : 'LOADING_SLOTS')); setError(null)
    try {
      const [cfg, est] = await Promise.all([repository.getSettings(restaurant.id), repository.getEarliestPickup(restaurant.id, prepMinutes, nowIso)])
      if (my !== seq.current) return
      const arrival = eta.estimateArrival(restaurant, journey, nowIso)?.arrivalAt ?? null
      const today = localDateOf(nowIso, restaurant.timezone)
      const horizonDays = Math.max(1, Math.ceil(cfg.maximumScheduleAheadMinutes / (24 * 60)) + 1)
      const dayList = Array.from({ length: horizonDays }, (_, i) => addLocalDays(today, i))
      const day = input.date && dayList.includes(input.date) ? input.date : dayList[0]
      const list = await repository.getAvailablePickupSlots({ restaurantId: restaurant.id, date: day, nowIso, prepMinutes, etaIso: arrival })
      if (my !== seq.current) return
      setSettings(cfg); setEstimate(est); setEtaIso(arrival); setDays(dayList); setDateState(day); setSlots(list)
      if (!cfg.modes.includes(mode)) setModeState(cfg.modes[0])
      setStatus((s) => (s === 'SELECTED' || s === 'STALE' ? s : list.some((x) => x.available) || cfg.modes.includes('asap') ? 'AVAILABLE' : 'UNAVAILABLE'))
    } catch (e) {
      if (my !== seq.current) return
      setError(e instanceof Error ? e.message : String(e)); setStatus('ERROR')
    }
  }, [repository, eta, mode, setSelection])

  const setMode = useCallback((m: PickupMode) => { setModeState(m); setSelection(null); setStaleReason(null); setStatus('AVAILABLE') }, [setSelection])
  const setDate = useCallback((d: string) => { const input = lastInput.current; if (input) void load({ ...input, date: d }) }, [load])

  const selectSlot = useCallback((slot: PickupSlot) => {
    const input = lastInput.current
    if (!slot.available || !input) return
    const ready = estimate?.earliestPickupAt ?? slot.startAt
    setSelection({ mode: 'scheduled', slotId: slot.id, requestedAt: slot.startAt, restaurantTimezone: slot.timezone, estimatedCustomerArrival: etaIso, estimatedReadyTime: Date.parse(ready) > Date.parse(slot.startAt) ? ready : slot.startAt, confirmedDisplayTime: '', cartId: input.cart.id, restaurantId: input.restaurant.id })
    setModeState('scheduled'); setStaleReason(null); setStatus('SELECTED')
  }, [estimate, etaIso, setSelection])

  const selectAsap = useCallback(() => {
    const input = lastInput.current
    if (!input || !estimate) return
    setSelection({ mode: 'asap', slotId: null, requestedAt: estimate.earliestPickupAt, restaurantTimezone: estimate.restaurantTimezone, estimatedCustomerArrival: etaIso, estimatedReadyTime: estimate.earliestPickupAt, confirmedDisplayTime: '', cartId: input.cart.id, restaurantId: input.restaurant.id })
    setModeState('asap'); setStaleReason(null); setStatus('SELECTED')
  }, [estimate, etaIso, setSelection])

  const validate = useCallback(async (restaurant: Restaurant, nowIso = new Date().toISOString()): Promise<PickupValidation> => {
    if (!selection) return { ok: false, reason: 'slot_missing' }
    setStatus('VALIDATING')
    try {
      const res = await repository.validatePickupSelection(restaurant, selection, nowIso)
      if (res.ok) { setStatus('SELECTED'); setStaleReason(null) } else { setStatus('STALE'); setStaleReason(res.reason) }
      return res
    } catch (e) { setError(e instanceof Error ? e.message : String(e)); setStatus('ERROR'); throw e }
  }, [repository, selection])

  const clear = useCallback(() => { setSelection(null); setStaleReason(null); setStatus('NOT_SELECTED') }, [setSelection])

  const api = useMemo<PickupApi>(() => ({ status, mode, date, days, slots, settings, estimate, eta: etaIso, selection, error, staleReason, load, setMode, setDate, selectSlot, selectAsap, validate, clear }), [status, mode, date, days, slots, settings, estimate, etaIso, selection, error, staleReason, load, setMode, setDate, selectSlot, selectAsap, validate, clear])
  return <PickupCtx.Provider value={api}>{children}</PickupCtx.Provider>
}

export function usePickup() {
  const ctx = useContext(PickupCtx)
  if (!ctx) throw new Error('usePickup must be used inside PickupProvider')
  return ctx
}
