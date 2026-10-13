import { useCallback, useEffect, useMemo, useRef, useState, type DependencyList, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { t } from '../../i18n/strings'
import { SupportAttempt, SupportError } from '../../support/api/apiSupport'
import { categoryLabel, linkStatusLabel, linkTypeLabel, statusLabel, statusTone, subcategoryLabel, supportDay, supportDayTime, supportErrorText, supportMoney, textLength } from '../../support/labels'
import type { NewRestaurantSupportCase, RestaurantSupportCatalog, RestaurantSupportRepository, SupportCase, SupportLink } from '../../support/repositories'
import { useSupportCase } from '../../support/useSupportCase'
import { useDashboard } from '../DashboardContext'
import { BASE } from '../DashboardLayout'
import { Card, Drawer, EmptyState, ErrorState, Field, Icon, PageHeader, Pill, Skeleton, Tabs, ToastLine, useToastMessage } from '../components/ui'
import './SupportPage.css'

/**
 * Support (Module 37): the restaurant's cases with FoodOnTheGo support — the list, a new case, and one case with its
 * conversation and the records it is about.
 *
 * - A case belongs to the restaurant (a location, or the organization as a whole): the team shares one conversation,
 *   and the name of the staff member who wrote is shown to the team. Who of FoodOnTheGo answered is never shown.
 * - What may be done — open a case, reply, confirm, withdraw, reopen — is what the backend says (`can`, the catalog's
 *   `can.create`); hiding a button is not the authorization, the backend decides every call again.
 * - A linked order, settlement or payout shows the status and the amount its own record has now. Nothing here changes
 *   an order, a settlement or a payout.
 * - No priority, queue, assignee, internal note or response target is shown: the backend sends none to a restaurant.
 * - Without a backend there are no cases to show — the page says so instead of inventing them.
 */
type Filter = 'open' | 'closed'
const PAGE_SIZE = 20
const TONE: Record<string, string> = { open: 'blue', waiting: 'amber', done: 'green', closed: 'muted' }
type ReferenceKind = 'order' | 'settlement' | 'payout'
const kindOf = (category: string): ReferenceKind => (category === 'SETTLEMENT' ? 'settlement' : category === 'PAYOUT' ? 'payout' : 'order')
const ORG = 'org:'

/** Loads on mount and whenever a dependency changes; an answer that arrives after a newer request is dropped. */
function useAsync<T>(fn: () => Promise<T>, deps: DependencyList) {
  const [data, setData] = useState<T | null>(null); const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading'); const seq = useRef(0)
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const load = useCallback(async (quiet = false) => { const n = ++seq.current; if (!quiet) setState('loading'); try { const d = await fn(); if (n === seq.current) { setData(d); setState('ready') } } catch { if (n === seq.current && !quiet) setState('error') } }, deps)
  useEffect(() => { void load() }, [load])
  return { data, state, reload: load }
}

const StatusPill = ({ status, locale, testId }: { status: string; locale: string; testId?: string }) => <span data-testid={testId} data-status={status}><Pill tone={TONE[statusTone(status)]}>{statusLabel(status, locale)}</Pill></span>

export default function SupportPage() {
  const d = useDashboard(); const locale = d.locale; const repo = d.repos.support
  useEffect(() => { document.title = `${t('dash.nav.support', undefined, locale)} · ${t('dash.brand', undefined, locale)}` }, [locale])
  // No backend, no cases: support is never development data.
  if (!repo) return (
    <div className="db-page" data-testid="db-support" data-source="none">
      <PageHeader title={t('dash.support.title', undefined, locale)} lead={t('dash.support.lead', undefined, locale)} />
      <Card><EmptyState icon="support" title={t('dash.support.offline.title', undefined, locale)} text={t('dash.support.offline.text', undefined, locale)} action={<Link to={`${BASE}/help`} className="db-btn db-btn--outline">{t('dash.support.offline.help', undefined, locale)}</Link>} /></Card>
    </div>
  )
  return <ConnectedSupport repo={repo} />
}

function ConnectedSupport({ repo }: { repo: RestaurantSupportRepository }) {
  const d = useDashboard(); const locale = d.locale
  const [params, setParams] = useSearchParams()
  const [filter, setFilter] = useState<Filter>('open'); const [location, setLocation] = useState('all'); const [page, setPage] = useState(1)
  const { msg, toast } = useToastMessage()
  const catalog = useAsync(() => repo.catalog(), [repo])
  const list = useAsync(() => repo.list({ state: filter, location, page, pageSize: PAGE_SIZE }), [repo, filter, location, page])
  const opened = params.get('case'); const composing = params.get('new') === '1'
  // Both must agree: the member's permission at the location the dashboard is on, and the backend's own answer.
  const mayCreate = d.can('support.create') && catalog.data?.canCreate === true
  const patch = (changes: Record<string, string | null>) => { const next = new URLSearchParams(params); for (const [k, v] of Object.entries(changes)) { if (v === null) next.delete(k); else next.set(k, v) } setParams(next, { replace: true }) }
  const closeForm = () => patch({ new: null, category: null, order: null, settlement: null, payout: null })
  // The filter offers the locations the dashboard already knows by their backend id, and the ones the backend names for a new case.
  const locations = useMemo(() => {
    const known = new Map<string, string>()
    for (const l of d.accessibleLocations) if (l.live?.locationId) known.set(l.live.locationId, l.profile.locationName ? `${l.restaurant.name} · ${l.profile.locationName}` : l.restaurant.name)
    for (const l of catalog.data?.locations ?? []) if (!known.has(l.id)) known.set(l.id, l.name)
    return [...known.entries()].map(([id, name]) => ({ id, name }))
  }, [d.accessibleLocations, catalog.data])
  const rows = list.data?.items ?? []; const pages = list.data?.lastPage ?? 1
  const refresh = () => { void list.reload(true); void d.refreshSupportUnread() }

  return (
    <div className="db-page" data-testid="db-support" data-source="backend" data-filter={filter}>
      <PageHeader title={t('dash.support.title', undefined, locale)} lead={t('dash.support.lead', undefined, locale)} actions={
        <div className="db-toolbar">
          <label className="db-field" style={{ minWidth: 200 }}><span className="db-sr-only">{t('dash.support.filter.location', undefined, locale)}</span>
            <select className="db-select" value={location} onChange={(e) => { setLocation(e.target.value); setPage(1) }} aria-label={t('dash.support.filter.location', undefined, locale)} data-testid="db-support-filter-location">
              <option value="all">{t('dash.support.filter.allLocations', undefined, locale)}</option>
              {locations.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
            </select>
          </label>
          {mayCreate && <button type="button" className="db-btn db-btn--primary" onClick={() => patch({ new: '1', case: null })} data-testid="db-support-new"><Icon name="plus" size={18} /> {t('dash.support.new', undefined, locale)}</button>}
        </div>} />
      <Card title={t('dash.support.list.title', undefined, locale)} subtitle={t('dash.support.list.sub', undefined, locale)}>
        <Tabs tabs={(['open', 'closed'] as const).map((id) => ({ id, label: t(`dash.support.tab.${id}`, undefined, locale) }))} value={filter} onChange={(v) => { setFilter(v); setPage(1) }} label={t('dash.support.tabs', undefined, locale)} testId="db-support-tab" />
        <div style={{ marginTop: 14 }}>
          {list.state === 'error' && <ErrorState title={t('dash.support.error.title', undefined, locale)} text={t('dash.support.error.text', undefined, locale)} onRetry={() => { void list.reload() }} locale={locale} />}
          {list.state === 'loading' && <Skeleton rows={5} />}
          {list.state === 'ready' && rows.length === 0 && <EmptyState icon="support" title={t(`dash.support.empty.${filter}`, undefined, locale)} text={t('dash.support.empty.text', undefined, locale)} />}
          {list.state === 'ready' && rows.length > 0 && (
            <div className="db-table-wrap"><table className="db-table" data-testid="db-support-list">
              <thead><tr><th scope="col">{t('dash.support.col.case', undefined, locale)}</th><th scope="col">{t('dash.support.col.subject', undefined, locale)}</th><th scope="col">{t('dash.support.col.category', undefined, locale)}</th><th scope="col">{t('dash.support.col.restaurant', undefined, locale)}</th><th scope="col">{t('dash.support.col.status', undefined, locale)}</th><th scope="col">{t('dash.support.col.updated', undefined, locale)}</th><th scope="col"><span className="db-sr-only">{t('dash.support.view', undefined, locale)}</span></th></tr></thead>
              <tbody>{rows.map((c) => (
                <tr key={c.id} className="db-sup-row" data-testid="db-support-row" data-case={c.caseNumber} data-status={c.status} onClick={() => patch({ case: c.caseNumber, new: null })}>
                  <td className="db-table__num">{c.caseNumber}{c.unread && <> <span data-testid="db-support-row-unread"><Pill tone="red">{t('dash.support.unread', undefined, locale)}</Pill></span></>}</td>
                  <td className="db-sup-subject" dir="auto">{c.subject}</td>
                  <td>{categoryLabel(c.category, locale)}</td>
                  <td dir="auto">{c.location?.name ?? t('dash.support.wholeOrganization', undefined, locale)}</td>
                  <td><StatusPill status={c.status} locale={locale} testId="db-support-row-status" /></td>
                  <td>{supportDay(c.lastMessageAt ?? c.createdAt, locale)}</td>
                  <td className="db-table__actions"><button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={(e) => { e.stopPropagation(); patch({ case: c.caseNumber, new: null }) }} data-testid={`db-support-open-${c.caseNumber}`}>{t('dash.support.view', undefined, locale)}</button></td>
                </tr>
              ))}</tbody>
            </table></div>
          )}
          {list.state === 'ready' && pages > 1 && (
            <nav className="db-toolbar" aria-label={t('dash.support.pager', undefined, locale)} style={{ justifyContent: 'flex-end', marginTop: 12 }}>
              <button type="button" className="db-btn db-btn--outline db-btn--sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('dash.support.prev', undefined, locale)}</button>
              <span className="db-muted" aria-current="page">{t('dash.support.page', { page, pages }, locale)}</span>
              <button type="button" className="db-btn db-btn--outline db-btn--sm" disabled={page >= pages} onClick={() => setPage(page + 1)}>{t('dash.support.next', undefined, locale)}</button>
            </nav>
          )}
        </div>
      </Card>

      <Drawer open={composing} onClose={closeForm} title={t('dash.support.new.title', undefined, locale)} wide>
        {composing && catalog.state === 'loading' && <Skeleton rows={5} />}
        {composing && catalog.state === 'error' && <ErrorState title={t('dash.support.error.title', undefined, locale)} text={t('dash.support.error.text', undefined, locale)} onRetry={() => { void catalog.reload() }} locale={locale} />}
        {composing && catalog.state === 'ready' && catalog.data && (mayCreate
          ? <NewCaseForm repo={repo} catalog={catalog.data} initial={{ category: (params.get('category') ?? '').toUpperCase(), order: params.get('order') ?? '', settlement: params.get('settlement') ?? '', payout: params.get('payout') ?? '' }} onCancel={closeForm}
              onCreated={(c) => { toast(t('dash.support.new.opened', { n: c.caseNumber }, locale)); setFilter('open'); setPage(1); patch({ new: null, category: null, order: null, settlement: null, payout: null, case: c.caseNumber }); refresh() }} />
          : <EmptyState icon="warning" title={t('dash.support.new.unavailable', undefined, locale)} />)}
      </Drawer>
      {/* Keyed by the case: another case starts with an empty reply box. */}
      <CaseDrawer key={composing ? '' : opened ?? ''} repo={repo} reference={composing ? null : opened} onClose={() => { patch({ case: null }); refresh() }} />
      <ToastLine msg={msg} />
    </div>
  )
}

/* ------------------------------------------------------------------ a new case */

type Initial = { category: string; order: string; settlement: string; payout: string }

function NewCaseForm({ repo, catalog, initial, onCancel, onCreated }: { repo: RestaurantSupportRepository; catalog: RestaurantSupportCatalog; initial: Initial; onCancel: () => void; onCreated: (c: SupportCase) => void }) {
  const d = useDashboard(); const locale = d.locale
  const here = d.location?.live?.locationId ?? null
  // The restaurant the dashboard is on, when a case may be opened for it; otherwise the first one offered.
  const [place, setPlace] = useState(() => (catalog.locations.some((l) => l.id === here) ? here! : catalog.locations[0]?.id ?? (catalog.organizations[0] ? `${ORG}${catalog.organizations[0].id}` : '')))
  const offered = useCallback((where: string) => {
    const location = catalog.locations.find((l) => l.id === where)
    // A category about money is offered for a location only when the member may read its money there.
    return catalog.categories.filter((c) => !c.needsFinance || where.startsWith(ORG) || location?.finance === true)
  }, [catalog])
  const [category, setCategory] = useState(() => (offered(place).some((c) => c.code === initial.category) ? initial.category : ''))
  const [subcategory, setSubcategory] = useState('')
  const [subject, setSubject] = useState(''); const [message, setMessage] = useState('')
  const [reference, setReference] = useState(() => initial[kindOf(category)].trim().slice(0, 64))
  const [showErrors, setShowErrors] = useState(false); const [sending, setSending] = useState(false)
  const [failure, setFailure] = useState<{ text: string; fields: Record<string, string[]>; code: string; unknown: boolean } | null>(null)
  const attempt = useRef(new SupportAttempt())
  const categories = offered(place); const kind = kindOf(category)
  const subcategories = categories.find((c) => c.code === category)?.subcategories ?? []
  const limits = catalog.limits
  const subjectLength = textLength(subject.trim()); const messageLength = textLength(message.trim())
  const errors = {
    location: place === '' ? t('dash.support.new.error.location', undefined, locale) : null,
    category: category === '' ? t('dash.support.new.error.category', undefined, locale) : null,
    subject: subjectLength === 0 ? t('dash.support.new.error.subject', undefined, locale) : subjectLength > limits.subjectMax ? t('dash.support.new.error.subjectLength', { max: limits.subjectMax }, locale) : null,
    message: messageLength === 0 ? t('dash.support.new.error.message', undefined, locale) : messageLength > limits.messageMax ? t('dash.support.new.error.messageLength', { max: limits.messageMax }, locale) : null,
  }
  const local = (name: keyof typeof errors) => (showErrors ? errors[name] : null)
  const remote = (...names: string[]) => names.map((n) => failure?.fields[n]?.[0]).find((x) => !!x) ?? null
  // The backend's refusal of the attached record is said under the field it is about.
  const referenceError = remote(kind, 'links') ?? (failure && failure.code === `${kind}_not_found` ? failure.text : null)
  const locationError = local('location') ?? remote('location', 'organization') ?? (failure?.code === 'restaurant_not_found' ? failure.text : null)

  const choosePlace = (next: string) => { setPlace(next); if (!offered(next).some((c) => c.code === category)) { setCategory(''); setSubcategory('') } }
  const chooseCategory = (next: string) => { if (kindOf(next) !== kind) setReference(''); setCategory(next); setSubcategory('') }
  const submit = async (e: FormEvent) => {
    e.preventDefault()
    if (sending) return
    setShowErrors(true)
    if (errors.location || errors.category || errors.subject || errors.message) return
    const input: NewRestaurantSupportCase = {
      ...(place.startsWith(ORG) ? { organization: place.slice(ORG.length) } : { location: place }),
      category, subcategory: subcategory || null, subject: subject.trim(), message: message.trim(), [kind]: reference.trim() || null,
    }
    setSending(true); setFailure(null)
    try {
      const created = await repo.create(input, attempt.current.keyFor(JSON.stringify(input)))
      attempt.current.settle()
      onCreated(created)
    } catch (err) {
      const unknown = !(err instanceof SupportError) || err.unknownOutcome
      if (!unknown) attempt.current.settle()
      setFailure({ text: supportErrorText(err, locale), fields: err instanceof SupportError ? err.fields : {}, code: err instanceof SupportError ? err.code : 'unknown', unknown })
      setSending(false)
    }
  }

  return (
    <form className="db-sup-form" noValidate onSubmit={(e) => { void submit(e) }} aria-busy={sending} data-testid="db-support-form">
      <Field label={t('dash.support.new.location', undefined, locale)} required id="db-sup-location" error={locationError ?? undefined}>
        <select id="db-sup-location" className="db-select" value={place} onChange={(e) => choosePlace(e.target.value)} data-testid="db-support-location">
          {place === '' && <option value="">—</option>}
          {catalog.locations.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
          {catalog.organizations.map((o) => <option key={o.id} value={`${ORG}${o.id}`}>{t('dash.support.wholeOrganizationOf', { name: o.name }, locale)}</option>)}
        </select>
      </Field>
      <Field label={t('dash.support.new.category', undefined, locale)} required id="db-sup-category" error={local('category') ?? remote('category') ?? undefined}>
        <select id="db-sup-category" className="db-select" value={category} onChange={(e) => chooseCategory(e.target.value)} data-testid="db-support-category">
          <option value="">{t('dash.support.new.category.choose', undefined, locale)}</option>
          {categories.map((c) => <option key={c.code} value={c.code}>{categoryLabel(c.code, locale)}</option>)}
        </select>
      </Field>
      {subcategories.length > 0 && (
        <Field label={t('dash.support.new.subcategory', undefined, locale)} id="db-sup-subcategory" error={remote('subcategory') ?? undefined}>
          <select id="db-sup-subcategory" className="db-select" value={subcategory} onChange={(e) => setSubcategory(e.target.value)} data-testid="db-support-subcategory">
            <option value="">{t('dash.support.new.subcategory.none', undefined, locale)}</option>
            {subcategories.map((s) => <option key={s} value={s}>{subcategoryLabel(s, locale)}</option>)}
          </select>
        </Field>
      )}
      <Field label={t('dash.support.new.subject', undefined, locale)} required id="db-sup-subject" hint={t('dash.support.new.subject.hint', { max: limits.subjectMax }, locale)} error={local('subject') ?? remote('subject') ?? undefined}>
        <input id="db-sup-subject" type="text" className="db-input" dir="auto" value={subject} onChange={(e) => setSubject(e.target.value)} maxLength={limits.subjectMax * 2} autoComplete="off" data-testid="db-support-subject" />
      </Field>
      <Field label={t('dash.support.new.message', undefined, locale)} required id="db-sup-message" hint={t('dash.support.new.message.hint', { max: limits.messageMax }, locale)} error={local('message') ?? remote('message') ?? undefined}>
        <textarea id="db-sup-message" className="db-textarea" dir="auto" rows={6} value={message} onChange={(e) => setMessage(e.target.value)} data-testid="db-support-message" />
      </Field>
      <Field label={t(`dash.support.new.reference.${kind}`, undefined, locale)} id="db-sup-reference" hint={t('dash.support.new.reference.hint', undefined, locale)} error={referenceError ?? undefined}>
        <input id="db-sup-reference" type="text" className="db-input" value={reference} onChange={(e) => setReference(e.target.value)} maxLength={64} autoComplete="off" spellCheck={false} data-testid="db-support-reference" data-kind={kind} />
      </Field>
      {failure && (
        <p className="db-field__error" role="alert" data-testid="db-support-form-error" data-outcome={failure.unknown ? 'unknown' : 'refused'}>
          {failure.unknown ? t('dash.support.new.unknown', undefined, locale) : `${t('dash.support.new.notOpened', undefined, locale)} ${failure.text}`}
        </p>
      )}
      <div className="db-toolbar">
        <button type="submit" className="db-btn db-btn--primary" disabled={sending} data-testid="db-support-submit">{sending ? t('dash.support.new.submitting', undefined, locale) : t('dash.support.new.submit', undefined, locale)}</button>
        <button type="button" className="db-btn db-btn--ghost" disabled={sending} onClick={onCancel}>{t('dash.action.cancel', undefined, locale)}</button>
      </div>
    </form>
  )
}

/* ------------------------------------------------------------------ one case */

function CaseDrawer({ repo, reference, onClose }: { repo: RestaurantSupportRepository; reference: string | null; onClose: () => void }) {
  const d = useDashboard(); const locale = d.locale
  const thread = useSupportCase(repo, reference, locale)
  const c = thread.case
  const [reply, setReply] = useState(''); const [reopening, setReopening] = useState(false); const [reopenText, setReopenText] = useState('')
  // Opening a case marks it read on the backend: the count in the navigation is read again.
  const openedId = thread.state === 'ready' ? c?.id ?? null : null
  const refreshUnread = d.refreshSupportUnread
  useEffect(() => { if (openedId) void refreshUnread() }, [openedId, refreshUnread])
  const busy = thread.busy !== null
  const send = async () => { if (await thread.sendReply(reply)) setReply('') }
  const reopen = async () => { if (await thread.reopen(reopenText)) { setReopenText(''); setReopening(false) } }
  const author = (m: { author: string; authorName: string | null }) => (m.author === 'YOU' && m.authorName ? m.authorName : t(`dash.support.case.author.${m.author}`, undefined, locale))
  const canAct = c !== null && (c.can.reply || c.can.close || c.can.withdraw || c.can.reopen)

  return (
    <Drawer open={!!reference} onClose={onClose} title={c ? `${t('dash.support.case.title', undefined, locale)} ${c.caseNumber}` : t('dash.support.case.title', undefined, locale)} wide>
      {thread.state === 'loading' && <Skeleton rows={6} />}
      {thread.state === 'not_found' && <EmptyState icon="warning" title={t('dash.support.case.notFound', undefined, locale)} text={t('dash.support.case.notFoundText', { ref: reference ?? '' }, locale)} />}
      {thread.state === 'error' && <ErrorState title={t('dash.support.case.error', undefined, locale)} text={t('dash.support.error.text', undefined, locale)} onRetry={thread.reload} locale={locale} />}
      {thread.state === 'ready' && c && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }} data-testid="db-support-case" data-case={c.caseNumber} data-status={c.status}>
          <div className="db-status-strip">
            <span className="db-table__num" data-testid="db-support-case-number">{c.caseNumber}</span>
            <StatusPill status={c.status} locale={locale} testId="db-support-case-status" />
            <Pill tone="muted">{categoryLabel(c.category, locale)}{c.subcategory ? ` · ${subcategoryLabel(c.subcategory, locale)}` : ''}</Pill>
          </div>
          <div>
            <b dir="auto" className="db-sup-subject" data-testid="db-support-case-subject">{c.subject}</b>
            <p className="db-muted" style={{ margin: '2px 0 0', fontSize: '0.82rem' }}><span dir="auto">{c.location?.name ?? t('dash.support.wholeOrganization', undefined, locale)}</span>{c.createdAt ? ` · ${t('dash.support.case.opened', { date: supportDay(c.createdAt, locale) }, locale)}` : ''}</p>
          </div>

          {c.links.length > 0 && (
            <Card title={t('dash.support.case.links', undefined, locale)}>
              <ul className="db-sup-links">{c.links.map((l) => <LinkCard key={l.id} link={l} locale={locale} canOpenOrder={d.can('orders.view') && !!c.location && c.location.id === d.location?.live?.locationId} />)}</ul>
            </Card>
          )}

          <Card title={t('dash.support.case.conversation', undefined, locale)}>
            {thread.hasEarlier && <div className="db-toolbar" style={{ marginBottom: 12 }}><button type="button" className="db-btn db-btn--outline db-btn--sm" disabled={thread.loadingEarlier} onClick={() => { void thread.loadEarlier() }} data-testid="db-support-earlier">{t('dash.support.case.earlier', undefined, locale)}</button></div>}
            {thread.messages.length === 0 ? <p className="db-muted">{t('dash.support.case.empty', undefined, locale)}</p> : (
              <ol className="db-sup-thread" data-testid="db-support-messages">
                {thread.messages.map((m) => (
                  <li key={m.id} className={`db-sup-msg db-sup-msg--${m.author}`} data-testid="db-support-message-item" data-author={m.author}>
                    <div className="db-sup-msg__meta"><b dir="auto" data-testid="db-support-message-author">{author(m)}</b>{m.createdAt && <time className="db-muted" dateTime={m.createdAt}>{supportDayTime(m.createdAt, locale)}</time>}</div>
                    <p className="db-sup-msg__body" dir="auto" data-testid="db-support-message-body">{m.body}</p>
                  </li>
                ))}
              </ol>
            )}
          </Card>

          {c.can.reply && (
            <form className="db-sup-form" onSubmit={(e) => { e.preventDefault(); void send() }}>
              <Field label={t('dash.support.case.reply', undefined, locale)} id="db-sup-reply">
                <textarea id="db-sup-reply" className="db-textarea" dir="auto" rows={4} value={reply} onChange={(e) => { setReply(e.target.value); if (thread.actionError) thread.clearError() }} placeholder={t('dash.support.case.reply.placeholder', undefined, locale)} data-testid="db-support-reply-input" />
              </Field>
              <div className="db-toolbar"><button type="submit" className="db-btn db-btn--primary" disabled={busy || reply.trim() === ''} data-testid="db-support-reply-send">{thread.busy === 'reply' ? t('dash.support.case.reply.sending', undefined, locale) : t('dash.support.case.reply.send', undefined, locale)}</button></div>
            </form>
          )}
          {(c.can.close || c.can.withdraw || c.can.reopen) && (
            <div className="db-sup-form" data-testid="db-support-actions">
              {c.can.close && <p className="db-muted" style={{ margin: 0 }}>{t('dash.support.case.confirm.hint', undefined, locale)}</p>}
              {c.can.reopen && c.reopenUntil && <p className="db-muted" style={{ margin: 0 }} data-testid="db-support-reopen-until">{t('dash.support.case.reopen.until', { date: supportDay(c.reopenUntil, locale) }, locale)}</p>}
              <div className="db-toolbar">
                {c.can.close && <button type="button" className="db-btn db-btn--success" disabled={busy} onClick={() => { void thread.close('CONFIRM') }} data-testid="db-support-confirm">{t('dash.support.case.confirm', undefined, locale)}</button>}
                {c.can.reopen && !reopening && <button type="button" className="db-btn db-btn--outline" disabled={busy} onClick={() => { thread.clearError(); setReopening(true) }} data-testid="db-support-reopen">{t('dash.support.case.reopen', undefined, locale)}</button>}
                {/* One call serves both: the backend confirms a resolved case and withdraws one that is being worked on. The button says which. */}
                {!c.can.close && c.can.withdraw && <button type="button" className="db-btn db-btn--outline" disabled={busy} onClick={() => { void thread.close('WITHDRAW') }} data-testid="db-support-withdraw">{t('dash.support.case.withdraw', undefined, locale)}</button>}
              </div>
              {c.can.reopen && reopening && (
                <form className="db-sup-form" onSubmit={(e) => { e.preventDefault(); void reopen() }}>
                  <Field label={t('dash.support.case.reopen.label', undefined, locale)} id="db-sup-reopen" required>
                    <textarea id="db-sup-reopen" className="db-textarea" dir="auto" rows={3} value={reopenText} onChange={(e) => setReopenText(e.target.value)} data-testid="db-support-reopen-message" />
                  </Field>
                  <div className="db-toolbar">
                    <button type="submit" className="db-btn db-btn--primary" disabled={busy || reopenText.trim() === ''} data-testid="db-support-reopen-submit">{t('dash.support.case.reopen.submit', undefined, locale)}</button>
                    <button type="button" className="db-btn db-btn--ghost" disabled={busy} onClick={() => setReopening(false)}>{t('dash.action.cancel', undefined, locale)}</button>
                  </div>
                </form>
              )}
            </div>
          )}
          {!canAct && c.status !== 'CLOSED' && c.status !== 'CANCELLED' && <p className="db-muted" data-testid="db-support-read-only">{t('dash.support.case.readOnly', undefined, locale)}</p>}
          {thread.actionError && <p className="db-field__error" role="alert" data-testid="db-support-action-error">{thread.actionError}</p>}
        </div>
      )}
    </Drawer>
  )
}

