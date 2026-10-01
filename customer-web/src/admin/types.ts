import type { BackendSecurityRepository } from './api/ApiAdminSecurityRepository'
import type { Order } from '../order/repositories'
import type { Restaurant } from '../repositories/types'
import type { Review } from '../review/repositories'
import type { LocationProfile, OnboardingStatus } from '../dashboard/types'
import type { City, CityStatus, Market as MarketModel, MarketConfiguration, MarketFeatureKey, MarketRegion, MarketStatus, RegionStatus, RouteCorridor, RouteStatus, ServiceArea, ServiceAreaStatus } from '../market/types'

/**
 * Platform Admin Dashboard (Module 18) — domain contracts. The admin operates on the SAME domain as the customer app and
 * the restaurant dashboard (Restaurant, Location, Order, Payment, Review, Customer, Staff); everything here is the
 * platform-oversight layer. All mocks are development data; the backend (/api/admin/*) is authoritative later for RBAC,
 * audit, payments, refunds, settlements, moderation, markets and monitoring.
 */
export type AdminPermission =
  | 'restaurants.view' | 'restaurants.approve' | 'restaurants.suspend'
  | 'customers.view' | 'customers.manage'
  | 'orders.view' | 'orders.override'
  | 'payments.view' | 'refunds.view' | 'refunds.issue' | 'settlements.view'
  | 'reviews.view' | 'reviews.moderate'
  | 'promotions.view' | 'promotions.manage'
  | 'support.view' | 'support.manage'
  | 'markets.view' | 'markets.manage' | 'configuration.manage'
  | 'cities.view' | 'cities.manage' | 'service_areas.view' | 'service_areas.manage' | 'market_configuration.view' | 'market_configuration.manage' | 'market_features.manage'
  | 'notifications.manage'
  | 'admin_users.view' | 'admin_users.manage'
  | 'audit.view' | 'security.view' | 'analytics.view' | 'system.view' | 'settings.manage'
export type AdminRoleId = 'super_admin' | 'operations_admin' | 'restaurant_onboarding' | 'support_admin' | 'finance_admin' | 'moderation_admin' | 'analyst'
export type AdminRole = { id: AdminRoleId; permissions: AdminPermission[] }
export type AdminStatus = 'INVITED' | 'ACTIVE' | 'SUSPENDED' | 'DISABLED'
export type AdminUser = { id: string; name: string; email: string; role: AdminRoleId; status: AdminStatus; lastLoginAt: string | null; createdAt: string; mfaEnrolled: boolean; /** Backend only: false when the account holds no role at all. */ hasRole?: boolean; /** Backend only: country code when the role is held for one market; null = platform-wide. */ roleMarket?: string | null }
export type Environment = 'LOCAL' | 'STAGING' | 'PRODUCTION'

/* ---------------- restaurants ---------------- */
export type AdminRestaurantStatus = OnboardingStatus // DRAFT | SUBMITTED | UNDER_REVIEW | APPROVED | REJECTED | SUSPENDED
export type DocumentStatus = 'NOT_SUBMITTED' | 'SUBMITTED' | 'UNDER_REVIEW' | 'APPROVED' | 'REJECTED' | 'EXPIRED'
/** Verification documents are market-configured (country, entity type, provider, legal); this is one requirement row. */
export type VerificationDocument = { id: string; kind: string; label: string; required: boolean; status: DocumentStatus; submittedAt: string | null; expiresAt: string | null; note: string | null }
export type AdminRestaurant = {
  restaurant: Restaurant
  profile: LocationProfile
  organizationId: string; organizationName: string
  locationCount: number
  status: AdminRestaurantStatus
  ordersTotal: number
  createdAt: string
  documents: VerificationDocument[]
  internalNotes: Array<{ at: string; by: string; text: string }>
  locations: Array<{ restaurantId: string; name: string; locationName: string; status: 'ACTIVE' | 'SUSPENDED'; timezone: string; currency: string; acceptingOrders: boolean }>
}
export type RestaurantFilter = { tab: 'all' | 'pending' | 'approved' | 'rejected' | 'suspended' | 'inactive'; query?: string; market?: string; cuisine?: string; sort?: 'name' | 'created' | 'orders' | 'rating'; page?: number; pageSize?: number }
export type Page<T> = { items: T[]; total: number; page: number; pageSize: number }
export type RejectionCategory = 'incomplete_documents' | 'invalid_business' | 'duplicate' | 'policy' | 'other'

