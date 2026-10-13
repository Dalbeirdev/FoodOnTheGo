import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { newSupportKey, SupportError } from './api/apiSupport'
import { supportErrorText } from './labels'
import type { SupportCase, SupportCaseRepository, SupportCloseIntent, SupportMessage } from './repositories'

/**
 * One support case on screen (Module 37): the case, its conversation and the three things a requester can do in it.
 * Used by the Customer Web page and the Restaurant Dashboard drawer alike.
 *
 * - The conversation is read a page at a time, oldest first on the backend; the screen starts with the NEWEST page
 *   and offers the earlier ones.
 * - There is no realtime channel for cases: while the case can still change and the tab is visible, it is read again
 *   every `pollMs` (and when the tab comes back). A failed re-read keeps what is on screen.
 * - A reply carries its own id. After an answer that never arrived the SAME text goes out with the SAME id, so
 *   nothing is written twice; a changed text or a definite refusal gets a new one.
 * - Nothing is decided here: after every action the case is what the backend answered, `can` included.
 */
export const SUPPORT_POLL_MS = 15000
const PAGE_SIZE = 50
const FINAL = ['CLOSED', 'CANCELLED']

export type SupportThread = {
  state: 'loading' | 'ready' | 'not_found' | 'error'
  case: SupportCase | null
  /** Oldest first. */
  messages: SupportMessage[]
  hasEarlier: boolean
  loadingEarlier: boolean
  loadEarlier: () => Promise<void>
  reload: () => void
  busy: 'reply' | 'close' | 'reopen' | null
  /** Why the last action did not happen — never shown as a success. */
  actionError: string | null
  clearError: () => void
  /** True when the message is in the conversation. */
  sendReply: (text: string) => Promise<boolean>
  close: (intent: SupportCloseIntent) => Promise<boolean>
  reopen: (message: string) => Promise<boolean>
}

type Stored = { message: SupportMessage; order: number }

