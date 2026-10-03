/**
 * Menu management on the backend (Module 24) for the Restaurant Dashboard.
 *
 * The Menu page keeps the MenuManagementRepository it had with the development data. In API mode:
 *  - the menu of the location comes from GET /restaurant/locations/{location}/menu (every category and item that
 *    is not archived, plus the archived items for the "Archived" filter); the backend creates it on first use in the
 *    currency of the location;
 *  - every write is authorised and validated by the backend (restaurant.menu.manage) and refused with an ApiError
 *    (403 no permission, 404 not yours, 409 changed in the meantime / category not empty, 422 invalid); edits carry
 *    the version the page loaded;
 *  - the page's availability choice is the backend's item status (available → ACTIVE, sold out → SOLD_OUT,
 *    temporarily unavailable → TEMPORARILY_UNAVAILABLE, unavailable → DISABLED); archiving is final;
 *  - option groups are sent as one document: groups and options that already exist keep their ids, new ones have
 *    none, the ones left out are archived by the backend;
 *  - a new photo (a data URL from the uploader) is uploaded as multipart after the item is saved; a photo that was
 *    removed is archived; the backend checks the file by content;
 *  - dietary labels are the backend's list (codes); the page shows their names.
 *
 * The backend id of a location is looked up through the management repository (the dashboard addresses locations
 * by the id its development data used).
 */
import { dataUrlToBlob } from '../../api/dataUrl'
import { ApiError, api } from '../../api/client'
import type { ItemAvailability, MenuCategory, MenuItem, ModifierGroup, OptionGroup, VariantGroup } from '../../menu/repositories'
import type { ManagedMenu, MenuItemInput, MenuManagementRepository } from '../types'
import type { ApiRestaurantManagementRepository } from './apiDashboard'

const R = { context: 'restaurant' as const }

/* ------------------------------------------------------------------ wire formats (OpenAPI: ManagedMenu, ManagedMenuItem, ManagedMenuCategory, MenuImage) */
export type ItemStatusDto = 'ACTIVE' | 'SOLD_OUT' | 'TEMPORARILY_UNAVAILABLE' | 'DISABLED' | 'ARCHIVED'
export type OptionStatusDto = 'ACTIVE' | 'TEMPORARILY_UNAVAILABLE' | 'DISABLED' | 'ARCHIVED'
export type ManagedOptionDto = { id: string; name: string; price_adjustment_minor: number; status: OptionStatusDto; default_selected: boolean; display_order: number }
export type ManagedGroupDto = { id: string; kind: 'VARIANT' | 'MODIFIER'; name: string; description: string | null; required: boolean; min_selections: number; max_selections: number; status: 'ACTIVE' | 'INACTIVE' | 'ARCHIVED'; display_order: number; options: ManagedOptionDto[] }
export type ManagedImageDto = { id: string; url: string; alt_text: string | null; width: number; height: number; display_order: number }
export type ManagedItemDto = {
  id: string; slug: string; category_id: string | null; name: string; description: string | null; base_price_minor: number; currency: string; status: ItemStatusDto
  preparation_minutes: number | null; featured: boolean; min_quantity: number; max_quantity: number
  dietary_tags: Array<{ code: string; name: string }>; allergen_information: string | null; ingredients: string | null; images: ManagedImageDto[]
  variant_groups: ManagedGroupDto[]; modifier_groups: ManagedGroupDto[]
  availability: { visible: boolean; orderable: boolean; reason: string | null; restaurant_reason: string | null } | null
  display_order: number; version: number; archived_at: string | null; updated_at: string | null
}
export type ManagedCategoryDto = { id: string; name: string; description: string | null; status: 'ACTIVE' | 'INACTIVE' | 'ARCHIVED'; display_order: number; item_count: number; version: number; updated_at: string | null }
export type ManagedMenuDto = {
  id: string; name: string; description: string | null; status: 'DRAFT' | 'ACTIVE' | 'INACTIVE' | 'ARCHIVED'; currency: string; catalog_version: number; version: number; updated_at: string | null
  location: { id: string; slug: string; name: string; timezone: string; currency: string }
  categories: ManagedCategoryDto[]; items: ManagedItemDto[]; limits: Record<string, unknown>
}
type TaxonomyDto = { dietary_tags?: Array<{ code: string; name: string; kind: string }>; menu?: { dietary_tags_per_item?: number } }

