import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useStaffSession } from '../auth/staff/StaffSession'
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { t } from '../i18n/strings'
import { useDashboard } from './DashboardContext'
import { Avatar, ErrorState, Icon, Skeleton, ToastLine, Toggle } from './components/ui'
import type { Permission } from './types'
import './dashboard.css'

/**
 * Restaurant Dashboard shell (Module 17): dark navigation sidebar (FoodOnTheGo wordmark + "Restaurant Dashboard"),
 * top header (location selector, accepting-orders switch, notifications, staff profile), light content surface.
 * Desktop: expanded sidebar · ≤1200px: collapsible · mobile: drawer + bottom tab bar (Overview / Orders / Menu / More).
 */
export const BASE = '/restaurant-dashboard'
export const NAV: Array<{ id: string; path: string; icon: string; perm: Permission | null; badge?: 'orders' | 'notifications' }> = [
  { id: 'overview', path: 'overview', icon: 'overview', perm: null },
  { id: 'orders', path: 'orders', icon: 'orders', perm: 'orders.view', badge: 'orders' },
  { id: 'pickup', path: 'pickup-verification', icon: 'qr', perm: 'pickup.verify' },
  { id: 'menu', path: 'menu', icon: 'menu', perm: 'menu.view' },
  { id: 'profile', path: 'profile', icon: 'profile', perm: 'restaurant.profile.view' },
  { id: 'hours', path: 'hours', icon: 'hours', perm: 'restaurant.profile.view' },
  { id: 'pickupSettings', path: 'pickup-settings', icon: 'pickup', perm: 'restaurant.profile.view' },
  { id: 'reviews', path: 'reviews', icon: 'reviews', perm: 'reviews.view' },
  { id: 'staff', path: 'staff', icon: 'staff', perm: 'staff.view' },
  { id: 'analytics', path: 'analytics', icon: 'analytics', perm: 'analytics.view' },
  { id: 'notifications', path: 'notifications', icon: 'notifications', perm: 'notifications.view', badge: 'notifications' },
  { id: 'settings', path: 'settings', icon: 'settings', perm: null },
  { id: 'help', path: 'help', icon: 'help', perm: null },
]

/** Sections that are still development data when the restaurant domain runs on the backend (Module 23). */
export const DEMO_SECTIONS = ['overview', 'orders', 'pickup-verification', 'reviews', 'analytics', 'notifications', 'settings']
/** Reasons that keep a restaurant away from customers altogether. */
const HIDDEN_REASONS = ['MARKET_UNAVAILABLE', 'RESTAURANT_NOT_APPROVED', 'RESTAURANT_SUSPENDED', 'LOCATION_NOT_APPROVED', 'LOCATION_SUSPENDED', 'OUTSIDE_SERVICE_AREA']

/**
 * Live mode: tells the staff — on every page — when customers cannot see or order from the location, with the
 * backend's reason and the explanation FoodOnTheGo wrote for them. "Closed right now" is ordinary and not shown here.
 */
function LiveStatusStrip() {
  const d = useDashboard(); const live = d.location?.live
  if (!live || !live.availability.reason || live.availability.reason === 'CLOSED_NOW') return null
  const reason = t(`dash.live.reason.${live.availability.reason}`, undefined, d.locale)
  const hidden = HIDDEN_REASONS.includes(live.availability.reason)
  const note = live.statusNote ?? live.organizationStatusNote
  return (
    <p className={`db-live-strip ${hidden ? 'db-live-strip--hidden' : ''}`} role="status" data-testid="db-live-strip" data-reason={live.availability.reason}>
      <Icon name="info" size={16} /><span>{t(hidden ? 'dash.live.notVisible' : 'dash.live.notOrderable', { reason }, d.locale)}{note ? ` ${t('dash.live.note', { note }, d.locale)}` : ''}</span>
    </p>
  )
}

function useOutsideClose<T extends HTMLElement>(open: boolean, onClose: () => void) {
  const ref = useRef<T>(null)
  useEffect(() => { if (!open) return; const onDoc = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) onClose() }; const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose() }; document.addEventListener('mousedown', onDoc); document.addEventListener('keydown', onKey); return () => { document.removeEventListener('mousedown', onDoc); document.removeEventListener('keydown', onKey) } }, [open, onClose])
  return ref
}

