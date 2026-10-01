/**
 * Public password pages of the Restaurant Dashboard and the Platform Admin (no session):
 *
 *   /…/forgot-password   asks for the e-mail; the answer is the same whether or not an account exists
 *   /…/reset-password#t  the link from the e-mail; the token is in the URL fragment (never sent in a request line or
 *                        a Referer header), read once and removed from the address bar
 *
 * A reset signs the account out everywhere; the person then signs in with the new password (and their MFA code).
 * Nothing is stored in the browser. The pages use the language of the link (`?lang=`) or the one last used here.
 */
import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { staffAuth, staffErrorMessage, type StaffContext } from './staffAuth'
import { useStaffStrings } from './staffLocale'
import '../../dashboard/dashboard.css'
import './staff-login.css'

export const STAFF_BASE: Record<StaffContext, string> = { admin: '/admin', restaurant: '/restaurant-dashboard' }
export const PASSWORD_MIN_LENGTH = 12

function Shell({ context, title, testId, onSubmit, children }: { context: StaffContext; title: string; testId: string; onSubmit: (e: FormEvent) => void; children: ReactNode }) {
  const { tr, lang } = useStaffStrings()
  useEffect(() => { document.title = `${title} · FoodOnTheGo` }, [title])
  return (
    <main className="staff-login" id="main" lang={lang}>
      <form className="staff-login__card db-card" onSubmit={onSubmit} noValidate aria-labelledby={`${testId}-title`} data-testid={testId}>
        <img src="/brand/foodonthego-icon.svg" alt="" width={44} height={44} />
        <p className="staff-login__brand">{tr(`staff.brand.${context}`)}</p>
        <h1 id={`${testId}-title`} className="staff-login__title">{title}</h1>
        {children}
      </form>
    </main>
  )
}

export function ForgotPasswordPage({ context }: { context: StaffContext }) {
  const auth = useMemo(() => staffAuth(context), [context])
  const { tr } = useStaffStrings()
  const [email, setEmail] = useState(''); const [busy, setBusy] = useState(false); const [error, setError] = useState<string | null>(null); const [sent, setSent] = useState(false)
  const submit = async (e: FormEvent) => {
    e.preventDefault(); if (busy) return
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email.trim())) { setError(tr('staff.forgot.enterEmail')); return }
    setBusy(true); setError(null)
    try { await auth.forgotPassword(email.trim()); setSent(true) } catch (err) { setError(staffErrorMessage(err, tr)) } finally { setBusy(false) }
  }
  return (
    <Shell context={context} title={tr('staff.forgot.title')} testId="staff-forgot" onSubmit={(e) => { void submit(e) }}>
      {sent ? <>
        <p role="status" data-testid="forgot-sent">{tr('staff.forgot.sent')}</p>
        <Link to={STAFF_BASE[context]} className="db-btn db-btn--primary staff-login__submit">{tr('staff.backToSignIn')}</Link>
      </> : <>
        <p className="db-muted">{tr('staff.forgot.lead')}</p>
        {error && <p className="staff-login__error" role="alert" data-testid="forgot-error">{error}</p>}
        <div className="db-field"><label htmlFor="forgot-email">{tr('staff.email')}</label><input id="forgot-email" className="db-input" type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} data-testid="forgot-email" /></div>
        <button type="submit" className="db-btn db-btn--primary staff-login__submit" disabled={busy || !email.trim()} data-testid="forgot-submit">{busy ? tr('staff.forgot.sending') : tr('staff.forgot.send')}</button>
        <Link to={STAFF_BASE[context]} className="staff-login__back">{tr('staff.backToSignIn')}</Link>
      </>}
    </Shell>
  )
}

export function ResetPasswordPage({ context }: { context: StaffContext }) {
  const auth = useMemo(() => staffAuth(context), [context])
  const { tr } = useStaffStrings()
  const [token] = useState(() => (typeof location !== 'undefined' ? location.hash.replace(/^#/, '') : ''))
  const [password, setPassword] = useState(''); const [repeat, setRepeat] = useState('')
  const [busy, setBusy] = useState(false); const [error, setError] = useState<string | null>(null); const [done, setDone] = useState(false)
  // Only the token leaves the address bar; `?lang=` stays so the page keeps its language.
  useEffect(() => { if (token) history.replaceState(null, '', location.pathname + location.search) }, [token])
  const submit = async (e: FormEvent) => {
    e.preventDefault(); if (busy) return
    if (password.length < PASSWORD_MIN_LENGTH) { setError(tr('staff.pw.minError', { n: PASSWORD_MIN_LENGTH })); return }
    if (password !== repeat) { setError(tr('staff.pw.mismatch')); return }
    setBusy(true); setError(null)
    try { await auth.resetPassword(token, password); setPassword(''); setRepeat(''); setDone(true) } catch (err) { setError(staffErrorMessage(err, tr)) } finally { setBusy(false) }
  }
  return (
    <Shell context={context} title={tr('staff.reset.title')} testId="staff-reset" onSubmit={(e) => { void submit(e) }}>
      {done ? <>
        <p role="status" data-testid="reset-done">{tr('staff.reset.done')}</p>
        <Link to={STAFF_BASE[context]} className="db-btn db-btn--primary staff-login__submit">{tr('staff.goSignIn')}</Link>
      </> : token.length !== 64 ? <>
        <p role="alert" data-testid="reset-invalid">{tr('staff.reset.incomplete')}</p>
        <Link to={`${STAFF_BASE[context]}/forgot-password`} className="db-btn db-btn--outline staff-login__submit">{tr('staff.reset.requestNew')}</Link>
      </> : <>
        {error && <p className="staff-login__error" role="alert" data-testid="reset-error">{error}</p>}
        <div className="db-field"><label htmlFor="reset-password">{tr('staff.pw.new')}</label><input id="reset-password" className="db-input" type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} data-testid="reset-password" /><p className="db-muted" style={{ margin: 0, fontSize: 13 }}>{tr('staff.pw.hint', { n: PASSWORD_MIN_LENGTH })}</p></div>
        <div className="db-field"><label htmlFor="reset-repeat">{tr('staff.pw.repeat')}</label><input id="reset-repeat" className="db-input" type="password" autoComplete="new-password" value={repeat} onChange={(e) => setRepeat(e.target.value)} data-testid="reset-repeat" /></div>
        <button type="submit" className="db-btn db-btn--primary staff-login__submit" disabled={busy || !password || !repeat} data-testid="reset-submit">{busy ? tr('staff.saving') : tr('staff.pw.change')}</button>
        <Link to={STAFF_BASE[context]} className="staff-login__back">{tr('staff.backToSignIn')}</Link>
      </>}
    </Shell>
  )
}
