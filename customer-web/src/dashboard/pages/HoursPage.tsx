import { useEffect, useState } from 'react'
import { t } from '../../i18n/strings'
import type { OpeningHours, OpeningPeriod } from '../../repositories/types'
import { useDashboard } from '../DashboardContext'
import { Card, ConfirmDialog, Field, Icon, PageHeader, Pill, Toggle, ToastLine, useToastMessage } from '../components/ui'
import type { HoursValidationIssue, SpecialHours } from '../types'

/** Weekly hours editor (Module 17): multiple periods per day, overnight periods, special hours / closures, restaurant-local timezone. */
export type DayPeriods = Record<number, Array<{ open: string; close: string }>>
export const DAYS = [1, 2, 3, 4, 5, 6, 0]
export const hm = (s: string) => { const m = /^(\d{1,2}):(\d{2})$/.exec(s); if (!m) return null; const h = Number(m[1]), mi = Number(m[2]); return h > 23 || mi > 59 ? null : h * 60 + mi }
export const toDayPeriods = (h: OpeningHours): DayPeriods => { const out: DayPeriods = {}; for (const p of h.periods) (out[p.day] ??= []).push({ open: p.open, close: p.close }); return out }
export const fromDayPeriods = (dp: DayPeriods, closures: OpeningHours['closures'], note?: string): OpeningHours => ({ periods: Object.entries(dp).flatMap(([d, ps]) => ps.map<OpeningPeriod>((p) => ({ day: Number(d), open: p.open, close: p.close }))), closures, note })
/** Validates times, zero-length and overlapping periods; a close earlier than open is an overnight period (allowed). */
export function validateHours(dp: DayPeriods): HoursValidationIssue[] {
  const issues: HoursValidationIssue[] = []
  for (const [d, ps] of Object.entries(dp)) {
    const day = Number(d); if (ps.length > 4) issues.push({ day, index: null, code: 'too_many' })
    const spans: Array<[number, number, number]> = []
    ps.forEach((p, i) => { const o = hm(p.open), c = hm(p.close); if (o == null || c == null) { issues.push({ day, index: i, code: 'invalid_time' }); return } if (o === c) { issues.push({ day, index: i, code: 'zero_length' }); return } spans.push([o, c > o ? c : c + 1440, i]) })
    spans.sort((a, b) => a[0] - b[0])
    for (let i = 1; i < spans.length; i++) if (spans[i][0] < spans[i - 1][1]) issues.push({ day, index: spans[i][2], code: 'overlap' })
  }
  return issues
}

