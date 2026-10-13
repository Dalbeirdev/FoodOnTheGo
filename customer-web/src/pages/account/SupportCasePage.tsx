import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ErrorState, LoadingState } from '../../components/AccountStates'
import { t, useLocale } from '../../i18n/strings'
import { categoryLabel, linkStatusLabel, linkTypeLabel, refundStatusLabel, subcategoryLabel, supportDay, supportDayTime, supportMoney } from '../../support/labels'
import type { SupportCaseRepository, SupportLink } from '../../support/repositories'
import { SupportOffline, SupportShell, SupportStatusChip } from '../../support/SupportShell'
import { useSupport } from '../../support/supportRepositories'
import { useSupportCase } from '../../support/useSupportCase'

/**
 * One support case (Module 37): what was asked, where it stands, the records it is about and the conversation.
 *
 * - What can be done — reply, confirm that it is resolved, withdraw the request, reopen it — is exactly what the
 *   backend's `can` says; the page decides none of it and reads the case again after every action.
 * - A linked order, payment or refund shows the status its OWN record has now (read by the backend when the case is
 *   shown). Nothing about them is inferred from the case.
 * - Everything a person wrote is rendered as a text node (line breaks kept by CSS), never as markup.
 * - No realtime: while the case can still change it is read again every few seconds on a visible tab.
 * - No priority, queue, assignee, internal note or response target exists on this page: the backend sends none.
 */
