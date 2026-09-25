import { useEffect } from 'react'
import { Link } from 'react-router-dom'
import Header from '../components/Header'
import { ArrowRightIcon, PinIcon } from '../components/Icons'
import { useNotFoundContent } from '../repositories'
import './NotFoundPage.css'

/** Branded 404 for unknown routes. `data-page="not-found"` lets route/link tests detect it. */
export default function NotFoundPage() {
  const c = useNotFoundContent()
  useEffect(() => { document.title = `${c.title} · FoodOnTheGo` }, [c.title])
  return (
    <>
      <Header />
      <main id="main" className="nf" data-page="not-found">
        <div className="nf__card">
          <span className="nf__pin" aria-hidden="true"><PinIcon size={40} /></span>
          <p className="nf__code" aria-hidden="true">{c.code}</p>
          <h1>{c.title}</h1>
          <p className="nf__text">{c.text}</p>
          <div className="nf__actions">
            {c.actions.map((a, i) => (
              <Link key={a.to} to={a.to} className={`btn btn--lg ${i === 0 ? 'btn--primary' : 'btn--outline'}`}>
                {a.label}{i === 0 && <ArrowRightIcon size={18} />}
              </Link>
            ))}
          </div>
        </div>
      </main>
    </>
  )
}
