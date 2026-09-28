import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it } from 'vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { Providers } from '../test/render'
import { AppShell } from '../App'
import RestaurantDetailPage from '../pages/RestaurantDetailPage'
import { MockMenuRepository, NO_MENU, setMockMenuLatency } from './mock/mockMenu'
import { setMockRestaurantLatency } from '../repositories/mock/restaurants'
import { MockJourneyRepository, MockRouteRepository, PLACES, setMockJourneyLatency, toLocation } from '../journey/mock/mockRepositories'
import { DEV_OTP, MockAuthRepository, TEST_NUMBERS } from '../auth/mock/MockAuthRepository'
import { formatMoney } from '../i18n/format'

const repo = new MockMenuRepository()
const place = (id: string) => toLocation(PLACES.find((p) => p.id === id)!)
beforeEach(() => { localStorage.clear(); sessionStorage.clear(); setMockMenuLatency(0); setMockRestaurantLatency(0); setMockJourneyLatency(0) })

describe('MenuRepository (mock)', () => {
  it('categories and items are restaurant-specific and dynamic', async () => {
    const dhaba = await repo.getCategories('dhaba-junction-ropar')
    const ramen = await repo.getCategories('ippudo-shizuoka')
    expect(dhaba.map((c) => c.name)).toEqual(['Popular', 'Tandoor', 'Breads', 'Drinks & Sweets'])
    expect(ramen.map((c) => c.name)).toEqual(['ラーメン', 'サイド', 'ドリンク'])
    const items = await repo.getItems('ippudo-shizuoka', { categoryId: ramen[0].id })
    expect(items.items.map((i) => i.name)).toEqual(['白丸元味', '赤丸新味', 'からか麺'])
    expect(items.items[0].currency).toBe('JPY'); expect(items.items[0].basePriceMinor).toBe(890)
  })
  it('legacy NCR menus keep Module 01 item ids for the existing cart / order pages', async () => {
    const item = await repo.getItemBySlug('burger-hub', 'classic-burger')
    expect(item?.id).toBe('classic-burger'); expect(item?.currency).toBe('INR'); expect(item?.basePriceMinor).toBe(25000)
  })
  it('TEST 3 — Unicode + alternate-name search inside a menu', async () => {
    expect((await repo.getItems('ippudo-shizuoka', { search: '餃子' })).items.map((i) => i.name)).toEqual(['餃子（5個）'])
    expect((await repo.getItems('ippudo-shizuoka', { search: 'gyoza' })).items.map((i) => i.name)).toEqual(['餃子（5個）'])
    expect((await repo.getItems('al-bait-al-shami', { search: 'حمص' })).items.length).toBe(1)
    expect((await repo.getItems('cafe-elysee-auxerre', { search: 'croque' })).items[0].name).toBe('Croque-Monsieur')
    expect((await repo.getItems('dhaba-junction-ropar', { search: 'tandoor' })).items.length).toBeGreaterThan(0) // category name match
  })
  it('TEST 4 — dietary + available-only filters', async () => {
    const veg = await repo.getItems('jaipur-thali', { dietary: ['Vegetarian'], limit: 500 })
    expect(veg.items.every((i) => i.dietaryTags.includes('Vegetarian'))).toBe(true)
    const vegan = await repo.getItems('jaipur-thali', { dietary: ['Vegan'], limit: 500 })
    expect(vegan.items.every((i) => i.dietaryTags.includes('Vegan') && i.dietaryTags.includes('Vegetarian'))).toBe(true)
    const avail = await repo.getItems('burger-hub', { availableOnly: true })
    expect(avail.items.some((i) => i.id === 'chicken-wings')).toBe(false)
    expect(await repo.getDietaryTags('harris-ranch')).toEqual(['Gluten-Free', 'Vegan', 'Vegetarian'])
  })
  it('TEST 5 — availability states exist and are not hidden by default', async () => {
    const all = await repo.getItems('burger-hub')
    expect(all.items.find((i) => i.id === 'chicken-wings')?.availability).toBe('sold_out')
    expect(all.items.find((i) => i.id === 'family-pack')?.availability).toBe('temporarily_unavailable')
  })
  it('TEST 10 — prices carry the restaurant currency; formatting is locale-aware', async () => {
    const us = (await repo.getItems('kettleman-diner')).items[0]
    expect(us.currency).toBe('USD'); expect(formatMoney(us.basePriceMinor, us.currency, 'en-US')).toBe('$11.99')
    const jp = (await repo.getItems('ippudo-shizuoka')).items[0]
    expect(formatMoney(jp.basePriceMinor, jp.currency, 'ja-JP')).toMatch(/890/)
    const fr = (await repo.getItems('cafe-elysee-auxerre')).items[0]
    expect(formatMoney(fr.basePriceMinor, fr.currency, 'fr-FR')).toMatch(/14,50/)
  })
  it('TEST 12 — large menu: many categories and 100+ items, paginated', async () => {
    const cats = await repo.getCategories('jaipur-thali')
    expect(cats.length).toBeGreaterThanOrEqual(13)
    const p1 = await repo.getItems('jaipur-thali', { limit: 24 })
    expect(p1.total).toBeGreaterThan(100); expect(p1.items).toHaveLength(24); expect(p1.nextCursor).toBe('c24')
  })
  it('restaurant without a menu returns no categories', async () => {
    expect(NO_MENU.has('ambala-chai')).toBe(true)
    expect(await repo.getCategories('ambala-chai')).toEqual([])
  })
  it('menu failure switch', async () => {
    sessionStorage.setItem('fotg.mock.fail', 'menu')
    await expect(repo.getCategories('burger-hub')).rejects.toThrow(/could not be loaded/i)
  })
})

