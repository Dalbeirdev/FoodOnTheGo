/**
 * Add / edit forms for states / regions, route corridors and market configuration in the Market Control Center
 * (backend mode only). Same rules as MarketGeoForms: the backend authorises, validates and audits; these forms
 * collect input and show the backend's refusal. New records start "Planned".
 *
 * A corridor centreline is either straight lines through the chosen cities (a stand-in until road geometry is
 * available) or a pasted GeoJSON LineString. Configuration edits always need a reason; provider keys are never
 * entered here, and a payment method that is locked for the market cannot be enabled.
 */
import { useMemo, useState } from 'react'
import { t } from '../../i18n/strings'
import type { City, MarketConfiguration, MarketRegion, RouteCorridor } from '../../market/types'
import { Drawer, Field } from '../../dashboard/components/ui'
import type { GeoJsonLine, MarketConfigurationInput, PaymentMethodStatus, RegionInput, RouteInput } from '../types'
import { failure, num } from './MarketGeoForms'

/* ------------------------------------------------------------------ state / region */
const REGION_TYPES: RegionInput['type'][] = ['STATE', 'UNION_TERRITORY', 'PROVINCE', 'REGION']
const typeOf = (r: MarketRegion): RegionInput['type'] => r.type ?? (r.kind === 'state' ? 'STATE' : r.kind === 'union_territory' ? 'UNION_TERRITORY' : 'REGION')
export function RegionFormDrawer({ open, region, locale, onClose, onSave }: { open: boolean; region: MarketRegion | null; locale: string; onClose: () => void; onSave: (input: RegionInput, reason: string) => Promise<void> }) {
  return open ? <RegionForm key={region?.id ?? 'new'} region={region} locale={locale} onClose={onClose} onSave={onSave} /> : null
}
function RegionForm({ region, locale, onClose, onSave }: { region: MarketRegion | null; locale: string; onClose: () => void; onSave: (input: RegionInput, reason: string) => Promise<void> }) {
  const [f, setF] = useState({ code: region?.code ?? '', name: region?.name ?? '', type: region ? typeOf(region) : ('STATE' as RegionInput['type']) })
  const [errors, setErrors] = useState<Record<string, string>>({}); const [message, setMessage] = useState(''); const [busy, setBusy] = useState(false)
  const submit = async () => {
    const er: Record<string, string> = {}; const code = f.code.trim().toUpperCase()
    if (!region && !/^[A-Z0-9-]{2,12}$/.test(code)) er.code = t('adm.geo.err.code', undefined, locale)
    if (f.name.trim().length < 2) er.name = t('adm.geo.err.name', undefined, locale)
    setErrors(er); setMessage(''); if (Object.keys(er).length) return
    setBusy(true)
    try { await onSave({ code, name: f.name.trim(), type: f.type }, '') } catch (e) { const x = failure(e, locale); setErrors(x.fields); setMessage(x.message) } finally { setBusy(false) }
  }
  return (
    <Drawer open onClose={onClose} title={region ? t('adm.geo.editRegion', { name: region.name }, locale) : t('adm.geo.addRegion', undefined, locale)}
      footer={<><button type="button" className="db-btn db-btn--ghost" onClick={onClose} disabled={busy}>{t('dash.action.cancel', undefined, locale)}</button><button type="button" className="db-btn db-btn--primary" onClick={() => { void submit() }} disabled={busy} data-testid="region-save">{busy ? t('dash.action.saving', undefined, locale) : region ? t('adm.geo.saveChanges', undefined, locale) : t('adm.geo.createRegion', undefined, locale)}</button></>}>
      <form className="db-grid" style={{ gap: 14 }} onSubmit={(e) => { e.preventDefault(); void submit() }} data-testid="region-form" noValidate>
        {!region && <p className="adm-note">{t('adm.geo.newIsPlanned', undefined, locale)}</p>}
        <Field label={t('adm.geo.f.regionCode', undefined, locale)} required={!region} error={errors.code} hint={region ? t('adm.geo.codeFixed', undefined, locale) : t('adm.geo.f.regionCodeHint', undefined, locale)} id="region-code"><input id="region-code" className="db-input" value={f.code} onChange={(e) => setF((p) => ({ ...p, code: e.target.value }))} readOnly={!!region} maxLength={12} autoComplete="off" /></Field>
        <Field label={t('adm.geo.f.regionName', undefined, locale)} required error={errors.name} id="region-name"><input id="region-name" className="db-input" value={f.name} onChange={(e) => setF((p) => ({ ...p, name: e.target.value }))} maxLength={120} autoComplete="off" /></Field>
        <Field label={t('adm.geo.f.regionType', undefined, locale)} error={errors.type} id="region-type"><select id="region-type" className="db-select" value={f.type} onChange={(e) => setF((p) => ({ ...p, type: e.target.value as RegionInput['type'] }))}>{REGION_TYPES.map((x) => <option key={x} value={x}>{t(`adm.geo.type.${x}`, undefined, locale)}</option>)}</select></Field>
        {message && <p className="db-field__error" role="alert" data-testid="geo-form-error">{message}</p>}
        <button type="submit" hidden />
      </form>
    </Drawer>
  )
}

