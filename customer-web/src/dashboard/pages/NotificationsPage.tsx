import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { t } from '../../i18n/strings'
import { useDashboard } from '../DashboardContext'
import { Card, EmptyState, Icon, PageHeader, Pill } from '../components/ui'
import type { NotificationType } from '../types'

/** Notifications (Module 17): centralized list (same state as the header / sidebar badges), type filter, mark read. */
const TYPES: Array<NotificationType | 'all'> = ['all', 'new_order', 'order_update', 'pickup', 'review', 'platform', 'warning']
export default function NotificationsPage() {
  const d = useDashboard(); const locale = d.locale; const [type, setType] = useState<NotificationType | 'all'>('all')
  useEffect(() => { document.title = `${t('dash.nav.notifications', undefined, locale)} · ${t('dash.brand', undefined, locale)}` }, [locale])
  const list = d.notifications.filter((n) => type === 'all' || n.type === type)
  const rel = (iso: string) => { const m = Math.round((Date.now() - new Date(iso).getTime()) / 60000); const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' }); return m < 60 ? rtf.format(-m, 'minute') : m < 1440 ? rtf.format(-Math.round(m / 60), 'hour') : rtf.format(-Math.round(m / 1440), 'day') }
  return (
    <div className="db-page" data-testid="db-notifications">
      <PageHeader title={t('dash.notifications.title', undefined, locale)} lead={t('dash.notifications.lead', undefined, locale)} actions={<><Pill tone={d.unreadCount ? 'orange' : 'muted'}>{t('dash.notifications.unread', { n: d.unreadCount }, locale)}</Pill>{d.unreadCount > 0 && <button type="button" className="db-btn db-btn--outline" onClick={() => { void d.markAllRead() }} data-testid="ntf-mark-all">{t('dash.notifications.markAll', undefined, locale)}</button>}<Link to="/restaurant-dashboard/settings#notifications" className="db-btn db-btn--ghost"><Icon name="settings" size={16} /> {t('dash.notifications.prefs', undefined, locale)}</Link></>} />
      <Card>
        <div className="db-toolbar" role="group" aria-label={t('dash.notifications.filter', undefined, locale)}>{TYPES.map((x) => <button key={x} type="button" className="db-chip" aria-pressed={type === x} onClick={() => setType(x)}>{t(`dash.notifications.type.${x}`, undefined, locale)}</button>)}</div>
        {list.length === 0 ? <EmptyState icon="notifications" title={t('dash.notifications.empty', undefined, locale)} text={t('dash.notifications.emptyText', undefined, locale)} /> : (
          <ul className="db-notif__list" style={{ marginTop: 12, maxHeight: 'none' }} data-testid="ntf-list">{list.map((n) => <li key={n.id} className={n.read ? '' : 'is-unread'} data-read={n.read}><button type="button" onClick={() => { void d.markRead(n.id) }} style={{ borderRadius: 10 }}><span className={`db-notif__dot db-notif__dot--${n.type}`} aria-hidden="true" /><span style={{ flex: 1 }}><b dir="auto">{n.title}</b> {!n.read && <Pill tone="orange">{t('dash.notifications.new', undefined, locale)}</Pill>}<small dir="auto">{n.body}</small><small className="db-muted">{t(`dash.notifications.type.${n.type}`, undefined, locale)} · {rel(n.at)}</small></span>{n.link && <Link to={n.link} className="db-link" onClick={(e) => e.stopPropagation()}>{t('dash.notifications.open', undefined, locale)} →</Link>}</button></li>)}</ul>
        )}
        <p className="db-muted" style={{ fontSize: '0.8rem', marginBottom: 0 }}>{t('dash.notifications.realtimeNote', undefined, locale)}</p>
      </Card>
    </div>
  )
}
