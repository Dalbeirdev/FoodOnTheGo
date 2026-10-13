import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Card, ErrorState, Skeleton, ToastLine, useToastMessage } from '../../dashboard/components/ui'
import { t } from '../../i18n/strings'
import { useAdmin } from '../AdminContext'
import { BASE } from '../AdminLayout'
import { ApiAdminSupportRepository, type SupportCaseDetail, type SupportEvent, type SupportLinkPanel, type SupportMessage, type SupportNote, type SupportProblem } from '../api/ApiAdminSupportRepository'
import { Badge, Details } from '../components/DataTable'
import { fmtDateTime, usePageTitle } from './shared'
import { CaseActionDialog, type CaseActionKind } from './support/CaseActionDialog'
import { Composer } from './support/Composer'
import { LinkPanels } from './support/LinkPanels'
import { PriorityBadge, SlaBadge, StatusBadge, UserText, deskLabel, useDesk } from './support/desk'

/**
 * One support case on the backend (Module 37) — the service desk's working page.
 *
 * TWO STREAMS, NEVER ONE LIST. "Conversation with the requester" holds the EXTERNAL messages; "Internal notes" holds
 * the INTERNAL ones — another endpoint, another block, another colour, and asked for only when the backend says this
 * administrator may read them (`can.notes`). The composer says where a text goes and starts on the internal note.
 *
 * WHAT CAN BE DONE is the backend's `can` block: a button exists only for a `true`. Every action is a small dialog for
 * its own endpoint; none of them carries a status, a requester, a customer or an author. A change of state sends the
 * version the administrator was looking at; when the case changed meanwhile it is read again and the page says so.
 *
 * LINKED RECORDS are read-only panels of what the other domains hold now. A case coordinates work — it cannot mark a
 * payment verified, complete a refund or edit a settlement or a payout, and the page says that too.
 *
 * The open case is read again every 20 seconds while the tab is visible (the backend's realtime for the desk is polling).
 */
const POLL_MS = 20000
const WAITING = 'WAITING_FOR_'
/** The detail keys of an event that are worth a word on the timeline (codes and references — never free text of a message). */
const EVENT_DETAIL = ['reason', 'resolution_code', 'duplicate_of', 'priority', 'from', 'to', 'team', 'level', 'link_type', 'reference', 'action', 'before', 'after']

const eventDetail = (e: SupportEvent): string => EVENT_DETAIL.map((k) => { const v = e.detail?.[k]; return typeof v === 'string' || typeof v === 'number' ? `${k.replace(/_/g, ' ')}: ${v}` : null }).filter(Boolean).join(' · ')

