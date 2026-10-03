import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useStaffSession } from '../auth/staff/StaffSession'
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { t } from '../i18n/strings'
import { Avatar, ErrorState, Icon, Skeleton } from '../dashboard/components/ui'
import { useAdmin } from './AdminContext'
import type { AdminPermission, SearchHit } from './types'
import '../dashboard/dashboard.css'
import './admin.css'

/**
 * Platform Admin shell (Module 18): dark navigation sidebar grouped by area (Overview · Operations · Finance · Growth ·
 * Platform · Security & Administration), header with global search, environment indicator (LOCAL / MOCK DATA), critical
 * alerts and the admin profile. Desktop: expanded sidebar · ≤1200px collapsible · mobile: drawer + bottom tab bar.
 */
export const BASE = '/admin'
export type NavItem = { id: string; path: string; icon: string; perm: AdminPermission | null; badge?: 'pending' | 'alerts' | 'support' }
export const NAV_GROUPS: Array<{ id: string; items: NavItem[] }> = [
  { id: 'overview', items: [{ id: 'overview', path: 'overview', icon: 'overview', perm: null }] },
  { id: 'operations', items: [{ id: 'restaurants', path: 'restaurants', icon: 'restaurants', perm: 'restaurants.view', badge: 'pending' }, { id: 'customers', path: 'customers', icon: 'customers', perm: 'customers.view' }, { id: 'orders', path: 'orders', icon: 'orders', perm: 'orders.view' }, { id: 'reviews', path: 'reviews', icon: 'reviews', perm: 'reviews.view' }, { id: 'support', path: 'support', icon: 'support', perm: 'support.view', badge: 'support' }] },
  { id: 'finance', items: [{ id: 'payments', path: 'payments', icon: 'payments', perm: 'payments.view' }, { id: 'refunds', path: 'refunds', icon: 'refunds', perm: 'refunds.view' }, { id: 'settlements', path: 'settlements', icon: 'settlements', perm: 'settlements.view' }] },
  { id: 'growth', items: [{ id: 'promotions', path: 'promotions', icon: 'promotions', perm: 'promotions.view' }] },
  { id: 'platform', items: [{ id: 'markets', path: 'markets', icon: 'markets', perm: 'markets.view' }, { id: 'configuration', path: 'configuration', icon: 'settings', perm: 'configuration.manage' }, { id: 'notifications', path: 'notifications', icon: 'notifications', perm: 'notifications.manage', badge: 'alerts' }, { id: 'analytics', path: 'analytics', icon: 'analytics', perm: 'analytics.view' }] },
  { id: 'security', items: [{ id: 'adminUsers', path: 'admin-users', icon: 'staff', perm: 'admin_users.view' }, { id: 'auditLogs', path: 'audit-logs', icon: 'audit', perm: 'audit.view' }, { id: 'security', path: 'security', icon: 'shield', perm: 'security.view' }, { id: 'system', path: 'system', icon: 'system', perm: 'system.view' }, { id: 'settings', path: 'settings', icon: 'settings', perm: 'settings.manage' }, { id: 'help', path: 'help', icon: 'help', perm: null }] },
]
const NAV_ALL = NAV_GROUPS.flatMap((g) => g.items)

function useOutsideClose<T extends HTMLElement>(open: boolean, onClose: () => void) {
  const ref = useRef<T>(null)
  useEffect(() => { if (!open) return; const onDoc = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) onClose() }; const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose() }; document.addEventListener('mousedown', onDoc); document.addEventListener('keydown', onKey); return () => { document.removeEventListener('mousedown', onDoc); document.removeEventListener('keydown', onKey) } }, [open, onClose])
  return ref
}

