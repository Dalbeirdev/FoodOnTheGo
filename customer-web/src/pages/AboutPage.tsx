import Header from '../components/Header'
import PublicIcon from '../components/PublicIcons'
import { contentRepository } from '../repositories'
import './AboutPage.css'

const about = contentRepository.getAboutContent()
const STATS = about.stats.items
const VALUES = about.values
const STORY = about.story.timeline

function Photo({ src, className, fallback }: { src: string; className: string; fallback: string }) {
  return (
    <div className={`about-photo ${className}`} aria-hidden="true">
      <img src={src} alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} />
      <span className="about-photo__fallback">{fallback}</span>
    </div>
  )
}

export default function AboutPage() {
  return (
    <>
      <Header />
      <main id="main" className="about">
        {/* ---------- Hero ---------- */}
        <section className="about-hero">
          <Photo src="/images/about-hero.jpg" className="about-hero__bg" fallback="" />
          <div className="about-hero__fade" aria-hidden="true" />

          <div className="about-hero__inner">
            <div className="about-hero__content">
              <p className="about-eyebrow">{about.hero.eyebrow}</p>
              <h1 className="about-hero__title">
                <span>{about.hero.title}</span>
                <span className="about-accent">{about.hero.accent}</span>
              </h1>
              <p className="about-hero__lead" dangerouslySetInnerHTML={{ __html: about.hero.leadHtml }} />
            </div>

            <p className="about-hero__script" aria-hidden="true">{about.hero.script}</p>

            <ul className="about-hero__pills">
              {about.hero.pills.map((p) => (
                <li key={p.title}><span className={`pill-icon pill-icon--${p.tone}`}><PublicIcon name={p.icon} size={22} /></span><span><strong>{p.title}</strong>{p.sub}</span></li>
              ))}
            </ul>
          </div>
        </section>

        {/* ---------- Stats ---------- */}
        <section className="about-stats" aria-label="FoodOnTheGo in numbers">
          <div className="about-stats__card">
            {STATS.map(({ icon, tone, value, label }) => (
              <div key={label} className="about-stat">
                <span className={`about-stat__icon tone-${tone}`}><PublicIcon name={icon} size={30} /></span>
                <div>
                  <strong>{value}</strong>
                  <span>{label}</span>
                </div>
              </div>
            ))}
          </div>
          {about.stats.illustrative && <p className="about-stats__note">Illustrative figures from the approved design — final numbers pending business confirmation.</p>}
        </section>

        {/* ---------- Mission / Vision / Values ---------- */}
        <section className="about-mvv">
          <article className="mvv-card mvv-card--mission">
            <div className="mvv-card__head">
              <span className="mvv-card__icon tone-orange"><PublicIcon name="target" size={30} /></span>
              <div>
                <h2>Our Mission</h2>
                <p>{about.mission}</p>
              </div>
            </div>
            <Photo src="/images/about-mission.jpg" className="mvv-card__photo" fallback="🛣️" />
          </article>

          <article className="mvv-card mvv-card--vision">
            <div className="mvv-card__head">
              <span className="mvv-card__icon tone-blue"><PublicIcon name="eye" size={30} /></span>
              <div>
                <h2>Our Vision</h2>
                <p>{about.vision}</p>
              </div>
            </div>
            <Photo src="/images/about-vision.jpg" className="mvv-card__photo" fallback="🏙️" />
          </article>

          <article className="mvv-card mvv-card--values">
            <div className="mvv-card__head">
              <span className="mvv-card__icon tone-green"><PublicIcon name="gem" size={30} /></span>
              <div>
                <h2>Our Values</h2>
                <ul className="values-list">
                  {VALUES.map(({ icon, tone, title, text }) => (
                    <li key={title}>
                      <span className={`values-list__icon tone-${tone}`}><PublicIcon name={icon} size={18} /></span>
                      <span><strong>{title}</strong>{text}</span>
                    </li>
                  ))}
                </ul>
              </div>
            </div>
          </article>
        </section>

        {/* ---------- Story ---------- */}
        <section className="about-story">
          <div className="about-story__intro">
            <p className="about-eyebrow">{about.story.eyebrow}</p>
            <h2>{about.story.title.split('\n').map((line, i) => <span key={i}>{i > 0 && <br />}{line}</span>)}</h2>
            <p>{about.story.intro}</p>
          </div>

          <ol className="about-timeline">
            {STORY.map(({ icon, title, text }, i) => (
              <li key={title}>
                <span className="about-timeline__icon"><PublicIcon name={icon} size={22} /></span>
                {i < STORY.length - 1 && <span className="about-timeline__line" aria-hidden="true" />}
                <h3>{title}</h3>
                <p>{text}</p>
              </li>
            ))}
          </ol>

          <Photo src="/images/about-story.jpg" className="about-story__photo" fallback="🚙" />
        </section>
      </main>
    </>
  )
}