/* ------------------------------------------------------------------ mapping */
export const AVAILABILITY_OF: Record<ItemStatusDto, ItemAvailability> = { ACTIVE: 'available', SOLD_OUT: 'sold_out', TEMPORARILY_UNAVAILABLE: 'temporarily_unavailable', DISABLED: 'unavailable', ARCHIVED: 'unavailable' }
export const STATUS_OF: Record<ItemAvailability, Exclude<ItemStatusDto, 'ARCHIVED'>> = { available: 'ACTIVE', sold_out: 'SOLD_OUT', temporarily_unavailable: 'TEMPORARILY_UNAVAILABLE', unavailable: 'DISABLED' }
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i
const isDataUrl = (url: string) => url.startsWith('data:')
const initials = (name: string) => [...name.trim()].slice(0, 2).join('').toUpperCase() || '🍽️'

export const toManagedCategory = (c: ManagedCategoryDto, restaurantId: string): MenuCategory => ({ id: c.id, restaurantId, name: c.name, description: c.description ?? undefined, icon: null, displayOrder: c.display_order })
export function toManagedItem(d: ManagedItemDto, restaurantId: string): MenuItem {
  const images = d.images.map((i) => i.url)
  const groups = [...d.variant_groups, ...d.modifier_groups].filter((g) => g.status === 'ACTIVE')
  return {
    id: d.id, publicId: d.id, slug: d.slug, restaurantId, categoryId: d.category_id ?? '', name: d.name, description: d.description ?? '',
    images, image: images[0] ?? '', fallback: initials(d.name),
    basePriceMinor: d.base_price_minor, currency: d.currency, availability: AVAILABILITY_OF[d.status],
    dietaryTags: d.dietary_tags.map((t) => t.name), customizable: groups.some((g) => g.options.some((o) => o.status !== 'ARCHIVED')), prepTimeMin: d.preparation_minutes ?? 0,
    displayOrder: d.display_order, featured: d.featured, status: d.status === 'ARCHIVED' ? 'inactive' : 'active',
  }
}
const toGroup = (g: ManagedGroupDto): OptionGroup => ({
  id: g.id, kind: g.kind === 'VARIANT' ? 'variant' : 'modifier', name: g.name, description: g.description ?? undefined, required: g.required,
  minSelections: g.min_selections, maxSelections: g.max_selections, displayOrder: g.display_order,
  options: g.options.filter((o) => o.status !== 'ARCHIVED').map((o) => ({ id: o.id, name: o.name, priceAdjustmentMinor: o.price_adjustment_minor, available: o.status === 'ACTIVE', defaultSelected: o.default_selected, displayOrder: o.display_order })),
})

export { dataUrlToBlob }

/* ------------------------------------------------------------------ repository */
export class ApiMenuManagementRepository implements MenuManagementRepository {
  /** Versions of the items and categories the page has seen (optimistic concurrency). */
  private versions = new Map<string, number>()
  private optionStatus = new Map<string, OptionStatusDto>()
  private tags: Array<{ code: string; name: string }> | null = null
  private readonly management: ApiRestaurantManagementRepository
  constructor(management: ApiRestaurantManagementRepository) { this.management = management }

