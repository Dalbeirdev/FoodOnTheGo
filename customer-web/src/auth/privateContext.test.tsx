import { act, renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api, tokens } from '../api/client'
import { CartProvider, useCart } from '../cart/CartContext'
import { AuthProvider, useAuth } from './AuthContext'
import { clearPrivateContext, isPrivateContextKey, PRIVATE_CONTEXT_CLEARED } from './privateContext'
import type { AuthRepository, AuthUser } from './repository'

/**
 * Module 39 (C-06): a customer sign-out leaves no journey, checkout, pickup, payment or chosen location behind in
 * this browser — and keeps the guest cart token, the analytics session and the development switches. The order note
 * (`fotg.cart.note`) is checkout text the customer typed, not part of the guest cart, so it goes too.
 */
const PRIVATE = {
  session: ['fotg.journey.current', 'fotg.journey.tokens', 'fotg.journey.context', 'fotg.journey.recent', 'fotg.journey.recent-places', 'fotg.checkout.request', 'fotg.checkout.terms', 'fotg.pickup.selection', 'fotg.payment.attempt', 'fotg.payment.current', 'fotg.cart.note'],
  local: ['fotg.discovery.scope'],
}
const KEPT = {
  session: ['fotg.analytics.session', 'fotg.analytics.once'],
  local: ['fotg.cart.token', 'fotg.journey.mode', 'fotg.checkout.mode', 'fotg.payment.mode', 'fotg.auth.mode'],
}
const seed = () => {
  for (const k of PRIVATE.session) sessionStorage.setItem(k, '{"x":1}')
  for (const k of PRIVATE.local) localStorage.setItem(k, '{"lat":28.6,"lng":77.2,"label":"Home · Delhi"}')
  for (const k of KEPT.session) sessionStorage.setItem(k, '"kept"')
  for (const k of KEPT.local) localStorage.setItem(k, k.endsWith('.mode') ? 'mock' : 'guest-cart-token')
}
const expectCleared = () => {
  for (const k of PRIVATE.session) expect(sessionStorage.getItem(k), k).toBeNull()
  for (const k of PRIVATE.local) expect(localStorage.getItem(k), k).toBeNull()
  for (const k of KEPT.session) expect(sessionStorage.getItem(k), k).toBe('"kept"')
  for (const k of KEPT.local) expect(localStorage.getItem(k), k).not.toBeNull()
}

const USER: AuthUser = { id: 'c0ffee00-0000-4000-8000-000000000001', name: 'Rahul Sharma', phone: '+919876543210', email: null, memberSince: '2026-01-12' }
const signedIn = (): AuthRepository => ({
  requestOtp: async () => { throw new Error('unused') }, verifyOtp: async () => { throw new Error('unused') }, completeSetup: async () => USER,
  getCurrentUser: async () => USER, refreshSession: async () => USER, updateProfile: async () => USER,
  logout: async () => { tokens.set('customer', null) },
})
const wrapper = (repo: AuthRepository) => ({ children }: { children: ReactNode }) => <AuthProvider repository={repo}>{children}</AuthProvider>

beforeEach(() => { sessionStorage.clear(); localStorage.clear() })
afterEach(() => { vi.unstubAllGlobals(); sessionStorage.clear(); localStorage.clear() })

describe('sign-out clears the private context (Module 39)', () => {
  it('names the private keys; a development mode switch is never one', () => {
    for (const k of [...PRIVATE.session, ...PRIVATE.local]) expect(isPrivateContextKey(k), k).toBe(true)
    for (const k of [...KEPT.session, ...KEPT.local]) expect(isPrivateContextKey(k), k).toBe(false)
  })

  it('clearPrivateContext removes them, keeps the guest cart token and the analytics session, and tells the pages', () => {
    seed()
    const heard = vi.fn(); window.addEventListener(PRIVATE_CONTEXT_CLEARED, heard)
    clearPrivateContext()
    window.removeEventListener(PRIVATE_CONTEXT_CLEARED, heard)
    expectCleared()
    expect(heard).toHaveBeenCalledTimes(1)
  })

  it('the Sign Out path (AuthContext.logout) clears it', async () => {
    tokens.set('customer', 'customer-token')
    const { result } = renderHook(() => useAuth(), { wrapper: wrapper(signedIn()) })
    await vi.waitFor(() => expect(result.current.isAuthenticated).toBe(true))
    seed()
    await act(async () => { await result.current.logout() })
    expectCleared()
    expect(tokens.get('customer')).toBeNull()
  })

  it('a session the backend rejects (401 on a stored token) clears it too', async () => {
    tokens.set('customer', 'customer-token')
    const { result } = renderHook(() => useAuth(), { wrapper: wrapper(signedIn()) })
    await vi.waitFor(() => expect(result.current.isAuthenticated).toBe(true))
    seed()
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(new Response(JSON.stringify({ error: { code: 'unauthenticated', message: 'Unauthenticated.' } }), { status: 401, headers: { 'Content-Type': 'application/json' } }))))
    await act(async () => { await expect(api('/customer/profile')).rejects.toMatchObject({ kind: 'unauthenticated' }) })
    expect(result.current.status).toBe('sessionExpired')
    expectCleared()
  })

  it('the order note the customer typed is forgotten in storage and in the cart provider, the cart token is kept', () => {
    localStorage.setItem('fotg.cart.token', 'guest-cart-token')
    const { result } = renderHook(() => useCart(), { wrapper: ({ children }: { children: ReactNode }) => <CartProvider initialCart={null}>{children}</CartProvider> })
    act(() => { result.current.setNote('Ring twice — flat 4B, Sector 21') })
    expect(sessionStorage.getItem('fotg.cart.note')).toBe('Ring twice — flat 4B, Sector 21')
    act(() => { clearPrivateContext() })
    expect(result.current.note).toBe('')
    expect(sessionStorage.getItem('fotg.cart.note')).toBeNull()
    expect(localStorage.getItem('fotg.cart.token')).toBe('guest-cart-token')
  })
})
