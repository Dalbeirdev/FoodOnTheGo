import { BrowserRouter, HashRouter, Navigate, Route, Routes, useLocation, useParams } from 'react-router-dom'
import { CartProvider } from './cart/CartContext'
import { PickupProvider } from './pickup/PickupContext'
import { CheckoutProvider } from './checkout/CheckoutContext'
import { PaymentProviderContext } from './payment/PaymentContext'
import PaymentPage from './pages/PaymentPage'
import { OrdersProvider } from './orders/OrdersContext'
import { ProfileProvider } from './profile/ProfileContext'
import { AuthProvider } from './auth/AuthContext'
import RequireAuth from './auth/RequireAuth'
import { AccountProvider } from './account/AccountContext'
import { JourneyProvider } from './journey/JourneyContext'
import { LocaleProvider } from './i18n/LocaleProvider'
import Footer from './components/Footer'
import { ToastProvider } from './components/Toast'
import HomePage from './pages/HomePage'
import HowItWorksPage from './pages/HowItWorksPage'
import AboutPage from './pages/AboutPage'
import RestaurantsPage from './pages/RestaurantsPage'
import RestaurantDetailPage from './pages/RestaurantDetailPage'
import ItemDetailPage from './pages/ItemDetailPage'
import CartPage from './pages/CartPage'
import PickupTimePage from './pages/PickupTimePage'
import CheckoutPage from './pages/CheckoutPage'
import OrderConfirmationPage from './pages/OrderConfirmationPage'
import OrderTrackingPage from './pages/OrderTrackingPage'
import MyOrdersPage from './pages/MyOrdersPage'
import OrderDetailsPage from './pages/OrderDetailsPage'
import ReviewPage from './pages/ReviewPage'
import MyProfilePage from './pages/MyProfilePage'
import FavoritesPage from './pages/account/FavoritesPage'
import AddressesPage from './pages/account/AddressesPage'
import PaymentMethodsPage from './pages/account/PaymentMethodsPage'
import NotificationsPage from './pages/account/NotificationsPage'
import HelpPage from './pages/account/HelpPage'
import ForRestaurantsPage from './pages/ForRestaurantsPage'
import LoginPage from './pages/auth/LoginPage'
import VerifyOtpPage from './pages/auth/VerifyOtpPage'
import AccountSetupPage from './pages/auth/AccountSetupPage'
import PlanJourneyPage from './pages/PlanJourneyPage'
import LegalPage from './pages/LegalPage'
import NotFoundPage from './pages/NotFoundPage'
import GetAppPage from './pages/GetAppPage'
import ComingSoonPage from './pages/ComingSoonPage'
import { DashboardProvider } from './dashboard/DashboardContext'
import DashboardLayout, { RequirePermission } from './dashboard/DashboardLayout'
import OverviewPage from './dashboard/pages/OverviewPage'
import OrdersPage from './dashboard/pages/OrdersPage'
import PickupVerificationPage from './dashboard/pages/PickupVerificationPage'
import MenuPage from './dashboard/pages/MenuPage'
import ProfilePage from './dashboard/pages/ProfilePage'
import HoursPage from './dashboard/pages/HoursPage'
import PickupSettingsPage from './dashboard/pages/PickupSettingsPage'
import ReviewsPage from './dashboard/pages/ReviewsPage'
import StaffPage from './dashboard/pages/StaffPage'
import AnalyticsPage from './dashboard/pages/AnalyticsPage'
import NotificationsDashboardPage from './dashboard/pages/NotificationsPage'
import SettingsPage from './dashboard/pages/SettingsPage'
import HelpDashboardPage from './dashboard/pages/HelpPage'
import { AdminProvider } from './admin/AdminContext'
import AdminLayout, { RequirePermission as RequireAdminPermission } from './admin/AdminLayout'
import AdminOverviewPage from './admin/pages/OverviewPage'
import AdminRestaurantsPage, { RestaurantDetailsPage as AdminRestaurantDetailsPage } from './admin/pages/RestaurantsPage'
import AdminCustomersPage, { CustomerDetailsPage as AdminCustomerDetailsPage } from './admin/pages/CustomersPage'
import AdminOrdersPage, { OrderDetailsPage as AdminOrderDetailsPage } from './admin/pages/OrdersPage'
import { PaymentsPage as AdminPaymentsPage, RefundsPage as AdminRefundsPage, SettlementsPage as AdminSettlementsPage } from './admin/pages/FinancePages'
import AdminReviewsPage from './admin/pages/ReviewsPage'
import AdminPromotionsPage from './admin/pages/PromotionsPage'
import AdminSupportPage, { SupportCasePage as AdminSupportCasePage } from './admin/pages/SupportPage'
import AdminNotificationsPage from './admin/pages/NotificationsPage'
import { MarketsPage as AdminMarketsPage, ConfigurationPage as AdminConfigurationPage } from './admin/pages/PlatformPages'
import { AdminUsersPage, AuditLogsPage as AdminAuditLogsPage, SecurityPage as AdminSecurityPage } from './admin/pages/SecurityPages'
import { AnalyticsPage as AdminAnalyticsPage, SystemPage as AdminSystemPage, SettingsPage as AdminSettingsPage, HelpPage as AdminHelpPage } from './admin/pages/InsightPages'

