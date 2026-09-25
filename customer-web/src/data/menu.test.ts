import { describe, expect, it } from 'vitest'
import { MENU, MENU_ITEMS, inr } from './menu'

describe('menu data', () => {
  it('formats INR with Indian grouping', () => {
    expect(inr(60)).toBe('₹60')
    expect(inr(1150)).toBe('₹1,150')
    expect(inr(125000)).toBe('₹1,25,000')
  })

  it('indexes every menu item by id with a positive price', () => {
    const all = MENU.flatMap((s) => s.items)
    expect(all.length).toBeGreaterThan(0)
    for (const item of all) {
      expect(MENU_ITEMS[item.id]).toBe(item)
      expect(item.price).toBeGreaterThan(0)
    }
    expect(new Set(all.map((i) => i.id)).size).toBe(all.length)
  })
})
