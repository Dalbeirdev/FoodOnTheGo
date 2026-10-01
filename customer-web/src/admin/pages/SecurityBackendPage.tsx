/**
 * Security screen on the backend's security events (shown when the admin runs against the backend).
 * Read-only: counters and alerts computed by the backend from stored events, and the event list with filters
 * applied by the backend. No code, password, token, phone number or e-mail is ever part of an event.
 */
import { useMemo, useState } from 'react'
import { t } from '../../i18n/strings'
import { Card, Drawer, ErrorState, Skeleton } from '../../dashboard/components/ui'
import { useAdmin } from '../AdminContext'
import type { BackendSecurityEvent, BackendSecurityFilter, BackendSecurityRepository } from '../api/ApiAdminSecurityRepository'
import { DataTable, Details, DevNote, Select, Stat, Toolbar, useUrlState, type Column } from '../components/DataTable'
import { Cell, StatusPill, fmtDateTime, useLoad } from './shared'

const DEFAULTS = { event: 'all', account: 'all', failed: '', from: '', to: '', page: '1' }
const ACCOUNT_TYPES = ['admin_user', 'restaurant_user', 'customer'] as const
const detailText = (e: BackendSecurityEvent) => Object.entries(e.details).filter(([, v]) => v !== null && typeof v !== 'object').map(([k, v]) => `${k.replace(/_/g, ' ')}: ${String(v)}`).join(' · ')

