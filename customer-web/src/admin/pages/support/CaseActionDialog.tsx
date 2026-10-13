import { useState } from 'react'
import { t } from '../../../i18n/strings'
import { describeSupportError, SUPPORT_CANCEL_CODES, SUPPORT_LINK_TYPES, SUPPORT_PRIORITIES, SUPPORT_TEAMS, SUPPORT_WAIT_FOR, type ApiAdminSupportRepository, type SupportCaseDetail, type SupportCaseGone, type SupportCatalog, type SupportLinkPanel, type SupportProblem, type SupportWaitFor } from '../../api/ApiAdminSupportRepository'
import { DeskDialog, DeskField, deskLabel, useDesk } from './desk'

/**
 * The dialog of one action on a case (Module 37). Each action is its own endpoint with a closed body, so each dialog
 * collects exactly what that endpoint needs — a queue, a person, a reason, a resolution code — and nothing else: there
 * is no field for a status, a requester, a customer or an author anywhere.
 *
 * Every change of the case's state carries the version the administrator was LOOKING AT when the dialog opened (not a
 * version a background re-read brought in since). When the backend says the case changed meanwhile, the dialog closes
 * and the page reads the case again.
 */
export type CaseActionKind = 'assign' | 'priority' | 'start' | 'wait' | 'resume' | 'resolve' | 'close' | 'reopen' | 'cancel' | 'escalate' | 'link' | 'annotate'
const ESCALATION_REASONS = ['FINANCIAL_RISK', 'CUSTOMER_IMPACT', 'RESTAURANT_IMPACT', 'SECURITY', 'PROVIDER_ISSUE', 'SLA_RISK', 'MANAGEMENT_REVIEW', 'OTHER']
const RESOLUTION_CODES = ['ANSWERED', 'ORDER_RESOLVED', 'REFUND_COMPLETED', 'PAYMENT_RECONCILED', 'RESTAURANT_RESOLVED', 'NO_ACTION_REQUIRED', 'ESCALATED_EXTERNALLY', 'OTHER']
/** A case is withdrawn by its requester and a duplicate is cancelled (naming the case it duplicates): neither is a way to resolve. */
const NOT_A_RESOLUTION = ['USER_WITHDREW', 'DUPLICATE_CASE']
const DANGER: CaseActionKind[] = ['cancel']

const pad = (n: number) => String(n).padStart(2, '0')
/** An instant as the value of a datetime-local input (the administrator's own clock). */
const toLocalInput = (iso: string | null) => { if (!iso) return ''; const d = new Date(iso); return Number.isNaN(d.getTime()) ? '' : `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}` }

