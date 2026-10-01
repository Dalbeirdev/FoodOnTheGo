/**
 * Public password pages of the Restaurant Dashboard and the Platform Admin (no session):
 *
 *   /…/forgot-password   asks for the e-mail; the answer is the same whether or not an account exists
 *   /…/reset-password#t  the link from the e-mail; the token is in the URL fragment (never sent in a request line or
 *                        a Referer header), read once and removed from the address bar
 *
 * A reset signs the account out everywhere; the person then signs in with the new password (and their MFA code).
 * Nothing is stored in the browser.
 */
import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { staffAuth, staffErrorMessage, type StaffContext } from './staffAuth'
import '../../dashboard/dashboard.css'
import './staff-login.css'

export const STAFF_BASE: Record<StaffContext, string> = { admin: '/admin', restaurant: '/restaurant-dashboard' }
const BRAND: Record<StaffContext, string> = { admin: 'FoodOnTheGo administration', restaurant: 'FoodOnTheGo for restaurants' }
export const PASSWORD_MIN_LENGTH = 12

function Shell({ context, title, testId, onSubmit, children }: { context: StaffContext; title: string; testId: string; onSubmit: (e: FormEvent) => void; children: ReactNode }) {
  useEffect(() => { document.title = `${title} · FoodOnTheGo` }, [title])
  return (
    <main className="staff-login" id="main">
      <form className="staff-login__card db-card" onSubmit={onSubmit} noValidate aria-labelledby={`${testId}-title`} data-testid={testId}>
        <img src="/brand/foodonthego-icon.svg" alt="" width={44} height={44} />
        <p className="staff-login__brand">{BRAND[context]}</p>
        <h1 id={`${testId}-title`} className="staff-login__title">{title}</h1>
        {children}
      </form>
    </main>
  )
}

export function ForgotPasswordPage({ context }: { context: StaffContext }) {
  const auth = useMemo(() => staffAuth(context), [context])
  const [email, setEmail] = useState(''); const [busy, setBusy] = useState(false); const [error, setError] = useState<string | null>(null); const [sent, setSent] = useState(false)
  const submit = async (e: FormEvent) => {
    e.preventDefault(); if (busy) return
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email.trim())) { setError('Enter your e-mail address.'); return }
    setBusy(true); setError(null)
    try { await auth.forgotPassword(email.trim()); setSent(true) } catch (err) { setError(staffErrorMessage(err)) } finally { setBusy(false) }
  }
  return (
    <Shell context={context} title="Forgot your password?" testId="staff-forgot" onSubmit={(e) => { void submit(e) }}>
      {sent ? <>
        <p role="status" data-testid="forgot-sent">If an account exists for this e-mail, a link to choose a new password has been sent. It is valid for a short time and works once.</p>
        <Link to={STAFF_BASE[context]} className="db-btn db-btn--primary staff-login__submit">Back to sign in</Link>
      </> : <>
        <p className="db-muted">Enter the e-mail of your account. We will send a link to choose a new password.</p>
        {error && <p className="staff-login__error" role="alert" data-testid="forgot-error">{error}</p>}
        <div className="db-field"><label htmlFor="forgot-email">E-mail</label><input id="forgot-email" className="db-input" type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} data-testid="forgot-email" /></div>
        <button type="submit" className="db-btn db-btn--primary staff-login__submit" disabled={busy || !email.trim()} data-testid="forgot-submit">{busy ? 'Sending…' : 'Send reset link'}</button>
        <Link to={STAFF_BASE[context]} className="staff-login__back">Back to sign in</Link>
      </>}
    </Shell>
  )
}

export function ResetPasswordPage({ context }: { context: StaffContext }) {
  const auth = useMemo(() => staffAuth(context), [context])
  const [token] = useState(() => (typeof location !== 'undefined' ? location.hash.replace(/^#/, '') : ''))
  const [password, setPassword] = useState(''); const [repeat, setRepeat] = useState('')
  const [busy, setBusy] = useState(false); const [error, setError] = useState<string | null>(null); const [done, setDone] = useState(false)
  useEffect(() => { if (token) history.replaceState(null, '', location.pathname) }, [token])
  const submit = async (e: FormEvent) => {
    e.preventDefault(); if (busy) return
    if (password.length < PASSWORD_MIN_LENGTH) { setError(`Use at least ${PASSWORD_MIN_LENGTH} characters.`); return }
    if (password !== repeat) { setError('The two passwords are not the same.'); return }
    setBusy(true); setError(null)
    try { await auth.resetPassword(token, password); setPassword(''); setRepeat(''); setDone(true) } catch (err) { setError(staffErrorMessage(err)) } finally { setBusy(false) }
  }
  return (
    <Shell context={context} title="Choose a new password" testId="staff-reset" onSubmit={(e) => { void submit(e) }}>
      {done ? <>
        <p role="status" data-testid="reset-done">Your password has been changed and every device has been signed out. Sign in with the new password.</p>
        <Link to={STAFF_BASE[context]} className="db-btn db-btn--primary staff-login__submit">Go to sign in</Link>
      </> : token.length !== 64 ? <>
        <p role="alert" data-testid="reset-invalid">This reset link is not complete. Open the link from the e-mail again, or request a new one.</p>
        <Link to={`${STAFF_BASE[context]}/forgot-password`} className="db-btn db-btn--outline staff-login__submit">Request a new link</Link>
      </> : <>
        {error && <p className="staff-login__error" role="alert" data-testid="reset-error">{error}</p>}
        <div className="db-field"><label htmlFor="reset-password">New password</label><input id="reset-password" className="db-input" type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} data-testid="reset-password" /><p className="db-muted" style={{ margin: 0, fontSize: 13 }}>At least {PASSWORD_MIN_LENGTH} characters. A long phrase is better than a short complicated one.</p></div>
        <div className="db-field"><label htmlFor="reset-repeat">Repeat the password</label><input id="reset-repeat" className="db-input" type="password" autoComplete="new-password" value={repeat} onChange={(e) => setRepeat(e.target.value)} data-testid="reset-repeat" /></div>
        <button type="submit" className="db-btn db-btn--primary staff-login__submit" disabled={busy || !password || !repeat} data-testid="reset-submit">{busy ? 'Saving…' : 'Change password'}</button>
        <Link to={STAFF_BASE[context]} className="staff-login__back">Back to sign in</Link>
      </>}
    </Shell>
  )
}
