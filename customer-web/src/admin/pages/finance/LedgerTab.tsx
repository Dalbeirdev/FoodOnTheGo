import { useState } from 'react'
import { Card, Drawer, Tabs } from '../../../dashboard/components/ui'
import { t } from '../../../i18n/strings'
import { useAdmin } from '../../AdminContext'
import { LEDGER_TRANSACTION_TYPES, type LedgerAccountDto, type LedgerTransactionDetailDto, type LedgerTransactionDto } from '../../api/ApiAdminFinanceRepository'
import { Badge, DataTable, Details, DevNote, Select, Toolbar, type Column } from '../../components/DataTable'
import { Cell, fmtDateTime, useLoad } from '../shared'
import { PAGE_SIZE, actionError, code, fm, type FinanceTabProps } from './helpers'
import { Facts } from './shared'

/**
 * The ledger (Module 36) — READ-ONLY, behind its own permission. Accounts with their debits, credits and balance, and
 * the transactions with their entries as debit and credit lines. Nothing here writes, edits or reverses an entry: the
 * backend posts every transaction itself, from the event that caused it. There is no form on this tab.
 */
export function LedgerTab({ repo, s, set }: FinanceTabProps) {
  const a = useAdmin(); const locale = a.locale
  const view = s.view === 'transactions' ? 'transactions' : 'accounts'; const page = Number(s.page) || 1
  const [type, setType] = useState('all'); const [reference, setReference] = useState(''); const [from, setFrom] = useState(''); const [to, setTo] = useState('')
  const accounts = useLoad(() => (view === 'accounts' ? repo.ledgerAccounts({ page, pageSize: PAGE_SIZE }) : Promise.resolve(null)), [repo, view, page])
  const transactions = useLoad(() => (view === 'transactions' ? repo.ledgerTransactions({ type, reference, from, to, page, pageSize: PAGE_SIZE }) : Promise.resolve(null)), [repo, view, type, reference, from, to, page])
  const [open, setOpen] = useState<LedgerTransactionDetailDto | null>(null); const [failed, setFailed] = useState<string | null>(null)
  const show = async (id: string) => { setFailed(null); try { setOpen(await repo.ledgerTransaction(id)) } catch (e) { setFailed(actionError(e, locale)) } }
  const first = () => set({ page: '1' })
  const owner = (x: LedgerAccountDto) => x.restaurant?.name ?? x.provider ?? code(x.owner, locale)
  const accountColumns: Array<Column<LedgerAccountDto>> = [
    { id: 'code', label: t('adm.finance.col.account', undefined, locale), primary: true, render: (x) => <Cell primary={code(x.code, locale)} secondary={x.code} /> },
    { id: 'owner', label: t('adm.finance.col.owner', undefined, locale), render: (x) => <Cell primary={owner(x)} secondary={code(x.owner, locale)} /> },
    { id: 'debits', label: t('adm.finance.col.debits', undefined, locale), align: 'end', hideMobile: true, render: (x) => fm(x.debits, locale) },
    { id: 'credits', label: t('adm.finance.col.credits', undefined, locale), align: 'end', hideMobile: true, render: (x) => fm(x.credits, locale) },
    { id: 'balance', label: t('adm.finance.col.balance', undefined, locale), align: 'end', render: (x) => <b>{fm(x.balance, locale)} <Badge tone="muted">{x.currency}</Badge></b> },
    { id: 'side', label: t('adm.finance.col.normalSide', undefined, locale), hideMobile: true, render: (x) => code(x.normal_side, locale) },
  ]
  const transactionColumns: Array<Column<LedgerTransactionDto>> = [
    { id: 'type', label: t('adm.finance.col.type', undefined, locale), primary: true, render: (x) => <Cell primary={code(x.type, locale)} secondary={x.source.reference ?? code(x.source.type, locale)} /> },
    { id: 'restaurant', label: t('adm.finance.col.restaurant', undefined, locale), hideMobile: true, render: (x) => <span dir="auto">{x.restaurant?.name ?? '—'}</span> },
    { id: 'amount', label: t('adm.finance.col.amount', undefined, locale), align: 'end', render: (x) => <b>{fm(x.amount, locale)} <Badge tone="muted">{x.currency}</Badge></b> },
    { id: 'occurred', label: t('adm.finance.col.occurred', undefined, locale), hideMobile: true, render: (x) => fmtDateTime(x.occurred_at, locale) },
    { id: 'posted', label: t('adm.finance.col.posted', undefined, locale), hideMobile: true, render: (x) => fmtDateTime(x.posted_at, locale) },
  ]
  return <>
    <Tabs tabs={[{ id: 'accounts', label: t('adm.finance.ledger.accounts', undefined, locale) }, { id: 'transactions', label: t('adm.finance.ledger.transactions', undefined, locale) }]} value={view} onChange={(v) => set({ view: v === 'transactions' ? 'transactions' : '', page: '1' })} label={t('adm.finance.ledger.views', undefined, locale)} testId="fin-view" />
    {view === 'accounts' && (
      <Card title={t('adm.finance.ledger.accounts', undefined, locale)} subtitle={t('adm.finance.ledger.accounts.subtitle', undefined, locale)}>
        <DataTable columns={accountColumns} rows={accounts.data?.items ?? []} keyOf={(x) => x.id} state={accounts.state} total={accounts.data?.total ?? 0} page={page} pageSize={PAGE_SIZE} onPage={(p) => set({ page: String(p) })} empty={{ icon: 'audit', title: t('adm.finance.ledger.accounts.empty', undefined, locale) }} onRetry={() => { void accounts.reload() }} locale={locale} testId="fin-ledger-accounts-table" caption={t('adm.finance.ledger.accounts', undefined, locale)}
          rowProps={(x) => ({ 'data-testid': 'fin-ledger-account-row', 'data-id': x.id, 'data-code': x.code, 'data-currency': x.currency })} />
      </Card>
    )}
    {view === 'transactions' && (
      <Card title={t('adm.finance.ledger.transactions', undefined, locale)} subtitle={t('adm.finance.ledger.transactions.subtitle', undefined, locale)}>
        <div data-testid="fin-ledger-filters">
          <Toolbar search={reference} onSearch={(v) => { setReference(v); first() }} placeholder={t('adm.finance.ledger.reference', undefined, locale)} locale={locale}>
            <Select label={t('adm.finance.col.type', undefined, locale)} value={type} onChange={(v) => { setType(v); first() }} options={[{ value: 'all', label: t('adm.status.all', undefined, locale) }, ...LEDGER_TRANSACTION_TYPES.map((x) => ({ value: x as string, label: code(x, locale) }))]} testId="fin-ledger-type" />
            <label className="adm-select"><span className="db-sr-only">{t('adm.finance.from', undefined, locale)}</span><input type="date" className="db-input db-input--sm" value={from} max={to || undefined} onChange={(e) => { setFrom(e.target.value); first() }} aria-label={t('adm.finance.from', undefined, locale)} data-testid="fin-ledger-from" /></label>
            <label className="adm-select"><span className="db-sr-only">{t('adm.finance.to', undefined, locale)}</span><input type="date" className="db-input db-input--sm" value={to} min={from || undefined} onChange={(e) => { setTo(e.target.value); first() }} aria-label={t('adm.finance.to', undefined, locale)} data-testid="fin-ledger-to" /></label>
          </Toolbar>
        </div>
        <DataTable columns={transactionColumns} rows={transactions.data?.items ?? []} keyOf={(x) => x.id} state={transactions.state} total={transactions.data?.total ?? 0} page={page} pageSize={PAGE_SIZE} onPage={(p) => set({ page: String(p) })} empty={{ icon: 'audit', title: t('adm.finance.ledger.transactions.empty', undefined, locale) }} onRetry={() => { void transactions.reload() }} locale={locale} testId="fin-ledger-transactions-table" caption={t('adm.finance.ledger.transactions', undefined, locale)}
          rowProps={(x) => ({ 'data-testid': 'fin-ledger-transaction-row', 'data-id': x.id, 'data-type': x.type, 'data-ref': x.source.reference ?? '' })}
          onRowClick={(x) => { void show(x.id) }} actions={(x) => <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { void show(x.id) }}>{t('adm.table.view', undefined, locale)}</button>} />
      </Card>
    )}
    <DevNote><span data-testid="fin-ledger-readonly">{t('adm.finance.ledger.note', undefined, locale)}</span></DevNote>
    {failed && !open && <p className="db-error" role="alert" data-testid="fin-error">{failed}</p>}

    <Drawer open={!!open} onClose={() => { setOpen(null); setFailed(null) }} title={open ? `${code(open.type, locale)}${open.source.reference ? ` · ${open.source.reference}` : ''}` : ''} wide>
      {open && <div className="db-grid" style={{ gap: 16 }} data-testid="fin-ledger-transaction" data-type={open.type} data-balanced={String(open.balanced)}>
        <Details rows={[
          [t('adm.finance.col.type', undefined, locale), code(open.type, locale)],
          [t('adm.finance.col.amount', undefined, locale), <b key="a">{fm(open.amount, locale)} ({open.currency})</b>],
          [t('adm.finance.ledger.balance', undefined, locale), <span key="b" data-testid="fin-ledger-balanced"><Badge tone={open.balanced ? 'green' : 'red'} dot>{t(open.balanced ? 'adm.finance.ledger.balanced' : 'adm.finance.ledger.notBalanced', undefined, locale)}</Badge></span>],
          [t('adm.finance.col.source', undefined, locale), `${code(open.source.type, locale)} · ${open.source.reference ?? open.source.id ?? '—'}`],
          [t('adm.finance.col.restaurant', undefined, locale), open.restaurant?.name ?? '—'],
          [t('adm.finance.col.occurred', undefined, locale), fmtDateTime(open.occurred_at, locale)],
          [t('adm.finance.col.posted', undefined, locale), fmtDateTime(open.posted_at, locale)],
          [t('adm.finance.ledger.postingKey', undefined, locale), <code key="k" className="adm-code">{open.posting_key}</code>],
          ...(open.reverses ? [[t('adm.finance.ledger.reverses', undefined, locale), open.reverses] as [string, React.ReactNode]] : []),
          ...(open.memo ? [[t('adm.finance.col.note', undefined, locale), open.memo] as [string, React.ReactNode]] : []),
        ]} />
        <Card title={t('adm.finance.ledger.entries', undefined, locale)}>
          <div className="db-table-wrap"><table className="db-table" data-testid="fin-ledger-entries">
            <thead><tr><th scope="col">#</th><th scope="col">{t('adm.finance.col.account', undefined, locale)}</th><th scope="col">{t('adm.finance.ledger.debit', undefined, locale)}</th><th scope="col">{t('adm.finance.ledger.credit', undefined, locale)}</th><th scope="col">{t('adm.finance.col.description', undefined, locale)}</th></tr></thead>
            <tbody>{open.entries.map((e) => (
              <tr key={e.line} data-testid="fin-ledger-entry" data-direction={e.direction}><td>{e.line}</td><td>{code(e.account.code, locale)}<small className="db-muted"> · {e.account.provider ?? code(e.account.owner, locale)}</small></td><td>{e.direction === 'DEBIT' ? fm(e.amount, locale) : ''}</td><td>{e.direction === 'CREDIT' ? fm(e.amount, locale) : ''}</td><td>{code(e.description, locale)}</td></tr>
            ))}</tbody>
          </table></div>
        </Card>
        <Card title={t('adm.finance.col.detail', undefined, locale)}><Facts value={open.detail} testId="fin-ledger-detail" /></Card>
      </div>}
    </Drawer>
  </>
}
