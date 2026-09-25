import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { MockAuthRepository } from './mock/MockAuthRepository'
import { AuthError, type AuthRepository, type AuthUser, type OtpRequest, type VerifyResult } from './repository'

export type AuthStatus = 'loggedOut' | 'authenticating' | 'authenticated' | 'sessionExpired'

/** Where to go after a successful sign-in (set by RequireAuth or by the login page). */
export type PendingLogin = { phone: string; returnTo: string; otp?: OtpRequest; setupToken?: string }

export type AuthApi = {
  status: AuthStatus
  user: AuthUser | null
  loading: boolean
  isAuthenticated: boolean
  pending: PendingLogin | null
  requestOtp: (phone: string, returnTo?: string) => Promise<OtpRequest>
  resendOtp: () => Promise<OtpRequest>
  verifyOtp: (code: string) => Promise<VerifyResult>
  completeSetup: (input: { name: string; email?: string; acceptTerms: boolean }) => Promise<AuthUser>
  changePhone: () => void
  logout: () => Promise<void>
  refresh: () => Promise<void>
  /** Marks the session as expired (used when a repository call reports session_expired). */
  expire: () => void
  /** One-shot UI notice (e.g. after logout) consumed by the login page. */
  notice: 'logged-out' | null
  clearNotice: () => void
  updateProfile: (patch: { name?: string; email?: string | null }) => Promise<AuthUser>
  repository: AuthRepository
}

const AuthContext = createContext<AuthApi | null>(null)
const KEY_PENDING = 'fotg.auth.pending'

/** Default wiring for Module 03: the development mock. ApiAuthRepository replaces it later. */
export const defaultAuthRepository: AuthRepository = new MockAuthRepository()

export function AuthProvider({ children, repository = defaultAuthRepository }: { children: ReactNode; repository?: AuthRepository }) {
  const [status, setStatus] = useState<AuthStatus>('loggedOut')
  const [user, setUser] = useState<AuthUser | null>(null)
  const [loading, setLoading] = useState(true)
  const [notice, setNotice] = useState<'logged-out' | null>(null)
  const [pending, setPendingState] = useState<PendingLogin | null>(() => { try { const v = sessionStorage.getItem(KEY_PENDING); return v ? (JSON.parse(v) as PendingLogin) : null } catch { return null } })
  const repo = repository

  const setPending = useCallback((p: PendingLogin | null) => {
    setPendingState(p)
    try { p ? sessionStorage.setItem(KEY_PENDING, JSON.stringify(p)) : sessionStorage.removeItem(KEY_PENDING) } catch { /* unavailable */ }
  }, [])

  const refresh = useCallback(async () => {
    try {
      const u = await repo.refreshSession()
      setUser(u); setStatus(u ? 'authenticated' : 'loggedOut')
    } catch (e) {
      setUser(null); setStatus(e instanceof AuthError && e.code === 'session_expired' ? 'sessionExpired' : 'loggedOut')
    } finally {
      setLoading(false)
    }
  }, [repo])

  useEffect(() => { const t = setTimeout(() => { void refresh() }, 0); return () => clearTimeout(t) }, [refresh])

  const value = useMemo<AuthApi>(() => ({
    status, user, loading, pending, repository: repo,
    isAuthenticated: status === 'authenticated' && user !== null,
    requestOtp: async (phone, returnTo) => {
      setStatus('authenticating')
      try {
        const otp = await repo.requestOtp(phone)
        setPending({ phone, returnTo: returnTo ?? pending?.returnTo ?? '/my-profile', otp })
        return otp
      } catch (e) { setStatus('loggedOut'); throw e }
    },
    resendOtp: async () => {
      if (!pending) throw new AuthError('unexpected', 'Start again by entering your mobile number.')
      const otp = await repo.requestOtp(pending.phone)
      setPending({ ...pending, otp })
      return otp
    },
    verifyOtp: async (code) => {
      if (!pending) throw new AuthError('expired_otp', 'Start again by entering your mobile number.')
      const result = await repo.verifyOtp(pending.phone, code)
      if (result.status === 'authenticated') { setUser(result.user); setStatus('authenticated'); setPending({ ...pending, otp: undefined }) }
      else setPending({ ...pending, otp: undefined, setupToken: result.setupToken })
      return result
    },
    completeSetup: async (input) => {
      if (!pending?.setupToken) throw new AuthError('expired_otp', 'Your verification has expired. Please sign in again.')
      const u = await repo.completeSetup(pending.setupToken, input)
      setUser(u); setStatus('authenticated'); setPending({ ...pending, setupToken: undefined })
      return u
    },
    changePhone: () => { setStatus('loggedOut'); setPending(pending ? { returnTo: pending.returnTo, phone: '' } : null) },
    logout: async () => { setNotice('logged-out'); setUser(null); setStatus('loggedOut'); setPending(null); await repo.logout() },
    notice,
    clearNotice: () => setNotice(null),
    refresh,
    expire: () => { setUser(null); setStatus('sessionExpired') },
    updateProfile: async (patch) => {
      // Profile identity edits stay in the mock directory until the backend module.
      if (!user) throw new AuthError('session_expired', 'Please sign in again.')
      const next = { ...user, ...(patch.name !== undefined && { name: patch.name }), ...(patch.email !== undefined && { email: patch.email }) }
      try { const list = JSON.parse(localStorage.getItem('fotg.mock.customers') ?? '[]') as AuthUser[]; localStorage.setItem('fotg.mock.customers', JSON.stringify(list.map((u) => (u.id === next.id ? next : u)))) } catch { /* unavailable */ }
      setUser(next)
      return next
    },
  }), [status, user, loading, pending, refresh, setPending, repo, notice])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider')
  return ctx
}