export function CaseActionDialog({ kind, c, repo, catalog, locale, onCase, onGone, onLinks, onStale, onCancel }: {
  kind: CaseActionKind; c: SupportCaseDetail; repo: ApiAdminSupportRepository; catalog: SupportCatalog | null; locale: string
  onCase: (c: SupportCaseDetail) => void; onGone: (c: SupportCaseGone) => void; onLinks: (links: SupportLinkPanel[]) => void; onStale: (p: SupportProblem) => void; onCancel: () => void
}) {
  // What the person saw when they decided — a later background re-read must not quietly replace it.
  const [seen] = useState(() => ({ version: c.version, summary: c.summary ?? '', nextAction: toLocalInput(c.next_action_at) }))
  const [team, setTeam] = useState(''); const [admin, setAdmin] = useState(''); const [unassign, setUnassign] = useState(false)
  const [priority, setPriority] = useState(c.priority)
  const [reason, setReason] = useState(''); const [note, setNote] = useState('')
  const [waitFor, setWaitFor] = useState<SupportWaitFor>(c.requester.type === 'CUSTOMER' ? 'CUSTOMER' : c.requester.type === 'RESTAURANT' ? 'RESTAURANT' : 'INTERNAL')
  const [resolution, setResolution] = useState(''); const [duplicateOf, setDuplicateOf] = useState('')
  const [level, setLevel] = useState(catalog?.escalation_levels[0] ?? 'L2')
  const [linkType, setLinkType] = useState(''); const [reference, setReference] = useState('')
  const [summary, setSummary] = useState(seen.summary); const [nextAction, setNextAction] = useState(seen.nextAction)
  const [busy, setBusy] = useState(false); const [error, setError] = useState<string | null>(null)
  const people = kind === 'assign' || kind === 'escalate'
  const agents = useDesk(() => (people ? repo.agents(c.market) : Promise.resolve([])), [repo, people, c.market])

  const teams = catalog?.teams ?? [...SUPPORT_TEAMS]
  const say = (key: string, params?: Record<string, string | number>) => t(`adm.desk.${key}`, params, locale)
  const short = (v: string) => v.trim().length < 3

  const invalid = (): string | null => {
    switch (kind) {
      case 'assign': return !team && !admin && !unassign ? say('error.assign') : null
      case 'priority': return short(reason) ? say('error.reason') : priority === c.priority ? say('error.samePriority') : null
      case 'resolve': return !resolution ? say('error.resolution') : short(note) ? say('error.note') : null
      case 'reopen': return short(reason) ? say('error.reason') : null
      case 'cancel': return !resolution ? say('error.resolution') : short(note) ? say('error.note') : resolution === 'DUPLICATE_CASE' && duplicateOf.trim() === '' ? say('error.duplicateOf') : null
      case 'escalate': return !reason ? say('error.escalationReason') : short(note) ? say('error.note') : null
      case 'link': return !linkType || reference.trim() === '' ? say('error.link') : null
      default: return null
    }
  }

  const run = async () => {
    const problem = invalid()
    if (problem) { setError(problem); return }
    setBusy(true); setError(null)
    const v = seen.version
    try {
      switch (kind) {
        case 'assign': onCase(await repo.assign(c.id, { team: team || null, admin: unassign ? null : admin || null, unassign, note }, v)); break
        case 'priority': onCase(await repo.priority(c.id, priority, reason, v)); break
        case 'start': onCase(await repo.start(c.id, reason, v)); break
        case 'resume': onCase(await repo.resume(c.id, reason, v)); break
        case 'wait': onCase(await repo.wait(c.id, waitFor, reason, v)); break
        case 'resolve': onCase(await repo.resolve(c.id, resolution, note, v)); break
        case 'close': onCase(await repo.close(c.id, v)); break
        case 'reopen': onCase(await repo.reopen(c.id, reason, v)); break
        case 'cancel': onCase(await repo.cancel(c.id, { code: resolution, note, duplicateOf }, v)); break
        case 'escalate': { const r = await repo.escalate(c.id, { reason, level, team: team || null, admin: admin || null, note }, v); if ('visible' in r) onGone(r); else onCase(r); break }
        case 'link': onLinks(await repo.link(c.id, linkType, reference)); break
        case 'annotate': onCase(await repo.annotate(c.id, {
          ...(summary !== seen.summary ? { summary: summary.trim() === '' ? null : summary } : {}),
          ...(nextAction !== seen.nextAction ? { nextActionAt: nextAction ? new Date(nextAction).toISOString() : null } : {}),
        }, v)); break
      }
    } catch (e) {
      const p = describeSupportError(e)
      if (p.reload) onStale(p); else setError(p.message)
    } finally { setBusy(false) }
  }

  const reasonBox = (label: string, hint?: string) => <DeskField label={label} hint={hint}>{(id) => <textarea id={id} className="db-textarea" rows={3} value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} data-testid="desk-field-reason" />}</DeskField>
  const noteBox = (label: string, max = 1000) => <DeskField label={label}>{(id) => <textarea id={id} className="db-textarea" rows={3} value={note} maxLength={max} onChange={(e) => setNote(e.target.value)} data-testid="desk-field-note" />}</DeskField>
  const teamSelect = (empty: string) => <DeskField label={say('field.team')}>{(id) => (
    <select id={id} className="db-select" value={team} onChange={(e) => setTeam(e.target.value)} data-testid="desk-field-team">
      <option value="">{empty}</option>
      {teams.map((x) => <option key={x} value={x}>{deskLabel('team', x, locale)}</option>)}
    </select>)}</DeskField>
  const personSelect = (empty: string) => <DeskField label={say('field.admin')} hint={agents.state === 'error' ? say('agents.unavailable') : c.classification === 'RESTRICTED' ? say('agents.restricted') : undefined}>{(id) => (
    <select id={id} className="db-select" value={unassign ? '' : admin} onChange={(e) => setAdmin(e.target.value)} disabled={unassign} data-testid="desk-field-admin">
      <option value="">{empty}</option>
      {(agents.data ?? []).map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
    </select>)}</DeskField>
  const codeSelect = (codes: readonly string[]) => <DeskField label={say('field.resolution')}>{(id) => (
    <select id={id} className="db-select" value={resolution} onChange={(e) => setResolution(e.target.value)} data-testid="desk-field-resolution">
      <option value="">{say('choose')}</option>
      {codes.map((x) => <option key={x} value={x}>{deskLabel('resolution', x, locale)}</option>)}
    </select>)}</DeskField>

  return (
    <DeskDialog action={kind} title={say(`action.${kind}`)} text={say(`action.${kind}.text`, { case: c.case_number })} submitLabel={say(`action.${kind}.confirm`)} onSubmit={run} onCancel={onCancel} busy={busy} error={error} danger={DANGER.includes(kind)} locale={locale}>
      {kind === 'assign' && <>
        {teamSelect(say('assign.keepTeam', { team: c.assigned.team ? deskLabel('team', c.assigned.team, locale) : say('none') }))}
        {personSelect(say('assign.noPerson'))}
        <label className="db-check"><input type="checkbox" checked={unassign} onChange={(e) => setUnassign(e.target.checked)} disabled={!c.assigned.admin} data-testid="desk-field-unassign" /> <span>{c.assigned.admin ? say('assign.unassign', { name: c.assigned.admin.name }) : say('assign.nobody')}</span></label>
        {noteBox(say('field.noteOptional'), 500)}
      </>}
      {kind === 'priority' && <>
        <DeskField label={say('field.priority')}>{(id) => (
          <select id={id} className="db-select" value={priority} onChange={(e) => setPriority(e.target.value)} data-testid="desk-field-priority">
            {(catalog?.priorities ?? SUPPORT_PRIORITIES).map((x) => <option key={x} value={x}>{deskLabel('priority', x, locale)}</option>)}
          </select>)}</DeskField>
        {reasonBox(say('field.reason'))}
      </>}
      {(kind === 'start' || kind === 'resume') && reasonBox(say('field.reasonOptional'))}
      {kind === 'wait' && <>
        <DeskField label={say('field.waitFor')}>{(id) => (
          <select id={id} className="db-select" value={waitFor} onChange={(e) => setWaitFor(e.target.value as SupportWaitFor)} data-testid="desk-field-wait-for">
            {SUPPORT_WAIT_FOR.map((x) => <option key={x} value={x}>{deskLabel('waitFor', x, locale)}</option>)}
          </select>)}</DeskField>
        {reasonBox(say('field.reasonOptional'))}
      </>}
      {kind === 'resolve' && <>
        {codeSelect((catalog?.resolution_codes ?? RESOLUTION_CODES).filter((x) => !NOT_A_RESOLUTION.includes(x)))}
        {noteBox(say('field.resolutionNote'))}
        <p className="db-muted adm-small">{say('resolve.hint')}</p>
      </>}
      {kind === 'reopen' && reasonBox(say('field.reason'))}
      {kind === 'cancel' && <>
        {codeSelect(SUPPORT_CANCEL_CODES)}
        {resolution === 'DUPLICATE_CASE' && <DeskField label={say('field.duplicateOf')} hint={say('cancel.duplicateHint')}>{(id) => <input id={id} className="db-input" value={duplicateOf} maxLength={40} onChange={(e) => setDuplicateOf(e.target.value)} data-testid="desk-field-duplicate-of" />}</DeskField>}
        {noteBox(say('field.note'))}
      </>}
      {kind === 'escalate' && <>
        <DeskField label={say('field.escalationReason')}>{(id) => (
          <select id={id} className="db-select" value={reason} onChange={(e) => setReason(e.target.value)} data-testid="desk-field-reason">
            <option value="">{say('choose')}</option>
            {(catalog?.escalation_reasons ?? ESCALATION_REASONS).map((x) => <option key={x} value={x}>{deskLabel('escalation', x, locale)}</option>)}
          </select>)}</DeskField>
        <DeskField label={say('field.level')}>{(id) => (
          <select id={id} className="db-select" value={level} onChange={(e) => setLevel(e.target.value)} data-testid="desk-field-level">
            {(catalog?.escalation_levels ?? ['L2', 'L3']).map((x) => <option key={x} value={x}>{x}</option>)}
          </select>)}</DeskField>
        {teamSelect(say('escalate.noTeam'))}
        {personSelect(say('escalate.noPerson'))}
        {noteBox(say('field.note'))}
        <p className={reason === 'SECURITY' || team === 'SECURITY' ? 'adm-desk-warning' : 'db-muted adm-small'} role="note" data-testid="desk-escalate-security-warning">{say('escalate.securityWarning')}</p>
      </>}
      {kind === 'link' && <>
        <DeskField label={say('field.linkType')}>{(id) => (
          <select id={id} className="db-select" value={linkType} onChange={(e) => setLinkType(e.target.value)} data-testid="desk-field-link-type">
            <option value="">{say('choose')}</option>
            {(catalog?.link_types ?? SUPPORT_LINK_TYPES).map((x) => <option key={x} value={x}>{deskLabel('link', x, locale)}</option>)}
          </select>)}</DeskField>
        <DeskField label={say('field.linkReference')} hint={say('link.hint')}>{(id) => <input id={id} className="db-input" value={reference} maxLength={80} onChange={(e) => setReference(e.target.value)} data-testid="desk-field-link-reference" />}</DeskField>
      </>}
      {kind === 'annotate' && <>
        <DeskField label={say('field.summary')} hint={say('annotate.hint')}>{(id) => <textarea id={id} className="db-textarea" rows={4} value={summary} maxLength={20000} onChange={(e) => setSummary(e.target.value)} dir="auto" data-testid="desk-field-summary" />}</DeskField>
        <DeskField label={say('field.nextAction')}>{(id) => <input id={id} type="datetime-local" className="db-input" value={nextAction} onChange={(e) => setNextAction(e.target.value)} data-testid="desk-field-next-action" />}</DeskField>
      </>}
    </DeskDialog>
  )
}
