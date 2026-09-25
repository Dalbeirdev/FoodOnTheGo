import type { ReactNode } from 'react'
import Header from '../../components/Header'
import { BagIcon, RouteIcon, StoreIcon } from '../../components/Icons'
import '../AuthPage.css'

const FEATURES = [
  { icon: StoreIcon, label: 'Discover Restaurants' },
  { icon: RouteIcon, label: 'Order on Your Route' },
  { icon: BagIcon, label: 'Quick & Easy Pickup' },
]

/** Shared two-column shell for Login, OTP and Account Setup (approved Login design). */
export default function AuthLayout({ children, step }: { children: ReactNode; step: 1 | 2 | 3 }) {
  return (
    <>
      <Header />
      <main id="main" className="auth">
        <div className="auth__inner">
          <section className="auth__form" aria-labelledby="auth-title">
            <ol className="auth__steps" aria-label="Sign-in progress">
              {['Mobile number', 'Verify code', 'Your details'].map((label, i) => (
                <li key={label} className={i + 1 === step ? 'is-current' : i + 1 < step ? 'is-done' : ''} aria-current={i + 1 === step ? 'step' : undefined}><span>{i + 1}</span>{label}</li>
              ))}
            </ol>
            {children}
          </section>
          <aside className="auth__panel" aria-hidden="true">
            <img src="/images/auth-panel.jpg" alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} />
            <div className="auth__panel-fade" />
            <div className="auth__panel-content">
              <img src="/brand/foodonthego-logo-dark-bg.svg" alt="" className="auth__panel-logo" />
              <h2>Good Food<br />On Your Route</h2>
              <ul>{FEATURES.map(({ icon: Icon, label }) => <li key={label}><span><Icon /></span>{label}</li>)}</ul>
            </div>
          </aside>
        </div>
      </main>
    </>
  )
}
