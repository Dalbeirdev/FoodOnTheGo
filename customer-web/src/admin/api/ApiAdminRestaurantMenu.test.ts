/**
 * Admin menu oversight on the backend (Module 24): GET /admin/restaurants/{location}/menu (OpenAPI: AdminMenuOversight)
 * becomes the read-only view the restaurant details page shows.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../../api/client'
import { ApiAdminRestaurantRepository } from './ApiAdminRestaurantRepository'

const BASE = 'http://127.0.0.1:8001/api/v1'
const L1 = '0a000000-0000-4000-8000-0000000000a1'
const OVERSIGHT = {
  menu: { id: 'm1', name: 'Menu', currency: 'INR', catalog_version: 12, updated_at: '2026-10-03T09:00:00+00:00', status: 'ACTIVE', version: 2 },
  summary: { categories: 2, inactive_categories: 1, items: 5, active_items: 2, sold_out_items: 1, unavailable_items: 1, disabled_items: 1, archived_items: 1, customizable_items: 1, last_changed_at: '2026-10-03T09:00:00+00:00' },
  categories: [{ id: 'c1', name: 'Burgers', description: null, display_order: 0, items: [
    { id: 'i1', slug: 'classic-burger', name: 'Classic Burger', description: null, base_price_minor: 24900, currency: 'INR', status: 'ACTIVE', images: [], dietary_tags: [{ code: 'NON_VEGETARIAN', name: 'Non-vegetarian' }], customizable: true, preparation_minutes: 12, featured: false, display_order: 0, version: 1, availability: { visible: true, orderable: true, reason: null, restaurant_reason: null } },
    { id: 'i2', slug: 'smoky-bbq', name: 'Smoky BBQ', description: null, base_price_minor: 29900, currency: 'INR', status: 'SOLD_OUT', images: [], dietary_tags: [], customizable: false, preparation_minutes: null, featured: false, display_order: 1, version: 1, availability: { visible: true, orderable: false, reason: 'ITEM_SOLD_OUT', restaurant_reason: null } },
  ] }],
  dietary_tags: [{ code: 'NON_VEGETARIAN', name: 'Non-vegetarian' }],
  inactive_categories: [{ id: 'c2', name: 'Seasonal' }],
  availability: { visible_to_customers: true, open_now: true, open_state: 'OPEN', accepting_orders: true, orderable: true, reason: null, closes_at: null, opens_next_at: null, checked_at: '2026-10-03T09:00:00+00:00' },
}
let calls: Array<{ method: string; path: string; auth: string | null }> = []
beforeEach(() => {
  calls = []; tokens.set('admin', 'admin-token')
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const path = url.replace(BASE, ''); calls.push({ method: init.method ?? 'GET', path, auth: (init.headers as Record<string, string>).Authorization ?? null })
    if (path === `/admin/restaurants/${L1}/menu`) return new Response(JSON.stringify(OVERSIGHT), { status: 200, headers: { 'Content-Type': 'application/json' } })
    if (path === '/admin/restaurants/none/menu') return new Response(JSON.stringify({ ...OVERSIGHT, menu: null, categories: [], inactive_categories: [], summary: { ...OVERSIGHT.summary, categories: 0, items: 0, active_items: 0 } }), { status: 200 })
    return new Response(JSON.stringify({ error: { code: 'not_found', message: 'No', details: {} } }), { status: 404 })
  }))
})
afterEach(() => vi.unstubAllGlobals())

describe('ApiAdminRestaurantRepository.menu', () => {
  it('reads the oversight document with the admin token and maps it for the details page', async () => {
    const m = await new ApiAdminRestaurantRepository().menu(L1)
    expect(calls[0]).toEqual({ method: 'GET', path: `/admin/restaurants/${L1}/menu`, auth: 'Bearer admin-token' })
    expect(m.menu).toEqual({ id: 'm1', name: 'Menu', status: 'ACTIVE', currency: 'INR', catalogVersion: 12, updatedAt: '2026-10-03T09:00:00+00:00' })
    expect(m.summary).toEqual({ categories: 2, inactiveCategories: 1, items: 5, activeItems: 2, soldOutItems: 1, unavailableItems: 1, disabledItems: 1, archivedItems: 1, customizableItems: 1, lastChangedAt: '2026-10-03T09:00:00+00:00' })
    expect(m.categories[0].items.map((i) => [i.name, i.priceMinor, i.status, i.orderable, i.reason])).toEqual([['Classic Burger', 24900, 'ACTIVE', true, null], ['Smoky BBQ', 29900, 'SOLD_OUT', false, 'ITEM_SOLD_OUT']])
    expect(m.categories[0].items[0].dietaryTags).toEqual(['Non-vegetarian'])
    expect(m.inactiveCategories).toEqual([{ id: 'c2', name: 'Seasonal' }]); expect(m.visibleToCustomers).toBe(true)
  })
  it('a restaurant without a published menu gives an empty view, not an error', async () => {
    const m = await new ApiAdminRestaurantRepository().menu('none')
    expect(m.menu).toBeNull(); expect(m.categories).toEqual([]); expect(m.summary.items).toBe(0)
  })
})