/** Global search: restaurants, orders, customers, payments and support cases the current admin may see. */
function GlobalSearch() {
  const a = useAdmin(); const nav = useNavigate(); const [q, setQ] = useState(''); const [hits, setHits] = useState<SearchHit[]>([]); const [open, setOpen] = useState(false); const [active, setActive] = useState(0)
  const ref = useOutsideClose<HTMLDivElement>(open, () => setOpen(false))
  useEffect(() => { if (q.trim().length < 2) { setHits([]); return } let alive = true; const id = setTimeout(() => { void a.repos.search.search(q, a.permissions).then((h) => { if (alive) { setHits(h); setActive(0); setOpen(true) } }) }, 180); return () => { alive = false; clearTimeout(id) } }, [q, a.repos, a.permissions])
  const go = (h: SearchHit) => { setOpen(false); setQ(''); nav(h.link) }
  return (
    <div className="adm-search" ref={ref}>
      <label className="db-search adm-search__box"><span className="db-sr-only">{t('adm.search.label', undefined, a.locale)}</span><Icon name="search" size={18} /><input className="db-input" type="search" value={q} placeholder={t('adm.search.placeholder', undefined, a.locale)} onChange={(e) => setQ(e.target.value)} onFocus={() => hits.length && setOpen(true)} onKeyDown={(e) => { if (e.key === 'ArrowDown') { e.preventDefault(); setActive((i) => Math.min(hits.length - 1, i + 1)) } else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((i) => Math.max(0, i - 1)) } else if (e.key === 'Enter' && hits[active]) go(hits[active]) }} aria-controls={open && hits.length > 0 ? "adm-search-list" : undefined} aria-autocomplete="list" data-testid="global-search" /></label>
      {open && q.trim().length >= 2 && (
        <ul className="adm-search__list" id="adm-search-list" role="listbox" data-testid="global-search-results">
          {hits.length === 0 && <li className="adm-search__empty">{t('adm.search.empty', undefined, a.locale)}</li>}
          {hits.map((h, i) => <li key={`${h.kind}-${h.ref}`} role="option" aria-selected={i === active}><button type="button" className={`adm-search__hit ${i === active ? 'is-on' : ''}`} onMouseDown={(e) => e.preventDefault()} onClick={() => go(h)}><span className={`adm-search__kind adm-search__kind--${h.kind}`}>{t(`adm.search.kind.${h.kind}`, undefined, a.locale)}</span><span><b dir="auto">{h.title}</b><small dir="auto">{h.subtitle}</small></span></button></li>)}
        </ul>
      )}
    </div>
  )
}
/** Market context selector: current market is always visible; future markets are listed but cannot be selected. */
function MarketSelector() {
  const a = useAdmin(); const [open, setOpen] = useState(false); const ref = useOutsideClose<HTMLDivElement>(open, () => setOpen(false))
  const current = a.marketModel; const selectable = a.markets.filter((m) => m.status !== 'DRAFT' && m.status !== 'CLOSED'); const future = a.markets.filter((m) => m.status === 'DRAFT')
  return (
    <div className="adm-market" ref={ref}>
      <button type="button" className="adm-market__btn" aria-haspopup="listbox" aria-expanded={open} onClick={() => setOpen((o) => !o)} data-testid="market-selector" aria-label={t('adm.market.selector', { market: current ? current.displayName : t('adm.market.allMarkets', undefined, a.locale) }, a.locale)}>
        <Icon name="markets" size={18} /><span className="adm-market__text"><small>{t('adm.market.label', undefined, a.locale)}</small><b>{current ? current.displayName : t('adm.market.allMarkets', undefined, a.locale)}</b></span>{current && <span className="adm-market__code">{current.countryCode}</span>}<Icon name="chevron" size={14} />
      </button>
      {open && (
        <ul className="adm-market__menu" role="listbox" aria-label={t('adm.market.label', undefined, a.locale)} data-testid="market-menu">
          {selectable.map((m) => <li key={m.countryCode} role="option" aria-selected={a.market === m.countryCode}><button type="button" className={`adm-market__opt ${a.market === m.countryCode ? 'is-on' : ''}`} onClick={() => { a.setMarket(m.countryCode); setOpen(false) }}><span><b>{m.displayName}</b><small>{m.countryCode} · {m.defaultCurrency} · {m.defaultTimezone}</small></span><span className={`db-badge db-badge--${m.status === 'ACTIVE' ? 'green' : m.status === 'PILOT' ? 'blue' : 'red'}`}>{t(`adm.marketStatus.${m.status}`, undefined, a.locale)}</span></button></li>)}
          <li role="option" aria-selected={a.market === 'all'}><button type="button" className={`adm-market__opt ${a.market === 'all' ? 'is-on' : ''}`} onClick={() => { a.setMarket('all'); setOpen(false) }}><span><b>{t('adm.market.allMarkets', undefined, a.locale)}</b><small>{t('adm.market.allNote', { n: selectable.length }, a.locale)}</small></span></button></li>
          {future.length > 0 && <li className="adm-market__heading" role="presentation">{t('adm.market.future', undefined, a.locale)}</li>}
          {future.map((m) => <li key={m.countryCode} role="option" aria-selected={false} aria-disabled="true" className="adm-market__opt adm-market__opt--disabled"><span><b>{m.displayName}</b><small>{m.countryCode} · {m.defaultCurrency}</small></span><span className="db-badge db-badge--muted">{t('adm.market.comingLater', undefined, a.locale)}</span></li>)}
        </ul>
      )}
    </div>
  )
}
/** Says where the data of THE CURRENT SECTION comes from: the backend, or development data kept in this browser. */
function EnvironmentBadge() {
  const a = useAdmin(); const loc = useLocation()
  const backend = a.backendSections.includes(loc.pathname.slice(BASE.length + 1).split('/')[0])
  return <span className={`adm-env adm-env--${a.environment.toLowerCase()}`} data-testid="env-badge" data-source={backend ? 'backend' : 'mock'} title={t('adm.env.title', undefined, a.locale)}><span className="adm-env__dot" aria-hidden="true" />{t(`adm.env.${a.environment}`, undefined, a.locale)}{backend ? <span className="adm-env__mock"> · {t('adm.env.backend', undefined, a.locale)}</span> : a.mockData && <span className="adm-env__mock"> · {t('adm.env.mock', undefined, a.locale)}</span>}</span>
}
function AlertsMenu() {
  const a = useAdmin(); const [open, setOpen] = useState(false); const ref = useOutsideClose<HTMLDivElement>(open, () => setOpen(false)); const nav = useNavigate()
  const time = (iso: string) => new Date(iso).toLocaleTimeString(a.locale, { hour: '2-digit', minute: '2-digit' })
  return (
    <div className="db-notif" ref={ref}>
      <button type="button" className="db-iconbtn" aria-label={t('adm.header.alerts', { n: a.unreadCount }, a.locale)} aria-expanded={open} onClick={() => setOpen((o) => !o)} data-testid="alerts-btn"><Icon name="notifications" />{a.unreadCount > 0 && <span className={`db-notif__badge ${a.criticalCount ? 'adm-badge--critical' : ''}`} data-testid="alerts-badge">{a.unreadCount}</span>}</button>
      {open && (
        <div className="db-notif__menu" role="dialog" aria-label={t('adm.header.alertsTitle', undefined, a.locale)}>
          <div className="db-notif__head"><b>{t('adm.header.alertsTitle', undefined, a.locale)}</b>{a.unreadCount > 0 && <button type="button" className="db-link" onClick={() => { void a.markAllRead() }}>{t('dash.notifications.markAll', undefined, a.locale)}</button>}</div>
          {a.alerts.length === 0 ? <p className="db-notif__empty db-muted">{t('adm.alerts.empty', undefined, a.locale)}</p> : (
            <ul className="db-notif__list">{a.alerts.slice(0, 8).map((n) => <li key={n.id} className={n.read ? '' : 'is-unread'}><button type="button" onClick={() => { void a.markRead(n.id); setOpen(false); if (n.link) nav(n.link) }}><span className={`db-notif__dot adm-sev--${n.severity}`} aria-hidden="true" /><span><b>{n.title}</b><small>{n.body}</small><small>{time(n.at)}</small></span></button></li>)}</ul>
          )}
          <Link to={`${BASE}/notifications`} className="db-notif__all" onClick={() => setOpen(false)}>{t('dash.notifications.viewAll', undefined, a.locale)}</Link>
        </div>
      )}
    </div>
  )
}
function ProfileMenu() {
  const a = useAdmin(); const session = useStaffSession(); const [open, setOpen] = useState(false); const ref = useOutsideClose<HTMLDivElement>(open, () => setOpen(false))
  return (
    <div className="db-profile" ref={ref}>
      <button type="button" className="db-profile__btn" aria-expanded={open} aria-label={`${a.admin.name} · ${t(`adm.role.${a.admin.role}`, undefined, a.locale)}`} onClick={() => setOpen((o) => !o)} data-testid="profile-btn"><Avatar name={a.admin.name} size={38} /><span className="db-profile__text"><b dir="auto">{a.admin.name}</b><small>{t(`adm.role.${a.admin.role}`, undefined, a.locale)}</small></span><Icon name="chevron" size={16} /></button>
      {open && (
        <div className="db-profile__menu" role="menu">
          {session.mode !== 'api' && (<>
          <div className="db-profile__dev"><p className="db-muted">{t('adm.header.switchAdmin', undefined, a.locale)}</p>
            <label className="db-sr-only" htmlFor="adm-switch">{t('adm.header.switchAdmin', undefined, a.locale)}</label>
            <select id="adm-switch" className="db-select db-input--sm" value={a.admin.id} onChange={(e) => { a.switchAdmin(e.target.value); setOpen(false) }} data-testid="admin-switch">{a.admins.map((u) => <option key={u.id} value={u.id}>{u.name} · {t(`adm.role.${u.role}`, undefined, a.locale)}{u.status !== 'ACTIVE' ? ` (${t(`adm.userStatus.${u.status}`, undefined, a.locale)})` : ''}</option>)}</select>
            <p className="db-muted adm-small">{t('adm.header.devNote', undefined, a.locale)}</p></div>
          </>)}
          <Link to={`${BASE}/settings`} className="db-profile__signout" role="menuitem" onClick={() => setOpen(false)}>{t('adm.nav.settings', undefined, a.locale)}</Link>
          {session.mode === 'api' && <Link to={`${BASE}/account-security`} className="db-profile__signout" role="menuitem" onClick={() => setOpen(false)} data-testid="account-security-link">Account security</Link>}
          {session.mode === 'api' && <p className="db-muted adm-small" dir="auto">{a.admin.email}</p>}
          <Link to="/" className="db-profile__signout" role="menuitem">{t('adm.header.exit', undefined, a.locale)}</Link>
          {session.mode === 'api' && <button type="button" className="db-profile__signout" role="menuitem" onClick={() => { setOpen(false); void session.logout() }} data-testid="staff-signout">Sign out</button>}
        </div>
      )}
    </div>
  )
}