export function SecurityBackendPage({ repo }: { repo: BackendSecurityRepository }) {
  const a = useAdmin(); const locale = a.locale
  const [s, set] = useUrlState(DEFAULTS); const [open, setOpen] = useState<BackendSecurityEvent | null>(null)
  const filter = useMemo<BackendSecurityFilter>(() => ({ event: s.event, accountType: s.account, failedOnly: s.failed === '1', from: s.from || undefined, to: s.to || undefined, page: Number(s.page) || 1, pageSize: 15 }), [s])
  const { data: sum, state: sumState, reload: reloadSum } = useLoad(() => repo.summary(), [repo])
  const { data, state, reload } = useLoad(() => repo.events(filter), [repo, filter])
  const types = useMemo(() => repo.eventTypes(), [repo, data]) // eslint-disable-line react-hooks/exhaustive-deps
  const eventLabel = (e: string) => t(`adm.sec.event.${e}`, undefined, locale)
  const account = (e: BackendSecurityEvent) => e.accountName ?? (e.knownAccount ? t('adm.sec.account.removed', undefined, locale) : t('adm.sec.account.unknown', undefined, locale))
  const blocked = sum ? sum.blockedAccounts.admin + sum.blockedAccounts.restaurant + sum.blockedAccounts.customer : 0
  const columns: Array<Column<BackendSecurityEvent>> = [
    { id: 'time', label: t('adm.security.col.time', undefined, locale), render: (e) => fmtDateTime(e.at, locale) },
    { id: 'event', label: t('adm.sec.col.event', undefined, locale), primary: true, render: (e) => eventLabel(e.event) },
    { id: 'severity', label: t('adm.security.col.severity', undefined, locale), render: (e) => <StatusPill status={e.severity} prefix="adm.security.sev" dot /> },
    { id: 'account', label: t('adm.sec.col.account', undefined, locale), render: (e) => <Cell primary={account(e)} secondary={e.accountType ? t(`adm.sec.accountType.${e.accountType}`, undefined, locale) : undefined} /> },
    { id: 'detail', label: t('adm.security.col.detail', undefined, locale), hideMobile: true, render: (e) => <span dir="auto">{detailText(e) || '—'}</span> },
    { id: 'ip', label: t('adm.security.col.ip', undefined, locale), hideMobile: true, render: (e) => <span className="adm-code">{e.ip ?? '—'}</span> },
  ]
  return (
    <div className="db-page" data-testid="adm-security">
      <div className="db-page__head"><div><h1 className="db-page__title">{t('adm.security.title', undefined, locale)}</h1><p className="db-page__lead">{t('adm.sec.lead', undefined, locale)}</p></div></div>
      {sumState === 'loading' && <Skeleton rows={2} />}
      {sumState === 'error' && <ErrorState title={t('adm.error.loadTitle', undefined, locale)} text={t('adm.error.loadText', undefined, locale)} onRetry={() => { void reloadSum() }} locale={locale} />}
      {sumState === 'ready' && sum && <>
        <div className="adm-stats" data-testid="security-kpis">
          <Stat label={t('adm.security.kpi.failedLogins', undefined, locale)} value={sum.failedAdminSignIns24h} tone={sum.failedAdminSignIns24h > 0 ? 'amber' : undefined} />
          <Stat label={t('adm.sec.kpi.failedSignIns', undefined, locale)} value={sum.failedSignIns24h} />
          <Stat label={t('adm.sec.kpi.failedCodes', undefined, locale)} value={sum.failedCodes24h} />
          <Stat label={t('adm.sec.kpi.blocked', undefined, locale)} value={blocked} tone={blocked ? 'red' : undefined} />
          <Stat label={t('adm.sec.kpi.permChanges', undefined, locale)} value={sum.permissionChanges7d} />
          <Stat label={t('adm.security.kpi.mfa', undefined, locale)} value={`${sum.adminMfa.enrolled}/${sum.adminMfa.total}`} tone={sum.adminMfa.total > 0 && sum.adminMfa.enrolled === sum.adminMfa.total ? 'green' : 'amber'} />
        </div>
        <div className="db-grid db-grid--2">
          <Card title={t('adm.security.alerts', undefined, locale)} tone={sum.alerts.length ? 'warn' : undefined}>
            {sum.alerts.length === 0 ? <p className="db-muted" data-testid="security-no-alerts">{t('adm.sec.noAlerts', undefined, locale)}</p>
              : <ul className="db-list" data-testid="security-alerts">{sum.alerts.map((x) => <li key={x.ip}><StatusPill status="high" prefix="adm.security.sev" dot /> <span style={{ flex: 1 }}>{t('adm.sec.alert.repeated', { failures: x.failures, minutes: x.windowMinutes, ip: x.ip }, locale)}</span><small className="db-muted">{fmtDateTime(x.lastAt, locale)}</small></li>)}</ul>}
          </Card>
          <Card title={t('adm.sec.accounts', undefined, locale)}>
            <Details rows={[[t('adm.sec.blocked.admin', undefined, locale), sum.blockedAccounts.admin], [t('adm.sec.blocked.restaurant', undefined, locale), sum.blockedAccounts.restaurant], [t('adm.sec.blocked.customer', undefined, locale), sum.blockedAccounts.customer], [t('adm.sec.statusChanges', undefined, locale), sum.accountStatusChanges7d], [t('adm.security.kpi.mfa', undefined, locale), t('adm.security.mfaNote', { enrolled: sum.adminMfa.enrolled, total: sum.adminMfa.total }, locale)]]} />
          </Card>
        </div>
      </>}
      <Card title={t('adm.security.events', undefined, locale)}>
        <Toolbar locale={locale}>
          <Select label={t('adm.sec.col.event', undefined, locale)} value={s.event} onChange={(event) => set({ event, page: '1' })} options={[{ value: 'all', label: t('adm.sec.event.all', undefined, locale) }, ...types.map((x) => ({ value: x, label: eventLabel(x) }))]} testId="filter-event" />
          <Select label={t('adm.sec.col.account', undefined, locale)} value={s.account} onChange={(account) => set({ account, page: '1' })} options={[{ value: 'all', label: t('adm.sec.accountType.all', undefined, locale) }, ...ACCOUNT_TYPES.map((x) => ({ value: x, label: t(`adm.sec.accountType.${x}`, undefined, locale) }))]} testId="filter-account" />
          <label className={`db-chip ${s.failed === '1' ? 'is-on' : ''}`}><input type="checkbox" checked={s.failed === '1'} onChange={(e) => set({ failed: e.target.checked ? '1' : '', page: '1' })} data-testid="filter-failed" /> {t('adm.sec.failedOnly', undefined, locale)}</label>
          <label className="adm-select"><span className="db-sr-only">{t('adm.audit.from', undefined, locale)}</span><input type="date" className="db-input db-input--sm" value={s.from} onChange={(e) => set({ from: e.target.value, page: '1' })} aria-label={t('adm.audit.from', undefined, locale)} data-testid="filter-from" /></label>
          <label className="adm-select"><span className="db-sr-only">{t('adm.audit.to', undefined, locale)}</span><input type="date" className="db-input db-input--sm" value={s.to} onChange={(e) => set({ to: e.target.value, page: '1' })} aria-label={t('adm.audit.to', undefined, locale)} data-testid="filter-to" /></label>
        </Toolbar>
        <DataTable columns={columns} rows={data?.items ?? []} keyOf={(e) => e.id} state={state} total={data?.total ?? 0} page={filter.page!} pageSize={15} onPage={(page) => set({ page: String(page) })} empty={{ icon: 'security', title: t('adm.sec.empty', undefined, locale) }} onRetry={() => { void reload() }} locale={locale} testId="security-events" caption={t('adm.security.events', undefined, locale)} onRowClick={setOpen} actions={(e) => <button type="button" className="db-btn db-btn--ghost db-btn--sm" onClick={() => setOpen(e)}>{t('adm.table.view', undefined, locale)}</button>} />
      </Card>
      <DevNote>{t('adm.sec.note', undefined, locale)}</DevNote>
      <Drawer open={!!open} onClose={() => setOpen(null)} title={t('adm.sec.detail', undefined, locale)}>{open && <div className="db-grid" style={{ gap: 14 }} data-testid="security-drawer">
        <Details rows={[
          [t('adm.security.col.time', undefined, locale), fmtDateTime(open.at, locale)], [t('adm.sec.col.event', undefined, locale), eventLabel(open.event)],
          [t('adm.security.col.severity', undefined, locale), <StatusPill key="s" status={open.severity} prefix="adm.security.sev" dot />],
          [t('adm.sec.col.account', undefined, locale), `${account(open)}${open.accountType ? ` (${t(`adm.sec.accountType.${open.accountType}`, undefined, locale)})` : ''}`],
          [t('adm.security.col.ip', undefined, locale), <span key="ip" className="adm-code">{open.ip ?? '—'}</span>], [t('adm.sec.device', undefined, locale), open.userAgent ?? '—'],
          [t('adm.audit.requestId', undefined, locale), <span key="rid" className="adm-code">{open.requestId ?? '—'}</span>],
        ]} />
        <div><b>{t('adm.security.col.detail', undefined, locale)}</b><pre className="adm-json">{JSON.stringify(open.details, null, 2)}</pre></div>
        <DevNote>{t('adm.sec.note', undefined, locale)}</DevNote>
      </div>}</Drawer>
    </div>
  )
}
