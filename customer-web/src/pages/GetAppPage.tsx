import { useEffect } from 'react'
import Header from '../components/Header'
import PublicIcon from '../components/PublicIcons'
import StoreBadges from '../components/StoreBadges'
import { useGetAppContent } from '../repositories'
import './GetAppPage.css'

/**
 * "Get the App" landing (CF-016). Android is coming; iOS is deferred.
 * No live store links exist yet — badges are placeholder/deferred states, never fake downloads.
 */
export default function GetAppPage() {
  const c = useGetAppContent()
  useEffect(() => { document.title = 'Get the App · FoodOnTheGo' }, [])
  return (
    <>
      <Header />
      <main id="main" className="ga">
        <section className="ga-hero">
          <div className="ga-hero__inner">
            <div className="ga-hero__content">
              <p className="ga-eyebrow">Get the app</p>
              <h1>{c.title} <span className="ga-accent">{c.accent}</span></h1>
              <p className="ga-lead">{c.lead}</p>
              <StoreBadges stores={c.stores} />
              <p className="ga-note" role="note">The Android app is in local review and will be published to Google Play when it is production-ready. iOS is planned for a later release.</p>
            </div>
            <div className="ga-hero__visual" aria-hidden="true">
              <div className="ga-phone">
                <div className="ga-phone__screen">
                  <img src="/brand/foodonthego-icon.svg" alt="" width="72" height="72" />
                  <b>FoodOnTheGo</b>
                  <span>Order • Pickup • On your route</span>
                </div>
              </div>
            </div>
          </div>
        </section>

        <section className="ga-highlights" aria-label="App highlights">
          <ul>
            {c.highlights.map((h) => (
              <li key={h.title}>
                <span className="ga-highlight__icon"><PublicIcon name={h.icon} size={26} /></span>
                <b>{h.title}</b>
                <p>{h.text}</p>
              </li>
            ))}
          </ul>
        </section>
      </main>
    </>
  )
}
