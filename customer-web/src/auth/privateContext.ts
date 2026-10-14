import { saveManualScope } from '../discovery/scope'
import { resetJourneyApiState } from '../journey/api/apiJourney'

/**
 * Module 39 (C-06): what a customer sign-out removes from this browser.
 *
 * The customer's session token was always removed on sign-out, but the private context the pages keep in browser
 * storage stayed behind: the journey (origin and destination with their coordinates, the journey tokens, the recent
 * places), the checkout request and the accepted legal documents, the pickup selection, the payment attempt and the
 * manually chosen discovery location (localStorage, so it even outlived the tab — and it can be a saved address). On
 * a shared or kiosk computer the next person saw where the previous customer was going. Now every sign-out path
 * (the Sign Out button, a session the backend rejected with 401, an account that may no longer be used) removes:
 *  - every `fotg.journey.*`, `fotg.checkout.*` and `fotg.payment.*` key, in sessionStorage and localStorage;
 *  - `fotg.pickup.selection`, `fotg.discovery.scope` and `fotg.cart.note` — the free-text order note the customer
 *    typed on the Cart / Checkout page. Despite its name it is NOT part of the guest cart: the cart providers keep it
 *    on the device next to the promo field because it "belongs to checkout (Modules 29+), not to the cart" (it is
 *    sent as the checkout's orderNote and never stored on the backend cart the guest token names);
 * and resets the journey module's in-memory copies. The pages that hold the same data in React state listen for
 * PRIVATE_CONTEXT_CLEARED and start clean.
 *
 * Kept on purpose:
 *  - `fotg.cart.token`, the GUEST cart token (Module 27, documented and intended): it names an anonymous cart, never
 *    the customer's own one, which lives on the backend behind the customer's token;
 *  - the analytics session keys (Module 38): a random per-tab id, keyed and hashed on the backend, never a person;
 *  - the development `fotg.<area>.mode` switches, which belong to the developer and not to the customer.
 */
export const PRIVATE_CONTEXT_CLEARED = 'fotg:private-context-cleared'

const PRIVATE_PREFIXES = ['fotg.journey.', 'fotg.checkout.', 'fotg.payment.']
const PRIVATE_KEYS = new Set(['fotg.pickup.selection', 'fotg.discovery.scope', 'fotg.cart.note'])

/** Whether a storage key holds the signed-in customer's private context (a development mode switch never does). */
export function isPrivateContextKey(key: string): boolean {
  if (key.endsWith('.mode')) return false
  return PRIVATE_KEYS.has(key) || PRIVATE_PREFIXES.some((prefix) => key.startsWith(prefix))
}

const clearStore = (store: () => Storage) => {
  try {
    const s = store()
    const keys: string[] = []
    for (let i = 0; i < s.length; i++) { const k = s.key(i); if (k !== null && isPrivateContextKey(k)) keys.push(k) }
    for (const k of keys) s.removeItem(k)
  } catch { /* storage unavailable: nothing was kept there */ }
}

/** Removes the customer's private context from this browser and tells the pages to start clean. */
export function clearPrivateContext(): void {
  clearStore(() => sessionStorage)
  clearStore(() => localStorage)
  resetJourneyApiState()
  saveManualScope(null)
  try { window.dispatchEvent(new Event(PRIVATE_CONTEXT_CLEARED)) } catch { /* no window (non-browser test) */ }
}
