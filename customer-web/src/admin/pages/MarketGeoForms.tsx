/**
 * Add / edit forms for cities and service areas in the Market Control Center (backend mode only).
 *
 * The backend decides everything that matters: permission and market scope, that the coordinates lie inside the
 * market, that the boundary is a valid polygon, the version the administrator edited. These forms collect the input,
 * show the backend's refusal next to the field it concerns, and never store anything themselves.
 *
 * A boundary is entered in one of two ways, because there is no map provider yet to draw on:
 *   - a circle (centre + radius), turned into a 32-point polygon here; or
 *   - pasted GeoJSON (Polygon / MultiPolygon, or a Feature that contains one) exported from a GIS tool.
 * New cities and service areas always start as "Planned": nothing goes live by being created.
 */
import { useMemo, useState } from 'react'
import { ApiError } from '../../api/client'
import { t } from '../../i18n/strings'
import type { City, MarketRegion, ServiceArea } from '../../market/types'
import { Drawer, Field } from '../../dashboard/components/ui'
import type { CityInput, GeoJsonArea, ServiceAreaInput } from '../types'

export const num = (v: string) => (v.trim() === '' || Number.isNaN(Number(v)) ? null : Number(v))
/** Backend refusal → message for the form: field errors of a 422 by field, anything else as one line. */
export function failure(e: unknown, locale: string): { fields: Record<string, string>; message: string } {
  if (e instanceof ApiError) return { fields: Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])), message: Object.keys(e.errors).length ? t('adm.geo.checkFields', undefined, locale) : e.message }
  return { fields: {}, message: t('adm.error.saveFailed', undefined, locale) }
}

/** A circle on the earth as a closed 32-point GeoJSON ring ([longitude, latitude]); good to a few metres at city scale. */
export function circlePolygon(lat: number, lng: number, radiusKm: number, points = 32): GeoJsonArea {
  const R = 6371.0088, d = radiusKm / R, lat1 = (lat * Math.PI) / 180, lng1 = (lng * Math.PI) / 180
  const ring = Array.from({ length: points }, (_, i) => {
    const b = (2 * Math.PI * i) / points
    const lat2 = Math.asin(Math.sin(lat1) * Math.cos(d) + Math.cos(lat1) * Math.sin(d) * Math.cos(b))
    const lng2 = lng1 + Math.atan2(Math.sin(b) * Math.sin(d) * Math.cos(lat1), Math.cos(d) - Math.sin(lat1) * Math.sin(lat2))
    return [Number(((lng2 * 180) / Math.PI).toFixed(6)), Number(((lat2 * 180) / Math.PI).toFixed(6))]
  })
  return { type: 'Polygon', coordinates: [[...ring, ring[0]]] }
}

/** Pasted text → Polygon / MultiPolygon. Accepts a bare geometry, a Feature, or a FeatureCollection with one feature. */
export function parseGeoJsonArea(text: string): GeoJsonArea | null {
  try {
    let g = JSON.parse(text) as { type?: string; geometry?: unknown; features?: Array<{ geometry?: unknown }>; coordinates?: unknown }
    if (g?.type === 'FeatureCollection' && g.features?.length === 1) g = g.features[0] as typeof g
    if (g?.type === 'Feature' || (g && !g.type && g.geometry)) g = g.geometry as typeof g
    if ((g?.type === 'Polygon' || g?.type === 'MultiPolygon') && Array.isArray(g.coordinates)) return { type: g.type, coordinates: g.coordinates } as GeoJsonArea
  } catch { /* not JSON */ }
  return null
}

