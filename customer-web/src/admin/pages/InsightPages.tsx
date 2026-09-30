import { useMemo, useState } from 'react'
import { t } from '../../i18n/strings'
import { BarChart, DonutChart, LineChart, RatingBars } from '../../dashboard/components/Charts'
import { Card, ErrorState, Field, KpiCard, Skeleton, Toggle, ToastLine, useToastMessage } from '../../dashboard/components/ui'
import { useAdmin } from '../AdminContext'
import { Badge, DevNote, Select, Stat, useUrlState } from '../components/DataTable'
import type { AnalyticsQuery } from '../types'
import { MarketScopeChip, KNOWN_MARKET_CODES, Stars, StatusPill, fmtDateTime, marketOptions, money, pct, useLoad, usePageTitle } from './shared'

/* ------------------------------------------------------------------ Analytics */
const AN_DEFAULTS = { range: '7d', market: 'all', from: '', to: '' }
export function AnalyticsPage() {
  const a = useAdmin(); const locale = a.locale; usePageTitle('adm.nav.analytics')
  const [s, set] = useUrlState(AN_DEFAULTS)
  const q = useMemo<AnalyticsQuery>(() => ({ range: s.range as AnalyticsQuery['range'], market: a.market === 'all' ? s.market : a.market, from: s.from || undefined, to: s.to || undefined }), [s, a.market])
  const { data, state, reload } = useLoad(() => a.repos.analytics.platform(q), [a.repos, q])
  return (
    <div className="db-page" data-testid="adm-analytics">
      <div className="db-page__head"><div><h1 className="db-page__title">{t('adm.analytics.title', undefined, locale)}</h1><p className="db-page__lead">{t('adm.analytics.lead', undefined, locale)}</p></div>
        <div className="db-page__actions"><Select label={t('adm.analytics.range', undefined, locale)} value={s.range} onChange={(range) => set({ range })} options={(['today', '7d', '30d', 'quarter', 'custom'] as const).map((r) => ({ value: r, label: t(`adm.range.${r}`, undefined, locale) }))} testId="filter-range" />{a.market === 'all' ? <Select label={t('adm.analytics.market', undefined, locale)} value={s.market} onChange={(market) => set({ market })} options={marketOptions(KNOWN_MARKET_CODES, locale)} testId="filter-market" /> : <MarketScopeChip />}</div></div>
      {s.range === 'custom' && <div className="adm-inline-form"><Field label={t('adm.analytics.from', undefined, locale)} id="an-from"><input id="an-from" type="date" className="db-input" value={s.from} onChange={(e) => set({ from: e.target.value })} /></Field><Field label={t('adm.analytics.to', undefined, locale)} id="an-to"><input id="an-to" type="date" className="db-input" value={s.to} onChange={(e) => set({ to: e.target.value })} /></Field></div>}
      {state === 'loading' && <div className="db-grid db-grid--kpi">{[0, 1, 2, 3].map((i) => <div key={i} className="db-kpi"><Skeleton rows={2} className="db-skeleton--card" /></div>)}</div>}
      {state === 'error' && <ErrorState title={t('adm.error.loadTitle', undefined, locale)} onRetry={() => { void reload() }} locale={locale} />}
      {state === 'ready' && data && <>
        <div className="db-grid db-grid--kpi adm-grid--kpi8">
          <KpiCard icon="orders" tone="orange" value={data.orders.toLocaleString(locale)} label={t('adm.analytics.kpi.orders', undefined, locale)} delta={data.ordersDelta} locale={locale} testId="an-orders" />
          <KpiCard icon="restaurants" tone="green" value={data.activeRestaurants.toLocaleString(locale)} label={t('adm.analytics.kpi.activeRestaurants', undefined, locale)} locale={locale} />
          <KpiCard icon="customers" tone="blue" value={data.activeCustomers.toLocaleString(locale)} label={t('adm.analytics.kpi.activeCustomers', undefined, locale)} locale={locale} />
          <KpiCard icon="refunds" tone="red" value={pct(data.refundRate, locale)} label={t('adm.analytics.kpi.refundRate', undefined, locale)} locale={locale} />
          <KpiCard icon="timer" tone="purple" value={t('dash.units.minutes', { n: data.averagePrepMinutes }, locale)} label={t('adm.analytics.kpi.prep', undefined, locale)} locale={locale} />
          <KpiCard icon="star" tone="amber" value={data.averageRating == null ? '—' : data.averageRating.toLocaleString(locale, { minimumFractionDigits: 1, maximumFractionDigits: 1 })} label={t('adm.analytics.kpi.rating', undefined, locale)} locale={locale} />
          <KpiCard icon="analytics" tone="blue" value={pct(data.conversionRate, locale)} label={t('adm.analytics.kpi.conversion', undefined, locale)} locale={locale} />
          <KpiCard icon="check" tone="green" value={pct(data.pickupOnTimeRate, locale)} label={t('adm.analytics.kpi.onTime', undefined, locale)} locale={locale} />
        </div>
        <div className="db-grid db-grid--2">
          <Card title={t('adm.analytics.ordersTrend', undefined, locale)}><LineChart title={t('adm.analytics.ordersTrend', undefined, locale)} data={data.ordersByDay} height={200} testId="an-trend" /></Card>
          <Card title={t('adm.analytics.byMarket', undefined, locale)}>{data.ordersByMarket.length === 0 ? <p className="db-muted">{t('adm.analytics.empty', undefined, locale)}</p> : <DonutChart title={t('adm.analytics.byMarket', undefined, locale)} data={data.ordersByMarket} colors={['var(--db-orange)', 'var(--db-blue)', 'var(--db-green)', 'var(--db-purple)', 'var(--db-amber)', '#0f766e']} testId="an-markets" />}</Card>
        </div>
        <Card title={t('adm.analytics.byCurrency', undefined, locale)} subtitle={t('adm.overview.gmvNote', undefined, locale)}>
          {data.byCurrency.length === 0 ? <p className="db-muted">{t('adm.analytics.empty', undefined, locale)}</p> : <div className="db-table-wrap" tabIndex={0}><table className="db-table" data-testid="an-currency"><caption className="db-sr-only">{t('adm.analytics.byCurrency', undefined, locale)}</caption><thead><tr><th scope="col">{t('adm.analytics.col.currency', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.analytics.col.orders', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.analytics.col.gmv', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.analytics.col.revenue', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.analytics.col.refunds', undefined, locale)}</th></tr></thead><tbody>{data.byCurrency.map((c) => <tr key={c.currency}><td><Badge tone="muted">{c.currency}</Badge></td><td className="adm-td--end">{c.orders.toLocaleString(locale)}</td><td className="adm-td--end">{money(c.gmvMinor, c.currency, locale)}</td><td className="adm-td--end">{money(c.revenueMinor, c.currency, locale)}</td><td className="adm-td--end">{money(c.refundsMinor, c.currency, locale)}</td></tr>)}</tbody></table></div>}
        </Card>
        <div className="db-grid db-grid--2">
          <Card title={t('adm.analytics.top', undefined, locale)}><div className="db-table-wrap" tabIndex={0}><table className="db-table" data-testid="an-top"><caption className="db-sr-only">{t('adm.analytics.top', undefined, locale)}</caption><thead><tr><th scope="col">{t('adm.analytics.col.restaurant', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.analytics.col.orders', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.analytics.col.gmv', undefined, locale)}</th><th scope="col">{t('adm.analytics.col.rating', undefined, locale)}</th></tr></thead><tbody>{data.topRestaurants.map((r) => <tr key={r.name}><td dir="auto">{r.name}</td><td className="adm-td--end">{r.orders.toLocaleString(locale)}</td><td className="adm-td--end">{money(r.gmvMinor, r.currency, locale)} <Badge tone="muted">{r.currency}</Badge></td><td><Stars rating={r.rating} /></td></tr>)}</tbody></table></div></Card>
          <Card title={t('adm.analytics.ratings', undefined, locale)}><RatingBars distribution={data.ratingDistribution} locale={locale} /><BarChart title={t('adm.analytics.ratings', undefined, locale)} data={data.ratingDistribution.map((r) => ({ label: `${r.rating}★`, value: r.count }))} height={120} /></Card>
        </div>
        <DevNote>{t('adm.analytics.exportNote', undefined, locale)} {t('adm.mockNote', undefined, locale)}</DevNote>
      </>}
    </div>
  )
}

