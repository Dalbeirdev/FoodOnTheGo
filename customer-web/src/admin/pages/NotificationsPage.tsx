import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { t } from '../../i18n/strings'
import { Card, Drawer, EmptyState, ErrorState, Field, Icon, Skeleton, Tabs, ToastLine, useToastMessage } from '../../dashboard/components/ui'
import { useAdmin } from '../AdminContext'
import { Badge, DevNote } from '../components/DataTable'
import type { AdminNotificationDetail, Announcement, NotificationDeliveryRow } from '../types'
import { StatusPill, fmtDateTime, KNOWN_MARKET_CODES, useLoad, usePageTitle } from './shared'
import { userMessage } from '../../api/userMessage'

type Tab = 'alerts' | 'announcements' | 'templates' | 'delivery'
const blank = (): Omit<Announcement, 'id' | 'createdAt'> & { id?: string } => ({ title: '', audience: 'customers', markets: [], channel: 'push', status: 'DRAFT', scheduledAt: null })
const STATUSES = ['all', 'QUEUED', 'SENDING', 'SENT', 'RETRY_SCHEDULED', 'FAILED', 'INVALID_DESTINATION', 'SKIPPED', 'CANCELLED']
const CHANNELS = ['all', 'IN_APP', 'PUSH', 'EMAIL', 'SMS']
const TONE: Record<string, string> = { SENT: 'green', DELIVERED: 'green', QUEUED: 'blue', SENDING: 'blue', RETRY_SCHEDULED: 'amber', FAILED: 'red', INVALID_DESTINATION: 'red', SKIPPED: 'muted', CANCELLED: 'muted' }

/**
 * Notifications (Module 18 shell): critical alerts and announcements stay development data; with the backend (Module 33)
 * the Delivery tab is the real diagnostics — every customer notification with its deliveries and attempts (never a
 * token, an address or a phone), a controlled resend of a failed delivery — and the Templates tab is the registry.
 */
