import { screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { renderPage } from '../test/render'
import CartPage from './CartPage'
import HomePage from './HomePage'
import MyOrdersPage from './MyOrdersPage'
import PlanJourneyPage from './PlanJourneyPage'
import RestaurantDetailPage from './RestaurantDetailPage'
import RestaurantsPage, { RESTAURANTS } from './RestaurantsPage'

describe('Customer Web pages render with the shared providers', () => {
  it('Home shows the approved hero copy and brand logo', () => {
    renderPage(<HomePage />)
    expect(screen.getByText('Delicious Food')).toBeInTheDocument()
    expect(screen.getByText('On Your Route')).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: /plan a journey/i }).length).toBeGreaterThan(0)
  })

  it('Restaurants lists every fixture restaurant', () => {
    renderPage(<RestaurantsPage />, { route: '/restaurants' })
    for (const r of RESTAURANTS) expect(screen.getAllByText(r.name).length).toBeGreaterThan(0)
    expect(screen.getByRole('button', { name: /find restaurants/i })).toBeInTheDocument()
  })

  it('Restaurant detail (Design A) renders the menu for the canonical slug route', () => {
    renderPage(<RestaurantDetailPage />, { route: '/restaurants/burger-hub', path: '/restaurants/:restaurantSlug' })
    expect(screen.getAllByText('Burger Hub').length).toBeGreaterThan(0)
    expect(screen.getAllByText('Classic Burger').length).toBeGreaterThan(0)
  })

  it('Cart shows the empty state with a way back to restaurants', () => {
    renderPage(<CartPage />, { route: '/cart' })
    expect(screen.getByText(/your cart is empty/i)).toBeInTheDocument()
    expect(screen.getAllByText(/explore restaurants/i).length).toBeGreaterThan(0)
  })

  it('My Orders shows the seeded order history', () => {
    renderPage(<MyOrdersPage />, { route: '/my-orders' })
    expect(screen.getAllByText(/FTG128701/).length).toBeGreaterThan(0)
    expect(screen.getAllByText(/FTG128654/).length).toBeGreaterThan(0)
  })

  it('Plan a Journey renders its form', () => {
    renderPage(<PlanJourneyPage />, { route: '/plan-journey' })
    expect(screen.getByRole('button', { name: /find restaurants on route/i })).toBeInTheDocument()
  })
})
