import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it } from 'vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { Providers } from '../test/render'
import { AppShell } from '../App'
import { LocaleProvider } from '../i18n/LocaleProvider'
import { AuthProvider } from '../auth/AuthContext'
import { ToastProvider } from '../components/Toast'
import { ProfileProvider } from '../profile/ProfileContext'
import { AccountProvider } from '../account/AccountContext'
import { JourneyProvider } from '../journey/JourneyContext'
import { CartProvider } from '../cart/CartContext'
import { OrdersProvider } from '../orders/OrdersContext'
import RestaurantsPage from '../pages/RestaurantsPage'
import PlanJourneyPage from '../pages/PlanJourneyPage'
import { MockRestaurantRepository, RESTAURANTS, computeAvailability, normalize, setMockRestaurantLatency } from '../repositories/mock/restaurants'
import { MockJourneyRepository, MockRouteRepository, PLACES, setMockJourneyLatency, toLocation } from '../journey/mock/mockRepositories'
import { formatDistance, formatLocalTime, formatMoney, priceLevelLabel } from '../i18n/format'
import { marketFor, resolveUnitSystem } from '../i18n/markets'
import type { Journey } from '../journey/repositories'
import type { DiscoveryScope } from '../repositories/types'

const PUNJAB: DiscoveryScope = { countryCode: 'IN', adminArea: 'Punjab', locality: 'Rupnagar', lat: 30.9685, lng: 76.5265, label: 'Rupnagar', source: 'manual' }
const setScope = (s: DiscoveryScope) => localStorage.setItem('fotg.discovery.scope', JSON.stringify(s))

const place = (id: string) => toLocation(PLACES.find((p) => p.id === id)!)
const repo = new MockRestaurantRepository()
const NOW = '2026-09-28T06:00:00.000Z' // 11:30 IST · 23:00 PDT (previous day) · 07:00 BST · 15:00 JST · 10:00 GST

async function journeyBetween(a: string, b: string): Promise<Journey> {
  const jr = new MockJourneyRepository()
  const j = await jr.create({ origin: place(a), destination: place(b), departureAt: null })
  const route = await new MockRouteRepository().getRoute(j.origin, j.destination)
  return jr.update({ ...j, route, status: 'route-available' })
}

beforeEach(() => { localStorage.clear(); sessionStorage.clear(); setMockRestaurantLatency(0); setMockJourneyLatency(0) })

describe('global readiness — formatting, units, time zones, Unicode', () => {
  it('TEST 4 — currency formatter adapts (ISO 4217, minor units, locale)', () => {
    expect(formatMoney(1250, 'INR', 'en-IN')).toBe('₹12.50')
    expect(formatMoney(1250, 'USD', 'en-US')).toBe('$12.50')
    expect(formatMoney(1250, 'JPY', 'ja-JP')).toMatch(/1,250/)
    expect(formatMoney(1250, 'EUR', 'fr-FR')).toMatch(/12,50/)
    expect(priceLevelLabel(2, 'GBP', 'en-GB')).toBe('££')
    expect(priceLevelLabel(3, 'INR', 'en-IN')).toBe('₹₹₹')
  })
  it('TEST 6 / 7 — metric and imperial distance rendering from the same metres', () => {
    expect(formatDistance(1500, 'metric', 'en-IN')).toBe('1.5 km')
    expect(formatDistance(1500, 'imperial', 'en-US')).toBe('0.9 mi')
    expect(formatDistance(350, 'metric', 'en')).toBe('350 m')
    expect(formatDistance(20, 'imperial', 'en-US')).toBe('66 ft')
    expect(resolveUnitSystem('auto', 'US')).toBe('imperial')
    expect(resolveUnitSystem('auto', 'GB')).toBe('imperial')
    expect(resolveUnitSystem('auto', 'JP')).toBe('metric')
    expect(resolveUnitSystem('imperial', 'JP')).toBe('imperial')
    expect(marketFor('ZZ').unitSystem).toBe('metric') // unknown country → neutral default, never India/US
  })
  it('TEST 5 — opening status uses the restaurant time zone, not the device zone', () => {
    const gilroy = RESTAURANTS.find((r) => r.id === 'gilroy-garlic')!   // 06:00–22:00 America/Los_Angeles
    const burger = RESTAURANTS.find((r) => r.id === 'burger-hub')!      // 08:00–23:30 Asia/Kolkata
    const diner = RESTAURANTS.find((r) => r.id === 'kettleman-diner')!  // 24 h
    const grapevine = RESTAURANTS.find((r) => r.id === 'grapevine-burgers')! // 10:00–01:00 overnight
    expect(computeAvailability(gilroy, NOW).status).toBe('closed')      // 23:00 PDT
    expect(computeAvailability(burger, NOW).status).toBe('open')        // 11:30 IST — same instant
    expect(computeAvailability(diner, NOW).status).toBe('open')
    expect(computeAvailability(grapevine, '2026-09-28T07:30:00.000Z').status).toBe('closing_soon') // 00:30 PDT, closes 01:00
    const soon = computeAvailability(gilroy, '2026-09-28T12:30:00.000Z')  // 05:30 PDT
    expect(soon.status).toBe('opening_soon')
    expect(formatLocalTime(soon.nextChangeAt!, gilroy.timezone, 'en-US')).toBe('6:00 AM')
    expect(computeAvailability(RESTAURANTS.find((r) => r.id === 'wok-express')!, NOW).status).toBe('temporarily_closed')
  })
  it('TEST 8 — Unicode names are preserved and searchable (NFKD, diacritics, alternate names)', async () => {
    expect(normalize('Café Élysée')).toBe('cafe elysee')
    const names = (p: Awaited<ReturnType<typeof repo.getRestaurants>>) => p.items.map((x) => x.restaurant.name)
    expect(names(await repo.getRestaurants({ search: '一風堂' }))).toContain('一風堂 静岡店')
    expect(names(await repo.getRestaurants({ search: 'ippudo' }))).toContain('一風堂 静岡店')
    expect(names(await repo.getRestaurants({ search: 'elysee' }))).toContain('Café Élysée des Routes')
    expect(names(await repo.getRestaurants({ search: 'البيت' }))).toContain('مطعم البيت الشامي')
    expect(names(await repo.getRestaurants({ search: 'al bait' }))).toContain('مطعم البيت الشامي')
  })
})