export default function NotificationsPage() {
  const a = useAdmin(); const locale = a.locale; usePageTitle('adm.nav.notifications')
  const [tab, setTab] = useState<Tab>('alerts'); const [form, setForm] = useState<ReturnType<typeof blank> | null>(null); const [busy, setBusy] = useState(false); const [err, setErr] = useState<string | null>(null); const { msg, toast } = useToastMessage()
  const ann = useLoad(() => a.repos.notifications.announcements(), [a.repos]); const tpl = useLoad(() => a.repos.notifications.templates(), [a.repos])
  const canManage = a.can('notifications.manage')
  const backend = a.repos.notifications.deliveriesAreBackend === true
  const save = async () => { if (!form) return; setBusy(true); setErr(null); try { await a.repos.notifications.saveAnnouncement(form, a.admin.id); setForm(null); await ann.refresh(); toast(t('adm.saved', undefined, locale)) } catch (e) { setErr(userMessage(e, 'error')) } finally { setBusy(false) } }
  return (
    <div className="db-page" data-testid="adm-notifications">
      <div className="db-page__head"><div><h1 className="db-page__title">{t('adm.notifications.title', undefined, locale)}</h1><p className="db-page__lead">{t('adm.notifications.lead', undefined, locale)}</p></div>{canManage && <button type="button" className="db-btn db-btn--primary" onClick={() => { setErr(null); setForm(blank()) }}>{t('adm.notifications.new', undefined, locale)}</button>}</div>
      <Tabs tabs={(['alerts', 'announcements', 'templates', 'delivery'] as Tab[]).map((id) => ({ id, label: t(`adm.notifications.tab.${id}`, undefined, locale), count: id === 'alerts' ? a.unreadCount : undefined }))} value={tab} onChange={(v) => setTab(v as Tab)} label={t('adm.notifications.tabs', undefined, locale)} />
      {tab === 'alerts' && <Card actions={a.unreadCount > 0 ? <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { void a.markAllRead() }}>{t('dash.notifications.markAll', undefined, locale)}</button> : undefined}>
        {a.alerts.length === 0 ? <EmptyState icon="notifications" title={t('adm.notifications.alertsEmpty', undefined, locale)} /> : <ul className="adm-activity" data-testid="alerts-list">{a.alerts.map((n) => <li key={n.id} className={n.read ? '' : 'is-unread'}><Badge tone={n.severity === 'critical' ? 'red' : n.severity === 'warning' ? 'amber' : 'blue'}>{n.severity}</Badge> <b>{n.title}</b> <span className="db-muted">{n.body}</span> <small>{fmtDateTime(n.at, locale)}</small>{n.link && <Link to={n.link}>{t('adm.open', undefined, locale)}</Link>}</li>)}</ul>}
        {backend && <DevNote>{t('adm.notifications.alertsDevNote', undefined, locale)}</DevNote>}
      </Card>}
      {tab === 'announcements' && <Card>
        {ann.state === 'loading' && <Skeleton rows={4} />}{ann.state === 'error' && <ErrorState title={t('adm.error.loadTitle', undefined, locale)} onRetry={() => { void ann.reload() }} locale={locale} />}
        {ann.state === 'ready' && (ann.data?.length ? <div className="db-table-wrap" tabIndex={0}><table className="db-table" data-testid="announcements-table"><caption className="db-sr-only">{t('adm.notifications.tab.announcements', undefined, locale)}</caption><thead><tr><th>{t('adm.notifications.col.title', undefined, locale)}</th><th>{t('adm.notifications.col.audience', undefined, locale)}</th><th>{t('adm.notifications.col.markets', undefined, locale)}</th><th>{t('adm.notifications.col.channel', undefined, locale)}</th><th>{t('adm.notifications.col.status', undefined, locale)}</th><th>{t('adm.notifications.col.scheduled', undefined, locale)}</th></tr></thead>
          <tbody>{ann.data.map((x) => <tr key={x.id}><td className="adm-td--primary" dir="auto">{x.title}</td><td>{t(`adm.notifications.audience.${x.audience}`, undefined, locale)}</td><td>{x.markets.length ? x.markets.join(', ') : t('adm.notifications.allMarkets', undefined, locale)}</td><td>{t(`adm.notifications.channel.${x.channel}`, undefined, locale)}</td><td><StatusPill status={x.status} prefix="adm.notifications.annStatus" /></td><td>{fmtDateTime(x.scheduledAt, locale)}</td></tr>)}</tbody></table></div> : <EmptyState icon="notifications" title={t('adm.notifications.tab.announcements', undefined, locale)} />)}
        <DevNote>{t('adm.notifications.sendNote', undefined, locale)}</DevNote>
      </Card>}
      {tab === 'templates' && (backend ? <RegistryTab /> : <Card>{tpl.state !== 'ready' ? <Skeleton rows={4} /> : <div className="db-table-wrap" tabIndex={0}><table className="db-table" data-testid="templates-table"><caption className="db-sr-only">{t('adm.notifications.tab.templates', undefined, locale)}</caption><thead><tr><th>{t('adm.notifications.tplCol.key', undefined, locale)}</th><th>{t('adm.notifications.tplCol.channel', undefined, locale)}</th><th>{t('adm.notifications.tplCol.audience', undefined, locale)}</th><th>{t('adm.notifications.tplCol.locales', undefined, locale)}</th><th>{t('adm.notifications.tplCol.status', undefined, locale)}</th></tr></thead><tbody>{(tpl.data ?? []).map((x) => <tr key={x.id}><td className="adm-td--primary">{x.key}</td><td>{t(`adm.notifications.channel.${x.channel}`, undefined, locale)}</td><td>{x.audience}</td><td>{x.locales.join(', ')}</td><td><Badge tone={x.status === 'ACTIVE' ? 'green' : 'muted'}>{x.status}</Badge></td></tr>)}</tbody></table></div>}</Card>)}
      {tab === 'delivery' && (backend ? <DeliveryTab canManage={canManage} /> : <Card><EmptyState icon="notifications" title={t('adm.notifications.tab.delivery', undefined, locale)} text={t('adm.notifications.deliveryNote', undefined, locale)} /></Card>)}
      <Drawer open={!!form} onClose={() => setForm(null)} title={form?.id ? t('adm.notifications.edit', undefined, locale) : t('adm.notifications.new', undefined, locale)} footer={<><button type="button" className="db-btn db-btn--outline" onClick={() => setForm(null)}>{t('adm.cancel', undefined, locale)}</button><button type="button" className="db-btn db-btn--primary" disabled={busy} onClick={() => { void save() }}>{t('adm.save', undefined, locale)}</button></>}>
        {form && <form className="db-grid" style={{ gap: 12 }} onSubmit={(e) => { e.preventDefault(); void save() }}>
          <Field label={t('adm.notifications.f.title', undefined, locale)} required error={err ? t('adm.promotions.err.required', undefined, locale) : undefined} id="an-title"><input id="an-title" className="db-input" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} /></Field>
          <div className="db-form-row"><Field label={t('adm.notifications.f.audience', undefined, locale)} id="an-aud"><select id="an-aud" className="db-select" value={form.audience} onChange={(e) => setForm({ ...form, audience: e.target.value as Announcement['audience'] })}>{(['customers', 'restaurants', 'admins'] as const).map((x) => <option key={x} value={x}>{t(`adm.notifications.audience.${x}`, undefined, locale)}</option>)}</select></Field><Field label={t('adm.notifications.f.channel', undefined, locale)} id="an-ch"><select id="an-ch" className="db-select" value={form.channel} onChange={(e) => setForm({ ...form, channel: e.target.value as Announcement['channel'] })}>{(['push', 'email', 'sms', 'in_app'] as const).map((x) => <option key={x} value={x}>{t(`adm.notifications.channel.${x}`, undefined, locale)}</option>)}</select></Field></div>
          <Field label={t('adm.notifications.f.markets', undefined, locale)} id="an-mk"><div className="db-tags">{KNOWN_MARKET_CODES.map((c) => <label key={c} className="db-chip" style={{ display: 'inline-flex', gap: 6 }}><input type="checkbox" checked={form.markets.includes(c)} onChange={(e) => setForm({ ...form, markets: e.target.checked ? [...form.markets, c] : form.markets.filter((m) => m !== c) })} /> {c}</label>)}</div></Field>
          <div className="db-form-row"><Field label={t('adm.notifications.f.status', undefined, locale)} id="an-st"><select id="an-st" className="db-select" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value as Announcement['status'] })}>{(['DRAFT', 'SCHEDULED', 'SENT'] as const).map((x) => <option key={x} value={x}>{t(`adm.notifications.annStatus.${x}`, undefined, locale)}</option>)}</select></Field><Field label={t('adm.notifications.f.scheduledAt', undefined, locale)} id="an-at"><input id="an-at" className="db-input" type="datetime-local" value={form.scheduledAt ? form.scheduledAt.slice(0, 16) : ''} onChange={(e) => setForm({ ...form, scheduledAt: e.target.value ? new Date(e.target.value).toISOString() : null })} /></Field></div>
        </form>}
      </Drawer>
      <ToastLine msg={msg} />
    </div>
  )
}

