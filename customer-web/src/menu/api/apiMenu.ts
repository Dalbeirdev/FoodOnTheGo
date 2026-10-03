/**
 * The customer menu on the backend (Module 24).
 *
 * The pages keep the MenuRepository they always had; in API mode it reads the published menu of a restaurant
 * (GET /restaurants/{slug}/menu) and the item page (GET /restaurants/{slug}/items/{itemSlug}) instead of the
 * development fixtures. What the backend is authoritative for:
 *  - what exists: only ACTIVE categories and customer-visible items come back; a disabled, archived or unknown
 *    item is a 404 and becomes `null` here — nothing is invented;
 *  - availability: sold out / temporarily unavailable / an impossible required group are the backend's answer per
 *    item; the restaurant's own open / accepting state stays on the Restaurant (the pages already combine the two);
 *  - prices: integer minor units in the menu's currency. The item page previews a configuration with the same
 *    rule the backend applies (base + adjustments × quantity); the backend's own answer is `getPriceQuote` — the
 *    only price that will ever be charged (the cart and checkout modules use it).
 *
 * The menu document is reused for 30 seconds per restaurant (search, filters and paging are applied here, as the
 * fixtures did); the catalog version the backend sends makes a changed menu visible on the next load.
 */
import { ApiError, api } from '../../api/client'
import { apiRestaurants } from '../../restaurants/api/restaurantData'
import { MenuError, type ItemAvailability, type MenuCategory, type MenuFilterValue, type MenuItem, type MenuItemDetail, type MenuPage, type MenuRepository, type ModifierGroup, type OptionGroup, type VariantGroup } from '../repositories'

/* ------------------------------------------------------------------ wire formats (OpenAPI: PublicMenu, PublicMenuItemDetail, PriceQuote) */
export type MenuAvailabilityDto = { visible: boolean; orderable: boolean; reason: string | null; restaurant_reason: string | null }
export type MenuImageDto = { id: string; url: string; alt_text: string | null; width: number; height: number; display_order: number }
export type DietaryTagDto = { code: string; name: string }
export type PublicMenuItemDto = {
  id: string; slug: string; name: string; description: string | null; base_price_minor: number; currency: string
  status: 'ACTIVE' | 'SOLD_OUT' | 'TEMPORARILY_UNAVAILABLE'
  images: MenuImageDto[]; dietary_tags: DietaryTagDto[]; customizable: boolean; preparation_minutes: number | null; featured: boolean
  display_order: number; version: number; availability: MenuAvailabilityDto
}
export type PublicMenuCategoryDto = { id: string; name: string; description: string | null; display_order: number; items: PublicMenuItemDto[] }
export type PublicMenuDto = {
  restaurant: { id: string; slug: string; name: string; currency: string; timezone: string }
  availability: Record<string, unknown>
  menu: { id: string; name: string; currency: string; catalog_version: number; updated_at: string | null } | null
  categories: PublicMenuCategoryDto[]
  dietary_tags: DietaryTagDto[]
}
export type PublicOptionDto = { id: string; name: string; price_adjustment_minor: number; available: boolean; default_selected: boolean; display_order: number }
export type PublicOptionGroupDto = { id: string; kind: 'VARIANT' | 'MODIFIER'; name: string; description: string | null; required: boolean; min_selections: number; max_selections: number; display_order: number; options: PublicOptionDto[] }
export type PublicMenuItemDetailDto = {
  data: PublicMenuItemDto & {
    restaurant: { id: string; slug: string; name: string }; category: { id: string; name: string }; menu: { id: string; currency: string; catalog_version: number }
    min_quantity: number; max_quantity: number; instructions_max_length: number; allergen_information: string | null; ingredients: string | null
    variant_groups: PublicOptionGroupDto[]; modifier_groups: PublicOptionGroupDto[]
  }
  restaurant_availability: Record<string, unknown>
}
export type PriceQuoteDto = {
  data: {
    base_price_minor: number; adjustments_minor: number; unit_price_minor: number; quantity: number; line_total_minor: number; currency: string
    selections: Array<{ group_id: string; group_name: string; kind: 'VARIANT' | 'MODIFIER'; options: Array<{ id: string; name: string; price_adjustment_minor: number }> }>
    item_id: string; item_version: number; catalog_version: number
  }
  availability: MenuAvailabilityDto
}
/** The backend's price for one configuration — the only price that is ever charged. */
export type PriceQuote = { basePriceMinor: number; adjustmentsMinor: number; unitPriceMinor: number; quantity: number; lineTotalMinor: number; currency: string; orderable: boolean; reason: string | null; catalogVersion: number }

