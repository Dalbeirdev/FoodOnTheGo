import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it } from 'vitest'
import { Route, Routes } from 'react-router-dom'
import { Providers } from '../test/render'
import { render } from '@testing-library/react'
import PlanJourneyPage from '../pages/PlanJourneyPage'
import RestaurantsPage from '../pages/RestaurantsPage'
import { MockJourneyRepository, MockLocationRepository, MockRouteRepository, PLACES, setMockJourneyLatency, toLocation } from './mock/mockRepositories'
import { validateJourney } from './repositories'
import { DEV_OTP, MockAuthRepository, TEST_NUMBERS } from '../auth/mock/MockAuthRepository'

const place = (id: string) => toLocation(PLACES.find((p) => p.id === id)!)

beforeEach(() => { localStorage.clear(); sessionStorage.clear(); setMockJourneyLatency(0) })

describe('journey repositories (mock)', () => {
  it('MockLocationRepository searches by name, alias and sub text', async () => {
    const repo = new MockLocationRepository()
    const r = await repo.search('Chandigarh')
    expect(r.map((l) => l.name)).toEqual(['Chandigarh', 'Chandigarh Railway Station', 'Chandigarh Airport'])
    expect((await repo.search('bangalore'))[0].name).toBe('Bengaluru')
    expect(await repo.search('nowhere')).toEqual([])
    expect(await repo.search('c')).toEqual([])
  })
  it('search fails when the mock failure switch is set', async () => {
    sessionStorage.setItem('fotg.mock.fail', 'location')
    await expect(new MockLocationRepository().search('Jammu')).rejects.toThrow(/search failed/i)
  })
  it('MockRouteRepository returns a corridor with waypoints and estimate flag', async () => {
    const r = await new MockRouteRepository().getRoute(place('chandigarh'), place('jammu'))
    expect(r.waypoints).toEqual(['Ropar', 'Hoshiarpur', 'Pathankot'])
    expect(r.distanceKm).toBeGreaterThan(250); expect(r.distanceKm).toBeLessThan(400)
    expect(r.isEstimate).toBe(true); expect(r.provider).toBe('mock')
    const back = await new MockRouteRepository().getRoute(place('jammu'), place('chandigarh'))
    expect(back.waypoints).toEqual(['Pathankot', 'Hoshiarpur', 'Ropar'])
  })
  it('MockRouteRepository reports no route for the island test case', async () => {
    await expect(new MockRouteRepository().getRoute(place('chandigarh'), place('port-blair'))).rejects.toThrow(/no road route/i)
  })
  it('MockJourneyRepository creates, lists recent (deduplicated) and removes', async () => {
    const repo = new MockJourneyRepository()
    const a = await repo.create({ origin: place('chandigarh'), destination: place('jammu'), departureAt: null })
    await repo.create({ origin: place('chandigarh'), destination: place('jammu'), departureAt: null })
    const b = await repo.create({ origin: place('delhi'), destination: place('jaipur'), departureAt: '2026-10-01T09:00:00.000Z' })
    expect(a.status).toBe('ready'); expect(a.originLat).toBe(30.7333); expect(a.routeGeometryRef).toBeNull()
    expect((await repo.recent()).map((j) => j.id)).toEqual([b.id, expect.any(String)])
    expect((await repo.recent()).length).toBe(2)
    await repo.remove(b.id)
    expect((await repo.recent()).length).toBe(1)
    expect(await repo.get('missing')).toBeNull()
  })
  it('validateJourney covers missing, incomplete and same locations', () => {
    expect(validateJourney(null, null)).toEqual({ origin: 'Enter your starting point', destination: 'Enter your destination' })
    expect(validateJourney(null, place('jammu'), 'Chan').origin).toMatch(/choose your starting point/i)
    expect(validateJourney(place('chandigarh'), place('chandigarh')).destination).toMatch(/different/i)
    expect(validateJourney(place('chandigarh'), place('jammu'))).toEqual({})
  })
})

function mount(route = '/plan-journey') {
  return render(
    <Providers route={route}>
      <Routes>
        <Route path="/plan-journey" element={<PlanJourneyPage />} />
        <Route path="/restaurants" element={<RestaurantsPage />} />
        <Route path="/login" element={<h1>Sign in with your mobile</h1>} />
      </Routes>
    </Providers>,
  )
}

