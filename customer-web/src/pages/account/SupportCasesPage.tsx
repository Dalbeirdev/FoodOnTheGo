import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '../../components/AccountStates'
import { BagIcon } from '../../components/Icons'
import { t, useLocale } from '../../i18n/strings'
import { categoryLabel, supportDay, supportErrorText } from '../../support/labels'
import type { SupportCaseSummary, SupportListState, SupportRepository } from '../../support/repositories'
import { SupportOffline, SupportShell, SupportStatusChip } from '../../support/SupportShell'
import { caseLink, newCaseLink, useSupport } from '../../support/supportRepositories'

/**
 * My support cases (Module 37): the signed-in customer's own requests, newest first — the case number, what it is
 * about, where it stands and whether support wrote something that was not opened yet. Whose cases these are is the
 * backend's decision (the token); the page sends no customer, and shows no priority, queue or response target.
 */
const PAGE_SIZE = 20

export default function SupportCasesPage({ repository }: { repository?: SupportRepository | null }) {
  const source = useSupport()
  const repo = repository !== undefined ? repository : source.repository
  const { locale } = useLocale()
  const [filter, setFilter] = useState<Exclude<SupportListState, 'all'>>('open')
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading')
  const [items, setItems] = useState<SupportCaseSummary[]>([])
  const [page, setPage] = useState({ page: 1, lastPage: 1 })
  const [more, setMore] = useState<'idle' | 'busy'>('idle')
  const [moreError, setMoreError] = useState<string | null>(null)
  const [reload, setReload] = useState(0)
  const seq = useRef(0)
  useEffect(() => { document.title = `${t('sup.list.title', undefined, locale)} · FoodOnTheGo` }, [locale])
  useEffect(() => {
    if (!repo) return
    const run = ++seq.current
    repo.list({ state: filter, page: 1, pageSize: PAGE_SIZE })
      .then((answer) => { if (run === seq.current) { setItems(answer.items); setPage({ page: answer.page, lastPage: answer.lastPage }); setState('ready') } })
      .catch(() => { if (run === seq.current) setState('error') })
  }, [repo, filter, reload])
  const choose = (next: Exclude<SupportListState, 'all'>) => { if (next === filter) return; setState('loading'); setItems([]); setMoreError(null); setFilter(next) }
  const loadMore = async () => {
    if (!repo || more === 'busy') return
    const run = seq.current
    setMore('busy'); setMoreError(null)
    try {
      const answer = await repo.list({ state: filter, page: page.page + 1, pageSize: PAGE_SIZE })
      if (run !== seq.current) return
      // A case that moved between two reads is shown once.
      setItems((before) => [...before, ...answer.items.filter((c) => !before.some((b) => b.id === c.id))]); setPage({ page: answer.page, lastPage: answer.lastPage })
    } catch (e) { if (run === seq.current) setMoreError(supportErrorText(e, locale)) }
    finally { setMore('idle') }
  }

  return (
    <SupportShell title={t('sup.list.title', undefined, locale)} lead={t('sup.list.lead', undefined, locale)}
      actions={repo ? <Link to={newCaseLink()} className="btn btn--primary" data-testid="support-new-case">{t('sup.list.new', undefined, locale)}</Link> : undefined}>
      {!repo ? <SupportOffline locale={locale} testId="support-cases" /> : (
        <div data-testid="support-cases" data-source="backend" data-filter={filter} style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          <div className="sup-filters" role="group" aria-label={t('sup.list.filters', undefined, locale)}>
            {(['open', 'closed'] as const).map((f) => (
              <button key={f} type="button" className="sup-filter" aria-pressed={filter === f} onClick={() => choose(f)} data-testid={`support-filter-${f}`}>{t(`sup.list.filter.${f}`, undefined, locale)}</button>
            ))}
          </div>
          {state === 'loading' && <LoadingState label={t('sup.list.loading', undefined, locale)} />}
          {state === 'error' && <ErrorState message={t('sup.list.error', undefined, locale)} onRetry={() => { setState('loading'); setReload((n) => n + 1) }} />}
          {state === 'ready' && items.length === 0 && (
            <EmptyState icon={<BagIcon size={34} />} title={t(`sup.list.empty.${filter}.title`, undefined, locale)} text={t(`sup.list.empty.${filter}.text`, undefined, locale)}
              action={<Link to={newCaseLink()} className="btn btn--primary">{t('sup.list.new', undefined, locale)}</Link>} />
          )}
          {state === 'ready' && items.length > 0 && (
            <ul className="sup-list" data-testid="support-case-list">
              {items.map((c) => (
                <li key={c.id} data-testid="support-case-row" data-case={c.caseNumber} data-status={c.status}>
                  <Link to={caseLink(c.caseNumber)} className="sup-row">
                    <span className="sup-row__head">
                      <span className="sup-row__number">{c.unread && <span className="sup-dot" role="img" aria-label={t('sup.list.unread', undefined, locale)} data-testid="support-case-unread" />}{c.caseNumber}</span>
                      <SupportStatusChip status={c.status} locale={locale} testId="support-case-status" />
                    </span>
                    <span className="sup-row__subject" dir="auto">{c.subject}</span>
                    <span className="sup-row__meta">
                      <span>{categoryLabel(c.category, locale)}</span>
                      {c.createdAt && <span>{t('sup.list.opened', { date: supportDay(c.createdAt, locale) }, locale)}</span>}
                      {c.lastMessageAt && <span>{t('sup.list.lastMessage', { date: supportDay(c.lastMessageAt, locale) }, locale)}</span>}
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
          {state === 'ready' && page.page < page.lastPage && (
            <div className="sup-actions"><button type="button" className="btn btn--outline" disabled={more === 'busy'} onClick={() => { void loadMore() }} data-testid="support-more">{t('sup.list.more', undefined, locale)}</button></div>
          )}
          {moreError && <p className="sup-error" role="alert">{moreError}</p>}
        </div>
      )}
    </SupportShell>
  )
}