const rings = (g: GeoJsonArea): number[][][] => (g.type === 'Polygon' ? (g.coordinates as number[][][]) : (g.coordinates as number[][][][]).flat())
/** Small outline preview of the boundary (shape only — not a map). */
function BoundaryPreview({ geometry, locale }: { geometry: GeoJsonArea; locale: string }) {
  const all = rings(geometry).flat().filter((p) => Array.isArray(p) && typeof p[0] === 'number' && typeof p[1] === 'number')
  if (all.length < 3) return null
  const xs = all.map((p) => p[0]), ys = all.map((p) => p[1]); const w = Math.min(...xs), e = Math.max(...xs), s = Math.min(...ys), n = Math.max(...ys)
  const k = Math.cos((((s + n) / 2) * Math.PI) / 180); const scale = 140 / Math.max((e - w) * k, n - s, 1e-9)
  const x = (lng: number) => 10 + (lng - w) * k * scale, y = (lat: number) => 150 - (lat - s) * scale
  return (
    <figure className="adm-geo-preview" data-testid="boundary-preview">
      <svg viewBox="0 0 160 160" role="img" aria-label={t('adm.geo.previewAria', { points: all.length }, locale)}><path d={rings(geometry).map((r) => `M${r.map((p) => `${x(p[0]).toFixed(1)},${y(p[1]).toFixed(1)}`).join('L')}Z`).join(' ')} fillRule="evenodd" className="adm-map__area adm-map__area--planned" /></svg>
      <figcaption className="db-field__hint">{t('adm.geo.previewCaption', { points: all.length, w: w.toFixed(4), s: s.toFixed(4), e: e.toFixed(4), n: n.toFixed(4) }, locale)}</figcaption>
    </figure>
  )
}

