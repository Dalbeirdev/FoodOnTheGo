/**
 * The support domain on System Status (Module 37) — GET /admin/system/support (needs admin.system.view).
 *
 * Cases by state, from the cases themselves, and the flags that say what is NOT built, configured or approved:
 * the SLA targets are internal and not contractual, attachments are disabled (no malware scanner), administrators get
 * no notifications yet, and a case can mark no payment verified, complete no refund and edit no settlement or payout.
 * Counts and states only — no case, no requester, no text. Nothing is green because it exists.
 */
import { Card, ErrorState, Skeleton } from '../../dashboard/components/ui'
import { t } from '../../i18n/strings'
import { ApiAdminSupportRepository, type SupportSystem } from '../api/ApiAdminSupportRepository'
import { Badge, Stat } from '../components/DataTable'
import { humanize } from './finance/helpers'
import { useLoad } from './shared'
import { deskLabel } from './support/desk'

const loadSupportSystem = () => new ApiAdminSupportRepository().system()

export function SupportMetricsCard({ locale, load = loadSupportSystem }: { locale: string; load?: () => Promise<SupportSystem> }) {
  const { data, state, reload } = useLoad(load, [load])
  const say = (key: string, p?: Record<string, string | number>) => t(`adm.desk.sys.${key}`, p, locale)
  const yesNo = (v: boolean) => say(v ? 'yes' : 'no')
  const n = (v: number) => v.toLocaleString(locale)
  const minutes = (v: number | null) => (v === null ? say('notMeasured') : say('minutes', { n: v.toLocaleString(locale, { maximumFractionDigits: 1 }) }))
  const word = (v: string) => <span data-status={v}><Badge tone="muted">{humanize(v)}</Badge></span>
  return (
    <Card title={say('title')} subtitle={say('subtitle')} actions={data ? <span data-testid="sys-support-sla-contract"><Badge tone="muted">{say('contractualSla')}: {yesNo(data.flags.contractual_sla)}</Badge></span> : undefined}>
      {state === 'loading' && <Skeleton rows={2} />}
      {state === 'error' && <ErrorState title={say('unavailable')} onRetry={() => { void reload() }} locale={locale} />}
      {state === 'ready' && data && <>
        <div className="adm-stats" data-testid="sys-support" data-open={data.cases.open} data-attachments={data.flags.attachments} data-contractual-sla={data.flags.contractual_sla ? 'true' : 'false'} data-production={data.flags.production}>
          <Stat label={say('open')} value={<span data-testid="sys-support-open">{n(data.cases.open)}</span>} />
          <Stat label={say('unassigned')} value={<span data-testid="sys-support-unassigned">{n(data.cases.unassigned)}</span>} tone={data.cases.unassigned > 0 ? 'amber' : undefined} />
          <Stat label={say('escalated')} value={<span data-testid="sys-support-escalated">{n(data.cases.escalated)}</span>} tone={data.cases.escalated > 0 ? 'amber' : undefined} />
          <Stat label={say('restricted')} value={<span data-testid="sys-support-restricted">{n(data.cases.restricted)}</span>} />
          <Stat label={say('slaAtRisk')} value={<span data-testid="sys-support-sla-at-risk">{n(data.cases.sla_at_risk)}</span>} tone={data.cases.sla_at_risk > 0 ? 'amber' : undefined} />
          <Stat label={say('slaBreached')} value={<span data-testid="sys-support-sla-breached">{n(data.cases.sla_breached)}</span>} tone={data.cases.sla_breached > 0 ? 'red' : undefined} />
        </div>
        <div className="adm-stats" data-testid="sys-support-totals">
          <Stat label={say('total')} value={<span data-testid="sys-support-total">{n(data.cases.total)}</span>} />
          <Stat label={say('created7d')} value={n(data.cases.created_last_7_days)} />
          <Stat label={say('slaNotMeasured')} value={n(data.cases.sla_not_measured)} />
          <Stat label={say('firstResponse')} value={<span data-testid="sys-support-first-response">{minutes(data.cases.first_response_minutes_avg_30d)}</span>} />
          <Stat label={say('resolution')} value={minutes(data.cases.resolution_minutes_avg_30d)} />
        </div>
        <dl className="adm-details" data-testid="sys-support-by-status" aria-label={say('byStatus')}>
          {Object.entries(data.cases.by_status ?? {}).map(([status, count]) => <div key={status}><dt>{deskLabel('status', status, locale)}</dt><dd data-status={status}>{n(count)}</dd></div>)}
        </dl>
        <dl className="adm-details" data-testid="sys-support-flags">
          <div><dt>{say('contractualSla')}</dt><dd data-testid="sys-support-contractual-sla">{yesNo(data.flags.contractual_sla)}</dd></div>
          <div><dt>{say('attachments')}</dt><dd data-testid="sys-support-attachments">{word(data.flags.attachments)}</dd></div>
          <div><dt>{say('scanner')}</dt><dd data-testid="sys-support-scanner">{word(data.flags.attachment_scanner)}</dd></div>
          <div><dt>{say('restaurantNotifications')}</dt><dd data-testid="sys-support-restaurant-notifications">{word(data.flags.restaurant_notifications)}</dd></div>
          <div><dt>{say('adminNotifications')}</dt><dd data-testid="sys-support-admin-notifications">{word(data.flags.admin_notifications)}</dd></div>
          <div><dt>{say('securityRole')}</dt><dd data-testid="sys-support-security-role">{word(data.flags.security_admin_role)}</dd></div>
          <div><dt>{say('marksPayment')}</dt><dd data-testid="sys-support-marks-payment">{yesNo(data.flags.support_marks_payment_verified)}</dd></div>
          <div><dt>{say('marksRefund')}</dt><dd data-testid="sys-support-marks-refund">{yesNo(data.flags.support_marks_refund_completed)}</dd></div>
          <div><dt>{say('editsSettlement')}</dt><dd data-testid="sys-support-edits-settlement">{yesNo(data.flags.support_edits_settlement_or_payout)}</dd></div>
          <div><dt>{say('realtime')}</dt><dd data-testid="sys-support-realtime">{word(data.flags.realtime)}</dd></div>
          <div><dt>{say('production')}</dt><dd data-testid="sys-support-production">{data.flags.production}</dd></div>
        </dl>
        <dl className="adm-details" data-testid="sys-support-policies" aria-label={say('policies')}>
          {Object.entries(data.policies ?? {}).map(([name, p]) => <div key={name}><dt>{humanize(name)}</dt><dd data-policy={name}>{p.approval}</dd></div>)}
        </dl>
        <p className="db-muted" data-testid="sys-support-note">{say('note')}</p>
      </>}
    </Card>
  )
}