export function useSupportCase(repo: SupportCaseRepository | null, reference: string | null, locale: string, options: { pollMs?: number } = {}): SupportThread {
  const pollMs = options.pollMs ?? SUPPORT_POLL_MS
  const [state, setState] = useState<SupportThread['state']>('loading')
  const [current, setCurrent] = useState<SupportCase | null>(null)
  const [stored, setStored] = useState<Map<string, Stored>>(new Map())
  const [lowest, setLowest] = useState(1)
  const [loadingEarlier, setLoadingEarlier] = useState(false)
  const [busy, setBusy] = useState<SupportThread['busy']>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const [tick, setTick] = useState(0)
  const highest = useRef(1)
  const seen = useRef<{ count: number; last: string | null } | null>(null)
  const pending = useRef<{ text: string; id: string } | null>(null)
  const alive = useRef(0)

  const merge = useCallback((page: number, items: SupportMessage[], replace = false) => {
    setStored((before) => {
      const next = replace ? new Map<string, Stored>() : new Map(before)
      items.forEach((message, index) => next.set(message.id, { message, order: (page - 1) * PAGE_SIZE + index }))
      return next
    })
  }, [])

  /** Reads the newest messages again: the highest page on screen and any page that came after it. */
  const readTail = useCallback(async (run: number) => {
    if (!repo || !reference) return
    for (let guard = 0; guard < 4; guard++) {
      const page = await repo.messages(reference, highest.current, PAGE_SIZE)
      if (run !== alive.current) return
      merge(page.page, page.items)
      if (page.lastPage <= highest.current) return
      highest.current += 1
    }
  }, [repo, reference, merge])

  useEffect(() => {
    const run = ++alive.current
    let mounted = true
    setState('loading'); setCurrent(null); setStored(new Map()); setActionError(null); pending.current = null; seen.current = null; highest.current = 1; setLowest(1)
    if (!repo || !reference) return
    ;(async () => {
      try {
        const c = await repo.get(reference)
        if (run !== alive.current) return
        let start = Math.max(1, Math.ceil(c.messageCount / PAGE_SIZE))
        let page = await repo.messages(reference, start, PAGE_SIZE)
        if (run !== alive.current) return
        // The count was read a moment before the page: when it named a page that does not exist, the last one is read.
        if (page.items.length === 0 && page.lastPage < start) { start = Math.max(1, page.lastPage); page = await repo.messages(reference, start, PAGE_SIZE); if (run !== alive.current) return }
        highest.current = start; setLowest(start); merge(start, page.items, true)
        seen.current = { count: c.messageCount, last: c.lastMessageAt }
        setCurrent(c); setState('ready')
        if (page.lastPage > start) { highest.current = start + 1; await readTail(run) }
      } catch (e) {
        if (run !== alive.current || !mounted) return
        setState(e instanceof SupportError && e.kind === 'not_found' ? 'not_found' : 'error')
      }
    })()
    return () => { mounted = false }
  }, [repo, reference, tick, merge, readTail])

  /** A quiet re-read: the case, and — only when it says something was written — the newest messages. */
  const refresh = useCallback(async (force = false) => {
    if (!repo || !reference) return
    const run = alive.current
    try {
      const c = await repo.get(reference)
      if (run !== alive.current) return
      setCurrent(c)
      const before = seen.current
      if (force || !before || before.count !== c.messageCount || before.last !== c.lastMessageAt) { seen.current = { count: c.messageCount, last: c.lastMessageAt }; await readTail(run) }
    } catch { /* the last known state stays on screen */ }
  }, [repo, reference, readTail])

  const status = current?.status ?? null
  const watching = state === 'ready' && status !== null && !FINAL.includes(status)
  useEffect(() => {
    if (!watching || pollMs <= 0) return
    const again = () => { if (document.visibilityState === 'visible') void refresh() }
    const timer = window.setInterval(again, pollMs)
    document.addEventListener('visibilitychange', again); window.addEventListener('focus', again)
    return () => { window.clearInterval(timer); document.removeEventListener('visibilitychange', again); window.removeEventListener('focus', again) }
  }, [watching, pollMs, refresh])

  const loadEarlier = useCallback(async () => {
    if (!repo || !reference || lowest <= 1 || loadingEarlier) return
    const run = alive.current
    setLoadingEarlier(true)
    try {
      const page = await repo.messages(reference, lowest - 1, PAGE_SIZE)
      if (run !== alive.current) return
      merge(page.page, page.items); setLowest(lowest - 1)
    } catch (e) { if (run === alive.current) setActionError(supportErrorText(e, locale)) }
    finally { if (run === alive.current) setLoadingEarlier(false) }
  }, [repo, reference, lowest, loadingEarlier, merge, locale])

  /** One action at a time; a refusal is said, and the case is read again so the screen offers what is true now. */
  const act = async (kind: NonNullable<SupportThread['busy']>, fn: () => Promise<SupportCase>): Promise<boolean> => {
    if (!repo || !reference || busy) return false
    const run = alive.current
    setBusy(kind); setActionError(null)
    try {
      const c = await fn()
      if (run !== alive.current) return true
      setCurrent(c)
      // What the action wrote (the reply, a line of the system) is read from the conversation itself.
      seen.current = { count: c.messageCount, last: c.lastMessageAt }
      try { await readTail(run) } catch { /* the next re-read brings it */ }
      return true
    } catch (e) {
      if (run !== alive.current) return false
      setActionError(supportErrorText(e, locale))
      if (e instanceof SupportError && (e.kind === 'conflict' || e.kind === 'not_found')) await refresh(true)
      throw e
    } finally { if (run === alive.current) setBusy(null) }
  }
  const quiet = (p: Promise<boolean>): Promise<boolean> => p.catch(() => false)

  const sendReply = (text: string): Promise<boolean> => {
    const body = text.trim()
    if (body === '' || !repo || !reference) return Promise.resolve(false)
    // The same text after an unanswered attempt keeps its id; anything else is a new message.
    const id = pending.current && pending.current.text === body ? pending.current.id : newSupportKey('msg')
    pending.current = { text: body, id }
    return quiet(act('reply', async () => {
      try {
        const answer = await repo.reply(reference, body, id)
        pending.current = null
        setStored((before) => (before.has(answer.message.id) ? before : new Map(before).set(answer.message.id, { message: answer.message, order: Number.MAX_SAFE_INTEGER })))
        return answer.case
      } catch (e) {
        if (!(e instanceof SupportError) || !e.unknownOutcome) pending.current = null
        throw e
      }
    }))
  }
  // What the button meant goes with the request: a case that changed in between is refused (409) and re-read, never withdrawn by a "confirm".
  const close = (intent: SupportCloseIntent): Promise<boolean> => (repo && reference ? quiet(act('close', () => repo.close(reference, intent))) : Promise.resolve(false))
  const reopen = (message: string): Promise<boolean> => (repo && reference && message.trim() !== '' ? quiet(act('reopen', () => repo.reopen(reference, message.trim()))) : Promise.resolve(false))

  const messages = useMemo(() => [...stored.values()].sort((a, b) => a.order - b.order || (a.message.createdAt ?? '').localeCompare(b.message.createdAt ?? '')).map((s) => s.message), [stored])
  return {
    state, case: current, messages, hasEarlier: lowest > 1, loadingEarlier, loadEarlier, reload: () => setTick((n) => n + 1), busy, actionError, clearError: () => setActionError(null),
    sendReply, close, reopen,
  }
}