function mount(route: string) {
  return render(
    <Providers route={route}>
      <Routes>
        <Route path="/restaurants/:id" element={<RestaurantDetailPage />} />
        <Route path="/restaurants/:rid/item/:itemId" element={<h1>Item page</h1>} />
        <Route path="/restaurants" element={<h1>Restaurants list</h1>} />
        <Route path="/login" element={<h1>Sign in with your mobile</h1>} />
      </Routes>
    </Providers>,
  )
}

describe('Restaurant Details (web)', () => {
  it('TEST 1 + 7 — opens directly by slug with no journey: hero, info, menu categories and items', async () => {
    mount('/restaurants/dhaba-junction-ropar')
    expect(await screen.findByRole('heading', { level: 1, name: 'Dhaba Junction' })).toBeInTheDocument()
    expect(screen.getAllByText(/NH-205, Rupnagar/).length).toBeGreaterThan(0)
    expect(screen.getByText(/plan a journey to see detour/i)).toBeInTheDocument()
    expect(await screen.findByRole('heading', { level: 2, name: 'Popular' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 3, name: 'Butter Chicken' })).toBeInTheDocument()
    expect([...document.querySelectorAll('.mcard strong')].some((e) => (e.textContent ?? '').replace(/[\s  ]/g, '') === '₹320.00')).toBe(true)
    expect(screen.getAllByText('Customizable').length).toBeGreaterThan(0)
    expect(document.title).toMatch(/Dhaba Junction/)
  })
  it('TEST 2 — category chip filters to that category and shows counts', async () => {
    const user = userEvent.setup()
    mount('/restaurants/dhaba-junction-ropar')
    await screen.findByRole('heading', { level: 2, name: 'Popular' })
    await user.click(screen.getByRole('button', { name: /^Breads/ }))
    expect(screen.getByRole('heading', { level: 2, name: 'Breads' })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { level: 2, name: 'Popular' })).not.toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 3, name: 'Garlic Naan' })).toBeInTheDocument()
  })
  it('TEST 3 (UI) — menu search finds a Unicode item by Latin alternate name', async () => {
    const user = userEvent.setup()
    mount('/restaurants/ippudo-shizuoka')
    await screen.findByRole('heading', { level: 2, name: 'ラーメン' })
    await user.type(screen.getByRole('searchbox', { name: /search this menu/i }), 'gyoza')
    await waitFor(() => expect(screen.queryByRole('heading', { level: 3, name: '白丸元味' })).not.toBeInTheDocument())
    expect(screen.getByRole('heading', { level: 3, name: '餃子（5個）' })).toBeInTheDocument()
    expect(screen.getByText(/￥450|¥450/)).toBeInTheDocument()
  })
  it('TEST 4 (UI) — dietary filter chips narrow the menu; clear restores it', async () => {
    const user = userEvent.setup()
    mount('/restaurants/harris-ranch')
    await screen.findByRole('heading', { level: 2, name: 'Steaks' })
    await user.click(screen.getByRole('checkbox', { name: /vegan/i }))
    await waitFor(() => expect(screen.queryByRole('heading', { level: 3, name: 'Ribeye 12 oz' })).not.toBeInTheDocument())
    expect(screen.getByRole('heading', { level: 3, name: 'Grilled Asparagus' })).toBeInTheDocument()
    await user.click(screen.getByRole('checkbox', { name: /gluten-free/i }))
    expect(await screen.findByText(/no items match/i)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: /clear search & filters/i }))
    expect(await screen.findByRole('heading', { level: 3, name: 'Ribeye 12 oz' })).toBeInTheDocument()
  })
  it('TEST 5 (UI) — sold out and temporarily unavailable items are shown with a clear status', async () => {
    mount('/restaurants/burger-hub')
    await screen.findByRole('heading', { level: 2, name: 'Burgers' })
    const wings = screen.getByRole('heading', { level: 3, name: 'Chicken Wings' }).closest('li')!
    expect(within(wings).getByRole('status')).toHaveTextContent(/sold out/i)
    expect(wings).toHaveClass('is-unavailable')
    const family = screen.getByRole('heading', { level: 3, name: 'Family Pack' }).closest('li')!
    expect(within(family).getByRole('status')).toHaveTextContent(/temporarily unavailable/i)
  })
  it('TEST 6 — favorite toggles through the Module 04 repository (guest → login, customer → saved)', async () => {
    const user = userEvent.setup()
    mount('/restaurants/burger-hub')
    await screen.findByRole('heading', { level: 1, name: 'Burger Hub' })
    await user.click(screen.getByRole('button', { name: /save burger hub/i }))
    expect(await screen.findByText(/sign in with your mobile/i)).toBeInTheDocument()
  })
  it('TEST 6b — signed-in customer sees the saved state and can toggle it', async () => {
    const auth = new MockAuthRepository(0)
    const otp = await auth.requestOtp(TEST_NUMBERS.existingCustomer)
    await auth.verifyOtp(otp.phone, DEV_OTP)
    const user = userEvent.setup()
    mount('/restaurants/burger-hub')
    await screen.findByRole('heading', { level: 1, name: 'Burger Hub' })
    const btn = await screen.findByRole('button', { name: /remove burger hub from favorites/i }) // seeded favorite
    await user.click(btn)
    expect(await screen.findByRole('button', { name: /save burger hub/i })).toBeInTheDocument()
  })
  it('TEST 8 — active journey shows route context (distance, detour, arrival, ready-by)', async () => {
    const jr = new MockJourneyRepository()
    const j = await jr.create({ origin: place('chandigarh'), destination: place('jammu'), departureAt: null })
    const withRoute = await jr.update({ ...j, route: await new MockRouteRepository().getRoute(j.origin, j.destination), status: 'route-available' })
    sessionStorage.setItem('fotg.journey.current', JSON.stringify(withRoute))
    mount('/restaurants/dhaba-junction-ropar')
    await screen.findByRole('heading', { level: 1, name: 'Dhaba Junction' })
    const facts = await screen.findByRole('list', { name: /on your route/i })
    expect(within(facts).getByText(/from route/i)).toBeInTheDocument()
    expect(within(facts).getByText(/detour/i)).toBeInTheDocument()
    expect(within(facts).getByText(/mock estimate/i)).toBeInTheDocument()
    expect(screen.getByText(/ready by ~/i)).toBeInTheDocument()
  })
  it('TEST 9 — invalid slug shows a professional not-found state', async () => {
    mount('/restaurants/does-not-exist')
    expect(await screen.findByRole('heading', { name: /couldn’t find that restaurant/i })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /browse restaurants/i })).toBeInTheDocument()
  })
  it('TEST 10 (UI) — different currency fixtures render through the formatter', async () => {
    mount('/restaurants/kettleman-diner')
    await screen.findByRole('heading', { level: 2, name: 'Breakfast' })
    expect(screen.getByText('$11.99')).toBeInTheDocument()
    expect(document.body.textContent).not.toMatch(/₹/)
  })
  it('TEST 11 — Unicode restaurant + item names render intact with RTL-safe direction', async () => {
    mount('/restaurants/al-bait-al-shami-jebel-ali')
    expect(await screen.findByRole('heading', { level: 1, name: 'مطعم البيت الشامي' })).toHaveAttribute('dir', 'auto')
    expect(await screen.findByRole('heading', { level: 3, name: 'شيش طاووق' })).toBeInTheDocument()
  })
  it('TEST 12 (UI) — large menu renders capped sections with "Show all" expansion', async () => {
    const user = userEvent.setup()
    mount('/restaurants/jaipur-rajwada-thali')
    await screen.findByRole('heading', { level: 2, name: 'Curries' })
    const section = screen.getByRole('heading', { level: 2, name: 'Curries' }).closest('section')!
    expect(within(section).getAllByRole('listitem')).toHaveLength(8)
    await user.click(within(section).getByRole('button', { name: /show all 12 items/i }))
    expect(within(section).getAllByRole('listitem')).toHaveLength(12)
  })
  it('menu unavailable state for a restaurant without a published menu', async () => {
    mount('/restaurants/ambala-chai-point')
    expect(await screen.findByText(/menu currently unavailable/i)).toBeInTheDocument()
  })
  it('menu load failure shows retry and recovers', async () => {
    const user = userEvent.setup()
    sessionStorage.setItem('fotg.mock.fail', 'menu')
    mount('/restaurants/burger-hub')
    expect(await screen.findByRole('alert')).toHaveTextContent(/menu failed to load/i)
    sessionStorage.removeItem('fotg.mock.fail')
    await user.click(screen.getByRole('button', { name: /try again/i }))
    expect(await screen.findByRole('heading', { level: 2, name: 'Burgers' })).toBeInTheDocument()
  })
  it('item navigation uses /restaurants/:slug/item/:itemSlug and the legacy singular route redirects', async () => {
    mount('/restaurants/dhaba-junction-ropar')
    await screen.findByRole('heading', { level: 3, name: 'Butter Chicken' })
    expect(screen.getAllByRole('link', { name: /view item: butter chicken/i })[0]).toHaveAttribute('href', expect.stringMatching(/^\/restaurants\/dhaba-junction-ropar\/item\/butter-chicken-0-0$/))
    render(<MemoryRouter initialEntries={['/restaurant/burger-hub']}><AppShell /></MemoryRouter>)
    expect((await screen.findAllByRole('heading', { level: 1, name: 'Burger Hub' })).length).toBeGreaterThan(0)
  })
  it('Info tab shows opening hours in the restaurant zone with today highlighted', async () => {
    const user = userEvent.setup()
    mount('/restaurants/grapevine-burgers')
    await screen.findByRole('heading', { level: 1, name: 'Grapevine Burgers' })
    await user.click(screen.getByRole('tab', { name: /info/i }))
    const hours = screen.getAllByRole('list').find((l) => l.className === 'rd-hours')!
    expect(within(hours).getAllByRole('listitem')).toHaveLength(7)
    const norm = (x: string) => x.replace(/[\s  ]+/g, ' ')
    expect(within(hours).getAllByRole('listitem').filter((li) => /10:00 am – 1:00 am/i.test(norm(li.textContent ?? ''))).length).toBe(7)
    expect(document.querySelector('.rd-hours li.is-today')).not.toBeNull()
    expect(screen.getAllByText(/America\/Los_Angeles/).length).toBeGreaterThan(0)
  })
})