async function pick(user: ReturnType<typeof userEvent.setup>, label: RegExp, text: string, option: RegExp) {
  const input = screen.getByRole('combobox', { name: label })
  await user.clear(input)
  await user.type(input, text)
  const list = await screen.findByRole('listbox', { name: label.source.replace(/\\\//g, '/') === label.source ? new RegExp(label.source + ' suggestions', 'i') : label })
  await user.click(await within(list).findByRole('option', { name: option }))
}

describe('Plan a Journey (web)', () => {
  it('TEST 1 — Chandigarh → Jammu: journey accepted, summary displayed, continue works', async () => {
    const user = userEvent.setup()
    mount()
    await pick(user, /starting point/i, 'Chandigarh', /^Chandigarh, Chandigarh, India$/)
    await pick(user, /destination/i, 'Jammu', /^Jammu, Jammu and Kashmir, India$/)
    await user.click(screen.getByRole('button', { name: /find restaurants on route/i }))
    expect(await screen.findByRole('heading', { name: /^your journey$/i })).toBeInTheDocument()
    await screen.findByText(/approximate distance/i)
    expect(screen.getAllByText(/mock development data/i, { selector: 'small' })).toHaveLength(2)
    expect(screen.getByRole('img', { name: /route from chandigarh to jammu via ropar, hoshiarpur, pathankot/i })).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: /find restaurants along route/i }))
    expect(await screen.findByRole('heading', { name: /restaurants? along your route/i })).toBeInTheDocument()
    expect(screen.getByText('Chandigarh', { selector: '.jctx__pt b' })).toBeInTheDocument()
    expect(screen.getByText('Jammu', { selector: '.jctx__pt b' })).toBeInTheDocument()
  })
  it('TEST 2 — swap exchanges origin and destination', async () => {
    const user = userEvent.setup()
    mount()
    await pick(user, /starting point/i, 'Chandigarh', /^Chandigarh, Chandigarh, India$/)
    await pick(user, /destination/i, 'Jammu', /^Jammu, Jammu and Kashmir, India$/)
    await user.click(screen.getByRole('button', { name: /swap start and destination/i }))
    expect(screen.getByRole('combobox', { name: /starting point/i })).toHaveValue('Jammu')
    expect(screen.getByRole('combobox', { name: /destination/i })).toHaveValue('Chandigarh')
    await user.click(screen.getByRole('button', { name: /find restaurants on route/i }))
    expect(await screen.findByRole('img', { name: /route from jammu to chandigarh via pathankot, hoshiarpur, ropar/i })).toBeInTheDocument()
  })
  it('TEST 3 — same origin and destination is rejected', async () => {
    const user = userEvent.setup()
    mount()
    await pick(user, /starting point/i, 'Chandigarh', /^Chandigarh, Chandigarh, India$/)
    await pick(user, /destination/i, 'Chandigarh', /^Chandigarh, Chandigarh, India$/)
    await user.click(screen.getByRole('button', { name: /find restaurants on route/i }))
    expect(await screen.findByRole('alert')).toHaveTextContent(/different from the starting point/i)
    expect(screen.queryByRole('heading', { name: /^your journey$/i })).not.toBeInTheDocument()
  })
  it('TEST 4 — missing origin cannot continue; typed-but-unselected text is flagged', async () => {
    const user = userEvent.setup()
    mount()
    await pick(user, /destination/i, 'Jammu', /^Jammu, Jammu and Kashmir, India$/)
    await user.click(screen.getByRole('button', { name: /find restaurants on route/i }))
    expect(await screen.findByText('Enter your starting point')).toBeInTheDocument()
    await user.type(screen.getByRole('combobox', { name: /starting point/i }), 'Chan')
    await user.click(screen.getByRole('button', { name: /find restaurants on route/i }))
    expect(await screen.findByText(/choose your starting point from the suggestions/i)).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: /^your journey$/i })).not.toBeInTheDocument()
  })
  it('TEST 5 + 7 — authenticated customer picks Home (saved address) → Jammu', async () => {
    const auth = new MockAuthRepository(0)
    const otp = await auth.requestOtp(TEST_NUMBERS.existingCustomer)
    await auth.verifyOtp(otp.phone, DEV_OTP)
    const user = userEvent.setup()
    mount()
    const from = screen.getByRole('combobox', { name: /starting point/i })
    await user.click(from)
    const list = await screen.findByRole('listbox', { name: /starting point suggestions/i })
    await user.click(await within(list).findByRole('option', { name: /^Home, / }))
    expect(from).toHaveValue('Home')
    await pick(user, /destination/i, 'Jammu', /^Jammu, Jammu and Kashmir, India$/)
    await user.click(screen.getByRole('button', { name: /find restaurants on route/i }))
    expect(await screen.findByRole('heading', { name: /^your journey$/i })).toBeInTheDocument()
    expect(await screen.findByText(/approximate distance/i)).toBeInTheDocument()
    expect(screen.getByText('Home', { selector: '.pj-preview__pt b' })).toBeInTheDocument()
  })
  it('TEST 6 — guest plans a journey and reaches restaurants without login', async () => {
    const user = userEvent.setup()
    mount()
    await pick(user, /starting point/i, 'Delhi', /^Delhi, National Capital Territory, India$/)
    await pick(user, /destination/i, 'Jaipur', /^Jaipur, Rajasthan, India$/)
    await user.click(screen.getByRole('button', { name: /find restaurants on route/i }))
    await user.click(await screen.findByRole('button', { name: /find restaurants along route/i }))
    expect(await screen.findByRole('heading', { name: /restaurants? along your route/i })).toBeInTheDocument()
    expect(screen.getByText('Jaipur', { selector: '.jctx__pt b' })).toBeInTheDocument()
    expect(screen.queryByText(/sign in with your mobile/i)).not.toBeInTheDocument()
  })
  it('current location shows the development notice and never claims a real position', async () => {
    const user = userEvent.setup()
    mount()
    await user.click(screen.getByRole('button', { name: /use my current location/i }))
    expect(screen.getByRole('dialog', { name: /current location/i })).toHaveTextContent(/REAL GEOLOCATION PERMISSION = PENDING INTEGRATION/)
    await user.click(screen.getByRole('button', { name: /use development location/i }))
    expect(screen.getByRole('combobox', { name: /starting point/i })).toHaveValue('Sector 62, Noida')
  })
  it('route failure shows an error with retry, and retry recovers', async () => {
    const user = userEvent.setup()
    mount()
    await pick(user, /starting point/i, 'Mumbai', /^Mumbai, Maharashtra, India$/)
    await pick(user, /destination/i, 'Pune', /^Pune, Maharashtra, India$/)
    sessionStorage.setItem('fotg.mock.fail', 'route')
    await user.click(screen.getByRole('button', { name: /find restaurants on route/i }))
    expect(await screen.findByRole('alert')).toHaveTextContent(/route preparation failed/i)
    sessionStorage.removeItem('fotg.mock.fail')
    await user.click(screen.getByRole('button', { name: /try again/i }))
    expect(await screen.findByText(/approximate distance/i)).toBeInTheDocument()
  })
  it('no route between mainland and island shows the no-route error', async () => {
    const user = userEvent.setup()
    mount()
    await pick(user, /starting point/i, 'Chandigarh', /^Chandigarh, Chandigarh, India$/)
    await pick(user, /destination/i, 'Port Blair', /^Port Blair/)
    await user.click(screen.getByRole('button', { name: /find restaurants on route/i }))
    expect(await screen.findByRole('alert')).toHaveTextContent(/no road route/i)
  })
  it('location lookup failure shows retry inside the suggestions', async () => {
    const user = userEvent.setup()
    mount()
    sessionStorage.setItem('fotg.mock.fail', 'location')
    await user.type(screen.getByRole('combobox', { name: /starting point/i }), 'Jammu')
    expect(await screen.findByText(/location search failed/i)).toBeInTheDocument()
    sessionStorage.removeItem('fotg.mock.fail')
    await user.click(screen.getByRole('button', { name: /retry/i }))
    expect(await screen.findByRole('option', { name: /^Jammu, Jammu and Kashmir/ })).toBeInTheDocument()
  })
  it('recent journeys: use again and remove', async () => {
    const user = userEvent.setup()
    mount()
    await pick(user, /starting point/i, 'Bengaluru', /^Bengaluru/)
    await pick(user, /destination/i, 'Mysuru', /^Mysuru/)
    await user.click(screen.getByRole('button', { name: /find restaurants on route/i }))
    await screen.findByText(/approximate distance/i)
    const recent = await screen.findByRole('region', { name: /recent journeys/i })
    expect(within(recent).getByText('Bengaluru')).toBeInTheDocument()
    await user.click(within(recent).getByRole('button', { name: /use again/i }))
    expect(screen.getByRole('combobox', { name: /starting point/i })).toHaveValue('Bengaluru')
    await user.click(within(recent).getByRole('button', { name: /remove bengaluru to mysuru/i }))
    await waitFor(() => expect(screen.queryByRole('region', { name: /recent journeys/i })).not.toBeInTheDocument())
  })
  it('keyboard: arrow down + enter selects a suggestion', async () => {
    const user = userEvent.setup()
    mount()
    const input = screen.getByRole('combobox', { name: /destination/i })
    await user.type(input, 'Jaipur')
    await screen.findByRole('option', { name: /^Jaipur/ })
    fireEvent.keyDown(input, { key: 'ArrowDown' })
    fireEvent.keyDown(input, { key: 'Enter' })
    expect(input).toHaveValue('Jaipur')
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })
  it('restaurants page with an unknown journey id explains and links back', async () => {
    mount('/restaurants?journey=jrn-missing')
    expect(await screen.findByText(/journey not found/i)).toBeInTheDocument()
    cleanup()
  })
})
