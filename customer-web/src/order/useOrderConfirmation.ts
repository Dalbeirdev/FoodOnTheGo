import { useCallback, useEffect, useMemo, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { MockPaymentRepository } from '../payment/mock/mockPayment'
import type { PaymentRepository } from '../payment/repositories'
import { MockOrderRepository, MockPickupVerificationRepository, MockReceiptRepository } from './mock/mockOrder'
import type { Order, OrderRepository, PickupVerification, PickupVerificationRepository, Receipt, ReceiptRepository } from './repositories'

/**
 * Centralized order-confirmation state (Module 13). Reloadable from the repository by order number — the page never depends on
 * transient navigation state. `pending-<paymentAttemptId>` references (Module 12 handoff) resolve to the created order, or to a
 * payment-pending / payment-failed state, without creating anything.
 */
export type ConfirmationStatus = 'LOADING' | 'CONFIRMED' | 'PAYMENT_PENDING' | 'PAYMENT_FAILED' | 'FAILED_TO_LOAD' | 'ORDER_NOT_FOUND' | 'CANCELLED'
export type OrderConfirmationDeps = { orders?: OrderRepository; verifications?: PickupVerificationRepository; receipts?: ReceiptRepository; payments?: PaymentRepository }
const defaults = { orders: new MockOrderRepository(), verifications: new MockPickupVerificationRepository(), receipts: new MockReceiptRepository(), payments: new MockPaymentRepository() }
export const orderRepositories = defaults

export function useOrderConfirmation(orderNumber: string, deps: OrderConfirmationDeps = {}) {
  const orders = deps.orders ?? defaults.orders; const verifications = deps.verifications ?? defaults.verifications; const receipts = deps.receipts ?? defaults.receipts; const payments = deps.payments ?? defaults.payments
  const auth = useAuth()
  const [status, setStatus] = useState<ConfirmationStatus>('LOADING')
  const [order, setOrder] = useState<Order | null>(null)
  const [verification, setVerification] = useState<PickupVerification | null>(null)
  const [receipt, setReceipt] = useState<Receipt | null>(null)
  const [redirectTo, setRedirectTo] = useState<string | null>(null)
  const [tick, setTick] = useState(0)
  const customerId = auth.user?.id ?? null

  useEffect(() => {
    let on = true
    setStatus('LOADING'); setOrder(null); setVerification(null); setReceipt(null); setRedirectTo(null)
    ;(async () => {
      try {
        if (!customerId) return
        if (orderNumber.startsWith('pending-')) {
          const attemptId = orderNumber.slice('pending-'.length)
          const existing = await orders.findByPaymentAttempt(attemptId)
          if (!on) return
          if (existing) { setRedirectTo(`/order-confirmation/${existing.orderNumber}`); return }
          const attempt = await payments.getAttempt(attemptId)
          if (!on) return
          if (!attempt) { setStatus('ORDER_NOT_FOUND'); return }
          if (attempt.status === 'PENDING' || attempt.status === 'UNKNOWN' || attempt.status === 'VERIFYING' || attempt.status === 'PROCESSING' || attempt.status === 'OPENING_PROVIDER' || attempt.status === 'SUCCESS_CLIENT_SIDE') { setStatus('PAYMENT_PENDING'); return }
          if (attempt.status === 'VERIFIED') { setRedirectTo('/payment'); return } // the payment page finishes the handoff (creates the order idempotently)
          setStatus('PAYMENT_FAILED'); return
        }
        const o = await orders.getByOrderNumber(orderNumber, customerId)
        if (!on) return
        if (!o) { setStatus('ORDER_NOT_FOUND'); return }
        setOrder(o)
        if (o.paymentStatus === 'PAYMENT_PENDING') { setStatus('PAYMENT_PENDING'); return }
        if (o.paymentStatus === 'FAILED') { setStatus('PAYMENT_FAILED'); return }
        if (o.orderStatus === 'CANCELLED' || o.orderStatus === 'REJECTED') { setStatus('CANCELLED'); return }
        const [pv, rc] = await Promise.all([verifications.getForOrder(o), receipts.getReceipt(o, auth.user?.name ?? null)])
        if (!on) return
        setVerification(pv); setReceipt(rc); setStatus('CONFIRMED')
      } catch { if (on) setStatus('FAILED_TO_LOAD') }
    })()
    return () => { on = false }
  }, [orderNumber, customerId, tick, orders, verifications, receipts, payments, auth.user?.name])

  const reload = useCallback(() => setTick((t) => t + 1), [])
  return useMemo(() => ({ status, order, verification, receipt, redirectTo, reload }), [status, order, verification, receipt, redirectTo, reload])
}