export default function SupportCasePage({ repository, pollMs }: { repository?: SupportCaseRepository | null; pollMs?: number }) {
  const source = useSupport()
  const repo = repository !== undefined ? repository : source.repository
  const { caseId = '' } = useParams()
  const { locale } = useLocale()
  const thread = useSupportCase(repo, caseId || null, locale, { pollMs })
  const c = thread.case
  const [reply, setReply] = useState('')
  const [reopening, setReopening] = useState(false)
  const [reopenText, setReopenText] = useState('')
  useEffect(() => { document.title = `${c ? c.caseNumber : t('sup.case.title', undefined, locale)} · FoodOnTheGo` }, [c, locale])

  const send = async () => { if (await thread.sendReply(reply)) setReply('') }
  const reopen = async () => { if (await thread.reopen(reopenText)) { setReopenText(''); setReopening(false) } }
  const busy = thread.busy !== null
  const noteKey = c ? `sup.statusNote.${c.status}` : ''
  const note = c && t(noteKey, undefined, locale) !== noteKey ? t(noteKey, undefined, locale) : null

  return (
    <SupportShell title={t('sup.case.title', undefined, locale)} actions={<Link to="/help/cases" className="btn btn--outline">{t('sup.case.back', undefined, locale)}</Link>}>
      {!repo ? <SupportOffline locale={locale} testId="support-case" /> : (
        <>
          {thread.state === 'loading' && <LoadingState label={t('sup.case.loading', undefined, locale)} />}
          {thread.state === 'error' && <ErrorState message={t('sup.case.error', undefined, locale)} onRetry={thread.reload} />}
          {thread.state === 'not_found' && (
            <div className="ac-card" role="alert" data-testid="support-case-missing">
              <h2>{t('sup.case.notFound.title', undefined, locale)}</h2>
              <p className="sup-muted">{t('sup.case.notFound.text', { ref: caseId }, locale)}</p>
              <div className="sup-actions"><Link to="/help/cases" className="btn btn--primary">{t('sup.case.back', undefined, locale)}</Link></div>
            </div>
          )}
          {thread.state === 'ready' && c && (
            <div data-testid="support-case" data-source="backend" data-case={c.caseNumber} data-status={c.status} style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
              <section className="ac-card" aria-labelledby="sup-case-subject">
                <div className="sup-case__head">
                  <span className="sup-case__number" data-testid="support-case-number">{c.caseNumber}</span>
                  <SupportStatusChip status={c.status} locale={locale} testId="support-case-status" />
                </div>
                <h2 id="sup-case-subject" className="sup-case__subject" dir="auto" data-testid="support-case-subject">{c.subject}</h2>
                <p className="sup-muted" data-testid="support-case-category">
                  {categoryLabel(c.category, locale)}{c.subcategory ? ` · ${subcategoryLabel(c.subcategory, locale)}` : ''}{c.createdAt ? ` · ${t('sup.case.opened', { date: supportDay(c.createdAt, locale) }, locale)}` : ''}
                </p>
                {note && <p className="sup-muted" data-testid="support-case-note">{note}</p>}
              </section>

              {c.links.length > 0 && (
                <section className="ac-card" aria-labelledby="sup-links-title">
                  <h2 id="sup-links-title" className="sup-section-title">{t('sup.link.title', undefined, locale)}</h2>
                  <ul className="sup-links">{c.links.map((l) => <LinkCard key={l.id} link={l} locale={locale} />)}</ul>
                </section>
              )}

              <section className="ac-card" aria-labelledby="sup-thread-title">
                <h2 id="sup-thread-title" className="sup-section-title">{t('sup.case.conversation', undefined, locale)}</h2>
                {thread.hasEarlier && (
                  <div className="sup-actions" style={{ margin: '0 0 12px' }}>
                    <button type="button" className="btn btn--outline" disabled={thread.loadingEarlier} onClick={() => { void thread.loadEarlier() }} data-testid="support-earlier">{thread.loadingEarlier ? t('sup.case.earlierLoading', undefined, locale) : t('sup.case.earlier', undefined, locale)}</button>
                  </div>
                )}
                {thread.messages.length === 0 ? <p className="sup-muted">{t('sup.case.empty', undefined, locale)}</p> : (
                  <ol className="sup-thread" data-testid="support-messages">
                    {thread.messages.map((m) => (
                      <li key={m.id} className={`sup-msg sup-msg--${m.author}`} data-testid="support-message-item" data-author={m.author}>
                        <div className="sup-msg__meta"><b>{t(`sup.case.author.${m.author}`, undefined, locale)}</b>{m.createdAt && <time dateTime={m.createdAt}>{supportDayTime(m.createdAt, locale)}</time>}</div>
                        <p className="sup-msg__body" dir="auto" data-testid="support-message-body">{m.body}</p>
                      </li>
                    ))}
                  </ol>
                )}

                {c.can.reply && (
                  <form className="sup-reply" onSubmit={(e) => { e.preventDefault(); void send() }}>
                    <div className="sup-field">
                      <label htmlFor="sup-reply">{t('sup.case.reply.label', undefined, locale)}</label>
                      <textarea id="sup-reply" dir="auto" rows={4} value={reply} onChange={(e) => { setReply(e.target.value); if (thread.actionError) thread.clearError() }} placeholder={t('sup.case.reply.placeholder', undefined, locale)} data-testid="support-reply-input" />
                    </div>
                    <div className="sup-actions" style={{ marginTop: 0 }}>
                      <button type="submit" className="btn btn--primary" disabled={busy || reply.trim() === ''} data-testid="support-reply-send">{thread.busy === 'reply' ? t('sup.case.reply.sending', undefined, locale) : t('sup.case.reply.send', undefined, locale)}</button>
                    </div>
                  </form>
                )}
                {!c.can.reply && !c.can.reopen && c.status !== 'CLOSED' && c.status !== 'CANCELLED' && <p className="sup-muted">{t('sup.case.noReply', undefined, locale)}</p>}

                {(c.can.close || c.can.withdraw || c.can.reopen) && (
                  <div className="sup-reply" data-testid="support-actions">
                    {c.can.close && <p className="sup-muted" style={{ margin: 0 }}>{t('sup.case.confirm.hint', undefined, locale)}</p>}
                    {c.can.reopen && c.reopenUntil && <p className="sup-muted" style={{ margin: 0 }} data-testid="support-reopen-until">{t('sup.case.reopen.until', { date: supportDay(c.reopenUntil, locale) }, locale)}</p>}
                    {!c.can.close && c.can.withdraw && <p className="sup-muted" style={{ margin: 0 }}>{t('sup.case.withdraw.hint', undefined, locale)}</p>}
                    <div className="sup-actions" style={{ marginTop: 0 }}>
                      {c.can.close && <button type="button" className="btn btn--primary" disabled={busy} onClick={() => { void thread.close('CONFIRM') }} data-testid="support-confirm-resolved">{t('sup.case.confirm', undefined, locale)}</button>}
                      {c.can.reopen && !reopening && <button type="button" className="btn btn--outline" disabled={busy} onClick={() => { thread.clearError(); setReopening(true) }} data-testid="support-reopen">{t('sup.case.reopen', undefined, locale)}</button>}
                      {/* One call serves both: the backend confirms a resolved case and withdraws one that is being worked on. The button says which. */}
                      {!c.can.close && c.can.withdraw && <button type="button" className="btn btn--outline" disabled={busy} onClick={() => { void thread.close('WITHDRAW') }} data-testid="support-withdraw">{t('sup.case.withdraw', undefined, locale)}</button>}
                    </div>
                    {c.can.reopen && reopening && (
                      <form className="sup-field" onSubmit={(e) => { e.preventDefault(); void reopen() }}>
                        <label htmlFor="sup-reopen">{t('sup.case.reopen.label', undefined, locale)}</label>
                        <textarea id="sup-reopen" dir="auto" rows={3} value={reopenText} onChange={(e) => setReopenText(e.target.value)} data-testid="support-reopen-message" />
                        <div className="sup-actions" style={{ marginTop: 4 }}>
                          <button type="submit" className="btn btn--primary" disabled={busy || reopenText.trim() === ''} data-testid="support-reopen-submit">{thread.busy === 'reopen' ? t('sup.case.working', undefined, locale) : t('sup.case.reopen.submit', undefined, locale)}</button>
                          <button type="button" className="btn btn--outline" disabled={busy} onClick={() => setReopening(false)}>{t('sup.case.reopen.cancel', undefined, locale)}</button>
                        </div>
                      </form>
                    )}
                  </div>
                )}
                {thread.actionError && <p className="sup-error" role="alert" data-testid="support-action-error">{thread.actionError}</p>}
              </section>
            </div>
          )}
        </>
      )}
    </SupportShell>
  )
}

