import { useMemo } from 'react'
import { Tabs } from '../../dashboard/components/ui'
import { t } from '../../i18n/strings'
import { useAdmin } from '../AdminContext'
import { ApiAdminFinanceRepository } from '../api/ApiAdminFinanceRepository'
import { useUrlState } from '../components/DataTable'
import type { AdminPermission } from '../types'
import { AdjustmentsTab } from './finance/AdjustmentsTab'
import { CommissionTab } from './finance/CommissionTab'
import { LedgerTab } from './finance/LedgerTab'
import { OverviewTab } from './finance/OverviewTab'
import { PayablesTab } from './finance/PayablesTab'
import { PayoutsTab } from './finance/PayoutsTab'
import { ReconciliationTab } from './finance/ReconciliationTab'
import { ReportsTab } from './finance/ReportsTab'
import { SettlementsTab } from './finance/SettlementsTab'
import { FINANCE_DEFAULTS } from './finance/helpers'
import { usePageTitle } from './shared'

/**
 * Finance on the backend (Module 36) — what the Settlements entry of the sidebar opens when the platform runs on the
 * backend: the overview, restaurant settlements, payouts, payables, commission plans, reconciliation, adjustments, holds,
 * orders awaiting terms, reports and the ledger (read-only). No development rows.
 *
 * A tab exists only for a permission the administrator holds (commission terms are confidential and have their own);
 * an action exists only when the backend's `can` says so. Every figure is the backend's, per currency. LIVE PAYOUTS
 * ARE DISABLED, and nowhere on this page can a settlement or a payout be set to paid by hand.
 */
type FinanceTab = 'overview' | 'settlements' | 'payouts' | 'payables' | 'commission' | 'reconciliation' | 'adjustments' | 'reports' | 'ledger'
const TABS: Array<{ id: FinanceTab; perm: AdminPermission }> = [
  { id: 'overview', perm: 'finance.view' }, { id: 'settlements', perm: 'settlements.view' }, { id: 'payouts', perm: 'finance.payouts.view' }, { id: 'payables', perm: 'finance.view' },
  { id: 'commission', perm: 'finance.commission.view' }, { id: 'reconciliation', perm: 'finance.reconciliation.view' }, { id: 'adjustments', perm: 'finance.view' },
  { id: 'reports', perm: 'finance.view' }, { id: 'ledger', perm: 'finance.ledger.view' },
]

export function BackendFinancePage({ repository }: { repository?: ApiAdminFinanceRepository }) {
  const a = useAdmin(); const locale = a.locale; usePageTitle('adm.finance.title')
  const repo = useMemo(() => repository ?? new ApiAdminFinanceRepository(), [repository])
  const [s, set] = useUrlState(FINANCE_DEFAULTS)
  const tabs = TABS.filter((x) => a.can(x.perm))
  const tab = tabs.find((x) => x.id === s.tab)?.id ?? tabs[0]?.id
  const props = { repo, s, set }
  return (
    <div className="db-page" data-testid="adm-finance" data-source="backend" data-tab={tab}>
      <div className="db-page__head"><div><h1 className="db-page__title">{t('adm.finance.title', undefined, locale)}</h1><p className="db-page__lead">{t('adm.finance.lead', undefined, locale)}</p></div></div>
      <Tabs tabs={tabs.map((x) => ({ id: x.id, label: t(`adm.finance.tab.${x.id}`, undefined, locale) }))} value={tab ?? 'settlements'} onChange={(id) => set({ tab: id, view: '', queue: 'all', page: '1', open: '' })} label={t('adm.finance.tabs', undefined, locale)} testId="fin-tab" />
      {tab === 'overview' && <OverviewTab {...props} />}
      {tab === 'settlements' && <SettlementsTab {...props} />}
      {tab === 'payouts' && <PayoutsTab {...props} />}
      {tab === 'payables' && <PayablesTab {...props} />}
      {tab === 'commission' && <CommissionTab {...props} />}
      {tab === 'reconciliation' && <ReconciliationTab {...props} />}
      {tab === 'adjustments' && <AdjustmentsTab {...props} />}
      {tab === 'reports' && <ReportsTab repo={repo} />}
      {tab === 'ledger' && <LedgerTab {...props} />}
    </div>
  )
}