/* ---------------- customers ---------------- */
export type CustomerStatus = 'ACTIVE' | 'RESTRICTED' | 'SUSPENDED' | 'DEACTIVATED'
export type AdminCustomer = { id: string; publicRef: string; name: string; phoneVerified: boolean; phoneMasked: string; emailMasked: string | null; status: CustomerStatus; orders: number; createdAt: string; market: string; lastOrderAt: string | null; reverificationRequired: boolean }
export type CustomerFilter = { query?: string; status?: CustomerStatus | 'all'; market?: string; page?: number; pageSize?: number }

/* ---------------- orders / payments / refunds / settlements ---------------- */
export type OrderExceptionKind = 'payment_without_confirmation' | 'restaurant_no_response' | 'pickup_verification_issue' | 'refund_overdue' | 'status_mismatch'
export type OrderException = { orderNumber: string; kind: OrderExceptionKind; detectedAt: string; detail: string }
export type AdminOrderFilter = { tab: 'all' | 'active' | 'preparing' | 'ready' | 'completed' | 'cancelled' | 'rejected' | 'exception'; query?: string; market?: string; date?: 'today' | '7d' | 'all'; page?: number; pageSize?: number }
export type AdminOrder = { order: Order; market: string; exceptions: OrderException[]; supportCaseIds: string[]; adminNotes: Array<{ at: string; by: string; text: string }> }
export type PaymentState = 'CREATED' | 'PENDING' | 'AUTHORIZED' | 'CAPTURED' | 'FAILED' | 'CANCELLED' | 'REFUND_PENDING' | 'PARTIALLY_REFUNDED' | 'REFUNDED'
export type PaymentEvent = { at: string; type: string; detail: string }
export type AdminPayment = { reference: string; providerReference: string | null; orderNumber: string; restaurantId: string; restaurantName: string; customerRef: string; provider: string; methodCategory: string; amountMinor: number; currency: string; status: PaymentState; createdAt: string; events: PaymentEvent[]; reconciliation: { expectedMinor: number; providerStatus: string; platformStatus: PaymentState; mismatch: boolean } }
export type RefundStatus = 'PENDING' | 'PROCESSING' | 'PARTIAL' | 'COMPLETED' | 'FAILED'
export type AdminRefund = { reference: string; orderNumber: string; paymentReference: string; restaurantName: string; requestedMinor: number; refundedMinor: number; currency: string; reason: string; status: RefundStatus; createdAt: string; processedAt: string | null; kind: 'full' | 'partial' }
export type SettlementStatus = 'PENDING' | 'PROCESSING' | 'PAID' | 'FAILED' | 'ON_HOLD'
export type Settlement = { id: string; restaurantId: string; restaurantName: string; period: string; grossMinor: number; refundsMinor: number; feesMinor: number; adjustmentsMinor: number; netMinor: number; currency: string; status: SettlementStatus; orders: number }

/* ---------------- reviews ---------------- */
export type ReviewModerationFilter = { tab: 'all' | 'pending' | 'flagged' | 'published' | 'hidden' | 'rejected'; query?: string; page?: number; pageSize?: number }
export type AdminReview = { review: Review; restaurantName: string; reports: number; reportReasons: string[]; moderationHistory: Array<{ at: string; by: string; action: string; reason: string | null }> }
export type ModerationAction = 'publish' | 'hide' | 'reject' | 'restore' | 'hide_response'

