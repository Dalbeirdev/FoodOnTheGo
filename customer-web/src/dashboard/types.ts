import type { Restaurant, OpeningHours } from '../repositories/types'
import type { MenuCategory, MenuItem, ModifierGroup, VariantGroup } from '../menu/repositories'
import type { Order, OrderStatus } from '../order/repositories'
import type { PickupSettings } from '../pickup/repositories'
import type { Review } from '../review/repositories'

/**
 * Restaurant Dashboard (Module 17) — domain contracts. The dashboard shares the customer-side domain (Restaurant, menu,
 * hours, orders, pickup verification, reviews); everything here is the restaurant-operations layer on top of it.
 * All mocks are development-only; the backend is authoritative for every transition, permission and aggregate later.
 */
export type Permission =
  | 'restaurant.profile.view' | 'restaurant.profile.edit' | 'hours.edit' | 'pickup.settings.edit'
  | 'menu.view' | 'menu.edit'
  | 'orders.view' | 'orders.update' | 'pickup.verify'
  | 'reviews.view' | 'reviews.respond'
  | 'staff.view' | 'staff.manage'
  | 'analytics.view' | 'notifications.view' | 'settings.manage'
export type RoleId = 'owner' | 'manager' | 'order_staff' | 'menu_manager' | 'viewer'
/** Roles are named permission bundles — the UI checks permissions, never the role label. */
export type Role = { id: RoleId; permissions: Permission[] }
export type OnboardingStatus = 'DRAFT' | 'SUBMITTED' | 'UNDER_REVIEW' | 'APPROVED' | 'REJECTED' | 'SUSPENDED'

export type Organization = { id: string; name: string; logo: string | null; onboardingStatus: OnboardingStatus; locationIds: string[] }
/** Location-level restaurant profile data that the shared Restaurant model does not carry (contact, images, status). */
export type LocationProfile = {
  restaurantId: string
  organizationId: string
  locationName: string
  contact: { phone: string | null; website: string | null; publicEmail: string | null }
  logo: string | null
  coverImage: string | null
  gallery: string[]
  onboardingStatus: OnboardingStatus
  /** Platform-controlled: the restaurant cannot activate itself. */
  active: boolean
}
export type DashboardLocation = { restaurant: Restaurant; profile: LocationProfile }
export type ProfilePatch = Partial<Pick<Restaurant, 'name' | 'description' | 'cuisines' | 'features' | 'prepTimeMin'>> & { contact?: Partial<LocationProfile['contact']>; logo?: string | null; coverImage?: string | null; gallery?: string[] }

/** Special / holiday hours or a temporary closure for one restaurant-local date (never hardcoded per country). */
export type SpecialHours = { id: string; date: string; label: string; closed: boolean; periods: Array<{ open: string; close: string }> }
export type HoursValidationIssue = { day: number | null; index: number | null; code: 'invalid_time' | 'overlap' | 'zero_length' | 'too_many' }

export type StaffStatus = 'active' | 'invited' | 'suspended'
export type StaffMember = { id: string; name: string; email: string; role: RoleId; locationAccess: 'all' | string[]; status: StaffStatus }
export type StaffInvite = { name: string; email: string; role: RoleId; locationAccess: 'all' | string[] }

export type NotificationType = 'new_order' | 'order_update' | 'review' | 'pickup' | 'platform' | 'warning'
export type DashboardNotification = { id: string; locationId: string | null; type: NotificationType; title: string; body: string; at: string; read: boolean; link: string | null }
export type NotificationPrefs = { newOrders: boolean; orderDelays: boolean; pickup: boolean; reviews: boolean; platform: boolean }
export type LocationSettings = { language: string; notifications: NotificationPrefs; soundOnNewOrder: boolean }

export type OrderTab = 'new' | 'preparing' | 'ready' | 'completed' | 'cancelled'
export type OrderListFilter = { tab: OrderTab; query?: string; date?: 'today' | 'all' }
export type RejectReason = 'item_unavailable' | 'kitchen_capacity' | 'closing' | 'unable_to_prepare' | 'other'
export type DelayReason = 'high_demand' | 'taking_longer' | 'capacity' | 'other'
export type VerificationFailure = 'invalid' | 'expired' | 'wrong_location' | 'already_used' | 'not_ready' | 'permission'
export type VerificationResult = { ok: true; order: Order } | { ok: false; reason: VerificationFailure }

export type ManagedMenu = { categories: MenuCategory[]; items: MenuItem[]; groups: Record<string, { variantGroups: VariantGroup[]; modifierGroups: ModifierGroup[] }> }
export type MenuItemInput = Omit<MenuItem, 'id' | 'publicId' | 'slug' | 'restaurantId' | 'currency' | 'image' | 'fallback'> & { id?: string; images: string[] }
export type MenuValidationIssue = { field: string; code: 'required' | 'invalid_price' | 'invalid_range' | 'duplicate' | 'min_max' | 'no_options' }

