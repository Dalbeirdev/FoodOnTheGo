import { useHomeContent } from '../repositories'
import './HowItWorks.css'

export default function HowItWorks() {
  const { howItWorks } = useHomeContent()
  return (
    <section className="how" id="how-it-works">
      <p className="how__eyebrow">{howItWorks.eyebrow}</p>
      <h2 className="how__title">{howItWorks.title}</h2>

      <ol className="how__steps">
        {howItWorks.steps.map((step, i) => (
          <li key={step.title} className="step">
            <div className="step__head">
              <span className="step__num">{i + 1}</span>
              <h3 className="step__title">{step.title}</h3>
              {i < howItWorks.steps.length - 1 && <span className="step__dash" aria-hidden="true" />}
            </div>
            <p className="step__text">{step.text}</p>
          </li>
        ))}
      </ol>
    </section>
  )
}
