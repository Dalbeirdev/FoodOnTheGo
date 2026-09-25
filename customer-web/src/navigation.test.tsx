import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { AppShell } from './App'
import { contentRepository } from './repositories'

const mount = (route: string) => render(<MemoryRouter initialEntries={[route]}><AppShell /></MemoryRouter>)

const PUBLIC_ROUTES: Array<[string, RegExp]> = [
  ['/', /Delicious Food/],
  ['/how-it-works', /How FoodOnTheGo Works/i],
  ['/restaurants', /Restaurants on Your Route/i],
  ['/plan-journey', /Plan Your Journey/i],
  ['/about-us', /Good Food/],
  ['/for-restaurants', /Grow Your Restaurant/i],
  ['/help', /Help & Support/i],
  ['/login', /Sign in with your mobile/i],
  ['/terms', /Terms & Conditions/],
  ['/privacy', /Privacy Policy/],
  ['/refund-policy', /Refund & Cancellation Policy/],
  ['/cookie-policy', /Cookie Policy/],
  ['/get-app', /In Your Pocket/],
]

describe('Module 02 navigation and routes', () => {
  it.each(PUBLIC_ROUTES)('%s renders its page', (route, heading) => {
    mount(route)
    expect(screen.getAllByText(heading).length).toBeGreaterThan(0)
    expect(document.querySelector('[data-page="not-found"]')).toBeNull()
  })

  it('unknown URLs show the branded 404 with the three recovery actions', () => {
    mount('/this/does/not/exist')
    expect(document.querySelector('[data-page="not-found"]')).not.toBeNull()
    expect(screen.getByRole('heading', { level: 1, name: /page not found/i })).toBeInTheDocument()
    for (const label of ['Return Home', 'Plan a Journey', 'Explore Restaurants']) {
      expect(within(document.querySelector('[data-page="not-found"]') as HTMLElement).getByRole('link', { name: new RegExp(label) })).toBeInTheDocument()
    }
  })

  it('/about redirects to the canonical /about-us', () => {
    mount('/about')
    expect(screen.getAllByText(/For Every Journey/).length).toBeGreaterThan(0)
  })

  it('/for-restaurants/register shows the interim coming-soon shell, not a fake onboarding form', () => {
    mount('/for-restaurants/register')
    expect(document.querySelector('[data-page="coming-soon"]')).not.toBeNull()
    expect(screen.queryByRole('form')).toBeNull()
  })

  it('header carries the approved public navigation, Login and Get the App', () => {
    mount('/')
    const nav = contentRepository.getSiteNavigation()
    const main = screen.getByRole('navigation', { name: 'Main' })
    for (const item of nav.primary) {
      expect(within(main).getByRole('link', { name: item.label })).toHaveAttribute('href', item.to)
    }
    expect(screen.getAllByRole('link', { name: 'Login' })[0]).toHaveAttribute('href', '/login')
    expect(screen.getByRole('link', { name: 'Get the App' })).toHaveAttribute('href', '/get-app')
    expect(within(main).getByRole('link', { name: 'Home' })).toHaveClass('is-active')
  })

  it('mobile menu toggles with the burger, closes on Escape and returns focus', async () => {
    const user = userEvent.setup()
    mount('/')
    const burger = screen.getByRole('button', { name: /open menu/i })
    expect(burger).toHaveAttribute('aria-expanded', 'false')
    await user.click(burger)
    expect(screen.getByRole('button', { name: /close menu/i })).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByRole('navigation', { name: 'Main' })).toHaveClass('is-open')
    await user.keyboard('{Escape}')
    expect(screen.getByRole('button', { name: /open menu/i })).toHaveAttribute('aria-expanded', 'false')
    expect(screen.getByRole('button', { name: /open menu/i })).toHaveFocus()
  })

  it('footer links point at real routes, legal pages and the app landing; socials are pending, not links', () => {
    mount('/')
    const nav = contentRepository.getSiteNavigation()
    const footer = screen.getByRole('contentinfo')
    for (const l of [...nav.footer.quickLinks, ...nav.footer.legal]) {
      expect(within(footer).getByRole('link', { name: l.label })).toHaveAttribute('href', l.to)
    }
    expect(within(footer).getByRole('link', { name: /google play/i })).toHaveAttribute('href', '/get-app')
    expect(within(footer).queryByRole('link', { name: /app store/i })).toBeNull()
    expect(within(footer).queryByRole('link', { name: /facebook|instagram|youtube/i })).toBeNull()
  })

  it('Help contact keeps Live Chat as an honest pending action (no backend)', async () => {
    const user = userEvent.setup()
    mount('/help')
    await user.click(screen.getByRole('button', { name: /live chat/i }))
    expect(screen.getByRole('status')).toHaveTextContent(/once the support system is connected/i)
    expect(screen.getByRole('link', { name: /email support/i })).toHaveAttribute('href', 'mailto:support@foodonthego.com')
  })
})
