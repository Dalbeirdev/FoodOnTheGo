import { useCallback, useEffect, useId, useRef, useState, type DependencyList, type FormEvent, type ReactNode } from 'react'
import { t } from '../../../i18n/strings'
import { describeSupportError, type SupportProblem } from '../../api/ApiAdminSupportRepository'
import { Badge } from '../../components/DataTable'
import { code } from '../finance/helpers'

/**
 * What the service-desk pages (Module 37) share: a loader that keeps the backend's refusal, the labels and tones of the
 * backend's codes, user-written text rendered as text, and the small dialog every action of a case opens.
 */

/** A loader that remembers WHY it failed (forbidden, restricted, not found …) and can re-read without blanking the screen. */
export function useDesk<T>(fn: () => Promise<T>, deps: DependencyList) {
  const [data, setData] = useState<T | null>(null)
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading')
  const [problem, setProblem] = useState<SupportProblem | null>(null)
  const seq = useRef(0)
  const run = useCallback(async (quiet: boolean): Promise<T | null> => {
    const n = ++seq.current
    if (!quiet) setState('loading')
    try {
      const d = await fn()
      if (n === seq.current) { setData(d); setProblem(null); setState('ready') }
      return d
    } catch (e) {
      const p = describeSupportError(e)
      // A quiet re-read that fails keeps the last known state on screen — unless the record is no longer the viewer's to see.
      if (n === seq.current && (!quiet || p.kind === 'restricted' || p.kind === 'forbidden' || p.kind === 'not_found')) { setProblem(p); setState('error') }
      return null
    }
  }, deps) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { void run(false) }, [run])
  const reload = useCallback(() => run(false), [run])
  const refresh = useCallback(() => run(true), [run])
  // What an action just answered is newer than any read still on its way: that read is dropped, not shown over it.
  const put = useCallback((d: T) => { seq.current++; setData(d); setProblem(null); setState('ready') }, [])
  return { data, state, problem, reload, refresh, setData: put }
}

/** The label of a backend code under `adm.desk.<group>`: the string table's when it has one, else the code in plain words. */
export const deskLabel = (group: string, value: string | null | undefined, locale: string) => code(value, locale, `adm.desk.${group}`)

const STATUS_TONE: Record<string, string> = {
  OPEN: 'red', REOPENED: 'red', ASSIGNED: 'blue', IN_PROGRESS: 'blue',
  WAITING_FOR_CUSTOMER: 'amber', WAITING_FOR_RESTAURANT: 'amber', WAITING_FOR_PROVIDER: 'amber', WAITING_FOR_FINANCE: 'amber', WAITING_FOR_INTERNAL: 'amber',
  RESOLVED: 'green', CLOSED: 'muted', CANCELLED: 'muted',
}
const PRIORITY_TONE: Record<string, string> = { URGENT: 'red', HIGH: 'amber', NORMAL: 'blue', LOW: 'muted' }
// Green only for a target that is being met; a case without a target is not "fine", it is not measured.
const SLA_TONE: Record<string, string> = { ON_TRACK: 'green', AT_RISK: 'amber', BREACHED: 'red', PAUSED: 'muted', 'N/A': 'muted' }

export function StatusBadge({ status, locale }: { status: string; locale: string }) { return <Badge tone={STATUS_TONE[status] ?? 'muted'} dot>{deskLabel('status', status, locale)}</Badge> }
export function PriorityBadge({ priority, locale }: { priority: string; locale: string }) { return <Badge tone={PRIORITY_TONE[priority] ?? 'muted'}>{deskLabel('priority', priority, locale)}</Badge> }
/** The state against the INTERNAL target. The word "target" is part of the badge: it is never a promise to the requester. */
export function SlaBadge({ status, locale }: { status: string; locale: string }) { return <span data-sla={status} title={t('adm.desk.sla.title', undefined, locale)}><Badge tone={SLA_TONE[status] ?? 'muted'}>{t('adm.desk.sla.badge', { state: deskLabel('sla', status, locale) }, locale)}</Badge></span> }

/** How long ago, in the largest whole unit. */
export function age(iso: string | null | undefined, locale: string, now: number = Date.now()): string {
  if (!iso) return '—'
  const minutes = Math.max(0, Math.floor((now - new Date(iso).getTime()) / 60000))
  if (minutes < 60) return t('adm.desk.age.minutes', { n: minutes }, locale)
  if (minutes < 60 * 48) return t('adm.desk.age.hours', { n: Math.floor(minutes / 60) }, locale)
  return t('adm.desk.age.days', { n: Math.floor(minutes / 1440) }, locale)
}

/**
 * Text a person wrote (a message, a note, a subject, a reason): always a text node — never markup — with its line
 * breaks kept and its own direction.
 */
export function UserText({ children, className = '', testId }: { children: string | null | undefined; className?: string; testId?: string }) {
  return <p className={`adm-desk-text ${className}`} dir="auto" data-testid={testId}>{children ?? ''}</p>
}

/** The dialog of one action: a title, what it will do, the fields the endpoint needs, the backend's refusal, cancel / confirm. */
export function DeskDialog({ action, title, text, children, submitLabel, onSubmit, onCancel, busy, error, danger, disabled, locale }: {
  action: string; title: string; text?: string; children?: ReactNode; submitLabel: string; onSubmit: () => void | Promise<void>; onCancel: () => void
  busy?: boolean; error?: string | null; danger?: boolean; disabled?: boolean; locale: string
}) {
  const hid = useId(); const ref = useRef<HTMLFormElement>(null); const cancelRef = useRef(onCancel); cancelRef.current = onCancel
  useEffect(() => {
    const prev = document.activeElement as HTMLElement | null
    ref.current?.querySelector<HTMLElement>('select, textarea, input, button')?.focus()
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') cancelRef.current() }
    document.addEventListener('keydown', onKey)
    return () => { document.removeEventListener('keydown', onKey); prev?.focus?.() }
  }, [])
  const submit = (e: FormEvent) => { e.preventDefault(); if (busy || disabled) return; void onSubmit() }
  return (
    <div className="db-drawer__backdrop db-drawer__backdrop--center" onMouseDown={(e) => { if (e.target === e.currentTarget && !busy) onCancel() }}>
      <form className="db-dialog adm-dialog" role="dialog" aria-modal="true" aria-labelledby={hid} ref={ref} onSubmit={submit} data-testid="desk-dialog" data-action={action} noValidate>
        <h2 id={hid}>{title}</h2>
        {text && <p className="db-muted">{text}</p>}
        {children}
        {error && <p className="db-field__error" role="alert" data-testid="desk-dialog-error">{error}</p>}
        <div className="db-dialog__actions">
          <button type="button" className="db-btn db-btn--ghost" onClick={onCancel} disabled={busy}>{t('dash.action.cancel', undefined, locale)}</button>
          <button type="submit" className={`db-btn ${danger ? 'db-btn--danger' : 'db-btn--primary'}`} disabled={busy || disabled} data-testid="desk-dialog-submit">{busy ? t('dash.action.saving', undefined, locale) : submitLabel}</button>
        </div>
      </form>
    </div>
  )
}

/** A labelled field of a desk dialog. */
export function DeskField({ label, hint, children }: { label: string; hint?: string; children: (id: string) => ReactNode }) {
  const id = useId()
  return <div className="db-field"><label className="db-field__label" htmlFor={id}>{label}</label>{children(id)}{hint && <p className="db-field__hint">{hint}</p>}</div>
}
