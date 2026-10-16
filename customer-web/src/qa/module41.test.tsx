import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError, REQUEST_TIMEOUT_MS, api, setStepUpHandler } from '../api/client'
import StepUpDialog from '../admin/StepUpDialog'
import { userMessage } from '../api/userMessage'
import { ConfirmDialog } from '../components/AccountStates'
import ErrorBoundary from '../components/ErrorBoundary'

/** Module 41 — final QA corrections: no developer language on screen, no blank page, no endless request, safe dialogs. */
describe('Module 41 — error text a person can read', () => {
  it('the app\'s own errors keep their message; JavaScript runtime errors give way to the friendly fallback', () => {
    expect(userMessage(new ApiError(409, 'This pickup time was just taken.'), 'fallback')).toBe('This pickup time was just taken.')
    expect(userMessage(new Error('The restaurant is closed.'), 'fallback')).toBe('The restaurant is closed.')
    expect(userMessage(new TypeError("Cannot read properties of undefined (reading 'x')"), 'Could not load the menu.')).toBe('Could not load the menu.')
    expect(userMessage(new SyntaxError('Unexpected token < in JSON'), 'fallback')).toBe('fallback')
    expect(userMessage('boom', 'fallback')).toBe('fallback')
    expect(userMessage(new Error(''), 'fallback')).toBe('fallback')
  })

  it('a render error shows a calm page with a reload button, never a blank screen or the error text', () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {})
    const Broken = () => { throw new TypeError('secret developer detail') }
    render(<ErrorBoundary><Broken /></ErrorBoundary>)
    expect(screen.getByRole('heading', { name: 'Something went wrong' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Reload the page' })).toBeInTheDocument()
    expect(document.body.textContent).not.toContain('secret developer detail')
    spy.mockRestore()
  })
})

describe('Module 41 — requests end', () => {
  afterEach(() => { vi.unstubAllGlobals() })

  it('every API request carries a time limit, so a loading state always ends', async () => {
    let signal: AbortSignal | undefined
    vi.stubGlobal('fetch', vi.fn(async (_url: string, init: RequestInit) => { signal = init.signal ?? undefined; return new Response('{}', { status: 200, headers: { 'Content-Type': 'application/json' } }) }))
    await api('/live', { auth: false })
    expect(signal).toBeInstanceOf(AbortSignal)
    expect(REQUEST_TIMEOUT_MS).toBe(30_000)
  })

  it('a request that is aborted (time limit or caller) fails like a lost connection', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => { throw new DOMException('The operation was aborted.', 'TimeoutError') }))
    await expect(api('/markets', { auth: false })).rejects.toMatchObject({ kind: 'network' })
  })
})

