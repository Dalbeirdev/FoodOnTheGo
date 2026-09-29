import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useLocale } from '../i18n/strings'
import { STAFF } from './mock/fixtures'
import { dashboardRepositories, permissionsForRole, seedDashboardFixtures } from './mock/mockDashboard'
import type { DashboardLocation, DashboardNotification, DashboardRepositories, Organization, Permission, StaffMember } from './types'

/**
 * Dashboard session (Module 17): organization, locations, the explicit current location, the signed-in staff member and
 * their permissions, plus centralized notification state (header + sidebar badges read the same value).
 * Staff sign-in is a development fixture switch until the restaurant auth module exists; permissions gate the UI only —
 * the backend enforces RBAC later (hiding a button is not authorization).
 */
export type DashboardState = {
  repos: DashboardRepositories
  status: 'loading' | 'ready' | 'error'
  organization: Organization | null
  locations: DashboardLocation[]
  location: DashboardLocation | null
  /** Locations the current staff member may open. */
  accessibleLocations: DashboardLocation[]
  staff: StaffMember
  staffList: StaffMember[]
  permissions: Set<Permission>
  can: (p: Permission) => boolean
  selectLocation: (id: string) => void
  switchStaff: (id: string) => void
  reload: () => Promise<void>
  refreshLocation: () => Promise<void>
  setAcceptingOrders: (v: boolean) => Promise<void>
  notifications: DashboardNotification[]
  unreadCount: number
  markRead: (id: string) => Promise<void>
  markAllRead: () => Promise<void>
  refreshNotifications: () => Promise<void>
  locale: string
}
const Ctx = createContext<DashboardState | null>(null)
const LOC_KEY = 'fotg.rd.location', STAFF_KEY = 'fotg.rd.staff'
const read = (k: string) => { try { return localStorage.getItem(k) } catch { return null } }
const write = (k: string, v: string) => { try { localStorage.setItem(k, v) } catch { /* ignore */ } }

export function DashboardProvider({ children, repos = dashboardRepositories }: { children: ReactNode; repos?: DashboardRepositories }) {
  const { locale } = useLocale()
  const [status, setStatus] = useState<DashboardState['status']>('loading')
  const [organization, setOrganization] = useState<Organization | null>(null)
  const [locations, setLocations] = useState<DashboardLocation[]>([])
  const [staffList, setStaffList] = useState<StaffMember[]>(STAFF)
  const [staffId, setStaffId] = useState(() => read(STAFF_KEY) ?? STAFF[0].id)
  const [locationId, setLocationId] = useState<string | null>(() => read(LOC_KEY))
  const [notifications, setNotifications] = useState<DashboardNotification[]>([])
  const staff = useMemo(() => staffList.find((s) => s.id === staffId) ?? staffList[0] ?? STAFF[0], [staffList, staffId])
  const permissions = useMemo(() => new Set(permissionsForRole(staff.role)), [staff])
  const accessibleLocations = useMemo(() => locations.filter((l) => staff.locationAccess === 'all' || staff.locationAccess.includes(l.restaurant.id)), [locations, staff])
  const location = useMemo(() => accessibleLocations.find((l) => l.restaurant.id === locationId) ?? accessibleLocations[0] ?? null, [accessibleLocations, locationId])

  const reload = useCallback(async () => {
    setStatus('loading')
    try {
      seedDashboardFixtures()
      const [org, locs, staffs] = await Promise.all([repos.management.getOrganization(), repos.management.getLocations(), repos.staff.list()])
      setOrganization(org); setLocations(locs); setStaffList(staffs.length ? staffs : STAFF); setStatus('ready')
    } catch { setStatus('error') }
  }, [repos])
  useEffect(() => { void reload() }, [reload])
  const refreshLocation = useCallback(async () => { try { setLocations(await repos.management.getLocations()) } catch { /* keep current */ } }, [repos])
  const refreshNotifications = useCallback(async () => { try { setNotifications(await repos.notifications.list(location?.restaurant.id ?? null)) } catch { /* ignore */ } }, [repos, location?.restaurant.id])
  useEffect(() => { if (status === 'ready') void refreshNotifications() }, [status, refreshNotifications])

  const value: DashboardState = {
    repos, status, organization, locations, location, accessibleLocations, staff, staffList, permissions, locale,
    can: (p) => permissions.has(p),
    selectLocation: (id) => { setLocationId(id); write(LOC_KEY, id) },
    switchStaff: (id) => { setStaffId(id); write(STAFF_KEY, id) },
    reload, refreshLocation,
    setAcceptingOrders: async (v) => { if (!location) return; await repos.management.setAcceptingOrders(location.restaurant.id, v); await refreshLocation() },
    notifications, unreadCount: notifications.filter((n) => !n.read).length,
    markRead: async (id) => { await repos.notifications.markRead(id); await refreshNotifications() },
    markAllRead: async () => { await repos.notifications.markAllRead(location?.restaurant.id ?? null); await refreshNotifications() },
    refreshNotifications,
  }
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>
}
export function useDashboard(): DashboardState { const v = useContext(Ctx); if (!v) throw new Error('useDashboard outside DashboardProvider'); return v }
