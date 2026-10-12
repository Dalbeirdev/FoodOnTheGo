import { Card } from '../../../dashboard/components/ui'
import { t } from '../../../i18n/strings'
import { useAdmin } from '../../AdminContext'
import type { RunDto } from '../../api/ApiAdminFinanceRepository'
import { DataTable, type Column } from '../../components/DataTable'
import { fmtDateTime, useLoad } from '../shared'
import { PAGE_SIZE, code, type FinanceTabProps } from './helpers'
import { Chip } from './shared'

/** The record of finance jobs (Module 36): when the ledger was last checked, what a reconciliation or an import found. Read-only. */
export function RunsView({ repo, s, set }: FinanceTabProps) {
  const a = useAdmin(); const locale = a.locale; const page = Number(s.page) || 1
  const { data, state, reload } = useLoad(() => repo.runs({ page, pageSize: PAGE_SIZE }), [repo, page])
  const columns: Array<Column<RunDto>> = [
    { id: 'kind', label: t('adm.finance.run.kind', undefined, locale), primary: true, render: (r) => <span>{code(r.kind, locale)}{r.dry_run ? ` · ${t('adm.finance.run.dryRun', undefined, locale)}` : ''}</span> },
    { id: 'status', label: t('adm.finance.col.status', undefined, locale), render: (r) => <Chip value={r.status} locale={locale} /> },
    { id: 'problems', label: t('adm.finance.run.problems', undefined, locale), align: 'end', render: (r) => r.problems.toLocaleString(locale) },
    { id: 'by', label: t('adm.finance.run.by', undefined, locale), hideMobile: true, render: (r) => code(r.triggered_by, locale) },
    { id: 'started', label: t('adm.finance.run.started', undefined, locale), hideMobile: true, render: (r) => fmtDateTime(r.started_at, locale) },
    { id: 'finished', label: t('adm.finance.run.finished', undefined, locale), hideMobile: true, render: (r) => fmtDateTime(r.finished_at, locale) },
  ]
  return (
    <Card title={t('adm.finance.runs.title', undefined, locale)} subtitle={t('adm.finance.runs.subtitle', undefined, locale)}>
      <DataTable columns={columns} rows={data?.items ?? []} keyOf={(r) => r.id} state={state} total={data?.total ?? 0} page={page} pageSize={PAGE_SIZE} onPage={(p) => set({ page: String(p) })} empty={{ icon: 'audit', title: t('adm.finance.runs.empty', undefined, locale) }} onRetry={() => { void reload() }} locale={locale} testId="fin-runs-table" caption={t('adm.finance.runs.title', undefined, locale)}
        rowProps={(r) => ({ 'data-testid': 'fin-run-row', 'data-id': r.id, 'data-kind': r.kind, 'data-status': r.status })} />
    </Card>
  )
}
