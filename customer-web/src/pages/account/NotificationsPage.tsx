import { useState, type ReactElement } from 'react'
import { Link } from 'react-router-dom'
import Header from '../../components/Header'
import AccountSidebar from '../../components/AccountSidebar'
import { useAccount, type Notification, type NotificationKind } from '../../account/AccountContext'
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

const ago = (d: Date) => {
  const m = Math.round((Date.now() - d.getTime()) / 60_000)
  if (m < 60) return `${Math.max(1, m)} min${m === 1 ? '' : 's'} ago`
  const h = Math.round(m / 60)
  if (h < 24) return `${h} hour${h === 1 ? '' : 's'} ago`
  const dd = Math.round(h / 24)
  return `${dd} day${dd === 1 ? '' : 's'} ago`
}

export default function NotificationsPage() {
  const { notifications, unreadCount, markRead, markAllRead } = useAccount()
  const [tab, setTab] = useState<'all' | NotificationKind>('all')
  const visible = notifications.filter((n) => tab === 'all' || n.kind === tab)

  return (
    <>
      <Header />
      <main id="main" className="ac">
        <div className="ac__grid">
          <AccountSidebar />
          <div className="ac__main">
            <div className="ac-head">
              <div><h1>Notifications</h1><p>Stay updated with your orders, offers and more</p></div>
              {unreadCount > 0 && <button type="button" className="ac-link-btn" onClick={markAllRead}>Mark all as read</button>}
            </div>

            <div className="nt-tabs" role="tablist">
              {TABS.map((t) => {
                const Icon = t.id === 'orders' ? ICON.bag : t.id === 'offers' ? ICON.tag : t.id === 'updates' ? ICON.bell : null
                return <button key={t.id} type="button" role="tab" aria-selected={tab === t.id} className={tab === t.id ? 'is-on' : ''} onClick={() => setTab(t.id)}>{Icon && <Icon size={16} />} {t.label}</button>
              })}
            </div>

            {visible.length === 0 ? (
              <div className="ac-card ac-empty"><span aria-hidden="true">🔔</span><h3>You're all caught up</h3><p>No notifications in this category.</p></div>
            ) : (
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
                        ? <Link to={n.link} onClick={() => markRead(n.id)}>{body}</Link>
                        : <button type="button" onClick={() => markRead(n.id)}>{body}</button>}
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