/* ------------------------------------------------------------------ city */
export function CityFormDrawer({ open, city, regions, defaultTimezone, locale, onClose, onSave }: {
  open: boolean; city: City | null; regions: MarketRegion[]; defaultTimezone: string; locale: string; onClose: () => void
  onSave: (input: CityInput, reason: string) => Promise<void>
}) {
  return open ? <CityForm key={city?.id ?? 'new'} city={city} regions={regions} defaultTimezone={defaultTimezone} locale={locale} onClose={onClose} onSave={onSave} /> : null
}
function CityForm({ city, regions, defaultTimezone, locale, onClose, onSave }: { city: City | null; regions: MarketRegion[]; defaultTimezone: string; locale: string; onClose: () => void; onSave: (input: CityInput, reason: string) => Promise<void> }) {
  const [f, setF] = useState({ name: city?.name ?? '', regionId: city?.regionId ?? '', lat: city ? String(city.lat) : '', lng: city ? String(city.lng) : '', timezone: city?.timezone ?? defaultTimezone, aliases: (city?.aliases ?? []).join(', '), launchStage: city?.launchStage ?? '' })
  const [errors, setErrors] = useState<Record<string, string>>({}); const [message, setMessage] = useState(''); const [busy, setBusy] = useState(false)
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((p) => ({ ...p, [k]: e.target.value }))
  const submit = async () => {
    const lat = num(f.lat), lng = num(f.lng); const er: Record<string, string> = {}
    if (f.name.trim().length < 2) er.name = t('adm.geo.err.name', undefined, locale)
    if (!city && !f.regionId) er.region_id = t('adm.geo.err.region', undefined, locale)
    if (lat === null || lat < -90 || lat > 90) er.latitude = t('adm.geo.err.lat', undefined, locale)
    if (lng === null || lng < -180 || lng > 180) er.longitude = t('adm.geo.err.lng', undefined, locale)
    if (!f.timezone.trim()) er.timezone = t('adm.geo.err.timezone', undefined, locale)
    setErrors(er); setMessage(''); if (Object.keys(er).length) return
    setBusy(true)
    try { await onSave({ regionId: f.regionId, name: f.name.trim(), lat: lat!, lng: lng!, timezone: f.timezone.trim(), aliases: f.aliases.split(',').map((a) => a.trim()).filter(Boolean), launchStage: f.launchStage.trim() || null }, '') } catch (e) { const x = failure(e, locale); setErrors(x.fields); setMessage(x.message) } finally { setBusy(false) }
  }
  return (
    <Drawer open onClose={onClose} title={city ? t('adm.geo.editCity', { name: city.name }, locale) : t('adm.geo.addCity', undefined, locale)}
      footer={<><button type="button" className="db-btn db-btn--ghost" onClick={onClose} disabled={busy}>{t('dash.action.cancel', undefined, locale)}</button><button type="button" className="db-btn db-btn--primary" onClick={() => { void submit() }} disabled={busy} data-testid="city-save">{busy ? t('dash.action.saving', undefined, locale) : city ? t('adm.geo.saveChanges', undefined, locale) : t('adm.geo.createCity', undefined, locale)}</button></>}>
      <form className="db-grid" style={{ gap: 14 }} onSubmit={(e) => { e.preventDefault(); void submit() }} data-testid="city-form" noValidate>
        {!city && <p className="adm-note">{t('adm.geo.newIsPlanned', undefined, locale)}</p>}
        <Field label={t('adm.geo.f.cityName', undefined, locale)} required error={errors.name} id="city-name"><input id="city-name" className="db-input" value={f.name} onChange={set('name')} maxLength={120} autoComplete="off" /></Field>
        {city
          ? <Field label={t('adm.mkt.col.state', undefined, locale)} hint={t('adm.geo.regionFixed', undefined, locale)} id="city-region"><input id="city-region" className="db-input" value={regions.find((r) => r.id === city.regionId)?.name ?? ''} readOnly /></Field>
          : <Field label={t('adm.mkt.col.state', undefined, locale)} required error={errors.region_id} id="city-region"><select id="city-region" className="db-select" value={f.regionId} onChange={set('regionId')}><option value="">{t('adm.geo.choose', undefined, locale)}</option>{[...regions].sort((a, b) => a.name.localeCompare(b.name)).map((r) => <option key={r.id} value={r.id}>{r.name} ({r.code})</option>)}</select></Field>}
        <div className="adm-geo-pair">
          <Field label={t('adm.geo.f.lat', undefined, locale)} required error={errors.latitude} hint={t('adm.geo.f.latHint', undefined, locale)} id="city-lat"><input id="city-lat" className="db-input" inputMode="decimal" value={f.lat} onChange={set('lat')} placeholder="28.5355" /></Field>
          <Field label={t('adm.geo.f.lng', undefined, locale)} required error={errors.longitude} hint={t('adm.geo.f.lngHint', undefined, locale)} id="city-lng"><input id="city-lng" className="db-input" inputMode="decimal" value={f.lng} onChange={set('lng')} placeholder="77.3910" /></Field>
        </div>
        <Field label={t('adm.geo.f.timezone', undefined, locale)} required error={errors.timezone} hint={t('adm.geo.f.timezoneHint', undefined, locale)} id="city-tz"><input id="city-tz" className="db-input" value={f.timezone} onChange={set('timezone')} autoComplete="off" /></Field>
        <Field label={t('adm.geo.f.aliases', undefined, locale)} hint={t('adm.geo.f.aliasesHint', undefined, locale)} error={errors.aliases} id="city-aliases"><input id="city-aliases" className="db-input" value={f.aliases} onChange={set('aliases')} autoComplete="off" /></Field>
        <Field label={t('adm.geo.f.launchStage', undefined, locale)} hint={t('adm.geo.f.launchStageHint', undefined, locale)} error={errors.launch_stage} id="city-stage"><input id="city-stage" className="db-input" value={f.launchStage} onChange={set('launchStage')} maxLength={120} autoComplete="off" /></Field>
        {message && <p className="db-field__error" role="alert" data-testid="geo-form-error">{message}</p>}
        <button type="submit" hidden />
      </form>
    </Drawer>
  )
}