/** The registry as the backend applies it (GET /admin/notifications/templates). */
function RegistryTab() {
  const a = useAdmin(); const locale = a.locale
  const reg = useLoad(() => a.repos.notifications.registry!(), [a.repos])
  return (
    <Card>
      <p className="db-muted" style={{ marginBottom: 10 }}>{t('adm.notifications.registry.lead', undefined, locale)}</p>
      {reg.state === 'loading' && <Skeleton rows={5} />}
      {reg.state === 'error' && <ErrorState title={t('adm.error.loadTitle', undefined, locale)} onRetry={() => { void reg.reload() }} locale={locale} />}
      {reg.state === 'ready' && reg.data && <div className="db-table-wrap" tabIndex={0}><table className="db-table" data-testid="registry-table"><caption className="db-sr-only">{t('adm.notifications.tab.templates', undefined, locale)}</caption>
        <thead><tr><th>{t('adm.notifications.registry.col.type', undefined, locale)}</th><th>{t('adm.notifications.registry.col.category', undefined, locale)}</th><th>{t('adm.notifications.registry.col.events', undefined, locale)}</th><th>{t('adm.notifications.registry.col.channels', undefined, locale)}</th><th>{t('adm.notifications.registry.col.priority', undefined, locale)}</th><th>{t('adm.notifications.registry.col.version', undefined, locale)}</th><th>{t('adm.notifications.registry.col.locales', undefined, locale)}</th></tr></thead>
        <tbody>{reg.data.types.map((x) => <tr key={x.type} data-type={x.type}><td className="adm-td--primary">{x.type}</td><td>{x.category}{!x.transactional && <> · <Badge tone="amber">marketing</Badge></>}</td><td className="db-muted">{x.events.join(', ') || '—'}</td><td>{Object.entries(x.channels).filter(([, rule]) => rule !== 'off').map(([ch, rule]) => <Badge key={ch} tone={rule === 'mandatory' ? 'green' : 'blue'}>{ch} · {rule}</Badge>)}</td><td>{x.priority}</td><td>v{x.templateVersion}</td><td>{x.locales.join(', ')}</td></tr>)}</tbody></table></div>}
      {reg.state === 'ready' && reg.data && <p className="db-muted" style={{ fontSize: '0.8rem', marginTop: 8 }}>Default locale {reg.data.defaultLocale} · variables: {reg.data.variables.join(', ')}</p>}
    </Card>
  )
}

