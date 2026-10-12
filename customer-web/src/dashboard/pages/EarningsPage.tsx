import { useCallback, useEffect, useRef, useState, type DependencyList, type ReactNode } from 'react'
import { ApiError } from '../../api/client'
import { formatMoney } from '../../i18n/format'
import { t } from '../../i18n/strings'
import { useDashboard } from '../DashboardContext'
import {
  EARNING_STATES, EARNING_TYPES, EXPORT_MAX_DAYS, SETTLEMENT_STATUSES, ratePercent,
  type EarningLineDto, type EarningsCurrencyDto, type EarningsPeriodDto, type EarningsSummaryDto, type FinanceLocationDto, type MoneyDto,
  type RestaurantFinanceRepository, type RestaurantPayoutDto, type RestaurantSettlementDetailDto, type RestaurantSettlementDto,
} from '../api/apiFinance'
import { BarChart } from '../components/Charts'
import { Card, Drawer, EmptyState, ErrorState, KpiCard, PageHeader, Pill, Skeleton, Tabs } from '../components/ui'

/**
 * Earnings (Module 36): the restaurant's own earnings, settlement statements, payout states and commercial terms, read
 * from the backend. Nothing on this page can be changed, and nothing on it is worked out in the browser: every figure,
 * and the meaning of every figure, is the backend's. Amounts are shown per currency and never added across currencies.
 *
 * "Gross sales" is never called earnings, a settlement statement is never called an invoice, and a payout destination is
 * only ever the provider's mask of it. Without a backend there are no figures to show — the page says so instead of
 * inventing them.
 */
type Tab = 'summary' | 'lines' | 'settlements' | 'payouts' | 'terms'
const TABS: Tab[] = ['summary', 'lines', 'settlements', 'payouts', 'terms']
const PAGE_SIZE = 25
type KpiKey = 'pending' | 'available' | 'held' | 'in_settlement' | 'paid_out' | 'balance'
const KPIS: Array<{ key: KpiKey; icon: string; tone: 'amber' | 'green' | 'red' | 'blue' | 'purple' | 'orange' }> = [
  { key: 'pending', icon: 'timer', tone: 'amber' }, { key: 'available', icon: 'money', tone: 'green' }, { key: 'held', icon: 'warning', tone: 'red' },
  { key: 'in_settlement', icon: 'audit', tone: 'blue' }, { key: 'paid_out', icon: 'check', tone: 'purple' }, { key: 'balance', icon: 'settlements', tone: 'orange' },
]
const TONE: Record<string, string> = {
  AVAILABLE: 'green', HELD: 'amber', IN_SETTLEMENT: 'blue', SETTLED: 'muted', PAID: 'green',
  // Settlements and payouts arrive in the words the backend keeps for restaurants — never its internal states.
  IN_REVIEW: 'amber', APPROVED: 'blue', PAYMENT_PENDING: 'blue', CLOSED: 'muted', PROCESSING: 'blue', FAILED: 'red',
  VERIFIED: 'green', PENDING_VERIFICATION: 'amber', NOT_CONFIGURED: 'muted', SUSPENDED: 'red',
}

/** Loads on mount and whenever a dependency changes; an answer that arrives after a newer request is dropped. */
function useAsync<T>(fn: () => Promise<T>, deps: DependencyList) {
  const [data, setData] = useState<T | null>(null); const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading'); const seq = useRef(0)
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const load = useCallback(async () => { const n = ++seq.current; setState('loading'); try { const d = await fn(); if (n === seq.current) { setData(d); setState('ready') } } catch { if (n === seq.current) setState('error') } }, deps)
  useEffect(() => { void load() }, [load])
  return { data, state, reload: load }
}