/** A linked record, read-only: its kind, its reference, the status its own record has, an amount — and the way to the order's own page. */
function LinkCard({ link: l, locale }: { link: SupportLink; locale: string }) {
  const status = linkStatusLabel(l, locale)
  const order = l.type === 'ORDER' ? l.reference : l.orderNumber
  return (
    <li data-testid="support-link" data-type={l.type} data-reference={l.reference} data-status={l.status ?? ''}>
      <span className="sup-links__top"><span>{linkTypeLabel(l.type, locale)}</span>{status && <span className="sup-chip sup-chip--closed" data-testid="support-link-status">{status}</span>}</span>
      <b>{l.reference}</b>
      {l.restaurant && <span dir="auto">{l.restaurant}</span>}
      {l.amount && <span data-testid="support-link-amount">{supportMoney(l.amount, locale)}</span>}
      {l.placedAt && <span>{supportDay(l.placedAt, locale)}</span>}
      {l.type === 'ORDER' && l.refundStatus && l.refundStatus !== 'NONE' && <span>{t('sup.link.refund', { status: refundStatusLabel(l.refundStatus, locale) }, locale)}</span>}
      {order && <Link to={`/order/${encodeURIComponent(order)}`} className="sup-link" data-testid="support-link-open">{t('sup.link.viewOrder', undefined, locale)}</Link>}
    </li>
  )
}