describe('location-scoped discovery (mock repository)', () => {
  it('SCOPE 1 — Punjab: near you → rest of Punjab → neighbouring states → rest of India, never other countries', async () => {
    const p1 = await repo.getRestaurants({ scope: PUNJAB, now: NOW, limit: 50 })
    expect(p1.items.map((x) => x.restaurant.id)).toEqual(['dhaba-junction-ropar', 'hoshiarpur-sweets', 'pathankot-rasoi'])
    expect(p1.items.map((x) => x.ring)).toEqual([0, 1, 1])
    expect(p1.ringApplied).toBe(1); expect(p1.nextRing).toBe(2)
    const p2 = await repo.getRestaurants({ scope: PUNJAB, now: NOW, limit: 50, maxRing: 2 })
    expect(p2.items.map((x) => x.restaurant.id)).toContain('ambala-chai') // Haryana neighbours Punjab
    expect(p2.items.every((x) => (x.ring ?? 9) <= 2)).toBe(true)
    expect(p2.nextRing).toBe(3)
    const p3 = await repo.getRestaurants({ scope: PUNJAB, now: NOW, limit: 50, maxRing: 3 })
    expect(p3.total).toBe(16) // every Indian fixture, nothing from US/GB/JP/FR/AE
    expect(p3.items.every((x) => x.restaurant.countryCode === 'IN')).toBe(true)
    expect(p3.nextRing).toBeNull()
  })
  it('SCOPE 2 — US scope shows only US restaurants; region-less scope auto-expands to the whole country', async () => {
    const us = await repo.getRestaurants({ scope: { countryCode: 'US', adminArea: 'CA', lat: 37.7749, lng: -122.4194, label: 'San Francisco', source: 'manual' }, now: NOW, limit: 50 })
    expect(us.items.every((x) => x.restaurant.countryCode === 'US')).toBe(true)
    expect(us.total).toBe(4)
    const inOnly = await repo.getRestaurants({ scope: { countryCode: 'IN', lat: null, lng: null, label: 'India', source: 'locale' }, now: NOW, limit: 50 })
    expect(inOnly.ringApplied).toBe(3); expect(inOnly.total).toBe(16)
    expect(inOnly.items.some((x) => x.restaurant.countryCode !== 'IN')).toBe(false)
  })
  it('SCOPE 3 — region adjacency works with non-Latin region names (静岡県 ↔ 愛知県)', async () => {
    const jp = await repo.getRestaurants({ scope: { countryCode: 'JP', adminArea: '静岡県', lat: null, lng: null, label: '静岡', source: 'manual' }, now: NOW, limit: 50, maxRing: 2 })
    expect(jp.items.map((x) => [x.restaurant.id, x.ring])).toEqual(expect.arrayContaining([['ippudo-shizuoka', 1], ['hamamatsu-unagi', 1], ['yamamotoya-nagoya', 2]]))
  })
})

