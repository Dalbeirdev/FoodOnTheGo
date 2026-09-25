import { useEffect } from 'react'
import { Link } from 'react-router-dom'
import Header from '../components/Header'
import { ArrowRightIcon } from '../components/Icons'
import './NotFoundPage.css'

/**
 * Interim shell for routes whose real page is scheduled for a later module
 * (e.g. restaurant registration, CF-015). Keeps navigation free of dead links
 * without inventing an unapproved design or a fake backend flow.
 */
export default function ComingSoonPage({ title, text, module, backTo = '/', backLabel = 'Return Home' }: { title: string; text: string; module: string; backTo?: string; backLabel?: string }) {
  useEffect(() => { document.title = `${title} · FoodOnTheGo` }, [title])
  return (
    <>
      <Header />
      <main id="main" className="nf" data-page="coming-soon">
        <div className="nf__card">
          <p className="ga-eyebrow" style={{ color: 'var(--orange-deep)', letterSpacing: 3, fontWeight: 700, fontSize: 13, textTransform: 'uppercase', margin: '0 0 8px' }}>Coming soon</p>
          <h1>{title}</h1>
          <p className="nf__text">{text}</p>
          <p className="nf__text" style={{ fontSize: 14 }}>Planned for {module}. Until then, reach us via <Link to="/help">Help &amp; Support</Link>.</p>
          <div className="nf__actions">
            <Link to={backTo} className="btn btn--lg btn--primary">{backLabel}<ArrowRightIcon size={18} /></Link>
            <Link to="/help" className="btn btn--lg btn--outline">Contact Us</Link>
          </div>
        </div>
      </main>
    </>
  )
}
