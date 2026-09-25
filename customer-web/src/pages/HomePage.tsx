import Header from '../components/Header'
import Hero from '../components/Hero'
import Features from '../components/Features'
import HowItWorks from '../components/HowItWorks'

export default function HomePage() {
  return (
    <>
      <Header />
      <main id="main">
        <Hero />
        <Features />
        <HowItWorks />
      </main>
    </>
  )
}