/** Delivery diagnostics (GET /admin/notifications, …/{id}) with a controlled resend. */
function DeliveryTab({ canManage }: { canManage: boolean }) {
  const a = useAdmin(); const locale = a.locale; const { msg, toast } = useToastMessage()
  const [q, setQ] = useState(''); const [status, setStatus] = useState('all'); const [channel, setChannel] = useState('all'); const [page, setPage] = useState(1)
  const [applied, setApplied] = useState({ q: '', status: 'all', channel: 'all' })
  const list = useLoad(() => a.repos.notifications.notifications!({ q: applied.q || undefined, status: applied.status, channel: applied.channel, page, pageSize: 20 }), [a.repos, applied, page])
  const [open, setOpen] = useState<AdminNotificationDetail | null>(null); const [detailState, setDetailState] = useState<'idle' | 'loading' | 'error'>('idle')
  const [retry, setRetry] = useState<NotificationDeliveryRow | null>(null); const [reason, setReason] = useState(''); const [busy, setBusy] = useState(false); const [err, setErr] = useState<string | null>(null)
  const show = useCallback(async (id: string) => { setDetailState('loading'); try { setOpen(await a.repos.notifications.notification!(id)); setDetailState('idle') } catch { setDetailState('error') } }, [a.repos])
  useEffect(() => { setPage(1) }, [applied])
  const doRetry = async () => {
    if (!retry || !open) return
    setBusy(true); setErr(null)
    try { await a.repos.notifications.retryDelivery!(retry.id, reason); setRetry(null); setReason(''); toast(t('adm.notifications.deliveries.retried', undefined, locale)); await show(open.id); await list.refresh() } catch (e) { setErr(userMessage(e, 'error')) } finally { setBusy(false) }
  }
  const summary = (s: Record<string, Record<string, number>>) => Object.entries(s).map(([ch, by]) => <span key={ch} style={{ marginRight: 6 }}><b>{ch}</b> {Object.entries(by).map(([st, n]) => <Badge key={st} tone={TONE[st] ?? 'muted'}>{st}{n > 1 ? ` ×${n}` : ''}</Badge>)}</span>)
  return (
    <>
      <Card>
        <p className="db-muted" style={{ marginBottom: 10 }}>{t('adm.notifications.deliveries.lead', undefined, locale)}</p>
        <form className="db-form-row" data-testid="ntf-filters" onSubmit={(e) => { e.preventDefault(); setApplied({ q: q.trim(), status, channel }) }}>
          <Field label={t('adm.notifications.deliveries.search', undefined, locale)} id="ntf-q"><input id="ntf-q" className="db-input" value={q} onChange={(e) => setQ(e.target.value)} placeholder="FOTG-… / id" /></Field>
          <Field label={t('adm.notifications.deliveries.status', undefined, locale)} id="ntf-status"><select id="ntf-status" className="db-select" value={status} onChange={(e) => setStatus(e.target.value)}>{STATUSES.map((s) => <option key={s} value={s}>{s === 'all' ? t('adm.notifications.deliveries.all', undefined, locale) : s}</option>)}</select></Field>
          <Field label={t('adm.notifications.deliveries.channel', undefined, locale)} id="ntf-channel"><select id="ntf-channel" className="db-select" value={channel} onChange={(e) => setChannel(e.target.value)}>{CHANNELS.map((s) => <option key={s} value={s}>{s === 'all' ? t('adm.notifications.deliveries.all', undefined, locale) : s}</option>)}</select></Field>
          <button type="submit" className="db-btn db-btn--outline" style={{ alignSelf: 'end' }}>{t('adm.notifications.deliveries.search', undefined, locale)}</button>
        </form>
        {list.state === 'loading' && <Skeleton rows={5} />}
        {list.state === 'error' && <ErrorState title={t('adm.error.loadTitle', undefined, locale)} onRetry={() => { void list.reload() }} locale={locale} />}
        {list.state === 'ready' && list.data && (list.data.items.length === 0 ? <EmptyState icon="notifications" title={t('adm.notifications.deliveries.empty', undefined, locale)} /> : (
          <div className="db-table-wrap" tabIndex={0}><table className="db-table" data-testid="ntf-table"><caption className="db-sr-only">{t('adm.notifications.tab.delivery', undefined, locale)}</caption>
            <thead><tr><th>{t('adm.notifications.deliveries.col.when', undefined, locale)}</th><th>{t('adm.notifications.deliveries.col.customer', undefined, locale)}</th><th>{t('adm.notifications.deliveries.col.type', undefined, locale)}</th><th>{t('adm.notifications.deliveries.col.title', undefined, locale)}</th><th>{t('adm.notifications.deliveries.col.summary', undefined, locale)}</th></tr></thead>
            <tbody>{list.data.items.map((n) => <tr key={n.id} data-testid="ntf-row" data-type={n.type} style={{ cursor: 'pointer' }} onClick={() => { void show(n.id) }}><td>{fmtDateTime(n.createdAt, locale)}</td><td>{n.customer?.display_name ?? '—'}</td><td>{n.type}{n.priority === 'HIGH' && <> <Badge tone="amber">HIGH</Badge></>}</td><td className="adm-td--primary" dir="auto">{n.title}</td><td>{summary(n.deliverySummary)}</td></tr>)}</tbody></table>
            <div className="db-pager"><span className="db-muted">{list.data.total} · {page}</span><button type="button" className="db-btn db-btn--outline db-btn--sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>‹</button><button type="button" className="db-btn db-btn--outline db-btn--sm" disabled={page * list.data.pageSize >= list.data.total} onClick={() => setPage(page + 1)}>›</button></div></div>
        ))}
      </Card>
      <Drawer open={open !== null || detailState === 'loading'} onClose={() => { setOpen(null); setRetry(null) }} title={t('adm.notifications.deliveries.detail', undefined, locale)}>
        {detailState === 'loading' && <Skeleton rows={6} />}
        {detailState === 'error' && <ErrorState title={t('adm.error.loadTitle', undefined, locale)} onRetry={() => undefined} locale={locale} />}
        {open && detailState === 'idle' && (
          <div className="db-grid" style={{ gap: 12 }} data-testid="ntf-detail" data-id={open.id}>
            <div><b dir="auto">{open.title}</b><p className="db-muted" dir="auto">{open.body}</p></div>
            <dl className="adm-dl">
              <dt>{t('adm.notifications.deliveries.col.customer', undefined, locale)}</dt><dd>{open.customer?.display_name ?? '—'}</dd>
              <dt>{t('adm.notifications.deliveries.col.type', undefined, locale)}</dt><dd>{open.type} · {open.category} · {open.priority}</dd>
              <dt>{t('adm.notifications.deliveries.template', undefined, locale)}</dt><dd>{open.template.key} v{open.template.version} · {open.template.locale}</dd>
              <dt>{t('adm.notifications.deliveries.dedupe', undefined, locale)}</dt><dd><code>{open.dedupeKey}</code></dd>
              <dt>{t('adm.notifications.col.status', undefined, locale)}</dt><dd>{open.read ? 'read' : 'unread'} · {open.action.target}{open.action.reference ? ` ${open.action.reference}` : ''}</dd>
            </dl>
            <ul className="adm-ntf-deliveries" data-testid="ntf-deliveries">
              {open.deliveries.map((d) => (
                <li key={d.id} data-testid="ntf-delivery" data-channel={d.channel} data-status={d.status}>
                  <div className="adm-ntf-delivery__head">
                    <b>{d.channel}</b>
                    <Badge tone={TONE[d.status] ?? 'muted'}>{d.status}</Badge>
                    {d.lastError && <Badge tone="red">{d.lastError.code}</Badge>}
                    {canManage && d.retryable && <button type="button" className="db-btn db-btn--outline db-btn--sm" data-testid="ntf-retry" onClick={() => { setRetry(d); setErr(null) }}>{t('adm.notifications.deliveries.retry', undefined, locale)}</button>}
                  </div>
                  <p className="db-muted adm-ntf-delivery__meta">{d.provider}{d.policyResult ? ` · ${d.policyResult}` : ''}{d.device ? ` · ${t('adm.notifications.deliveries.device', undefined, locale)} ${d.device.platform} (${d.device.tokenState})` : ''}</p>
                  <details><summary className="db-muted">{t('adm.notifications.deliveries.attempts', undefined, locale)} ({d.attempts.length})</summary>
                    {d.attempts.length === 0 ? <p className="db-muted">{t('adm.notifications.deliveries.noAttempts', undefined, locale)}</p> : <ul className="db-muted" style={{ fontSize: '0.85rem' }}>{d.attempts.map((x) => <li key={x.number}>#{x.number} {x.result}{x.providerCode ? ` · ${x.providerCode}` : ''}{x.errorCategory ? ` · ${x.errorCategory}` : ''}{x.latencyMs !== null ? ` · ${x.latencyMs} ms` : ''}{x.safeError ? ` — ${x.safeError}` : ''}</li>)}</ul>}
                  </details>
                </li>
              ))}
            </ul>
            {retry && <form className="db-grid" style={{ gap: 8 }} onSubmit={(e) => { e.preventDefault(); void doRetry() }} data-testid="ntf-retry-form">
              <Field label={t('adm.notifications.deliveries.retryReason', undefined, locale)} required id="ntf-reason" error={err ?? undefined}><input id="ntf-reason" className="db-input" value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
              <div><button type="submit" className="db-btn db-btn--primary db-btn--sm" disabled={busy || reason.trim().length < 3}><Icon name="check" size={16} /> {t('adm.notifications.deliveries.retry', undefined, locale)}</button></div>
            </form>}
          </div>
        )}
      </Drawer>
      <ToastLine msg={msg} />
    </>
  )
}
