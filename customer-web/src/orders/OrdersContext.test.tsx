import { act, renderHook } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { OrdersProvider, useOrders } from './OrdersContext'

describe('OrdersContext', () => {
  it('seeds the sample order history used by the approved designs', () => {
    const { result } = renderHook(() => useOrders(), { wrapper: OrdersProvider })
    expect(result.current.orders.map((o) => o.number)).toEqual(['FTG128701', 'FTG128654', 'FTG128600'])
    const pizza = result.current.getOrder('FTG128701')!
    expect(pizza.subtotal).toBe(400)
    expect(pizza.tax).toBe(0) // no tax rate in the frontend
    expect(pizza.total).toBe(400)
    expect(result.current.getOrder('FTG128600')!.status).toBe('cancelled')
  })

  it('places an order with a discount and a FTG number, newest first — tax only when supplied, never from a rate', () => {
    const { result } = renderHook(() => useOrders(), { wrapper: OrdersProvider })
    const lines = [
      { key: 'burger-hub:classic-combo', itemId: 'classic-combo', restaurantId: 'burger-hub', name: 'Classic Combo', unitPrice: 350, qty: 1 },
      { key: 'burger-hub:cola', itemId: 'cola', restaurantId: 'burger-hub', name: 'Cola', unitPrice: 60, qty: 2 },
    ]
    let placed!: ReturnType<typeof result.current.placeOrder>
    act(() => { placed = result.current.placeOrder({ restaurantId: 'burger-hub', lines, note: '', discount: 50, payment: { method: 'UPI', status: 'successful' } }) })
    expect(placed.number).toMatch(/^FTG\d{6}$/)
    expect(placed.subtotal).toBe(470)
    expect(placed.tax).toBe(0)
    expect(placed.total).toBe(470 - 50 + placed.tax)
    expect(placed.status).toBe('preparing')
    expect(placed.readyFrom.getTime() - placed.placedAt.getTime()).toBe(15 * 60_000)
    expect(result.current.orders[0].number).toBe(placed.number)
    expect(result.current.getOrder(placed.number)).toBe(result.current.orders[0])
  })
})