/** Static share builds (VITE_ROUTER=hash) run from a single file host where only hash routes survive a reload. */
const Router = import.meta.env.VITE_ROUTER === 'hash' ? HashRouter : BrowserRouter

/** Canonical restaurant routes are /restaurants/:slug and /restaurants/:slug/item/:itemId — old /restaurant/… links redirect. */
function LegacyItemRedirect() { const { rid, itemId } = useParams(); return <Navigate to={`/restaurants/${rid}/item/${itemId}`} replace /> }
/** The restaurant dashboard has its own shell — the public footer stays off its routes. */
function SiteFooter() { const l = useLocation(); return l.pathname.startsWith('/restaurant-dashboard') || l.pathname.startsWith('/admin') ? null : <Footer /> }
function LegacyRestaurantRedirect() { const { rid } = useParams(); return <Navigate to={`/restaurants/${rid}`} replace /> }

/** Provider stack + route table, router-agnostic so tests can mount it inside a MemoryRouter. */
export function AppShell() {
  return (
      <LocaleProvider>
      <AuthProvider>
      <ToastProvider>
      <ProfileProvider>
      <AccountProvider>
      <JourneyProvider>
      <CartProvider>
      <PickupProvider>
      <CheckoutProvider>
      <PaymentProviderContext>
        <OrdersProvider>
          <Routes>
            <Route path="/" element={<HomePage />} />
            <Route path="/how-it-works" element={<HowItWorksPage />} />
            <Route path="/about-us" element={<AboutPage />} />
            <Route path="/about" element={<Navigate to="/about-us" replace />} />
            <Route path="/restaurants" element={<RestaurantsPage />} />
            <Route path="/restaurants/:id" element={<RestaurantDetailPage />} />
            <Route path="/restaurants/:rid/item/:itemId" element={<ItemDetailPage />} />
            <Route path="/restaurant/:rid/item/:itemId" element={<LegacyItemRedirect />} />
            <Route path="/restaurant/:rid" element={<LegacyRestaurantRedirect />} />
            <Route path="/cart" element={<CartPage />} />
            <Route path="/pickup-time" element={<PickupTimePage />} />
            <Route path="/checkout" element={<RequireAuth><CheckoutPage /></RequireAuth>} />
            <Route path="/payment" element={<RequireAuth><PaymentPage /></RequireAuth>} />
            <Route path="/order-confirmation/:orderNumber" element={<RequireAuth><OrderConfirmationPage /></RequireAuth>} />
            <Route path="/order-tracking/:orderNumber" element={<RequireAuth><OrderTrackingPage /></RequireAuth>} />
            <Route path="/my-orders" element={<RequireAuth><MyOrdersPage /></RequireAuth>} />
            <Route path="/order/:orderNumber" element={<RequireAuth><OrderDetailsPage /></RequireAuth>} />
            <Route path="/order/:orderNumber/review" element={<RequireAuth><ReviewPage /></RequireAuth>} />
            <Route path="/my-profile" element={<RequireAuth><MyProfilePage /></RequireAuth>} />
            <Route path="/favorites" element={<RequireAuth><FavoritesPage /></RequireAuth>} />
            <Route path="/addresses" element={<RequireAuth><AddressesPage /></RequireAuth>} />
            <Route path="/payment-methods" element={<RequireAuth><PaymentMethodsPage /></RequireAuth>} />
            <Route path="/notifications" element={<RequireAuth><NotificationsPage /></RequireAuth>} />
            <Route path="/help" element={<HelpPage />} />
            <Route path="/for-restaurants" element={<ForRestaurantsPage />} />
            <Route path="/for-restaurants/register" element={<ComingSoonPage title="Restaurant Registration" text="Partner onboarding opens with the restaurant portal. We are not accepting registrations through the website yet." module="the Restaurant Portal module" backTo="/for-restaurants" backLabel="Back to For Restaurants" />} />
            <Route path="/get-app" element={<GetAppPage />} />
            <Route path="/login" element={<LoginPage />} />
            <Route path="/verify-otp" element={<VerifyOtpPage />} />
            <Route path="/account-setup" element={<AccountSetupPage />} />
            <Route path="/signup" element={<Navigate to="/login" replace />} />
            <Route path="/forgot-password" element={<Navigate to="/login" replace />} />
            <Route path="/reset-password" element={<Navigate to="/login" replace />} />
            <Route path="/plan-journey" element={<PlanJourneyPage />} />
            <Route path="/terms" element={<LegalPage slug="terms" />} />
            <Route path="/privacy" element={<LegalPage slug="privacy" />} />
            <Route path="/refund-policy" element={<LegalPage slug="refund-policy" />} />
            <Route path="/cookie-policy" element={<LegalPage slug="cookie-policy" />} />
            {/* Module 17 — Restaurant Dashboard (responsive web, own shell, shared domain) */}
            <Route path="/restaurant-dashboard" element={<DashboardProvider><DashboardLayout /></DashboardProvider>}>
              <Route index element={<Navigate to="overview" replace />} />
              <Route path="overview" element={<OverviewPage />} />
              <Route path="orders" element={<RequirePermission perm="orders.view"><OrdersPage /></RequirePermission>} />
              <Route path="orders/:orderNumber" element={<RequirePermission perm="orders.view"><OrdersPage /></RequirePermission>} />
              <Route path="pickup-verification" element={<RequirePermission perm="pickup.verify"><PickupVerificationPage /></RequirePermission>} />
              <Route path="menu" element={<RequirePermission perm="menu.view"><MenuPage /></RequirePermission>} />
              <Route path="profile" element={<RequirePermission perm="restaurant.profile.view"><ProfilePage /></RequirePermission>} />
              <Route path="hours" element={<RequirePermission perm="restaurant.profile.view"><HoursPage /></RequirePermission>} />
              <Route path="pickup-settings" element={<RequirePermission perm="restaurant.profile.view"><PickupSettingsPage /></RequirePermission>} />
              <Route path="reviews" element={<RequirePermission perm="reviews.view"><ReviewsPage /></RequirePermission>} />
              <Route path="staff" element={<RequirePermission perm="staff.view"><StaffPage /></RequirePermission>} />
              <Route path="analytics" element={<RequirePermission perm="analytics.view"><AnalyticsPage /></RequirePermission>} />
              <Route path="notifications" element={<RequirePermission perm="notifications.view"><NotificationsDashboardPage /></RequirePermission>} />
              <Route path="settings" element={<SettingsPage />} />
              <Route path="help" element={<HelpDashboardPage />} />
            </Route>
            {/* Module 18 — Platform Admin Dashboard (responsive web, own shell, shared domain, RBAC = UX gating only) */}
            <Route path="/admin" element={<AdminProvider><AdminLayout /></AdminProvider>}>
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
              <Route path="markets" element={<RequireAdminPermission perm="markets.view"><AdminMarketsPage /></RequireAdminPermission>} />
              <Route path="configuration" element={<RequireAdminPermission perm="configuration.manage"><AdminConfigurationPage /></RequireAdminPermission>} />
              <Route path="admin-users" element={<RequireAdminPermission perm="admin_users.view"><AdminUsersPage /></RequireAdminPermission>} />
              <Route path="audit-logs" element={<RequireAdminPermission perm="audit.view"><AdminAuditLogsPage /></RequireAdminPermission>} />
              <Route path="security" element={<RequireAdminPermission perm="security.view"><AdminSecurityPage /></RequireAdminPermission>} />
              <Route path="analytics" element={<RequireAdminPermission perm="analytics.view"><AdminAnalyticsPage /></RequireAdminPermission>} />
              <Route path="system" element={<RequireAdminPermission perm="system.view"><AdminSystemPage /></RequireAdminPermission>} />
              <Route path="settings" element={<RequireAdminPermission perm="settings.manage"><AdminSettingsPage /></RequireAdminPermission>} />
              <Route path="help" element={<AdminHelpPage />} />
            </Route>
            <Route path="*" element={<NotFoundPage />} />
          </Routes>
          <SiteFooter />
        </OrdersProvider>
      </PaymentProviderContext>
      </CheckoutProvider>
      </PickupProvider>
      </CartProvider>
      </JourneyProvider>
      </AccountProvider>
      </ProfileProvider>
      </ToastProvider>
      </AuthProvider>
      </LocaleProvider>
  )
}

export default function App() {
  return (
    <Router>
      <AppShell />
    </Router>
  )
}
