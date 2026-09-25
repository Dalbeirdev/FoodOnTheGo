import { Link } from 'react-router-dom'
import Header from '../components/Header'
import { ArrowRightIcon } from '../components/Icons'
import PublicIcon from '../components/PublicIcons'
import { contentRepository } from '../repositories'
import './ForRestaurantsPage.css'

const fr = contentRepository.getForRestaurantsContent()
const BENEFITS = fr.benefits
const STEPS = fr.steps.map((s) => [s.title, s.text] as const)

export default function ForRestaurantsPage() {
  return (
    <>
      <Header />
      <main id="main" className="fr">
        <section className="fr-hero">
          <div className="fr-hero__inner">
            <div className="fr-hero__content">
              <h1>{fr.hero.title} <span>{fr.hero.accent}</span></h1>
              <p>{fr.hero.lead}</p>
              <div className="fr-hero__cta">
                <Link to={fr.hero.primaryCta.to} className="btn btn--primary btn--lg">{fr.hero.primaryCta.label}</Link>
                <a href={fr.hero.secondaryCta.to} className="btn btn--outline btn--lg">{fr.hero.secondaryCta.label}</a>
              </div>
            </div>
            <div className="fr-hero__visual" aria-hidden="true">
              <img src="/images/for-restaurants-hero.jpg" alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} />
              <div className="fr-hero__tablet">
                <div className="fr-hero__screen">
                  <b>Orders Today</b>
                  <div className="fr-hero__stats"><span><i>24</i>Pickups</span><span><i>₹9,860</i>Revenue</span><span><i>4.6★</i>Rating</span></div>
                  <ul>{['Classic Combo · 12:45', 'Veg Delight · 12:50', 'BBQ Bacon Burger · 1:05', 'Family Pack · 1:20'].map((l) => <li key={l}>{l}<em>Ready</em></li>)}</ul>
                </div>
              </div>
            </div>
          </div>
        </section>

        <section className="fr-benefits" id="how">
          <ul>
            {BENEFITS.map(({ icon, title, text }) => (
              <li key={title}><span className="fr-benefit__icon"><PublicIcon name={icon} size={26} /></span><b>{title}</b><p>{text}</p></li>
            ))}
          </ul>
        </section>

        <section className="fr-steps">
          <p className="fr-eyebrow">How it works for partners</p>
          <h2>Up and running in four steps</h2>
          <ol>
            {STEPS.map(([t, d], i) => <li key={t}><span>{i + 1}</span><b>{t}</b><p>{d}</p></li>)}
          </ol>
        </section>

        <section className="fr-cta">
          <div className="fr-cta__inner">
            <h2>{fr.closing.title}</h2>
            <p>{fr.closing.text}</p>
            <Link to={fr.closing.cta.to} className="btn btn--primary btn--lg">{fr.closing.cta.label} <ArrowRightIcon size={18} /></Link>
          </div>
        </section>
      </main>
    </>
  )
}
