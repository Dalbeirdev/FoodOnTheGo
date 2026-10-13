import { useCallback, useEffect, useRef, useState } from 'react'
import { t } from '../../../i18n/strings'
import { EXPORT_REPORTS, describeAnalyticsError, exportIsPending, type AnalyticsExportDto, type AnalyticsFilter, type AnalyticsProblem, type RestaurantAnalyticsReports } from '../../api/apiAnalytics'
import { Card, EmptyState, ErrorState, Pill, Skeleton } from '../../components/ui'
import { fmtCount, fmtDay, fmtInstant } from './format'

/**
 * Exports (Module 38): a CSV of the restaurant's own orders or menu items for the location and the period of the
 * filters. Asking for one queues a job on the backend; the list shows the person's OWN exports (a colleague's does not
 * exist for them) and is read again every few seconds while one is being prepared. A file can be downloaded until it
 * expires. Only a holder of the export permission reaches this tab — and the backend checks it again on every call.
 */
const TONE: Record<string, string> = { QUEUED: 'muted', PROCESSING: 'blue', READY: 'green', FAILED: 'red', EXPIRED: 'amber' }
const MAX_QUIET_FAILURES = 5
type Line = { ok: boolean; text: string }

export function ExportsTab({ repo, filter, blocked, scope, locale, pollMs }: {
  repo: RestaurantAnalyticsReports; filter: AnalyticsFilter
  /** Why the filters cannot be asked right now (an unfinished custom period), or null. */
  blocked: string | null
  /** The location and the period of the filters, in words. */
  scope: string; locale: string; pollMs: number
}) {
  const [rows, setRows] = useState<AnalyticsExportDto[]>([]); const [reports, setReports] = useState<Array<{ key: string; label: string }>>([]); const [expiryHours, setExpiryHours] = useState<number | null>(null)
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading'); const [problem, setProblem] = useState<AnalyticsProblem | null>(null)
  const [report, setReport] = useState<string>(EXPORT_REPORTS[0]); const [busy, setBusy] = useState(false); const [note, setNote] = useState<Line | null>(null)
  const [downloading, setDownloading] = useState<string | null>(null); const [fileNote, setFileNote] = useState<Line | null>(null)
  const [round, setRound] = useState(0); const failures = useRef(0); const alive = useRef(true)

  const refresh = useCallback(async (quiet: boolean) => {
    if (!quiet) setState('loading')
    try {
      const list = await repo.exports()
      if (!alive.current) return
      failures.current = 0; setRows(list.data); setReports(list.reports ?? []); setExpiryHours(typeof list.expiry_hours === 'number' ? list.expiry_hours : null); setProblem(null); setState('ready')
    } catch (e) {
      if (!alive.current) return
      // A quiet re-read that fails keeps what is on screen and is tried again a few times.
      if (quiet) { failures.current++; setRound((x) => x + 1) } else { setProblem(describeAnalyticsError(e, locale)); setState('error') }
    }
  }, [repo, locale])
  useEffect(() => { alive.current = true; void refresh(false); return () => { alive.current = false } }, [refresh])

  // While an export is queued or being built the list is read again; it stops by itself when none is.
  const pending = rows.some(exportIsPending)
  useEffect(() => {
    if (!pending || state !== 'ready' || failures.current >= MAX_QUIET_FAILURES) return
    const timer = window.setTimeout(() => { if (document.visibilityState === 'hidden') setRound((x) => x + 1); else void refresh(true) }, pollMs)
    return () => window.clearTimeout(timer)
  }, [pending, state, rows, round, pollMs, refresh])

  const refusal = (e: unknown): string => { const p = describeAnalyticsError(e, locale); return p.kind === 'forbidden' ? t('dash.ra.export.forbidden', undefined, locale) : p.message }
  const generate = async () => {
    setNote(null)
    if (blocked) { setNote({ ok: false, text: blocked }); return }
    setBusy(true)
    try {
      const made = await repo.requestExport(report, filter)
      if (!alive.current) return
      failures.current = 0; setRows((prev) => [made, ...prev.filter((x) => x.id !== made.id)]); setNote({ ok: true, text: t('dash.ra.export.requested', { label: made.label }, locale) })
    } catch (e) { if (alive.current) setNote({ ok: false, text: refusal(e) }) } finally { if (alive.current) setBusy(false) }
  }
  const download = async (e: AnalyticsExportDto) => {
    setFileNote(null); setDownloading(e.id)
    try { const name = await repo.downloadExport(e); if (alive.current) setFileNote({ ok: true, text: t('dash.ra.export.downloaded', { name }, locale) }) }
    catch (err) {
      if (!alive.current) return
      setFileNote({ ok: false, text: refusal(err) })
      // Not ready any more, expired or gone: the list says what it is now.
      const kind = describeAnalyticsError(err, locale).kind
      if (kind === 'expired' || kind === 'conflict' || kind === 'not_found') void refresh(true)
    } finally { if (alive.current) setDownloading(null) }
  }

  const options = reports.length > 0 ? reports : EXPORT_REPORTS.map((key) => ({ key, label: t(`dash.ra.export.report.${key}`, undefined, locale) }))
  const places = (e: AnalyticsExportDto) => { const names = (e.scope?.locations ?? []).map((l) => l.name).filter(Boolean); return names.length ? names.join(', ') : '—' }
  const failure = (e: AnalyticsExportDto) => (e.status !== 'FAILED' ? null : t(e.failure_code === 'too_many_rows' ? 'dash.ra.export.failed.tooManyRows' : 'dash.ra.export.failed.other', undefined, locale))
  return (
    <div className="db-grid" data-testid="ra-exports">
      <Card title={t('dash.ra.export.title', undefined, locale)} subtitle={expiryHours ? t('dash.ra.export.sub', { hours: expiryHours }, locale) : t('dash.ra.export.sub.plain', undefined, locale)}>
        <div className="db-toolbar ra-export__form">
          <label className="db-field"><span className="db-field__label">{t('dash.ra.export.report', undefined, locale)}</span>
            <select className="db-select" value={report} onChange={(e) => setReport(e.target.value)} data-testid="ra-export-report">{options.map((o) => <option key={o.key} value={o.key}>{o.label}</option>)}</select>
          </label>
          <button type="button" className="db-btn db-btn--primary" disabled={busy} onClick={() => { void generate() }} data-testid="ra-export-generate">{t(busy ? 'dash.ra.export.generating' : 'dash.ra.export.generate', undefined, locale)}</button>
        </div>
        <p className="db-muted ra-small" data-testid="ra-export-scope">{t('dash.ra.export.scope', { scope }, locale)}</p>
        {note && <p className={note.ok ? 'db-muted' : 'db-field__error'} role={note.ok ? 'status' : 'alert'} data-testid="ra-export-note" data-ok={String(note.ok)}>{note.text}</p>}
      </Card>
      <Card title={t('dash.ra.export.list', undefined, locale)} subtitle={t('dash.ra.export.list.sub', undefined, locale)} actions={<button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { failures.current = 0; void refresh(state !== 'ready') }} data-testid="ra-export-refresh">{t('dash.ra.export.refresh', undefined, locale)}</button>}>
        {state === 'loading' && <Skeleton rows={4} />}
        {state === 'error' && problem && (problem.kind === 'forbidden'
          ? <p className="ra-empty" role="alert" data-testid="ra-export-forbidden">{t('dash.ra.export.forbidden', undefined, locale)}</p>
          : <ErrorState title={t('dash.ra.error.title', undefined, locale)} text={problem.message} onRetry={() => { void refresh(false) }} locale={locale} />)}
        {state === 'ready' && rows.length === 0 && <EmptyState icon="audit" title={t('dash.ra.export.empty', undefined, locale)} text={t('dash.ra.export.empty.text', undefined, locale)} />}
        {state === 'ready' && rows.length > 0 && (
          <div className="db-table-wrap"><table className="db-table" data-testid="ra-export-list" data-pending={String(pending)}>
            <caption className="db-sr-only">{t('dash.ra.export.list', undefined, locale)}</caption>
            <thead><tr><th scope="col">{t('dash.ra.export.col.report', undefined, locale)}</th><th scope="col">{t('dash.ra.export.col.period', undefined, locale)}</th><th scope="col">{t('dash.ra.col.location', undefined, locale)}</th><th scope="col">{t('dash.ra.export.col.requested', undefined, locale)}</th><th scope="col">{t('dash.ra.export.col.status', undefined, locale)}</th><th scope="col" className="ra-num">{t('dash.ra.export.col.rows', undefined, locale)}</th><th scope="col">{t('dash.ra.export.col.expires', undefined, locale)}</th><th scope="col"><span className="db-sr-only">{t('dash.ra.export.download', undefined, locale)}</span></th></tr></thead>
            <tbody>{rows.map((e) => { const zone = e.period?.timezone ?? undefined; const why = failure(e); return (
              <tr key={e.id} data-testid={`ra-export-row-${e.id}`} data-status={e.status} data-report={e.report}>
                <th scope="row" className="ra-rowhead">{e.label}</th>
                <td>{fmtDay(e.period?.from, locale)} – {fmtDay(e.period?.to, locale)}{zone ? <><br /><small className="db-muted">{zone}</small></> : null}</td>
                <td dir="auto">{places(e)}</td>
                <td>{fmtInstant(e.requested_at, locale, zone)}</td>
                <td><span data-testid={`ra-export-status-${e.id}`} data-status={e.status}><Pill tone={TONE[e.status] ?? 'muted'}>{t(`dash.ra.export.status.${e.status}`, undefined, locale)}</Pill></span>{why && <><br /><small className="db-muted" data-testid={`ra-export-failure-${e.id}`}>{why}</small></>}</td>
                <td className="ra-num" data-testid={`ra-export-rows-${e.id}`}>{fmtCount(e.row_count, locale) ?? '—'}</td>
                <td data-testid={`ra-export-expires-${e.id}`}>{e.status === 'READY' || e.status === 'EXPIRED' ? fmtInstant(e.expires_at, locale, zone) : '—'}</td>
                <td className="db-table__actions">{e.status === 'READY' && e.download_path
                  ? <button type="button" className="db-btn db-btn--outline db-btn--sm" disabled={downloading === e.id} onClick={() => { void download(e) }} aria-label={t('dash.ra.export.downloadOf', { label: e.label, from: fmtDay(e.period?.from, locale), to: fmtDay(e.period?.to, locale) }, locale)} data-testid={`ra-export-download-${e.id}`}>{t('dash.ra.export.download', undefined, locale)}</button>
                  : null}</td>
              </tr>
            ) })}</tbody>
          </table></div>
        )}
        {pending && state === 'ready' && <p className="db-muted ra-small" role="status" data-testid="ra-export-polling">{t('dash.ra.export.polling', undefined, locale)}</p>}
        {fileNote && <p className={fileNote.ok ? 'db-muted' : 'db-field__error'} role={fileNote.ok ? 'status' : 'alert'} data-testid="ra-export-file-note" data-ok={String(fileNote.ok)}>{fileNote.text}</p>}
      </Card>
    </div>
  )
}