/* ---------------- promotions ---------------- */
export type PromotionType = 'fixed' | 'percentage'
export type PromotionScope = 'platform' | 'market' | 'restaurant'
export type PromotionStatus = 'DRAFT' | 'SCHEDULED' | 'ACTIVE' | 'PAUSED' | 'EXPIRED' | 'DISABLED'
export type Promotion = { id: string; code: string; name: string; description: string; type: PromotionType; value: number; currency: string | null; startsAt: string; endsAt: string; minimumSpendMinor: number | null; maximumDiscountMinor: number | null; usageLimit: number | null; perCustomerLimit: number | null; scope: PromotionScope; marketCode: string | null; restaurantId: string | null; status: PromotionStatus; usedCount: number; createdAt: string }
export type PromotionIssue = { field: string; code: 'required' | 'invalid' | 'range' | 'dates' | 'currency' }

/* ---------------- support ---------------- */
export type SupportStatus = 'OPEN' | 'IN_PROGRESS' | 'WAITING_CUSTOMER' | 'WAITING_RESTAURANT' | 'RESOLVED' | 'CLOSED'
export type SupportPriority = 'LOW' | 'MEDIUM' | 'HIGH' | 'URGENT'
export type SupportSource = 'customer' | 'restaurant' | 'system'
export type SupportMessage = { id: string; at: string; author: string; authorType: SupportSource | 'admin'; text: string; internal: boolean }
export type SupportCase = { id: string; ticketNumber: string; type: string; source: SupportSource; requester: string; orderNumber: string | null; restaurantId: string | null; customerRef: string | null; priority: SupportPriority; status: SupportStatus; assignedTo: string | null; createdAt: string; updatedAt: string; summary: string; messages: SupportMessage[]; market: string }
export type SupportFilter = { tab: 'open' | 'in_progress' | 'waiting' | 'resolved' | 'all'; query?: string; priority?: SupportPriority | 'all'; page?: number; pageSize?: number }

/* ---------------- notifications / markets / configuration ---------------- */
export type AdminNotification = { id: string; severity: 'info' | 'warning' | 'critical'; type: 'restaurant_approval' | 'payment_anomaly' | 'refund_failure' | 'flagged_review' | 'support_escalation' | 'system_degradation' | 'config_change'; title: string; body: string; at: string; read: boolean; link: string | null }
export type NotificationTemplate = { id: string; key: string; channel: 'email' | 'sms' | 'push' | 'in_app'; audience: 'customer' | 'restaurant' | 'admin'; locales: string[]; status: 'ACTIVE' | 'DRAFT' }
export type Announcement = { id: string; title: string; audience: 'customers' | 'restaurants' | 'admins'; markets: string[]; channel: NotificationTemplate['channel']; status: 'DRAFT' | 'SCHEDULED' | 'SENT'; scheduledAt: string | null; createdAt: string }
export type Market = { code: string; name: string; countryCode: string; defaultLocale: string; languages: string[]; currency: string; timezoneDefault: string; unitSystem: 'metric' | 'imperial'; paymentProviders: string[]; taxConfigRef: string | null; status: 'ACTIVE' | 'PILOT' | 'INACTIVE'; restaurants: number }
export type TaxConfig = { id: string; marketCode: string; name: string; kind: string; ratePercent: number | null; appliesTo: string; effectiveFrom: string; status: 'ACTIVE' | 'DRAFT' }
export type FeeConfig = { id: string; marketCode: string | 'ALL'; name: string; kind: 'platform_fee' | 'restaurant_commission' | 'other'; model: 'percentage' | 'fixed'; value: number; currency: string | null; appliesTo: string; status: 'ACTIVE' | 'DRAFT' }
export type FeatureFlag = { key: string; description: string; enabled: boolean; scope: 'platform' | 'market'; markets: string[]; backendAuthoritative: true }
export type ConfigItem = { key: string; category: 'ordering' | 'pickup' | 'payments' | 'fees' | 'markets' | 'notifications' | 'restaurants'; value: string | number | boolean; description: string; editable: boolean }

