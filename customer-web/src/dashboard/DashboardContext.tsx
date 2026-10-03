import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { restaurantPermissionsFromApi, restaurantRoleFromApi } from '../auth/staff/staffAuth'
import { useStaffSession } from '../auth/staff/StaffSession'
import { useLocale } from '../i18n/strings'
import { restaurantMode } from '../restaurants/restaurantMode'
import { apiDashboardRepositories, dashboardErrorMessage } from './api/apiDashboard'
import { STAFF } from './mock/fixtures'
import { dashboardRepositories, permissionsForRole, seedDashboardFixtures } from './mock/mockDashboard'
import type { DashboardLocation, DashboardNotification, DashboardRepositories, Organization, Permission, StaffMember } from './types'

/**
 * Dashboard session (Module 17): organization, locations, the explicit current location, the signed-in staff member and
 * their permissions, plus centralized notification state (header + sidebar badges read the same value).
 * Permissions gate the UI only — hiding a button is not authorization; the backend decides every action.
 *
 * With the backend (Module 23, `live`): the locations are the ones the signed-in user's memberships give access to,
 * and the role and permissions shown are the ones held AT THE CURRENT LOCATION — switching location can change
 * what the user may do. Without it, staff sign-in is a development fixture switch.
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
  /** True when organizations, locations, profile, hours, pickup settings and staff come from the backend. */
  live: boolean
  /** A refused action of the shell (e.g. the accepting-orders switch), shown as a short notice. */
  notice: string | null
}
const Ctx = createContext<DashboardState | null>(null)
const LOC_KEY = 'fotg.rd.location', STAFF_KEY = 'fotg.rd.staff'
const read = (k: string) => { try { return localStorage.getItem(k) } catch { return null } }
const write = (k: string, v: string) => { try { localStorage.setItem(k, v) } catch { /* ignore */ } }

export function DashboardProvider({ children, repos: given }: { children: ReactNode; repos?: DashboardRepositories }) {
  const { locale } = useLocale()
  const session = useStaffSession()
  const live = !given && session.mode === 'api' && restaurantMode() === 'api'
  const repos = useMemo(() => given ?? (live ? apiDashboardRepositories : dashboardRepositories), [given, live])
  const [status, setStatus] = useState<DashboardState['status']>('loading')
  const [organization, setOrganization] = useState<Organization | null>(null)
  const [locations, setLocations] = useState<DashboardLocation[]>([])
  const [staffList, setStaffList] = useState<StaffMember[]>(STAFF)
  const [staffId, setStaffId] = useState(() => read(STAFF_KEY) ?? STAFF[0].id)
  const [locationId, setLocationId] = useState<string | null>(() => read(LOC_KEY))
  const [notifications, setNotifications] = useState<DashboardNotification[]>([])
  const [notice, setNotice] = useState<string | null>(null)
  useEffect(() => { if (!notice) return; const id = setTimeout(() => setNotice(null), 4500); return () => clearTimeout(id) }, [notice])
  // Signed in against the backend (Module 21): identity comes from the session. Display only — the backend decides
  // every action.
  const fixtureStaff = useMemo<StaffMember>(() => staffList.find((s) => s.id === staffId) ?? staffList[0] ?? STAFF[0], [staffList, staffId])
  // With the backend the list already is "what this user may open" (default location first); the fixtures filter it here.
  const accessibleLocations = useMemo(() => (session.mode === 'api' ? locations : locations.filter((l) => fixtureStaff.locationAccess === 'all' || fixtureStaff.locationAccess.includes(l.restaurant.id))), [session.mode, locations, fixtureStaff])
  const location = useMemo(() => accessibleLocations.find((l) => l.restaurant.id === locationId) ?? accessibleLocations[0] ?? null, [accessibleLocations, locationId])
  const staff = useMemo<StaffMember>(() => (session.mode === 'api'
    ? { id: session.principal.id, name: session.principal.name, email: session.principal.email, role: location?.live?.role ?? restaurantRoleFromApi(session.principal.roles), locationAccess: 'all', status: 'active' }
    : fixtureStaff), [session, fixtureStaff, location])
  // Live: what the user holds AT THIS LOCATION. Session-wide codes would also show actions of another location's role.
  const permissions = useMemo(() => new Set(live ? location?.live?.permissions ?? [] : session.mode === 'api' ? restaurantPermissionsFromApi(session.principal.permissions) : permissionsForRole(staff.role)), [live, session, staff, location])

  const reload = useCallback(async () => {
    setStatus('loading')
    try {
      seedDashboardFixtures()
      // Live: the staff list needs its own permission and is loaded by the staff page; the fixture switch needs it here.
      const [locs, staffs] = await Promise.all([repos.management.getLocations(), live ? Promise.resolve([] as StaffMember[]) : repos.staff.list()])
      const org = await repos.management.getOrganization()
      setOrganization(org); setLocations(locs); setStaffList(staffs.length ? staffs : STAFF); setStatus('ready')
    } catch { setStatus('error') }
  }, [repos, live])
  useEffect(() => { void reload() }, [reload])
  const refreshLocation = useCallback(async () => { try { setLocations(await repos.management.getLocations()) } catch { /* keep current */ } }, [repos])
  const refreshNotifications = useCallback(async () => { try { setNotifications(await repos.notifications.list(location?.restaurant.id ?? null)) } catch { /* ignore */ } }, [repos, location?.restaurant.id])
  useEffect(() => { if (status === 'ready') void refreshNotifications() }, [status, refreshNotifications])

  const value: DashboardState = {
    repos, status, locations, location, accessibleLocations, staff, staffList, permissions, locale, live,
    // Live: the organization of the location the dashboard is on (a user can belong to several).
    organization: live && location?.live ? { id: location.live.organizationId, name: location.live.organizationName, logo: null, onboardingStatus: location.live.organizationStatus, locationIds: locations.filter((l) => l.live?.organizationId === location.live!.organizationId).map((l) => l.restaurant.id) } : organization,
    can: (p) => permissions.has(p),
    selectLocation: (id) => { setLocationId(id); write(LOC_KEY, id) },
    switchStaff: (id) => { if (session.mode === 'api') return; setStaffId(id); write(STAFF_KEY, id) },
    reload, refreshLocation,
    notice,
    // A refusal (403, 404 …) must not break the dashboard: it is shown and the real state is loaded again.
    setAcceptingOrders: async (v) => { if (!location) return; try { await repos.management.setAcceptingOrders(location.restaurant.id, v) } catch (e) { setNotice(dashboardErrorMessage(e)) } await refreshLocation() },
    notifications, unreadCount: notifications.filter((n) => !n.read).length,
    markRead: async (id) => { await repos.notifications.markRead(id); await refreshNotifications() },
    markAllRead: async () => { await repos.notifications.markAllRead(location?.restaurant.id ?? null); await refreshNotifications() },
    refreshNotifications,
  }
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>
}
export function useDashboard(): DashboardState { const v = useContext(Ctx); if (!v) throw new Error('useDashboard outside DashboardProvider'); return v }
