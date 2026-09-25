import { useHomeContent } from '../repositories'
import PublicIcon from './PublicIcons'
import './Features.css'

export default function Features() {
  const { features } = useHomeContent()
  return (
    <section className="features" aria-label="Why FoodOnTheGo">
      <div className="features__card">
        {features.map(({ icon, tone, title, text }) => (
          <div key={title} className="feature">
            <span className={`feature__icon feature__icon--${tone}`}>
              <PublicIcon name={icon} size={30} />
            </span>
            <h3 className="feature__title">{title}</h3>
            <p className="feature__text">{text}</p>
          </div>
        ))}
      </div>
    </section>
  )
}
