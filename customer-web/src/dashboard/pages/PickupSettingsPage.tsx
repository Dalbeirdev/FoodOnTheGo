import { useCallback, useEffect, useState } from 'react'
import { t } from '../../i18n/strings'
import type { PickupMethod, PickupSettings } from '../../pickup/repositories'
import { useDashboard } from '../DashboardContext'
import { Card, Field, PageHeader, Pill, Skeleton, Toggle, ToastLine, useToastMessage } from '../components/ui'
import { dashboardErrorMessage } from '../api/apiDashboard'
import { formatLocalTime } from '../../i18n/format'
import { formatLocalDate } from '../../pickup/time'
import type { PickupPreview } from '../types'

/** Pickup settings (Module 17): enable pickup, methods, slot interval, lead time, schedule horizon, buffer, cut-off, instructions. */
const INTERVALS = [5, 10, 15, 20, 30, 60]
const METHOD_TYPES: PickupMethod['type'][] = ['counter', 'curbside', 'drive_through']
export function validatePickupSettings(s: PickupSettings): Record<string, string> {
  const e: Record<string, string> = {}
  if (!INTERVALS.includes(s.intervalMinutes)) e.intervalMinutes = 'interval'
  if (s.minimumLeadMinutes < 0 || s.minimumLeadMinutes > 24 * 60) e.minimumLeadMinutes = 'range'
  if (s.maximumScheduleAheadMinutes < 60 || s.maximumScheduleAheadMinutes > 30 * 24 * 60) e.maximumScheduleAheadMinutes = 'range'
  if (s.minimumLeadMinutes >= s.maximumScheduleAheadMinutes) e.maximumScheduleAheadMinutes = 'horizon'
  if (s.bufferMinutes < 0 || s.bufferMinutes > 120) e.bufferMinutes = 'range'
  if (s.acceptanceCutoffMinutes < 0 || s.acceptanceCutoffMinutes > 240) e.acceptanceCutoffMinutes = 'range'
  if (s.modes.length === 0) e.modes = 'modes'
  if (!s.methods.some((m) => m.enabled)) e.methods = 'methods'
  if (s.capacityPerSlot !== undefined && s.capacityPerSlot !== null && (!Number.isInteger(s.capacityPerSlot) || s.capacityPerSlot < 1 || s.capacityPerSlot > 500)) e.capacityPerSlot = 'capacity'
  return e
}
export default function PickupSettingsPage() {
  const d = useDashboard(); const locale = d.locale; const loc = d.location!; const rid = loc.restaurant.id; const canEdit = d.can('pickup.settings.edit')
  const [s, setS] = useState<PickupSettings | null>(null); const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading'); const [errors, setErrors] = useState<Record<string, string>>({}); const [busy, setBusy] = useState(false); const { msg, toast } = useToastMessage()
  useEffect(() => { let on = true; setState('loading'); d.repos.management.getPickupSettings(rid).then((v) => { if (on) { setS(v); setState('ready') } }).catch(() => { if (on) setState('error') }); return () => { on = false } }, [d.repos, rid])
  useEffect(() => { document.title = `${t('dash.nav.pickupSettings', undefined, locale)} · ${t('dash.brand', undefined, locale)}` }, [locale])
  // What customers are offered right now — asked from the backend's pickup engine after loading and after every save.
  const [preview, setPreview] = useState<PickupPreview | null>(null); const [previewState, setPreviewState] = useState<'idle' | 'loading' | 'ready' | 'error'>('idle')
  const management = d.repos.management; const live = d.live
  const loadPreview = useCallback(async () => {
    if (!live || !management.getPickupPreview) return
    setPreviewState('loading')
    try { setPreview(await management.getPickupPreview(rid)); setPreviewState('ready') } catch { setPreviewState('error') }
  }, [live, management, rid])
  useEffect(() => { const id = setTimeout(() => { void loadPreview() }, 0); return () => clearTimeout(id) }, [loadPreview])
  if (state !== 'ready' || !s) return <div className="db-page"><PageHeader title={t('dash.pickupSettings.title', undefined, locale)} /><Card><Skeleton rows={6} /></Card></div>
  const enabled = s.modes.length > 0
  const set = (patch: Partial<PickupSettings>) => setS({ ...s, ...patch })
  const method = (type: PickupMethod['type']) => s.methods.find((m) => m.type === type)
  const toggleMethod = (type: PickupMethod['type'], on: boolean) => { const m = method(type); const labels: Record<PickupMethod['type'], string> = { counter: t('dash.pickupSettings.method.counter', undefined, locale), curbside: t('dash.pickupSettings.method.curbside', undefined, locale), drive_through: t('dash.pickupSettings.method.drive_through', undefined, locale) }; set({ methods: m ? s.methods.map((x) => (x.type === type ? { ...x, enabled: on } : x)) : [...s.methods, { id: type, type, label: labels[type], enabled: on, requiresVehicleInfo: type !== 'counter' }] }) }
  const allowed = (mode: 'asap' | 'scheduled') => s.marketModes?.[mode] !== false
  const save = async () => { const e = validatePickupSettings(s); setErrors(e); if (Object.keys(e).length) return; setBusy(true); try { setS(await d.repos.management.savePickupSettings(rid, s)); if (d.live) { await d.refreshLocation(); await loadPreview() } toast(t('dash.pickupSettings.saved', undefined, locale)) } catch (err) { toast(d.live ? dashboardErrorMessage(err) : t('dash.pickupSettings.saveFailed', undefined, locale)); if (d.live) d.repos.management.getPickupSettings(rid).then(setS).catch(() => undefined) } finally { setBusy(false) } }
  const err = (k: string) => (errors[k] ? t(`dash.pickupSettings.error.${errors[k]}`, undefined, locale) : undefined)
  return (
    <div className="db-page" data-testid="db-pickup-settings">
      <PageHeader title={t('dash.pickupSettings.title', undefined, locale)} lead={t('dash.pickupSettings.lead', undefined, locale)} actions={canEdit ? <button type="button" className="db-btn db-btn--primary" onClick={() => { void save() }} disabled={busy} data-testid="ps-save">{busy ? t('dash.action.saving', undefined, locale) : t('dash.action.saveChanges', undefined, locale)}</button> : undefined} />
      {Object.keys(errors).length > 0 && <p className="db-field__error" role="alert" data-testid="ps-errors">{t('dash.validation.summary', { n: Object.keys(errors).length }, locale)}</p>}
      <div className="db-grid db-grid--half">
        <Card title={t('dash.pickupSettings.general', undefined, locale)}>
          <div className="db-settings-row"><p>{t('dash.pickupSettings.enable', undefined, locale)}<small>{t('dash.pickupSettings.enableHint', undefined, locale)}</small></p><Toggle checked={enabled} onChange={(v) => set({ modes: v ? (['asap', 'scheduled'] as const).filter(allowed) : [] })} label={t('dash.pickupSettings.enable', undefined, locale)} disabled={!canEdit} testId="ps-enable" /></div>
          <div className="db-settings-row"><p>{t('dash.pickupSettings.asap', undefined, locale)}<small>{allowed('asap') ? t('dash.pickupSettings.asapHint', undefined, locale) : t('dash.pickupSettings.notInMarket', undefined, locale)}</small></p><Toggle checked={s.modes.includes('asap')} onChange={(v) => set({ modes: v ? [...new Set([...s.modes, 'asap' as const])] : s.modes.filter((m) => m !== 'asap') })} label={t('dash.pickupSettings.asap', undefined, locale)} disabled={!canEdit || !allowed('asap')} /></div>
          <div className="db-settings-row"><p>{t('dash.pickupSettings.scheduled', undefined, locale)}<small>{allowed('scheduled') ? t('dash.pickupSettings.scheduledHint', undefined, locale) : t('dash.pickupSettings.notInMarket', undefined, locale)}</small></p><Toggle checked={s.modes.includes('scheduled')} onChange={(v) => set({ modes: v ? [...new Set([...s.modes, 'scheduled' as const])] : s.modes.filter((m) => m !== 'scheduled') })} label={t('dash.pickupSettings.scheduled', undefined, locale)} disabled={!canEdit || !allowed('scheduled')} /></div>
          {err('modes') && <p className="db-field__error" role="alert">{err('modes')}</p>}
          <p className="db-field__label" style={{ margin: '14px 0 6px' }}>{t('dash.pickupSettings.methods', undefined, locale)}</p>
          <p className="db-muted" style={{ margin: '0 0 8px', fontSize: '0.82rem' }}>{t('dash.pickupSettings.methodsHint', undefined, locale)}</p>
          {METHOD_TYPES.map((type) => { const off = method(type)?.availableInMarket === false; return <div key={type} className="db-settings-row"><p>{t(`dash.pickupSettings.method.${type}`, undefined, locale)}{off ? <small data-testid={`ps-method-${type}-unavailable`}>{t('dash.pickupSettings.notInMarket', undefined, locale)}</small> : type !== 'counter' && <small>{t('dash.pickupSettings.vehicleInfo', undefined, locale)}</small>}</p><Toggle checked={method(type)?.enabled ?? false} onChange={(v) => toggleMethod(type, v)} label={t(`dash.pickupSettings.method.${type}`, undefined, locale)} disabled={!canEdit || off} testId={`ps-method-${type}`} /></div> })}
          {err('methods') && <p className="db-field__error" role="alert">{err('methods')}</p>}
        </Card>
        <Card title={t('dash.pickupSettings.timing', undefined, locale)} subtitle={t('dash.pickupSettings.timingSub', { zone: loc.restaurant.timezone }, locale)}>
          <div className="db-form-grid">
            <Field label={t('dash.pickupSettings.prep', undefined, locale)} hint={t('dash.pickupSettings.prepHint', { n: loc.restaurant.prepTimeMin }, locale)} id="ps-prep"><input id="ps-prep" className="db-input" value={t('dash.units.minutes', { n: loc.restaurant.prepTimeMin }, locale)} readOnly /></Field>
            <Field label={t('dash.pickupSettings.interval', undefined, locale)} hint={t('dash.pickupSettings.intervalHint', undefined, locale)} error={err('intervalMinutes')} id="ps-int"><select id="ps-int" className="db-select" value={s.intervalMinutes} onChange={(e) => set({ intervalMinutes: Number(e.target.value) })} disabled={!canEdit} data-testid="ps-interval">{INTERVALS.map((m) => <option key={m} value={m}>{t('dash.units.minutes', { n: m }, locale)}</option>)}</select></Field>
            <div className="db-form-row">
              <Field label={t('dash.pickupSettings.minLead', undefined, locale)} error={err('minimumLeadMinutes')} id="ps-lead"><input id="ps-lead" type="number" min={0} className="db-input" value={s.minimumLeadMinutes} onChange={(e) => set({ minimumLeadMinutes: Number(e.target.value) })} disabled={!canEdit} data-testid="ps-lead" /></Field>
              <Field label={t('dash.pickupSettings.horizon', undefined, locale)} hint={t('dash.pickupSettings.horizonHint', undefined, locale)} error={err('maximumScheduleAheadMinutes')} id="ps-hor"><input id="ps-hor" type="number" min={60} className="db-input" value={s.maximumScheduleAheadMinutes} onChange={(e) => set({ maximumScheduleAheadMinutes: Number(e.target.value) })} disabled={!canEdit} data-testid="ps-horizon" /></Field>
            </div>
            <div className="db-form-row">
              <Field label={t('dash.pickupSettings.buffer', undefined, locale)} error={err('bufferMinutes')} id="ps-buf"><input id="ps-buf" type="number" min={0} className="db-input" value={s.bufferMinutes} onChange={(e) => set({ bufferMinutes: Number(e.target.value) })} disabled={!canEdit} /></Field>
              <Field label={t('dash.pickupSettings.cutoff', undefined, locale)} error={err('acceptanceCutoffMinutes')} id="ps-cut"><input id="ps-cut" type="number" min={0} className="db-input" value={s.acceptanceCutoffMinutes} onChange={(e) => set({ acceptanceCutoffMinutes: Number(e.target.value) })} disabled={!canEdit} /></Field>
            </div>
            <Field label={t('dash.pickupSettings.instructions', undefined, locale)} hint={t('dash.pickupSettings.instructionsHint', undefined, locale)} id="ps-ins"><textarea id="ps-ins" className="db-textarea" value={s.instructions ?? ''} onChange={(e) => set({ instructions: e.target.value || undefined })} dir="auto" disabled={!canEdit} data-testid="ps-instructions" /></Field>
            {s.capacityPerSlot !== undefined ? (
              <Field label={t('dash.pickupSettings.capacityPerSlot', undefined, locale)} hint={t('dash.pickupSettings.capacityPerSlotHint', undefined, locale)} error={err('capacityPerSlot')} id="ps-cap"><input id="ps-cap" type="number" min={1} max={500} className="db-input" placeholder={t('dash.pickupSettings.capacityNone', undefined, locale)} value={s.capacityPerSlot ?? ''} onChange={(e) => set({ capacityPerSlot: e.target.value === '' ? null : Number(e.target.value) })} disabled={!canEdit} data-testid="ps-capacity" /></Field>
            ) : (
              <div className="db-settings-row"><p>{t('dash.pickupSettings.capacity', undefined, locale)}<small>{t('dash.pickupSettings.capacityHint', undefined, locale)}</small></p><Pill tone="amber">{t('dash.pending', undefined, locale)}</Pill></div>
            )}
          </div>
        </Card>
      </div>
      {live && previewState !== 'idle' && (
        <Card title={t('dash.pickupSettings.preview.title', undefined, locale)} subtitle={t('dash.pickupSettings.preview.subtitle', { zone: loc.restaurant.timezone }, locale)}>
          {previewState === 'loading' && !preview && <Skeleton rows={3} />}
          {previewState === 'error' && <p className="db-field__error" role="alert" data-testid="ps-preview-error">{t('dash.pickupSettings.preview.error', undefined, locale)} <button type="button" className="db-btn" onClick={() => { void loadPreview() }}>{t('dash.action.retry', undefined, locale)}</button></p>}
          {preview && (() => {
            const tz = preview.timezone; const at = (iso: string | null) => (iso ? formatLocalTime(iso, tz, locale) : '—')
            const blocking = preview.issues.find((i) => i.blocking)
            return (
              <div data-testid="ps-preview" data-can-order={preview.customersCanOrder ? 'true' : 'false'}>
                {preview.warnings.map((w) => <p key={w.code} className="db-field__error" role="alert" data-testid="ps-preview-warning" data-code={w.code}>{w.message}</p>)}
                {blocking && <p className="db-muted" data-testid="ps-preview-blocked" data-code={blocking.code}>{t('dash.pickupSettings.preview.blocked', undefined, locale)} {blocking.message}</p>}
                <div className="db-settings-row"><p>{t('dash.pickupSettings.asap', undefined, locale)}<small data-testid="ps-preview-asap" data-available={preview.asap.available ? 'true' : 'false'}>{!preview.asap.enabled ? t('dash.pickupSettings.preview.off', undefined, locale) : preview.asap.available ? t('dash.pickupSettings.preview.asapAt', { time: at(preview.asap.pickupAt), minutes: preview.asap.readyInMinutes ?? 0 }, locale) : t(`dash.pickupSettings.preview.asap.${preview.asap.reason ?? 'ASAP_UNAVAILABLE'}`, undefined, locale)}</small></p><Pill tone={preview.asap.available ? 'green' : 'amber'}>{preview.asap.available ? t('dash.pickupSettings.preview.offered', undefined, locale) : t('dash.pickupSettings.preview.notOffered', undefined, locale)}</Pill></div>
                <div className="db-settings-row"><p>{t('dash.pickupSettings.scheduled', undefined, locale)}<small data-testid="ps-preview-scheduled" data-slots={preview.scheduled.slotCount} data-available={preview.scheduled.availableSlotCount}>{!preview.scheduled.enabled ? t('dash.pickupSettings.preview.off', undefined, locale) : t('dash.pickupSettings.preview.slots', { n: preview.scheduled.availableSlotCount, interval: preview.scheduled.intervalMinutes ?? 0 }, locale)}{preview.scheduled.truncated ? ` ${t('dash.pickupSettings.preview.truncated', undefined, locale)}` : ''}</small></p><Pill tone={preview.scheduled.availableSlotCount > 0 ? 'green' : 'amber'}>{preview.scheduled.availableSlotCount > 0 ? t('dash.pickupSettings.preview.offered', undefined, locale) : t('dash.pickupSettings.preview.notOffered', undefined, locale)}</Pill></div>
                {preview.scheduled.days.length > 0 && (
                  <ul className="db-muted" style={{ margin: '8px 0 0', paddingInlineStart: 18, fontSize: '0.86rem' }} data-testid="ps-preview-days">
                    {preview.scheduled.days.slice(0, 7).map((x) => <li key={x.localDate} data-date={x.localDate} data-slots={x.slots} data-available={x.available}>{t('dash.pickupSettings.preview.day', { date: formatLocalDate(`${x.localDate}T12:00:00Z`, 'UTC', locale), first: at(x.firstSlotAt), last: at(x.lastSlotAt), n: x.available, total: x.slots }, locale)}</li>)}
                  </ul>
                )}
                {preview.preparation && <p className="db-muted" style={{ margin: '10px 0 0', fontSize: '0.82rem' }} data-testid="ps-preview-note">{t('dash.pickupSettings.preview.note', { ready: preview.preparation.readyInMinutes, prep: preview.preparation.estimatedMinutes, buffer: preview.preparation.bufferMinutes, lead: preview.preparation.minimumLeadMinutes }, locale)}</p>}
              </div>
            )
          })()}
        </Card>
      )}
      <ToastLine msg={msg} />
    </div>
  )
}