export function BackendSupportCasePage({ repository, pollMs = POLL_MS }: { repository?: ApiAdminSupportRepository; pollMs?: number }) {
  const a = useAdmin(); const locale = a.locale; const nav = useNavigate(); usePageTitle('adm.nav.support')
  const params = useParams(); const ref = params.caseId ?? params.id ?? ''
  const repo = useMemo(() => repository ?? new ApiAdminSupportRepository(), [repository])
  const detail = useDesk(() => repo.get(ref), [repo, ref])
  // A re-read that found the case gone from this viewer's sight (restricted meanwhile, permission taken away) closes it.
  const blocked = detail.state === 'error' && detail.problem !== null && ['restricted', 'forbidden', 'not_found'].includes(detail.problem.kind)
  const c = !blocked && detail.data && (detail.data.id === ref || detail.data.case_number.toUpperCase() === ref.toUpperCase()) ? detail.data : null
  const id = c?.id ?? ''; const canNotes = c?.can.notes === true
  const messages = useDesk(() => (id ? repo.messages(id) : Promise.resolve<SupportMessage[]>([])), [repo, id])
  // Internal notes are not even asked for when the backend says this viewer may not read them.
  const notes = useDesk(() => (id && canNotes ? repo.notes(id) : Promise.resolve<SupportNote[]>([])), [repo, id, canNotes])
  const events = useDesk(() => (id ? repo.events(id) : Promise.resolve<SupportEvent[]>([])), [repo, id])
  const links = useDesk(() => (id ? repo.links(id) : Promise.resolve<SupportLinkPanel[]>([])), [repo, id])
  const catalog = useDesk(() => repo.catalog(), [repo])
  const [acting, setActing] = useState<CaseActionKind | null>(null)
  const [conflict, setConflict] = useState<string | null>(null)
  const { msg, toast } = useToastMessage()
  const say = (key: string, p?: Record<string, string | number>) => t(`adm.desk.${key}`, p, locale)

  const readAgain = useRef<() => void>(() => {})
  readAgain.current = () => { void detail.refresh(); void messages.refresh(); if (canNotes) void notes.refresh(); void events.refresh(); void links.refresh() }
  useEffect(() => {
    if (!id || pollMs <= 0) return
    const again = () => { if (document.visibilityState === 'visible') readAgain.current() }
    const timer = window.setInterval(again, pollMs)
    document.addEventListener('visibilitychange', again)
    return () => { window.clearInterval(timer); document.removeEventListener('visibilitychange', again) }
  }, [id, pollMs])

  /** The case on screen is not the case any more: read everything again and say so. */
  const stale = (p: SupportProblem) => { setActing(null); setConflict(p.message); readAgain.current() }
  const changed = (next: SupportCaseDetail) => { detail.setData(next); setActing(null); setConflict(null); toast(t('adm.updated', undefined, locale)); void events.refresh() }

  if (detail.state === 'loading' && !c) return <div className="db-page" data-testid="desk-case-loading"><Skeleton rows={6} /></div>
  if (!c) {
    const p = detail.problem; const back = <Link to={`${BASE}/support`} className="db-link">← {say('back')}</Link>
    if (p?.kind === 'restricted') return <div className="db-page" data-testid="desk-case-restricted">{back}<ErrorState title={say('restricted.title')} text={say('restricted.text')} locale={locale} /></div>
    if (p?.kind === 'forbidden') return <div className="db-page" data-testid="desk-case-forbidden">{back}<ErrorState title={say('forbidden.title')} text={say('forbidden.text')} locale={locale} /></div>
    if (p?.kind === 'not_found') return <div className="db-page" data-testid="desk-case-not-found">{back}<ErrorState title={say('notFound')} locale={locale} /></div>
    return <div className="db-page" data-testid="desk-case-error">{back}<ErrorState title={t('adm.error.loadTitle', undefined, locale)} text={p?.message} onRetry={() => { void detail.reload() }} locale={locale} /></div>
  }

  const waiting = c.status.startsWith(WAITING)
  // "Start" and "Resume" are the same step (to IN_PROGRESS) under two names: a case that waits is resumed, any other is started.
  const actions: Array<[CaseActionKind, boolean]> = [
    ['assign', c.can.assign], ['priority', c.can.priority], ['start', c.can.start && !waiting], ['wait', c.can.wait], ['resume', c.can.resume],
    ['resolve', c.can.resolve], ['close', c.can.close], ['reopen', c.can.reopen], ['cancel', c.can.cancel], ['escalate', c.can.escalate], ['link', c.can.link], ['annotate', c.can.annotate],
  ]
  const offered = actions.filter(([, on]) => on)
  const requester = c.requester
  const restricted = c.classification === 'RESTRICTED'
  const limits = catalog.data?.limits ?? { subject_max: 140, message_max: 4000, note_max: 4000 }
  const when = (iso: string | null) => fmtDateTime(iso, locale)

  return (
    <div className="db-page adm-desk-case" data-testid="desk-case" data-case={c.case_number} data-id={c.id} data-status={c.status} data-priority={c.priority} data-version={c.version} data-classification={c.classification}>
      <Link to={`${BASE}/support`} className="db-link">← {say('back')}</Link>
      {restricted && <p className="adm-desk-restricted" role="note" data-testid="desk-restricted-banner" data-class={c.restricted_class ?? ''}>{say('restricted.banner', { class: c.restricted_class ?? '' })}</p>}
      {conflict && <p className="adm-desk-conflict" role="alert" data-testid="desk-conflict-notice">{conflict} <button type="button" className="db-link" onClick={() => setConflict(null)}>{say('dismiss')}</button></p>}

      <div className="db-page__head">
        <div>
          <h1 className="db-page__title"><span data-testid="desk-case-number">{c.case_number}</span></h1>
          <UserText className="adm-desk-subject" testId="desk-subject">{c.subject}</UserText>
          <p className="db-page__lead adm-desk-badges">
            <span data-testid="desk-status"><StatusBadge status={c.status} locale={locale} /></span> <span data-testid="desk-priority"><PriorityBadge priority={c.priority} locale={locale} /></span>
            {c.escalation_level && <> <span data-testid="desk-escalation-level"><Badge tone="purple">{say('escalated', { level: c.escalation_level })}</Badge></span></>}
            {c.awaiting_reply && <> <span data-testid="desk-awaiting"><Badge tone="red">{say('awaitingReply')}</Badge></span></>}
          </p>
        </div>
      </div>
      {/* The version the next change will carry (expected_version). Never shown, never typed. */}
      <input type="hidden" name="expected_version" value={c.version} readOnly data-testid="desk-version" />

      <div className="adm-grid--21">
        <div className="db-grid" style={{ gap: 16, alignContent: 'start' }}>
          <div data-testid="desk-conversation" data-visibility="EXTERNAL"><Card title={say('conversation.title')} subtitle={say('conversation.subtitle')}>
            <div className="adm-desk-stream">
              {messages.state === 'loading' && !messages.data && <Skeleton rows={2} />}
              {messages.state === 'error' && <p className="db-field__error" role="alert">{messages.problem?.message}</p>}
              {messages.data && messages.data.length === 0 && <p className="db-muted" data-testid="desk-conversation-empty">{say(requester.type === 'CUSTOMER' || requester.type === 'RESTAURANT' ? 'conversation.empty' : 'conversation.noRequester')}</p>}
              {(messages.data ?? []).map((m) => (
                <article key={m.id} className={`adm-msg adm-desk-msg ${m.author_type === 'SUPPORT' ? 'adm-msg--admin' : ''}`} data-testid="desk-message" data-author={m.author_type} data-id={m.id}>
                  <div className="adm-msg__meta"><b dir="auto">{m.author_name ?? deskLabel('author', m.author_type, locale)}</b><span>{deskLabel('author', m.author_type, locale)}</span><span>{when(m.created_at)}</span></div>
                  <UserText>{m.body}</UserText>
                </article>
              ))}
            </div>
          </Card></div>

          {/* The internal stream is its own block: it is never merged into the conversation, and it is not asked for without the permission. */}
          <div data-testid="desk-notes" data-visibility="INTERNAL" data-readable={canNotes ? 'true' : 'false'}><Card title={say('notes.title')} className="adm-desk-notes-card">
            <p className="adm-desk-internal-label" data-testid="desk-notes-label">{say('notes.label')}</p>
            {canNotes ? (
              <div className="adm-desk-stream">
                {notes.state === 'loading' && !notes.data && <Skeleton rows={2} />}
                {notes.state === 'error' && <p className="db-field__error" role="alert">{notes.problem?.message}</p>}
                {notes.data && notes.data.length === 0 && <p className="db-muted" data-testid="desk-notes-empty">{say('notes.empty')}</p>}
                {(notes.data ?? []).map((n) => (
                  <article key={n.id} className="adm-msg adm-msg--internal adm-desk-msg" data-testid="desk-note" data-system={n.system ? 'true' : 'false'} data-id={n.id}>
                    <div className="adm-msg__meta"><b dir="auto">{n.system ? say('author.SYSTEM') : n.author_name}</b><span className="adm-msg__internal">{say('notes.badge')}</span><span>{when(n.created_at)}</span></div>
                    <UserText>{n.body}</UserText>
                  </article>
                ))}
              </div>
            ) : <p className="db-muted" data-testid="desk-notes-forbidden">{say('notes.forbidden')}</p>}
          </Card></div>

          <Card title={say('composer.title')}>
            <Composer key={c.id} c={c} repo={repo} limits={limits} locale={locale} onStale={stale}
              onReplied={(r) => { messages.setData([...(messages.data ?? []).filter((m) => m.id !== r.message.id), r.message]); detail.setData(r.case); setConflict(null); toast(say('composer.sent')); void events.refresh() }}
              onNoted={(n) => { notes.setData([...(notes.data ?? []).filter((x) => x.id !== n.id), n]); setConflict(null); toast(say('composer.saved')); void detail.refresh(); void events.refresh() }} />
          </Card>

          <Card title={say('timeline.title')} subtitle={say('timeline.subtitle')}>
            {events.data && events.data.length === 0 ? <p className="db-muted">{say('timeline.empty')}</p> : (
              <ol className="db-timeline" data-testid="desk-timeline">
                {(events.data ?? []).map((e) => (
                  <li key={e.sequence} data-testid="desk-event" data-type={e.type} data-sequence={e.sequence} data-audience={e.audience}>
                    <span className={`db-timeline__dot ${e.type === 'STEP_REFUSED' || e.type === 'SLA_BREACHED' ? 'db-timeline__dot--stop' : ''}`} />
                    <span>
                      <b>#{e.sequence} {deskLabel('event', e.type, locale)}</b>
                      {(e.from_status || e.to_status) && <> · {e.from_status ? deskLabel('status', e.from_status, locale) : '—'} → {e.to_status ? deskLabel('status', e.to_status, locale) : '—'}</>}
                      <small className="db-muted"> · <span dir="auto">{e.actor_name ?? deskLabel('author', e.actor_type, locale)}</span> · {when(e.occurred_at)}{e.audience === 'INTERNAL' ? ` · ${say('timeline.internal')}` : ''}</small>
                      {eventDetail(e) && <small className="db-muted adm-desk-event__detail" dir="auto">{eventDetail(e)}</small>}
                    </span>
                  </li>
                ))}
              </ol>
            )}
          </Card>

          <div className="db-grid db-grid--2">
            <Card title={say('assignments.title')}>
              <ul className="db-timeline" data-testid="desk-assignments">
                {c.assignments.length === 0 && <li className="db-muted">{say('assignments.empty')}</li>}
                {c.assignments.map((x, i) => (
                  <li key={i} data-testid="desk-assignment" data-source={x.source}><span className="db-timeline__dot" /><span><b>{x.team ? deskLabel('team', x.team, locale) : '—'}</b>{x.admin ? <> · <span dir="auto">{x.admin}</span></> : <> · {say('unassigned')}</>}<small className="db-muted"> · {deskLabel('assignmentSource', x.source, locale)}{x.by ? ` · ${x.by}` : ''} · {when(x.at)}</small>{x.note && <UserText className="adm-desk-inline">{x.note}</UserText>}</span></li>
                ))}
              </ul>
            </Card>
            <Card title={say('escalations.title')}>
              <ul className="db-timeline" data-testid="desk-escalations">
                {c.escalations.length === 0 && <li className="db-muted">{say('escalations.empty')}</li>}
                {c.escalations.map((x) => (
                  <li key={x.id} data-testid="desk-escalation" data-reason={x.reason} data-level={x.level} data-status={x.status}><span className={`db-timeline__dot ${x.status === 'OPEN' ? 'db-timeline__dot--stop' : ''}`} /><span><b>{x.level} · {deskLabel('escalation', x.reason, locale)}</b> <Badge tone={x.status === 'OPEN' ? 'red' : 'muted'}>{deskLabel('escalationStatus', x.status, locale)}</Badge><small className="db-muted"> · {x.from_team ? deskLabel('team', x.from_team, locale) : '—'} → {x.to_team ? deskLabel('team', x.to_team, locale) : '—'}{x.to_admin ? ` (${x.to_admin})` : ''} · {x.by === 'SYSTEM' ? say('author.SYSTEM') : x.by ?? ''} · {when(x.at)}</small><UserText className="adm-desk-inline">{x.note}</UserText></span></li>
                ))}
              </ul>
            </Card>
          </div>
        </div>

        <div className="db-grid" style={{ gap: 16, alignContent: 'start' }}>
          <Card title={say('actions.title')}>
            <div className="adm-desk-actions" data-testid="desk-actions">
              {offered.map(([kind]) => <button key={kind} type="button" className={`db-btn db-btn--sm ${kind === 'cancel' ? 'db-btn--danger' : kind === 'resolve' ? 'db-btn--primary' : 'db-btn--outline'}`} onClick={() => setActing(kind)} data-testid={`desk-action-${kind}`}>{say(`action.${kind}`)}</button>)}
            </div>
            {offered.length === 0 && <p className="db-muted" data-testid="desk-no-actions">{say('actions.none')}</p>}
            <p className="db-muted adm-small" data-testid="desk-domain-authority">{say('domainAuthority')}</p>
          </Card>

          <Card title={say('details.title')}>
            <Details rows={[
              [say('col.category'), <span key="c" data-testid="desk-category">{deskLabel('category', c.category, locale)}{c.subcategory ? ` / ${deskLabel('subcategory', c.subcategory, locale)}` : ''}</span>],
              [say('col.requester'), (
                <span key="r" data-testid="desk-requester" data-type={requester.type}>
                  <Badge tone="muted">{deskLabel('requester', requester.type, locale)}</Badge>
                  {requester.name ? <> <span dir="auto">{requester.name}</span></> : null}
                  {requester.phone ? <> · <span data-testid="desk-requester-phone">{requester.phone}</span></> : null}
                  {requester.type === 'CUSTOMER' && !requester.name && !requester.phone && <small className="db-muted"> · {say('requester.hidden')}</small>}
                  {(requester.type === 'ADMIN' || requester.type === 'SYSTEM') && <small className="db-muted"> · {say('requester.none')}</small>}
                </span>)],
              [say('col.restaurant'), c.restaurant ? <span key="rs" dir="auto">{c.restaurant.name}</span> : '—'],
              [say('field.market'), c.market ?? say('market.none')],
              [say('details.source'), deskLabel('source', c.source, locale)],
              [say('details.classification'), <span key="cl" data-testid="desk-classification">{restricted ? say('restricted.badge', { class: c.restricted_class ?? '' }) : deskLabel('classification', c.classification, locale)}</span>],
              [say('details.escalation'), c.escalation_level ? `${c.escalation_level}${c.escalated_at ? ` · ${when(c.escalated_at)}` : ''}` : say('details.notEscalated')],
              [say('col.assigned'), <span key="as" data-testid="desk-assigned">{c.assigned.team ? deskLabel('team', c.assigned.team, locale) : '—'} · <span dir="auto">{c.assigned.admin?.name ?? say('unassigned')}</span></span>],
              [say('details.created'), when(c.created_at)],
              [say('details.updated'), when(c.updated_at)],
              ...(c.reopen_count > 0 ? [[say('details.reopened'), `${c.reopen_count}${c.reopened_at ? ` · ${when(c.reopened_at)}` : ''}`] as [string, ReactNode]] : []),
              ...(c.next_action_at ? [[say('field.nextAction'), <span key="na" data-testid="desk-next-action">{when(c.next_action_at)}</span>] as [string, ReactNode]] : []),
            ]} />
            {c.summary && <div className="adm-note" data-testid="desk-summary"><small>{say('field.summary')}</small><UserText>{c.summary}</UserText></div>}
            {c.resolution && <div className="adm-note" data-testid="desk-resolution" data-code={c.resolution.code}><small>{say('details.resolution')}</small><b>{deskLabel('resolution', c.resolution.code, locale)}</b>{c.resolution.duplicate_of ? <> · {say('details.duplicateOf')} <Link to={`${BASE}/support/${encodeURIComponent(c.resolution.duplicate_of)}`} className="db-link">{c.resolution.duplicate_of}</Link></> : null}{c.resolution.by ? ` · ${c.resolution.by}` : ''}<UserText>{c.resolution.note}</UserText></div>}
          </Card>

          <div data-testid="desk-sla" data-status={c.sla.status} data-contractual="false"><Card title={say('sla.heading')}>
            <div>
              <p style={{ margin: '0 0 8px' }}><SlaBadge status={c.sla.status} locale={locale} /></p>
              {c.sla.status === 'N/A' && !c.sla.policy ? <p className="db-muted">{say('sla.none')}</p> : (
                <Details rows={[
                  [say('sla.policy'), c.sla.policy ? <span key="p" dir="auto">{c.sla.policy.name}{c.sla.policy.approval ? ` · ${c.sla.policy.approval}` : ''}</span> : '—'],
                  [say('sla.firstResponseDue'), when(c.sla.first_response_due_at)],
                  [say('sla.firstResponseAt'), c.sla.first_response_at ? when(c.sla.first_response_at) : say('sla.notYet')],
                  [say('sla.resolutionDue'), when(c.sla.resolution_due_at)],
                  ...(c.sla.paused_at ? [[say('sla.pausedAt'), when(c.sla.paused_at)] as [string, ReactNode]] : []),
                  ...(c.sla.at_risk_at ? [[say('sla.atRiskAt'), when(c.sla.at_risk_at)] as [string, ReactNode]] : []),
                  ...(c.sla.breached_at ? [[say('sla.breachedAt'), when(c.sla.breached_at)] as [string, ReactNode]] : []),
                ]} />
              )}
              <p className="db-muted adm-small">{say('sla.note')}</p>
            </div>
          </Card></div>

          <Card title={say('links.title')} subtitle={say('links.subtitle')}>
            {links.state === 'loading' && !links.data && <Skeleton rows={3} />}
            {links.state === 'error' && <p className="db-field__error" role="alert">{links.problem?.message}</p>}
            {links.data && <LinkPanels caseId={c.id} panels={links.data} repo={repo} locale={locale} onPanels={(p) => links.setData(p)} onActed={() => { void events.refresh() }} />}
          </Card>
        </div>
      </div>

      {acting && <CaseActionDialog kind={acting} c={c} repo={repo} catalog={catalog.data} locale={locale} onCancel={() => setActing(null)} onStale={stale} onCase={changed}
        onLinks={(p) => { links.setData(p); setActing(null); toast(t('adm.updated', undefined, locale)); void events.refresh() }}
        onGone={(g) => nav(`${BASE}/support`, { state: { deskNotice: say('escalate.gone', { case: g.case_number, class: g.restricted_class ?? '' }) } })} />}
      <ToastLine msg={msg} />
    </div>
  )
}
