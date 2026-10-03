/**
 * Customer menu adapter for the backend (Module 24). The network is stubbed with payloads in the shape of the real API
 * (OpenAPI: PublicMenu, PublicMenuItemDetail, PriceQuote); the tests check what is requested and what reaches the pages.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/client'
import { MenuError } from '../repositories'
import { ApiMenuRepository, itemAvailability, type PublicMenuDto, type PublicMenuItemDto } from './apiMenu'

const BASE = 'http://127.0.0.1:8001/api/v1'
const I1 = '0c000000-0000-4000-8000-0000000000c1'
const item = (patch: Partial<PublicMenuItemDto> = {}): PublicMenuItemDto => ({
  id: I1, slug: 'classic-burger', name: 'Classic Burger', description: 'Juicy grilled patty.', base_price_minor: 24900, currency: 'INR', status: 'ACTIVE',
  images: [{ id: 'img1', url: 'http://10.0.0.5:8001/api/v1/media/menu/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa/bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb.jpg', alt_text: 'A burger', width: 800, height: 600, display_order: 0 }],
  dietary_tags: [{ code: 'NON_VEGETARIAN', name: 'Non-vegetarian' }], customizable: true, preparation_minutes: 12, featured: true, display_order: 0, version: 3,
  availability: { visible: true, orderable: true, reason: null, restaurant_reason: null }, ...patch,
})
const MENU: PublicMenuDto = {
  restaurant: { id: 'r1', slug: 'burger-hub', name: 'Burger Hub', currency: 'INR', timezone: 'Asia/Kolkata' }, availability: { orderable: true },
  menu: { id: 'm1', name: 'Menu', currency: 'INR', catalog_version: 7, updated_at: null },
  categories: [
    { id: 'c1', name: 'Burgers', description: null, display_order: 0, items: [item(), item({ id: 'i2', slug: 'smoky-bbq', name: 'Smoky BBQ', status: 'SOLD_OUT', dietary_tags: [], availability: { visible: true, orderable: false, reason: 'ITEM_SOLD_OUT', restaurant_reason: null } })] },
    { id: 'c2', name: 'Sides', description: 'Small plates', display_order: 1, items: [item({ id: 'i3', slug: 'fries', name: 'Fries', customizable: false, preparation_minutes: null, dietary_tags: [{ code: 'VEGETARIAN', name: 'Vegetarian' }, { code: 'VEGAN', name: 'Vegan' }] })] },
  ],
  dietary_tags: [{ code: 'NON_VEGETARIAN', name: 'Non-vegetarian' }, { code: 'VEGETARIAN', name: 'Vegetarian' }, { code: 'VEGAN', name: 'Vegan' }],
}
const DETAIL = {
  data: {
    ...item(), restaurant: { id: 'r1', slug: 'burger-hub', name: 'Burger Hub' }, category: { id: 'c1', name: 'Burgers' }, menu: { id: 'm1', currency: 'INR', catalog_version: 7 },
    min_quantity: 1, max_quantity: 20, instructions_max_length: 200, allergen_information: 'Contains gluten.', ingredients: null,
    variant_groups: [{ id: 'g1', kind: 'VARIANT', name: 'Size', description: null, required: true, min_selections: 1, max_selections: 1, display_order: 0, options: [{ id: 'o1', name: 'Regular', price_adjustment_minor: 0, available: true, default_selected: true, display_order: 0 }, { id: 'o2', name: 'Large', price_adjustment_minor: 5000, available: true, default_selected: false, display_order: 1 }] }],
    modifier_groups: [{ id: 'g2', kind: 'MODIFIER', name: 'Add-ons', description: 'Choose up to 3', required: false, min_selections: 0, max_selections: 3, display_order: 1, options: [{ id: 'o3', name: 'Extra Cheese', price_adjustment_minor: 2000, available: true, default_selected: false, display_order: 0 }, { id: 'o4', name: 'Fried Egg', price_adjustment_minor: 3000, available: false, default_selected: false, display_order: 1 }] }],
  },
  restaurant_availability: { orderable: true },
}
const QUOTE = { data: { base_price_minor: 20000, adjustments_minor: 7000, unit_price_minor: 27000, quantity: 2, line_total_minor: 54000, currency: 'INR', selections: [], item_id: I1, item_version: 3, catalog_version: 7 }, availability: { visible: true, orderable: true, reason: null, restaurant_reason: null } }

type Call = { method: string; path: string; body: Record<string, unknown> | null; auth: string | null }
type Reply = { status?: number; body?: unknown }
let calls: Call[] = []
let routes: Record<string, Reply> = {}
const refuse = (status: number, code: string, details: Record<string, unknown> = {}): Reply => ({ status, body: { error: { code, message: `Refused: ${code}`, details, request_id: 'req-1' } } })

beforeEach(() => {
  calls = []
  routes = { 'GET /restaurants/burger-hub/menu': { body: MENU }, 'GET /restaurants/burger-hub/items/classic-burger': { body: DETAIL }, 'POST /restaurants/burger-hub/items/classic-burger/price-quote': { body: QUOTE } }
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const path = decodeURIComponent(url.replace(BASE, '')); const method = init.method ?? 'GET'
    calls.push({ method, path, body: init.body ? JSON.parse(init.body as string) : null, auth: (init.headers as Record<string, string>).Authorization ?? null })
    const r = routes[`${method} ${path.split('?')[0]}`]
    if (!r) return new Response(JSON.stringify({ error: { code: 'not_found', message: `No stub for ${method} ${path}`, details: {} } }), { status: 404 })
    return new Response(r.body === undefined ? null : JSON.stringify(r.body), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
})
afterEach(() => vi.unstubAllGlobals())

const repo = () => new ApiMenuRepository((id) => (id === 'legacy-burger-hub' ? 'burger-hub' : id))

describe('ApiMenuRepository — the published menu', () => {
  it('reads categories, items and labels from one public request, mapped for the pages', async () => {
    const r = repo()
    const [categories, page, tags] = await Promise.all([r.getCategories('burger-hub'), r.getItems('burger-hub', { limit: 500 }), r.getDietaryTags('burger-hub')])
    expect(calls).toHaveLength(1); expect(calls[0]).toMatchObject({ method: 'GET', path: '/restaurants/burger-hub/menu', auth: null })
    expect(categories.map((c) => [c.name, c.displayOrder, c.restaurantId])).toEqual([['Burgers', 0, 'burger-hub'], ['Sides', 1, 'burger-hub']])
    expect(categories[1].description).toBe('Small plates')
    expect(page.total).toBe(3); expect(page.items.map((i) => i.slug)).toEqual(['classic-burger', 'smoky-bbq', 'fries'])
    const classic = page.items[0]
    expect(classic).toMatchObject({ id: I1, publicId: I1, categoryId: 'c1', basePriceMinor: 24900, currency: 'INR', availability: 'available', customizable: true, prepTimeMin: 12, featured: true, status: 'active', dietaryTags: ['Non-vegetarian'] })
    expect(classic.image).toContain('/api/v1/media/menu/'); expect(classic.images).toHaveLength(1)
    expect(page.items[1].availability).toBe('sold_out')
    expect(page.items[2]).toMatchObject({ prepTimeMin: 0, customizable: false, dietaryTags: ['Vegetarian', 'Vegan'] })
    expect(tags).toEqual(['Non-vegetarian', 'Vegetarian', 'Vegan'])
  })

  it('addresses the backend by slug when the page still uses a development id', async () => {
    await repo().getCategories('legacy-burger-hub')
    expect(calls[0].path).toBe('/restaurants/burger-hub/menu')
  })

  it('searches, filters and pages like the development repository', async () => {
    const r = repo()
    expect((await r.getItems('burger-hub', { search: 'FRIES' })).items.map((i) => i.name)).toEqual(['Fries'])
    expect((await r.getItems('burger-hub', { search: 'small plates' })).items.map((i) => i.name)).toEqual(['Fries'])
    expect((await r.getItems('burger-hub', { dietary: ['Vegetarian', 'Vegan'] })).items.map((i) => i.name)).toEqual(['Fries'])
    expect((await r.getItems('burger-hub', { availableOnly: true })).items.map((i) => i.slug)).toEqual(['classic-burger', 'fries'])
    expect((await r.getItems('burger-hub', { categoryId: 'c1' })).total).toBe(2)
    const first = await r.getItems('burger-hub', { limit: 2 })
    expect(first.items).toHaveLength(2); expect(first.nextCursor).toBe('c2')
    expect((await r.getItems('burger-hub', { limit: 2, cursor: 'c2' })).items.map((i) => i.slug)).toEqual(['fries'])
    expect(await r.getItemBySlug('burger-hub', 'smoky-bbq')).toMatchObject({ name: 'Smoky BBQ' })
    expect(await r.getItemBySlug('burger-hub', 'no-such')).toBeNull()
    expect(calls.filter((c) => c.path === '/restaurants/burger-hub/menu')).toHaveLength(1)
  })

  it('maps the backend availability answer: sold out, temporarily unavailable, an impossible required group', () => {
    const ok = { visible: true, orderable: true, reason: null, restaurant_reason: null }
    expect(itemAvailability({ status: 'ACTIVE', availability: ok })).toBe('available')
    expect(itemAvailability({ status: 'SOLD_OUT', availability: { ...ok, orderable: false, reason: 'ITEM_SOLD_OUT' } })).toBe('sold_out')
    expect(itemAvailability({ status: 'TEMPORARILY_UNAVAILABLE', availability: { ...ok, orderable: false, reason: 'ITEM_TEMPORARILY_UNAVAILABLE' } })).toBe('temporarily_unavailable')
    expect(itemAvailability({ status: 'ACTIVE', availability: { ...ok, orderable: false, reason: 'REQUIRED_GROUP_UNAVAILABLE' } })).toBe('unavailable')
    // the restaurant being closed or paused is not an item property: the pages read it from the restaurant
    expect(itemAvailability({ status: 'ACTIVE', availability: { ...ok, orderable: false, reason: 'RESTAURANT_UNAVAILABLE', restaurant_reason: 'CLOSED_NOW' } })).toBe('available')
  })

  it('the menu document is reused for a while and forgotten on invalidate; a failure is a MenuError', async () => {
    const r = repo()
    await r.getCategories('burger-hub'); await r.getDietaryTags('burger-hub')
    expect(calls).toHaveLength(1)
    r.invalidate('burger-hub'); await r.getCategories('burger-hub')
    expect(calls).toHaveLength(2)
    routes = {}
    await expect(repo().getCategories('burger-hub')).rejects.toBeInstanceOf(MenuError)
    vi.stubGlobal('fetch', vi.fn(async () => { throw new TypeError('offline') }))
    await expect(repo().getCategories('burger-hub')).rejects.toMatchObject({ name: 'MenuError', code: 'network' })
  })
})

describe('ApiMenuRepository — the item page and the price quote', () => {
  it('maps the item with its groups; a temporarily unavailable option is shown as not available', async () => {
    const d = await repo().getItemDetail('burger-hub', 'classic-burger')
    expect(calls[0]).toMatchObject({ method: 'GET', path: '/restaurants/burger-hub/items/classic-burger', auth: null })
    expect(d).toMatchObject({ id: I1, slug: 'classic-burger', restaurantId: 'burger-hub', categoryId: 'c1', restaurantSlug: 'burger-hub', allergenInformation: 'Contains gluten.', minimumQuantity: 1, maximumQuantity: 20, instructionsMaxLength: 200 })
    expect(d!.variantGroups).toHaveLength(1); expect(d!.variantGroups[0]).toMatchObject({ id: 'g1', kind: 'variant', name: 'Size', required: true, minSelections: 1, maxSelections: 1 })
    expect(d!.variantGroups[0].options.map((o) => [o.name, o.priceAdjustmentMinor, o.available, o.defaultSelected])).toEqual([['Regular', 0, true, true], ['Large', 5000, true, false]])
    expect(d!.modifierGroups[0]).toMatchObject({ kind: 'modifier', name: 'Add-ons', description: 'Choose up to 3', maxSelections: 3 })
    expect(d!.modifierGroups[0].options.find((o) => o.name === 'Fried Egg')!.available).toBe(false)
  })

  it('an item the backend does not show is null; an item id is resolved to its slug first', async () => {
    const r = repo()
    expect(await r.getItemDetail('burger-hub', 'old-wrap')).toBeNull()
    expect(await r.getItemDetail('burger-hub', I1)).toMatchObject({ slug: 'classic-burger' })
    expect(calls.map((c) => c.path)).toEqual(['/restaurants/burger-hub/items/old-wrap', '/restaurants/burger-hub/menu', '/restaurants/burger-hub/items/classic-burger'])
    expect(await r.getItemDetail('burger-hub', '0c000000-0000-4000-8000-0000000000ff')).toBeNull()
  })

  it('asks the backend for the price of a configuration and passes its refusal on unchanged', async () => {
    const r = repo()
    const q = await r.getPriceQuote('burger-hub', 'classic-burger', { g1: ['o2'], g2: ['o3'], g3: [] }, 2)
    expect(calls[0]).toMatchObject({ method: 'POST', path: '/restaurants/burger-hub/items/classic-burger/price-quote', body: { selections: [{ group_id: 'g1', option_ids: ['o2'] }, { group_id: 'g2', option_ids: ['o3'] }], quantity: 2 } })
    expect(q).toMatchObject({ basePriceMinor: 20000, adjustmentsMinor: 7000, unitPriceMinor: 27000, quantity: 2, lineTotalMinor: 54000, currency: 'INR', orderable: true, reason: null, catalogVersion: 7 })
    routes['POST /restaurants/burger-hub/items/classic-burger/price-quote'] = refuse(422, 'invalid_selection', { issues: [{ group_id: 'g1', code: 'required', min: 1 }] })
    const e = await r.getPriceQuote('burger-hub', 'classic-burger', {}, 1).catch((x: unknown) => x)
    expect(e).toBeInstanceOf(ApiError); expect((e as ApiError).code).toBe('invalid_selection'); expect((e as ApiError).details.issues).toEqual([{ group_id: 'g1', code: 'required', min: 1 }])
  })
})