describe('route-aware discovery (mock repository)', () => {
  it('TEST 1 — short journey returns restaurants relevant to the route with detour + arrival', async () => {
    const j = await journeyBetween('dubai', 'abu-dhabi')
    const page = await repo.getRestaurantsForJourney(j, { now: NOW })
    expect(page.items.map((x) => x.restaurant.id).sort()).toEqual(['al-bait-al-shami', 'ghantoot-karak'])
    const first = page.items.find((x) => x.restaurant.id === 'al-bait-al-shami')!
    expect(first.distanceFromRouteM).toBeGreaterThan(0)
    expect(first.detourDurationMin).toBeGreaterThan(0)
    expect(first.routePosition).toBeGreaterThan(0); expect(first.routePosition).toBeLessThan(1)
    expect(first.estimatedArrival).toMatch(/^2026-/)
    expect(page.items.find((x) => x.restaurant.id === 'ghantoot-karak')!.distanceFromRouteM).toBe(0) // sits on a corridor waypoint
    expect(page.corridorM).toBe(marketFor('AE').corridorM)
  })
  it('TEST 2 — long journey stays bounded: corridor + cursor pagination', async () => {
    const j = await journeyBetween('delhi', 'mumbai')
    const p1 = await repo.getRestaurantsForJourney(j, { now: NOW, limit: 4 })
    expect(p1.total).toBe(6); expect(p1.items).toHaveLength(4); expect(p1.nextCursor).toBe('c4')
    const p2 = await repo.getRestaurantsForJourney(j, { now: NOW, limit: 4, cursor: p1.nextCursor })
    expect(p2.items).toHaveLength(2); expect(p2.nextCursor).toBeNull()
    // Noida fixtures are ~15 km off the Delhi start — excluded by the corridor, never "all restaurants".
    expect([...p1.items, ...p2.items].some((x) => x.restaurant.id === 'burger-hub')).toBe(false)
  })
  it('TEST 3 — different country: US results carry US address / currency / units, no Indian formatting', async () => {
    const j = await journeyBetween('san-francisco', 'los-angeles')
    const page = await repo.getRestaurantsForJourney(j, { now: NOW })
    expect(page.items.length).toBeGreaterThanOrEqual(3)
    for (const x of page.items) {
      expect(x.restaurant.countryCode).toBe('US'); expect(x.restaurant.currency).toBe('USD'); expect(x.restaurant.timezone).toBe('America/Los_Angeles')
      expect(x.restaurant.address.formatted).not.toMatch(/₹|Pradesh|PIN/)
    }
    expect(formatDistance(page.items[0].distanceFromRouteM!, resolveUnitSystem('auto', 'US'), 'en-US')).toMatch(/mi|ft/)
  })
  it('TEST 9 — data-driven filters: cuisine taxonomy from data, journey-only filters, open now / dietary / rating', async () => {
    const defs = repo.getFilterDefinitions(null)
    expect(defs.find((d) => d.id === 'cuisine')!.options!.map((o) => o.value)).toEqual(expect.arrayContaining(['Café', 'ラーメン', 'شامي']))
    expect(defs.some((d) => d.id === 'distanceFromRoute')).toBe(false)
    const j = await journeyBetween('chandigarh', 'jammu')
    expect(repo.getFilterDefinitions(j).some((d) => d.id === 'distanceFromRoute' && d.journeyOnly)).toBe(true)
    const all = await repo.getRestaurantsForJourney(j, { now: NOW })
    expect(all.items.map((x) => x.restaurant.id).sort()).toEqual(['dhaba-junction-ropar', 'hoshiarpur-sweets', 'pathankot-rasoi'])
    const veg = await repo.getRestaurantsForJourney(j, { now: NOW, filters: { dietary: ['Vegetarian'] } })
    expect(veg.items.map((x) => x.restaurant.id).sort()).toEqual(['dhaba-junction-ropar', 'hoshiarpur-sweets'])
    const open = await repo.getRestaurants({ now: '2026-09-28T22:00:00.000Z', filters: { openNow: true } }) // 03:30 IST
    expect(open.items.every((x) => x.availability.status === 'open' || x.availability.status === 'closing_soon')).toBe(true)
    expect(open.items.some((x) => x.restaurant.id === 'dhaba-junction-ropar')).toBe(true) // 24 h
    const rated = await repo.getRestaurants({ now: NOW, filters: { rating: 4.5 }, limit: 50 })
    expect(rated.items.every((x) => x.restaurant.rating >= 4.5)).toBe(true)
  })
  it('TEST 10 — sorting: highest rated, fastest pickup, lowest detour, transparent recommended', async () => {
    const byRating = (await repo.getRestaurants({ now: NOW, sort: 'highestRated', limit: 50 })).items.map((x) => x.restaurant.rating)
    expect(byRating).toEqual([...byRating].sort((a, b) => b - a))
    const byPrep = (await repo.getRestaurants({ now: NOW, sort: 'fastestPickup', limit: 50 })).items.map((x) => x.restaurant.prepTimeMin)
    expect(byPrep).toEqual([...byPrep].sort((a, b) => a - b))
    const j = await journeyBetween('san-francisco', 'los-angeles')
    const byDetour = (await repo.getRestaurantsForJourney(j, { now: NOW, sort: 'lowestDetour' })).items.map((x) => x.detourDurationMin!)
    expect(byDetour).toEqual([...byDetour].sort((a, b) => a - b))
    const rec = (await repo.getRestaurantsForJourney(j, { now: NOW, sort: 'recommended' })).items
    const firstClosed = rec.findIndex((x) => x.availability.status === 'closed' || x.availability.status === 'temporarily_closed')
    const lastOpen = rec.map((x) => x.availability.status).lastIndexOf('open')
    if (firstClosed >= 0 && lastOpen >= 0) expect(lastOpen).toBeLessThan(firstClosed)
  })
  it('TEST 13 — sparse coverage returns an empty page, not an error', async () => {
    const j = await journeyBetween('bengaluru', 'mysuru')
    const page = await repo.getRestaurantsForJourney(j, { now: NOW })
    expect(page.items).toEqual([]); expect(page.total).toBe(0)
  })
})

