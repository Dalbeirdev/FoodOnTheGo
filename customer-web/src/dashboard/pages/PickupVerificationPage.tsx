import { useCallback, useEffect, useRef, useState } from 'react'
import jsQR from 'jsqr'
import { Link, useSearchParams } from 'react-router-dom'
import { formatLocalTime, formatMoney } from '../../i18n/format'
import { t } from '../../i18n/strings'
import { useDashboard } from '../DashboardContext'
import { BASE } from '../DashboardLayout'
import { Card, Icon, PageHeader, Pill, StatusBadge, Tabs } from '../components/ui'
import type { PickupVerifyInput, VerificationResult } from '../types'

/**
 * Pickup verification (Module 17 UI, Module 32 verification): the camera scanner (jsQR on getUserMedia frames), a
 * field a hardware scanner types the payload into, and the manual fallback — order number + code. Whatever was
 * read is UNTRUSTED input: the backend (or the development mock) decides; the page only shows the answer. A
 * refusal is a plain reason for the counter; a lost connection never marks a pickup.
 */
const PAYLOAD = /^FOTG1\.[A-Za-z0-9_-]{43}$/

export default function PickupVerificationPage() {
  const d = useDashboard(); const locale = d.locale; const loc = d.location!; const rid = loc.restaurant.id
  const [params] = useSearchParams(); const [mode, setMode] = useState<'scan' | 'code'>('code')
  const [code, setCode] = useState(''); const [orderNumber, setOrderNumber] = useState(params.get('order') ?? ''); const [payload, setPayload] = useState('')
  const [busy, setBusy] = useState(false); const [result, setResult] = useState<VerificationResult | null>(null); const [last, setLast] = useState<string | null>(null)
  const [camera, setCamera] = useState<'off' | 'starting' | 'on' | 'unavailable'>('off')
  const inFlight = useRef(false); const inputRef = useRef<HTMLInputElement>(null); const payloadRef = useRef<HTMLInputElement>(null)
  const videoRef = useRef<HTMLVideoElement>(null); const canvasRef = useRef<HTMLCanvasElement>(null); const streamRef = useRef<MediaStream | null>(null); const frameRef = useRef<number | null>(null)
  const lastScanned = useRef<{ payload: string; at: number } | null>(null)
  useEffect(() => { document.title = `${t('dash.nav.pickup', undefined, locale)} · ${t('dash.brand', undefined, locale)}` }, [locale])
  useEffect(() => { if (mode === 'code') inputRef.current?.focus(); else payloadRef.current?.focus() }, [mode])
  const orderHint = params.get('order')

  const verify = useCallback(async (input: PickupVerifyInput, shown: string) => {
    if (inFlight.current) return
    inFlight.current = true; setBusy(true)
    try { const r = await d.repos.orders.verifyPickup(rid, input); setResult(r); setLast(shown); if (r.ok) { setCode(''); setPayload('') } }
    finally { inFlight.current = false; setBusy(false) }
  }, [d.repos.orders, rid])

  const stopCamera = useCallback(() => {
    if (frameRef.current !== null) { window.cancelAnimationFrame(frameRef.current); frameRef.current = null }
    streamRef.current?.getTracks().forEach((track) => track.stop()); streamRef.current = null
    setCamera('off')
  }, [])
  const startCamera = useCallback(async () => {
    if (!navigator.mediaDevices?.getUserMedia) { setCamera('unavailable'); return }
    setCamera('starting')
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
      streamRef.current = stream
      const video = videoRef.current; if (!video) { stopCamera(); return }
      video.srcObject = stream; await video.play(); setCamera('on')
      const scan = () => {
        const v = videoRef.current; const c = canvasRef.current
        if (!v || !c || v.readyState < 2) { frameRef.current = window.requestAnimationFrame(scan); return }
        c.width = v.videoWidth; c.height = v.videoHeight
        const ctx = c.getContext('2d', { willReadFrequently: true })
        if (ctx) {
          ctx.drawImage(v, 0, 0, c.width, c.height)
          const image = ctx.getImageData(0, 0, c.width, c.height)
          const found = jsQR(image.data, image.width, image.height, { inversionAttempts: 'dontInvert' })
          const text = found?.data?.trim() ?? ''
          // The same code in the frame again within a few seconds is not a second attempt.
          if (text && PAYLOAD.test(text) && !(lastScanned.current && lastScanned.current.payload === text && Date.now() - lastScanned.current.at < 4000)) {
            lastScanned.current = { payload: text, at: Date.now() }
            void verify({ method: 'QR', payload: text }, 'QR')
          }
        }
        frameRef.current = window.requestAnimationFrame(scan)
      }
      frameRef.current = window.requestAnimationFrame(scan)
    } catch { stopCamera(); setCamera('unavailable') }
  }, [stopCamera, verify])
  useEffect(() => () => stopCamera(), [stopCamera])
  useEffect(() => { if (mode !== 'scan') stopCamera() }, [mode, stopCamera])

  const submitPayload = (e: React.FormEvent) => { e.preventDefault(); const p = payload.trim(); if (!p) return; void verify({ method: 'QR', payload: p }, 'QR') }
  const submitCode = (e: React.FormEvent) => { e.preventDefault(); const c = code.trim(); const n = orderNumber.trim(); if (!c || (d.live && !n)) return; void verify({ method: 'MANUAL_CODE', orderNumber: n, code: c }, c.toUpperCase()) }
  const can = d.can('pickup.verify')

  return (
    <div className="db-page" data-testid="db-pickup">
      <PageHeader title={t('dash.pickup.title', undefined, locale)} lead={t('dash.pickup.lead', { restaurant: loc.restaurant.name }, locale)} actions={<Link to={`${BASE}/orders?tab=ready`} className="db-btn db-btn--outline"><Icon name="orders" size={18} /> {t('dash.pickup.readyOrders', undefined, locale)}</Link>} />
      {orderHint && <p className="db-badge db-badge--blue" style={{ alignSelf: 'flex-start' }}><Icon name="info" size={14} /> {t('dash.pickup.orderHint', { n: orderHint }, locale)}</p>}
      <div className="db-grid db-grid--half">
        <Card>
          <Tabs tabs={[{ id: 'scan' as const, label: t('dash.pickup.scan', undefined, locale) }, { id: 'code' as const, label: t('dash.pickup.enterCode', undefined, locale) }]} value={mode} onChange={setMode} label={t('dash.pickup.modes', undefined, locale)} />
          {mode === 'scan' ? (
            <div style={{ marginTop: 16, display: 'flex', flexDirection: 'column', gap: 12 }} data-testid="pickup-scanner" data-camera={camera}>
              <div className="db-scanner" role="img" aria-label={t('dash.pickup.scannerAria', undefined, locale)} style={{ position: 'relative', overflow: 'hidden' }}>
                <video ref={videoRef} muted playsInline style={{ width: '100%', height: '100%', objectFit: 'cover', display: camera === 'on' ? 'block' : 'none' }} />
                <canvas ref={canvasRef} hidden />
                {camera !== 'on' && <><span className="db-scanner__frame" /><p style={{ margin: 0, fontWeight: 700 }}>{t('dash.pickup.scannerTitle', undefined, locale)}</p><p className="db-muted" style={{ margin: 0 }}>{t('dash.pickup.scannerText', undefined, locale)}</p></>}
              </div>
              {camera === 'on' ? <p className="db-badge db-badge--green" style={{ alignSelf: 'flex-start' }}>{t('dash.pickup.scannerActive', undefined, locale)}</p> : camera === 'unavailable' ? <p className="db-badge db-badge--amber"><Icon name="warning" size={14} /> {t('dash.pickup.scannerPending', undefined, locale)}</p> : null}
              <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                {camera === 'on' ? <button type="button" className="db-btn db-btn--outline" onClick={stopCamera}>{t('dash.pickup.scannerStop', undefined, locale)}</button>
                  : <button type="button" className="db-btn db-btn--primary" onClick={() => { void startCamera() }} disabled={!can || camera === 'starting'} data-testid="pickup-camera-start">{t('dash.pickup.scannerStart', undefined, locale)}</button>}
                <button type="button" className="db-btn db-btn--outline" onClick={() => setMode('code')}>{t('dash.pickup.useCode', undefined, locale)}</button>
              </div>
              <form style={{ display: 'flex', flexDirection: 'column', gap: 8 }} onSubmit={submitPayload} data-testid="pickup-payload-form">
                <label className="db-field__label" htmlFor="pv-payload">{t('dash.pickup.payloadLabel', undefined, locale)}</label>
                <input id="pv-payload" ref={payloadRef} className="db-input" value={payload} onChange={(e) => setPayload(e.target.value)} autoComplete="off" spellCheck={false} maxLength={128} aria-describedby="pv-payload-hint" data-testid="pickup-payload" />
                <p id="pv-payload-hint" className="db-field__hint">{t('dash.pickup.payloadHint', undefined, locale)}</p>
                <button type="submit" className="db-btn db-btn--primary" disabled={busy || !payload.trim() || !can} data-testid="pickup-payload-submit"><Icon name="check" size={18} /> {busy ? t('dash.pickup.verifying', undefined, locale) : t('dash.pickup.verify', undefined, locale)}</button>
              </form>
            </div>
          ) : (
            <form style={{ marginTop: 16, display: 'flex', flexDirection: 'column', gap: 12 }} onSubmit={submitCode} data-testid="pickup-form">
              <label className="db-field__label" htmlFor="pv-order">{t('dash.pickup.orderNumberLabel', undefined, locale)}</label>
              <input id="pv-order" className="db-input" value={orderNumber} onChange={(e) => setOrderNumber(e.target.value.toUpperCase())} autoComplete="off" spellCheck={false} maxLength={24} placeholder="FOTG-20260101-ABC123" aria-describedby="pv-order-hint" data-testid="pickup-order" />
              <p id="pv-order-hint" className="db-field__hint">{t('dash.pickup.orderNumberHint', undefined, locale)}</p>
              <label className="db-field__label" htmlFor="pv-code">{t('dash.pickup.codeLabel', undefined, locale)}</label>
              <input id="pv-code" ref={inputRef} className="db-input db-code-input" value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} autoComplete="off" inputMode="text" maxLength={10} placeholder="ABC123" aria-describedby="pv-hint" data-testid="pickup-code" />
              <p id="pv-hint" className="db-field__hint">{t('dash.pickup.codeHint', undefined, locale)}</p>
              <button type="submit" className="db-btn db-btn--primary" disabled={busy || !code.trim() || (d.live && !orderNumber.trim()) || !can} data-testid="pickup-submit"><Icon name="check" size={18} /> {busy ? t('dash.pickup.verifying', undefined, locale) : t('dash.pickup.verify', undefined, locale)}</button>
              {!can && <p className="db-field__error">{t('dash.error.permissionText', { role: t(`dash.role.${d.staff.role}`, undefined, locale) }, locale)}</p>}
            </form>
          )}
        </Card>
        <Card title={t('dash.pickup.resultTitle', undefined, locale)}>
          {!result && <p className="db-muted">{t('dash.pickup.resultEmpty', undefined, locale)}</p>}
          {result && result.ok && (
            <div className="db-verify-result db-verify-result--ok" role="status" aria-live="polite" data-testid="pickup-result" data-result="ok" data-method={result.method ?? ''}>
              <Icon name="check" size={26} />
              <div><h3>{t('dash.pickup.success', undefined, locale)}</h3><p style={{ margin: 0 }}>{t('dash.pickup.successText', { n: result.order.orderNumber, customer: result.order.customerDisplayName ?? '' }, locale)}</p>
                <p style={{ margin: '4px 0 0', fontSize: '0.9rem' }}>{result.method && t(`dash.pickup.method.${result.method}`, undefined, locale)}{result.verifiedAt && ` · ${t('dash.pickup.verifiedAt', { time: formatLocalTime(result.verifiedAt, result.order.pickup.restaurantTimezone, locale) }, locale)}`}</p>
                <div className="db-status-strip" style={{ marginTop: 8 }}><StatusBadge status={result.order.orderStatus} locale={locale} /><Pill tone="muted">{t('dash.units.items', { n: result.order.items.reduce((a, i) => a + i.quantity, 0) }, locale)}</Pill><Pill tone="muted">{formatMoney(result.order.pricing.totalMinor, result.order.pricing.currency, locale)}</Pill></div>
                <ul className="db-list" style={{ marginTop: 8 }}>{result.order.items.map((i) => <li key={i.lineId} dir="auto">{i.quantity}× {i.itemName}</li>)}</ul>
                <Link to={`${BASE}/orders/${result.order.orderNumber}`} className="db-btn db-btn--outline db-btn--sm" style={{ marginTop: 8 }}>{t('dash.orders.view', undefined, locale)}</Link></div>
            </div>
          )}
          {result && !result.ok && (
            <div className="db-verify-result db-verify-result--bad" role="alert" data-testid="pickup-result" data-result={result.reason}>
              <Icon name="warning" size={26} />
              <div><h3>{t(`dash.pickup.fail.${result.reason}`, undefined, locale)}</h3><p style={{ margin: 0 }}>{result.reason === 'already_used' && result.detail?.verifiedByYou ? t('dash.pickup.verifiedByYou', undefined, locale) : t(`dash.pickup.failText.${result.reason}`, undefined, locale)}</p>
                {result.detail?.orderNumber && <p style={{ margin: '6px 0 0', fontSize: '0.9rem' }}>{t('dash.pickup.failOrder', { n: result.detail.orderNumber, status: result.detail.status ?? '' }, locale)}</p>}
                {result.reason === 'wrong_location' && result.detail?.orderLocationName && <p style={{ margin: '6px 0 0', fontSize: '0.9rem' }}>{t('dash.pickup.failLocation', { location: result.detail.orderLocationName }, locale)}</p>}
                {result.reason === 'rate_limited' && result.detail?.retryAfterSeconds != null && <p style={{ margin: '6px 0 0', fontSize: '0.9rem' }}>{t('dash.pickup.retryAfter', { s: result.detail.retryAfterSeconds }, locale)}</p>}
                {last && <p style={{ margin: '6px 0 0', fontSize: '0.8rem' }}>{t('dash.pickup.lastCode', { code: last }, locale)}</p>}</div>
            </div>
          )}
          <p className="db-muted" style={{ marginTop: 14, fontSize: '0.8rem' }}>{t(d.live ? 'dash.pickup.backendNoteLive' : 'dash.pickup.backendNote', undefined, locale)}</p>
        </Card>
      </div>
    </div>
  )
}
