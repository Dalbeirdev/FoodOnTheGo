/** Places & routing provider health on the admin System Status page (Module 26): the backend's answer, shown as it is. */
import { render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ProviderHealthCard, type ProviderHealthDto } from './ProviderHealthCard'

const health = (patch: Partial<ProviderHealthDto['providers'][number]['health']> = {}) => ({ state: 'UNKNOWN' as const, window_minutes: 10, calls: 0, ok: 0, failures: 0, timeouts: 0, rate_limited: 0, not_found: 0, avg_latency_ms: null, last_call_at: null, ...patch })
const report = (patch: Partial<ProviderHealthDto> = {}): ProviderHealthDto => ({
  checked_at: '2026-10-05T09:50:00+00:00', fake_allowed: true,
  providers: [
    { capability: 'places', driver: 'fake', mode: 'development', configured: true, missing: [], timeout_seconds: 5, health: health() },
    { capability: 'routing', driver: 'fake', mode: 'development', configured: true, missing: [], timeout_seconds: 8, health: health({ state: 'OPERATIONAL', calls: 12, ok: 12, avg_latency_ms: 3, last_call_at: '2026-10-05T09:49:30+00:00' }) },
  ],
  journeys: { active: 4, awaiting_prune: 1, ttl_minutes: 30 }, ...patch,
})

describe('ProviderHealthCard', () => {
  it('shows driver, mode and the health derived from real calls — "Unknown" when nothing was called', async () => {
    render(<ProviderHealthCard locale="en" load={async () => report()} />)
    const table = await screen.findByTestId('sys-providers')
    const [places, routing] = within(table).getAllByRole('row').slice(1)
    expect(places).toHaveAttribute('data-state', 'UNKNOWN')
    expect(within(places).getByText('Unknown')).toBeInTheDocument()
    expect(within(places).getByText('No call yet')).toBeInTheDocument()
    expect(within(places).getByText('Development provider')).toBeInTheDocument()
    expect(routing).toHaveAttribute('data-mode', 'development')
    expect(within(routing).getByText('Operational')).toBeInTheDocument()
    expect(within(routing).getByText('12')).toBeInTheDocument()
    expect(within(routing).getByText('3 ms')).toBeInTheDocument()
    expect(within(routing).getByText('0 / 0 / 0')).toBeInTheDocument()
    expect(screen.getByTestId('sys-journeys')).toHaveTextContent(/4.*Active journeys.*1.*Expired, awaiting removal.*30 min/)
    expect(screen.getByTestId('sys-providers-note')).toHaveTextContent(/development providers are in use.*synthetic/i)
    expect(document.body.textContent).not.toMatch(/cost|₹|\$/i) // no cost is invented
  })

  it('a live provider that is not configured says which setting is missing — by name, never a value', async () => {
    render(<ProviderHealthCard locale="en" load={async () => report({ providers: [
      { capability: 'places', driver: 'google', mode: 'live', configured: false, missing: ['PLACES_API_KEY'], timeout_seconds: 5, health: health() },
      { capability: 'routing', driver: 'google', mode: 'live', configured: true, missing: [], timeout_seconds: 8, health: health({ state: 'DEGRADED', calls: 40, ok: 33, failures: 2, timeouts: 3, rate_limited: 2, avg_latency_ms: 812, last_call_at: '2026-10-05T09:49:30+00:00' }) },
    ] })} />)
    const rows = within(await screen.findByTestId('sys-providers')).getAllByRole('row').slice(1)
    expect(within(rows[0]).getByText('Not configured')).toBeInTheDocument()
    expect(within(rows[0]).getByText('Missing: PLACES_API_KEY')).toBeInTheDocument()
    expect(within(rows[0]).getByText('Live')).toBeInTheDocument()
    expect(within(rows[1]).getByText('Degraded')).toBeInTheDocument()
    expect(within(rows[1]).getByText('2 / 3 / 2')).toBeInTheDocument()
    expect(screen.getByTestId('sys-providers-note')).toHaveTextContent(/no cost is shown/i)
  })

  it('offers a retry when the backend cannot be asked', async () => {
    render(<ProviderHealthCard locale="en" load={async () => { throw new Error('network') }} />)
    expect(await screen.findByRole('button', { name: /try again|retry/i })).toBeInTheDocument()
    expect(screen.queryByTestId('sys-providers')).toBeNull()
  })
})
