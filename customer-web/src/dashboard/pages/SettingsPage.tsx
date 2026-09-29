import { useEffect, useState } from 'react'
import { t } from '../../i18n/strings'
import { useDashboard } from '../DashboardContext'
import { AcceptingSwitch } from '../DashboardLayout'
import { resetDashboardStores } from '../mock/mockDashboard'
import { Card, Field, PageHeader, Pill, Skeleton, Toggle, ToastLine, useToastMessage } from '../components/ui'
import type { LocationSettings } from '../types'

/** Settings (Module 17): preferences, operational status, language / timezone (platform-controlled), notification prefs, account & security placeholders. */
export default function SettingsPage() {
  const d = useDashboard(); const locale = d.locale; const loc = d.location!; const rid = loc.restaurant.id; const canManage = d.can('settings.manage')
  const [s, setS] = useState<LocationSettings | null>(null); const [busy, setBusy] = useState(false); const { msg, toast } = useToastMessage()
  useEffect(() => { let on = true; d.repos.management.getSettings(rid).then((v) => { if (on) setS(v) }).catch(() => {}); return () => { on = false } }, [d.repos, rid])
  useEffect(() => { document.title = `${t('dash.nav.settings', undefined, locale)} · ${t('dash.brand', undefined, locale)}` }, [locale])
  if (!s) return <div className="db-page"><PageHeader title={t('dash.settings.title', undefined, locale)} /><Card><Skeleton rows={5} /></Card></div>
  const save = async (next: LocationSettings) => { setS(next); setBusy(true); try { await d.repos.management.saveSettings(rid, next); toast(t('dash.settings.saved', undefined, locale)) } finally { setBusy(false) } }
  const prefRow = (k: keyof LocationSettings['notifications']) => <div key={k} className="db-settings-row"><p>{t(`dash.settings.pref.${k}`, undefined, locale)}<small>{t(`dash.settings.prefHint.${k}`, undefined, locale)}</small></p><Toggle checked={s.notifications[k]} onChange={(v) => { void save({ ...s, notifications: { ...s.notifications, [k]: v } }) }} label={t(`dash.settings.pref.${k}`, undefined, locale)} disabled={!canManage || busy} testId={`pref-${k}`} /></div>
  return (
    <div className="db-page" data-testid="db-settings">
      <PageHeader title={t('dash.settings.title', undefined, locale)} lead={t('dash.settings.lead', undefined, locale)} />
      <div className="db-grid db-grid--half">
        <Card title={t('dash.settings.operational', undefined, locale)}>
          <div className="db-settings-row"><p>{t('dash.profile.accepting', undefined, locale)}<small>{t('dash.profile.acceptingHint', undefined, locale)}</small></p><AcceptingSwitch /></div>
          <div className="db-settings-row"><p>{t('dash.settings.sound', undefined, locale)}<small>{t('dash.settings.soundHint', undefined, locale)}</small></p><Toggle checked={s.soundOnNewOrder} onChange={(v) => { void save({ ...s, soundOnNewOrder: v }) }} label={t('dash.settings.sound', undefined, locale)} disabled={!canManage || busy} /></div>
          <div className="db-settings-row"><p>{t('dash.settings.onboarding', undefined, locale)}<small>{t('dash.settings.onboardingHint', undefined, locale)}</small></p><Pill tone={loc.profile.onboardingStatus === 'APPROVED' ? 'green' : 'amber'}>{t(`dash.onboarding.${loc.profile.onboardingStatus}`, undefined, locale)}</Pill></div>
        </Card>
        <Card title={t('dash.settings.locale', undefined, locale)}>
          <Field label={t('dash.settings.language', undefined, locale)} hint={t('dash.settings.languageHint', undefined, locale)} id="st-lang"><select id="st-lang" className="db-select" value={s.language} onChange={(e) => { void save({ ...s, language: e.target.value }) }} disabled={!canManage}>{['en', 'hi', 'fr', 'ja', 'ar'].map((l) => <option key={l} value={l}>{new Intl.DisplayNames([locale], { type: 'language' }).of(l) ?? l}</option>)}</select></Field>
          <div className="db-settings-row"><p>{t('dash.profile.timezone', undefined, locale)}<small>{t('dash.settings.timezoneHint', undefined, locale)}</small></p><b>{loc.restaurant.timezone}</b></div>
          <div className="db-settings-row"><p>{t('dash.profile.currency', undefined, locale)}<small>{t('dash.settings.currencyHint', undefined, locale)}</small></p><b>{loc.restaurant.currency}</b></div>
          <div className="db-settings-row"><p>{t('dash.settings.tax', undefined, locale)}<small>{t('dash.settings.taxHint', undefined, locale)}</small></p><Pill tone="amber">{t('dash.pending', undefined, locale)}</Pill></div>
        </Card>
        <Card title={t('dash.settings.notifications', undefined, locale)} subtitle={t('dash.settings.notificationsSub', undefined, locale)} id="notifications">
          {(['newOrders', 'orderDelays', 'pickup', 'reviews', 'platform'] as const).map(prefRow)}
          <p className="db-muted" style={{ fontSize: '0.8rem', marginBottom: 0 }}>{t('dash.settings.deliveryNote', undefined, locale)}</p>
        </Card>
        <Card title={t('dash.settings.account', undefined, locale)}>
          <div className="db-settings-row"><p>{t('dash.settings.signedIn', undefined, locale)}</p><b dir="auto">{d.staff.name} · {t(`dash.role.${d.staff.role}`, undefined, locale)}</b></div>
          <div className="db-settings-row"><p>{t('dash.settings.password', undefined, locale)}<small>{t('dash.settings.passwordHint', undefined, locale)}</small></p><Pill tone="amber">{t('dash.pending', undefined, locale)}</Pill></div>
          <div className="db-settings-row"><p>{t('dash.settings.payouts', undefined, locale)}<small>{t('dash.settings.payoutsHint', undefined, locale)}</small></p><Pill tone="amber">{t('dash.pending', undefined, locale)}</Pill></div>
          <div className="db-settings-row"><p>{t('dash.settings.documents', undefined, locale)}<small>{t('dash.settings.documentsHint', undefined, locale)}</small></p><Pill tone="amber">{t('dash.pending', undefined, locale)}</Pill></div>
          <div className="db-settings-row"><p>{t('dash.settings.devReset', undefined, locale)}<small>{t('dash.settings.devResetHint', undefined, locale)}</small></p><button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { resetDashboardStores(); window.location.reload() }}>{t('dash.settings.reset', undefined, locale)}</button></div>
        </Card>
      </div>
      <ToastLine msg={msg} />
    </div>
  )
}