function LocationSelector() {
  const d = useDashboard(); const [open, setOpen] = useState(false); const ref = useOutsideClose<HTMLDivElement>(open, () => setOpen(false))
  const loc = d.location; if (!loc) return null
  return (
    <div className="db-loc" ref={ref}>
      <button type="button" className="db-loc__btn" aria-haspopup="listbox" aria-expanded={open} onClick={() => setOpen(!open)} data-testid="db-location">
        <span className="db-loc__logo" aria-hidden="true">{loc.profile.logo ? <img src={loc.profile.logo} alt="" /> : loc.restaurant.fallback}</span>
        <span className="db-loc__text"><b dir="auto">{loc.restaurant.name}</b><small dir="auto">{loc.profile.locationName}</small></span>
        <Icon name="chevron" size={16} />
      </button>
      {open && (
        <ul className="db-loc__menu" role="listbox" aria-label={t('dash.header.switchLocation', undefined, d.locale)}>
          {d.accessibleLocations.map((l) => <li key={l.restaurant.id} role="option" aria-selected={l.restaurant.id === loc.restaurant.id}><button type="button" className={`db-loc__opt ${l.restaurant.id === loc.restaurant.id ? 'is-on' : ''}`} onClick={() => { d.selectLocation(l.restaurant.id); setOpen(false) }}><span className="db-loc__logo" aria-hidden="true">{l.restaurant.fallback}</span><span className="db-loc__text"><b dir="auto">{l.restaurant.name}</b><small dir="auto">{l.profile.locationName} · {l.restaurant.address.locality ?? l.restaurant.countryCode} · {l.restaurant.currency}</small></span>{l.restaurant.id === loc.restaurant.id && <Icon name="check" size={16} />}</button></li>)}
          {d.locations.length > d.accessibleLocations.length && <li className="db-loc__note">{t('dash.header.locationsRestricted', { n: d.locations.length - d.accessibleLocations.length }, d.locale)}</li>}
        </ul>
      )}
    </div>
  )
}

function NotificationsMenu() {
  const d = useDashboard(); const [open, setOpen] = useState(false); const ref = useOutsideClose<HTMLDivElement>(open, () => setOpen(false)); const nav = useNavigate()
  const latest = d.notifications.slice(0, 5)
  return (
    <div className="db-notif" ref={ref}>
      <button type="button" className="db-iconbtn db-notif__btn" aria-haspopup="dialog" aria-expanded={open} onClick={() => setOpen(!open)} aria-label={t('dash.header.notifications', { n: d.unreadCount }, d.locale)} data-testid="db-notif-btn"><Icon name="notifications" />{d.unreadCount > 0 && <span className="db-notif__badge" data-testid="db-notif-count">{d.unreadCount}</span>}</button>
      {open && (
        <div className="db-notif__menu" role="dialog" aria-label={t('dash.nav.notifications', undefined, d.locale)}>
          <div className="db-notif__head"><b>{t('dash.nav.notifications', undefined, d.locale)}</b>{d.unreadCount > 0 && <button type="button" className="db-link" onClick={() => { void d.markAllRead() }}>{t('dash.notifications.markAll', undefined, d.locale)}</button>}</div>
          {latest.length === 0 ? <p className="db-muted db-notif__empty">{t('dash.notifications.empty', undefined, d.locale)}</p> : <ul className="db-notif__list">{latest.map((n) => <li key={n.id} className={n.read ? '' : 'is-unread'}><button type="button" onClick={() => { void d.markRead(n.id); setOpen(false); if (n.link) nav(n.link) }}><span className={`db-notif__dot db-notif__dot--${n.type}`} aria-hidden="true" /><span><b dir="auto">{n.title}</b><small dir="auto">{n.body}</small></span></button></li>)}</ul>}
          <Link to={`${BASE}/notifications`} className="db-notif__all" onClick={() => setOpen(false)}>{t('dash.notifications.viewAll', undefined, d.locale)}</Link>
        </div>
      )}
    </div>
  )
}