/** A linked record, read-only: its kind and reference, the status its own record has now, and its amount. The order opens only from the location the dashboard is on. */
function LinkCard({ link: l, locale, canOpenOrder }: { link: SupportLink; locale: string; canOpenOrder: boolean }) {
  const status = linkStatusLabel(l, locale)
  return (
    <li data-testid="db-support-link" data-type={l.type} data-reference={l.reference} data-status={l.status ?? ''}>
      <span className="db-sup-links__top"><span className="db-muted">{linkTypeLabel(l.type, locale)}</span>{status && <span data-testid="db-support-link-status"><Pill tone="muted">{status}</Pill></span>}</span>
      <b className="db-table__num">{l.reference}</b>
      {l.restaurant && <span className="db-muted" dir="auto">{l.restaurant}</span>}
      {l.amount && <span data-testid="db-support-link-amount" data-amount={l.amount.amount} data-currency={l.amount.currency}>{supportMoney(l.amount, locale)}</span>}
      {l.periodStart && l.periodEnd && <span className="db-muted">{t('sup.link.period', { from: supportDay(l.periodStart, locale), to: supportDay(l.periodEnd, locale) }, locale)}</span>}
      {l.type === 'ORDER' && canOpenOrder && <Link to={`${BASE}/orders/${encodeURIComponent(l.reference)}`} className="db-link">{t('dash.orders.view', undefined, locale)}</Link>}
    </li>
  )
}
