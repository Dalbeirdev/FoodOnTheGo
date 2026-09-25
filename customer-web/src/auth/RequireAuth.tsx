import type { ReactElement } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { useAuth } from './AuthContext'

/**
 * Reusable protected-route gate. Guests are sent to /login with the original URL so they
 * return to it after OTP verification; an expired session shows the expiry message there.
 */
export default function RequireAuth({ children }: { children: ReactElement }) {
  const { isAuthenticated, loading, status } = useAuth()
  const location = useLocation()
  if (loading) return <main id="main" className="auth-loading" aria-busy="true"><p>Checking your session…</p></main>
  if (!isAuthenticated) {
    const to = status === 'sessionExpired' ? '/login?reason=expired' : '/login'
    return <Navigate to={to} replace state={{ from: location.pathname + location.search }} />
  }
  return children
}