function ProfileMenu() {
  const d = useDashboard(); const session = useStaffSession(); const [open, setOpen] = useState(false); const ref = useOutsideClose<HTMLDivElement>(open, () => setOpen(false))
  return (
    <div className="db-profile" ref={ref}>
      <button type="button" className="db-profile__btn" aria-haspopup="menu" aria-expanded={open} onClick={() => setOpen(!open)} data-testid="db-profile" aria-label={`${d.staff.name} · ${t(`dash.role.${d.staff.role}`, undefined, d.locale)}`}><Avatar name={d.staff.name} src={d.staff.avatar} /><span className="db-profile__text"><b dir="auto">{d.staff.name}</b><small>{t(`dash.role.${d.staff.role}`, undefined, d.locale)}</small></span><Icon name="chevron" size={16} /></button>
      {open && (
        <div className="db-profile__menu" role="menu" aria-label={t('dash.header.profileMenu', undefined, d.locale)}>
          <Link role="menuitem" to={`${BASE}/settings`} onClick={() => setOpen(false)}>{t('dash.nav.settings', undefined, d.locale)}</Link>
          <Link role="menuitem" to={`${BASE}/help`} onClick={() => setOpen(false)}>{t('dash.nav.help', undefined, d.locale)}</Link>
          {session.mode === 'api' && <Link role="menuitem" to={`${BASE}/account-security`} onClick={() => setOpen(false)} data-testid="account-security-link">Account security</Link>}
          {session.mode !== 'api' && (<>
          <div className="db-profile__dev"><p className="db-muted">{t('dash.header.switchStaff', undefined, d.locale)}</p>
            {d.staffList.filter((s) => s.status !== 'suspended').map((s) => <button key={s.id} type="button" role="menuitemradio" aria-checked={s.id === d.staff.id} className={s.id === d.staff.id ? 'is-on' : ''} onClick={() => { d.switchStaff(s.id); setOpen(false) }} data-testid={`db-staff-${s.id}`}><span dir="auto">{s.name}</span> <small>{t(`dash.role.${s.role}`, undefined, d.locale)}</small></button>)}
          </div>
          </>)}
          <Link role="menuitem" to="/" className="db-profile__signout"><Icon name="logout" size={16} /> {t('dash.header.exit', undefined, d.locale)}</Link>
          {session.mode === 'api' && <button type="button" role="menuitem" className="db-profile__signout" onClick={() => { setOpen(false); void session.logout() }} data-testid="staff-signout"><Icon name="logout" size={16} /> Sign out</button>}
        </div>
      )}
    </div>
  )
}

export function AcceptingSwitch({ compact }: { compact?: boolean }) {
  const d = useDashboard(); const loc = d.location; if (!loc) return null
  const on = loc.restaurant.acceptingOrders && loc.restaurant.status === 'active'
  const canEdit = d.can('restaurant.profile.edit') || d.can('settings.manage')
  return (
    <div className={`db-accepting ${on ? 'is-on' : ''} ${compact ? 'db-accepting--compact' : ''}`} data-testid="db-accepting">
      <span className="db-accepting__dot" aria-hidden="true" /><span className="db-accepting__label">{on ? t('dash.header.accepting', undefined, d.locale) : t('dash.header.notAccepting', undefined, d.locale)}</span>
      <Toggle checked={loc.restaurant.acceptingOrders} onChange={(v) => { void d.setAcceptingOrders(v) }} label={t('dash.header.acceptingToggle', undefined, d.locale)} disabled={!canEdit || loc.restaurant.status !== 'active'} testId="db-accepting-toggle" />
    </div>
  )
}