/* ------------------------------------------------------------------ mapping */
/** Sold out and temporarily unavailable come from the item's state; an impossible required group makes it unavailable. The restaurant's own state is not an item property. */
export function itemAvailability(d: Pick<PublicMenuItemDto, 'status' | 'availability'>): ItemAvailability {
  if (d.status === 'SOLD_OUT') return 'sold_out'
  if (d.status === 'TEMPORARILY_UNAVAILABLE') return 'temporarily_unavailable'
  return d.availability.reason === 'REQUIRED_GROUP_UNAVAILABLE' ? 'unavailable' : 'available'
}
const initials = (name: string) => [...name.trim()].slice(0, 2).join('').toUpperCase() || '🍽️'

export function toMenuItem(d: PublicMenuItemDto, restaurantId: string, categoryId: string): MenuItem {
  const images = d.images.map((i) => i.url)
  return {
    id: d.id, publicId: d.id, slug: d.slug, restaurantId, categoryId, name: d.name, description: d.description ?? '',
    images, image: images[0] ?? '', fallback: initials(d.name),
    basePriceMinor: d.base_price_minor, currency: d.currency, availability: itemAvailability(d),
    dietaryTags: d.dietary_tags.map((t) => t.name), customizable: d.customizable, prepTimeMin: d.preparation_minutes ?? 0,
    displayOrder: d.display_order, featured: d.featured, status: 'active',
  }
}
const toGroup = (g: PublicOptionGroupDto): OptionGroup => ({
  id: g.id, kind: g.kind === 'VARIANT' ? 'variant' : 'modifier', name: g.name, description: g.description ?? undefined, required: g.required,
  minSelections: g.min_selections, maxSelections: g.max_selections, displayOrder: g.display_order,
  options: g.options.map((o) => ({ id: o.id, name: o.name, priceAdjustmentMinor: o.price_adjustment_minor, available: o.available, defaultSelected: o.default_selected, displayOrder: o.display_order })),
})
export function toMenuItemDetail(d: PublicMenuItemDetailDto['data'], restaurantId: string): MenuItemDetail {
  return {
    ...toMenuItem(d, restaurantId, d.category.id),
    restaurantSlug: d.restaurant.slug,
    allergenInformation: d.allergen_information ?? undefined,
    minimumQuantity: d.min_quantity, maximumQuantity: d.max_quantity, instructionsMaxLength: d.instructions_max_length,
    variantGroups: d.variant_groups.map(toGroup) as VariantGroup[], modifierGroups: d.modifier_groups.map(toGroup) as ModifierGroup[],
  }
}

const normalize = (s: string) => s.normalize('NFKD').replace(/[̀-ͯ]/g, '').toLowerCase()
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i
/** The pages address a restaurant by the id its development data used; the backend wants the slug (they coincide for new restaurants). */
export const slugForRestaurant = (restaurantId: string): string => apiRestaurants()?.find((r) => r.id === restaurantId || r.publicId === restaurantId)?.slug ?? restaurantId

function menuError(e: unknown): MenuError {
  if (e instanceof ApiError) {
    if (e.kind === 'network') return new MenuError('network', e.message)
    if (e.kind === 'not_found') return new MenuError('not-found', e.message)
  }
  return new MenuError('unavailable', 'The menu could not be loaded. Please try again.')
}

const REUSE_MS = 30_000
const PAGE = 24

/* ------------------------------------------------------------------ repository */
export class ApiMenuRepository implements MenuRepository {
  private documents = new Map<string, { doc: PublicMenuDto; at: number }>()
  private loading = new Map<string, Promise<PublicMenuDto>>()
  private readonly slugOf: (restaurantId: string) => string

  constructor(slugOf: (restaurantId: string) => string = slugForRestaurant) { this.slugOf = slugOf }

  /** Forget what was loaded (after the dashboard changed a menu in the same browser, or in tests). */
  invalidate(restaurantId?: string) { if (restaurantId) this.documents.delete(this.slugOf(restaurantId)); else this.documents.clear() }

