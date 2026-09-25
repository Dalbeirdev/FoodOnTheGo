import { render } from '@testing-library/react'
import type { ReactElement, ReactNode } from 'react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { AccountProvider } from '../account/AccountContext'
import { AuthProvider } from '../auth/AuthContext'
import { CartProvider } from '../cart/CartContext'
import { OrdersProvider } from '../orders/OrdersContext'
import { ProfileProvider } from '../profile/ProfileContext'

/** Same provider stack as App.tsx, but with a memory router so tests can start on any route. */
export function Providers({ children, route = '/' }: { children: ReactNode; route?: string }) {
  return (
    <MemoryRouter initialEntries={[route]}>
      <AuthProvider>
      <ProfileProvider>
        <AccountProvider>
          <CartProvider>
            <OrdersProvider>{children}</OrdersProvider>
          </CartProvider>
        </AccountProvider>
      </ProfileProvider>
      </AuthProvider>
    </MemoryRouter>
  )
}

export function renderPage(element: ReactElement, { route = '/', path = '*' } = {}) {
  return render(
    <Providers route={route}>
      <Routes>
        <Route path={path} element={element} />
      </Routes>
    </Providers>,
  )
}
