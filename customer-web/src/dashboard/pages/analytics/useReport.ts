import { useCallback, useEffect, useRef, useState, type DependencyList } from 'react'

export type ReportState<T> = { data: T | null; state: 'idle' | 'loading' | 'ready' | 'error'; error: unknown; reload: () => void }
type Held<T> = { key: string; data: T | null; state: ReportState<T>['state']; error: unknown }

/**
 * Loads on mount and whenever a dependency changes, and keeps WHY a load failed (a 403 and a 422 are shown differently
 * from a failure). An answer that arrives after a newer request is dropped. `fn` null = nothing to ask right now.
 *
 * `key` names what is being asked (a tab and its filters): an answer — or a refusal — is only ever handed out for the
 * question it belongs to, so the screen never shows the previous tab's figures or error under the new tab's name.
 */
export function useReport<T>(fn: (() => Promise<T>) | null, deps: DependencyList, key = ''): ReportState<T> {
  const [held, setHeld] = useState<Held<T>>({ key, data: null, state: fn ? 'loading' : 'idle', error: null })
  const [attempt, setAttempt] = useState(0)
  const seq = useRef(0); const latest = useRef({ fn, key }); latest.current = { fn, key }
  useEffect(() => {
    const n = ++seq.current; const { fn: ask, key: asked } = latest.current
    if (!ask) { setHeld({ key: asked, data: null, state: 'idle', error: null }); return }
    setHeld({ key: asked, data: null, state: 'loading', error: null })
    ask().then(
      (data) => { if (n === seq.current) setHeld({ key: asked, data, state: 'ready', error: null }) },
      (error: unknown) => { if (n === seq.current) setHeld({ key: asked, data: null, state: 'error', error }) },
    )
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, key, attempt])
  // Leaving the page drops whatever is still on its way.
  useEffect(() => () => { seq.current++ }, [])
  const reload = useCallback(() => setAttempt((x) => x + 1), [])
  if (held.key !== key) return { data: null, state: fn ? 'loading' : 'idle', error: null, reload }
  return { data: held.data, state: held.state, error: held.error, reload }
}
