import { Link, useParams } from 'react-router-dom'
import Header from '../components/Header'
import { ChevronRightIcon } from '../components/Icons'
import { LEGAL_DOCS } from '../data/legal'
import './LegalPage.css'

const NAV = [['Terms & Conditions', '/terms'], ['Privacy Policy', '/privacy'], ['Refund & Cancellation Policy', '/refund-policy'], ['Cookie Policy', '/cookie-policy']]

export default function LegalPage({ slug: slugProp }: { slug?: string }) {
  const params = useParams()
  const slug = slugProp ?? params.slug ?? 'terms'
  const doc = LEGAL_DOCS[slug]

  if (!doc) {
    return (
      <>
        <Header />
        <main id="main" className="lg lg--missing"><div className="lg-card"><h1>Page not found</h1><p>This policy doesn't exist.</p><Link to="/" className="btn btn--primary">Back to Home</Link></div></main>
      </>
    )
  }

  return (
    <>
      <Header />
      <main id="main" className="lg">
        <section className="lg-hero">
          <div className="lg-hero__inner">
            <nav className="lg-crumbs" aria-label="Breadcrumb"><Link to="/">Home</Link><ChevronRightIcon size={14} /><span>Legal</span><ChevronRightIcon size={14} /><span>{doc.title}</span></nav>
            <h1>{doc.title}</h1>
            <p>{doc.intro}</p>
            <span className="lg-updated">Last updated: {doc.lastUpdated}</span>
          </div>
        </section>

        <div className="lg__grid">
          <aside className="lg-side">
            <h2>Legal</h2>
            <ul>{NAV.map(([label, to]) => <li key={to}><Link to={to} className={to === `/${doc.slug}` ? 'is-on' : ''}>{label}</Link></li>)}</ul>
            <h2>On this page</h2>
            <ol>{doc.sections.map((s, i) => <li key={s.heading}><a href={`#s${i + 1}`}>{s.heading}</a></li>)}</ol>
          </aside>

          <article className="lg-doc">
            <div className="lg-draft" role="note">
              <b>Draft — not yet in effect.</b> This page shows the approved layout and section structure only. Final wording is pending business and legal approval and will replace this text before release.
            </div>
            {doc.sections.map((s, i) => (
              <section key={s.heading} id={`s${i + 1}`}>
                <h2>{s.heading}</h2>
                {s.body.map((p, j) => <p key={j} className={p.startsWith('Final wording') ? 'lg-pending' : ''}>{p}</p>)}
              </section>
            ))}
            <p className="lg-contact">Questions? Visit <Link to="/help">Help &amp; Support</Link>.</p>
          </article>
        </div>
      </main>
    </>
  )
}
