import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { ErrorState, LoadingState } from '../../components/AccountStates'
import { t, useLocale } from '../../i18n/strings'
import { SupportAttempt, SupportError } from '../../support/api/apiSupport'
import { categoryLabel, linkTypeLabel, subcategoryLabel, supportErrorText, textLength } from '../../support/labels'
import type { NewSupportCase, SupportCatalog, SupportRepository } from '../../support/repositories'
import { SupportOffline, SupportShell } from '../../support/SupportShell'
import { caseLink, useSupport } from '../../support/supportRepositories'

/**
 * Contact support (Module 37): a category from the backend's catalog, a subject and a message — and, when the page
 * was opened from an order, a payment or a refund, that record attached as a read-only reference. The customer sends
 * no status, no priority and nobody's identity; the backend checks that an attached record is their own.
 *
 * A request that did not go through keeps its text. When nobody can say whether it arrived (no answer, a server
 * fault) the unchanged request is sent again with the SAME Idempotency-Key, so it can never open two cases; after a
 * definite refusal, or once the text changes, it is a new request with a new key. The page never says "sent" before
 * the backend answered with the case.
 */
type LinkKind = 'order' | 'payment' | 'refund'
const LINK_KINDS: LinkKind[] = ['refund', 'payment', 'order']
const clean = (v: string | null): string => (v ?? '').trim().slice(0, 64)