export default function DashboardLayout() {
  const d = useDashboard(); const loc = useLocation(); const [collapsed, setCollapsed] = useState(false); const [drawer, setDrawer] = useState(false)
  useEffect(() => { setDrawer(false) }, [loc.pathname])
  useEffect(() => { document.title = `${t('dash.brand', undefined, d.locale)} · FoodOnTheGo` }, [d.locale])
  const items = NAV.filter((n) => !n.perm || d.can(n.perm))
  const pendingOrders = 0 // sidebar order badge is filled by the orders page through context-free polling later; header counts come from notifications
  const badge = (n: (typeof NAV)[number]) => (n.badge === 'notifications' ? d.unreadCount : n.badge === 'orders' ? pendingOrders + d.notifications.filter((x) => !x.read && x.type === 'new_order').length : 0)
  const nav = (mobile = false) => (
    <nav className="db-nav" aria-label={t('dash.nav.label', undefined, d.locale)}>
      <ul>{items.map((n) => <li key={n.id}><NavLink to={`${BASE}/${n.path}`} className={({ isActive }) => `db-nav__link ${isActive ? 'is-active' : ''}`} onClick={() => mobile && setDrawer(false)} data-nav={n.id}><Icon name={n.icon} size={20} /><span className="db-nav__text">{t(`dash.nav.${n.id}`, undefined, d.locale)}</span>{badge(n) > 0 && <span className="db-nav__badge" aria-label={`${badge(n)}`}>{badge(n)}</span>}</NavLink></li>)}</ul>
    </nav>
  )
  const brand = (
    <div className="db-brand">
      <Link to={`${BASE}/overview`} className="db-brand__link" aria-label={t('dash.brand', undefined, d.locale)}>
        <img src="/brand/foodonthego-logo-dark-bg.svg" alt="FoodOnTheGo" className="db-brand__logo db-brand__logo--full" width="620" height="125" />
        <img src="/brand/foodonthego-icon.svg" alt="FoodOnTheGo" className="db-brand__logo db-brand__logo--mark" width="64" height="64" />
      </Link>
      <span className="db-brand__label">{t('dash.brand', undefined, d.locale)}</span>
    </div>
  )
  let content: ReactNode
  if (d.status === 'loading') content = <div className="db-page"><Skeleton rows={6} /></div>
  else if (d.status === 'error') content = <div className="db-page"><ErrorState title={t('dash.error.loadTitle', undefined, d.locale)} text={t('dash.error.loadText', undefined, d.locale)} onRetry={() => { void d.reload() }} locale={d.locale} /></div>
  else if (!d.location) content = <div className="db-page"><ErrorState title={t('dash.error.noLocationTitle', undefined, d.locale)} text={t('dash.error.noLocationText', undefined, d.locale)} locale={d.locale} /></div>
  else {
    const section = loc.pathname.slice(BASE.length + 1).split('/')[0]
    content = <>
      {d.live && <LiveStatusStrip />}
      {d.live && DEMO_SECTIONS.includes(section) && <p className="db-demo-banner" role="note" data-testid="db-demo-banner"><Icon name="info" size={16} /><span>{t('dash.live.mockBanner', undefined, d.locale)}</span></p>}
      <Outlet />
    </>
  }
  return (
    <div className={`db-shell ${collapsed ? 'db-shell--collapsed' : ''}`} data-testid="db-shell">
      <a href="#db-main" className="db-skip">{t('dash.skip', undefined, d.locale)}</a>
      <aside className="db-sidebar" data-testid="db-sidebar">
        {brand}
        {nav()}
        <button type="button" className="db-sidebar__collapse" onClick={() => setCollapsed(!collapsed)} aria-pressed={collapsed} aria-label={t('dash.nav.collapse', undefined, d.locale)}><Icon name="back" size={18} /><span className="db-nav__text">{t('dash.nav.collapse', undefined, d.locale)}</span></button>
      </aside>
      {drawer && <div className="db-drawer__backdrop db-mobile-nav__backdrop" onMouseDown={() => setDrawer(false)}><aside className="db-sidebar db-sidebar--drawer" role="dialog" aria-modal="true" aria-label={t('dash.nav.label', undefined, d.locale)} onMouseDown={(e) => e.stopPropagation()}>{brand}{nav(true)}</aside></div>}
      <div className="db-main-col">
        <header className="db-header" data-testid="db-header">
          <button type="button" className="db-iconbtn db-header__burger" onClick={() => setDrawer(true)} aria-label={t('dash.nav.open', undefined, d.locale)} aria-expanded={drawer}><Icon name="burger" /></button>
          <img src="/brand/foodonthego-icon.svg" alt="FoodOnTheGo" className="db-header__mark" width="32" height="32" />
          <LocationSelector />
          <AcceptingSwitch compact />
          <div className="db-header__spacer" />
          <NotificationsMenu />
          <ProfileMenu />
        </header>
        <main id="db-main" className="db-main" tabIndex={-1}>{content}</main>
        <ToastLine msg={d.notice} />
        <nav className="db-tabbar" aria-label={t('dash.nav.label', undefined, d.locale)}>
          {[NAV[0], NAV[1], NAV[3]].filter((n) => !n.perm || d.can(n.perm)).map((n) => <NavLink key={n.id} to={`${BASE}/${n.path}`} className={({ isActive }) => `db-tabbar__link ${isActive ? 'is-active' : ''}`}><Icon name={n.icon} />{t(`dash.nav.${n.id}`, undefined, d.locale)}{badge(n) > 0 && <span className="db-nav__badge">{badge(n)}</span>}</NavLink>)}
          <button type="button" className="db-tabbar__link" onClick={() => setDrawer(true)}><Icon name="more" />{t('dash.nav.more', undefined, d.locale)}</button>
        </nav>
      </div>
    </div>
  )
}

/** Route guard for permission-scoped pages: staff without the permission see an explicit "permission denied" state. */
export function RequirePermission({ perm, children }: { perm: Permission; children: ReactNode }) {
  const d = useDashboard()
  if (!d.can(perm)) return <div className="db-page"><ErrorState title={t('dash.error.permissionTitle', undefined, d.locale)} text={t('dash.error.permissionText', { role: t(`dash.role.${d.staff.role}`, undefined, d.locale) }, d.locale)} locale={d.locale} /></div>
  return <>{children}</>
}