function mount(route: string) {
  return render(
    <Providers route={route}>
      <Routes>
        <Route path="/restaurants" element={<RestaurantsPage />} />
        <Route path="/plan-journey" element={<PlanJourneyPage />} />
        <Route path="/login" element={<h1>Sign in with your mobile</h1>} />
      </Routes>
    </Providers>,
  )
}

describe('Restaurants page (web)', () => {
  it('TEST 12 — no journey: general browsing with a Plan a Journey CTA and no route-only sort options', async () => {
    mount('/restaurants')
    expect(await screen.findByRole('heading', { name: /^\d+ restaurants$/i })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: /browsing all restaurants/i })).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: /plan a journey/i }).length).toBeGreaterThan(0)
    const sort = screen.getByRole('combobox', { name: /sort by/i })
    expect(within(sort).queryByRole('option', { name: /lowest detour/i })).not.toBeInTheDocument()
    expect((await screen.findAllByRole('heading', { level: 3 })).length).toBeGreaterThan(0)
  })
  it('TEST 1 (UI) — journey context, route-aware count, cards with detour + local arrival', async () => {
    const j = await journeyBetween('chandigarh', 'jammu')
    mount(`/restaurants?journey=${j.id}`)
    expect(await screen.findByRole('heading', { name: /3 restaurants along your route/i })).toBeInTheDocument()
    expect(screen.getByText('Chandigarh', { selector: '.jctx__pt b' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /edit journey/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /start new journey/i })).toBeInTheDocument()
    const card = screen.getByRole('heading', { name: 'Dhaba Junction' }).closest('li')!
    expect(within(card).getByText(/from route/i)).toBeInTheDocument()
    expect(within(card).getByText(/detour/i)).toBeInTheDocument()
    expect(within(card).getByText(/arrive ~/i)).toBeInTheDocument()
    expect(within(card).getByRole('link', { name: /view restaurant/i })).toHaveAttribute('href', '/restaurants/dhaba-junction-ropar')
  })
  it('TEST 3 (UI) — different market renders miles and $ price levels, never ₹ or km', async () => {
    const j = await journeyBetween('san-francisco', 'los-angeles')
    mount(`/restaurants?journey=${j.id}`)
    const card = (await screen.findByRole('heading', { name: 'Route 5 Diner' })).closest('li')!
    expect(within(card).getByText(/mi from route|ft from route/)).toBeInTheDocument()
    expect(within(card).getByText('$')).toBeInTheDocument()
    expect(card.textContent).not.toMatch(/₹|km/)
  })
  it('TEST 7 (UI) — unit preference override switches the same result to metric', async () => {
    const user = userEvent.setup()
    const j = await journeyBetween('london', 'manchester')
    mount(`/restaurants?journey=${j.id}`)
    const card = (await screen.findByRole('heading', { name: /Watford Gap/ })).closest('li')!
    expect(within(card).getByText(/mi from route|ft from route/)).toBeInTheDocument()
    await user.selectOptions(screen.getByRole('combobox', { name: /distance units/i }), 'metric')
    await waitFor(() => expect(within(card).getByText(/(km|m) from route/)).toBeInTheDocument())
  })
  it('TEST 8 (UI) — Unicode names render intact and typed search finds them (JP scope)', async () => {
    const user = userEvent.setup()
    setScope({ countryCode: 'JP', label: '日本', lat: null, lng: null, source: 'manual' })
    mount('/restaurants')
    await screen.findByRole('heading', { name: /^\d+ restaurants$/i })
    await user.type(screen.getByRole('searchbox', { name: /search restaurants/i }), '山本屋')
    expect(await screen.findByRole('heading', { name: '味噌煮込みうどん 山本屋' })).toBeInTheDocument()
    expect(await screen.findByRole('heading', { name: /^1 restaurants?/i })).toBeInTheDocument()
  })
  it('TEST 9 (UI) — filters panel is data-driven; Open now + Clear all work', async () => {
    const user = userEvent.setup()
    mount('/restaurants')
    await screen.findByRole('heading', { name: /^\d+ restaurants$/i })
    const before = screen.getByRole('heading', { name: /^\d+ restaurants$/i }).textContent!
    expect(screen.getByRole('group', { name: /cuisine/i })).toBeInTheDocument()
    await user.click(screen.getByRole('checkbox', { name: /open now/i }))
    await waitFor(() => expect(screen.getByRole('heading', { name: /^\d+ restaurants$/i }).textContent).not.toBe(before))
    await user.click(screen.getByRole('button', { name: /clear all/i }))
    await waitFor(() => expect(screen.getByRole('heading', { name: /^\d+ restaurants$/i }).textContent).toBe(before))
  })
  it('TEST 10 (UI) — sort select re-orders the list (JP scope)', async () => {
    const user = userEvent.setup()
    setScope({ countryCode: 'JP', label: '日本', lat: null, lng: null, source: 'manual' })
    mount('/restaurants')
    await screen.findByRole('heading', { name: /^\d+ restaurants$/i })
    await user.selectOptions(screen.getByRole('combobox', { name: /sort by/i }), 'highestRated')
    await waitFor(() => expect(within(document.querySelector('.cards')!).getAllByRole('heading', { level: 3 })[0]).toHaveTextContent(/うなぎ藤田/))
  })
  it('TEST 11 — map marker selection highlights the card and the text alternative lists stops', async () => {
    const user = userEvent.setup()
    const j = await journeyBetween('dubai', 'abu-dhabi')
    mount(`/restaurants?journey=${j.id}`)
    await screen.findByRole('heading', { name: /2 restaurants along your route/i })
    await user.click(screen.getByRole('button', { name: /map view/i }))
    const map = await screen.findByRole('group', { name: /route map/i })
    await user.click(screen.getByRole('button', { name: /list \+ map/i }))
    await user.click(within(map).getByRole('button', { name: 'Karak House Ghantoot' }))
    const card = screen.getByRole('heading', { name: 'Karak House Ghantoot' }).closest('li')!
    expect(card).toHaveClass('is-selected')
    expect(screen.getByText(/selected: karak house ghantoot/i)).toBeInTheDocument()
    expect(screen.getByText(/restaurants along the route, in route order/i)).toBeInTheDocument()
  })
  it('TEST 2 (UI) — load more appends the next page and stays bounded', async () => {
    const user = userEvent.setup()
    mount('/restaurants')
    await screen.findByRole('heading', { name: /^\d+ restaurants$/i })
    const first = screen.getAllByRole('heading', { level: 3 }).length
    await user.click(screen.getByRole('button', { name: /load more restaurants/i }))
    await waitFor(() => expect(screen.getAllByRole('heading', { level: 3 }).length).toBeGreaterThan(first))
  })
  it('SCOPE (UI) — banner shows the scope, Punjab restaurants come first with ring labels, Show neighbouring regions expands, Change location dialog works', async () => {
    const user = userEvent.setup()
    setScope(PUNJAB)
    mount('/restaurants')
    expect(await screen.findByRole('status')).toHaveTextContent(/showing restaurants near rupnagar/i)
    const list = document.querySelector('.cards') as HTMLElement
    await waitFor(() => expect(within(list).getAllByRole('heading', { level: 3 })[0]).toHaveTextContent('Dhaba Junction'))
    expect(within(list).getByText('Near you')).toBeInTheDocument()
    expect(within(list).getAllByText('In Punjab').length).toBe(2)
    expect(screen.queryByRole('heading', { name: 'Ambala Chai Point' })).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: /show neighbouring regions/i }))
    expect(await screen.findByRole('heading', { name: 'Ambala Chai Point' })).toBeInTheDocument()
    expect(screen.getAllByText('Nearby region').length).toBeGreaterThan(0)
    expect(screen.queryByRole('heading', { name: 'Route 5 Diner' })).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: /change location/i }))
    const dialog = screen.getByRole('dialog', { name: /your location/i })
    await user.type(within(dialog).getByRole('combobox'), 'san fran')
    await user.click(await within(dialog).findByRole('option', { name: /^San Francisco, California/ }))
    await user.click(within(dialog).getByRole('button', { name: /use this location/i }))
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent(/showing restaurants near san francisco/i))
    await waitFor(() => expect(screen.getByRole('heading', { name: 'Gilroy Garlic Kitchen' })).toBeInTheDocument())
    expect(screen.queryByRole('heading', { name: 'Dhaba Junction' })).not.toBeInTheDocument()
    expect(JSON.parse(localStorage.getItem('fotg.discovery.scope')!).countryCode).toBe('US')
  })
  it('SCOPE (UI) — no scope resolvable → asks for a location instead of showing mixed markets', async () => {
    render(
      <MemoryRouter initialEntries={['/restaurants']}>
        <LocaleProvider locale="en"><AuthProvider><ToastProvider><ProfileProvider><AccountProvider><JourneyProvider><CartProvider><OrdersProvider>
          <Routes><Route path="/restaurants" element={<RestaurantsPage />} /></Routes>
        </OrdersProvider></CartProvider></JourneyProvider></AccountProvider></ProfileProvider></ToastProvider></AuthProvider></LocaleProvider>
      </MemoryRouter>,
    )
    expect(await screen.findByText(/where are you\?/i)).toBeInTheDocument()
    expect(document.querySelectorAll('.cards:not(.cards--skeleton) .rcard').length).toBe(0)
  })
  it('TEST 13 (UI) — empty route results offer widen / edit route recovery', async () => {
    const user = userEvent.setup()
    const j = await journeyBetween('bengaluru', 'mysuru')
    mount(`/restaurants?journey=${j.id}`)
    expect(await screen.findByText(/no restaurants found near this route/i)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /edit route/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /widen search to/i })).toHaveTextContent(/10 km/)
    await user.click(screen.getByRole('button', { name: /widen search to/i }))
    await waitFor(() => expect(screen.getByRole('button', { name: /widen search to/i })).toHaveTextContent(/20 km/), { timeout: 3000 })
  })
  it('error state with retry', async () => {
    const user = userEvent.setup()
    sessionStorage.setItem('fotg.mock.fail', 'restaurants')
    mount('/restaurants')
    expect(await screen.findByRole('alert')).toHaveTextContent(/unavailable/i)
    sessionStorage.removeItem('fotg.mock.fail')
    await user.click(screen.getByRole('button', { name: /try again/i }))
    expect(await screen.findByRole('heading', { name: /^\d+ restaurants$/i })).toBeInTheDocument()
  })
  it('canonical restaurant routes: legacy /restaurant/... redirects to /restaurants/...', async () => {
    render(<MemoryRouter initialEntries={['/restaurant/burger-hub/item/classic-burger']}><AppShell /></MemoryRouter>)
    expect((await screen.findAllByText(/Classic Burger/i)).length).toBeGreaterThan(0)
    expect(screen.getAllByRole('link', { name: /Burger Hub/i })[0]).toHaveAttribute('href', expect.stringMatching(/^\/restaurants\//))
  })
})
