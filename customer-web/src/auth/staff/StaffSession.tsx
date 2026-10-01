import { createContext, useCallback, useContext, useEffect, useId, useMemo, useState, type FormEvent, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { setUnauthenticatedHandler } from '../../api/client'
import { authMode } from '../authMode'
import { staffAuth, staffErrorMessage, type StaffContext, type StaffPrincipal } from './staffAuth'
import '../../dashboard/dashboard.css'
import './staff-login.css'
import { MfaSetup } from './MfaSetup'
import { STAFF_BASE } from './StaffPasswordPages'

/**
 * Sign-in gate for the Restaurant Dashboard and the Platform Admin (Module 21).
 *
 *  - api mode: nothing of the dashboard renders until the backend confirms a session for this context. The session
 *    (who, which roles, which permissions) comes from the backend; the dashboards use it for navigation only.
 *  - mock mode (unit tests, share builds): no gate — the dashboards keep their development fixture switch.
 *
 * A 401 on any call of this context returns to the sign-in form once (no redirect loop). A 403 for one resource is
 * shown by the page as "access denied" and does not sign the user out.
 */
/** refresh: re-reads the session from the backend (after the person changed MFA, or signed out everywhere). */
export type StaffSession = { mode: 'mock' } | { mode: 'api'; principal: StaffPrincipal; logout: () => Promise<void>; refresh: () => Promise<void> }
const Ctx = createContext<StaffSession>({ mode: 'mock' })
export const useStaffSession = (): StaffSession => useContext(Ctx)

const COPY: Record<StaffContext, { title: string; lead: string; brand: string }> = {
  restaurant: { title: 'Restaurant Dashboard', lead: 'Sign in with your restaurant staff account.', brand: 'FoodOnTheGo for restaurants' },
  admin: { title: 'Platform Admin', lead: 'Sign in with your FoodOnTheGo administrator account.', brand: 'FoodOnTheGo administration' },
}

export function StaffAuthGate({ context, children }: { context: StaffContext; children: ReactNode }) {
  const live = authMode() === 'api'
  const auth = useMemo(() => staffAuth(context), [context])
  const [state, setState] = useState<'checking' | 'signed_out' | 'unreachable' | 'signed_in'>(live ? 'checking' : 'signed_in')
  const [principal, setPrincipal] = useState<StaffPrincipal | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const check = useCallback(async () => {
    setState('checking')
    try { const p = await auth.me(); setPrincipal(p); setState(p ? 'signed_in' : 'signed_out') } catch { setState('unreachable') }
  }, [auth])
  useEffect(() => { if (!live) return; const t = setTimeout(() => { void check() }, 0); return () => clearTimeout(t) }, [live, check])
  useEffect(() => {
    if (!live) return
    setUnauthenticatedHandler(() => { setPrincipal(null); setNotice('Your session has ended. Please sign in again.'); setState('signed_out') }, context)
    return () => setUnauthenticatedHandler(null, context)
  }, [live, context])

  const session = useMemo<StaffSession>(() => (live && principal
    ? { mode: 'api', principal, logout: async () => { await auth.logout(); setPrincipal(null); setNotice('You have been signed out.'); setState('signed_out') },
      refresh: async () => { try { const p = await auth.me(); setPrincipal(p); if (!p) { setNotice('You have been signed out.'); setState('signed_out') } } catch { /* keep the current view; the next request reports the problem */ } } }
    : { mode: 'mock' }), [live, principal, auth])

  if (!live) return <Ctx.Provider value={{ mode: 'mock' }}>{children}</Ctx.Provider>
  if (state === 'checking') return <main className="staff-login" aria-busy="true"><p className="db-muted" role="status">Checking your session…</p></main>
  if (state === 'unreachable') return (
    <main className="staff-login"><div className="staff-login__card db-card" role="alert">
      <h1 className="staff-login__title">{COPY[context].title}</h1>
      <p>Cannot reach FoodOnTheGo right now. Check your connection and try again.</p>
      <button type="button" className="db-btn db-btn--primary" onClick={() => { void check() }}>Try again</button>
    </div></main>
  )
  if (state === 'signed_out' || !principal) return <StaffLogin context={context} notice={notice} onSignedIn={(p) => { setNotice(null); setPrincipal(p); setState('signed_in') }} />
  return <Ctx.Provider value={session}>{children}</Ctx.Provider>
}

function StaffLogin({ context, notice, onSignedIn }: { context: StaffContext; notice: string | null; onSignedIn: (p: StaffPrincipal) => void }) {
  const auth = useMemo(() => staffAuth(context), [context])
  const id = useId(); const copy = COPY[context]
  // An account that must enrol in MFA first gets an enrol-only token: it is held here, in memory, for the setup screen.
  const [enrolToken, setEnrolToken] = useState<string | null>(null)
  const [email, setEmail] = useState(''); const [password, setPassword] = useState(''); const [code, setCode] = useState('')
  const [challenge, setChallenge] = useState<string | null>(null)
  const [busy, setBusy] = useState(false); const [error, setError] = useState<string | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    if (busy) return
    setBusy(true); setError(null)
    try {
      const result = challenge ? await auth.verifyMfa(challenge, code) : await auth.login(email.trim(), password)
      if (result.kind === 'signed_in') { setPassword(''); onSignedIn(result.principal); return }
      if (result.kind === 'mfa_required') { setChallenge(result.challenge); setPassword(''); setCode('') }
      else if (result.enrolToken) { setPassword(''); setEnrolToken(result.enrolToken) }
      else setError('This account must set up multi-factor authentication before it can sign in. Please contact your administrator.')
    } catch (err) {
      const message = staffErrorMessage(err)
      // An expired or exhausted challenge sends the user back to the password step.
      if (challenge && /sign in again/i.test(message)) { setChallenge(null); setCode('') }
      setError(message)
    } finally { setBusy(false) }
  }

  if (enrolToken) return (
    <main className="staff-login" id="main">
      <div className="staff-login__card db-card" data-testid="staff-enrol">
        <p className="staff-login__brand">{copy.brand}</p>
        <p className="staff-login__notice" role="status">Your account must use multi-factor authentication. Set it up now to finish signing in.</p>
        <MfaSetup context={context} enrolToken={enrolToken} onCancel={() => setEnrolToken(null)} onDone={(p) => { setEnrolToken(null); onSignedIn(p) }} />
      </div>
    </main>
  )

  return (
    <main className="staff-login" id="main">
      <form className="staff-login__card db-card" onSubmit={submit} noValidate aria-labelledby={`${id}-title`} data-testid={`staff-login-${context}`}>
        <img src="/brand/foodonthego-icon.svg" alt="" width={44} height={44} />
        <p className="staff-login__brand">{copy.brand}</p>
        <h1 id={`${id}-title`} className="staff-login__title">{copy.title}</h1>
        <p className="db-muted">{challenge ? 'Enter the 6-digit code from your authenticator app, or a recovery code.' : copy.lead}</p>
        {notice && !error && <p className="staff-login__notice" role="status">{notice}</p>}
        {error && <p className="staff-login__error" role="alert" data-testid="staff-login-error">{error}</p>}
        {challenge ? (
          <div className="db-field">
            <label htmlFor={`${id}-code`}>Verification code</label>
            <input id={`${id}-code`} className="db-input" value={code} onChange={(e) => setCode(e.target.value)} autoComplete="one-time-code" inputMode="text" autoFocus required data-testid="staff-login-code" />
          </div>
        ) : (
          <>
            <div className="db-field">
              <label htmlFor={`${id}-email`}>E-mail</label>
              <input id={`${id}-email`} className="db-input" type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="username" autoFocus required data-testid="staff-login-email" />
            </div>
            <div className="db-field">
              <label htmlFor={`${id}-password`}>Password</label>
              <input id={`${id}-password`} className="db-input" type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" required data-testid="staff-login-password" />
            </div>
          </>
        )}
        <button type="submit" className="db-btn db-btn--primary staff-login__submit" disabled={busy || (challenge ? !code.trim() : !email.trim() || !password)} data-testid="staff-login-submit">{busy ? 'Signing in…' : challenge ? 'Verify and sign in' : 'Sign in'}</button>
        {challenge && <button type="button" className="db-btn db-btn--ghost" onClick={() => { setChallenge(null); setCode(''); setError(null) }}>Use a different account</button>}
        {!challenge && <Link to={`${STAFF_BASE[context]}/forgot-password`} className="staff-login__back" data-testid="staff-forgot-link">Forgot your password?</Link>}
        <Link to="/" className="staff-login__back">Back to FoodOnTheGo</Link>
      </form>
    </main>
  )
}
