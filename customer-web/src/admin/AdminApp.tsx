import { Navigate, Route, Routes } from 'react-router-dom'
import { AdminProvider } from './AdminContext'
import AdminLayout, { RequirePermission as RequireAdminPermission } from './AdminLayout'
import AdminOverviewPage from './pages/OverviewPage'
import AdminRestaurantsPage, { RestaurantDetailsPage as AdminRestaurantDetailsPage } from './pages/RestaurantsPage'
import AdminCustomersPage, { CustomerDetailsPage as AdminCustomerDetailsPage } from './pages/CustomersPage'
import AdminOrdersPage, { OrderDetailsPage as AdminOrderDetailsPage } from './pages/OrdersPage'
import { PaymentsPage as AdminPaymentsPage, RefundsPage as AdminRefundsPage, SettlementsPage as AdminSettlementsPage } from './pages/FinancePages'
import AdminReviewsPage from './pages/ReviewsPage'
import AdminPromotionsPage from './pages/PromotionsPage'
import AdminSupportPage, { SupportCasePage as AdminSupportCasePage } from './pages/SupportPage'
import AdminNotificationsPage from './pages/NotificationsPage'
import { MarketsOverviewPage, MarketLayout, MarketOverviewTab, MarketStatesTab, MarketCitiesTab, MarketServiceAreasTab, MarketRoutesTab, MarketConfigurationTab, MarketFeaturesTab } from './pages/MarketPages'
import { MarketsPage as AdminMarketsPage, ConfigurationPage as AdminConfigurationPage } from './pages/PlatformPages'
import { AdminUsersPage, AuditLogsPage as AdminAuditLogsPage, SecurityPage as AdminSecurityPage } from './pages/SecurityPages'
import { AnalyticsPage as AdminAnalyticsPage, SystemPage as AdminSystemPage, SettingsPage as AdminSettingsPage, HelpPage as AdminHelpPage } from './pages/InsightPages'

/**
 * Platform Admin route tree (Modules 18 / 18A). Loaded as its own chunk (Module 19): customers and restaurants never
 * download admin code. Mounted by App.tsx at /admin/*.
 */
export default function AdminApp() {
  return (
    <Routes>
    <Route path="/" element={<AdminProvider><AdminLayout /></AdminProvider>}>
      <Route index element={<Navigate to="overview" replace />} />
      <Route path="overview" element={<AdminOverviewPage />} />
      <Route path="restaurants" element={<RequireAdminPermission perm="restaurants.view"><AdminRestaurantsPage /></RequireAdminPermission>} />
      <Route path="restaurants/:id" element={<RequireAdminPermission perm="restaurants.view"><AdminRestaurantDetailsPage /></RequireAdminPermission>} />
      <Route path="customers" element={<RequireAdminPermission perm="customers.view"><AdminCustomersPage /></RequireAdminPermission>} />
      <Route path="customers/:id" element={<RequireAdminPermission perm="customers.view"><AdminCustomerDetailsPage /></RequireAdminPermission>} />
      <Route path="orders" element={<RequireAdminPermission perm="orders.view"><AdminOrdersPage /></RequireAdminPermission>} />
      <Route path="orders/:orderNumber" element={<RequireAdminPermission perm="orders.view"><AdminOrderDetailsPage /></RequireAdminPermission>} />
      <Route path="payments" element={<RequireAdminPermission perm="payments.view"><AdminPaymentsPage /></RequireAdminPermission>} />
      <Route path="refunds" element={<RequireAdminPermission perm="refunds.view"><AdminRefundsPage /></RequireAdminPermission>} />
      <Route path="settlements" element={<RequireAdminPermission perm="settlements.view"><AdminSettlementsPage /></RequireAdminPermission>} />
      <Route path="reviews" element={<RequireAdminPermission perm="reviews.view"><AdminReviewsPage /></RequireAdminPermission>} />
      <Route path="promotions" element={<RequireAdminPermission perm="promotions.view"><AdminPromotionsPage /></RequireAdminPermission>} />
      <Route path="support" element={<RequireAdminPermission perm="support.view"><AdminSupportPage /></RequireAdminPermission>} />
      <Route path="support/:id" element={<RequireAdminPermission perm="support.view"><AdminSupportCasePage /></RequireAdminPermission>} />
      <Route path="notifications" element={<RequireAdminPermission perm="notifications.manage"><AdminNotificationsPage /></RequireAdminPermission>} />
      {/* Module 18A — Market Control Center (India-first launch configuration, global-ready) */}
      <Route path="markets" element={<RequireAdminPermission perm="markets.view"><MarketsOverviewPage /></RequireAdminPermission>} />
      <Route path="markets/registry" element={<RequireAdminPermission perm="markets.view"><AdminMarketsPage /></RequireAdminPermission>} />
      <Route path="markets/:slug" element={<RequireAdminPermission perm="markets.view"><MarketLayout /></RequireAdminPermission>}>
        <Route index element={<MarketOverviewTab />} />
        <Route path="states" element={<MarketStatesTab />} />
        <Route path="cities" element={<RequireAdminPermission perm="cities.view"><MarketCitiesTab /></RequireAdminPermission>} />
        <Route path="service-areas" element={<RequireAdminPermission perm="service_areas.view"><MarketServiceAreasTab /></RequireAdminPermission>} />
        <Route path="routes" element={<RequireAdminPermission perm="service_areas.view"><MarketRoutesTab /></RequireAdminPermission>} />
        <Route path="configuration" element={<MarketConfigurationTab />} />
        <Route path="features" element={<MarketFeaturesTab />} />
      </Route>
      <Route path="configuration" element={<RequireAdminPermission perm="configuration.manage"><AdminConfigurationPage /></RequireAdminPermission>} />
      <Route path="admin-users" element={<RequireAdminPermission perm="admin_users.view"><AdminUsersPage /></RequireAdminPermission>} />
      <Route path="audit-logs" element={<RequireAdminPermission perm="audit.view"><AdminAuditLogsPage /></RequireAdminPermission>} />
      <Route path="security" element={<RequireAdminPermission perm="security.view"><AdminSecurityPage /></RequireAdminPermission>} />
      <Route path="analytics" element={<RequireAdminPermission perm="analytics.view"><AdminAnalyticsPage /></RequireAdminPermission>} />
      <Route path="system" element={<RequireAdminPermission perm="system.view"><AdminSystemPage /></RequireAdminPermission>} />
      <Route path="settings" element={<RequireAdminPermission perm="settings.manage"><AdminSettingsPage /></RequireAdminPermission>} />
      <Route path="help" element={<AdminHelpPage />} />
    </Route>
    </Routes>
  )
}