/* ------------------------------------------------------------------ System status */
export function SystemPage() {
  const a = useAdmin(); const locale = a.locale; usePageTitle('adm.nav.system')
  const { data: sys, state, reload } = useLoad(() => a.repos.system.status(), [a.repos])
  const overall = sys ? (sys.services.some((x) => x.state === 'OUTAGE') ? 'OUTAGE' : sys.services.some((x) => x.state === 'DEGRADED') ? 'DEGRADED' : 'OPERATIONAL') : 'UNKNOWN'
  return (
    <div className="db-page" data-testid="adm-system">
      <div className="db-page__head"><div><h1 className="db-page__title">{t('adm.system.title', undefined, locale)}</h1><p className="db-page__lead">{t('adm.system.lead', undefined, locale)}</p></div><div className="db-page__actions"><span>{t('adm.system.overall', undefined, locale)}: </span><StatusPill status={overall} prefix="adm.health" dot /></div></div>
      {state === 'loading' && <Skeleton rows={5} />}{state === 'error' && <ErrorState title={t('adm.error.loadTitle', undefined, locale)} onRetry={() => { void reload() }} locale={locale} />}
      {state === 'ready' && sys && <>
        <Card title={t('adm.system.services', undefined, locale)}><div className="db-table-wrap" tabIndex={0}><table className="db-table" data-testid="sys-services"><caption className="db-sr-only">{t('adm.system.services', undefined, locale)}</caption><thead><tr><th scope="col">{t('adm.system.col.service', undefined, locale)}</th><th scope="col">{t('adm.system.col.kind', undefined, locale)}</th><th scope="col">{t('adm.system.col.state', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.system.col.latency', undefined, locale)}</th><th scope="col" className="adm-hide-mobile">{t('adm.system.col.note', undefined, locale)}</th><th scope="col" className="adm-hide-mobile">{t('adm.system.col.checked', undefined, locale)}</th></tr></thead><tbody>{sys.services.map((x) => <tr key={x.id}><td className="adm-td--primary">{x.name}</td><td>{t(`adm.system.kind.${x.kind}`, undefined, locale)}</td><td><StatusPill status={x.state} prefix="adm.health" dot /></td><td className="adm-td--end">{x.latencyMs == null ? '—' : t('adm.system.ms', { n: x.latencyMs.toLocaleString(locale) }, locale)}</td><td className="adm-hide-mobile">{x.note ?? '—'}</td><td className="adm-hide-mobile">{fmtDateTime(x.checkedAt, locale)}</td></tr>)}</tbody></table></div></Card>
        <div className="db-grid db-grid--2">
          <Card title={t('adm.system.queues', undefined, locale)}><div className="db-table-wrap" tabIndex={0}><table className="db-table" data-testid="sys-queues"><caption className="db-sr-only">{t('adm.system.queues', undefined, locale)}</caption><thead><tr><th scope="col">{t('adm.system.q.name', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.system.q.pending', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.system.q.failed', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.system.q.oldest', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.system.q.workers', undefined, locale)}</th></tr></thead><tbody>{sys.queues.map((x) => <tr key={x.name}><td><span className="adm-code">{x.name}</span></td><td className="adm-td--end">{x.pending}</td><td className="adm-td--end">{x.failed > 0 ? <Badge tone="red">{x.failed}</Badge> : 0}</td><td className="adm-td--end">{x.oldestJobAgeSec == null ? '—' : t('adm.system.seconds', { n: x.oldestJobAgeSec }, locale)}</td><td className="adm-td--end">{x.workers}</td></tr>)}</tbody></table></div></Card>
          <Card title={t('adm.system.maps', undefined, locale)}><div className="adm-stats"><Stat label={t('adm.system.maps.requests', undefined, locale)} value={sys.maps.requests24h.toLocaleString(locale)} /><Stat label={t('adm.system.maps.failures', undefined, locale)} value={sys.maps.failures24h.toLocaleString(locale)} tone={sys.maps.failures24h > 0 ? 'amber' : 'green'} /><Stat label={t('adm.system.col.state', undefined, locale)} value={<StatusPill status={sys.maps.providerState} prefix="adm.health" dot />} /></div></Card>
        </div>
        <Card title={t('adm.system.webhooks', undefined, locale)}><div className="db-table-wrap" tabIndex={0}><table className="db-table" data-testid="sys-webhooks"><caption className="db-sr-only">{t('adm.system.webhooks', undefined, locale)}</caption><thead><tr><th scope="col">{t('adm.system.w.provider', undefined, locale)}</th><th scope="col">{t('adm.system.w.event', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.system.w.received', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.system.w.processed', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.system.w.failed', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.system.w.retrying', undefined, locale)}</th><th scope="col" className="adm-hide-mobile">{t('adm.system.w.last', undefined, locale)}</th></tr></thead><tbody>{sys.webhooks.map((x) => <tr key={x.provider + x.event}><td>{x.provider}</td><td><span className="adm-code">{x.event}</span></td><td className="adm-td--end">{x.received.toLocaleString(locale)}</td><td className="adm-td--end">{x.processed.toLocaleString(locale)}</td><td className="adm-td--end">{x.failed > 0 ? <Badge tone="red">{x.failed}</Badge> : 0}</td><td className="adm-td--end">{x.retrying}</td><td className="adm-hide-mobile">{fmtDateTime(x.lastAt, locale)}</td></tr>)}</tbody></table></div></Card>
        <DevNote>{t('adm.mockNote', undefined, locale)}</DevNote>
      </>}
    </div>
  )
}

/* ------------------------------------------------------------------ Settings & Help */
type AdminSettings = { platformName: string; defaultLocale: string; supportEmail: string; supportPhone: string; requireMfa: boolean; sessionTimeoutMin: number; ipAllowlist: boolean }
const SETTINGS_KEY = 'fotg.adm.settings.v1'
const loadSettings = (): AdminSettings => { try { const raw = localStorage.getItem(SETTINGS_KEY); if (raw) return JSON.parse(raw) as AdminSettings } catch { /* ignore */ } return { platformName: 'FoodOnTheGo', defaultLocale: 'en', supportEmail: 'support@foodonthego.example', supportPhone: '+1 555 010 0000', requireMfa: true, sessionTimeoutMin: 30, ipAllowlist: false } }
export function SettingsPage() {
  const a = useAdmin(); const locale = a.locale; usePageTitle('adm.nav.settings')
  const [s, setS] = useState<AdminSettings>(loadSettings); const { msg, toast } = useToastMessage()
  const save = (e: React.FormEvent) => { e.preventDefault(); try { localStorage.setItem(SETTINGS_KEY, JSON.stringify(s)) } catch { /* ignore */ } toast(t('adm.settings.saved', undefined, locale)) }
  const legal: Array<[string, string]> = [['terms', '0.4'], ['privacy', '0.4'], ['refund', '0.3'], ['cookie', '0.2']]
  return (
    <div className="db-page" data-testid="adm-settings">
      <div className="db-page__head"><div><h1 className="db-page__title">{t('adm.settings.title', undefined, locale)}</h1><p className="db-page__lead">{t('adm.settings.lead', undefined, locale)}</p></div></div>
      <form onSubmit={save} className="adm-settings-grid">
        <Card title={t('adm.settings.general', undefined, locale)}><Field label={t('adm.settings.platformName', undefined, locale)} id="st-name"><input id="st-name" className="db-input" value={s.platformName} onChange={(e) => setS({ ...s, platformName: e.target.value })} /></Field><Field label={t('adm.settings.defaultLocale', undefined, locale)} id="st-loc"><input id="st-loc" className="db-input" value={s.defaultLocale} onChange={(e) => setS({ ...s, defaultLocale: e.target.value })} /></Field></Card>
        <Card title={t('adm.settings.brand', undefined, locale)}><div className="adm-cell"><img src="/brand/foodonthego-logo-master.svg" alt="FoodOnTheGo" width={180} height={48} /></div><p className="db-muted">{t('adm.settings.brandNote', undefined, locale)}</p></Card>
        <Card title={t('adm.settings.support', undefined, locale)}><Field label={t('adm.settings.supportEmail', undefined, locale)} id="st-email"><input id="st-email" type="email" className="db-input" value={s.supportEmail} onChange={(e) => setS({ ...s, supportEmail: e.target.value })} /></Field><Field label={t('adm.settings.supportPhone', undefined, locale)} id="st-phone"><input id="st-phone" className="db-input" value={s.supportPhone} onChange={(e) => setS({ ...s, supportPhone: e.target.value })} /></Field></Card>
        <Card title={t('adm.settings.legal', undefined, locale)} subtitle={t('adm.settings.legalNote', undefined, locale)}><ul className="adm-legal" data-testid="legal-versions">{legal.map(([k, v]) => <li key={k}><span>{t(`adm.settings.legal.${k}`, undefined, locale)} <small className="db-muted">{t('adm.settings.version', { v }, locale)}</small></span><Badge tone="amber">{t('adm.settings.pendingApproval', undefined, locale)}</Badge></li>)}</ul></Card>
        <Card title={t('adm.settings.security', undefined, locale)}><div className="db-settings-row"><p>{t('adm.settings.mfa', undefined, locale)}</p><Toggle checked={s.requireMfa} onChange={(v) => setS({ ...s, requireMfa: v })} label={t('adm.settings.mfa', undefined, locale)} testId="st-mfa" /></div><Field label={t('adm.settings.sessionTimeout', undefined, locale)} id="st-timeout"><input id="st-timeout" className="db-input" inputMode="numeric" value={String(s.sessionTimeoutMin)} onChange={(e) => setS({ ...s, sessionTimeoutMin: Number(e.target.value) || 0 })} /></Field><div className="db-settings-row"><p>{t('adm.settings.ipAllowlist', undefined, locale)}</p><Toggle checked={s.ipAllowlist} onChange={(v) => setS({ ...s, ipAllowlist: v })} label={t('adm.settings.ipAllowlist', undefined, locale)} /></div></Card>
        <Card title={t('adm.settings.integrations', undefined, locale)} subtitle={t('adm.settings.integrationsNote', undefined, locale)}><ul className="adm-legal">{[['payments', 'sandbox'], ['maps', 'sandbox'], ['notifications', 'configured'], ['sms', 'notConfigured']].map(([k, st]) => <li key={k}><span>{t(`adm.settings.int.${k}`, undefined, locale)}</span><Badge tone={st === 'notConfigured' ? 'muted' : st === 'sandbox' ? 'amber' : 'green'}>{t(`adm.settings.int.${st}`, undefined, locale)}</Badge></li>)}</ul></Card>
        <div><button type="submit" className="db-btn db-btn--primary" data-testid="settings-save">{t('dash.action.saveChanges', undefined, locale)}</button></div>
      </form>
      <ToastLine msg={msg} />
    </div>
  )
}
export function HelpPage() {
  const a = useAdmin(); const locale = a.locale; usePageTitle('adm.nav.help')
  return (
    <div className="db-page" data-testid="adm-help">
      <div className="db-page__head"><div><h1 className="db-page__title">{t('adm.help.title', undefined, locale)}</h1><p className="db-page__lead">{t('adm.help.lead', undefined, locale)}</p></div></div>
      <div className="db-help-grid">{['rbac', 'audit', 'money', 'mock', 'shortcuts'].map((k) => <Card key={k} title={t(`adm.help.${k}`, undefined, locale)}><p style={{ margin: 0 }}>{t(`adm.help.${k}Text`, undefined, locale)}</p></Card>)}</div>
    </div>
  )
}
