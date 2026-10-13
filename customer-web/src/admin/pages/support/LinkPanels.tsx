import { useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { t } from '../../../i18n/strings'
import { BASE } from '../../AdminLayout'
import { describeSupportError, type ApiAdminSupportRepository, type SupportDomainAction, type SupportDomainResult, type SupportLinkPanel, type SupportMoney, type SupportShortcut } from '../../api/ApiAdminSupportRepository'
import { Badge } from '../../components/DataTable'
import { code, toneOfCode } from '../finance/helpers'
import { fmtDateTime, money } from '../shared'
import { DeskDialog, DeskField, deskLabel } from './desk'

/**
 * The linked records of a case (Module 37): one READ-ONLY panel per link.
 *
 * A panel shows the record as its own domain has it NOW (the backend reads it when the case is shown — a case holds no
 * copy of a state). Without that domain's read permission the panel says only that a record is linked and which
 * permission is missing. The buttons under a panel are shortcuts to an action of the record's OWN domain (ask the
 * provider again, retry creating the order): that domain's service runs it and that domain's permission decides, so a
 * button is enabled only when the backend says `allowed`. Nothing here can mark a payment verified, complete a refund
 * or edit a settlement or a payout — the backend provides no such thing and no control is drawn for it.
 */
type Kind = 'text' | 'code' | 'money' | 'time' | 'number'
const FIELDS: Record<string, Array<[string, Kind]>> = {
  ORDER: [['order_number', 'text'], ['status', 'code'], ['payment_state', 'code'], ['refund_status', 'code'], ['total', 'money'], ['restaurant', 'text'], ['placed_at', 'time'], ['pickup_at', 'time'], ['ready_at', 'time'], ['picked_up_at', 'time'], ['completed_at', 'time'], ['cancelled_at', 'time'], ['rejection_reason', 'code']],
  PAYMENT: [['support_reference', 'text'], ['status', 'code'], ['status_reason', 'code'], ['order_creation', 'code'], ['order_creation_reason', 'code'], ['amount', 'money'], ['provider', 'text'], ['provider_mode', 'text'], ['provider_payment_reference', 'text'], ['attempt_number', 'number'], ['restaurant', 'text'], ['verified_at', 'time'], ['last_reconciled_at', 'time']],
  REFUND: [['support_reference', 'text'], ['status', 'code'], ['review_reason', 'code'], ['type', 'code'], ['amount', 'money'], ['order_number', 'text'], ['reason_code', 'code'], ['failure_category', 'code'], ['provider_status', 'text'], ['requested_at', 'time'], ['completed_at', 'time']],
  SETTLEMENT: [['reference', 'text'], ['status', 'code'], ['restaurant', 'text'], ['period_start', 'time'], ['period_end', 'time'], ['order_count', 'number'], ['line_count', 'number'], ['gross_sales', 'money'], ['commission', 'money'], ['refund_adjustments', 'money'], ['other_adjustments', 'money'], ['net_payable', 'money'], ['paid', 'money'], ['paid_at', 'time']],
  PAYOUT: [['support_reference', 'text'], ['status', 'code'], ['restaurant', 'text'], ['method', 'code'], ['amount', 'money'], ['provider', 'text'], ['provider_status', 'text'], ['provider_payout_reference', 'text'], ['failure_code', 'code'], ['review_reason', 'code'], ['requested_at', 'time'], ['completed_at', 'time']],
  DISPUTE: [['provider_dispute_reference', 'text'], ['status', 'code'], ['phase', 'code'], ['reason_code', 'code'], ['amount', 'money'], ['order_number', 'text'], ['restaurant', 'text'], ['opened_at', 'time'], ['closed_at', 'time']],
  REVIEW: [['status', 'code'], ['overall_rating', 'number'], ['submitted_at', 'time']],
  DIFFERENCE: [['type', 'code'], ['severity', 'code'], ['status', 'code'], ['subject_reference', 'text'], ['expected', 'money'], ['actual', 'money'], ['first_seen_at', 'time'], ['last_seen_at', 'time']],
  LOCATION: [['name', 'text'], ['status', 'code'], ['operational_status', 'code']],
  ORGANIZATION: [['name', 'text'], ['status', 'code']],
  // What a case needs of a customer: who it is. The phone number arrives masked and is shown as it arrives.
  CUSTOMER: [['name', 'text'], ['status', 'code'], ['phone', 'text']],
}
const SHORTCUT_KEY: Record<string, string> = { PAYMENT_RECONCILE: 'payment', REFUND_RECONCILE: 'refund', ORDER_RECOVER: 'order' }
const isMoney = (v: unknown): v is SupportMoney => typeof v === 'object' && v !== null && typeof (v as SupportMoney).amount === 'number' && typeof (v as SupportMoney).currency === 'string'
const str = (v: unknown) => (typeof v === 'string' ? v : typeof v === 'number' ? String(v) : '')

/** The existing admin page of the record's domain — only where one exists and can open that record. */
export function domainPage(p: SupportLinkPanel): string | null {
  const r = p.record ?? {}; const id = encodeURIComponent(p.record_id)
  switch (p.type) {
    case 'ORDER': return str(r.order_number) ? `${BASE}/orders/${encodeURIComponent(str(r.order_number))}` : null
    case 'PAYMENT': return `${BASE}/payments?q=${id}`
    case 'REFUND': return `${BASE}/refunds?open=${id}`
    case 'SETTLEMENT': return `${BASE}/settlements?tab=settlements&open=${id}`
    case 'PAYOUT': return `${BASE}/settlements?tab=payouts&open=${id}`
    case 'DISPUTE': return str(r.provider_dispute_reference) ? `${BASE}/refunds?view=disputes&q=${encodeURIComponent(str(r.provider_dispute_reference))}` : `${BASE}/refunds?view=disputes`
    case 'DIFFERENCE': return `${BASE}/settlements?tab=reconciliation&open=${id}`
    case 'LOCATION': return `${BASE}/restaurants/${id}`
    default: return null   // reviews, organizations and customers have no page that opens one record from the backend
  }
}

function Value({ kind, value, locale }: { kind: Kind; value: unknown; locale: string }): ReactNode {
  if (kind === 'money') return isMoney(value) ? <>{money(value.amount, value.currency, locale)} <Badge tone="muted">{value.currency}</Badge></> : '—'
  if (kind === 'time') return fmtDateTime(str(value), locale)
  if (kind === 'code') return <Badge tone={toneOfCode(str(value))}>{code(str(value), locale, 'adm.desk.recordCode')}</Badge>
  return <span dir="auto">{str(value)}</span>
}

export function LinkPanels({ caseId, panels, repo, locale, onPanels, onActed }: {
  caseId: string; panels: SupportLinkPanel[]; repo: ApiAdminSupportRepository; locale: string
  onPanels: (panels: SupportLinkPanel[]) => void; onActed: () => void
}) {
  const [asking, setAsking] = useState<{ panel: SupportLinkPanel; shortcut: SupportShortcut } | null>(null)
  const [reason, setReason] = useState(''); const [busy, setBusy] = useState(false); const [error, setError] = useState<string | null>(null)
  const [results, setResults] = useState<Record<string, SupportDomainResult>>({})
  const say = (key: string, params?: Record<string, string | number>) => t(`adm.desk.${key}`, params, locale)

  const run = async () => {
    if (!asking) return
    if (reason.trim().length < 3) { setError(say('error.reason')); return }
    setBusy(true); setError(null)
    try {
      const res = await repo.domainAction(caseId, asking.shortcut.action as SupportDomainAction, asking.panel.id, reason)
      setResults((r) => ({ ...r, [asking.panel.id]: res.result })); onPanels(res.links); setAsking(null); setReason(''); onActed()
    } catch (e) { setError(describeSupportError(e).message) } finally { setBusy(false) }
  }

  if (panels.length === 0) return <p className="db-muted" data-testid="desk-links-empty">{say('links.empty')}</p>
  return (
    <div className="adm-desk-panels" data-testid="desk-links">
      {panels.map((p) => {
        const page = p.visible ? domainPage(p) : null; const record = p.record ?? {}; const result = results[p.id]
        return (
          <section key={p.id} className={`adm-desk-panel ${p.visible ? '' : 'adm-desk-panel--closed'}`} aria-label={`${deskLabel('link', p.type, locale)} ${p.reference}`} data-testid="desk-link-panel" data-type={p.type} data-visible={p.visible ? 'true' : 'false'} data-link={p.id} data-reference={p.reference}>
            <div className="adm-desk-panel__head">
              <b><Badge tone="navy">{deskLabel('link', p.type, locale)}</Badge> <span className="adm-code" dir="auto">{p.reference}</span></b>
              {page && <Link to={page} className="db-link" data-testid="desk-link-open">{say('panel.open', { page: deskLabel('page', p.type, locale) })} →</Link>}
            </div>
            {p.visible ? <>
              <p className="db-muted adm-small" data-testid="desk-link-read">{say('panel.readFrom', { domain: deskLabel('domain', p.type, locale), time: fmtDateTime(p.read_at, locale) })}</p>
              {p.type === 'DISPUTE' && <p className="adm-desk-deadline" role="note" data-testid="desk-dispute-respond-by">{record.respond_by_at ? say('panel.respondBy', { date: fmtDateTime(str(record.respond_by_at), locale) }) : say('panel.respondByNone')} <small>{say('panel.disputeAnswer')}</small></p>}
              <dl className="adm-details">
                {(FIELDS[p.type] ?? []).filter(([field]) => record[field] !== null && record[field] !== undefined && record[field] !== '').map(([field, kind]) => { const v = record[field]; return (
                  <div key={field}><dt>{code(field, locale, 'adm.desk.rec')}</dt><dd data-field={field} data-value={isMoney(v) ? String(v.amount) : str(v)}><Value kind={kind} value={v} locale={locale} /></dd></div>
                ) })}
              </dl>
              {(p.shortcuts ?? []).length > 0 && <div className="adm-desk-shortcuts">
                {(p.shortcuts ?? []).map((sc) => (
                  <span key={sc.action} className="adm-desk-shortcut">
                    <button type="button" className="db-btn db-btn--outline db-btn--sm" disabled={sc.allowed !== true} title={sc.allowed === true ? undefined : say('shortcut.needs', { permission: sc.required_permission })} onClick={() => { setError(null); setReason(''); setAsking({ panel: p, shortcut: sc }) }} data-testid="desk-shortcut" data-action={sc.action} data-allowed={sc.allowed === true ? 'true' : 'false'}>{say(`shortcut.${SHORTCUT_KEY[sc.action] ?? 'other'}`)}</button>
                    {sc.allowed !== true && <small className="db-muted" data-testid="desk-shortcut-missing">{say('shortcut.needs', { permission: sc.required_permission })}</small>}
                  </span>
                ))}
              </div>}
              {result && <p className="adm-desk-result" role="status" data-testid="desk-shortcut-result" data-action={result.action} data-before={result.before} data-after={result.after} data-changed={result.changed ? 'true' : 'false'}>{say(result.changed ? 'shortcut.result' : 'shortcut.resultSame', { action: say(`shortcut.${SHORTCUT_KEY[result.action] ?? 'other'}`), before: result.before, after: result.after })}</p>}
            </> : (
              <p className="adm-desk-closed" data-testid="desk-link-forbidden" data-reason={p.reason ?? ''} data-permission={p.required_permission ?? ''}>{p.reason === 'PERMISSION' ? say('panel.forbidden', { permission: p.required_permission ?? '' }) : say('panel.gone')}</p>
            )}
          </section>
        )
      })}
      {asking && (
        <DeskDialog action="domain-action" title={say(`shortcut.${SHORTCUT_KEY[asking.shortcut.action] ?? 'other'}`)} text={say('shortcut.text', { reference: asking.panel.reference })} submitLabel={say('shortcut.confirm')} onSubmit={run} onCancel={() => setAsking(null)} busy={busy} error={error} locale={locale}>
          <DeskField label={say('field.reason')}>{(id) => <textarea id={id} className="db-textarea" rows={3} value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} data-testid="desk-field-reason" />}</DeskField>
        </DeskDialog>
      )}
    </div>
  )
}