export default function SupportNewCasePage({ repository }: { repository?: SupportRepository | null }) {
  const source = useSupport()
  const repo = repository !== undefined ? repository : source.repository
  const { locale } = useLocale()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const [catalog, setCatalog] = useState<SupportCatalog | null>(null)
  const [load, setLoad] = useState<'loading' | 'ready' | 'error'>('loading')
  const [reload, setReload] = useState(0)
  const wanted = (params.get('category') ?? '').trim().toUpperCase()
  const [category, setCategory] = useState('')
  const [subcategory, setSubcategory] = useState('')
  const [subject, setSubject] = useState('')
  const [message, setMessage] = useState('')
  // The record this page was opened for. It can be removed, never edited: a reference is not something to type here.
  const [links, setLinks] = useState<Record<LinkKind, string>>(() => ({ order: clean(params.get('order')), payment: clean(params.get('payment')), refund: clean(params.get('refund')) }))
  const [showErrors, setShowErrors] = useState(false)
  const [sending, setSending] = useState(false)
  const [failure, setFailure] = useState<{ text: string; fields: Record<string, string[]>; unknown: boolean } | null>(null)
  const attempt = useRef(new SupportAttempt())

  useEffect(() => { document.title = `${t('sup.new.title', undefined, locale)} · FoodOnTheGo` }, [locale])
  useEffect(() => {
    if (!repo) return
    let on = true
    repo.catalog().then((c) => {
      if (!on) return
      setCatalog(c); setLoad('ready')
      // The topic the link asked for — only when the backend offers it.
      if (wanted && c.categories.some((x) => x.code === wanted)) setCategory((now) => now || wanted)
    }).catch(() => { if (on) setLoad('error') })
    return () => { on = false }
  }, [repo, reload, wanted])

  const limits = catalog?.limits ?? { subjectMax: 140, messageMax: 4000 }
  const subcategories = useMemo(() => catalog?.categories.find((c) => c.code === category)?.subcategories ?? [], [catalog, category])
  const subjectLength = textLength(subject.trim()); const messageLength = textLength(message.trim())
  const errors = {
    category: category === '' ? t('sup.new.error.category', undefined, locale) : null,
    subject: subjectLength === 0 ? t('sup.new.error.subject', undefined, locale) : subjectLength > limits.subjectMax ? t('sup.new.error.subjectLength', { max: limits.subjectMax }, locale) : null,
    message: messageLength === 0 ? t('sup.new.error.message', undefined, locale) : messageLength > limits.messageMax ? t('sup.new.error.messageLength', { max: limits.messageMax }, locale) : null,
  }
  const fieldError = (name: 'category' | 'subcategory' | 'subject' | 'message'): string | null => (showErrors && name !== 'subcategory' ? errors[name] : null) ?? failure?.fields[name]?.[0] ?? null
  const attached = LINK_KINDS.filter((k) => links[k] !== '')

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    if (!repo || sending) return
    setShowErrors(true)
    if (errors.category || errors.subject || errors.message) return
    const input: NewSupportCase = { category, subcategory: subcategory || null, subject: subject.trim(), message: message.trim(), order: links.order || null, payment: links.payment || null, refund: links.refund || null }
    setSending(true); setFailure(null)
    try {
      const created = await repo.create(input, attempt.current.keyFor(JSON.stringify(input)))
      attempt.current.settle()
      navigate(caseLink(created.caseNumber), { replace: true })
    } catch (err) {
      const unknown = !(err instanceof SupportError) || err.unknownOutcome
      // A refusal is final for that key: what is sent next is a new request.
      if (!unknown) attempt.current.settle()
      setFailure({ text: supportErrorText(err, locale), fields: err instanceof SupportError ? err.fields : {}, unknown })
      setSending(false)
    }
  }

  return (
    <SupportShell title={t('sup.new.title', undefined, locale)} lead={t('sup.new.lead', undefined, locale)}>
      {!repo ? <SupportOffline locale={locale} testId="support-new" /> : (
        <div className="ac-card" data-testid="support-new" data-source="backend">
          {load === 'loading' && <LoadingState label={t('sup.new.loading', undefined, locale)} rows={2} />}
          {load === 'error' && <ErrorState message={t('sup.new.loadError', undefined, locale)} onRetry={() => { setLoad('loading'); setReload((n) => n + 1) }} />}
          {load === 'ready' && catalog && (
            <form className="sup-form" noValidate onSubmit={(e) => { void submit(e) }} aria-busy={sending}>
              {attached.length > 0 && (
                <div>
                  <b>{t('sup.new.linked', undefined, locale)}</b>
                  <ul className="sup-linked">
                    {attached.map((k) => (
                      <li key={k} data-type={k.toUpperCase()}>
                        <span>{linkTypeLabel(k.toUpperCase(), locale)}</span>
                        <code data-testid="support-linked" data-type={k.toUpperCase()}>{links[k]}</code>
                        <button type="button" className="sup-link" disabled={sending} onClick={() => setLinks({ ...links, [k]: '' })} aria-label={t('sup.new.linked.removeLabel', { type: linkTypeLabel(k.toUpperCase(), locale), reference: links[k] }, locale)}>{t('sup.new.linked.remove', undefined, locale)}</button>
                      </li>
                    ))}
                  </ul>
                  {LINK_KINDS.map((k) => failure?.fields[k]?.[0] && <p key={k} className="sup-field-error" role="alert">{failure.fields[k][0]}</p>)}
                </div>
              )}
              <div className="sup-field">
                <label htmlFor="sup-category">{t('sup.new.category', undefined, locale)}</label>
                <select id="sup-category" value={category} onChange={(e) => { setCategory(e.target.value); setSubcategory('') }} aria-invalid={!!fieldError('category') || undefined} aria-describedby={fieldError('category') ? 'sup-category-err' : undefined} required data-testid="support-category">
                  <option value="">{t('sup.new.category.choose', undefined, locale)}</option>
                  {catalog.categories.map((c) => <option key={c.code} value={c.code}>{categoryLabel(c.code, locale)}</option>)}
                </select>
                {fieldError('category') && <p id="sup-category-err" className="sup-field-error" role="alert">{fieldError('category')}</p>}
              </div>
              {subcategories.length > 0 && (
                <div className="sup-field">
                  <label htmlFor="sup-subcategory">{t('sup.new.subcategory', undefined, locale)}</label>
                  <select id="sup-subcategory" value={subcategory} onChange={(e) => setSubcategory(e.target.value)} data-testid="support-subcategory">
                    <option value="">{t('sup.new.subcategory.none', undefined, locale)}</option>
                    {subcategories.map((s) => <option key={s} value={s}>{subcategoryLabel(s, locale)}</option>)}
                  </select>
                  {fieldError('subcategory') && <p className="sup-field-error" role="alert">{fieldError('subcategory')}</p>}
                </div>
              )}
              <div className="sup-field">
                <label htmlFor="sup-subject">{t('sup.new.subject', undefined, locale)}</label>
                <input id="sup-subject" type="text" dir="auto" value={subject} onChange={(e) => setSubject(e.target.value)} maxLength={limits.subjectMax * 2} aria-invalid={!!fieldError('subject') || undefined} aria-describedby="sup-subject-hint" required autoComplete="off" data-testid="support-subject" />
                <p id="sup-subject-hint" className={`sup-hint ${subjectLength > limits.subjectMax ? 'sup-hint--over' : ''}`}><span>{t('sup.new.subject.hint', { max: limits.subjectMax }, locale)}</span><span>{t('sup.new.count', { n: subjectLength, max: limits.subjectMax }, locale)}</span></p>
                {fieldError('subject') && <p className="sup-field-error" role="alert">{fieldError('subject')}</p>}
              </div>
              <div className="sup-field">
                <label htmlFor="sup-message">{t('sup.new.message', undefined, locale)}</label>
                <textarea id="sup-message" dir="auto" rows={6} value={message} onChange={(e) => setMessage(e.target.value)} aria-invalid={!!fieldError('message') || undefined} aria-describedby="sup-message-hint" required data-testid="support-message" />
                <p id="sup-message-hint" className={`sup-hint ${messageLength > limits.messageMax ? 'sup-hint--over' : ''}`}><span>{t('sup.new.message.hint', undefined, locale)}</span><span>{t('sup.new.count', { n: messageLength, max: limits.messageMax }, locale)}</span></p>
                {fieldError('message') && <p className="sup-field-error" role="alert">{fieldError('message')}</p>}
              </div>
              {failure && (
                <p className="sup-error" role="alert" data-testid="support-form-error" data-outcome={failure.unknown ? 'unknown' : 'refused'}>
                  {failure.unknown ? t('sup.new.unknown', undefined, locale) : `${t('sup.new.notSent', undefined, locale)} ${failure.text}`}
                </p>
              )}
              <div className="sup-actions">
                <button type="submit" className="btn btn--primary" disabled={sending} data-testid="support-submit">{sending ? t('sup.new.submitting', undefined, locale) : t('sup.new.submit', undefined, locale)}</button>
                <Link to="/help/cases" className="btn btn--outline">{t('sup.new.cancel', undefined, locale)}</Link>
              </div>
            </form>
          )}
        </div>
      )}
    </SupportShell>
  )
}
