import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { adminPermissionsFromApi, adminRoleFromApi } from '../auth/staff/staffAuth'
import { useStaffSession } from '../auth/staff/StaffSession'
import { useLocale } from '../i18n/strings'
import { ADMIN_USERS, DEFAULT_ADMIN_ID } from './mock/fixtures'
import { adminPermissionsForRole, adminRepositories, currentAdminUsers, K, seedAdminFixtures } from './mock/mockAdmin'
import { fixtureScope } from '../market/fixtureScope'
import { marketRepository } from '../market/mock/mockMarket'
import type { Market } from '../market/types'
import type { AdminNotification, AdminPermission, AdminRepositories, AdminUser, Environment } from './types'

/**
 * Admin session (Module 18): the signed-in platform admin, their role permissions (UX gating only — the backend enforces
 * RBAC, MFA and audit later), the environment indicator and centralized critical alerts. Admin sign-in is a development
 * fixture switch until the admin auth module exists.
 */
export type AdminState = {
  repos: AdminRepositories
  status: 'loading' | 'ready' | 'error'
  admin: AdminUser
  admins: AdminUser[]
  permissions: Set<AdminPermission>
  can: (p: AdminPermission) => boolean
  switchAdmin: (id: string) => void
  reload: () => Promise<void>
  environment: Environment
  mockData: boolean
  alerts: AdminNotification[]
  unreadCount: number
  criticalCount: number
  markRead: (id: string) => Promise<void>
  markAllRead: () => Promise<void>
  refreshAlerts: () => Promise<void>
  locale: string
  /** Market context (Module 18A): an ISO country code or 'all'. Always visible in the header; operational lists are scoped to it. */
  market: string
  marketModel: Market | null
  markets: Market[]
  setMarket: (code: string) => void
}
const Ctx = createContext<AdminState | null>(null)
const MARKET_KEY = 'fotg.adm.market'
const read = (k: string) => { try { return localStorage.getItem(k) } catch { return null } }
const write = (k: string, v: string) => { try { localStorage.setItem(k, v) } catch { /* ignore */ } }
/** The environment comes from build configuration, never from a hardcoded string; local development shows LOCAL / MOCK DATA. */
export const detectEnvironment = (): Environment => { const e = (import.meta.env.VITE_ENVIRONMENT as string | undefined)?.toUpperCase(); return e === 'PRODUCTION' || e === 'STAGING' ? e : 'LOCAL' }

export function AdminProvider({ children, repos = adminRepositories }: { children: ReactNode; repos?: AdminRepositories }) {
  const { locale } = useLocale()
  const [status, setStatus] = useState<AdminState['status']>('loading')
  const [admins, setAdmins] = useState<AdminUser[]>(() => ADMIN_USERS(new Date()))
  const [adminId, setAdminId] = useState(() => read(K.session) ?? DEFAULT_ADMIN_ID)
  const [alerts, setAlerts] = useState<AdminNotification[]>([])
  const [markets, setMarkets] = useState<Market[]>(() => marketRepository.getMarkets())
  // Default context = the active market (India launch). 'all' is prepared for future multi-market operation.
  const [market, setMarketState] = useState<string>(() => { const saved = read(MARKET_KEY); return saved && (saved === 'all' || marketRepository.getMarketByCode(saved)) ? saved : fixtureScope() === 'global' ? 'all' : marketRepository.getActiveMarket().countryCode })
  const setMarket = useCallback((code: string) => { write(MARKET_KEY, code); setMarketState(code) }, [])
  // Signed in against the backend (Module 21): identity, role and permissions come from the session. They gate what is
  // shown; the backend decides every protected action. Without a backend session (tests, share builds) the fixture switch applies.
  const session = useStaffSession()
  const admin = useMemo<AdminUser>(() => (session.mode === 'api'
    ? { id: session.principal.id, name: session.principal.name, email: session.principal.email, role: adminRoleFromApi(session.principal.roles), status: 'ACTIVE', lastLoginAt: session.principal.lastLoginAt, createdAt: session.principal.lastLoginAt ?? new Date(0).toISOString(), mfaEnrolled: session.principal.mfaEnabled }
    : admins.find((a) => a.id === adminId) ?? admins[0]), [session, admins, adminId])
  const permissions = useMemo(() => new Set(session.mode === 'api' ? adminPermissionsFromApi(session.principal.permissions) : admin.status === 'ACTIVE' ? adminPermissionsForRole(admin.role) : []), [session, admin])
  const refreshAlerts = useCallback(async () => { try { setAlerts(await repos.notifications.alerts()) } catch { /* keep previous */ } }, [repos])
  const reload = useCallback(async () => {
    setStatus('loading')
    try { seedAdminFixtures(); setAdmins(currentAdminUsers()); setMarkets(marketRepository.getMarkets()); await repos.overview.snapshot(); await refreshAlerts(); setStatus('ready') } catch { setStatus('error') }
  }, [repos, refreshAlerts])
  useEffect(() => { void reload() }, [reload])
  const switchAdmin = useCallback((id: string) => { if (session.mode === 'api') return; write(K.session, id); setAdminId(id) }, [session.mode])
  const markRead = useCallback(async (id: string) => { await repos.notifications.markRead(id); await refreshAlerts() }, [repos, refreshAlerts])
  const markAllRead = useCallback(async () => { await repos.notifications.markAllRead(); await refreshAlerts() }, [repos, refreshAlerts])
  const value = useMemo<AdminState>(() => ({ repos, status, admin, admins, permissions, can: (p) => permissions.has(p), switchAdmin, reload, environment: detectEnvironment(), mockData: true, alerts, unreadCount: alerts.filter((a) => !a.read).length, criticalCount: alerts.filter((a) => !a.read && a.severity === 'critical').length, markRead, markAllRead, refreshAlerts, locale, market, marketModel: market === 'all' ? null : markets.find((m) => m.countryCode === market) ?? null, markets, setMarket }), [market, markets, setMarket, repos, status, admin, admins, permissions, switchAdmin, reload, alerts, markRead, markAllRead, refreshAlerts, locale])
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>
}
export function useAdmin(): AdminState { const v = useContext(Ctx); if (!v) throw new Error('useAdmin outside AdminProvider'); return v }
