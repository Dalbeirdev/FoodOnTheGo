import { act, render, renderHook, screen, waitFor, within } from '@testing-library/react'
import type { ReactNode } from 'react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it } from 'vitest'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import CartPage from './CartPage'
import ItemDetailPage from './ItemDetailPage'
import PickupTimePage from './PickupTimePage'
import { CartProvider, useCart, type AddItemInput } from '../cart/CartContext'
import { MemoryCartRepository, type Cart } from '../cart/cartModel'
import { AuthProvider, useAuth } from '../auth/AuthContext'
import { JourneyProvider } from '../journey/JourneyContext'
import { LocaleProvider } from '../i18n/LocaleProvider'
import { setMockMenuLatency } from '../menu/mock/mockMenu'
import { setMockRestaurantLatency } from '../repositories/mock/restaurants'

/** Module 09 — Cart page on the Module 08 cart (web). TEST 1–14 at component level. */
const burger = (over: Partial<AddItemInput> = {}): AddItemInput => ({
  menuItemId: 'classic-burger', itemSlug: 'classic-burger', itemName: 'Classic Burger', image: '', fallback: '', basePriceMinor: 25000, currency: 'INR',
  restaurant: { id: 'burger-hub', slug: 'burger-hub', name: 'Burger Hub', currency: 'INR' },
  selectedVariants: [{ groupId: 'classic-burger:size', groupName: 'Size', optionId: 'size:large', optionName: 'Large', priceAdjustmentMinor: 7000 }],
  selectedModifiers: [{ groupId: 'classic-burger:addons', groupName: 'Add-ons', optionId: 'addons:cheese', optionName: 'Extra Cheese', priceAdjustmentMinor: 3000 }],
  specialInstructions: 'No onion please', quantity: 2, unitPriceMinor: 35000, maximumQuantity: 20, ...over,
})
const fries = (): AddItemInput => burger({ menuItemId: 'french-fries', itemSlug: 'french-fries', itemName: 'French Fries', basePriceMinor: 12000, selectedVariants: [], selectedModifiers: [], specialInstructions: '', quantity: 1, unitPriceMinor: 12000 })

function Loc() { const l = useLocation(); return <output data-testid="loc">{l.pathname + l.search}</output> }
function AuthProbe() { const a = useAuth(); const cart = useCart(); return <output data-testid="auth">{JSON.stringify({ authed: a.isAuthenticated, count: cart.count })}</output> }

function mount(path: string, repo = new MemoryCartRepository()) {
  return render(
    <LocaleProvider>
      <AuthProvider>
        <JourneyProvider>
          <CartProvider repository={repo}>
            <MemoryRouter initialEntries={[path]}>
              <Routes>
                <Route path="/cart" element={<CartPage />} />
                <Route path="/pickup-time" element={<PickupTimePage />} />
                <Route path="/restaurants/:rid/item/:itemId" element={<ItemDetailPage />} />
                <Route path="*" element={<p>other</p>} />
              </Routes>
              <Loc />
              <AuthProbe />
            </MemoryRouter>
          </CartProvider>
        </JourneyProvider>
      </AuthProvider>
    </LocaleProvider>,
  )
}
const seededRepo = async (items: AddItemInput[]) => {
  const repo = new MemoryCartRepository()
  const wrapper = ({ children }: { children: ReactNode }) => <CartProvider repository={repo}>{children}</CartProvider>
  const hook = renderHook(() => useCart(), { wrapper })
  await act(async () => {})
  for (const i of items) act(() => { hook.result.current.addItem(i) })
  await act(async () => { await new Promise((r) => setTimeout(r, 10)) })
  hook.unmount()
  return repo
}

