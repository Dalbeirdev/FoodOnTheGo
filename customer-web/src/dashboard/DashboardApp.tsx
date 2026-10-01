import { Navigate, Route, Routes } from 'react-router-dom'
import AccountSecurityPage from '../auth/staff/AccountSecurityPage'
import { ForgotPasswordPage, ResetPasswordPage } from '../auth/staff/StaffPasswordPages'
import { StaffAuthGate } from '../auth/staff/StaffSession'
import { DashboardProvider } from './DashboardContext'
import DashboardLayout, { RequirePermission } from './DashboardLayout'
import OverviewPage from './pages/OverviewPage'
import OrdersPage from './pages/OrdersPage'
import PickupVerificationPage from './pages/PickupVerificationPage'
import MenuPage from './pages/MenuPage'
import ProfilePage from './pages/ProfilePage'
import HoursPage from './pages/HoursPage'
import PickupSettingsPage from './pages/PickupSettingsPage'
import ReviewsPage from './pages/ReviewsPage'
import StaffPage from './pages/StaffPage'
import AnalyticsPage from './pages/AnalyticsPage'
import NotificationsDashboardPage from './pages/NotificationsPage'
import SettingsPage from './pages/SettingsPage'
import HelpDashboardPage from './pages/HelpPage'

/**
 * Restaurant Dashboard route tree (Module 17). Loaded as its own chunk (Module 19): customers never download
 * dashboard code. Mounted by App.tsx at /restaurant-dashboard/*.
 */
export default function DashboardApp() {
  return (
    <Routes>
    {/* Public: no session yet. */}
    <Route path="forgot-password" element={<ForgotPasswordPage context="restaurant" />} />
    <Route path="reset-password" element={<ResetPasswordPage context="restaurant" />} />
    <Route path="/" element={<StaffAuthGate context="restaurant"><DashboardProvider><DashboardLayout /></DashboardProvider></StaffAuthGate>}>
      <Route index element={<Navigate to="overview" replace />} />
      <Route path="overview" element={<OverviewPage />} />
      <Route path="account-security" element={<AccountSecurityPage context="restaurant" />} />
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
    </Routes>
  )
}
