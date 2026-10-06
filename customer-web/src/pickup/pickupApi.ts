import { createContext, useContext } from 'react'
import type { Restaurant } from '../repositories/types'
import type { JourneyLike } from '../repositories/types'
import type { Cart } from '../cart/cartModel'
import type { ServerPickupState } from './api/apiPickup'
import type { PickupEstimate, PickupMode, PickupSelection, PickupSettings, PickupSlot, PickupValidation } from './repositories'

/**
 * Pickup time selection as the pages use it — one interface, two implementations chosen by pickupMode():
 *  - the development slot engine of Module 10 (mock mode: unit tests, share builds), and
 *  - the backend of Module 28 (API mode), where every time, capacity state and validity is the server's.
 *
 * NOT_SELECTED → LOADING_SLOTS → AVAILABLE → SELECTED → VALIDATING → (STALE | UNAVAILABLE | ERROR).
 */
export type PickupStatus = 'NOT_SELECTED' | 'LOADING_SLOTS' | 'AVAILABLE' | 'SELECTED' | 'VALIDATING' | 'STALE' | 'UNAVAILABLE' | 'ERROR'

export type LoadInput = { restaurant: Restaurant; cart: Cart; journey: JourneyLike | null; date?: string; prepMinutes: number; nowIso?: string }

export type PickupApi = {
  /** 'api' = the backend decides (Module 28); 'mock' = the in-browser development engine. */
  source: 'api' | 'mock'
  status: PickupStatus
  mode: PickupMode
  date: string | null
  days: string[]
  /** The slots of the selected restaurant-local day. */
  slots: PickupSlot[]
  settings: PickupSettings | null
  estimate: PickupEstimate | null
  eta: string | null
  selection: PickupSelection | null
  error: string | null
  staleReason: Exclude<PickupValidation, { ok: true }>['reason'] | null
  /** API mode: what the backend said beyond the fields above (issues, recommendation, journey freshness, capacity). */
  server: ServerPickupState | null
  /** A choice is on its way to the backend. */
  busy: boolean
  /**
   * The stored choice is known. API mode: false after a page load (or a cart change) until the backend has been asked
   * for the choice of this cart — until then "no selection" means "not known yet", and nothing may act on it.
   */
  ready: boolean
  /** The pickup method the answer is for (API mode: the restaurant's offered methods are in `settings.methods`). */
  method: string | null
  load: (input: LoadInput) => Promise<void>
  /** Asks again with what was asked last (after a cart change, on resume, when the answer has aged). */
  refresh: () => Promise<void>
  setMode: (mode: PickupMode) => void
  setDate: (date: string) => void
  setMethod: (method: string) => void
  selectSlot: (slot: PickupSlot) => void
  selectAsap: () => void
  validate: (restaurant: Restaurant, nowIso?: string) => Promise<PickupValidation>
  clear: () => void
}

export const PickupCtx = createContext<PickupApi | null>(null)

export function usePickup() {
  const ctx = useContext(PickupCtx)
  if (!ctx) throw new Error('usePickup must be used inside PickupProvider')
  return ctx
}