const humanize = (code: string) => { const s = code.toLowerCase().replace(/_/g, ' '); return s.charAt(0).toUpperCase() + s.slice(1) }
/** The label of a backend code: the string table's when it has one, else the code in plain words. */
const label = (prefix: string, code: string, locale: string) => { const key = `${prefix}.${code}`; const s = t(key, undefined, locale); return s === key ? humanize(code) : s }
const money = (m: MoneyDto, locale: string) => formatMoney(m.amount, m.currency, locale)
const day = (iso: string | null, locale: string, timeZone?: string) => (iso ? new Date(iso).toLocaleDateString(locale, { dateStyle: 'medium', timeZone }) : '—')
const dayTime = (iso: string | null, locale: string, timeZone?: string) => (iso ? new Date(iso).toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short', timeZone }) : '—')
const refusal = (e: unknown, locale: string) => (e instanceof ApiError && e.kind !== 'server' && e.kind !== 'network' ? Object.values(e.errors)[0]?.[0] ?? e.message : t('dash.earn.failed', undefined, locale))
const isoDay = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

export default function EarningsPage() {
  const d = useDashboard(); const locale = d.locale; const repo = d.repos.finance
  useEffect(() => { document.title = `${t('dash.nav.earnings', undefined, locale)} · ${t('dash.brand', undefined, locale)}` }, [locale])
  // No backend, no figures: earnings are never development data.
  if (!repo) return (
    <div className="db-page" data-testid="db-earnings" data-source="none">
      <PageHeader title={t('dash.earn.title', undefined, locale)} lead={t('dash.earn.lead', undefined, locale)} />
      <Card><EmptyState icon="money" title={t('dash.earn.offline.title', undefined, locale)} text={t('dash.earn.offline.text', undefined, locale)} /></Card>
    </div>
  )
  return <ConnectedEarnings repo={repo} locale={locale} />
}

function ConnectedEarnings({ repo, locale }: { repo: RestaurantFinanceRepository; locale: string }) {
  const [location, setLocation] = useState('all'); const [tab, setTab] = useState<Tab>('summary'); const [known, setKnown] = useState<FinanceLocationDto[]>([])
  // The answer for "all locations" names every location this person may read: that is the list the filter offers.
  const summary = useAsync(async () => { const answer = await repo.summary(location); if (answer.scope.all_readable) setKnown(answer.scope.locations); return answer }, [repo, location])
  const timezone = summary.data?.timezone
  return (
    <div className="db-page" data-testid="db-earnings" data-source="backend">
      <PageHeader title={t('dash.earn.title', undefined, locale)} lead={t('dash.earn.lead', undefined, locale)} actions={
        <label className="db-field" style={{ minWidth: 220 }}><span className="db-sr-only">{t('dash.earn.location', undefined, locale)}</span>
          <select className="db-select" value={location} onChange={(e) => setLocation(e.target.value)} aria-label={t('dash.earn.location', undefined, locale)} data-testid="earn-location">
            <option value="all">{t('dash.earn.location.all', undefined, locale)}</option>
            {known.map((l) => <option key={l.id} value={l.id}>{l.name} · {l.currency}</option>)}
          </select>
        </label>} />
      <Tabs tabs={TABS.map((id) => ({ id, label: t(`dash.earn.tab.${id}`, undefined, locale) }))} value={tab} onChange={setTab} label={t('dash.earn.tabs', undefined, locale)} testId="earn-tab" />
      {tab === 'summary' && <>
        {summary.state === 'error' && <ErrorState title={t('dash.earn.error.title', undefined, locale)} text={t('dash.earn.error.text', undefined, locale)} onRetry={() => { void summary.reload() }} locale={locale} />}
        {summary.state === 'loading' && <Card><Skeleton rows={5} /></Card>}
        {summary.state === 'ready' && summary.data && <SummaryPanel data={summary.data} locale={locale} />}
      </>}
      {/* Keyed by the location: choosing another one starts a list again on its first page. */}
      {tab === 'lines' && <LinesPanel key={location} repo={repo} location={location} locale={locale} timezone={timezone} />}
      {tab === 'settlements' && <SettlementsPanel key={location} repo={repo} location={location} locale={locale} />}
      {tab === 'payouts' && <PayoutsPanel key={location} repo={repo} location={location} locale={locale} timezone={timezone} />}
      {tab === 'terms' && <TermsPanel repo={repo} location={location} locale={locale} />}
    </div>
  )
}

/* ------------------------------------------------------------------ summary */

const PERIODS: Array<['today' | 'this_week' | 'this_month' | 'lifetime', (c: EarningsCurrencyDto) => EarningsPeriodDto]> = [
  ['today', (c) => c.periods.today], ['this_week', (c) => c.periods.this_week], ['this_month', (c) => c.periods.this_month], ['lifetime', (c) => c.lifetime],
]
function SummaryPanel({ data, locale }: { data: EarningsSummaryDto; locale: string }) {
  const def = (key: string) => data.definitions[key]
  return <>
    <p className="db-demo-banner" role="note" data-testid="earn-policy"><span>{data.settlement_policy.note} {data.payouts.live ? '' : data.payouts.note} <small>{t('dash.earn.policy.cadence', { cadence: label('dash.earn.cadence', data.settlement_policy.cadence, locale), approval: humanize(data.settlement_policy.approval) }, locale)}</small></span></p>
    {data.currencies.length === 0 && <Card><EmptyState icon="money" title={t('dash.earn.empty.title', undefined, locale)} text={t('dash.earn.empty.text', undefined, locale)} /></Card>}
    {/* One block per currency: two currencies are two sets of figures, never one sum. */}
    {data.currencies.map((c) => {
      const series = data.series.filter((s) => s.currency === c.currency)
      const fmt = (v: number) => formatMoney(Math.round(v), c.currency, locale)
      return (
        <section key={c.currency} className="db-grid" data-testid={`earn-currency-${c.currency}`} aria-label={t('dash.earn.currency', { currency: c.currency }, locale)}>
          <h2 className="db-card__title" style={{ margin: 0 }}>{t('dash.earn.currency', { currency: c.currency }, locale)}</h2>
          <div className="db-grid db-grid--3">
            {KPIS.map(({ key, icon, tone }) => (
              <KpiCard key={key} icon={icon} tone={tone} locale={locale} testId={`earn-card-${key}-${c.currency}`}
                value={<span data-testid={`earn-kpi-${key}-${c.currency}`} data-amount={c[key].amount} data-currency={c[key].currency}>{money(c[key], locale)}</span>}
                label={`${t(`dash.earn.kpi.${key}`, undefined, locale)}${key === 'pending' && c.pending_orders ? ` · ${t('dash.earn.orders', { n: c.pending_orders }, locale)}` : ''}`} />
            ))}
          </div>
          {c.awaiting_terms_orders > 0 && <p className="db-muted" data-testid={`earn-awaiting-terms-${c.currency}`}>{t('dash.earn.awaitingTerms', { n: c.awaiting_terms_orders }, locale)}</p>}
          <Card title={t('dash.earn.periods', undefined, locale)} subtitle={t('dash.earn.periods.sub', { zone: data.timezone }, locale)}>
            <div className="db-table-wrap"><table className="db-table" data-testid={`earn-periods-${c.currency}`}>
              <thead><tr><th scope="col">{t('dash.earn.col.period', undefined, locale)}</th><th scope="col">{t('dash.earn.col.orders', undefined, locale)}</th><th scope="col" title={def('gross_sales')}>{t('dash.earn.def.gross_sales', undefined, locale)}</th><th scope="col" title={def('commission')}>{t('dash.earn.def.commission', undefined, locale)}</th><th scope="col" title={def('adjustments')}>{t('dash.earn.def.adjustments', undefined, locale)}</th><th scope="col" title={def('net_earnings')}>{t('dash.earn.def.net_earnings', undefined, locale)}</th></tr></thead>
              <tbody>{PERIODS.map(([id, pick]) => { const p = pick(c); return (
                <tr key={id} data-period={id}><th scope="row">{t(`dash.earn.period.${id}`, undefined, locale)}</th><td>{p.orders.toLocaleString(locale)}</td><td>{money(p.gross_sales, locale)}</td><td>{money(p.commission, locale)}</td><td>{money(p.adjustments, locale)}</td><td><b>{money(p.net_earnings, locale)}</b></td></tr>
              ) })}</tbody>
            </table></div>
          </Card>
          <Card title={t('dash.earn.series', undefined, locale)} subtitle={t('dash.earn.series.sub', { currency: c.currency }, locale)}>
            {series.length === 0 ? <p className="db-muted" data-testid={`earn-series-empty-${c.currency}`}>{t('dash.earn.series.empty', undefined, locale)}</p>
              // A chart of bars cannot show a day that ended below zero: such a period is listed as figures instead.
              : series.some((s) => s.net_earnings.amount < 0) ? (
                <div className="db-table-wrap"><table className="db-table" data-testid={`earn-series-${c.currency}`}>
                  <thead><tr><th scope="col">{t('dash.earn.col.date', undefined, locale)}</th><th scope="col">{t('dash.earn.col.orders', undefined, locale)}</th><th scope="col">{t('dash.earn.def.gross_sales', undefined, locale)}</th><th scope="col">{t('dash.earn.def.net_earnings', undefined, locale)}</th></tr></thead>
                  <tbody>{series.map((s) => <tr key={s.day}><th scope="row">{s.day}</th><td>{s.orders.toLocaleString(locale)}</td><td>{money(s.gross_sales, locale)}</td><td>{money(s.net_earnings, locale)}</td></tr>)}</tbody>
                </table></div>
              ) : <BarChart title={t('dash.earn.series.sub', { currency: c.currency }, locale)} data={series.map((s) => ({ label: s.day.slice(5), value: s.net_earnings.amount }))} format={fmt} testId={`earn-series-${c.currency}`} />}
          </Card>
        </section>
      )
    })}
    <Card title={t('dash.earn.definitions', undefined, locale)} subtitle={t('dash.earn.definitions.sub', undefined, locale)}>
      <dl className="db-earn-defs" data-testid="earn-definitions">{Object.entries(data.definitions).map(([key, text]) => <div key={key}><dt><b>{label('dash.earn.def', key, locale)}</b></dt><dd className="db-muted" style={{ margin: '0 0 8px' }}>{text}</dd></div>)}</dl>
    </Card>
  </>
}

/* ------------------------------------------------------------------ shared pieces */

function Pager({ page, total, onPage, locale }: { page: number; total: number; onPage: (p: number) => void; locale: string }) {
  const pages = Math.max(1, Math.ceil(total / PAGE_SIZE))
  if (pages <= 1) return null
  return (
    <nav className="db-toolbar" aria-label={t('dash.earn.pager', undefined, locale)} style={{ justifyContent: 'flex-end', marginTop: 12 }}>
      <button type="button" className="db-btn db-btn--outline db-btn--sm" disabled={page <= 1} onClick={() => onPage(page - 1)}>{t('dash.earn.prev', undefined, locale)}</button>
      <span className="db-muted" aria-current="page">{t('dash.earn.page', { page, pages }, locale)}</span>
      <button type="button" className="db-btn db-btn--outline db-btn--sm" disabled={page >= pages} onClick={() => onPage(page + 1)}>{t('dash.earn.next', undefined, locale)}</button>
    </nav>
  )
}
function Listing({ state, empty, onRetry, locale, children }: { state: 'loading' | 'ready' | 'error'; empty: string | null; onRetry: () => void; locale: string; children: ReactNode }) {
  if (state === 'error') return <ErrorState title={t('dash.earn.error.title', undefined, locale)} text={t('dash.earn.error.text', undefined, locale)} onRetry={onRetry} locale={locale} />
  if (state === 'loading') return <Skeleton rows={5} />
  if (empty) return <EmptyState icon="money" title={empty} />
  return <>{children}</>
}
const StatePill = ({ prefix, code, locale }: { prefix: string; code: string; locale: string }) => <span data-state={code}><Pill tone={TONE[code] ?? 'muted'}>{label(prefix, code, locale)}</Pill></span>

/* ------------------------------------------------------------------ earnings lines */

function LinesPanel({ repo, location, locale, timezone }: { repo: RestaurantFinanceRepository; location: string; locale: string; timezone?: string }) {
  const [state, setState] = useState('all'); const [type, setType] = useState('all'); const [page, setPage] = useState(1)
  const today = new Date(); const [from, setFrom] = useState(() => isoDay(new Date(today.getFullYear(), today.getMonth(), today.getDate() - 29))); const [to, setTo] = useState(() => isoDay(today))
  const [busy, setBusy] = useState(false); const [note, setNote] = useState<{ ok: boolean; text: string } | null>(null)
  const list = useAsync(() => repo.earnings({ location, state, type, page, pageSize: PAGE_SIZE }), [repo, location, state, type, page])
  const exportCsv = async () => {
    setNote(null)
    const span = Math.round((Date.parse(to) - Date.parse(from)) / 86_400_000)
    if (!from || !to || Number.isNaN(span) || span < 0) { setNote({ ok: false, text: t('dash.earn.export.order', undefined, locale) }); return }
    if (span > EXPORT_MAX_DAYS) { setNote({ ok: false, text: t('dash.earn.export.tooLong', { days: EXPORT_MAX_DAYS }, locale) }); return }
    setBusy(true)
    try { const name = await repo.exportEarnings({ location, from, to }); setNote({ ok: true, text: t('dash.earn.export.done', { name }, locale) }) }
    catch (e) { setNote({ ok: false, text: refusal(e, locale) }) }
    finally { setBusy(false) }
  }
  const rows = list.data?.items ?? []
  return <>
    <Card title={t('dash.earn.lines.title', undefined, locale)} subtitle={t('dash.earn.lines.sub', undefined, locale)} actions={
      <div className="db-toolbar">
        <select className="db-select db-input--sm" value={state} onChange={(e) => { setState(e.target.value); setPage(1) }} aria-label={t('dash.earn.col.state', undefined, locale)} data-testid="earn-filter-state">
          <option value="all">{t('dash.earn.filter.allStates', undefined, locale)}</option>{EARNING_STATES.map((s) => <option key={s} value={s}>{label('dash.earn.state', s, locale)}</option>)}
        </select>
        <select className="db-select db-input--sm" value={type} onChange={(e) => { setType(e.target.value); setPage(1) }} aria-label={t('dash.earn.col.type', undefined, locale)} data-testid="earn-filter-type">
          <option value="all">{t('dash.earn.filter.allTypes', undefined, locale)}</option>{EARNING_TYPES.map((s) => <option key={s} value={s}>{label('dash.earn.type', s, locale)}</option>)}
        </select>
      </div>}>
      <Listing state={list.state} empty={rows.length === 0 ? t('dash.earn.lines.empty', undefined, locale) : null} onRetry={() => { void list.reload() }} locale={locale}>
        <div className="db-table-wrap"><table className="db-table" data-testid="earn-lines">
          <thead><tr><th scope="col">{t('dash.earn.col.date', undefined, locale)}</th><th scope="col">{t('dash.earn.col.type', undefined, locale)}</th><th scope="col">{t('dash.earn.col.order', undefined, locale)}</th><th scope="col">{t('dash.earn.def.gross_sales', undefined, locale)}</th><th scope="col">{t('dash.earn.def.commission', undefined, locale)}</th><th scope="col">{t('dash.earn.col.net', undefined, locale)}</th><th scope="col">{t('dash.earn.col.state', undefined, locale)}</th><th scope="col">{t('dash.earn.col.settlement', undefined, locale)}</th></tr></thead>
          <tbody>{rows.map((r: EarningLineDto) => (
            <tr key={r.id} data-testid="earn-line-row" data-id={r.id} data-order={r.order_number ?? ''} data-state={r.state}><td>{dayTime(r.available_at, locale, timezone)}</td><td>{label('dash.earn.type', r.type, locale)}</td><td className="db-table__num">{r.order_number ?? '—'}</td><td>{money(r.gross_sales, locale)}</td><td>{money(r.commission, locale)}</td><td><b>{money(r.amount, locale)}</b></td><td><StatePill prefix="dash.earn.state" code={r.state} locale={locale} /></td><td className="db-table__num">{r.settlement?.reference ?? '—'}</td></tr>
          ))}</tbody>
        </table></div>
        <Pager page={page} total={list.data?.total ?? 0} onPage={setPage} locale={locale} />
      </Listing>
    </Card>
    <Card title={t('dash.earn.export.title', undefined, locale)} subtitle={t('dash.earn.export.sub', { days: EXPORT_MAX_DAYS }, locale)}>
      <div className="db-toolbar" data-testid="earn-export">
        <label className="db-field"><span className="db-field__label">{t('dash.earn.export.from', undefined, locale)}</span><input type="date" className="db-input db-input--sm" value={from} max={to} onChange={(e) => setFrom(e.target.value)} data-testid="earn-export-from" /></label>
        <label className="db-field"><span className="db-field__label">{t('dash.earn.export.to', undefined, locale)}</span><input type="date" className="db-input db-input--sm" value={to} min={from} onChange={(e) => setTo(e.target.value)} data-testid="earn-export-to" /></label>
        <button type="button" className="db-btn db-btn--outline" disabled={busy} onClick={() => { void exportCsv() }} data-testid="earn-export-csv">{t('dash.earn.export.csv', undefined, locale)}</button>
      </div>
      {note && <p className={note.ok ? 'db-muted' : 'db-field__error'} role={note.ok ? 'status' : 'alert'} data-testid="earn-export-note">{note.text}</p>}
    </Card>
  </>
}

/* ------------------------------------------------------------------ settlement statements */

function SettlementsPanel({ repo, location, locale }: { repo: RestaurantFinanceRepository; location: string; locale: string }) {
  const [page, setPage] = useState(1); const [open, setOpen] = useState<RestaurantSettlementDetailDto | null>(null); const [busy, setBusy] = useState(false); const [note, setNote] = useState<{ ok: boolean; text: string } | null>(null)
  const [status, setStatus] = useState('all')
  const list = useAsync(() => repo.settlements({ location, status, page, pageSize: PAGE_SIZE }), [repo, location, status, page])
  const show = async (id: string) => { setNote(null); try { setOpen(await repo.settlement(id)) } catch (e) { setNote({ ok: false, text: refusal(e, locale) }) } }
  const exportCsv = async () => {
    if (!open) return
    setBusy(true); setNote(null)
    try { const name = await repo.exportSettlement(open.id, open.reference); setNote({ ok: true, text: t('dash.earn.export.done', { name }, locale) }) }
    catch (e) { setNote({ ok: false, text: refusal(e, locale) }) }
    finally { setBusy(false) }
  }
  const rows = list.data?.items ?? []; const zone = open?.period.timezone
  const period = (s: RestaurantSettlementDto) => `${day(s.period.start, locale, s.period.timezone)} – ${day(s.period.end, locale, s.period.timezone)}`
  return <>
    <Card title={t('dash.earn.settlements.title', undefined, locale)} subtitle={t('dash.earn.statement.notInvoice', undefined, locale)} actions={
      <select className="db-select db-input--sm" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }} aria-label={t('dash.earn.col.status', undefined, locale)} data-testid="earn-filter-status">
        <option value="all">{t('dash.earn.filter.allStatuses', undefined, locale)}</option>{SETTLEMENT_STATUSES.map((x) => <option key={x} value={x}>{label('dash.earn.settlementStatus', x, locale)}</option>)}
      </select>}>
      <Listing state={list.state} empty={rows.length === 0 ? t('dash.earn.settlements.empty', undefined, locale) : null} onRetry={() => { void list.reload() }} locale={locale}>
        <div className="db-table-wrap"><table className="db-table" data-testid="earn-settlements">
          <thead><tr><th scope="col">{t('dash.earn.col.reference', undefined, locale)}</th><th scope="col">{t('dash.earn.col.period', undefined, locale)}</th><th scope="col">{t('dash.earn.col.restaurant', undefined, locale)}</th><th scope="col">{t('dash.earn.col.netPayable', undefined, locale)}</th><th scope="col">{t('dash.earn.col.paid', undefined, locale)}</th><th scope="col">{t('dash.earn.col.status', undefined, locale)}</th><th scope="col"><span className="db-sr-only">{t('dash.earn.view', undefined, locale)}</span></th></tr></thead>
          <tbody>{rows.map((s) => (
            <tr key={s.id} data-testid="earn-settlement-row" data-id={s.id} data-ref={s.reference} data-status={s.status}><td className="db-table__num">{s.reference}</td><td>{period(s)}</td><td dir="auto">{s.restaurant.name}</td><td><b>{money(s.summary.net_payable, locale)}</b></td><td>{money(s.summary.amount_paid, locale)}</td><td><StatePill prefix="dash.earn.settlementStatus" code={s.status} locale={locale} /></td>
              <td className="db-table__actions"><button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { void show(s.id) }} data-testid={`earn-settlement-open-${s.reference}`}>{t('dash.earn.view', undefined, locale)}</button></td></tr>
          ))}</tbody>
        </table></div>
        <Pager page={page} total={list.data?.total ?? 0} onPage={setPage} locale={locale} />
      </Listing>
      {note && !open && <p className="db-field__error" role="alert" data-testid="earn-settlement-error">{note.text}</p>}
    </Card>
    <Drawer open={!!open} onClose={() => { setOpen(null); setNote(null) }} title={open ? t('dash.earn.statement.title', { ref: open.reference }, locale) : ''} wide>
      {open && <div className="db-grid" style={{ gap: 16 }} data-testid="earn-settlement" data-status={open.status}>
        <p className="db-demo-banner" role="note" data-testid="earn-statement-kind"><span>{t('dash.earn.statement.notInvoice', undefined, locale)}</span></p>
        <dl className="db-earn-defs" data-testid="earn-settlement-summary">
          {([
            [t('dash.earn.col.status', undefined, locale), <StatePill key="s" prefix="dash.earn.settlementStatus" code={open.status} locale={locale} />],
            [t('dash.earn.col.restaurant', undefined, locale), <span key="r" dir="auto">{open.restaurant.name}</span>],
            [t('dash.earn.col.period', undefined, locale), `${period(open)} · ${open.period.timezone}`],
            [t('dash.earn.statement.orders', undefined, locale), open.summary.completed_orders.toLocaleString(locale)],
            [t('dash.earn.def.gross_sales', undefined, locale), money(open.summary.gross_sales, locale)],
            [t('dash.earn.statement.discounts', undefined, locale), money(open.summary.restaurant_discounts, locale)],
            [t('dash.earn.def.commission', undefined, locale), money(open.summary.commission, locale)],
            [t('dash.earn.statement.refundAdjustments', undefined, locale), money(open.summary.refund_adjustments, locale)],
            [t('dash.earn.statement.otherAdjustments', undefined, locale), money(open.summary.other_adjustments, locale)],
            [t('dash.earn.col.netPayable', undefined, locale), <b key="n" data-testid="earn-settlement-net">{money(open.summary.net_payable, locale)}</b>],
            [t('dash.earn.col.paid', undefined, locale), money(open.summary.amount_paid, locale)],
            [t('dash.earn.statement.pending', undefined, locale), money(open.summary.amount_pending, locale)],
          ] as Array<[string, ReactNode]>).map(([k, v]) => <div key={k} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, padding: '4px 0' }}><dt className="db-muted">{k}</dt><dd style={{ margin: 0 }}>{v}</dd></div>)}
        </dl>
        <Card title={t('dash.earn.statement.lines', undefined, locale)}>
          {open.lines.length === 0 ? <p className="db-muted">{t('dash.earn.lines.empty', undefined, locale)}</p> : (
            <div className="db-table-wrap"><table className="db-table" data-testid="earn-settlement-lines">
              <thead><tr><th scope="col">#</th><th scope="col">{t('dash.earn.col.date', undefined, locale)}</th><th scope="col">{t('dash.earn.col.type', undefined, locale)}</th><th scope="col">{t('dash.earn.col.order', undefined, locale)}</th><th scope="col">{t('dash.earn.def.gross_sales', undefined, locale)}</th><th scope="col">{t('dash.earn.def.commission', undefined, locale)}</th><th scope="col">{t('dash.earn.col.net', undefined, locale)}</th></tr></thead>
              <tbody>{open.lines.map((l) => <tr key={l.line}><td>{l.line}</td><td>{dayTime(l.occurred_at, locale, zone)}</td><td>{label('dash.earn.type', l.type, locale)}</td><td className="db-table__num">{l.order_number ?? '—'}</td><td>{money(l.gross_sales, locale)}</td><td>{money(l.commission, locale)}</td><td><b>{money(l.amount, locale)}</b></td></tr>)}</tbody>
            </table></div>
          )}
        </Card>
        <Card title={t('dash.earn.tab.payouts', undefined, locale)}>
          {open.payouts.length === 0 ? <p className="db-muted" data-testid="earn-settlement-payouts-empty">{t('dash.earn.statement.noPayout', undefined, locale)}</p> : (
            <ul className="db-list" data-testid="earn-settlement-payouts">{open.payouts.map((p) => <li key={p.id}><span className="db-table__num">{p.reference}</span> · {money(p.amount, locale)} · <StatePill prefix="dash.earn.payoutStatus" code={p.status} locale={locale} />{p.completed_at ? ` · ${dayTime(p.completed_at, locale, zone)}` : ''}</li>)}</ul>
          )}
        </Card>
        <div className="db-toolbar"><button type="button" className="db-btn db-btn--outline" disabled={busy} onClick={() => { void exportCsv() }} data-testid="earn-settlement-export">{t('dash.earn.statement.export', undefined, locale)}</button></div>
        {note && <p className={note.ok ? 'db-muted' : 'db-field__error'} role={note.ok ? 'status' : 'alert'} data-testid="earn-settlement-note">{note.text}</p>}
      </div>}
    </Drawer>
  </>
}