export function RequirePermission({ perm, children }: { perm: AdminPermission; children: ReactNode }) {
  const a = useAdmin()
  if (a.status !== 'ready') return null
  if (!a.can(perm)) return <div className="db-page"><ErrorState title={t('adm.error.forbiddenTitle', undefined, a.locale)} text={t('adm.error.forbiddenText', { role: t(`adm.role.${a.admin.role}`, undefined, a.locale) }, a.locale)} locale={a.locale} /></div>
  return <>{children}</>
}

export default function AdminLayout() {
  const a = useAdmin(); const loc = useLocation(); const [collapsed, setCollapsed] = useState(false); const [drawer, setDrawer] = useState(false)
  const [pendingCount, setPendingCount] = useState(0); const [supportCount, setSupportCount] = useState(0)
  useEffect(() => { setDrawer(false) }, [loc.pathname])
  useEffect(() => { document.body.classList.toggle('db-lock', drawer); return () => document.body.classList.remove('db-lock') }, [drawer])
  useEffect(() => { if (a.status !== 'ready') return; let alive = true; void a.repos.restaurants.list({ tab: 'pending', pageSize: 1 }).then((p) => alive && setPendingCount(p.total)).catch(() => {}); void a.repos.support.list({ tab: 'open', pageSize: 1 }).then((p) => alive && setSupportCount(p.counts.open)).catch(() => {}); return () => { alive = false } }, [a.status, a.repos, loc.pathname])
  const badge = (b: NavItem['badge']) => (b === 'pending' ? pendingCount : b === 'alerts' ? a.unreadCount : b === 'support' ? supportCount : 0)
  const visible = (i: NavItem) => !i.perm || a.can(i.perm)
  const nav = (
    <nav className="db-nav adm-nav" aria-label={t('adm.nav.label', undefined, a.locale)}>
      {NAV_GROUPS.map((g) => { const items = g.items.filter(visible); if (!items.length) return null; return (
        <div key={g.id} className="adm-nav__group">{g.id !== 'overview' && <p className="adm-nav__heading">{t(`adm.nav.group.${g.id}`, undefined, a.locale)}</p>}
          <ul>{items.map((i) => { const n = badge(i.badge); return <li key={i.id}><NavLink to={`${BASE}/${i.path}`} className={({ isActive }) => `db-nav__link ${isActive ? 'is-active' : ''}`} title={i.id} onClick={() => setDrawer(false)}><Icon name={i.icon} /><span className="db-nav__text">{t(`adm.nav.${i.id}`, undefined, a.locale)}</span>{n > 0 && <span className={`db-nav__badge ${i.badge === 'alerts' && a.criticalCount ? 'adm-badge--critical' : ''}`} aria-label={t('adm.nav.badge', { n }, a.locale)}>{n}</span>}</NavLink></li> })}</ul>
        </div>) })}
    </nav>
  )
  const brand = (
    <div className="db-brand adm-brand"><Link to={`${BASE}/overview`} className="db-brand__link" aria-label="FoodOnTheGo"><img src="/brand/foodonthego-logo-dark-bg.svg" alt="" className="db-brand__logo db-brand__logo--full" width={170} height={44} /><img src="/brand/foodonthego-icon.svg" alt="" className="db-brand__logo db-brand__logo--mark" width={40} height={40} /></Link><span className="db-brand__label">{t('adm.brand', undefined, a.locale)}</span></div>
  )
  const tabs = NAV_ALL.filter((i) => ['overview', 'restaurants', 'orders'].includes(i.id) && visible(i))
  return (
    <div className={`db-shell adm-shell ${collapsed ? 'db-shell--collapsed' : ''}`} data-testid="admin-shell">
      <a href="#adm-main" className="db-skip">{t('dash.skip', undefined, a.locale)}</a>
      <aside className="db-sidebar adm-sidebar" aria-label={t('adm.brand', undefined, a.locale)}>{brand}{nav}<button type="button" className="db-sidebar__collapse" onClick={() => setCollapsed((c) => !c)} aria-pressed={collapsed} aria-label={t(collapsed ? 'adm.nav.expand' : 'dash.nav.collapse', undefined, a.locale)}><Icon name="back" /><span className="db-nav__text">{t('dash.nav.collapse', undefined, a.locale)}</span></button></aside>
      {drawer && <div className="db-drawer__backdrop db-drawer__backdrop--nav" onMouseDown={() => setDrawer(false)}><aside className="db-sidebar db-sidebar--drawer adm-sidebar" onMouseDown={(e) => e.stopPropagation()} aria-label={t('adm.brand', undefined, a.locale)}>{brand}{nav}</aside></div>}
      <div className="db-main-col">
        <header className="db-header adm-header">
          <button type="button" className="db-iconbtn db-header__burger" aria-label={t('adm.nav.open', undefined, a.locale)} onClick={() => setDrawer(true)}><Icon name="burger" /></button>
          <Link to={`${BASE}/overview`} className="db-header__mark" aria-label="FoodOnTheGo"><img src="/brand/foodonthego-icon.svg" alt="" width={36} height={36} /></Link>
          <GlobalSearch />
          <MarketSelector />
          <EnvironmentBadge />
          <div className="db-header__spacer" />
          <AlertsMenu />
          <ProfileMenu />
        </header>
        <main id="adm-main" className="db-main adm-main" tabIndex={-1}>
          {a.status === 'loading' && <div className="db-page"><Skeleton rows={6} /></div>}
          {a.status === 'error' && <div className="db-page"><ErrorState title={t('adm.error.loadTitle', undefined, a.locale)} text={t('adm.error.loadText', undefined, a.locale)} onRetry={() => { void a.reload() }} locale={a.locale} /></div>}
          {a.status === 'ready' && <Outlet />}
        </main>
        <nav className="db-tabbar adm-tabbar" aria-label={t('adm.nav.label', undefined, a.locale)}>
          {tabs.map((i) => <NavLink key={i.id} to={`${BASE}/${i.path}`} className={({ isActive }) => `db-tabbar__link ${isActive ? 'is-active' : ''}`}><Icon name={i.icon} /><span>{t(`adm.nav.${i.id}`, undefined, a.locale)}</span></NavLink>)}
          <button type="button" className="db-tabbar__link" onClick={() => setDrawer(true)}><Icon name="burger" /><span>{t('dash.nav.more', undefined, a.locale)}</span></button>
        </nav>
      </div>
    </div>
  )
}