/* ------------------------------------------------------------------ service area */
type Mode = 'circle' | 'geojson'
export function ServiceAreaFormDrawer({ open, area, cities, locale, onClose, onSave }: {
  open: boolean; area: ServiceArea | null; cities: City[]; locale: string; onClose: () => void
  onSave: (input: ServiceAreaInput, reason: string) => Promise<void>
}) {
  return open ? <ServiceAreaForm key={area?.id ?? 'new'} area={area} cities={cities} locale={locale} onClose={onClose} onSave={onSave} /> : null
}
function ServiceAreaForm({ area, cities, locale, onClose, onSave }: { area: ServiceArea | null; cities: City[]; locale: string; onClose: () => void; onSave: (input: ServiceAreaInput, reason: string) => Promise<void> }) {
  const [f, setF] = useState({ name: area?.name ?? '', cityId: area?.cityId ?? '', priority: String(area?.priority ?? 0), launchStage: area?.launchStage ?? '', lat: '', lng: '', radiusKm: '', geojson: '' })
  const [replace, setReplace] = useState(!area); const [mode, setMode] = useState<Mode>('circle')
  const [errors, setErrors] = useState<Record<string, string>>({}); const [message, setMessage] = useState(''); const [busy, setBusy] = useState(false)
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((p) => ({ ...p, [k]: e.target.value }))
  const city = cities.find((c) => c.id === f.cityId) ?? null
  const geometry = useMemo<GeoJsonArea | null>(() => {
    if (!replace) return null
    if (mode === 'geojson') return parseGeoJsonArea(f.geojson)
    const lat = num(f.lat), lng = num(f.lng), r = num(f.radiusKm)
    return lat !== null && lng !== null && r !== null && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180 && r >= 0.2 && r <= 100 ? circlePolygon(lat, lng, r) : null
  }, [replace, mode, f.geojson, f.lat, f.lng, f.radiusKm])
  const useCityCentre = () => { if (city) setF((p) => ({ ...p, lat: String(city.lat), lng: String(city.lng) })) }
  const submit = async () => {
    const er: Record<string, string> = {}; const priority = num(f.priority)
    if (f.name.trim().length < 2) er.name = t('adm.geo.err.name', undefined, locale)
    if (!area && !f.cityId) er.city_id = t('adm.geo.err.city', undefined, locale)
    if (priority === null || !Number.isInteger(priority) || priority < -1000 || priority > 1000) er.priority = t('adm.geo.err.priority', undefined, locale)
    if (replace && !geometry) er.geometry = t(mode === 'circle' ? 'adm.geo.err.circle' : 'adm.geo.err.geojson', undefined, locale)
    setErrors(er); setMessage(''); if (Object.keys(er).length) return
    setBusy(true)
    try { await onSave({ cityId: f.cityId, name: f.name.trim(), priority: priority!, launchStage: f.launchStage.trim() || null, geometry }, '') } catch (e) { const x = failure(e, locale); setErrors(x.fields); setMessage(x.message) } finally { setBusy(false) }
  }
  return (
    <Drawer open wide onClose={onClose} title={area ? t('adm.geo.editArea', { name: area.name }, locale) : t('adm.geo.addArea', undefined, locale)}
      footer={<><button type="button" className="db-btn db-btn--ghost" onClick={onClose} disabled={busy}>{t('dash.action.cancel', undefined, locale)}</button><button type="button" className="db-btn db-btn--primary" onClick={() => { void submit() }} disabled={busy} data-testid="area-save">{busy ? t('dash.action.saving', undefined, locale) : area ? t('adm.geo.saveChanges', undefined, locale) : t('adm.geo.createArea', undefined, locale)}</button></>}>
      <form className="db-grid" style={{ gap: 14 }} onSubmit={(e) => { e.preventDefault(); void submit() }} data-testid="area-form" noValidate>
        {!area && <p className="adm-note">{t('adm.geo.newIsPlanned', undefined, locale)}</p>}
        <Field label={t('adm.geo.f.areaName', undefined, locale)} required error={errors.name} id="area-name"><input id="area-name" className="db-input" value={f.name} onChange={set('name')} maxLength={160} autoComplete="off" /></Field>
        {area
          ? <Field label={t('adm.mkt.col.city', undefined, locale)} hint={t('adm.geo.cityFixed', undefined, locale)} id="area-city"><input id="area-city" className="db-input" value={city?.name ?? ''} readOnly /></Field>
          : <Field label={t('adm.mkt.col.city', undefined, locale)} required error={errors.city_id} id="area-city"><select id="area-city" className="db-select" value={f.cityId} onChange={set('cityId')}><option value="">{t('adm.geo.choose', undefined, locale)}</option>{[...cities].sort((a, b) => a.name.localeCompare(b.name)).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>}
        <div className="adm-geo-pair">
          <Field label={t('adm.geo.f.priority', undefined, locale)} hint={t('adm.geo.f.priorityHint', undefined, locale)} error={errors.priority} id="area-priority"><input id="area-priority" className="db-input" inputMode="numeric" value={f.priority} onChange={set('priority')} /></Field>
          <Field label={t('adm.geo.f.launchStage', undefined, locale)} hint={t('adm.geo.f.launchStageHint', undefined, locale)} error={errors.launch_stage} id="area-stage"><input id="area-stage" className="db-input" value={f.launchStage} onChange={set('launchStage')} maxLength={120} autoComplete="off" /></Field>
        </div>

        <fieldset className="adm-geo-boundary">
          <legend>{t('adm.geo.boundary', undefined, locale)}</legend>
          {area && <label className="adm-geo-check"><input type="checkbox" checked={replace} onChange={(e) => setReplace(e.target.checked)} data-testid="area-replace" /> {t('adm.geo.replaceBoundary', undefined, locale)}</label>}
          {replace && <>
            <div className="adm-geo-modes" role="radiogroup" aria-label={t('adm.geo.boundaryMode', undefined, locale)}>
              {(['circle', 'geojson'] as Mode[]).map((m) => <label key={m} className={`db-chip ${mode === m ? 'is-on' : ''}`}><input type="radio" name="boundary-mode" checked={mode === m} onChange={() => setMode(m)} data-testid={`boundary-${m}`} /> {t(`adm.geo.mode.${m}`, undefined, locale)}</label>)}
            </div>
            {mode === 'circle'
              ? <>
                <div className="adm-geo-pair">
                  <Field label={t('adm.geo.f.centreLat', undefined, locale)} required id="area-lat"><input id="area-lat" className="db-input" inputMode="decimal" value={f.lat} onChange={set('lat')} /></Field>
                  <Field label={t('adm.geo.f.centreLng', undefined, locale)} required id="area-lng"><input id="area-lng" className="db-input" inputMode="decimal" value={f.lng} onChange={set('lng')} /></Field>
                </div>
                <div className="adm-geo-pair">
                  <Field label={t('adm.geo.f.radius', undefined, locale)} required hint={t('adm.geo.f.radiusHint', undefined, locale)} id="area-radius"><input id="area-radius" className="db-input" inputMode="decimal" value={f.radiusKm} onChange={set('radiusKm')} /></Field>
                  <div className="db-field"><span className="db-field__label" aria-hidden="true">&nbsp;</span><button type="button" className="db-btn db-btn--ghost" onClick={useCityCentre} disabled={!city} data-testid="use-city-centre">{t('adm.geo.useCityCentre', undefined, locale)}</button></div>
                </div>
              </>
              : <Field label={t('adm.geo.f.geojson', undefined, locale)} required hint={t('adm.geo.f.geojsonHint', undefined, locale)} id="area-geojson"><textarea id="area-geojson" className="db-textarea adm-geo-json" rows={7} value={f.geojson} onChange={set('geojson')} spellCheck={false} placeholder='{"type":"Polygon","coordinates":[[[77.30,28.50],[77.40,28.50],[77.40,28.60],[77.30,28.60],[77.30,28.50]]]}' /></Field>}
            {errors.geometry && <p className="db-field__error" role="alert">{errors.geometry}</p>}
            {geometry && <BoundaryPreview geometry={geometry} locale={locale} />}
          </>}
          <p className="db-field__hint">{t('adm.geo.boundaryNote', undefined, locale)}</p>
        </fieldset>
        {message && <p className="db-field__error" role="alert" data-testid="geo-form-error">{message}</p>}
        <button type="submit" hidden />
      </form>
    </Drawer>
  )
}
