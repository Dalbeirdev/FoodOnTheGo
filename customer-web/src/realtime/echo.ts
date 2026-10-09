/**
 * The realtime connection (Module 32): one Laravel Echo instance per sign-in context (customer / restaurant), over
 * the Pusher protocol to the local Laravel Reverb server. Private channels are authorized by the backend
 * (/customer/broadcasting/auth, /restaurant/broadcasting/auth) with the same bearer token as the REST API.
 *
 * What arrives here is a NOTICE ("something changed, here is the version"), never the order: the order is always read
 * from the API. The connection may drop; callers watch `transport` and fall back to polling.
 *
 * VITE_REALTIME_MODE: api = connect (needs VITE_ORDER_MODE=api and the Reverb settings); off = never connect, poll.
 */
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { API_BASE_URL, newRequestId, tokens, type AuthContextName } from '../api/client'

export type TransportState = 'CONNECTING' | 'CONNECTED' | 'RECONNECTING' | 'DISCONNECTED' | 'FALLBACK_POLLING' | 'OFF'

type RealtimeEnv = { mode: 'api' | 'off'; key: string; host: string; port: number; scheme: 'http' | 'https' }

export function realtimeEnv(): RealtimeEnv {
  const env = import.meta.env as Record<string, string | undefined>
  const mode = env.VITE_REALTIME_MODE === 'api' && env.VITE_ORDER_MODE === 'api' ? 'api' : 'off'
  return {
    mode,
    key: env.VITE_REVERB_APP_KEY ?? '',
    host: env.VITE_REVERB_HOST ?? '127.0.0.1',
    port: Number.parseInt(env.VITE_REVERB_PORT ?? '8085', 10) || 8085,
    scheme: env.VITE_REVERB_SCHEME === 'https' ? 'https' : 'http',
  }
}

export const realtimeEnabled = (): boolean => realtimeEnv().mode === 'api' && realtimeEnv().key !== ''

const instances: Partial<Record<AuthContextName, Echo<'reverb'>>> = {}

/** The Echo instance of a context, created on first use; null when realtime is off or the context has no token. */
export function echoFor(context: AuthContextName): Echo<'reverb'> | null {
  if (!realtimeEnabled()) return null
  const token = tokens.get(context)
  if (!token) return null
  const existing = instances[context]
  if (existing) return existing
  const env = realtimeEnv()
  ;(window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher
  const echo = new Echo({
    broadcaster: 'reverb',
    key: env.key,
    wsHost: env.host,
    wsPort: env.port,
    wssPort: env.port,
    forceTLS: env.scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: `${API_BASE_URL}/${context}/broadcasting/auth`,
    // The token is read at every authorization, so a renewed session is used and a signed-out one fails (401).
    authorizer: (channel: { name: string }) => ({
      authorize: (socketId: string, callback: (error: Error | null, data: { auth: string; channel_data?: string } | null) => void) => {
        const bearer = tokens.get(context)
        fetch(`${API_BASE_URL}/${context}/broadcasting/auth`, {
          method: 'POST',
          // The same headers as every API request: a request id for support / log correlation, the context's token.
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Request-Id': newRequestId(), ...(bearer ? { Authorization: `Bearer ${bearer}` } : {}) },
          body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
        }).then(async (r) => { if (!r.ok) { callback(new Error(`channel authorization refused (${r.status})`), null); return } callback(null, (await r.json()) as { auth: string }) }).catch((e: unknown) => callback(e instanceof Error ? e : new Error('channel authorization failed'), null))
      },
    }),
  })
  instances[context] = echo
  // Development only: the e2e suite reaches the connection to cut it and bring it back.
  if (import.meta.env.DEV) (window as unknown as { __fotgEcho?: typeof instances }).__fotgEcho = instances
  return echo
}

/** Drops the context's connection (sign-out): no channel of a gone session stays open. */
export function disconnectEcho(context: AuthContextName): void {
  const echo = instances[context]
  if (!echo) return
  try { echo.disconnect() } catch { /* already gone */ }
  delete instances[context]
}

type ConnectionLike = { state: string; bind: (event: string, cb: (change: { current: string; previous: string }) => void) => void; unbind: (event: string, cb: (change: { current: string; previous: string }) => void) => void }

/** The Pusher connection state as our transport vocabulary. */
export function transportOf(state: string): TransportState {
  switch (state) {
    case 'connected': return 'CONNECTED'
    case 'connecting': case 'initialized': return 'CONNECTING'
    case 'unavailable': return 'RECONNECTING'
    case 'failed': case 'disconnected': return 'DISCONNECTED'
    default: return 'CONNECTING'
  }
}

/** Watches the connection's state; returns an unsubscribe function. Fires once with the current state. */
export function watchTransport(echo: Echo<'reverb'>, listener: (t: TransportState) => void): () => void {
  const connection = (echo.connector as unknown as { pusher?: { connection?: ConnectionLike } }).pusher?.connection
  if (!connection) { listener('DISCONNECTED'); return () => undefined }
  const cb = (change: { current: string }) => listener(transportOf(change.current))
  connection.bind('state_change', cb)
  listener(transportOf(connection.state))
  return () => connection.unbind('state_change', cb)
}

/** The exponential backoff with jitter the fallback poll uses while the socket is away (bounded). */
export function backoffMs(attempt: number, baseMs = 2000, maxMs = 30000): number {
  const exp = Math.min(maxMs, baseMs * 2 ** Math.max(0, attempt))
  return Math.round(exp / 2 + Math.random() * (exp / 2))
}
