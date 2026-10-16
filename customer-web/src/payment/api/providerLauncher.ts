import type { ProviderReport, ProviderSession } from './apiPayment'
import { userMessage } from '../../api/userMessage'

/**
 * Opens the PROVIDER'S OWN payment interface (Module 30) and resolves with what it tells the browser.
 *
 * Razorpay Standard Checkout, as documented by Razorpay (reviewed 2026-10-07): the page loads
 * https://checkout.razorpay.com/v1/checkout.js, creates `new Razorpay(options)` with the PUBLIC key id and the order
 * the backend created, and calls open(). On success the handler receives razorpay_payment_id and razorpay_signature;
 * a failed try raises "payment.failed" and the window stays open for another try; closing it calls modal.ondismiss.
 *
 * What comes back is a REPORT for the backend to verify — this file never decides that a payment succeeded — and
 * nothing the customer types into the provider's window passes through FoodOnTheGo.
 */

type RazorpaySuccess = { razorpay_payment_id?: string; razorpay_order_id?: string; razorpay_signature?: string }
type RazorpayFailure = { error?: { code?: string; reason?: string; description?: string } }
type RazorpayInstance = { open(): void; on(event: 'payment.failed', handler: (response: RazorpayFailure) => void): void }
declare global { interface Window { Razorpay?: new (options: Record<string, unknown>) => RazorpayInstance } }

/** The only place a provider script may come from. A URL from anywhere else is not loaded, whoever names it. */
const SCRIPT_ORIGINS = ['https://checkout.razorpay.com']
const METHOD: Record<string, string> = { UPI: 'upi', CARD: 'card', NET_BANKING: 'netbanking', WALLET: 'wallet' }

const loading = new Map<string, Promise<void>>()

export function loadProviderScript(src: string): Promise<void> {
  let origin = ''
  try { origin = new URL(src).origin } catch { /* not a URL */ }
  if (!SCRIPT_ORIGINS.includes(origin)) return Promise.reject(new Error('provider_script_not_allowed'))
  const pending = loading.get(src)
  if (pending) return pending
  const promise = new Promise<void>((resolve, reject) => {
    const script = document.createElement('script')
    script.src = src; script.async = true
    script.onload = () => resolve()
    script.onerror = () => { loading.delete(src); script.remove(); reject(new Error('provider_script_failed')) }
    document.head.appendChild(script)
  })
  loading.set(src, promise)
  return promise
}

export type ProviderLauncher = (session: ProviderSession) => Promise<ProviderReport>

/** Thrown when the provider's interface could not be opened at all. Nothing was paid; the attempt can be opened again. */
export class ProviderUnavailableError extends Error {}

export const launchRazorpay: ProviderLauncher = async (session) => {
  if (!session.keyId || !session.checkoutScript) throw new ProviderUnavailableError('provider_not_configured')
  try { await loadProviderScript(session.checkoutScript) } catch (e) { throw new ProviderUnavailableError(userMessage(e, 'provider_script_failed')) }
  const Razorpay = window.Razorpay
  if (!Razorpay) throw new ProviderUnavailableError('provider_script_failed')

  return new Promise<ProviderReport>((resolve) => {
    let settled = false
    let lastError: { code?: string | null; reason?: string | null } | null = null
    const finish = (report: ProviderReport) => { if (!settled) { settled = true; resolve(report) } }
    const checkout = new Razorpay({
      // The public key id and the order come from the backend; the amount of the order is the backend's.
      key: session.keyId,
      order_id: session.orderReference,
      amount: session.amountMinor,
      currency: session.currency,
      name: session.name,
      description: session.description,
      prefill: { name: session.prefill.name ?? undefined, contact: session.prefill.contact, email: session.prefill.email ?? undefined, method: session.preferredMethod ? METHOD[session.preferredMethod] : undefined },
      theme: { color: session.themeColor },
      timeout: session.timeoutSeconds,
      handler: (response: RazorpaySuccess) => {
        // The provider says it worked. That is a claim with a proof attached — the backend checks both.
        if (response.razorpay_payment_id && response.razorpay_signature) finish({ outcome: 'success', reference: response.razorpay_payment_id, signature: response.razorpay_signature })
        else finish({ outcome: 'failed', error: { reason: 'incomplete_provider_response' } })
      },
      modal: {
        confirm_close: true,
        // Closed by the customer: after a failed try it is a failure, otherwise a cancellation. Either way the backend
        // asks the provider what really happened before anything is concluded.
        ondismiss: () => finish(lastError ? { outcome: 'failed', error: lastError } : { outcome: 'cancelled' }),
      },
    })
    checkout.on('payment.failed', (response) => { lastError = { code: response.error?.code ?? null, reason: response.error?.reason ?? null } })
    checkout.open()
  })
}

export const launcherFor = (provider: string): ProviderLauncher | null => (provider === 'razorpay' ? launchRazorpay : null)