/* ---------------- audit / security / system / analytics ---------------- */
export type AuditResult = 'SUCCESS' | 'DENIED' | 'FAILED'
export type AuditEvent = { id: string; at: string; actor: string; actorRole: string; action: string; targetType: string; targetRef: string; description: string; result: AuditResult; ip: string | null; before: Record<string, unknown> | null; after: Record<string, unknown> | null; reason: string | null; /** Backend events: the request that made the change, for log correlation. */ requestId?: string | null }
export type AuditFilter = { query?: string; action?: string | 'all'; targetType?: string | 'all'; result?: AuditResult | 'all'; from?: string; to?: string; page?: number; pageSize?: number }
export type SecurityEvent = { id: string; at: string; kind: 'auth_failure' | 'authz_denied' | 'rate_limit' | 'permission_change' | 'sensitive_action' | 'lockout'; severity: 'low' | 'medium' | 'high'; actor: string | null; detail: string; ip: string | null }
export type SecuritySummary = { failedAdminLogins24h: number; suspiciousActivity: number; lockedAccounts: number; highRiskActions24h: number; recentPermissionChanges: number; alerts: SecurityEvent[]; events: SecurityEvent[]; mfaCoverage: { enrolled: number; total: number } }
export type HealthState = 'OPERATIONAL' | 'DEGRADED' | 'OUTAGE' | 'UNKNOWN'
export type ServiceHealth = { id: string; name: string; kind: 'internal' | 'provider'; state: HealthState; latencyMs: number | null; note: string | null; checkedAt: string }
export type QueueStatus = { name: string; pending: number; failed: number; oldestJobAgeSec: number | null; workers: number }
export type WebhookStatus = { provider: string; event: string; received: number; processed: number; failed: number; retrying: number; lastAt: string | null }
export type SystemStatus = { services: ServiceHealth[]; queues: QueueStatus[]; webhooks: WebhookStatus[]; maps: { requests24h: number; failures24h: number; providerState: HealthState } }
export type AnalyticsRange = 'today' | '7d' | '30d' | 'quarter' | 'custom'
export type AnalyticsQuery = { range: AnalyticsRange; market?: string | 'all'; from?: string; to?: string }
export type SeriesPoint = { label: string; value: number }
export type CurrencyTotal = { currency: string; gmvMinor: number; revenueMinor: number; orders: number; refundsMinor: number }
export type PlatformAnalytics = { orders: number; ordersDelta: number; activeRestaurants: number; activeCustomers: number; refundRate: number; averagePrepMinutes: number; averageRating: number | null; conversionRate: number; byCurrency: CurrencyTotal[]; ordersByDay: SeriesPoint[]; ordersByMarket: SeriesPoint[]; pickupOnTimeRate: number; ratingDistribution: Array<{ rating: number; count: number }>; topRestaurants: Array<{ name: string; orders: number; currency: string; gmvMinor: number; rating: number }>; source: 'mock' }
export type OverviewSnapshot = { restaurants: number; activeRestaurants: number; pendingApprovals: number; customers: number; ordersToday: number; ordersInProgress: number; gmvByCurrency: CurrencyTotal[]; refundsPending: number; failedPayments24h: number; openSupport: number; platformRating: number | null; health: ServiceHealth[]; activity: Array<{ id: string; at: string; kind: string; title: string; detail: string; link: string | null }>; ordersByHour: SeriesPoint[]; pendingRestaurants: AdminRestaurant[]; topRestaurants: PlatformAnalytics['topRestaurants'] }
export type SearchHit = { kind: 'restaurant' | 'order' | 'customer' | 'payment' | 'support'; ref: string; title: string; subtitle: string; link: string }

