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
export type OnboardingStatus = 'DRAFT' | 'SUBMITTED' | 'UNDER_REVIEW' | 'APPROVED' | 'REJECTED' | 'SUSPENDED' | 'INACTIVE'

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
/**
 * What only the backend knows about a location (Module 23). Present when the dashboard runs against the API:
 * the approval status with its explanation, what customers currently see, and what THIS user may do here.
 */
export type LocationLive = {
  /** Backend ids (the dashboard keeps using the development id of the location for its other sections). */
  locationId: string; organizationId: string; organizationName: string
  status: OnboardingStatus; statusNote: string | null
  organizationStatus: OnboardingStatus; organizationStatusNote: string | null
  operationalStatus: 'OPERATING' | 'TEMPORARILY_CLOSED'
  pauseReason: string | null; pausedUntil: string | null
  availability: { visibleToCustomers: boolean; openNow: boolean; openState: 'OPEN' | 'CLOSED' | 'TEMPORARILY_CLOSED'; acceptingOrders: boolean; orderable: boolean; reason: string | null; closesAt: string | null; opensNextAt: string | null }
  /** Display only: the backend decides every action again. */
  permissions: Permission[]; role: RoleId
  version: number; hoursVersion: number
}
export type DashboardLocation = { restaurant: Restaurant; profile: LocationProfile; live?: LocationLive }
/** Cuisines and features a profile may choose from (backend taxonomy). */
export type ProfileTaxonomy = { cuisines: Array<{ code: string; name: string }>; features: Array<{ code: string; name: string; category: string }>; limits: { cuisines: number; features: number; description: number } }
export type ProfilePatch = Partial<Pick<Restaurant, 'name' | 'description' | 'cuisines' | 'features' | 'prepTimeMin'>> & { contact?: Partial<LocationProfile['contact']>; logo?: string | null; coverImage?: string | null; gallery?: string[] }

/** Special / holiday hours or a temporary closure for one restaurant-local date (never hardcoded per country). */
export type SpecialHours = { id: string; date: string; label: string; closed: boolean; periods: Array<{ open: string; close: string }> }
export type HoursValidationIssue = { day: number | null; index: number | null; code: 'invalid_time' | 'overlap' | 'zero_length' | 'too_many' }

export type StaffStatus = 'active' | 'invited' | 'suspended'
export type StaffMember = { id: string; name: string; email: string; role: RoleId; locationAccess: 'all' | string[]; status: StaffStatus; avatar?: string | null; /** Backend only: this row is the signed-in user (nobody changes their own membership). */ isSelf?: boolean }
export type StaffInvite = { name: string; email: string; role: RoleId; locationAccess: 'all' | string[]; avatar?: string | null }

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
  /** Backend only: the options cuisines and features are chosen from. Absent = free text (development mock). */
  getTaxonomy?(): Promise<ProfileTaxonomy>
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
/** `locationId` names the location the dashboard is on: the backend lists and changes the staff of ITS organization. */
export interface RestaurantStaffRepository {
  list(locationId?: string): Promise<StaffMember[]>
  invite(i: StaffInvite, locationId?: string): Promise<StaffMember>
  update(id: string, patch: Partial<Pick<StaffMember, 'role' | 'locationAccess' | 'status' | 'avatar'>>, locationId?: string): Promise<StaffMember>
  remove(id: string, locationId?: string): Promise<void>
  /** Backend only: sends a new single-use link; earlier links stop working. */
  resendInvitation?(id: string, locationId?: string): Promise<void>
  /** Backend only: the roles as the backend defines them today (what each role may do can change without a release). */
  roles?(): Promise<Role[]>
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
