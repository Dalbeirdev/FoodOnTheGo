/**
 * Menu abstractions (Module 07). Categories and items are restaurant-specific and dynamic —
 * nothing here is a worldwide category list. Prices are integer minor units + ISO 4217 code;
 * formatting happens in the UI through Intl. MockMenuRepository supplies development data;
 * ApiMenuRepository replaces it without touching the screens.
 */
export type MenuCategory = {
  id: string
  restaurantId: string
  name: string
  description?: string
  displayOrder: number
}

export type ItemAvailability = 'available' | 'unavailable' | 'sold_out' | 'temporarily_unavailable'
export type MenuItemStatus = 'active' | 'inactive'

export type MenuItem = {
  id: string
  publicId: string
  slug: string
  restaurantId: string
  categoryId: string
  name: string
  /** Latin transliterations / other-language names for search only. */
  alternateNames?: string[]
  description: string
  images: string[]
  image: string
  fallback: string
  /** Integer minor units (paise, cents, yen…). */
  basePriceMinor: number
  /** ISO 4217 — normally the restaurant's currency. */
  currency: string
  availability: ItemAvailability
  /** Restaurant-provided structured tags (Vegetarian, Vegan, Gluten-Free…). Not a medical/allergy guarantee. */
  dietaryTags: string[]
  customizable: boolean
  prepTimeMin: number
  displayOrder: number
  featured: boolean
  status: MenuItemStatus
}

export type MenuFilterValue = { search?: string; categoryId?: string | null; dietary?: string[]; availableOnly?: boolean; cursor?: string | null; limit?: number }
export type MenuPage = { items: MenuItem[]; nextCursor: string | null; total: number }

export interface MenuRepository {
  /** Categories in display order; [] when the restaurant has no menu yet. */
  getCategories(restaurantId: string): Promise<MenuCategory[]>
  /** Paginated items (optionally by category / search / filters). */
  getItems(restaurantId: string, filter?: MenuFilterValue): Promise<MenuPage>
  getItemBySlug(restaurantId: string, slug: string): Promise<MenuItem | null>
  /** Dietary tags actually used by this restaurant's menu (drives the filter chips). */
  getDietaryTags(restaurantId: string): Promise<string[]>
}

export class MenuError extends Error {
  code: 'unavailable' | 'network' | 'not-found'
  constructor(code: MenuError['code'], message: string) { super(message); this.name = 'MenuError'; this.code = code }
}