/* ---------------- market control center (Module 18A) ---------------- */
export type MarketRestaurantPin = { id: string; name: string; lat: number; lng: number; cityId: string | null; serviceAreaId: string | null; status: AdminRestaurantStatus; customerVisible: boolean }
export type MarketStats = { activeCities: number; pilotCities: number; serviceAreas: number; activeServiceAreas: number; restaurants: number; visibleRestaurants: number; ordersToday: number; orders: number; customers: number; gmvMinor: number; currency: string; pendingApprovals: number; activeRoutes: number; byCity: Record<string, { restaurants: number; serviceAreas: number; orders: number }>; byArea: Record<string, { restaurants: number }>; byRegion: Record<string, { cities: number; activeCities: number; restaurants: number; serviceAreas: number }>; byRoute: Record<string, { restaurants: number }> }
export type MarketAttention = { id: string; severity: 'info' | 'warning'; text: string; link: string }
export type MarketSnapshot = { market: MarketModel; configuration: MarketConfiguration | null; states: MarketRegion[]; cities: City[]; serviceAreas: ServiceArea[]; routes: RouteCorridor[]; restaurants: MarketRestaurantPin[]; stats: MarketStats; attention: MarketAttention[] }
export type MarketsOverview = { markets: MarketModel[]; active: MarketSnapshot; activeMarkets: number; futureMarkets: number }
/** GeoJSON (RFC 7946): positions are [longitude, latitude]. */
export type GeoJsonArea = { type: 'Polygon'; coordinates: number[][][] } | { type: 'MultiPolygon'; coordinates: number[][][][] }
export type CityInput = { regionId: string; name: string; lat: number; lng: number; timezone: string; aliases: string[]; launchStage: string | null }
/** geometry = null on an edit keeps the stored boundary. */
export type ServiceAreaInput = { cityId: string; name: string; priority: number; launchStage: string | null; geometry: GeoJsonArea | null }
export type RegionInput = { code: string; name: string; type: 'STATE' | 'UNION_TERRITORY' | 'PROVINCE' | 'REGION' }
/** GeoJSON LineString, positions are [longitude, latitude]. centreline = null on an edit keeps the stored one. */
export type GeoJsonLine = { type: 'LineString'; coordinates: number[][] }
export type RouteInput = { name: string; highway: string | null; originCityId: string; destinationCityId: string; viaCityIds: string[]; corridorWidthM: number; centreline: GeoJsonLine | null }
export type PaymentMethodStatus = 'PLANNED' | 'ENABLED' | 'NOT_APPROVED'
export type MarketConfigurationInput = { paymentMethods: Record<string, PaymentMethodStatus>; taxRegime: string; taxStatus: string; postalCodeLabel: string; postalCodePattern: string; adminAreaLabel: string }
export interface AdminMarketControlRepository {
  createRegion(marketCode: string, input: RegionInput): Promise<void>
  updateRegion(id: string, input: RegionInput, reason: string): Promise<void>
  createRoute(marketCode: string, input: RouteInput): Promise<void>
  /** The cities of a corridor are fixed once it exists; name, highway, width and centreline can change. */
  updateRoute(id: string, input: RouteInput, reason: string): Promise<void>
  /** Always needs a reason. Categories the form does not show (provider strategy, legal, ordering) are left as stored. */
  updateConfiguration(marketCode: string, input: MarketConfigurationInput, reason: string): Promise<void>
  /** Creating and editing geography exists only against the backend; the fixture repository refuses. New records start PLANNED. */
  createCity(marketCode: string, input: CityInput): Promise<void>
  updateCity(id: string, input: CityInput, reason: string): Promise<void>
  createServiceArea(marketCode: string, input: ServiceAreaInput): Promise<void>
  updateServiceArea(id: string, input: ServiceAreaInput, reason: string): Promise<void>
  overview(): Promise<MarketsOverview>
  snapshot(slug: string): Promise<MarketSnapshot | null>
  setMarketStatus(code: string, status: MarketStatus, actor: string, reason: string): Promise<void>
  setStateStatus(id: string, status: RegionStatus, actor: string, reason: string): Promise<void>
  setCityStatus(id: string, status: CityStatus, actor: string, reason: string): Promise<void>
  setServiceAreaStatus(id: string, status: ServiceAreaStatus, actor: string, reason: string): Promise<void>
  setRouteStatus(id: string, status: RouteStatus, actor: string, reason: string): Promise<void>
  setFeature(code: string, key: MarketFeatureKey, enabled: boolean, actor: string, reason: string): Promise<void>
}

