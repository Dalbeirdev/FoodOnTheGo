import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { CartProvider } from './cart/CartContext'
import { OrdersProvider } from './orders/OrdersContext'
import { ProfileProvider } from './profile/ProfileContext'
import { AuthProvider } from './auth/AuthContext'
import RequireAuth from './auth/RequireAuth'
import { AccountProvider } from './account/AccountContext'
import Footer from './components/Footer'
import HomePage from './pages/HomePage'
import HowItWorksPage from './pages/HowItWorksPage'
import AboutPage from './pages/AboutPage'
import RestaurantsPage from './pages/RestaurantsPage'
import RestaurantDetailPage from './pages/RestaurantDetailPage'
import ItemDetailPage from './pages/ItemDetailPage'
import CartPage from './pages/CartPage'
import CheckoutPage from './pages/CheckoutPage'
import OrderConfirmationPage from './pages/OrderConfirmationPage'
import OrderTrackingPage from './pages/OrderTrackingPage'
import MyOrdersPage from './pages/MyOrdersPage'
import OrderDetailsPage from './pages/OrderDetailsPage'
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

/** Provider stack + route table, router-agnostic so tests can mount it inside a MemoryRouter. */
export function AppShell() {
  return (
      <AuthProvider>
      <ProfileProvider>
      <AccountProvider>
      <CartProvider>
        <OrdersProvider>
          <Routes>
            <Route path="/" element={<HomePage />} />
            <Route path="/how-it-works" element={<HowItWorksPage />} />
            <Route path="/about-us" element={<AboutPage />} />
            <Route path="/about" element={<Navigate to="/about-us" replace />} />
            <Route path="/restaurants" element={<RestaurantsPage />} />
            <Route path="/restaurants/:id" element={<RestaurantDetailPage />} />
            <Route path="/restaurant/:rid/item/:itemId" element={<ItemDetailPage />} />
            <Route path="/cart" element={<CartPage />} />
            <Route path="/checkout" element={<RequireAuth><CheckoutPage /></RequireAuth>} />
            <Route path="/order-confirmation/:orderNumber" element={<RequireAuth><OrderConfirmationPage /></RequireAuth>} />
            <Route path="/order-tracking/:orderNumber" element={<RequireAuth><OrderTrackingPage /></RequireAuth>} />
            <Route path="/my-orders" element={<RequireAuth><MyOrdersPage /></RequireAuth>} />
            <Route path="/order/:orderNumber" element={<RequireAuth><OrderDetailsPage /></RequireAuth>} />
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
            <Route path="*" element={<NotFoundPage />} />
          </Routes>
          <Footer />
        </OrdersProvider>
      </CartProvider>
      </AccountProvider>
      </ProfileProvider>
      </AuthProvider>
  )
}

export default function App() {
  return (
    <BrowserRouter>
      <AppShell />
    </BrowserRouter>
  )
}
