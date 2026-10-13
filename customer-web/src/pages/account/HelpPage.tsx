import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../auth/AuthContext'
import { t, useLocale } from '../../i18n/strings'
import { newCaseLink, useSupport } from '../../support/supportRepositories'
import Header from '../../components/Header'
import AccountSidebar from '../../components/AccountSidebar'
import { ChevronRightIcon, SearchIcon } from '../../components/Icons'
import PublicIcon from '../../components/PublicIcons'
import { contentRepository } from '../../repositories'
import './AccountPage.css'
import './HelpPage.css'
import '../../support/Support.css'

const help = contentRepository.getHelpContent()
const TOPICS = help.topics

export default function HelpPage() {
  const [query, setQuery] = useState('')
  const [notice, setNotice] = useState('')
  const q = query.trim().toLowerCase()
  const topics = q ? TOPICS.filter((x) => `${x.title} ${x.text} ${x.keywords}`.toLowerCase().includes(q)) : TOPICS
  // Module 37: with the support backend a signed-in customer opens and follows cases from here; a guest is asked to
  // sign in (a case always belongs to an account). Without the backend the page is what it always was.
  const { repository } = useSupport()
  const auth = useAuth()
  const { locale } = useLocale()
  const signedIn = auth.isAuthenticated
  const [unread, setUnread] = useState(0)
  useEffect(() => {
    if (!repository || !signedIn) return
    let on = true
    repository.list({ state: 'all', page: 1, pageSize: 1 }).then((p) => { if (on) setUnread(p.unread) }).catch(() => { /* the count is a convenience: the list itself says what is unread */ })
    return () => { on = false }
  }, [repository, signedIn])

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

            {repository && !auth.loading && (
              <section className="ac-card" aria-labelledby="hp-support-title" data-testid="help-support" data-signed-in={signedIn ? 'true' : 'false'}>
                <h2 id="hp-support-title">{t('sup.help.title', undefined, locale)}</h2>
                {signedIn ? (
                  <>
                    <p className="sup-muted">{t('sup.help.text', undefined, locale)}</p>
                    <div className="sup-actions">
                      <Link to={newCaseLink()} className="btn btn--primary" data-testid="help-contact-support">{t('sup.help.contact', undefined, locale)}</Link>
                      <Link to="/help/cases" className="btn btn--outline" data-testid="help-my-cases">
                        {t('sup.help.myCases', undefined, locale)}
                        {unread > 0 && <span className="sup-count" data-testid="help-cases-unread" aria-label={t('sup.help.unread', { n: unread }, locale)}>{unread}</span>}
                      </Link>
                    </div>
                  </>
                ) : (
                  <>
                    <p className="sup-muted">{t('sup.help.signIn.text', undefined, locale)}</p>
                    <div className="sup-actions"><Link to="/login" state={{ from: newCaseLink() }} className="btn btn--primary" data-testid="help-sign-in">{t('sup.help.signIn.action', undefined, locale)}</Link></div>
                  </>
                )}
              </section>
            )}

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