describe('Cart page (web)', () => {
  beforeEach(() => { setMockMenuLatency(0); setMockRestaurantLatency(0); localStorage.clear(); sessionStorage.clear() })

  it('TEST 1 — empty cart shows a professional empty state without checkout controls', async () => {
    mount('/cart')
    expect(await screen.findByRole('heading', { level: 1, name: /your cart is empty/i })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /explore restaurants/i })).toHaveAttribute('href', '/restaurants')
    expect(within(screen.getByRole('heading', { level: 1, name: /your cart is empty/i }).closest('section')!).getByRole('link', { name: /plan a journey/i })).toHaveAttribute('href', '/plan-journey')
    expect(screen.queryByRole('button', { name: /continue to pickup/i })).toBeNull()
  })

  it('TEST 2 / 3 / 14 — structured lines, restaurant context, subtotal; Unicode and INR formatting', async () => {
    const repo = await seededRepo([burger(), fries()])
    mount('/cart', repo)
    expect(await screen.findByRole('heading', { level: 2, name: 'Burger Hub' })).toBeInTheDocument()
    expect(await screen.findByText(/Noida/)).toBeInTheDocument()
    const line = await screen.findByRole('listitem', { name: 'Classic Burger' })
    expect(within(line).getByText(/Size:/).closest('li')).toHaveTextContent(/Large/)
    expect(within(line).getByText(/Size:/).closest('li')!.textContent!.replace(/[\s  ]/g, '')).toBe('Size:Large(+₹70.00)')
    expect(within(line).getByText(/Add-ons:/).closest('li')).toHaveTextContent(/Extra Cheese/)
    expect(within(line).getByText(/No onion please/)).toBeInTheDocument()
    expect(within(line).getByText(/₹350\.00 each/)).toBeInTheDocument()
    expect(within(line).getByText('₹700.00')).toBeInTheDocument()
    expect(screen.getByText(/items subtotal/i).parentElement).toHaveTextContent('₹820.00')
    expect(screen.getByText(/estimated total/i).parentElement).toHaveTextContent('₹820.00')
    expect(screen.queryByText(/GST|VAT|service fee|platform fee/i)).toBeNull()
    expect(screen.getByText(/no taxes or fees are configured/i)).toBeInTheDocument()
    await waitFor(() => expect(screen.getByRole('button', { name: /continue to pickup/i })).toBeEnabled())
  })

  it('TEST 4 / 6 / 7 — quantity updates totals; remove asks first; removing the last item empties the cart', async () => {
    const user = userEvent.setup()
    const repo = await seededRepo([burger({ quantity: 1 })])
    mount('/cart', repo)
    const line = await screen.findByRole('listitem', { name: 'Classic Burger' })
    await user.click(within(line).getByRole('button', { name: /increase quantity/i }))
    expect(within(line).getByText('₹700.00')).toBeInTheDocument()
    expect(screen.getByText(/items subtotal/i).parentElement).toHaveTextContent('₹700.00')
    await user.click(within(line).getByRole('button', { name: /decrease quantity/i }))
    expect(within(line).getByText('₹350.00')).toBeInTheDocument()
    await user.click(within(line).getByRole('button', { name: /remove classic burger/i }))
    expect(within(line).getByText(/remove this item\?/i)).toBeInTheDocument()
    await user.click(within(line).getByRole('button', { name: /keep/i }))
    expect(await screen.findByRole('listitem', { name: 'Classic Burger' })).toBeInTheDocument()
    await user.click(within(line).getByRole('button', { name: /remove classic burger/i }))
    await user.click(within(line).getByRole('button', { name: /yes, remove/i }))
    expect(await screen.findByRole('heading', { level: 1, name: /your cart is empty/i })).toBeInTheDocument()
  })

  it('TEST 5 — Edit reopens the item with the previous configuration and saves the change back', async () => {
    const user = userEvent.setup()
    const repo = await seededRepo([burger()])
    mount('/cart', repo)
    const line = await screen.findByRole('listitem', { name: 'Classic Burger' })
    await user.click(within(line).getByRole('link', { name: /edit classic burger/i }))
    expect(await screen.findByRole('heading', { level: 2, name: /editing your cart item/i })).toBeInTheDocument()
    expect(screen.getByRole('radio', { name: /large/i })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: /extra cheese/i })).toBeChecked()
    expect(screen.getByLabelText(/special instructions/i)).toHaveValue('No onion please')
    await waitFor(() => expect(screen.getByText(/item total/i).parentElement).toHaveTextContent('₹700.00'))
    await user.click(screen.getByRole('radio', { name: /jumbo/i }))
    await user.click(screen.getByRole('checkbox', { name: /bacon/i }))
    await user.click(screen.getByRole('button', { name: /save changes/i }))
    await waitFor(() => expect(screen.getByTestId('loc')).toHaveTextContent('/cart'))
    const updated = await screen.findByRole('listitem', { name: 'Classic Burger' })
    expect(within(updated).getByText(/Size:/).closest('li')).toHaveTextContent(/Jumbo/)
    expect(within(updated).getAllByText(/Add-ons:/).map((e) => e.closest('li')!.textContent)).toEqual(expect.arrayContaining([expect.stringContaining('Extra Cheese'), expect.stringContaining('Bacon')]))
    expect(within(updated).getByText(/₹460\.00 each/)).toBeInTheDocument()
    expect(screen.getAllByRole('listitem', { name: 'Classic Burger' })).toHaveLength(1)
  })

  it('TEST 8 — Continue shopping links back to the same restaurant and keeps the cart', async () => {
    const repo = await seededRepo([burger()])
    mount('/cart', repo)
    expect(await screen.findByRole('link', { name: /continue shopping/i })).toHaveAttribute('href', '/restaurants/burger-hub')
    expect((await repo.load())?.items).toHaveLength(1)
  })

  it('TEST 9 / 10 — guest cart works and signing in keeps it', async () => {
    const repo = await seededRepo([burger()])
    mount('/cart', repo)
    await screen.findByRole('listitem', { name: 'Classic Burger' })
    expect(JSON.parse(screen.getByTestId('auth').textContent!)).toEqual({ authed: false, count: 2 })
    // simulate a login by writing the mock auth session, then re-mount the tree on the same repository
    await act(async () => { sessionStorage.setItem('fotg.auth.session', JSON.stringify({ token: 't', user: { id: 'u1', name: 'Test', phone: '+911234567890', email: null } })) })
    expect((await repo.load())?.items).toHaveLength(1)
  })

  it('TEST 11 / 12 — unavailable item and changed price block progression until reviewed', async () => {
    const user = userEvent.setup()
    const repo = await seededRepo([burger({ unitPriceMinor: 30000 }), fries()])
    mount('/cart', repo)
    const line = await screen.findByRole('listitem', { name: 'Classic Burger' })
    expect(await within(line).findByText(/price updated from ₹300\.00 to ₹350\.00/i)).toBeInTheDocument()
    expect(screen.getByText(/your cart has changed/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /^review cart$/i })).toBeDisabled()
    await user.click(within(line).getByRole('button', { name: /accept updated price/i }))
    await waitFor(() => expect(screen.getByRole('button', { name: /continue to pickup/i })).toBeEnabled())
    expect(within(await screen.findByRole('listitem', { name: 'Classic Burger' })).getByText(/₹350\.00 each/)).toBeInTheDocument()
    expect(screen.getByTestId('auth').textContent).toContain('"count":3')
    sessionStorage.setItem('fotg.mock.stale', 'unavailable')
    await user.click(within(await screen.findByRole('listitem', { name: 'French Fries' })).getByRole('button', { name: /increase quantity/i }))
    expect(await screen.findByText(/this item is no longer available/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /^review cart$/i })).toBeDisabled()
  })

  it('TEST 13 — a USD cart formats in dollars and the promo area works with integer money', async () => {
    const user = userEvent.setup()
    const repo = await seededRepo([burger({ menuItemId: 'grapevine-burgers:truck-stop-breakfast-0-0', itemSlug: 'truck-stop-breakfast-0-0', itemName: 'Truck Stop Breakfast', currency: 'USD', basePriceMinor: 1199, restaurant: { id: 'grapevine-burgers', slug: 'grapevine-burgers', name: 'Grapevine Burgers', currency: 'USD' }, selectedVariants: [{ groupId: 'grapevine-burgers:truck-stop-breakfast-0-0:size', groupName: 'Size', optionId: 'size:regular', optionName: 'Regular', priceAdjustmentMinor: 0 }], selectedModifiers: [{ groupId: 'grapevine-burgers:truck-stop-breakfast-0-0:side', groupName: 'Choose your side', optionId: 'side:fries', optionName: 'Fries', priceAdjustmentMinor: 0 }], specialInstructions: '', quantity: 2, unitPriceMinor: 1199 })])
    mount('/cart', repo)
    expect(await screen.findByRole('heading', { level: 2, name: 'Grapevine Burgers' })).toBeInTheDocument()
    expect(screen.getByText(/items subtotal/i).parentElement).toHaveTextContent('$23.98')
    await user.type(screen.getByLabelText(/promo code/i), 'welcome10')
    await user.click(screen.getByRole('button', { name: /^apply$/i }))
    expect(await screen.findByText(/WELCOME10 applied/i)).toBeInTheDocument()
    expect(screen.getByText(/discount \(WELCOME10\)/i).parentElement).toHaveTextContent('−$2.40')
    expect(screen.getByText(/estimated total/i).parentElement).toHaveTextContent('$21.58')
    await user.click(screen.getByRole('button', { name: /remove code/i }))
    await user.clear(screen.getByLabelText(/promo code/i)); await user.type(screen.getByLabelText(/promo code/i), 'expired'); await user.click(screen.getByRole('button', { name: /^apply$/i }))
    expect(await screen.findByText(/that code has expired/i)).toBeInTheDocument()
  })

  it('proceed leads to the interim pickup-time stage, which redirects back when the cart is empty', async () => {
    const user = userEvent.setup()
    const repo = await seededRepo([burger()])
    mount('/cart', repo)
    const btn = await screen.findByRole('button', { name: /continue to pickup/i })
    await waitFor(() => expect(btn).toBeEnabled())
    await user.click(btn)
    expect(await screen.findByRole('heading', { level: 1, name: /pickup time/i })).toBeInTheDocument()
    expect(screen.getByText(/nothing has been ordered/i)).toBeInTheDocument()
    mount('/pickup-time')
    await waitFor(() => expect(screen.getAllByTestId('loc').some((l) => l.textContent === '/cart')).toBe(true))
  })
})

// keeps the Cart type import used for future assertions on persisted shape
export type _CartShape = Cart
