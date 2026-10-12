import { useState } from 'react'
import { Card } from '../../../dashboard/components/ui'
import { t } from '../../../i18n/strings'
import { useAdmin } from '../../AdminContext'
import type { PayableDto } from '../../api/ApiAdminFinanceRepository'
import { Badge, DataTable, DevNote, Toolbar, type Column } from '../../components/DataTable'
import { Cell, useLoad } from '../shared'
import { PAGE_SIZE, fm, type FinanceTabProps } from './helpers'
import { Chip } from './shared'

/**
 * Restaurant payables (Module 36): what the platform owes each restaurant location now, per currency, from the ledger.
 * Read-only — there is no balance to edit. A negative balance means the restaurant owes the platform: nothing is
 * collected automatically, and the row says that finance has to look.
 */
export function PayablesTab({ repo, s, set }: FinanceTabProps) {
  const a = useAdmin(); const locale = a.locale
  const page = Number(s.page) || 1; const [q, setQ] = useState('')
  const { data, state, reload } = useLoad(() => repo.payables({ query: q, page, pageSize: PAGE_SIZE }), [repo, q, page])
  const columns: Array<Column<PayableDto>> = [
    { id: 'restaurant', label: t('adm.finance.col.restaurant', undefined, locale), primary: true, render: (x) => <Cell primary={x.restaurant.name} secondary={<>{x.restaurant.organization} · <code className="adm-code">{x.restaurant.id}</code></>} /> },
    { id: 'market', label: t('adm.finance.col.market', undefined, locale), hideMobile: true, render: (x) => x.market ?? '—' },
    { id: 'balance', label: t('adm.finance.col.balance', undefined, locale), align: 'end', render: (x) => <b>{fm(x.balance, locale)} <Badge tone="muted">{x.currency}</Badge></b> },
    { id: 'available', label: t('adm.finance.col.available', undefined, locale), align: 'end', hideMobile: true, render: (x) => fm(x.available, locale) },
    { id: 'held', label: t('adm.finance.col.held', undefined, locale), align: 'end', hideMobile: true, render: (x) => fm(x.held, locale) },
    { id: 'in_settlement', label: t('adm.finance.col.inSettlement', undefined, locale), align: 'end', hideMobile: true, render: (x) => fm(x.in_settlement, locale) },
    { id: 'paid_out', label: t('adm.finance.col.paidOut', undefined, locale), align: 'end', hideMobile: true, render: (x) => fm(x.paid_out, locale) },
    { id: 'flags', label: t('adm.finance.col.flags', undefined, locale), render: (x) => <span data-flags={`${x.negative ? 'negative ' : ''}${x.location_held ? 'held' : ''}`.trim()}>{x.finance_review_required && <Badge tone="red">{t('adm.finance.flag.review', undefined, locale)}</Badge>}{x.location_held && <> <Badge tone="amber">{t('adm.finance.flag.held', undefined, locale)}</Badge></>}{!x.finance_review_required && !x.location_held && '—'}</span> },
    { id: 'destination', label: t('adm.finance.col.payoutDestination', undefined, locale), hideMobile: true, render: (x) => <span><Chip value={x.payout_destination.status} locale={locale} dot={false} />{x.payout_destination.destination ? ` ${x.payout_destination.destination}` : ''}{x.payout_destination.test_only ? <> <Badge tone="amber">{t('adm.finance.testOnly', undefined, locale)}</Badge></> : null}</span> },
  ]
  return <>
    <Card title={t('adm.finance.payables.title', undefined, locale)} subtitle={t('adm.finance.payables.subtitle', undefined, locale)}>
      <Toolbar search={q} onSearch={(v) => { setQ(v); set({ page: '1' }) }} placeholder={t('adm.finance.payables.search', undefined, locale)} locale={locale} />
      <DataTable columns={columns} rows={data?.items ?? []} keyOf={(x) => `${x.restaurant.id}:${x.currency}`} state={state} total={data?.total ?? 0} page={page} pageSize={PAGE_SIZE} onPage={(p) => set({ page: String(p) })} empty={{ icon: 'settlements', title: t('adm.finance.payables.empty', undefined, locale) }} onRetry={() => { void reload() }} locale={locale} testId="fin-payables-table" rowProps={(x) => ({ 'data-testid': 'fin-payable-row', 'data-id': x.restaurant.id, 'data-currency': x.currency, 'data-negative': String(x.negative) })} caption={t('adm.finance.payables.title', undefined, locale)} />
    </Card>
    <DevNote>{t('adm.finance.payables.note', undefined, locale)}</DevNote>
  </>
}
