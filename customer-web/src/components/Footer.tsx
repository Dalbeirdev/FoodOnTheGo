import { Link } from 'react-router-dom'
import { useSiteNavigation } from '../repositories'
import { GooglePlayIcon } from './Icons'
import './Footer.css'

const SOCIAL_PATHS: Record<string, string> = {
  Facebook: 'M13.5 22v-8h2.7l.4-3.2h-3.1V8.8c0-.9.3-1.6 1.6-1.6h1.7V4.4c-.3 0-1.3-.1-2.5-.1-2.5 0-4.1 1.5-4.1 4.2v2.3H7.4V14h2.8v8h3.3Z',
  Instagram: 'M12 7.3a4.7 4.7 0 1 0 0 9.4 4.7 4.7 0 0 0 0-9.4Zm0 7.7a3 3 0 1 1 0-6 3 3 0 0 1 0 6Zm5.2-8.9a1.1 1.1 0 1 1-2.2 0 1.1 1.1 0 0 1 2.2 0ZM12 3c-2.4 0-2.7 0-3.7.1-2.5.1-4.1 1.7-4.2 4.2C4 8.3 4 8.6 4 12s0 3.7.1 4.7c.1 2.5 1.7 4.1 4.2 4.2 1 .1 1.3.1 3.7.1s2.7 0 3.7-.1c2.5-.1 4.1-1.7 4.2-4.2.1-1 .1-1.3.1-4.7s0-3.7-.1-4.7c-.1-2.5-1.7-4.1-4.2-4.2C14.7 3 14.4 3 12 3Zm0 1.6c2.4 0 2.6 0 3.6.1 1.7.1 2.6 1 2.7 2.7.1 1 .1 1.2.1 3.6s0 2.6-.1 3.6c-.1 1.7-1 2.6-2.7 2.7-1 .1-1.2.1-3.6.1s-2.6 0-3.6-.1c-1.7-.1-2.6-1-2.7-2.7-.1-1-.1-1.2-.1-3.6s0-2.6.1-3.6c.1-1.7 1-2.6 2.7-2.7 1-.1 1.2-.1 3.6-.1Z',
  X: 'M17.5 3h3l-6.8 7.8L21.7 21h-6.2l-4.9-6.4L5 21H2l7.3-8.3L1.6 3h6.4l4.4 5.8L17.5 3Zm-1.1 16.2h1.7L6.7 4.7H4.9l11.5 14.5Z',
  YouTube: 'M21.6 7.2a2.5 2.5 0 0 0-1.8-1.8C18.2 5 12 5 12 5s-6.2 0-7.8.4A2.5 2.5 0 0 0 2.4 7.2 26 26 0 0 0 2 12a26 26 0 0 0 .4 4.8 2.5 2.5 0 0 0 1.8 1.8C5.8 19 12 19 12 19s6.2 0 7.8-.4a2.5 2.5 0 0 0 1.8-1.8A26 26 0 0 0 22 12a26 26 0 0 0-.4-4.8ZM10 15V9l5.2 3L10 15Z',
}

/** Shared public footer. Social profiles render as pending (no approved URLs yet); iOS badge intentionally absent. */
export default function Footer() {
  const { footer, primaryAction } = useSiteNavigation()
  return (
    <footer className="footer">
      <div className="footer__inner">
        <div className="footer__brand">
          <img src="/brand/foodonthego-logo-dark-bg.svg" alt="FoodOnTheGo" width="620" height="125" />
          <p>{footer.tagline}</p>
          <ul className="footer__social" aria-label="Social profiles (coming soon)">
            {footer.social.map((s) => (
              <li key={s.name}>
                {s.href && !s.pending ? (
                  <a href={s.href} aria-label={s.name} target="_blank" rel="noreferrer"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d={SOCIAL_PATHS[s.name]} /></svg></a>
                ) : (
                  <span className="is-pending" role="img" title={`${s.name} — coming soon`} aria-label={`${s.name} (coming soon)`}><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d={SOCIAL_PATHS[s.name]} /></svg></span>
                )}
              </li>
            ))}
          </ul>
        </div>
        <div className="footer__col">
          <h3>Quick Links</h3>
          <ul>{footer.quickLinks.map((l) => <li key={l.to}><Link to={l.to}>{l.label}</Link></li>)}</ul>
        </div>
        <div className="footer__col">
          <h3>Legal</h3>
          <ul>{footer.legal.map((l) => <li key={l.to}><Link to={l.to}>{l.label}</Link></li>)}</ul>
        </div>
        <div className="footer__col footer__app">
          <h3>Get the App</h3>
          <p>Download our Android app for a better experience.</p>
          <Link to={primaryAction.to} className="store-badge" aria-label="Google Play: coming soon"><GooglePlayIcon size={26} /><span><small>COMING SOON ON</small><strong>Google Play</strong></span></Link>
          {/* iOS is deferred — App Store badge intentionally omitted until approved. */}
        </div>
      </div>
      <div className="footer__bottom">
        <span>© {new Date().getFullYear()} FoodOnTheGo. All rights reserved.</span>
        <span>Made with <b aria-hidden="true">♥</b><span className="sr-only">love</span> for travellers</span>
      </div>
    </footer>
  )
}