/* ------------------------------------------------------------------ route corridor */
/** Pasted text → LineString. Accepts a bare geometry, a Feature, or a FeatureCollection with one feature. */
export function parseGeoJsonLine(text: string): GeoJsonLine | null {
  try {
    let g = JSON.parse(text) as { type?: string; geometry?: unknown; features?: Array<{ geometry?: unknown }>; coordinates?: unknown }
    if (g?.type === 'FeatureCollection' && g.features?.length === 1) g = g.features[0] as typeof g
    if (g?.type === 'Feature') g = g.geometry as typeof g
    if (g?.type === 'LineString' && Array.isArray(g.coordinates) && g.coordinates.length >= 2) return { type: 'LineString', coordinates: g.coordinates as number[][] }
  } catch { /* not JSON */ }
  return null
}
const flatDist = (a: City, b: City) => Math.hypot(a.lat - b.lat, (a.lng - b.lng) * Math.cos((a.lat * Math.PI) / 180))
/** Origin → via cities (ordered by their distance from the origin) → destination, through the city centres. */
export function lineThroughCities(origin: City, via: City[], destination: City): { line: GeoJsonLine; viaIds: string[] } {
  const ordered = [...via].sort((a, b) => flatDist(origin, a) - flatDist(origin, b))
  return { line: { type: 'LineString', coordinates: [origin, ...ordered, destination].map((c) => [c.lng, c.lat]) }, viaIds: ordered.map((c) => c.id) }
}
type LineMode = 'cities' | 'geojson'
export function RouteFormDrawer({ open, route, cities, locale, onClose, onSave }: { open: boolean; route: RouteCorridor | null; cities: City[]; locale: string; onClose: () => void; onSave: (input: RouteInput, reason: string) => Promise<void> }) {
  return open ? <RouteForm key={route?.id ?? 'new'} route={route} cities={cities} locale={locale} onClose={onClose} onSave={onSave} /> : null
}
function RouteForm({ route, cities, locale, onClose, onSave }: { route: RouteCorridor | null; cities: City[]; locale: string; onClose: () => void; onSave: (input: RouteInput, reason: string) => Promise<void> }) {
  const [f, setF] = useState({ name: route?.name ?? '', highway: route?.highway ?? '', origin: route?.originCityId ?? '', destination: route?.destinationCityId ?? '', width: String(route?.corridorWidthM ?? 5000), geojson: '' })
  const [via, setVia] = useState<string[]>(route?.viaCityIds ?? []); const [replace, setReplace] = useState(!route); const [mode, setMode] = useState<LineMode>(route ? 'geojson' : 'cities')
  const [errors, setErrors] = useState<Record<string, string>>({}); const [message, setMessage] = useState(''); const [busy, setBusy] = useState(false)
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((p) => ({ ...p, [k]: e.target.value }))
  const sorted = useMemo(() => [...cities].sort((a, b) => a.name.localeCompare(b.name)), [cities]); const byId = (id: string) => cities.find((c) => c.id === id) ?? null
  const origin = byId(f.origin), destination = byId(f.destination)
  const through = origin && destination && origin.id !== destination.id ? lineThroughCities(origin, via.map(byId).filter((c): c is City => !!c && c.id !== origin.id && c.id !== destination.id), destination) : null
  const centreline: GeoJsonLine | null = !replace ? null : mode === 'geojson' ? parseGeoJsonLine(f.geojson) : through?.line ?? null
  const submit = async () => {
    const er: Record<string, string> = {}; const width = num(f.width)
    if (f.name.trim().length < 2) er.name = t('adm.geo.err.name', undefined, locale)
    if (!route && (!origin || !destination)) er.origin_city_id = t('adm.geo.err.origin', undefined, locale)
    else if (!route && origin!.id === destination!.id) er.origin_city_id = t('adm.geo.err.sameCity', undefined, locale)
    if (width === null || !Number.isInteger(width) || width < 100 || width > 100000) er.corridor_width_meters = t('adm.geo.err.width', undefined, locale)
    if (replace && !centreline && !er.origin_city_id) er.geometry = t(mode === 'geojson' ? 'adm.geo.err.lineGeojson' : 'adm.geo.err.line', undefined, locale)
    setErrors(er); setMessage(''); if (Object.keys(er).length) return
    setBusy(true)
    try { await onSave({ name: f.name.trim(), highway: f.highway.trim() || null, originCityId: f.origin, destinationCityId: f.destination, viaCityIds: through?.viaIds ?? via, corridorWidthM: width!, centreline }, '') } catch (e) { const x = failure(e, locale); setErrors(x.fields); setMessage(x.message) } finally { setBusy(false) }
  }
  const cityField = (k: 'origin' | 'destination', label: string, id: string) => route
    ? <Field label={label} id={id}><input id={id} className="db-input" value={byId(f[k])?.name ?? '—'} readOnly /></Field>
    : <Field label={label} required id={id}><select id={id} className="db-select" value={f[k]} onChange={set(k)}><option value="">{t('adm.geo.choose', undefined, locale)}</option>{sorted.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
  return (
    <Drawer open wide onClose={onClose} title={route ? t('adm.geo.editRegion', { name: route.name }, locale) : t('adm.geo.addRoute', undefined, locale)}
      footer={<><button type="button" className="db-btn db-btn--ghost" onClick={onClose} disabled={busy}>{t('dash.action.cancel', undefined, locale)}</button><button type="button" className="db-btn db-btn--primary" onClick={() => { void submit() }} disabled={busy} data-testid="route-save">{busy ? t('dash.action.saving', undefined, locale) : route ? t('adm.geo.saveChanges', undefined, locale) : t('adm.geo.createRoute', undefined, locale)}</button></>}>
      <form className="db-grid" style={{ gap: 14 }} onSubmit={(e) => { e.preventDefault(); void submit() }} data-testid="route-form" noValidate>
        {!route && <p className="adm-note">{t('adm.geo.newIsPlanned', undefined, locale)}</p>}
        <Field label={t('adm.geo.f.routeName', undefined, locale)} required error={errors.name} id="route-name"><input id="route-name" className="db-input" value={f.name} onChange={set('name')} maxLength={160} autoComplete="off" /></Field>
        <div className="adm-geo-pair">{cityField('origin', t('adm.geo.f.origin', undefined, locale), 'route-origin')}{cityField('destination', t('adm.geo.f.destination', undefined, locale), 'route-destination')}</div>
        {errors.origin_city_id && <p className="db-field__error" role="alert">{errors.origin_city_id}</p>}
        {route ? <p className="db-field__hint">{t('adm.geo.citiesFixed', undefined, locale)}</p>
          : <fieldset className="adm-geo-boundary"><legend>{t('adm.geo.f.via', undefined, locale)}</legend>
            <div className="adm-geo-via">{sorted.filter((c) => c.id !== f.origin && c.id !== f.destination).map((c) => <label key={c.id} className={`db-chip ${via.includes(c.id) ? 'is-on' : ''}`}><input type="checkbox" checked={via.includes(c.id)} onChange={(e) => setVia((v) => (e.target.checked ? [...v, c.id] : v.filter((x) => x !== c.id)))} data-testid={`via-${c.id}`} /> {c.name}</label>)}</div>
            <p className="db-field__hint">{t('adm.geo.f.viaHint', undefined, locale)}</p></fieldset>}
        <div className="adm-geo-pair">
          <Field label={t('adm.geo.f.highway', undefined, locale)} hint={t('adm.geo.f.highwayHint', undefined, locale)} error={errors.highway} id="route-highway"><input id="route-highway" className="db-input" value={f.highway} onChange={set('highway')} maxLength={80} autoComplete="off" /></Field>
          <Field label={t('adm.geo.f.width', undefined, locale)} required hint={t('adm.geo.f.widthHint', undefined, locale)} error={errors.corridor_width_meters} id="route-width"><input id="route-width" className="db-input" inputMode="numeric" value={f.width} onChange={set('width')} /></Field>
        </div>
        <fieldset className="adm-geo-boundary">
          <legend>{t('adm.geo.centreline', undefined, locale)}</legend>
          {route && <label className="adm-geo-check"><input type="checkbox" checked={replace} onChange={(e) => setReplace(e.target.checked)} data-testid="route-replace" /> {t('adm.geo.replaceCentreline', undefined, locale)}</label>}
          {replace && <>
            {!route && <div className="adm-geo-modes" role="radiogroup" aria-label={t('adm.geo.centreline', undefined, locale)}>{(['cities', 'geojson'] as LineMode[]).map((m) => <label key={m} className={`db-chip ${mode === m ? 'is-on' : ''}`}><input type="radio" name="line-mode" checked={mode === m} onChange={() => setMode(m)} data-testid={`line-${m}`} /> {t(`adm.geo.line.${m}`, undefined, locale)}</label>)}</div>}
            {mode === 'geojson' && <Field label={t('adm.geo.f.lineGeojson', undefined, locale)} required hint={t('adm.geo.f.lineGeojsonHint', undefined, locale)} id="route-geojson"><textarea id="route-geojson" className="db-textarea adm-geo-json" rows={6} value={f.geojson} onChange={set('geojson')} spellCheck={false} placeholder='{"type":"LineString","coordinates":[[77.209,28.6139],[76.9905,29.6857]]}' /></Field>}
            {errors.geometry && <p className="db-field__error" role="alert">{errors.geometry}</p>}
            {centreline && <p className="db-field__hint" data-testid="line-preview">{t('adm.geo.linePreview', { points: centreline.coordinates.length }, locale)}{mode === 'cities' && through ? ` · ${[origin!, ...through.viaIds.map((id) => byId(id)!), destination!].map((c) => c.name).join(' → ')}` : ''}</p>}
          </>}
          <p className="db-field__hint">{t('adm.geo.centrelineNote', undefined, locale)}</p>
        </fieldset>
        {message && <p className="db-field__error" role="alert" data-testid="geo-form-error">{message}</p>}
        <button type="submit" hidden />
      </form>
    </Drawer>
  )
}

/* ------------------------------------------------------------------ market configuration */
const PAYMENT_STATUSES: PaymentMethodStatus[] = ['PLANNED', 'ENABLED', 'NOT_APPROVED']
export function ConfigurationFormDrawer({ open, configuration, locale, onClose, onSave }: { open: boolean; configuration: MarketConfiguration; locale: string; onClose: () => void; onSave: (input: MarketConfigurationInput, reason: string) => Promise<void> }) {
  return open ? <ConfigurationForm configuration={configuration} locale={locale} onClose={onClose} onSave={onSave} /> : null
}
function ConfigurationForm({ configuration: c, locale, onClose, onSave }: { configuration: MarketConfiguration; locale: string; onClose: () => void; onSave: (input: MarketConfigurationInput, reason: string) => Promise<void> }) {
  const locked = c.lockedFeatures ?? []
  const [methods, setMethods] = useState<Record<string, PaymentMethodStatus>>(() => Object.fromEntries(c.payment.methods.map((m) => [m.method, m.status as PaymentMethodStatus])))
  const [f, setF] = useState({ taxRegime: c.tax.regime, taxStatus: c.tax.state || 'PENDING', postalCodeLabel: c.address.postalCodeLabel, postalCodePattern: c.address.postalCodePattern ?? '', adminAreaLabel: c.address.adminAreaLabel, reason: '' })
  const [errors, setErrors] = useState<Record<string, string>>({}); const [message, setMessage] = useState(''); const [busy, setBusy] = useState(false)
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((p) => ({ ...p, [k]: e.target.value }))
  const submit = async () => {
    const er: Record<string, string> = {}
    if (!f.postalCodeLabel.trim()) er.postalCodeLabel = t('adm.geo.err.label', undefined, locale)
    if (!f.adminAreaLabel.trim()) er.adminAreaLabel = t('adm.geo.err.label', undefined, locale)
    if (f.postalCodePattern.trim()) { try { new RegExp(f.postalCodePattern) } catch { er.postalCodePattern = t('adm.geo.err.pattern', undefined, locale) } }
    if (f.reason.trim().length < 3) er.reason = t('adm.geo.err.reason', undefined, locale)
    setErrors(er); setMessage(''); if (Object.keys(er).length) return
    setBusy(true)
    try { await onSave({ paymentMethods: methods, taxRegime: f.taxRegime.trim(), taxStatus: f.taxStatus, postalCodeLabel: f.postalCodeLabel.trim(), postalCodePattern: f.postalCodePattern.trim(), adminAreaLabel: f.adminAreaLabel.trim() }, f.reason.trim()) } catch (e) { const x = failure(e, locale); setErrors(x.fields); setMessage(x.message) } finally { setBusy(false) }
  }
  return (
    <Drawer open wide onClose={onClose} title={t('adm.geo.configTitle', undefined, locale)}
      footer={<><button type="button" className="db-btn db-btn--ghost" onClick={onClose} disabled={busy}>{t('dash.action.cancel', undefined, locale)}</button><button type="button" className="db-btn db-btn--primary" onClick={() => { void submit() }} disabled={busy} data-testid="config-save">{busy ? t('dash.action.saving', undefined, locale) : t('adm.geo.saveChanges', undefined, locale)}</button></>}>
      <form className="db-grid" style={{ gap: 14 }} onSubmit={(e) => { e.preventDefault(); void submit() }} data-testid="config-form" noValidate>
        <fieldset className="adm-geo-boundary"><legend>{t('adm.geo.cfg.payments', undefined, locale)}</legend>
          <div className="adm-geo-methods">{Object.keys(methods).map((m) => { const isLocked = locked.includes(m); return (
            <div key={m} className="adm-geo-method"><label htmlFor={`pay-${m}`}>{t(`adm.mkt.pay.${m}`, undefined, locale)}{isLocked && <small className="db-muted"> — {t('adm.geo.cfg.locked', undefined, locale)}</small>}</label>
              <select id={`pay-${m}`} className="db-select" value={methods[m]} disabled={isLocked} onChange={(e) => setMethods((p) => ({ ...p, [m]: e.target.value as PaymentMethodStatus }))} data-testid={`pay-${m}`}>{PAYMENT_STATUSES.map((s) => <option key={s} value={s}>{t(`adm.geo.cfg.status.${s}`, undefined, locale)}</option>)}</select></div>) })}</div>
          <p className="db-field__hint">{t('adm.geo.cfg.paymentsHint', undefined, locale)}</p>
        </fieldset>
        <fieldset className="adm-geo-boundary"><legend>{t('adm.geo.cfg.tax', undefined, locale)}</legend>
          <div className="adm-geo-pair">
            <Field label={t('adm.geo.cfg.taxRegime', undefined, locale)} id="cfg-tax-regime"><input id="cfg-tax-regime" className="db-input" value={f.taxRegime} onChange={set('taxRegime')} maxLength={60} autoComplete="off" /></Field>
            <Field label={t('adm.geo.cfg.taxStatus', undefined, locale)} id="cfg-tax-status"><select id="cfg-tax-status" className="db-select" value={f.taxStatus} onChange={set('taxStatus')}>{['PENDING', 'CONFIGURED'].map((s) => <option key={s} value={s}>{t(`adm.geo.cfg.tax.${s}`, undefined, locale)}</option>)}</select></Field>
          </div>
          <p className="db-field__hint">{t('adm.geo.cfg.taxHint', undefined, locale)}</p>
        </fieldset>
        <fieldset className="adm-geo-boundary"><legend>{t('adm.geo.cfg.address', undefined, locale)}</legend>
          <div className="adm-geo-pair">
            <Field label={t('adm.geo.cfg.postalLabel', undefined, locale)} required error={errors.postalCodeLabel} id="cfg-postal-label"><input id="cfg-postal-label" className="db-input" value={f.postalCodeLabel} onChange={set('postalCodeLabel')} maxLength={40} autoComplete="off" /></Field>
            <Field label={t('adm.geo.cfg.adminArea', undefined, locale)} required error={errors.adminAreaLabel} id="cfg-admin-area"><input id="cfg-admin-area" className="db-input" value={f.adminAreaLabel} onChange={set('adminAreaLabel')} maxLength={40} autoComplete="off" /></Field>
          </div>
          <Field label={t('adm.geo.cfg.postalPattern', undefined, locale)} hint={t('adm.geo.cfg.postalPatternHint', undefined, locale)} error={errors.postalCodePattern} id="cfg-postal-pattern"><input id="cfg-postal-pattern" className="db-input adm-geo-json" value={f.postalCodePattern} onChange={set('postalCodePattern')} maxLength={120} autoComplete="off" spellCheck={false} /></Field>
        </fieldset>
        <Field label={t('adm.geo.cfg.reason', undefined, locale)} required hint={t('adm.geo.cfg.reasonHint', undefined, locale)} error={errors.reason} id="cfg-reason"><textarea id="cfg-reason" className="db-textarea" rows={2} value={f.reason} onChange={set('reason')} maxLength={500} data-testid="config-reason" /></Field>
        {message && <p className="db-field__error" role="alert" data-testid="geo-form-error">{message}</p>}
        <button type="submit" hidden />
      </form>
    </Drawer>
  )
}