export default function HoursPage() {
  const d = useDashboard(); const locale = d.locale; const loc = d.location!; const r = loc.restaurant; const rid = r.id; const canEdit = d.can('hours.edit')
  const [dp, setDp] = useState<DayPeriods>(() => toDayPeriods(r.openingHours)); const [issues, setIssues] = useState<HoursValidationIssue[]>([]); const [busy, setBusy] = useState(false); const { msg, toast } = useToastMessage()
  const [special, setSpecial] = useState<SpecialHours[]>([]); const [sp, setSp] = useState({ date: '', label: '', closed: true, open: '10:00', close: '16:00' }); const [spErr, setSpErr] = useState<string | null>(null); const [removeSp, setRemoveSp] = useState<SpecialHours | null>(null)
  useEffect(() => { setDp(toDayPeriods(r.openingHours)); setIssues([]); d.repos.management.getSpecialHours(rid).then(setSpecial).catch(() => setSpecial([])) }, [rid]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { document.title = `${t('dash.nav.hours', undefined, locale)} · ${t('dash.brand', undefined, locale)}` }, [locale])
  const dayName = (day: number) => new Intl.DateTimeFormat(locale, { weekday: 'long' }).format(new Date(Date.UTC(2024, 0, 7 + day)))
  const issue = (day: number, i: number | null) => issues.find((x) => x.day === day && (x.index === i || (i === null && x.index === null)))
  const update = (day: number, i: number, k: 'open' | 'close', v: string) => setDp({ ...dp, [day]: dp[day].map((p, k2) => (k2 === i ? { ...p, [k]: v } : p)) })
  const save = async () => { const iss = validateHours(dp); setIssues(iss); if (iss.length) return; setBusy(true); try { await d.repos.management.updateHours(rid, fromDayPeriods(dp, r.openingHours.closures, r.openingHours.note)); await d.refreshLocation(); toast(t('dash.hours.saved', undefined, locale)) } catch { toast(t('dash.hours.saveFailed', undefined, locale)) } finally { setBusy(false) } }
  const addSpecial = async () => { if (!/^\d{4}-\d{2}-\d{2}$/.test(sp.date)) { setSpErr(t('dash.validation.date', undefined, locale)); return } if (!sp.label.trim()) { setSpErr(t('dash.validation.required', undefined, locale)); return } if (!sp.closed && (hm(sp.open) == null || hm(sp.close) == null)) { setSpErr(t('dash.validation.time', undefined, locale)); return } setSpErr(null); const list = [...special.filter((s) => s.date !== sp.date), { id: `sh_${sp.date}`, date: sp.date, label: sp.label.trim(), closed: sp.closed, periods: sp.closed ? [] : [{ open: sp.open, close: sp.close }] }].sort((a, b) => a.date.localeCompare(b.date)); setSpecial(await d.repos.management.saveSpecialHours(rid, list)); await d.refreshLocation(); setSp({ date: '', label: '', closed: true, open: '10:00', close: '16:00' }); toast(t('dash.hours.specialSaved', undefined, locale)) }
  return (
    <div className="db-page" data-testid="db-hours">
      <PageHeader title={t('dash.hours.title', undefined, locale)} lead={t('dash.hours.lead', { zone: r.timezone }, locale)} actions={<Pill tone="muted"><Icon name="hours" size={14} /> {r.timezone}</Pill>} />
      <div className="db-grid db-grid--2">
        <Card title={t('dash.hours.weekly', undefined, locale)} subtitle={t('dash.hours.weeklySub', undefined, locale)} actions={canEdit ? <button type="button" className="db-btn db-btn--primary" onClick={() => { void save() }} disabled={busy} data-testid="hours-save">{busy ? t('dash.action.saving', undefined, locale) : t('dash.action.saveChanges', undefined, locale)}</button> : undefined}>
          {issues.length > 0 && <p className="db-field__error" role="alert" data-testid="hours-errors">{t('dash.hours.errors', { n: issues.length }, locale)}</p>}
          <div data-testid="hours-editor">
            {DAYS.map((day) => { const ps = dp[day] ?? []; const openDay = ps.length > 0; return (
              <div key={day} className="db-hours-day" data-day={day}>
                <span className="db-hours-day__name">{dayName(day)}</span>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, paddingTop: 4 }}><Toggle checked={openDay} onChange={(v) => setDp({ ...dp, [day]: v ? [{ open: '09:00', close: '22:00' }] : [] })} label={`${dayName(day)}: ${t('dash.hours.openToggle', undefined, locale)}`} disabled={!canEdit} testId={`day-toggle-${day}`} /><span className={`db-badge ${openDay ? 'db-badge--green' : 'db-badge--muted'}`}>{openDay ? t('dash.hours.open', undefined, locale) : t('dash.hours.closed', undefined, locale)}</span></div>
                <div className="db-hours-day__periods">
                  {ps.map((p, i) => { const iss = issue(day, i); const overnight = hm(p.open) != null && hm(p.close) != null && hm(p.close)! <= hm(p.open)!; return (
                    <div key={i} className="db-hours-period">
                      <input type="time" className={`db-input db-input--sm ${iss ? 'db-field--error' : ''}`} value={p.open} onChange={(e) => update(day, i, 'open', e.target.value)} aria-label={`${dayName(day)} ${t('dash.hours.from', undefined, locale)} ${i + 1}`} disabled={!canEdit} aria-invalid={!!iss} />
                      <span className="db-muted">–</span>
                      <input type="time" className="db-input db-input--sm" value={p.close} onChange={(e) => update(day, i, 'close', e.target.value)} aria-label={`${dayName(day)} ${t('dash.hours.to', undefined, locale)} ${i + 1}`} disabled={!canEdit} aria-invalid={!!iss} />
                      {overnight && !iss && <Pill tone="blue">{t('dash.hours.overnight', undefined, locale)}</Pill>}
                      {iss && <span className="db-field__error" role="alert">{t(`dash.hours.issue.${iss.code}`, undefined, locale)}</span>}
                      {canEdit && <button type="button" className="db-iconbtn" style={{ width: 34, height: 34 }} aria-label={t('dash.hours.removePeriod', undefined, locale)} onClick={() => setDp({ ...dp, [day]: ps.filter((_, k) => k !== i) })}><Icon name="trash" size={14} /></button>}
                    </div>) })}
                  {openDay && canEdit && ps.length < 4 && <button type="button" className="db-link" style={{ alignSelf: 'flex-start', fontSize: '0.85rem' }} onClick={() => setDp({ ...dp, [day]: [...ps, { open: '17:00', close: '22:00' }] })} data-testid={`add-period-${day}`}><Icon name="plus" size={12} /> {t('dash.hours.addPeriod', undefined, locale)}</button>}
                  {issue(day, null) && <span className="db-field__error" role="alert">{t('dash.hours.issue.too_many', undefined, locale)}</span>}
                </div>
              </div>) })}
          </div>
          <p className="db-muted" style={{ fontSize: '0.82rem', marginBottom: 0 }}>{t('dash.hours.note', undefined, locale)}</p>
        </Card>
        <Card title={t('dash.hours.special', undefined, locale)} subtitle={t('dash.hours.specialSub', undefined, locale)}>
          {special.length === 0 ? <p className="db-muted" style={{ marginTop: 0 }}>{t('dash.hours.noSpecial', undefined, locale)}</p> : <ul className="db-list" data-testid="special-list">{special.map((s) => <li key={s.id}><span style={{ flex: 1 }}><b>{new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${s.date}T12:00:00Z`))}</b> · <span dir="auto">{s.label}</span><br /><small className="db-muted">{s.closed ? t('dash.hours.closedAllDay', undefined, locale) : s.periods.map((p) => `${p.open}–${p.close}`).join(', ')}</small></span><Pill tone={s.closed ? 'red' : 'blue'}>{s.closed ? t('dash.hours.closure', undefined, locale) : t('dash.hours.specialOpen', undefined, locale)}</Pill>{canEdit && <button type="button" className="db-iconbtn" style={{ width: 34, height: 34 }} aria-label={t('dash.action.remove', undefined, locale)} onClick={() => setRemoveSp(s)}><Icon name="trash" size={14} /></button>}</li>)}</ul>}
          {canEdit && (
            <form className="db-form-grid" style={{ marginTop: 12 }} onSubmit={(e) => { e.preventDefault(); void addSpecial() }} data-testid="special-form">
              <div className="db-form-row"><Field label={t('dash.hours.date', undefined, locale)} required id="sp-date"><input id="sp-date" type="date" className="db-input db-input--sm" value={sp.date} onChange={(e) => setSp({ ...sp, date: e.target.value })} data-testid="special-date" /></Field><Field label={t('dash.hours.label', undefined, locale)} required id="sp-label"><input id="sp-label" className="db-input db-input--sm" value={sp.label} onChange={(e) => setSp({ ...sp, label: e.target.value })} placeholder={t('dash.hours.labelHint', undefined, locale)} dir="auto" data-testid="special-label" /></Field></div>
              <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}><Toggle checked={sp.closed} onChange={(v) => setSp({ ...sp, closed: v })} label={t('dash.hours.closedAllDay', undefined, locale)} /><span>{sp.closed ? t('dash.hours.closedAllDay', undefined, locale) : t('dash.hours.specialOpenHours', undefined, locale)}</span>{!sp.closed && <><input type="time" className="db-input db-input--sm" style={{ width: 118 }} value={sp.open} onChange={(e) => setSp({ ...sp, open: e.target.value })} aria-label={t('dash.hours.from', undefined, locale)} /><input type="time" className="db-input db-input--sm" style={{ width: 118 }} value={sp.close} onChange={(e) => setSp({ ...sp, close: e.target.value })} aria-label={t('dash.hours.to', undefined, locale)} /></>}</div>
              {spErr && <p className="db-field__error" role="alert">{spErr}</p>}
              <button type="submit" className="db-btn db-btn--outline" style={{ alignSelf: 'flex-start' }} data-testid="special-add"><Icon name="plus" size={16} /> {t('dash.hours.addSpecial', undefined, locale)}</button>
            </form>
          )}
        </Card>
      </div>
      <ConfirmDialog open={!!removeSp} title={t('dash.hours.removeSpecialTitle', undefined, locale)} confirmLabel={t('dash.action.remove', undefined, locale)} cancelLabel={t('dash.action.cancel', undefined, locale)} danger onCancel={() => setRemoveSp(null)} onConfirm={async () => { if (removeSp) { setSpecial(await d.repos.management.saveSpecialHours(rid, special.filter((s) => s.id !== removeSp.id))); await d.refreshLocation(); setRemoveSp(null) } }} />
      <ToastLine msg={msg} />
    </div>
  )
}