  private document(restaurantId: string): Promise<PublicMenuDto> {
    const slug = this.slugOf(restaurantId)
    const cached = this.documents.get(slug)
    if (cached && Date.now() - cached.at < REUSE_MS) return Promise.resolve(cached.doc)
    let pending = this.loading.get(slug)
    if (!pending) {
      pending = api<PublicMenuDto>(`/restaurants/${encodeURIComponent(slug)}/menu`, { auth: false })
        .then((doc) => { this.documents.set(slug, { doc, at: Date.now() }); return doc })
        .catch((e: unknown) => { throw menuError(e) })
        .finally(() => this.loading.delete(slug))
      this.loading.set(slug, pending)
    }
    return pending
  }
  private items(doc: PublicMenuDto, restaurantId: string): MenuItem[] {
    return doc.categories.flatMap((c) => c.items.map((i) => toMenuItem(i, restaurantId, c.id)))
  }

  async getCategories(restaurantId: string): Promise<MenuCategory[]> {
    const doc = await this.document(restaurantId)
    return doc.categories.map((c) => ({ id: c.id, restaurantId, name: c.name, description: c.description ?? undefined, icon: null, displayOrder: c.display_order }))
  }

  /** Search, filters and paging over the published document, exactly as the development repository did. */
  async getItems(restaurantId: string, f: MenuFilterValue = {}): Promise<MenuPage> {
    const doc = await this.document(restaurantId)
    const q = normalize(f.search ?? '').trim()
    const categories = new Map(doc.categories.map((c) => [c.id, c]))
    let list = this.items(doc, restaurantId)
    if (f.categoryId) list = list.filter((i) => i.categoryId === f.categoryId)
    if (q) list = list.filter((i) => [i.name, i.description, categories.get(i.categoryId)?.name ?? '', categories.get(i.categoryId)?.description ?? ''].map(normalize).some((h) => h.includes(q)))
    if (f.dietary?.length) list = list.filter((i) => f.dietary!.every((d) => i.dietaryTags.includes(d)))
    if (f.availableOnly) list = list.filter((i) => i.availability === 'available')
    const offset = f.cursor && /^c\d+$/.test(f.cursor) ? Number(f.cursor.slice(1)) : 0
    const limit = f.limit ?? PAGE
    return { items: list.slice(offset, offset + limit), nextCursor: offset + limit < list.length ? `c${offset + limit}` : null, total: list.length }
  }

  async getItemBySlug(restaurantId: string, slug: string): Promise<MenuItem | null> {
    return this.items(await this.document(restaurantId), restaurantId).find((i) => i.slug === slug || i.id === slug) ?? null
  }

  /** null = the backend says this item is not on the menu (unknown, disabled, archived, hidden category, another restaurant's item). */
  async getItemDetail(restaurantId: string, slug: string): Promise<MenuItemDetail | null> {
    let itemSlug = slug
    if (UUID.test(slug)) {
      const known = await this.getItemBySlug(restaurantId, slug).catch(() => null)
      if (!known) return null
      itemSlug = known.slug
    }
    try {
      const d = await api<PublicMenuItemDetailDto>(`/restaurants/${encodeURIComponent(this.slugOf(restaurantId))}/items/${encodeURIComponent(itemSlug)}`, { auth: false })
      return toMenuItemDetail(d.data, restaurantId)
    } catch (e) {
      if (e instanceof ApiError && e.kind === 'not_found') return null
      throw menuError(e)
    }
  }

  /** The labels in use on this menu, as the backend names them. */
  async getDietaryTags(restaurantId: string): Promise<string[]> {
    return (await this.document(restaurantId)).dietary_tags.map((t) => t.name)
  }

  /**
   * The backend's price for a configuration (base + adjustments, × quantity). Refused selections arrive as an ApiError
   * (422 invalid_selection with `details.issues`), exactly as the cart will see them later.
   */
  async getPriceQuote(restaurantId: string, itemSlug: string, selections: Record<string, string[]>, quantity = 1): Promise<PriceQuote> {
    const res = await api<PriceQuoteDto>(`/restaurants/${encodeURIComponent(this.slugOf(restaurantId))}/items/${encodeURIComponent(itemSlug)}/price-quote`, {
      method: 'POST', auth: false,
      body: { selections: Object.entries(selections).filter(([, ids]) => ids.length > 0).map(([group_id, option_ids]) => ({ group_id, option_ids })), quantity },
    })
    return {
      basePriceMinor: res.data.base_price_minor, adjustmentsMinor: res.data.adjustments_minor, unitPriceMinor: res.data.unit_price_minor, quantity: res.data.quantity,
      lineTotalMinor: res.data.line_total_minor, currency: res.data.currency, orderable: res.availability.orderable, reason: res.availability.reason, catalogVersion: res.data.catalog_version,
    }
  }
}
