/**
 * Customer Web on the backend's refund domain (Module 35). The network is stubbed with payloads in the shape of
 * GET /customer/orders/{order} and the cancellation endpoints; the tests check how the order's refunds are read, what
 * the pages show for each refund state, and what a cancellation SENDS (a reason — never an amount, an effect or a status).
 */
import { act, cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AccountProvider } from '../../account/AccountContext'
import { tokens } from '../../api/client'
import { AuthProvider } from '../../auth/AuthContext'
import RequireAuth from '../../auth/RequireAuth'
import { CartProvider } from '../../cart/CartContext'
import { MemoryCartRepository } from '../../cart/cartModel'
import { LocaleProvider } from '../../i18n/LocaleProvider'
import OrderDetailsPage from '../../pages/OrderDetailsPage'
import OrderTrackingPage from '../../pages/OrderTrackingPage'
import CancelOrderDialog from '../CancelOrderDialog'
import { hasRefund, refundOpen } from '../history'
import { MockPickupVerificationRepository } from '../mock/mockOrder'
import { MockReceiptRepository } from '../mock/index'
import RefundList from '../RefundList'
import type { Order, OrderEvent } from '../repositories'
import { reduceOrder, type OrderTrackingService, type TrackingListener } from '../tracking'
import { ApiOrderRepository, eventsFromApi, paymentStatusFromApi, toOrder, toSummary, type OrderDto, type OrderRefundsDto, type RefundDto } from './apiOrder'

const BASE = 'http://127.0.0.1:8001/api/v1'
const N = 'FOTG-20261012-ABC234'
const money = (amount: number) => ({ amount, currency: 'INR' })
const refundItem = (patch: Partial<RefundDto> = {}): RefundDto => ({
  id: '0e000000-0000-4000-8000-000000000001', reference: 'RFD-0E00000000', type: 'FULL', amount: money(50000), status: 'REQUIRED',
  reason: { code: 'RESTAURANT_REJECTED', label: 'The restaurant could not accept the order' }, requested_at: '2026-10-12T05:00:00+00:00', completed_at: null, ...patch,
})
const refundBlock = (status: OrderRefundsDto['status'], items: RefundDto[], refunded = 0, pending = 50000): OrderRefundsDto => ({ status, paid: money(50000), refunded: money(refunded), pending: money(pending), items, timing_note: null })
const line = (sequence: number, type: string, to: string | null, detail: Record<string, unknown> = {}) => ({ sequence, event_id: `o1-${sequence}`, at: `2026-10-12T05:0${sequence}:00+00:00`, type, from: null, to, actor: 'FOODONTHEGO', detail })
const dto = (patch: Partial<OrderDto> = {}): OrderDto => ({
  id: '0a000000-0000-4000-8000-000000000001', order_number: N, status: 'REJECTED', payment_state: 'PAID', refund_status: 'REQUIRED',
  restaurant: { id: 'loc-1', slug: 'burger-hub', name: 'Burger Hub', branch_label: null, formatted_address: 'Sector 62, Noida', phone: null, timezone: 'Asia/Kolkata', pickup_instructions: null },
  item_count: 2, total: money(50000),
  pickup: { mode: 'ASAP', method: 'COUNTER', pickup_at: '2026-10-12T06:00:00+00:00', pickup_at_local: null, slot: { starts_at: null, ends_at: null }, timezone: 'Asia/Kolkata', estimated_prep_minutes: 20, traveler_arrival_at: null },
  placed_at: '2026-10-12T05:00:00+00:00', updated_at: '2026-10-12T05:05:00+00:00',
  items: [{ id: 'i1', item_id: 'm1', slug: 'classic-burger', name: 'Classic Burger', quantity: 2, unit_price: money(25000), line_subtotal: money(50000), special_instructions: null, options: [] }],
  pricing: { currency: 'INR', subtotal: money(50000), discount: money(0), tax: money(0), tax_included: money(0), fee: money(0), total: money(50000) },
  promotion: null, taxes: [], fees: [],
  payment: { required: true, id: '0b000000-0000-4000-8000-000000000001', provider: 'fake', method: { type: 'UPI', summary: { vpa: 'ra***@fake' } }, verified_at: '2026-10-12T05:00:30+00:00' },
  customer_note: null, journey: null,
  rejection: { reason: 'ITEM_UNAVAILABLE', message: 'The restaurant could not accept your order: an item is no longer available.' }, cancellation: null,
  pickup_credential: null,
  timeline: [line(1, 'ORDER_CREATED', 'WAITING_FOR_RESTAURANT'), line(2, 'RESTAURANT_REJECTED', 'REJECTED', { reason: 'ITEM_UNAVAILABLE' }), line(3, 'REFUND_REQUIRED', null, { amount_minor: 50000, currency: 'INR', refund_status: 'REQUIRED' })] as OrderDto['timeline'],
  next_action: 'AWAIT_REFUND', times: {}, version: 3,
  refund: refundBlock('REQUIRED', [refundItem()]),
  customer_cancellation: { enabled: false, can_cancel: false, reason: 'CUSTOMER_CANCELLATION_DISABLED' },
  ...patch,
})

