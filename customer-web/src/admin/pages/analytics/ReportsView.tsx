import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Icon } from '../../../dashboard/components/ui'
import { Badge } from '../../components/DataTable'
import { describeAnalyticsError, isExportPending, type ExportDto, type ExportStatus } from '../../api/ApiAdminAnalyticsRepository'
import { humanize } from '../finance/helpers'
import { count, dayLabel, instant, say } from './format'
import { ReportFrame, Section, Table, useReport, type ViewProps } from './parts'

/**
 * Reports (Module 38): ask for one of the curated CSV exports for the market and the period of the filter bar, watch
 * it being prepared, download it while it exists.
 *
 * The file is built by a queued job: the request answers "Queued", and the list is read again every few seconds for
 * as long as an export is queued or being prepared — and not at all once none is. An export belongs to the person who
 * asked for it; the download is made with the administrator's own token and is checked again by the backend.
 */
export const EXPORT_POLL_MS = 3000
const TONE: Record<ExportStatus, string> = { QUEUED: 'muted', PROCESSING: 'blue', READY: 'green', FAILED: 'red', EXPIRED: 'muted' }

export function ReportsView(p: ViewProps & { pollMs?: number }) {
  const { locale, repo, publish } = p
  const pollMs = p.pollMs ?? EXPORT_POLL_MS
  const list = useReport(() => repo.exports(), [repo])
  const [exports, setExports] = useState<ExportDto[]>([])
  const [report, setReport] = useState('')
  const [busy, setBusy] = useState(false)
  const [failed, setFailed] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [rowProblem, setRowProblem] = useState<{ id: string; message: string } | null>(null)
  const [downloading, setDownloading] = useState<string | null>(null)

  // This view has no report with a period of its own: the page's line about the figures on screen is cleared.
  useEffect(() => { publish(null, null) }, [publish])
  useEffect(() => { if (list.data) setExports(list.data.data) }, [list.data])
  const reports = useMemo(() => list.data?.reports ?? [], [list.data])
  const chosen = reports.find((r) => r.key === report) ?? reports[0] ?? null

  // Watch the exports that are still being prepared — and stop as soon as none is.
  const pending = exports.some(isExportPending)
  const alive = useRef(true)
  useEffect(() => { alive.current = true; return () => { alive.current = false } }, [])
  const reread = useCallback(async () => { try { const fresh = await repo.exports(); if (alive.current) setExports(fresh.data) } catch { /* the next round asks again */ } }, [repo])
  useEffect(() => {
    if (!pending) return
    const timer = window.setInterval(() => { void reread() }, pollMs)
    return () => window.clearInterval(timer)
  }, [pending, pollMs, reread])

  const generate = async () => {
    if (!chosen || !p.query || busy) return
    setBusy(true); setFailed(null); setNotice(null)
    try {
      const created = await repo.requestExport({ report: chosen.key, market: p.query.market, preset: p.query.preset, from: p.query.from, to: p.query.to })
      setExports((rows) => [created, ...rows.filter((r) => r.id !== created.id)])
      setNotice(say('reports.queued', { name: created.label }, locale))
    } catch (e) {
      const problem = describeAnalyticsError(e)
      setFailed(problem.kind === 'forbidden' ? say('reports.forbidden', { needs: chosen.requires.join(', ') }, locale) : problem.kind === 'unavailable' ? say('reports.failed', undefined, locale) : problem.message)
    } finally { setBusy(false) }
  }
  const download = async (e: ExportDto) => {
    setDownloading(e.id); setRowProblem(null); setNotice(null)
    try { setNotice(say('reports.downloaded', { name: await repo.download(e) }, locale)); void reread() } catch (x) {
      const problem = describeAnalyticsError(x)
      setRowProblem({ id: e.id, message: problem.kind === 'forbidden' ? say('reports.downloadForbidden', undefined, locale) : problem.kind === 'unavailable' ? say('reports.downloadFailed', undefined, locale) : problem.message })
      void reread()
    } finally { setDownloading(null) }
  }

  const period = !p.query ? null : p.query.preset === 'custom' ? say('range', { from: dayLabel(p.query.from, locale), to: dayLabel(p.query.to, locale) }, locale) : say(`preset.${p.query.preset}`, undefined, locale)
  return (
    <ReportFrame report={list} locale={locale} forbidden={say('reports.noAccess', undefined, locale)}>{(d) => (
      <>
        <Section title={say('reports.new', undefined, locale)} subtitle={say('reports.newNote', { hours: d.expiry_hours, rows: count(d.max_rows, locale), format: d.format }, locale)} testId="an-export-new">
          <div className="adm-an-filters">
            <label className="adm-an-field"><span>{say('reports.report', undefined, locale)}</span>
              <select className="db-select db-input--sm" value={chosen?.key ?? ''} onChange={(e) => { setReport(e.target.value); setFailed(null) }} data-testid="an-export-report">
                {reports.map((r) => <option key={r.key} value={r.key}>{r.label}</option>)}
              </select>
            </label>
            <button type="button" className="db-btn db-btn--primary" onClick={() => { void generate() }} disabled={!chosen || !p.query || busy} data-testid="an-export-generate">{busy ? say('reports.asking', undefined, locale) : say('reports.generate', undefined, locale)}</button>
          </div>
          <p className="adm-an-note" data-testid="an-export-scope">{period ? say('reports.scope', { market: p.marketName, period }, locale) : say('period.incomplete', undefined, locale)}</p>
          {chosen && chosen.requires.length > 0 && <p className="adm-an-note" data-testid="an-export-requires">{say('reports.requires', { needs: chosen.requires.join(', ') }, locale)}</p>}
          {failed && <p className="db-field__error" role="alert" data-testid="an-export-error">{failed}</p>}
          {notice && <p className="adm-an-notice" role="status" data-testid="an-export-notice">{notice}</p>}
        </Section>

        <Section title={say('reports.mine', undefined, locale)} subtitle={say('reports.mineNote', undefined, locale)} testId="an-exports" actions={<button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { void reread() }} data-testid="an-export-refresh">{say('reports.refresh', undefined, locale)}</button>}>
          <p className="db-sr-only" role="status" data-testid="an-export-watching" data-watching={pending ? 'true' : 'false'}>{pending ? say('reports.watching', undefined, locale) : ''}</p>
          {exports.length === 0 ? <p className="adm-an-empty" data-testid="an-exports-empty">{say('reports.none', undefined, locale)}</p> : (
            <Table caption={say('reports.mine', undefined, locale)} rows={exports} keyOf={(e) => e.id} testId="an-exports-table"
              rowProps={(e) => ({ 'data-testid': `an-export-row-${e.id}`, 'data-status': e.status, 'data-report': e.report })}
              columns={[
                { id: 'report', head: say('reports.col.report', undefined, locale), rowHeader: true, cell: (e) => e.label },
                { id: 'period', head: say('reports.col.period', undefined, locale), cell: (e) => (e.period.from && e.period.to ? <>{say('range', { from: dayLabel(e.period.from, locale), to: dayLabel(e.period.to, locale) }, locale)}{e.period.timezone ? <small className="adm-an-sub"> {e.period.timezone}</small> : null}</> : '—') },
                { id: 'status', head: say('col.status', undefined, locale), cell: (e) => (
                  <>
                    <span data-testid={`an-export-status-${e.id}`} data-status={e.status}><Badge tone={TONE[e.status] ?? 'muted'} dot>{say(`reports.status.${e.status}`, undefined, locale)}</Badge></span>
                    {e.status === 'FAILED' && e.failure_code && <small className="adm-an-sub"> {humanize(e.failure_code)}</small>}
                    {rowProblem?.id === e.id && <p className="db-field__error" role="alert" data-testid={`an-export-problem-${e.id}`}>{rowProblem.message}</p>}
                  </>) },
                { id: 'rows', head: say('reports.col.rows', undefined, locale), end: true, cell: (e) => <span data-testid={`an-export-rows-${e.id}`}>{e.row_count === null ? '—' : count(e.row_count, locale)}</span> },
                { id: 'requested', head: say('reports.col.requested', undefined, locale), cell: (e) => instant(e.requested_at, e.period.timezone, locale) },
                { id: 'expires', head: say('reports.col.expires', undefined, locale), cell: (e) => <span data-testid={`an-export-expires-${e.id}`}>{e.status === 'READY' || e.status === 'EXPIRED' ? instant(e.expires_at, e.period.timezone, locale) : '—'}</span> },
                { id: 'file', head: say('reports.col.file', undefined, locale), end: true, cell: (e) => (e.status === 'READY'
                  ? <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { void download(e) }} disabled={downloading === e.id} data-testid={`an-export-download-${e.id}`}><Icon name="down" size={14} /> {say('reports.download', undefined, locale)}<span className="db-sr-only"> — {e.label}</span></button>
                  : <span className="db-muted">{say(isExportPending(e) ? 'reports.preparing' : 'reports.noFile', undefined, locale)}</span>) },
              ]} />
          )}
        </Section>
      </>
    )}</ReportFrame>
  )
}
