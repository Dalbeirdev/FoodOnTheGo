/**
 * Menu management adapter for the backend (Module 24). The network is stubbed with payloads in the shape of the real
 * API (OpenAPI: ManagedMenu, ManagedMenuItem, ManagedMenuCategory, MenuImage); the tests check what is SENT (paths,
 * versions, bodies, uploads) and how the answers reach the Menu page.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, tokens } from '../../api/client'
import type { MenuItemInput } from '../types'
import { ApiRestaurantManagementRepository } from './apiDashboard'
import { ApiMenuManagementRepository, dataUrlToBlob, type ManagedCategoryDto, type ManagedItemDto, type ManagedMenuDto } from './apiMenuManagement'

const BASE = 'http://127.0.0.1:8001/api/v1'
const ORG = '0a000000-0000-4000-8000-00000000000a'; const L1 = '0a000000-0000-4000-8000-0000000000a1'
const C1 = '0c000000-0000-4000-8000-0000000000c1'; const C2 = '0c000000-0000-4000-8000-0000000000c2'; const C3 = '0c000000-0000-4000-8000-0000000000c3'
const I1 = '0d000000-0000-4000-8000-0000000000d1'; const I2 = '0d000000-0000-4000-8000-0000000000d2'; const I3 = '0d000000-0000-4000-8000-0000000000d3'
const G1 = '0e000000-0000-4000-8000-0000000000e1'; const O1 = '0f000000-0000-4000-8000-0000000000f1'; const O2 = '0f000000-0000-4000-8000-0000000000f2'; const O3 = '0f000000-0000-4000-8000-0000000000f3'
const IMG = '01000000-0000-4000-8000-000000000011'
const availability = { visible_to_customers: true, open_now: true, open_state: 'OPEN', accepting_orders: true, orderable: true, reason: null, closes_at: null, opens_next_at: null, checked_at: '2026-10-05T06:00:00+00:00' }
const LOCATION = {
  id: L1, slug: 'test-kitchen', name: 'Test Kitchen', branch_label: null, short_description: null, description: null, pickup_instructions: null, cuisines: [], features: [], price_level: 2, phone: null, email: null, website: null,
  address: { formatted: 'Sector 62, Noida', line1: null, postal_code: null, city: 'Noida', city_slug: 'noida', region: 'Uttar Pradesh', region_code: 'IN-UP', country_code: 'IN' },
  location: { latitude: 28.6, longitude: 77.3 }, timezone: 'Asia/Kolkata', currency: 'INR', images: [], status: 'APPROVED', status_note: null, rejection_category: null,
  operational_status: 'OPERATING', accepting_orders: true, pause_reason: null, paused_at: null, paused_until: null, hours: { version: 1, timezone: 'Asia/Kolkata', weekly: [], special: [] }, version: 1, updated_at: null,
  organization: { id: ORG, name: 'Test Group', status: 'APPROVED', status_note: null }, market: 'IN', pickup: { methods: [], asap: true, scheduled: false, default_prep_minutes: 12, minimum_lead_minutes: null, instructions: null },
  availability, permissions: ['restaurant.menu.view', 'restaurant.menu.manage'],
}
const CONTEXT = { organizations: [{ id: ORG, name: 'Test Group', legal_name: 'Test Group Pvt. Ltd.', status: 'APPROVED', status_note: null, membership: { id: 'm-self', role: { code: 'OWNER', name: 'Owner' }, all_locations: true }, permissions: [] }], locations: [LOCATION], default_location_id: L1 }
const TAXONOMY = { cuisines: [], features: [], limits: { cuisines: 5, features: 12, description: 1000, periods_per_day: 3, pause_max_hours: 24 }, dietary_tags: [{ code: 'VEGETARIAN', name: 'Vegetarian', kind: 'DIET' }, { code: 'NON_VEGETARIAN', name: 'Non-vegetarian', kind: 'DIET' }, { code: 'JAIN', name: 'Jain', kind: 'DIET' }], menu: { dietary_tags_per_item: 6 } }

const option = (id: string, name: string, price: number, status = 'ACTIVE', order = 0) => ({ id, name, price_adjustment_minor: price, status, default_selected: order === 0, display_order: order })
const group = (patch: Record<string, unknown> = {}) => ({ id: G1, kind: 'VARIANT', name: 'Size', description: null, required: true, min_selections: 1, max_selections: 1, status: 'ACTIVE', display_order: 0, options: [option(O1, 'Regular', 0), option(O2, 'Large', 5000, 'TEMPORARILY_UNAVAILABLE', 1), option(O3, 'Old', 1000, 'DISABLED', 2)], ...patch })
const item = (patch: Partial<ManagedItemDto> = {}): ManagedItemDto => ({
  id: I1, slug: 'classic-burger', category_id: C1, name: 'Classic Burger', description: 'Juicy.', base_price_minor: 24900, currency: 'INR', status: 'ACTIVE', preparation_minutes: 12, featured: false, min_quantity: 1, max_quantity: 20,
  dietary_tags: [{ code: 'NON_VEGETARIAN', name: 'Non-vegetarian' }], allergen_information: null, ingredients: null,
  images: [{ id: IMG, url: `http://10.0.0.5:8001/api/v1/media/menu/${L1}/${IMG}.jpg`, alt_text: 'Classic Burger', width: 800, height: 600, display_order: 0 }],
  variant_groups: [group() as ManagedItemDto['variant_groups'][number]], modifier_groups: [], availability: { visible: true, orderable: true, reason: null, restaurant_reason: null },
  display_order: 0, version: 3, archived_at: null, updated_at: null, ...patch,
})
const category = (patch: Partial<ManagedCategoryDto> = {}): ManagedCategoryDto => ({ id: C1, name: 'Burgers', description: null, status: 'ACTIVE', display_order: 0, item_count: 2, version: 2, updated_at: null, ...patch })
const MENU: ManagedMenuDto = {
  id: '0b000000-0000-4000-8000-0000000000b1', name: 'Menu', description: null, status: 'ACTIVE', currency: 'INR', catalog_version: 9, version: 1, updated_at: null,
  location: { id: L1, slug: 'test-kitchen', name: 'Test Kitchen', timezone: 'Asia/Kolkata', currency: 'INR' },
  categories: [category(), category({ id: C2, name: 'Sides', display_order: 1, item_count: 0, version: 1 }), category({ id: C3, name: 'Old', status: 'ARCHIVED', display_order: 2, item_count: 0 })],
  items: [item(), item({ id: I2, slug: 'draft-burger', name: 'Draft Burger', status: 'DISABLED', images: [], variant_groups: [], version: 1 }), item({ id: I3, slug: 'old-wrap', name: 'Old Wrap', status: 'ARCHIVED', archived_at: '2026-10-01T00:00:00+00:00', images: [], variant_groups: [], version: 4 })],
  limits: {},
}

type Call = { method: string; path: string; query: string; body: Record<string, unknown> | null; form: Record<string, string> | null; auth: string | null }
type Reply = { status?: number; body?: unknown }
let calls: Call[] = []
let routes: Record<string, Reply | ((call: Call) => Reply)> = {}
const sent = (method: string, fragment: string) => calls.filter((c) => c.method === method && c.path.includes(fragment))
const refuse = (status: number, code: string, fields?: Record<string, string[]>): Reply => ({ status, body: { error: { code, message: `Refused: ${code}`, details: fields ? { fields } : {}, request_id: 'req-1' } } })

beforeEach(() => {
  calls = []; tokens.set('restaurant', 'staff-token')
  routes = {
    'GET /restaurant/context': { body: CONTEXT }, 'GET /restaurant/taxonomy': { body: TAXONOMY },
    [`GET /restaurant/locations/${L1}/menu`]: { body: MENU },
  }
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const full = decodeURIComponent(url.replace(BASE, '')); const [path, query = ''] = full.split('?'); const method = init.method ?? 'GET'
    const form = init.body instanceof FormData ? Object.fromEntries([...init.body.entries()].map(([k, v]) => [k, v instanceof Blob ? `${v.type}:${v.size}:${(v as File).name ?? ''}` : String(v)])) : null
    const call: Call = { method, path, query, body: init.body && !form ? JSON.parse(init.body as string) : null, form, auth: (init.headers as Record<string, string>).Authorization ?? null }
    calls.push(call)
    const route = routes[`${method} ${path}`]; const r = typeof route === 'function' ? route(call) : route
    if (!r) return new Response(JSON.stringify({ error: { code: 'not_found', message: `No stub for ${method} ${path}`, details: {} } }), { status: 404 })
    return new Response(r.body === undefined ? null : JSON.stringify(r.body), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
})
afterEach(() => vi.unstubAllGlobals())

async function repo(): Promise<ApiMenuManagementRepository> {
  const management = new ApiRestaurantManagementRepository()
  await management.getLocations()
  return new ApiMenuManagementRepository(management)
}
const input = (patch: Partial<MenuItemInput> = {}): MenuItemInput => ({
  id: I1, name: 'Classic Burger', description: 'Juicy.', categoryId: C1, basePriceMinor: 26900, availability: 'sold_out', dietaryTags: ['Non-vegetarian', 'Jain'], customizable: true, prepTimeMin: 15, displayOrder: 0, featured: true, status: 'active', images: [item().images[0].url], ...patch,
})

describe('ApiMenuManagementRepository — reading the menu', () => {
  it('loads the location menu with archived rows and maps categories, items, states and groups for the page', async () => {
    const r = await repo(); const menu = await r.getMenu('test-kitchen')
    expect(sent('GET', '/menu')[0]).toMatchObject({ path: `/restaurant/locations/${L1}/menu`, query: 'include=archived', auth: 'Bearer staff-token' })
    expect(menu.categories.map((c) => [c.name, c.displayOrder])).toEqual([['Burgers', 0], ['Sides', 1]]) // the archived category is not offered
    expect(menu.items.map((i) => [i.slug, i.availability, i.status])).toEqual([['classic-burger', 'available', 'active'], ['draft-burger', 'unavailable', 'active'], ['old-wrap', 'unavailable', 'inactive']])
    expect(menu.items[0]).toMatchObject({ id: I1, restaurantId: 'test-kitchen', categoryId: C1, basePriceMinor: 24900, currency: 'INR', prepTimeMin: 12, dietaryTags: ['Non-vegetarian'], customizable: true })
    expect(menu.items[0].image).toContain(`/media/menu/${L1}/`)
    const size = menu.groups[I1].variantGroups[0]
    expect(size).toMatchObject({ id: G1, kind: 'variant', name: 'Size', required: true, minSelections: 1, maxSelections: 1 })
    expect(size.options.map((o) => [o.name, o.priceAdjustmentMinor, o.available])).toEqual([['Regular', 0, true], ['Large', 5000, false], ['Old', 1000, false]])
    expect(menu.groups[I2]).toEqual({ variantGroups: [], modifierGroups: [] })
  })

  it('offers the dietary labels of the backend', async () => {
    const r = await repo()
    expect(await r.dietaryTags()).toEqual([{ code: 'VEGETARIAN', name: 'Vegetarian' }, { code: 'NON_VEGETARIAN', name: 'Non-vegetarian' }, { code: 'JAIN', name: 'Jain' }])
    await r.dietaryTags(); expect(sent('GET', '/restaurant/taxonomy')).toHaveLength(1)
  })
})

describe('ApiMenuManagementRepository — writing', () => {
  it('saves an edited item with its version, the backend status, tag codes and the whole option document', async () => {
    const r = await repo(); await r.getMenu('test-kitchen')
    routes[`PATCH /restaurant/menu/items/${I1}`] = { body: item({ base_price_minor: 26900, status: 'SOLD_OUT', version: 4, featured: true }) }
    const groups = {
      variantGroups: [{ id: G1, kind: 'variant' as const, name: 'Size', required: true, minSelections: 1, maxSelections: 1, displayOrder: 0, options: [
        { id: O1, name: 'Regular', priceAdjustmentMinor: 0, available: true, defaultSelected: true, displayOrder: 0 }, { id: O2, name: 'Large', priceAdjustmentMinor: 7000, available: true, defaultSelected: false, displayOrder: 1 }, { id: O3, name: 'Old', priceAdjustmentMinor: 1000, available: false, defaultSelected: false, displayOrder: 2 },
      ] }],
      modifierGroups: [{ id: 'grp_new1', kind: 'modifier' as const, name: 'Add-ons', required: false, minSelections: 0, maxSelections: 2, displayOrder: 0, options: [{ id: 'opt_new1', name: 'Extra Cheese', priceAdjustmentMinor: 2000, available: true, defaultSelected: false, displayOrder: 0 }, { id: 'opt_new2', name: 'Egg', priceAdjustmentMinor: 3000, available: false, defaultSelected: false, displayOrder: 1 }] }],
    }
    const saved = await r.saveItem('test-kitchen', input(), groups)
    const call = sent('PATCH', `/restaurant/menu/items/${I1}`)[0]
    expect(call.body).toMatchObject({ version: 3, name: 'Classic Burger', category_id: C1, base_price_minor: 26900, status: 'SOLD_OUT', preparation_minutes: 15, featured: true, dietary_tags: ['NON_VEGETARIAN', 'JAIN'] })
    expect(call.body).not.toHaveProperty('currency'); expect(call.body).not.toHaveProperty('slug')
    const sentGroups = call.body!.groups as Array<Record<string, unknown>>
    expect(sentGroups).toHaveLength(2)
    expect(sentGroups[0]).toMatchObject({ id: G1, kind: 'VARIANT', name: 'Size', required: true, min_selections: 1, max_selections: 1 })
    expect((sentGroups[0].options as Array<Record<string, unknown>>).map((o) => [o.id ?? null, o.name, o.price_adjustment_minor, o.status])).toEqual([[O1, 'Regular', 0, 'ACTIVE'], [O2, 'Large', 7000, 'ACTIVE'], [O3, 'Old', 1000, 'DISABLED']])
    expect(sentGroups[1]).toMatchObject({ kind: 'MODIFIER', name: 'Add-ons', required: false, min_selections: 0, max_selections: 2 }); expect(sentGroups[1]).not.toHaveProperty('id')
    expect((sentGroups[1].options as Array<Record<string, unknown>>).map((o) => [o.id ?? null, o.status])).toEqual([[null, 'ACTIVE'], [null, 'TEMPORARILY_UNAVAILABLE']])
    expect(saved).toMatchObject({ id: I1, availability: 'sold_out', basePriceMinor: 26900, featured: true })
    expect(sent('POST', '/images')).toHaveLength(0); expect(sent('DELETE', '/images')).toHaveLength(0) // the existing photo was kept
    // the version the backend returned is what the next edit sends
    routes[`PATCH /restaurant/menu/items/${I1}`] = { body: item({ version: 5 }) }
    await r.saveItem('test-kitchen', input())
    expect(sent('PATCH', `/restaurant/menu/items/${I1}`)[1].body).toMatchObject({ version: 4 })
    expect(sent('PATCH', `/restaurant/menu/items/${I1}`)[1].body).not.toHaveProperty('groups')
  })

  it('creates an item in the location menu, then uploads the new photo as multipart and reloads the item', async () => {
    const r = await repo(); await r.getMenu('test-kitchen')
    const created = item({ id: I2, slug: 'paneer-burger', name: 'Paneer Burger', images: [], version: 1 })
    routes[`POST /restaurant/locations/${L1}/menu/items`] = { body: created }
    routes[`POST /restaurant/menu/items/${I2}/images`] = { status: 201, body: { id: IMG, url: 'http://x/api/v1/media/menu/a/b.png', alt_text: 'Paneer Burger', width: 400, height: 400, display_order: 0 } }
    routes[`GET /restaurant/menu/items/${I2}`] = { body: { ...created, images: [{ id: IMG, url: 'http://x/api/v1/media/menu/a/b.png', alt_text: 'Paneer Burger', width: 400, height: 400, display_order: 0 }] } }
    const png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
    const saved = await r.saveItem('test-kitchen', input({ id: undefined, name: 'Paneer Burger', availability: 'available', dietaryTags: ['Vegetarian'], images: [png] }), { variantGroups: [], modifierGroups: [] })
    expect(sent('POST', '/menu/items')[0]).toMatchObject({ path: `/restaurant/locations/${L1}/menu/items`, body: { name: 'Paneer Burger', status: 'ACTIVE', dietary_tags: ['VEGETARIAN'], groups: [] } })
    expect(sent('POST', '/menu/items')[0].body).not.toHaveProperty('version')
    const upload = sent('POST', `/restaurant/menu/items/${I2}/images`)[0]
    expect(upload.form).toEqual({ image: 'image/png:70:photo.png', alt_text: 'Paneer Burger' })
    expect(saved.images).toEqual(['http://x/api/v1/media/menu/a/b.png'])
  })

  it('removes a photo the page took away and translates the backend refusals', async () => {
    const r = await repo(); await r.getMenu('test-kitchen')
    routes[`PATCH /restaurant/menu/items/${I1}`] = { body: item() }
    routes[`DELETE /restaurant/menu/items/${I1}/images/${IMG}`] = { status: 204 }
    routes[`GET /restaurant/menu/items/${I1}`] = { body: item({ images: [] }) }
    const saved = await r.saveItem('test-kitchen', input({ images: [] }))
    expect(sent('DELETE', `/images/${IMG}`)).toHaveLength(1); expect(saved.images).toEqual([])

    routes[`PATCH /restaurant/menu/items/${I1}`] = refuse(409, 'stale_update')
    const stale = await r.saveItem('test-kitchen', input()).catch((e: unknown) => e)
    expect(stale).toBeInstanceOf(ApiError); expect((stale as ApiError).code).toBe('stale_update')
    routes[`PATCH /restaurant/menu/items/${I1}`] = refuse(422, 'validation_failed', { 'groups.0.min_selections': ['The minimum cannot exceed the maximum.'] })
    const invalid = await r.saveItem('test-kitchen', input()).catch((e: unknown) => e)
    expect((invalid as ApiError).field('groups.0.min_selections')).toBe('The minimum cannot exceed the maximum.')
  })

  it('status switches, duplicates and archives go to the item routes', async () => {
    const r = await repo(); await r.getMenu('test-kitchen')
    routes[`PATCH /restaurant/menu/items/${I1}/status`] = { body: item({ status: 'SOLD_OUT' }) }
    expect((await r.setAvailability('test-kitchen', I1, 'sold_out')).availability).toBe('sold_out')
    expect(sent('PATCH', '/status')[0].body).toEqual({ status: 'SOLD_OUT' })
    routes[`PATCH /restaurant/menu/items/${I1}/status`] = { body: item({ status: 'DISABLED' }) }
    expect((await r.setAvailability('test-kitchen', I1, 'unavailable')).availability).toBe('unavailable')
    expect(sent('PATCH', '/status')[1].body).toEqual({ status: 'DISABLED' })
    routes[`POST /restaurant/menu/items/${I1}/duplicate`] = { status: 201, body: item({ id: I2, slug: 'classic-burger-copy', name: 'Classic Burger (copy)', status: 'DISABLED' }) }
    expect(await r.duplicateItem('test-kitchen', I1)).toMatchObject({ id: I2, name: 'Classic Burger (copy)', availability: 'unavailable' })
    routes[`DELETE /restaurant/menu/items/${I1}`] = { body: item({ status: 'ARCHIVED' }) }
    await r.archiveItem('test-kitchen', I1)
    expect(sent('DELETE', `/restaurant/menu/items/${I1}`)).toHaveLength(1)
  })

  it('categories: create, rename with the version, reorder as one list, and a category with items cannot be removed', async () => {
    const r = await repo(); await r.getMenu('test-kitchen')
    routes[`POST /restaurant/locations/${L1}/menu/categories`] = { status: 201, body: category({ id: C3, name: 'Desserts', display_order: 2, version: 1 }) }
    expect(await r.saveCategory('test-kitchen', { name: 'Desserts', description: 'Sweet' })).toMatchObject({ id: C3, name: 'Desserts', displayOrder: 2, restaurantId: 'test-kitchen' })
    expect(sent('POST', '/categories')[0].body).toEqual({ name: 'Desserts', description: 'Sweet' })
    routes[`PATCH /restaurant/menu/categories/${C1}`] = { body: category({ name: 'Burgers & Wraps', version: 3 }) }
    expect((await r.saveCategory('test-kitchen', { id: C1, name: 'Burgers & Wraps' })).name).toBe('Burgers & Wraps')
    expect(sent('PATCH', `/categories/${C1}`)[0].body).toEqual({ version: 2, name: 'Burgers & Wraps', description: null })
    routes[`POST /restaurant/locations/${L1}/menu/categories/reorder`] = { body: { ...MENU, categories: [category({ id: C2, name: 'Sides', display_order: 0 }), category({ display_order: 1 })] } }
    expect((await r.reorderCategories('test-kitchen', [C2, C1])).map((c) => c.name)).toEqual(['Sides', 'Burgers'])
    expect(sent('POST', '/categories/reorder')[0].body).toEqual({ categories: [C2, C1] })
    routes[`DELETE /restaurant/menu/categories/${C1}`] = refuse(409, 'category_not_empty')
    await expect(r.deleteCategory('test-kitchen', C1)).rejects.toThrow('category_not_empty')
  })

  it('turns the uploader data URL into a file without touching the network', () => {
    const { blob, name } = dataUrlToBlob('data:image/jpeg;base64,/9j/4AAQSkZJRg==')
    expect(blob.type).toBe('image/jpeg'); expect(blob.size).toBe(10); expect(name).toBe('photo.jpg')
    expect(dataUrlToBlob('data:image/webp;base64,UklGRg==').name).toBe('photo.webp')
  })
})