type Call = { method: string; path: string; body: Record<string, unknown> | null }
let calls: Call[] = []
let routes: Record<string, { status?: number; body?: unknown }> = {}
beforeEach(() => {
  calls = []; routes = {}; tokens.set('customer', 'customer-session')
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const path = decodeURIComponent(url.replace(BASE, '')); const method = init.method ?? 'GET'
    calls.push({ method, path, body: init.body ? JSON.parse(init.body as string) : null })
    const r = routes[`${method} ${path.split('?')[0]}`]
    if (!r) return new Response(JSON.stringify({ error: { code: 'not_found', message: `No stub for ${method} ${path}`, details: {} } }), { status: 404 })
    return new Response(JSON.stringify(r.body ?? {}), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
})
afterEach(() => { cleanup(); vi.unstubAllGlobals(); vi.useRealTimers(); tokens.set('customer', null); localStorage.clear(); sessionStorage.clear() })

describe('Module 35 — the order\'s refunds as the backend sends them', () => {
  it('reads the derived refund status without inferring anything from the order\'s state', () => {
    expect(paymentStatusFromApi('NONE')).toBe('PAID')
    expect(paymentStatusFromApi('REQUIRED')).toBe('REFUND_PENDING')
    expect(paymentStatusFromApi('PROCESSING')).toBe('REFUND_PROCESSING')
    expect(paymentStatusFromApi('PARTIALLY_REFUNDED')).toBe('PARTIALLY_REFUNDED')
    expect(paymentStatusFromApi('REFUNDED')).toBe('REFUNDED')
    // A refund that failed or needs a person is "under review" — never "payment failed", never "refunded".
    // The customer's answers say UNDER_REVIEW; the internal words read the same should they ever arrive.
    expect(paymentStatusFromApi('UNDER_REVIEW')).toBe('REFUND_UNDER_REVIEW')
    expect(paymentStatusFromApi('FAILED')).toBe('REFUND_UNDER_REVIEW')
    expect(paymentStatusFromApi('REVIEW_REQUIRED')).toBe('REFUND_UNDER_REVIEW')
    // The names of a backend older than Module 35 are still read; an unknown value is not turned into a refund state.
    expect(paymentStatusFromApi('PENDING')).toBe('REFUND_PROCESSING')
    expect(paymentStatusFromApi('PARTIAL')).toBe('PARTIALLY_REFUNDED')
    expect(paymentStatusFromApi('SOMETHING_NEW')).toBe('PAID')
    expect([hasRefund('REFUND_PROCESSING'), hasRefund('REFUND_UNDER_REVIEW'), hasRefund('PAID')]).toEqual([true, true, false])
    expect([refundOpen('REFUND_PENDING'), refundOpen('REFUND_PROCESSING'), refundOpen('REFUND_UNDER_REVIEW'), refundOpen('REFUNDED'), refundOpen('PARTIALLY_REFUNDED')]).toEqual([true, true, true, false, false])

    // A rejected, paid order: REJECTED and "refund pending" are two facts; nothing is refunded yet.
    const required = toOrder(dto(), 'u1')
    expect([required.orderStatus, required.paymentStatus, required.payment.paidAmountMinor, required.payment.refundedAmountMinor]).toEqual(['REJECTED', 'REFUND_PENDING', 50000, 0])
    expect(required.refunds).toEqual({ refundedMinor: 0, pendingMinor: 50000, items: [{ id: '0e000000-0000-4000-8000-000000000001', reference: 'RFD-0E00000000', type: 'FULL', amountMinor: 50000, currency: 'INR', status: 'REQUIRED', reasonLabel: 'The restaurant could not accept the order', requestedAt: '2026-10-12T05:00:00+00:00', completedAt: null }] })
    expect(required.cancellation).toEqual({ enabled: false, canCancel: false, reason: 'CUSTOMER_CANCELLATION_DISABLED' })

    // A partial refund of a completed order: the amount is what the provider confirmed — not the order total, not nothing.
    const partial = toOrder(dto({ status: 'COMPLETED', refund_status: 'PARTIALLY_REFUNDED', rejection: null, refund: refundBlock('PARTIALLY_REFUNDED', [refundItem({ type: 'PARTIAL', amount: money(20000), status: 'REFUNDED', completed_at: '2026-10-12T07:00:00+00:00' })], 20000, 0) }), 'u1')
    expect([partial.orderStatus, partial.paymentStatus, partial.payment.refundedAmountMinor, partial.refunds?.items[0].status]).toEqual(['COMPLETED', 'PARTIALLY_REFUNDED', 20000, 'REFUNDED'])
    expect(toSummary(dto({ refund_status: 'UNDER_REVIEW' })).paymentStatus).toBe('REFUND_UNDER_REVIEW')

    // An order that was never paid for has no refund block; an answer of an older backend has none either.
    const free = toOrder(dto({ payment_state: 'PAYMENT_NOT_REQUIRED', refund_status: 'NONE', refund: null, payment: { required: false, id: null, provider: null, method: null, verified_at: null } }), 'u1')
    expect([free.refunds, free.paymentStatus, free.payment.refundedAmountMinor]).toEqual([undefined, 'PAID', null])
    const old = toOrder({ ...dto({ refund_status: 'REFUNDED' }), refund: undefined, customer_cancellation: undefined }, 'u1')
    expect([old.refunds, old.cancellation, old.payment.refundedAmountMinor]).toEqual([undefined, undefined, 50000])
  })

  it('turns every refund line of the timeline into an event that carries the status the backend derived', () => {
    const d = dto({
      refund_status: 'REFUNDED',
      timeline: [
        line(1, 'ORDER_CREATED', 'WAITING_FOR_RESTAURANT'), line(2, 'RESTAURANT_REJECTED', 'REJECTED', { reason: 'ITEM_UNAVAILABLE' }),
        line(3, 'REFUND_REQUIRED', null, { refund_status: 'REQUIRED' }), line(4, 'REFUND_PROCESSING', null, { refund_status: 'PROCESSING' }),
        line(5, 'REFUND_UNDER_REVIEW', null, { refund_status: 'UNDER_REVIEW' }), line(6, 'REFUND_PROCESSING', null, { refund_status: 'PROCESSING' }), line(7, 'REFUND_COMPLETED', null, { refund_status: 'REFUNDED' }),
        line(8, 'SOMETHING_FROM_THE_FUTURE', null),
      ] as OrderDto['timeline'],
    })
    const events = eventsFromApi(d).filter((e) => e.type === 'REFUND_UPDATED')
    expect(events.map((e) => e.paymentStatus)).toEqual(['REFUND_PENDING', 'REFUND_PROCESSING', 'REFUND_UNDER_REVIEW', 'REFUND_PROCESSING', 'REFUNDED'])
    expect(events.every((e) => e.status === null)).toBe(true)   // a refund never changes the order's own status
    // A line without the derived status (a backend older than Module 35) keeps the meaning of its own name.
    expect(eventsFromApi(dto({ timeline: [line(1, 'REFUND_REQUIRED', null)] as OrderDto['timeline'] })).pop()?.paymentStatus).toBe('REFUND_PENDING')

    // Applied to a rejected order on screen: the order stays REJECTED while the money state moves on.
    let shown: Order = toOrder(dto({ timeline: d.timeline.slice(0, 3) }), 'u1')
    for (const e of eventsFromApi(d) as OrderEvent[]) shown = reduceOrder(shown, e).order
    expect([shown.orderStatus, shown.paymentStatus]).toEqual(['REJECTED', 'REFUNDED'])
  })

  it('asks the backend what cancelling would mean and sends a reason — never an amount, an effect or a status', async () => {
    const repo = new ApiOrderRepository()
    const quote = { enabled: true, policy_status: 'CONFIGURED', policy_version: 'p1', can_cancel: true, reason: null, message: 'If you cancel now, a refund of your payment is due.', financial_effect: 'FULL_REFUND', refundable_amount: money(50000), reasons: ['CHANGED_MIND', 'OTHER'], order: { id: 'o1', order_number: N, status: 'WAITING_FOR_RESTAURANT', version: 2 } }
    routes[`GET /customer/orders/${N}/cancellation`] = { body: { cancellation: quote } }
    expect(await repo.cancellationQuote(N)).toEqual({ enabled: true, canCancel: true, reason: null, message: 'If you cancel now, a refund of your payment is due.', financialEffect: 'FULL_REFUND', refundableMinor: 50000, currency: 'INR', reasons: ['CHANGED_MIND', 'OTHER'], version: 2 })

    routes[`POST /customer/orders/${N}/cancel`] = { body: { order: dto({ status: 'CANCELLED', rejection: null, cancellation: { by: 'YOU', message: 'This order was cancelled. A refund is due — its status is shown with this order.' } }) } }
    const after = await repo.cancel(N, { reasonCode: 'CHANGED_MIND', note: 'Plans changed', expectedVersion: 2 })
    expect([after?.orderStatus, after?.paymentStatus, after?.cancellationMessage]).toEqual(['CANCELLED', 'REFUND_PENDING', 'This order was cancelled. A refund is due — its status is shown with this order.'])
    expect(calls.at(-1)).toEqual({ method: 'POST', path: `/customer/orders/${N}/cancel`, body: { reason_code: 'CHANGED_MIND', note: 'Plans changed', expected_version: 2 } })
    await repo.cancel(N, { reasonCode: 'OTHER', note: null, expectedVersion: null })
    expect(calls.at(-1)?.body).toEqual({ reason_code: 'OTHER' })
    expect(JSON.stringify(calls.map((c) => c.body))).not.toMatch(/amount|refund|currency|status|effect/)

    // The backend's refusal reaches the caller as it is (the policy is disabled, or the order moved on).
    routes[`POST /customer/orders/${N}/cancel`] = { status: 409, body: { error: { code: 'order_cancellation_not_allowed', message: 'Orders cannot be cancelled in the app yet.', details: { reason: 'CUSTOMER_CANCELLATION_DISABLED' } } } }
    await expect(repo.cancel(N, { reasonCode: 'CHANGED_MIND', note: null, expectedVersion: null })).rejects.toMatchObject({ status: 409, code: 'order_cancellation_not_allowed' })
    // Somebody else's order is "not found" for the quote.
    expect(await repo.cancellationQuote('FOTG-20261012-ZZZ999')).toBeNull()
  })
})

describe('Module 35 — what the customer is shown', () => {
  const order = (patch: Partial<OrderDto> = {}) => toOrder(dto(patch), 'u1')

  it('lists each refund with its amount, status and reference, and calls only confirmed money "refunded"', () => {
    const { rerender } = render(<RefundList order={order()} locale="en-IN" />)
    const block = screen.getByTestId('od-refunds')
    expect(within(block).getByTestId('od-refund-confirmed')).toHaveTextContent('₹0.00')
    expect(within(block).getByTestId('od-refund-pending')).toHaveTextContent('₹500.00')
    expect(within(block).getByTestId('od-refund-status')).toHaveTextContent('Refund due')
    expect(within(block).getByTestId('od-refund-reference')).toHaveTextContent('RFD-0E00000000')
    expect(block).toHaveTextContent('The restaurant could not accept the order')
    expect(block).not.toHaveTextContent(/completed by the payment provider/i)

    rerender(<RefundList order={order({ refund_status: 'PROCESSING', refund: refundBlock('PROCESSING', [refundItem({ status: 'PROCESSING' })]) })} locale="en-IN" />)
    expect(screen.getByTestId('od-refund-status')).toHaveTextContent('Processing')
    expect(screen.getByTestId('od-refunds')).toHaveTextContent('It is not completed yet')
    expect(screen.getByTestId('od-refund-confirmed')).toHaveTextContent('₹0.00')

    rerender(<RefundList order={order({ refund_status: 'UNDER_REVIEW', refund: refundBlock('UNDER_REVIEW', [refundItem({ status: 'UNDER_REVIEW' })]) })} locale="en-IN" />)
    expect(screen.getByTestId('od-refund-status')).toHaveTextContent('Under review')
    expect(screen.getByTestId('od-refunds')).toHaveTextContent('you do not need to do anything')
    expect(screen.getByTestId('od-refunds')).not.toHaveTextContent(/failed/i)

    // Two partial refunds of a completed order: each one is shown; the totals are the backend's.
    rerender(<RefundList order={order({ status: 'COMPLETED', rejection: null, refund_status: 'PROCESSING', refund: refundBlock('PROCESSING', [
      refundItem({ id: 'r1', reference: 'RFD-AAAAAAAAAA', type: 'PARTIAL', amount: money(20000), status: 'REFUNDED', completed_at: '2026-10-12T07:00:00+00:00', reason: { code: 'ITEM_UNAVAILABLE', label: 'An item was not available' } }),
      refundItem({ id: 'r2', reference: 'RFD-BBBBBBBBBB', type: 'PARTIAL', amount: money(10000), status: 'PROCESSING', reason: { code: 'OTHER', label: 'Adjustment by FoodOnTheGo support' } }),
    ], 20000, 10000) })} locale="en-IN" />)
    const items = screen.getAllByTestId('od-refund-item')
    expect(items.map((i) => i.getAttribute('data-status'))).toEqual(['REFUNDED', 'PROCESSING'])
    expect(items[0]).toHaveTextContent('₹200.00'); expect(items[0]).toHaveTextContent('part of the payment'); expect(items[1]).toHaveTextContent('₹100.00')
    expect(screen.getByTestId('od-refund-confirmed')).toHaveTextContent('₹200.00'); expect(screen.getByTestId('od-refund-pending')).toHaveTextContent('₹100.00')
    // No number of days is promised anywhere in the block.
    expect(screen.getByTestId('od-refunds').textContent).not.toMatch(/\d+\s*(–|-|to)?\s*\d*\s*(working |business )?days/i)

    // Nothing to show: no block at all (an order without refunds, an order that was never paid for).
    rerender(<RefundList order={order({ refund_status: 'NONE', refund: refundBlock('NONE', [], 0, 0) })} locale="en-IN" />)
    expect(screen.queryByTestId('od-refunds')).toBeNull()
  })

  const USER = { id: 'u1', name: 'Dev Tester', phone: '+919876543210', email: 'dev@example.com', memberSince: '2026-01-01' }
  const signIn = () => { localStorage.setItem('fotg.mock.customers', JSON.stringify([USER])); sessionStorage.setItem('fotg.mock.session', JSON.stringify({ token: 'dev', userId: 'u1', expiresAt: Date.now() + 3600000 })) }

  it('the order page follows the refund from due to processing to refunded without a reload, and never says refunded early', async () => {
    signIn()
    vi.useFakeTimers({ shouldAdvanceTime: true })
    routes[`GET /customer/orders/${N}`] = { body: { order: dto() } }
    render(
      <LocaleProvider><AuthProvider><AccountProvider><CartProvider repository={new MemoryCartRepository()}>
        <MemoryRouter initialEntries={[`/order/${N}`]}>
          <Routes><Route path="/order/:orderNumber" element={<RequireAuth><OrderDetailsPage deps={{ orders: new ApiOrderRepository(), receipts: new MockReceiptRepository() }} /></RequireAuth>} /><Route path="*" element={<p>other</p>} /></Routes>
        </MemoryRouter>
      </CartProvider></AccountProvider></AuthProvider></LocaleProvider>,
    )
    await screen.findByTestId('od-refunds')
    expect(screen.getByTestId('od-order-status')).toHaveTextContent('Rejected'); expect(screen.getByTestId('od-payment-status')).toHaveTextContent('Refund pending')
    expect(screen.getByTestId('od-refund-status')).toHaveTextContent('Refund due')
    expect(screen.queryByTestId('od-refunded')).toBeNull()   // the old single-figure block is not shown beside the real refunds

    // The administrator approves and sends it; the page asks again by itself.
    routes[`GET /customer/orders/${N}`] = { body: { order: dto({ refund_status: 'PROCESSING', refund: refundBlock('PROCESSING', [refundItem({ status: 'PROCESSING' })]) }) } }
    await act(async () => { await vi.advanceTimersByTimeAsync(8100) })
    await waitFor(() => expect(screen.getByTestId('od-refund-status')).toHaveTextContent('Processing'))
    expect(screen.getByTestId('od-payment-status')).toHaveTextContent('Refund processing'); expect(screen.getByTestId('od-refund-confirmed')).toHaveTextContent('₹0.00')

    // The provider confirms: refunded — and the order is still Rejected.
    routes[`GET /customer/orders/${N}`] = { body: { order: dto({ refund_status: 'REFUNDED', refund: refundBlock('REFUNDED', [refundItem({ status: 'REFUNDED', completed_at: '2026-10-12T06:00:00+00:00' })], 50000, 0) }) } }
    await act(async () => { await vi.advanceTimersByTimeAsync(8100) })
    await waitFor(() => expect(screen.getByTestId('od-refund-status')).toHaveTextContent('Refunded'))
    expect(screen.getByTestId('od-order-status')).toHaveTextContent('Rejected'); expect(screen.getByTestId('od-payment-status')).toHaveTextContent('Refunded'); expect(screen.getByTestId('od-refund-confirmed')).toHaveTextContent('₹500.00')
    // Settled: the page stops asking.
    const reads = calls.filter((c) => c.method === 'GET').length
    await act(async () => { await vi.advanceTimersByTimeAsync(20000) })
    expect(calls.filter((c) => c.method === 'GET').length).toBe(reads)
  })

  it('shows a cancel action only when the backend says the customer may cancel, and the backend decides what it means', async () => {
    signIn()
    const active = (cancellation: OrderDto['customer_cancellation']) => dto({ status: 'WAITING_FOR_RESTAURANT', refund_status: 'NONE', rejection: null, refund: refundBlock('NONE', [], 0, 0), timeline: [line(1, 'ORDER_CREATED', 'WAITING_FOR_RESTAURANT')] as OrderDto['timeline'], pickup_credential: { code: '123456', qr_payload: 'qr', status: 'ACTIVE' }, customer_cancellation: cancellation })
    const quiet: OrderTrackingService = { subscribe: (_o: Order, l: TrackingListener) => { l.onConnection('LIVE'); return () => {} }, refresh: async (n: string, c: string) => new ApiOrderRepository().getByOrderNumber(n, c) }
    const mount = () => render(
      <LocaleProvider><AuthProvider><CartProvider repository={new MemoryCartRepository()}>
        <MemoryRouter initialEntries={[`/order-tracking/${N}`]}>
          <Routes><Route path="/order-tracking/:orderNumber" element={<RequireAuth><OrderTrackingPage deps={{ orders: new ApiOrderRepository(), verifications: new MockPickupVerificationRepository(), tracking: quiet }} /></RequireAuth>} /><Route path="*" element={<p>other</p>} /></Routes>
        </MemoryRouter>
      </CartProvider></AuthProvider></LocaleProvider>,
    )

    // The policy is not approved: no cancel button at all — and a plain sentence instead of a dead control.
    routes[`GET /customer/orders/${N}`] = { body: { order: active({ enabled: false, can_cancel: false, reason: 'CUSTOMER_CANCELLATION_DISABLED' }) } }
    const first = mount()
    await screen.findByTestId('trk-status')
    expect(screen.queryByTestId('trk-cancel')).toBeNull(); expect(screen.queryByRole('button', { name: /cancel order/i })).toBeNull()
    expect(screen.getByTestId('trk-cancel-unavailable')).toHaveTextContent('Orders cannot be cancelled in the app yet')
    first.unmount()

    // A policy is switched on and allows this order: the button opens the backend's quote.
    routes[`GET /customer/orders/${N}`] = { body: { order: active({ enabled: true, can_cancel: true, reason: null }) } }
    routes[`GET /customer/orders/${N}/cancellation`] = { body: { cancellation: { enabled: true, policy_status: 'CONFIGURED', policy_version: 'p1', can_cancel: true, reason: null, message: 'If you cancel now, a refund of your payment for this order is due.', financial_effect: 'FULL_REFUND', refundable_amount: money(50000), reasons: ['CHANGED_MIND', 'PICKUP_TIME_NO_LONGER_WORKS'], order: { id: 'o1', order_number: N, status: 'WAITING_FOR_RESTAURANT', version: 3 } } } }
    mount()
    await userEvent.click(await screen.findByTestId('trk-cancel'))
    const dialog = await screen.findByTestId('cancel-dialog')
    await waitFor(() => expect(within(dialog).getByTestId('cancel-message')).toHaveTextContent('a refund of your payment for this order is due'))
    expect(within(dialog).getByTestId('cancel-effect')).toHaveTextContent('A refund of your payment is due'); expect(within(dialog).getByTestId('cancel-refundable')).toHaveTextContent('₹500.00')
    await userEvent.selectOptions(within(dialog).getByTestId('cancel-reason'), 'PICKUP_TIME_NO_LONGER_WORKS')

    // The restaurant started preparing in the meantime: the backend refuses, and the dialog says the order moved on.
    routes[`POST /customer/orders/${N}/cancel`] = { status: 409, body: { error: { code: 'order_cancellation_not_allowed', message: 'This order can no longer be cancelled in the app.', details: { reason: 'ORDER_STATE_NOT_CANCELLABLE' } } } }
    await userEvent.click(within(dialog).getByTestId('cancel-confirm'))
    expect(await within(dialog).findByTestId('cancel-error')).toHaveTextContent('This order has moved on')
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ reason_code: 'PICKUP_TIME_NO_LONGER_WORKS', expected_version: 3 })

    // Allowed: the order is cancelled and the page shows the refund the backend created — owed, not made.
    const cancelled = dto({ status: 'CANCELLED', rejection: null, cancellation: { by: 'YOU', message: 'This order was cancelled. A refund is due — its status is shown with this order.' }, refund: refundBlock('REQUIRED', [refundItem({ reason: { code: 'CUSTOMER_CANCELLATION', label: 'The order was cancelled' } })]), customer_cancellation: { enabled: true, can_cancel: false, reason: 'ORDER_ALREADY_CLOSED' }, timeline: [line(1, 'ORDER_CREATED', 'WAITING_FOR_RESTAURANT'), line(2, 'ORDER_CANCELLED', 'CANCELLED'), line(3, 'REFUND_REQUIRED', null, { refund_status: 'REQUIRED' })] as OrderDto['timeline'] })
    routes[`POST /customer/orders/${N}/cancel`] = { body: { order: cancelled } }
    routes[`GET /customer/orders/${N}`] = { body: { order: cancelled } }
    await userEvent.click(within(dialog).getByTestId('cancel-confirm'))
    await waitFor(() => expect(screen.queryByTestId('cancel-dialog')).toBeNull())
    await waitFor(() => expect(screen.getByTestId('trk-order-status')).toHaveTextContent('Cancelled'))
    expect(screen.getByTestId('trk-payment-status')).toHaveTextContent('Refund pending'); expect(screen.getByTestId('od-refund-status')).toHaveTextContent('Refund due')
    expect(screen.queryByTestId('trk-cancel')).toBeNull()
  })

  it('the cancellation dialog cannot be confirmed when the quote says no', async () => {
    const orders = { cancellationQuote: vi.fn(async () => ({ enabled: false, canCancel: false, reason: 'CUSTOMER_CANCELLATION_DISABLED', message: 'Orders cannot be cancelled in the app yet. Please contact FoodOnTheGo support.', financialEffect: null, refundableMinor: null, currency: '', reasons: [], version: 1 })), cancel: vi.fn() }
    const closed = vi.fn()
    render(<CancelOrderDialog order={toOrder(dto(), 'u1')} orders={orders as never} locale="en-IN" onClose={closed} onCancelled={vi.fn()} />)
    expect(await screen.findByTestId('cancel-message')).toHaveTextContent('Orders cannot be cancelled in the app yet')
    expect(screen.queryByTestId('cancel-confirm')).toBeNull(); expect(screen.queryByTestId('cancel-effect')).toBeNull(); expect(screen.queryByTestId('cancel-reason')).toBeNull()
    await userEvent.click(screen.getByTestId('cancel-keep'))
    expect(closed).toHaveBeenCalled(); expect(orders.cancel).not.toHaveBeenCalled()
  })
})