export type AnalyticsRange = 'today' | '7d' | '30d' | 'custom'
export type AnalyticsQuery = { range: AnalyticsRange; from?: string; to?: string }
export type SeriesPoint = { label: string; value: number; secondary?: number }
export type AnalyticsSummary = {
  currency: string
  orders: number; ordersDelta: number
  revenueMinor: number; revenueDelta: number
  averageOrderMinor: number
  averagePrepMinutes: number
  cancellationRate: number
  averageRating: number | null; reviewCount: number
  ordersByDay: SeriesPoint[]
  revenueByDay: SeriesPoint[]
  statusDistribution: Array<{ status: OrderStatus; count: number }>
  topItems: Array<{ name: string; orders: number; image: string | null }>
  pickupHours: SeriesPoint[]
  ratingDistribution: Array<{ rating: number; count: number }>
  unavailableItems: number
  source: 'mock'
}
export type OverviewSnapshot = {
  currency: string
  ordersToday: number; ordersTodayDelta: number
  preparing: number; ready: number; completedToday: number
  revenueTodayMinor: number; revenueDelta: number
  revenueByHour: SeriesPoint[]
  averagePrepMinutes: number; prepDelta: number
  averageRating: number | null; ratingDelta: number
  unavailableItems: number
  recentOrders: Order[]
  topItems: Array<{ name: string; orders: number; image: string | null }>
}

export interface RestaurantManagementRepository {
  getOrganization(): Promise<Organization>
  getLocations(): Promise<DashboardLocation[]>
  getLocation(id: string): Promise<DashboardLocation | null>
  updateProfile(id: string, patch: ProfilePatch): Promise<DashboardLocation>
  setAcceptingOrders(id: string, accepting: boolean): Promise<DashboardLocation>
  updateHours(id: string, hours: OpeningHours): Promise<DashboardLocation>
  getSpecialHours(id: string): Promise<SpecialHours[]>
  saveSpecialHours(id: string, list: SpecialHours[]): Promise<SpecialHours[]>
  getPickupSettings(id: string): Promise<PickupSettings>
  savePickupSettings(id: string, s: PickupSettings): Promise<PickupSettings>
  getSettings(id: string): Promise<LocationSettings>
  saveSettings(id: string, s: LocationSettings): Promise<LocationSettings>
}
export interface MenuManagementRepository {
  getMenu(locationId: string): Promise<ManagedMenu>
  saveCategory(locationId: string, c: Partial<MenuCategory> & { name: string }): Promise<MenuCategory>
  reorderCategories(locationId: string, orderedIds: string[]): Promise<MenuCategory[]>
  deleteCategory(locationId: string, id: string): Promise<void>
  saveItem(locationId: string, input: MenuItemInput, groups?: ManagedMenu['groups'][string]): Promise<MenuItem>
  duplicateItem(locationId: string, id: string): Promise<MenuItem>
  setAvailability(locationId: string, id: string, availability: MenuItem['availability']): Promise<MenuItem>
  archiveItem(locationId: string, id: string): Promise<void>
}
export interface RestaurantOrderRepository {
  list(locationId: string, f: OrderListFilter): Promise<{ orders: Order[]; counts: Record<OrderTab, number> }>
  get(locationId: string, orderNumber: string): Promise<Order | null>
  accept(locationId: string, orderNumber: string): Promise<Order>
  reject(locationId: string, orderNumber: string, reason: RejectReason, internalNote: string): Promise<Order>
  startPreparing(locationId: string, orderNumber: string): Promise<Order>
  delay(locationId: string, orderNumber: string, etaReadyAt: string, reason: DelayReason): Promise<Order>
  markReady(locationId: string, orderNumber: string): Promise<Order>
  verifyPickup(locationId: string, code: string): Promise<VerificationResult>
  history(locationId: string, q: { query?: string; status?: 'all' | 'completed' | 'cancelled' | 'rejected'; cursor?: string | null; limit?: number }): Promise<{ orders: Order[]; nextCursor: string | null; total: number }>
}
export interface RestaurantStaffRepository {
  list(): Promise<StaffMember[]>
  invite(i: StaffInvite): Promise<StaffMember>
  update(id: string, patch: Partial<Pick<StaffMember, 'role' | 'locationAccess' | 'status'>>): Promise<StaffMember>
  remove(id: string): Promise<void>
}
export interface RestaurantReviewRepository {
  summary(locationId: string): Promise<{ averageRating: number | null; reviewCount: number; distribution: Array<{ rating: number; count: number }> }>
  list(locationId: string): Promise<Review[]>
  respond(reviewId: string, text: string, responderName: string): Promise<Review>
}
export interface RestaurantAnalyticsRepository { summary(locationId: string, q: AnalyticsQuery): Promise<AnalyticsSummary>; overview(locationId: string): Promise<OverviewSnapshot> }
export interface RestaurantNotificationRepository {
  list(locationId: string | null): Promise<DashboardNotification[]>
  markRead(id: string): Promise<void>
  markAllRead(locationId: string | null): Promise<void>
}
export type DashboardRepositories = {
  management: RestaurantManagementRepository; menu: MenuManagementRepository; orders: RestaurantOrderRepository
  staff: RestaurantStaffRepository; reviews: RestaurantReviewRepository; analytics: RestaurantAnalyticsRepository; notifications: RestaurantNotificationRepository
}
