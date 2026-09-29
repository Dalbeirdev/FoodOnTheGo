import { useEffect, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { formatLocalTime, formatMoney } from '../../i18n/format'
import { t } from '../../i18n/strings'
import { useDashboard } from '../DashboardContext'
import { BASE } from '../DashboardLayout'
import { Card, Icon, PageHeader, Pill, StatusBadge, Tabs } from '../components/ui'
import type { VerificationResult } from '../types'

/**
 * Pickup verification (Module 17): QR scanner SHELL (camera / decoder integration = PENDING, never faked) + manual code
 * entry. The mock verifies against the shared pickup-verification store; the backend must verify atomically and
 * idempotently later. Invalid / expired / wrong location / already used / not ready are handled without leaking data.
 */
export default function PickupVerificationPage() {
  const d = useDashboard(); const locale = d.locale; const loc = d.location!; const rid = loc.restaurant.id
  const [params] = useSearchParams(); const [mode, setMode] = useState<'scan' | 'code'>('code'); const [code, setCode] = useState(''); const [busy, setBusy] = useState(false); const [result, setResult] = useState<VerificationResult | null>(null); const [last, setLast] = useState<string | null>(null)
  const inFlight = useRef(false); const inputRef = useRef<HTMLInputElement>(null)
  useEffect(() => { document.title = `${t('dash.nav.pickup', undefined, locale)} · ${t('dash.brand', undefined, locale)}`; inputRef.current?.focus() }, [locale])
  const orderHint = params.get('order')
  const verify = async () => {
    const c = code.trim(); if (!c || inFlight.current) return
    inFlight.current = true; setBusy(true)
    try { const r = await d.repos.orders.verifyPickup(rid, c); setResult(r); setLast(c.toUpperCase()); if (r.ok) setCode('') }
    finally { inFlight.current = false; setBusy(false); inputRef.current?.focus() }
  }
  return (
    <div className="db-page" data-testid="db-pickup">
      <PageHeader title={t('dash.pickup.title', undefined, locale)} lead={t('dash.pickup.lead', { restaurant: loc.restaurant.name }, locale)} actions={<Link to={`${BASE}/orders?tab=ready`} className="db-btn db-btn--outline">{t('dash.pickup.readyOrders', undefined, locale)}</Link>} />
      {orderHint && <p className="db-badge db-badge--blue" style={{ alignSelf: 'flex-start' }}><Icon name="info" size={14} /> {t('dash.pickup.orderHint', { n: orderHint }, locale)}</p>}
      <div className="db-grid db-grid--half">
        <Card>
          <Tabs tabs={[{ id: 'scan' as const, label: t('dash.pickup.scan', undefined, locale) }, { id: 'code' as const, label: t('dash.pickup.enterCode', undefined, locale) }]} value={mode} onChange={setMode} label={t('dash.pickup.modes', undefined, locale)} />
          {mode === 'scan' ? (
            <div style={{ marginTop: 16 }} data-testid="pickup-scanner">
              <div className="db-scanner" role="img" aria-label={t('dash.pickup.scannerAria', undefined, locale)}><span className="db-scanner__frame" /><p style={{ margin: 0, fontWeight: 700 }}>{t('dash.pickup.scannerTitle', undefined, locale)}</p><p style={{ margin: 0, fontSize: '0.85rem' }}>{t('dash.pickup.scannerText', undefined, locale)}</p></div>
              <p className="db-badge db-badge--amber" style={{ marginTop: 12 }}><Icon name="warning" size={14} /> {t('dash.pickup.scannerPending', undefined, locale)}</p>
              <button type="button" className="db-btn db-btn--outline" style={{ marginTop: 10 }} onClick={() => setMode('code')}>{t('dash.pickup.useCode', undefined, locale)}</button>
            </div>
          ) : (
            <form style={{ marginTop: 16, display: 'flex', flexDirection: 'column', gap: 12 }} onSubmit={(e) => { e.preventDefault(); void verify() }} data-testid="pickup-form">
              <label className="db-field__label" htmlFor="pv-code">{t('dash.pickup.codeLabel', undefined, locale)}</label>
              <input id="pv-code" ref={inputRef} className="db-input db-code-input" value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} autoComplete="off" inputMode="text" maxLength={10} placeholder="ABC123" aria-describedby="pv-hint" data-testid="pickup-code" />
              <p id="pv-hint" className="db-field__hint">{t('dash.pickup.codeHint', undefined, locale)}</p>
              <button type="submit" className="db-btn db-btn--primary" disabled={busy || !code.trim() || !d.can('pickup.verify')} data-testid="pickup-submit"><Icon name="check" size={18} /> {busy ? t('dash.pickup.verifying', undefined, locale) : t('dash.pickup.verify', undefined, locale)}</button>
              {!d.can('pickup.verify') && <p className="db-field__error">{t('dash.error.permissionText', { role: t(`dash.role.${d.staff.role}`, undefined, locale) }, locale)}</p>}
            </form>
          )}
        </Card>
        <Card title={t('dash.pickup.resultTitle', undefined, locale)}>
          {!result && <p className="db-muted">{t('dash.pickup.resultEmpty', undefined, locale)}</p>}
          {result && result.ok && (
            <div className="db-verify-result db-verify-result--ok" role="status" aria-live="polite" data-testid="pickup-result" data-result="ok">
              <Icon name="check" size={26} />
              <div><h3>{t('dash.pickup.success', undefined, locale)}</h3><p style={{ margin: 0 }}>{t('dash.pickup.successText', { n: result.order.orderNumber, customer: result.order.customerDisplayName ?? '' }, locale)}</p>
                <div className="db-status-strip" style={{ marginTop: 8 }}><StatusBadge status={result.order.orderStatus} locale={locale} /><Pill tone="muted">{t('dash.units.items', { n: result.order.items.reduce((a, i) => a + i.quantity, 0) }, locale)} · {formatMoney(result.order.pricing.totalMinor, result.order.pricing.currency, locale)}</Pill><Pill tone="muted">{formatLocalTime(result.order.pickup.requestedAt, loc.restaurant.timezone, locale)}</Pill></div>
                <ul className="db-list" style={{ marginTop: 8 }}>{result.order.items.map((i) => <li key={i.lineId} dir="auto">{i.quantity}× {i.itemName}</li>)}</ul>
                <Link to={`${BASE}/orders/${result.order.orderNumber}`} className="db-btn db-btn--outline db-btn--sm" style={{ marginTop: 8 }}>{t('dash.orders.view', undefined, locale)}</Link></div>
            </div>
          )}
          {result && !result.ok && (
            <div className="db-verify-result db-verify-result--bad" role="alert" data-testid="pickup-result" data-result={result.reason}>
              <Icon name="warning" size={26} />
              <div><h3>{t(`dash.pickup.fail.${result.reason}`, undefined, locale)}</h3><p style={{ margin: 0 }}>{t(`dash.pickup.failText.${result.reason}`, undefined, locale)}</p>{last && <p className="db-muted" style={{ margin: '6px 0 0', fontSize: '0.8rem' }}>{t('dash.pickup.lastCode', { code: last }, locale)}</p>}</div>
            </div>
          )}
          <p className="db-muted" style={{ marginTop: 14, fontSize: '0.8rem' }}>{t('dash.pickup.backendNote', undefined, locale)}</p>
        </Card>
      </div>
    </div>
  )
}