  private async menuUrl(restaurantId: string, path = ''): Promise<string> {
    let uuid = this.management.locationUuid(restaurantId)
    if (!uuid) { await this.management.getLocations().catch(() => undefined); uuid = this.management.locationUuid(restaurantId) }
    if (!uuid) throw new Error('location_unavailable')
    return `/restaurant/locations/${uuid}/menu${path}`
  }
  private rememberItem(d: ManagedItemDto, restaurantId: string): MenuItem {
    this.versions.set(d.id, d.version)
    for (const g of [...d.variant_groups, ...d.modifier_groups]) for (const o of g.options) this.optionStatus.set(o.id, o.status)
    return toManagedItem(d, restaurantId)
  }
  private rememberCategory(c: ManagedCategoryDto, restaurantId: string): MenuCategory { this.versions.set(c.id, c.version); return toManagedCategory(c, restaurantId) }
  private toManagedMenu(doc: ManagedMenuDto, restaurantId: string): ManagedMenu {
    this.versions.set(doc.id, doc.version)
    const groups: ManagedMenu['groups'] = {}
    for (const d of doc.items) {
      groups[d.id] = {
        variantGroups: d.variant_groups.filter((g) => g.status !== 'ARCHIVED').map(toGroup) as VariantGroup[],
        modifierGroups: d.modifier_groups.filter((g) => g.status !== 'ARCHIVED').map(toGroup) as ModifierGroup[],
      }
    }
    return { categories: doc.categories.filter((c) => c.status !== 'ARCHIVED').map((c) => this.rememberCategory(c, restaurantId)), items: doc.items.map((d) => this.rememberItem(d, restaurantId)), groups }
  }

  async getMenu(restaurantId: string): Promise<ManagedMenu> {
    return this.toManagedMenu(await api<ManagedMenuDto>(await this.menuUrl(restaurantId), { ...R, query: { include: 'archived' } }), restaurantId)
  }

  /** The dietary labels the backend knows (code + name); the page offers them instead of free text. */
  async dietaryTags(): Promise<Array<{ code: string; name: string }>> {
    if (this.tags) return this.tags
    const t = await api<TaxonomyDto>('/restaurant/taxonomy', R)
    this.tags = (t.dietary_tags ?? []).map(({ code, name }) => ({ code, name }))
    return this.tags
  }
  private async tagCodes(names: string[]): Promise<string[]> {
    const tags = await this.dietaryTags().catch(() => [] as Array<{ code: string; name: string }>)
    return [...new Set(names.map((n) => n.trim()).filter(Boolean).map((n) => tags.find((t) => t.name.toLowerCase() === n.toLowerCase() || t.code === n.toUpperCase())?.code ?? n.toUpperCase().replace(/[^A-Z0-9]+/g, '_')))]
  }

  async saveCategory(restaurantId: string, c: Partial<MenuCategory> & { name: string }): Promise<MenuCategory> {
    const body = { name: c.name, description: c.description?.trim() || null }
    if (c.id) return this.rememberCategory(await api<ManagedCategoryDto>(`/restaurant/menu/categories/${c.id}`, { ...R, method: 'PATCH', body: { version: this.versions.get(c.id) ?? 1, ...body } }), restaurantId)
    return this.rememberCategory(await api<ManagedCategoryDto>(await this.menuUrl(restaurantId, '/categories'), { ...R, method: 'POST', body }), restaurantId)
  }
  /** Every category exactly once, in the new order — all or nothing. */
  async reorderCategories(restaurantId: string, orderedIds: string[]): Promise<MenuCategory[]> {
    return this.toManagedMenu(await api<ManagedMenuDto>(await this.menuUrl(restaurantId, '/categories/reorder'), { ...R, method: 'POST', body: { categories: orderedIds } }), restaurantId).categories
  }
  /** Archives the category; the backend refuses while it still holds items. */
  async deleteCategory(_restaurantId: string, id: string): Promise<void> {
    try { await api(`/restaurant/menu/categories/${id}`, { ...R, method: 'DELETE' }) }
    catch (e) { if (e instanceof ApiError && e.code === 'category_not_empty') throw new Error('category_not_empty'); throw e }
  }

