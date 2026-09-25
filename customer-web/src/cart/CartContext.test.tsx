import { act, renderHook } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { CartProvider, useCart } from './CartContext'

const burger = { key: 'burger-hub:classic-burger', itemId: 'classic-burger', restaurantId: 'burger-hub', name: 'Classic Burger', unitPrice: 250 }
const fries = { key: 'burger-hub:french-fries', itemId: 'french-fries', restaurantId: 'burger-hub', name: 'French Fries', unitPrice: 120 }

describe('CartContext', () => {
  it('adds, steps and totals lines', () => {
    const { result } = renderHook(() => useCart(), { wrapper: CartProvider })
    act(() => result.current.add(burger))
    act(() => result.current.add(burger))
    act(() => result.current.add(fries, 3))
    expect(result.current.count).toBe(5)
    expect(result.current.total).toBe(2 * 250 + 3 * 120)
    expect(result.current.qtyOf('classic-burger')).toBe(2)

    act(() => result.current.remove(burger.key))
    expect(result.current.qtyOf('classic-burger')).toBe(1)
    act(() => result.current.remove(burger.key))
    expect(result.current.lines.find((l) => l.key === burger.key)).toBeUndefined()

    act(() => result.current.removeLine(fries.key))
    expect(result.current.count).toBe(0)
  })

  it('clear() empties lines and the special-instructions note', () => {
    const { result } = renderHook(() => useCart(), { wrapper: CartProvider })
    act(() => { result.current.add(burger); result.current.setNote('No onions') })
    expect(result.current.note).toBe('No onions')
    act(() => result.current.clear())
    expect(result.current.lines).toEqual([])
    expect(result.current.note).toBe('')
  })

  it('throws when used outside its provider', () => {
    expect(() => renderHook(() => useCart())).toThrow(/inside CartProvider/)
  })
})
