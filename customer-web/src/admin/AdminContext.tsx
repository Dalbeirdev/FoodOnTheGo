import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useLocale } from '../i18n/strings'
import { ADMIN_USERS, DEFAULT_ADMIN_ID } from './mock/fixtures'
import { adminPermissionsForRole, adminRepositories, currentAdminUsers, K, seedAdminFixtures } from './mock/mockAdmin'
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
}
const Ctx = createContext<AdminState | null>(null)
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
  const admin = useMemo(() => admins.find((a) => a.id === adminId) ?? admins[0], [admins, adminId])
  const permissions = useMemo(() => new Set(admin.status === 'ACTIVE' ? adminPermissionsForRole(admin.role) : []), [admin])
  const refreshAlerts = useCallback(async () => { try { setAlerts(await repos.notifications.alerts()) } catch { /* keep previous */ } }, [repos])
  const reload = useCallback(async () => {
    setStatus('loading')
    try { seedAdminFixtures(); setAdmins(currentAdminUsers()); await repos.overview.snapshot(); await refreshAlerts(); setStatus('ready') } catch { setStatus('error') }
  }, [repos, refreshAlerts])
  useEffect(() => { void reload() }, [reload])
  const switchAdmin = useCallback((id: string) => { write(K.session, id); setAdminId(id) }, [])
  const markRead = useCallback(async (id: string) => { await repos.notifications.markRead(id); await refreshAlerts() }, [repos, refreshAlerts])
  const markAllRead = useCallback(async () => { await repos.notifications.markAllRead(); await refreshAlerts() }, [repos, refreshAlerts])
  const value = useMemo<AdminState>(() => ({ repos, status, admin, admins, permissions, can: (p) => permissions.has(p), switchAdmin, reload, environment: detectEnvironment(), mockData: true, alerts, unreadCount: alerts.filter((a) => !a.read).length, criticalCount: alerts.filter((a) => !a.read && a.severity === 'critical').length, markRead, markAllRead, refreshAlerts, locale }), [repos, status, admin, admins, permissions, switchAdmin, reload, alerts, markRead, markAllRead, refreshAlerts, locale])
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>
}
export function useAdmin(): AdminState { const v = useContext(Ctx); if (!v) throw new Error('useAdmin outside AdminProvider'); return v }