  private groupBody(g: OptionGroup) {
    return {
      ...(UUID.test(g.id) ? { id: g.id } : {}),
      kind: g.kind === 'variant' ? 'VARIANT' : 'MODIFIER', name: g.name.trim(), description: g.description?.trim() || null,
      required: g.required, min_selections: g.minSelections, max_selections: g.maxSelections,
      options: g.options.map((o) => ({
        ...(UUID.test(o.id) ? { id: o.id } : {}),
        name: o.name.trim(), price_adjustment_minor: o.priceAdjustmentMinor,
        // The page only knows "available": a disabled option that stays unavailable keeps being disabled.
        status: o.available ? 'ACTIVE' : this.optionStatus.get(o.id) === 'DISABLED' ? 'DISABLED' : 'TEMPORARILY_UNAVAILABLE',
        default_selected: o.defaultSelected,
      })),
    }
  }
  private async itemBody(input: MenuItemInput, groups?: ManagedMenu['groups'][string]): Promise<Record<string, unknown>> {
    return {
      name: input.name.trim(), description: input.description.trim() || null, category_id: input.categoryId, base_price_minor: input.basePriceMinor,
      status: STATUS_OF[input.availability], preparation_minutes: input.prepTimeMin, featured: input.featured,
      dietary_tags: await this.tagCodes(input.dietaryTags),
      ...(groups ? { groups: [...groups.variantGroups, ...groups.modifierGroups].map((g) => this.groupBody(g)) } : {}),
    }
  }
  /** New photos (data URLs) are uploaded, photos that disappeared from the item are removed; existing URLs are kept. */
  private async syncImages(saved: ManagedItemDto, wanted: string[]): Promise<ManagedItemDto> {
    const keep = new Set(wanted.filter((u) => !isDataUrl(u)))
    let changed = false
    for (const image of saved.images) if (!keep.has(image.url)) { await api(`/restaurant/menu/items/${saved.id}/images/${image.id}`, { ...R, method: 'DELETE' }); changed = true }
    for (const url of wanted) {
      if (!isDataUrl(url)) continue
      const { blob, name } = dataUrlToBlob(url)
      const form = new FormData(); form.append('image', blob, name); form.append('alt_text', saved.name)
      await api(`/restaurant/menu/items/${saved.id}/images`, { ...R, method: 'POST', body: form }); changed = true
    }
    return changed ? api<ManagedItemDto>(`/restaurant/menu/items/${saved.id}`, R) : saved
  }

  /** Item, dietary labels, option groups and options in one request; photos follow. A stale version is a 409 the page explains. */
  async saveItem(restaurantId: string, input: MenuItemInput, groups?: ManagedMenu['groups'][string]): Promise<MenuItem> {
    const body = await this.itemBody(input, groups)
    const saved = input.id
      ? await api<ManagedItemDto>(`/restaurant/menu/items/${input.id}`, { ...R, method: 'PATCH', body: { version: this.versions.get(input.id) ?? 1, ...body } })
      : await api<ManagedItemDto>(await this.menuUrl(restaurantId, '/items'), { ...R, method: 'POST', body })
    return this.rememberItem(await this.syncImages(saved, input.images), restaurantId)
  }
  /** A deep copy named "… (copy)"; it starts unavailable (DISABLED) until the restaurant has reviewed it. */
  async duplicateItem(restaurantId: string, id: string): Promise<MenuItem> {
    return this.rememberItem(await api<ManagedItemDto>(`/restaurant/menu/items/${id}/duplicate`, { ...R, method: 'POST' }), restaurantId)
  }
  /** A switch without a version: the last request wins. */
  async setAvailability(restaurantId: string, id: string, availability: ItemAvailability): Promise<MenuItem> {
    return this.rememberItem(await api<ManagedItemDto>(`/restaurant/menu/items/${id}/status`, { ...R, method: 'PATCH', body: { status: STATUS_OF[availability] } }), restaurantId)
  }
  /** Final: the item disappears for customers and stays for order history. */
  async archiveItem(_restaurantId: string, id: string): Promise<void> { await api(`/restaurant/menu/items/${id}`, { ...R, method: 'DELETE' }) }
}