describe('Module 41 — the confirmation dialog', () => {
  it('focuses the safe choice first, Escape cancels, and the focus returns to the opener', async () => {
    const user = userEvent.setup()
    const onCancel = vi.fn()
    const { rerender } = render(<><button type="button">Remove</button><ConfirmDialog open={false} title="Remove this place?" text="It is removed." danger onCancel={onCancel} onConfirm={() => {}} /></>)
    screen.getByRole('button', { name: 'Remove' }).focus()
    rerender(<><button type="button">Remove</button><ConfirmDialog open title="Remove this place?" text="It is removed." confirmLabel="Delete" danger onCancel={onCancel} onConfirm={() => {}} /></>)
    expect(screen.getByRole('dialog', { name: 'Remove this place?' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Cancel' })).toHaveFocus()
    await user.tab()
    expect(screen.getByRole('button', { name: 'Delete' })).toHaveFocus()
    await user.tab() // Tab stays inside the dialog
    expect(screen.getByRole('button', { name: 'Cancel' })).toHaveFocus()
    await user.keyboard('{Escape}')
    expect(onCancel).toHaveBeenCalledTimes(1)
    rerender(<><button type="button">Remove</button><ConfirmDialog open={false} title="Remove this place?" text="It is removed." danger onCancel={onCancel} onConfirm={() => {}} /></>)
    expect(screen.getByRole('button', { name: 'Remove' })).toHaveFocus()
  })
})

const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
const stepUpRequired = () => json(403, { error: { code: 'step_up_required', message: 'Please confirm it is you before this action: enter your password again.', details: { step_up: { mfa: false } } } })

describe('Module 41 — administrator step-up', () => {
  afterEach(() => { vi.unstubAllGlobals(); setStepUpHandler(null) })

  it('a high-risk admin action that needs a recent sign-in is repeated once after the step-up; cancel leaves it undone', async () => {
    const fetchMock = vi.fn().mockResolvedValueOnce(stepUpRequired()).mockResolvedValueOnce(json(200, { status: 'APPROVED' }))
    vi.stubGlobal('fetch', fetchMock)
    const handler = vi.fn(async () => true)
    setStepUpHandler(handler)
    await expect(api('/admin/refunds/r1/approve', { method: 'POST', context: 'admin' })).resolves.toEqual({ status: 'APPROVED' })
    expect(handler).toHaveBeenCalledWith({ mfa: false })
    expect(fetchMock).toHaveBeenCalledTimes(2)

    // Cancelled: the error reaches the caller, nothing is repeated.
    fetchMock.mockReset(); fetchMock.mockResolvedValueOnce(stepUpRequired())
    setStepUpHandler(async () => false)
    await expect(api('/admin/refunds/r1/approve', { method: 'POST', context: 'admin' })).rejects.toMatchObject({ code: 'step_up_required' })
    expect(fetchMock).toHaveBeenCalledTimes(1)

    // Never for the customer or restaurant contexts.
    fetchMock.mockReset(); fetchMock.mockResolvedValueOnce(stepUpRequired())
    const other = vi.fn(async () => true); setStepUpHandler(other)
    await expect(api('/customer/x', { method: 'POST', context: 'customer' })).rejects.toMatchObject({ code: 'step_up_required' })
    expect(other).not.toHaveBeenCalled()
  })

  it('the dialog asks for the password, confirms the session on the backend and the action goes through', async () => {
    const user = userEvent.setup()
    const calls: string[] = []
    vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit) => {
      const path = new URL(url).pathname.replace(/^.*\/api\/v1/, '')
      calls.push(`${init.method} ${path}`)
      if (path === '/auth/admin/step-up') return JSON.parse(String(init.body)).password === 'right-password' ? json(200, { data: { step_up_until: '2026-10-16T10:15:00Z' } }) : json(422, { error: { code: 'step_up_failed', message: 'That did not match. Please try again.' } })
      return calls.filter((c) => c.endsWith('/approve')).length === 1 ? stepUpRequired() : json(200, { status: 'APPROVED' })
    }))
    render(<StepUpDialog />)
    let result: Promise<unknown> = Promise.resolve()
    act(() => { result = api('/admin/refunds/r1/approve', { method: 'POST', context: 'admin' }) })
    const dialog = await screen.findByRole('dialog', { name: 'Confirm it is you' })
    expect(screen.getByLabelText('Password')).toHaveFocus()
    await user.type(screen.getByLabelText('Password'), 'wrong')
    await user.click(screen.getByRole('button', { name: 'Confirm' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('That did not match')
    await user.clear(screen.getByLabelText('Password')); await user.type(screen.getByLabelText('Password'), 'right-password')
    await user.click(screen.getByRole('button', { name: 'Confirm' }))
    await expect(result).resolves.toEqual({ status: 'APPROVED' })
    await waitFor(() => expect(dialog).not.toBeInTheDocument())
    expect(calls).toEqual(['POST /admin/refunds/r1/approve', 'POST /auth/admin/step-up', 'POST /auth/admin/step-up', 'POST /admin/refunds/r1/approve'])
  })
})
