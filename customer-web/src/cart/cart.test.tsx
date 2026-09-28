import { act, renderHook } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { ReactNode } from 'react'
import { CartProvider, useCart, type AddItemInput } from './CartContext'
import { MemoryCartRepository } from './cartModel'

const restA = { id: 'r-a', slug: 'r-a', name: 'Restaurant A', currency: 'INR' }
const restB = { id: 'r-b', slug: 'r-b', name: 'Restaurant B', currency: 'USD' }
const base = (over: Partial<AddItemInput> = {}): AddItemInput => ({
  menuItemId: 'r-a:burger', itemSlug: 'burger', itemName: 'Burger', image: '', fallback: '', basePriceMinor: 25000, currency: 'INR', restaurant: restA,
  selectedVariants: [{ groupId: 'size', groupName: 'Size', optionId: 'size:regular', optionName: 'Regular', priceAdjustmentMinor: 0 }],
  selectedModifiers: [], specialInstructions: '', quantity: 1, unitPriceMinor: 25000, maximumQuantity: 20, ...over,
})
const wrapper = ({ children }: { children: ReactNode }) => <CartProvider repository={new MemoryCartRepository()}>{children}</CartProvider>

describe('Cart state (Module 08)', () => {
  it('TEST 1 / 9 — adds structured items from one restaurant and keeps one cart', () => {
    const { result } = renderHook(() => useCart(), { wrapper })
    expect(result.current.status).toBe('empty')
    act(() => { result.current.addItem(base()) })
    act(() => { result.current.addItem(base({ menuItemId: 'r-a:fries', itemSlug: 'fries', itemName: 'Fries', selectedVariants: [], unitPriceMinor: 12000, basePriceMinor: 12000, quantity: 2 })) })
    expect(result.current.status).toBe('active')
    expect(result.current.cart?.restaurantId).toBe('r-a')
    expect(result.current.cart?.items).toHaveLength(2)
    expect(result.current.count).toBe(3)
    expect(result.current.subtotalMinor).toBe(25000 + 2 * 12000)
    expect(result.current.cart?.items[0].selectedVariants[0].optionName).toBe('Regular')
  })
  it('TEST 11 — identical configuration merges into one line (documented behaviour)', () => {
    const { result } = renderHook(() => useCart(), { wrapper })
    act(() => { result.current.addItem(base({ specialInstructions: 'No ice' })) })
    let res: ReturnType<typeof result.current.addItem> | undefined
    act(() => { res = result.current.addItem(base({ specialInstructions: 'no ice ' })) })
    expect(res && res.ok && res.merged).toBe(true)
    expect(result.current.cart?.items).toHaveLength(1)
    expect(result.current.cart?.items[0].quantity).toBe(2)
    expect(result.current.cart?.items[0].lineTotalMinor).toBe(50000)
  })
  it('TEST 12 — different configurations stay separate lines', () => {
    const { result } = renderHook(() => useCart(), { wrapper })
    act(() => { result.current.addItem(base()) })
    act(() => { result.current.addItem(base({ selectedModifiers: [{ groupId: 'addons', groupName: 'Add-ons', optionId: 'addons:cheese', optionName: 'Extra Cheese', priceAdjustmentMinor: 3000 }], unitPriceMinor: 28000 })) })
    act(() => { result.current.addItem(base({ specialInstructions: 'pack separately' })) })
    expect(result.current.cart?.items).toHaveLength(3)
    expect(result.current.subtotalMinor).toBe(25000 + 28000 + 25000)
  })
  it('TEST 10 — a different restaurant raises an explicit conflict; confirm replaces, cancel keeps', () => {
    const { result } = renderHook(() => useCart(), { wrapper })
    act(() => { result.current.addItem(base()) })
    let res: ReturnType<typeof result.current.addItem> | undefined
    act(() => { res = result.current.addItem(base({ menuItemId: 'r-b:taco', restaurant: restB, currency: 'USD', basePriceMinor: 900, unitPriceMinor: 900 })) })
    expect(res).toEqual({ ok: false, reason: 'restaurant_conflict' })
    expect(result.current.conflict?.current.restaurantName).toBe('Restaurant A')
    expect(result.current.cart?.restaurantId).toBe('r-a')
    act(() => result.current.cancelReplace())
    expect(result.current.conflict).toBeNull(); expect(result.current.cart?.restaurantId).toBe('r-a')
    act(() => { result.current.addItem(base({ menuItemId: 'r-b:taco', restaurant: restB, currency: 'USD', basePriceMinor: 900, unitPriceMinor: 900 })) })
    act(() => result.current.confirmReplace())
    expect(result.current.cart?.restaurantId).toBe('r-b'); expect(result.current.currency).toBe('USD'); expect(result.current.count).toBe(1)
  })
  it('rejects currency mismatches and invalid quantities', () => {
    const { result } = renderHook(() => useCart(), { wrapper })
    let res: ReturnType<typeof result.current.addItem> | undefined
    act(() => { res = result.current.addItem(base({ currency: 'USD' })) })
    expect(res).toEqual({ ok: false, reason: 'currency_mismatch' })
    act(() => { res = result.current.addItem(base({ quantity: 0 })) })
    expect(res).toEqual({ ok: false, reason: 'invalid_quantity' })
    expect(result.current.status).toBe('empty')
  })
  it('updateQuantity / removeItem / clearCart respect bounds and empty the cart', () => {
    const { result } = renderHook(() => useCart(), { wrapper })
    act(() => { result.current.addItem(base({ maximumQuantity: 3 })) })
    const id = result.current.cart!.items[0].id
    act(() => result.current.updateQuantity(id, 10)); expect(result.current.cart?.items[0].quantity).toBe(3)
    act(() => result.current.updateQuantity(id, 0)); expect(result.current.status).toBe('empty')
    act(() => { result.current.addItem(base()) }); act(() => result.current.removeItem(result.current.cart!.items[0].id)); expect(result.current.cart).toBeNull()
    act(() => { result.current.addItem(base()); result.current.setNote('x') }); act(() => result.current.clearCart()); expect(result.current.count).toBe(0); expect(result.current.note).toBe('')
  })
  it('legacy line API stays compatible for the Module 01 cart / checkout pages (major units)', () => {
    const { result } = renderHook(() => useCart(), { wrapper })
    act(() => { result.current.addItem(base({ selectedModifiers: [{ groupId: 'addons', groupName: 'Add-ons', optionId: 'addons:cheese', optionName: 'Extra Cheese', priceAdjustmentMinor: 3000 }], unitPriceMinor: 28000, quantity: 2 })) })
    expect(result.current.lines).toHaveLength(1)
    expect(result.current.lines[0]).toMatchObject({ itemId: 'r-a:burger', name: 'Burger', qty: 2, unitPrice: 280, detail: 'Regular, Extra Cheese' })
    expect(result.current.total).toBe(560)
    act(() => result.current.remove(result.current.lines[0].key)); expect(result.current.count).toBe(1)
    act(() => result.current.removeLine(result.current.lines[0].key)); expect(result.current.count).toBe(0)
  })
  it('persists through the repository and hydrates a new provider', async () => {
    const repo = new MemoryCartRepository()
    const w = ({ children }: { children: ReactNode }) => <CartProvider repository={repo}>{children}</CartProvider>
    const first = renderHook(() => useCart(), { wrapper: w })
    await act(async () => { first.result.current.addItem(base()) })
    expect((await repo.load())?.items).toHaveLength(1)
    const second = renderHook(() => useCart(), { wrapper: w })
    await act(async () => { await Promise.resolve() })
    expect(second.result.current.count).toBe(1)
  })
})
