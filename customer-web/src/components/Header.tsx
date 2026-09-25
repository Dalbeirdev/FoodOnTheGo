import { useEffect, useId, useRef, useState } from 'react'
import { Link, NavLink } from 'react-router-dom'
import { useSiteNavigation } from '../repositories'
import { useAuth } from '../auth/AuthContext'
import './Header.css'

const UserIcon = ({ size = 22 }: { size?: number }) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    <circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" />
  </svg>
)

/** Global public header: logo, primary navigation, Login, Get the App, and the mobile menu. */
export default function Header() {
  const nav = useSiteNavigation()
  const { isAuthenticated, user } = useAuth()
  const secondary = isAuthenticated ? nav.accountLink : nav.secondaryAction
  const [open, setOpen] = useState(false)
  const menuId = useId()
  const burgerRef = useRef<HTMLButtonElement>(null)

  // Close the mobile menu on Escape (links close it on click); return focus to the toggle.
  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') { setOpen(false); burgerRef.current?.focus() }
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open])

  return (
    <header className="header">
      <a href="#main" className="skip-link">Skip to content</a>
      <div className="header__inner">
        <Link to="/" className="header__brand" aria-label="FoodOnTheGo home">
          <img src="/brand/foodonthego-logo-no-tagline.svg" alt="FoodOnTheGo" className="header__logo" width="620" height="125" />
        </Link>

        <nav id={menuId} className={`header__nav ${open ? 'is-open' : ''}`} aria-label="Main">
          {nav.primary.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              className={({ isActive }) => `header__link ${isActive ? 'is-active' : ''}`}
              onClick={() => setOpen(false)}
            >
              {item.label}
            </NavLink>
          ))}
          <div className="header__nav-actions">
            <Link to={secondary.to} className="btn btn--outline" onClick={() => setOpen(false)}>{secondary.label}</Link>
            {!isAuthenticated && <Link to={nav.accountLink.to} className="btn btn--outline" onClick={() => setOpen(false)}>{nav.accountLink.label}</Link>}
          </div>
        </nav>

        <div className="header__actions">
          <Link to={nav.accountLink.to} className="header__account" aria-label={user ? `${nav.accountLink.label}: ${user.name}` : nav.accountLink.label} title={user?.name ?? nav.accountLink.label}>
            <UserIcon />
          </Link>
          <Link to={secondary.to} className="btn btn--outline header__login">{secondary.label}</Link>
          <Link to={nav.primaryAction.to} className="btn btn--primary">{nav.primaryAction.label}</Link>
          <button
            ref={burgerRef}
            type="button"
            className={`header__burger ${open ? 'is-open' : ''}`}
            aria-label={open ? 'Close menu' : 'Open menu'}
            aria-expanded={open}
            aria-controls={menuId}
            onClick={() => setOpen((v) => !v)}
          >
            <span />
            <span />
            <span />
          </button>
        </div>
      </div>
    </header>
  )
}
