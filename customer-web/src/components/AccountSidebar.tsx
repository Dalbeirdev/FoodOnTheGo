import type { ReactElement } from 'react'
import { NavLink } from 'react-router-dom'
import LogoutButton from '../auth/LogoutButton'
import { initials, useProfile } from '../profile/ProfileContext'
import { useAccount } from '../account/AccountContext'
import './AccountSidebar.css'

type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const UserIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" /></svg>)
const OrdersIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M6 3h12v18l-3-2-3 2-3-2-3 2z" /><path d="M9 8h6M9 12h6" /></svg>)
const HeartIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M12 21s-7.5-4.6-9.5-9.3C1 8 3.5 4.5 7 4.5c2 0 3.5 1 5 2.8 1.5-1.8 3-2.8 5-2.8 3.5 0 6 3.5 4.5 7.2C19.5 16.4 12 21 12 21Z" /></svg>)
const PinIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M12 22s7-7.75 7-13a7 7 0 0 0-14 0c0 5.25 7 13 7 13Z" /><circle cx="12" cy="9" r="2.5" /></svg>)
const CardIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 10h18M7 15h4" /></svg>)
const BellIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4l2-2ZM10 20a2 2 0 0 0 4 0" /></svg>)
const RouteIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><circle cx="6" cy="18" r="2.5" /><circle cx="18" cy="6" r="2.5" /><path d="M8.5 18H14a3 3 0 0 0 0-6h-4a3 3 0 0 1 0-6h5.5" /></svg>)
const HeadsetIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M4 13v-2a8 8 0 0 1 16 0v2" /><rect x="3" y="13" width="4" height="6" rx="1.5" /><rect x="17" y="13" width="4" height="6" rx="1.5" /><path d="M19 19a3 3 0 0 1-3 2h-2" /></svg>)
const LogoutIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M10 4H5v16h5M14 8l4 4-4 4M8 12h10" /></svg>)

const NAV: Array<{ to: string; label: string; icon: (p: P) => ReactElement; badge?: number }> = [
  { to: '/my-profile', label: 'My Profile', icon: UserIcon },
  { to: '/favorites', label: 'Favorite Restaurants', icon: HeartIcon },
  { to: '/addresses', label: 'Saved Addresses', icon: PinIcon },
  { to: '/payment-methods', label: 'Payment Methods', icon: CardIcon },
  { to: '/notifications', label: 'Notifications', icon: BellIcon },
  { to: '/my-orders', label: 'My Orders', icon: OrdersIcon },
  { to: '/plan-journey', label: 'Plan a Journey', icon: RouteIcon },
  { to: '/help', label: 'Help & Support', icon: HeadsetIcon },
]

export default function AccountSidebar() {
  const { profile } = useProfile()
  const { unreadCount } = useAccount()
  return (
    <aside className="acct">
      <div className="acct__user">
        {profile?.avatarUrl ? <img className="acct__avatar acct__avatar--img" src={profile.avatarUrl} alt="" /> : <span className="acct__avatar">{initials(profile?.name ?? '')}</span>}
        <span><b>{profile?.name ?? '…'}</b>{profile?.email || profile?.phone || ''}</span>
      </div>
      <nav aria-label="Account">
        {NAV.map(({ to, label, icon: Icon, badge }) => (
          <NavLink key={to} to={to} className={({ isActive }) => `acct__link ${isActive ? 'is-active' : ''}`}><Icon /> {label}{(badge ?? (to === '/notifications' ? unreadCount : 0)) ? <span className="acct__badge">{badge ?? unreadCount}</span> : null}</NavLink>
        ))}
        <span className="acct__rule" />
        <LogoutButton className="acct__link"><LogoutIcon /> Logout</LogoutButton>
      </nav>
    </aside>
  )
}
