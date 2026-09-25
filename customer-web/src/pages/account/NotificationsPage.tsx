import { useState, type ReactElement } from 'react'
import { Link } from 'react-router-dom'
import Header from '../../components/Header'
import AccountSidebar from '../../components/AccountSidebar'
import { EmptyState, ErrorState, LoadingState } from '../../components/AccountStates'
import { useToast } from '../../components/Toast'
import { useAccount, type Notification, type NotificationKind, type NotificationPreferences } from '../../account/AccountContext'
import './AccountPage.css'
import './NotificationsPage.css'

type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const ICON: Record<Notification['icon'], (p: P) => ReactElement> = {
  bag: ({ size = 20 }) => (<svg {...stroke(size)}><path d="M6 8h12l1 12H5L6 8Z" /><path d="M9 8V6a3 3 0 0 1 6 0v2" /></svg>),
  tag: ({ size = 20 }) => (<svg {...stroke(size)}><path d="M3 12V3h9l9 9-9 9z" /><circle cx="8" cy="8" r="1.5" /></svg>),
  bell: ({ size = 20 }) => (<svg {...stroke(size)}><path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4l2-2ZM10 20a2 2 0 0 0 4 0" /></svg>),
  store: ({ size = 20 }) => (<svg {...stroke(size)}><path d="M3 9.5 4.5 4h15L21 9.5M3 9.5a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0M5 12v8h14v-8M10 20v-5h4v5" /></svg>),
  percent: ({ size = 20 }) => (<svg {...stroke(size)}><path d="M19 5 5 19" /><circle cx="7" cy="7" r="2.5" /><circle cx="17" cy="17" r="2.5" /></svg>),
  user: ({ size = 20 }) => (<svg {...stroke(size)}><circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" /></svg>),
}
const TONE: Record<Notification['icon'], string> = { bag: 'green', tag: 'red', bell: 'orange', store: 'blue', percent: 'orange', user: 'green' }
const TABS: Array<{ id: 'all' | NotificationKind; label: string }> = [{ id: 'all', label: 'All' }, { id: 'orders', label: 'Orders' }, { id: 'offers', label: 'Offers' }, { id: 'updates', label: 'Updates' }]
const PREFS: Array<{ key: keyof NotificationPreferences; label: string; sub: string; provider: string }> = [
  { key: 'push', label: 'Push notifications', sub: 'Alerts on this device', provider: 'Firebase FCM = NOT STARTED' },
  { key: 'orderUpdates', label: 'Order updates', sub: 'Confirmed, being prepared, ready for pickup', provider: '' },
  { key: 'paymentUpdates', label: 'Payment & refund updates', sub: 'Payment confirmations and refunds', provider: '' },
  { key: 'promotions', label: 'Offers & promotions', sub: 'Deals and new restaurants on your routes', provider: '' },
  { key: 'email', label: 'Email notifications', sub: 'Receipts and important account emails', provider: 'Email provider = NOT STARTED' },
  { key: 'sms', label: 'SMS notifications', sub: 'Pickup codes and urgent updates', provider: 'SMS provider = NOT STARTED' },
]

const ago = (iso: string) => {
  const m = Math.round((Date.now() - new Date(iso).getTime()) / 60_000)
  if (m < 60) return `${Math.max(1, m)} min${m === 1 ? '' : 's'} ago`
  const h = Math.round(m / 60)
  if (h < 24) return `${h} hour${h === 1 ? '' : 's'} ago`
  const dd = Math.round(h / 24)
  return `${dd} day${dd === 1 ? '' : 's'} ago`
}

export default function NotificationsPage() {
  const { notifications, unreadCount, markRead, markAllRead, notificationPrefs, updateNotificationPrefs } = useAccount()
  const toast = useToast()
  const [tab, setTab] = useState<'all' | NotificationKind>('all')
  const [showPrefs, setShowPrefs] = useState(false)
  const [busyAll, setBusyAll] = useState(false)
  const [savingPref, setSavingPref] = useState<string | null>(null)
  const visible = notifications.data.filter((n) => tab === 'all' || n.kind === tab)

  const read = (id: string) => markRead(id).catch(() => toast.error('Could not update the notification'))
  const allRead = async () => { setBusyAll(true); try { await markAllRead(); toast.success('All notifications marked as read') } catch { toast.error('Could not mark all as read') } finally { setBusyAll(false) } }
  const togglePref = async (key: keyof NotificationPreferences, value: boolean) => {
    setSavingPref(key)
    try { await updateNotificationPrefs({ [key]: value }); toast.success('Preference saved') } catch { toast.error('Could not save the preference') } finally { setSavingPref(null) }
  }

  return (
    <>
      <Header />
      <main id="main" className="ac">
        <div className="ac__grid">
          <AccountSidebar />
          <div className="ac__main">
            <div className="ac-head">
              <div><h1>Notifications</h1><p>Stay updated with your orders, offers and more</p></div>
              <div className="nt-head__actions">
                <button type="button" className="ac-link-btn" aria-expanded={showPrefs} aria-controls="nt-prefs" onClick={() => setShowPrefs((v) => !v)}>Preferences</button>
                {unreadCount > 0 && <button type="button" className="ac-link-btn" onClick={allRead} disabled={busyAll}>{busyAll ? 'Updating…' : 'Mark all as read'}</button>}
              </div>
            </div>

            {showPrefs && (
              <section id="nt-prefs" className="ac-card nt-prefs" aria-label="Notification preferences">
                <h2>Notification preferences</h2>
                {notificationPrefs.status === 'error' && <ErrorState message={notificationPrefs.error ?? 'Failed to load preferences.'} onRetry={notificationPrefs.reload} />}
                {notificationPrefs.status !== 'error' && !notificationPrefs.data && <LoadingState label="Loading preferences" rows={2} />}
                {notificationPrefs.data && (
                  <ul className="nt-prefs__list">
                    {PREFS.map((p) => (
                      <li key={p.key}>
                        <span><b>{p.label}</b><small>{p.sub}{p.provider && <> · <em>{p.provider}</em></>}</small></span>
                        <label className="pf-toggle nt-toggle"><input type="checkbox" checked={notificationPrefs.data![p.key]} disabled={savingPref === p.key} onChange={(e) => togglePref(p.key, e.target.checked)} aria-label={p.label} /><i aria-hidden="true" /></label>
                      </li>
                    ))}
                  </ul>
                )}
                <p className="ac-note">Delivery of push, SMS and email is connected in later modules; your choices are saved now.</p>
              </section>
            )}

            <div className="nt-tabs" role="tablist">
              {TABS.map((t) => {
                const Icon = t.id === 'orders' ? ICON.bag : t.id === 'offers' ? ICON.tag : t.id === 'updates' ? ICON.bell : null
                return <button key={t.id} type="button" role="tab" aria-selected={tab === t.id} className={tab === t.id ? 'is-on' : ''} onClick={() => setTab(t.id)}>{Icon && <Icon size={16} />} {t.label}</button>
              })}
            </div>

            {notifications.status === 'loading' || notifications.status === 'idle' ? <LoadingState label="Loading notifications" rows={4} /> : null}
            {notifications.status === 'error' && <ErrorState message={notifications.error ?? 'Failed to load notifications.'} onRetry={notifications.reload} />}
            {notifications.status === 'ready' && visible.length === 0 && (
              <EmptyState icon={<ICON.bell size={32} />} title="You're all caught up" text={tab === 'all' ? 'No notifications yet.' : 'No notifications in this category.'} />
            )}
            {notifications.status === 'ready' && visible.length > 0 && (
              <ul className="nt-list">
                {visible.map((n) => {
                  const Icon = ICON[n.icon]
                  const body = (
                    <>
                      <span className={`nt__icon nt__icon--${TONE[n.icon]}`}><Icon /></span>
                      <span className="nt__text"><b>{n.title}</b>{n.text}</span>
                      <span className="nt__when">{ago(n.at)}</span>
                      {!n.read && <i className="nt__dot" aria-label="Unread" />}
                    </>
                  )
                  return (
                    <li key={n.id} className={`nt ${n.read ? '' : 'is-unread'}`}>
                      {n.link
                        ? <Link to={n.link} onClick={() => { void read(n.id) }}>{body}</Link>
                        : <button type="button" onClick={() => { void read(n.id) }} aria-label={`${n.title}. ${n.read ? 'Read' : 'Mark as read'}`}>{body}</button>}
                    </li>
                  )
                })}
              </ul>
            )}
          </div>
        </div>
      </main>
    </>
  )
}
