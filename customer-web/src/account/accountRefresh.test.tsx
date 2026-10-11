import { act, cleanup, render, waitFor } from '@testing-library/react'
import { useEffect } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { AuthProvider } from '../auth/AuthContext'
import { DEV_OTP, MockAuthRepository, TEST_NUMBERS } from '../auth/mock/MockAuthRepository'
import { AccountProvider, defaultAccountRepositories, useAccount, useInboxRefreshOn } from './AccountContext'
import { MockNotificationRepository, setMockLatency } from './mock/mockRepositories'

beforeEach(() => { localStorage.clear(); sessionStorage.clear(); setMockLatency(0) })
afterEach(() => cleanup())

/** The pattern of the tracking page before BUG-234 was fixed: an effect that depends on the refresh function and calls it. */
function Probe({ seen }: { seen: Set<unknown> }) {
  const { refreshNotifications, notifications } = useAccount()
  seen.add(refreshNotifications)
  useEffect(() => { void refreshNotifications() }, [refreshNotifications])
  return <p data-testid="state">{notifications.status}</p>
}

describe('Account inbox refresh (Module 33, BUG-234)', () => {
  it('refreshNotifications keeps one identity across reloads, so an effect that depends on it reads the inbox once and not in a loop', async () => {
    const auth = new MockAuthRepository(0)
    await auth.requestOtp(TEST_NUMBERS.existingCustomer)
    await auth.verifyOtp(TEST_NUMBERS.existingCustomer, DEV_OTP)
    const inner = new MockNotificationRepository()
    let lists = 0
    const notifications = new Proxy(inner, { get(target, key, receiver) { if (key === 'list') return (u: string) => { lists += 1; return target.list(u) }; const v = Reflect.get(target, key, receiver); return typeof v === 'function' ? v.bind(target) : v } })
    const seen = new Set<unknown>()
    const view = render(<MemoryRouter><AuthProvider><AccountProvider repositories={{ ...defaultAccountRepositories, notifications }}><Probe seen={seen} /></AccountProvider></AuthProvider></MemoryRouter>)
    await waitFor(() => expect(view.getByTestId('state').textContent).toBe('ready'))
    await new Promise((r) => setTimeout(r, 400))
    // the provider's own first load + the probe's one call (+ one more if the session arrived after the first render)
    expect(lists).toBeGreaterThanOrEqual(1)
    expect(lists).toBeLessThanOrEqual(4)
    // one function per signed-in state (signed out → signed in is at most two identities), never one per reload
    expect(seen.size).toBeLessThanOrEqual(2)
  })
})

/** A page that asks for the inbox when its trigger changes (the tracking page: the order status; the inbox page: 'opened'). */
function Triggered({ trigger }: { trigger: string | null }) {
  const { notifications } = useAccount()
  useInboxRefreshOn(trigger)
  return <p data-testid="status">{notifications.status}:{notifications.data.length}</p>
}

describe('useInboxRefreshOn (Module 33)', () => {
  it('reads the inbox once per trigger value, quietly (the list never goes back to loading), and not at all without a trigger', async () => {
    const auth = new MockAuthRepository(0)
    await auth.requestOtp(TEST_NUMBERS.existingCustomer)
    await auth.verifyOtp(TEST_NUMBERS.existingCustomer, DEV_OTP)
    const inner = new MockNotificationRepository()
    let lists = 0
    const notifications = new Proxy(inner, { get(target, key, receiver) { if (key === 'list') return (u: string) => { lists += 1; return target.list(u) }; const v = Reflect.get(target, key, receiver); return typeof v === 'function' ? v.bind(target) : v } })
    const repositories = { ...defaultAccountRepositories, notifications }   // one object: a new one per render would make the provider itself load again
    const tree = (trigger: string | null) => <MemoryRouter><AuthProvider><AccountProvider repositories={repositories}><Triggered trigger={trigger} /></AccountProvider></AuthProvider></MemoryRouter>
    const view = render(tree(null))
    await waitFor(() => expect(view.getByTestId('status').textContent).toMatch(/^ready:/))
    await new Promise((r) => setTimeout(r, 150))
    const afterLoad = lists
    const seen: string[] = []
    const watch = new MutationObserver(() => seen.push(view.queryByTestId('status')?.textContent ?? ''))
    watch.observe(view.container, { subtree: true, childList: true, characterData: true })
    await act(async () => { view.rerender(tree('ACCEPTED')); await new Promise((r) => setTimeout(r, 150)) })
    expect(lists).toBe(afterLoad + 1)
    await act(async () => { view.rerender(tree('ACCEPTED')); await new Promise((r) => setTimeout(r, 150)) })
    expect(lists).toBe(afterLoad + 1)   // the same value again: no read
    await act(async () => { view.rerender(tree('READY_FOR_PICKUP')); await new Promise((r) => setTimeout(r, 150)) })
    expect(lists).toBe(afterLoad + 2)
    watch.disconnect()
    expect(seen.some((s) => s.startsWith('loading'))).toBe(false)
  })
})