/* ------------------------------------------------------------------ payouts */

function PayoutsPanel({ repo, location, locale, timezone }: { repo: RestaurantFinanceRepository; location: string; locale: string; timezone?: string }) {
  const [page, setPage] = useState(1)
  const list = useAsync(() => repo.payouts({ location, page, pageSize: PAGE_SIZE }), [repo, location, page])
  const rows = list.data?.items ?? []; const accounts = list.data?.accounts ?? []
  return <>
    {list.state === 'ready' && list.data && !list.data.livePayouts && <p className="db-demo-banner" role="note" data-testid="earn-payouts-live"><span>{t('dash.earn.payouts.notLive', undefined, locale)}</span></p>}
    {/* Where payouts go is shown only to a person the backend names it for (the owner) — and only as a mask. */}
    {accounts.length > 0 && (
      <Card title={t('dash.earn.destination.title', undefined, locale)} subtitle={t('dash.earn.destination.sub', undefined, locale)}>
        <ul className="db-list" data-testid="earn-destinations">{accounts.map((a) => (
          <li key={a.location.id} data-status={a.status}><b dir="auto">{a.location.name}</b> · <span className="db-table__num" data-testid="earn-destination-mask">{a.destination ?? t('dash.earn.destination.none', undefined, locale)}</span> · <StatePill prefix="dash.earn.destinationStatus" code={a.status} locale={locale} />{a.test_only ? <> <Pill tone="amber">{t('dash.earn.destination.test', undefined, locale)}</Pill></> : null}</li>
        ))}</ul>
      </Card>
    )}
    <Card title={t('dash.earn.payouts.title', undefined, locale)} subtitle={t('dash.earn.payouts.sub', undefined, locale)}>
      <Listing state={list.state} empty={rows.length === 0 ? t('dash.earn.payouts.empty', undefined, locale) : null} onRetry={() => { void list.reload() }} locale={locale}>
        <div className="db-table-wrap"><table className="db-table" data-testid="earn-payouts">
          <thead><tr><th scope="col">{t('dash.earn.col.reference', undefined, locale)}</th><th scope="col">{t('dash.earn.col.settlement', undefined, locale)}</th><th scope="col">{t('dash.earn.col.restaurant', undefined, locale)}</th><th scope="col">{t('dash.earn.col.amount', undefined, locale)}</th><th scope="col">{t('dash.earn.col.method', undefined, locale)}</th><th scope="col">{t('dash.earn.col.destination', undefined, locale)}</th><th scope="col">{t('dash.earn.col.status', undefined, locale)}</th><th scope="col">{t('dash.earn.col.requested', undefined, locale)}</th><th scope="col">{t('dash.earn.col.completed', undefined, locale)}</th></tr></thead>
          <tbody>{rows.map((p: RestaurantPayoutDto) => (
            <tr key={p.id} data-testid="earn-payout-row" data-id={p.id} data-ref={p.reference} data-status={p.status}><td className="db-table__num">{p.reference}</td><td className="db-table__num">{p.settlement?.reference ?? '—'}</td><td dir="auto">{p.restaurant.name}</td><td><b>{money(p.amount, locale)}</b></td><td>{label('dash.earn.method', p.method, locale)}</td><td className="db-table__num">{p.destination ?? '—'}</td><td><StatePill prefix="dash.earn.payoutStatus" code={p.status} locale={locale} /></td><td>{dayTime(p.requested_at, locale, timezone)}</td><td>{dayTime(p.completed_at, locale, timezone)}</td></tr>
          ))}</tbody>
        </table></div>
        <Pager page={page} total={list.data?.total ?? 0} onPage={setPage} locale={locale} />
      </Listing>
    </Card>
  </>
}

