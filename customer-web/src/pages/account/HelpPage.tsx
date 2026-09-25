import { useState } from 'react'
import { Link } from 'react-router-dom'
import Header from '../../components/Header'
import AccountSidebar from '../../components/AccountSidebar'
import { ChevronRightIcon, SearchIcon } from '../../components/Icons'
import PublicIcon from '../../components/PublicIcons'
import { contentRepository } from '../../repositories'
import './AccountPage.css'
import './HelpPage.css'

const help = contentRepository.getHelpContent()
const TOPICS = help.topics

export default function HelpPage() {
  const [query, setQuery] = useState('')
  const [notice, setNotice] = useState('')
  const q = query.trim().toLowerCase()
  const topics = q ? TOPICS.filter((t) => `${t.title} ${t.text} ${t.keywords}`.toLowerCase().includes(q)) : TOPICS

  return (
    <>
      <Header />
      <main id="main" className="ac">
        <div className="ac__grid">
          <AccountSidebar />
          <div className="ac__main">
            <div className="ac-head"><div><h1>{help.title}</h1><p>{help.sub}</p></div></div>

            <label className="hp-search">
              <SearchIcon size={20} />
              <input type="search" value={query} placeholder={help.searchPlaceholder} onChange={(e) => setQuery(e.target.value)} aria-label="Search help" />
            </label>

            <section>
              <h2 className="hp-title">Popular Topics</h2>
              {topics.length === 0 ? (
                <div className="ac-card ac-empty"><span aria-hidden="true">🔍</span><h3>No topics match "{query}"</h3><p>Try another word, or contact support below.</p></div>
              ) : (
                <ul className="hp-topics">
                  {topics.map(({ icon, title, text, to }) => (
                    <li key={title}>{to.startsWith('#') ? <a href={to}><span className="hp-topic__icon"><PublicIcon name={icon} /></span><span><b>{title}</b>{text}</span></a> : <Link to={to}><span className="hp-topic__icon"><PublicIcon name={icon} /></span><span><b>{title}</b>{text}</span></Link>}</li>
                  ))}
                </ul>
              )}
            </section>

            <section id="contact">
              <h2 className="hp-title">Contact Support</h2>
              <ul className="hp-contact">
                {help.contacts.map((c) => (
                  <li key={c.kind}>
                    {c.backendRequired ? (
                      // BACKEND CONNECTION = NOT STARTED: shown, but never pretends to connect.
                      <button type="button" className="hp-contact__pending" aria-describedby={`contact-note-${c.kind}`} onClick={() => setNotice(`${c.title} will be available once the support system is connected. Please email or call us for now.`)}>
                        <span className="hp-contact__icon"><PublicIcon name={c.icon} /></span><span><b>{c.title}</b>{c.detail}<small id={`contact-note-${c.kind}`}>{c.note}</small></span><ChevronRightIcon />
                      </button>
                    ) : (
                      <a href={c.href}><span className="hp-contact__icon"><PublicIcon name={c.icon} /></span><span><b>{c.title}</b>{c.detail}{c.note && <small>{c.note}</small>}</span><ChevronRightIcon /></a>
                    )}
                  </li>
                ))}
              </ul>
              {notice && <p className="hp-notice" role="status">{notice}</p>}
            </section>
          </div>
        </div>
      </main>
    </>
  )
}
