import { Link } from 'react-router-dom'
import type { StoreBadge } from '../repositories'
import { AppleIcon, GooglePlayIcon } from './Icons'
import './StoreBadges.css'

/**
 * App-store badges driven by content status:
 *  - live        → external store link (not used yet; no production app exists)
 *  - placeholder → routes to /get-app (Android landing) until the Play Store link exists
 *  - deferred    → rendered as a non-interactive "coming later" state (iOS is deferred)
 */
export default function StoreBadges({ stores, size = 28 }: { stores: StoreBadge[]; size?: number }) {
  return (
    <div className="store-badges">
      {stores.map((s) => {
        const icon = s.store === 'google-play' ? <GooglePlayIcon size={size} /> : <AppleIcon size={size} />
        const body = (<>{icon}<span><small>{s.sub}</small><strong>{s.label}</strong></span></>)
        if (s.status === 'deferred') {
          return <span key={s.store} className="store-badge store-badge--deferred" aria-label={`${s.label}: coming later`} title="Coming later">{body}</span>
        }
        if (s.status === 'live' && s.to) {
          return <a key={s.store} href={s.to} className="store-badge" target="_blank" rel="noreferrer">{body}</a>
        }
        return <Link key={s.store} to={s.to ?? '/get-app'} className="store-badge" aria-label={`${s.label}: ${s.sub}`}>{body}</Link>
      })}
    </div>
  )
}