/* ------------------------------------------------------------------ commercial terms */

function TermsPanel({ repo, location, locale }: { repo: RestaurantFinanceRepository; location: string; locale: string }) {
  const terms = useAsync(() => repo.terms(location), [repo, location])
  const data = terms.data
  return (
    <Card title={t('dash.earn.terms.title', undefined, locale)} subtitle={t('dash.earn.terms.sub', undefined, locale)}>
      <Listing state={terms.state} empty={data && !data.visible ? t('dash.earn.terms.hidden', undefined, locale) : data && data.terms.length === 0 ? t('dash.earn.terms.none', undefined, locale) : null} onRetry={() => { void terms.reload() }} locale={locale}>
        {data && <>
          <ul className="db-list" data-testid="earn-terms" data-visible={String(data.visible)}>{data.terms.map((x) => (
            <li key={x.location.id} data-location={x.location.id}>
              <b dir="auto">{x.location.name}</b> <Pill tone="muted">{x.currency}</Pill>
              {x.commission === null ? <p className="db-muted" style={{ margin: '4px 0 0' }}>{t('dash.earn.terms.noPlan', undefined, locale)}</p> : (
                <p style={{ margin: '4px 0 0' }}>
                  {t('dash.earn.terms.rate', undefined, locale)}: <b data-testid="earn-terms-rate">{ratePercent(x.commission.rate_bps)}</b> · {t('dash.earn.terms.base', undefined, locale)}: {label('dash.earn.base', x.commission.base_policy, locale)} · {label('dash.earn.applies', x.commission.applies_to, locale)} · {t('dash.earn.terms.since', { date: day(x.commission.effective_from, locale) }, locale)}{x.commission.effective_until ? ` · ${t('dash.earn.terms.until', { date: day(x.commission.effective_until, locale) }, locale)}` : ''}
                  {x.commission.test_fixture && <> <span data-testid="earn-terms-test"><Pill tone="amber">{t('dash.earn.terms.testFixture', undefined, locale)}</Pill></span></>}
                </p>
              )}
            </li>
          ))}</ul>
          <p className="db-muted" data-testid="earn-terms-fees">{label('dash.earn.providerFees', data.provider_fees.borne_by, locale)} <small>({humanize(data.approval)})</small></p>
        </>}
      </Listing>
    </Card>
  )
}