/* ---------------- repositories ---------------- */
export interface AdminOverviewRepository { snapshot(): Promise<OverviewSnapshot> }
export interface AdminRestaurantRepository {
  list(f: RestaurantFilter): Promise<Page<AdminRestaurant>>
  get(id: string): Promise<AdminRestaurant | null>
  approve(id: string, actor: string): Promise<AdminRestaurant>
  reject(id: string, actor: string, category: RejectionCategory, publicReason: string, internalNote: string): Promise<AdminRestaurant>
  requestInformation(id: string, actor: string, message: string): Promise<AdminRestaurant>
  suspend(id: string, actor: string, reason: string): Promise<AdminRestaurant>
  reactivate(id: string, actor: string): Promise<AdminRestaurant>
  setLocationStatus(id: string, locationId: string, status: 'ACTIVE' | 'SUSPENDED', actor: string, reason: string): Promise<AdminRestaurant>
  setDocumentStatus(id: string, docId: string, status: DocumentStatus, actor: string, note: string): Promise<AdminRestaurant>
  cuisines(): string[]
}
export interface AdminCustomerRepository { list(f: CustomerFilter): Promise<Page<AdminCustomer>>; get(id: string): Promise<(AdminCustomer & { recentOrders: Order[]; supportCases: SupportCase[]; reviews: Review[]; securityEvents: SecurityEvent[] }) | null>; setStatus(id: string, status: CustomerStatus, actor: string, reason: string): Promise<AdminCustomer>; requireReverification(id: string, actor: string): Promise<AdminCustomer> }
export interface AdminOrderRepository { list(f: AdminOrderFilter): Promise<Page<AdminOrder> & { counts: Record<AdminOrderFilter['tab'], number> }>; get(orderNumber: string): Promise<(AdminOrder & { payment: AdminPayment | null; refunds: AdminRefund[]; supportCases: SupportCase[] }) | null>; addNote(orderNumber: string, actor: string, text: string): Promise<AdminOrder>; exceptions(): Promise<OrderException[]> }
export interface AdminPaymentRepository { list(f: { query?: string; status?: PaymentState | 'all'; currency?: string; page?: number; pageSize?: number }): Promise<Page<AdminPayment>>; get(reference: string): Promise<AdminPayment | null> }
export interface AdminRefundRepository { list(f: { tab: 'all' | RefundStatus; query?: string; page?: number; pageSize?: number }): Promise<Page<AdminRefund>>; get(reference: string): Promise<AdminRefund | null> }
export interface AdminSettlementRepository { list(f: { status?: SettlementStatus | 'all'; currency?: string; page?: number; pageSize?: number }): Promise<Page<Settlement>>; totalsByCurrency(): Promise<Array<{ currency: string; netMinor: number; count: number }>> }
export interface AdminReviewRepository { list(f: ReviewModerationFilter): Promise<Page<AdminReview> & { counts: Record<ReviewModerationFilter['tab'], number> }>; moderate(reviewId: string, action: ModerationAction, actor: string, reason: string): Promise<AdminReview> }
export interface AdminPromotionRepository { list(f: { query?: string; status?: PromotionStatus | 'all'; page?: number; pageSize?: number }): Promise<Page<Promotion>>; save(p: Omit<Promotion, 'id' | 'usedCount' | 'createdAt'> & { id?: string }, actor: string): Promise<Promotion>; setStatus(id: string, status: PromotionStatus, actor: string, reason: string): Promise<Promotion> }
export interface AdminSupportRepository { list(f: SupportFilter): Promise<Page<SupportCase> & { counts: Record<SupportFilter['tab'], number> }>; get(id: string): Promise<SupportCase | null>; assign(id: string, assignee: string | null, actor: string): Promise<SupportCase>; setStatus(id: string, status: SupportStatus, actor: string): Promise<SupportCase>; addMessage(id: string, actor: string, text: string, internal: boolean): Promise<SupportCase>; setPriority(id: string, priority: SupportPriority, actor: string): Promise<SupportCase> }
export interface AdminNotificationRepository { alerts(): Promise<AdminNotification[]>; markRead(id: string): Promise<void>; markAllRead(): Promise<void>; templates(): Promise<NotificationTemplate[]>; announcements(): Promise<Announcement[]>; saveAnnouncement(a: Omit<Announcement, 'id' | 'createdAt'> & { id?: string }, actor: string): Promise<Announcement> }
export interface AdminMarketRepository { list(): Promise<Market[]>; save(m: Market, actor: string): Promise<Market>; taxes(): Promise<TaxConfig[]>; fees(): Promise<FeeConfig[]>; saveFee(f: FeeConfig, actor: string): Promise<FeeConfig>; saveTax(t: TaxConfig, actor: string): Promise<TaxConfig> }
export interface AdminConfigurationRepository { items(): Promise<ConfigItem[]>; setValue(key: string, value: ConfigItem['value'], actor: string, reason: string): Promise<ConfigItem>; flags(): Promise<FeatureFlag[]>; setFlag(key: string, enabled: boolean, actor: string, reason: string): Promise<FeatureFlag> }
export interface AdminUserRepository { list(): Promise<AdminUser[]>; roles(): AdminRole[]; invite(u: { name: string; email: string; role: AdminRoleId }, actor: string): Promise<AdminUser>; update(id: string, patch: Partial<Pick<AdminUser, 'role' | 'status'>>, actor: string, reason: string): Promise<AdminUser>; /** Backend only: sends a new single-use invitation link to an INVITED account. */ resendInvitation?(id: string): Promise<void> }
export interface AdminAuditRepository { list(f: AuditFilter): Promise<Page<AuditEvent>>; get(id: string): Promise<AuditEvent | null>; actions(): string[]; targetTypes(): string[] }
export interface AdminSecurityRepository { summary(): Promise<SecuritySummary> }
export interface AdminAnalyticsRepository { platform(q: AnalyticsQuery): Promise<PlatformAnalytics> }
export interface AdminSystemRepository { status(): Promise<SystemStatus> }
export interface AdminSearchService { search(q: string, permissions: Set<AdminPermission>): Promise<SearchHit[]> }
export type AdminRepositories = {
  overview: AdminOverviewRepository; restaurants: AdminRestaurantRepository; customers: AdminCustomerRepository; orders: AdminOrderRepository; payments: AdminPaymentRepository; refunds: AdminRefundRepository; settlements: AdminSettlementRepository
  reviews: AdminReviewRepository; promotions: AdminPromotionRepository; support: AdminSupportRepository; notifications: AdminNotificationRepository; markets: AdminMarketRepository; configuration: AdminConfigurationRepository
  adminUsers: AdminUserRepository; audit: AdminAuditRepository; security: AdminSecurityRepository; analytics: AdminAnalyticsRepository; system: AdminSystemRepository; search: AdminSearchService; marketControl: AdminMarketControlRepository
  /** Present when the admin runs against the backend: the audit trail the backend writes (markets and geography so far). `audit` stays the development log of the areas that are still mock. */
  backendAudit?: AdminAuditRepository
  /** Present when the admin runs against the backend: the real security events (sign-ins, codes, MFA, sessions, permission changes). */
  backendSecurity?: BackendSecurityRepository
}
