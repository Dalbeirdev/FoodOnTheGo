/**
 * /restaurant-dashboard/accept-invitation#<token> — an invited staff member joins a restaurant.
 *
 * The token travels in the URL fragment, so it is never sent to a server in a request line or a Referer header;
 * it is read once and removed from the address bar. The backend validates it (single use, expiring).
 *
 * Someone who already has a restaurant account simply accepts. A new account has no password yet: the backend
 * says so, and the person chooses one here — nobody else ever sets or sees it. Afterwards they sign in normally.
 */
import { useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { ApiError } from '../../api/client'
import { acceptStaffInvitation } from '../api/apiDashboard'
import '../dashboard.css'
import '../../auth/staff/staff-login.css'

const MIN_LENGTH = 12

export default function AcceptStaffInvitationPage() {
  const [token] = useState(() => (typeof location !== 'undefined' ? location.hash.replace(/^#/, '') : ''))
  const [needsPassword, setNeedsPassword] = useState(false)
  const [password, setPassword] = useState(''); const [repeat, setRepeat] = useState('')
  const [error, setError] = useState<string | null>(null); const [busy, setBusy] = useState(false); const [joined, setJoined] = useState<string | null>(null)
  useEffect(() => { document.title = 'Accept your invitation · FoodOnTheGo Restaurant Dashboard'; if (token) history.replaceState(null, '', location.pathname) }, [token])

  const submit = async (e: FormEvent) => {
    e.preventDefault(); setError(null)
    if (needsPassword) {
      if (password.length < MIN_LENGTH) { setError(`Use at least ${MIN_LENGTH} characters.`); return }
      if (password !== repeat) { setError('The two passwords are not the same.'); return }
    }
    setBusy(true)
    try { const { restaurant } = await acceptStaffInvitation(token, needsPassword ? password : undefined); setPassword(''); setRepeat(''); setJoined(restaurant) }
    catch (err) {
      // A new account: the invitation is fine, a password is still needed. Nothing was accepted yet.
      if (err instanceof ApiError && err.kind === 'validation' && err.field('password') && !needsPassword) setNeedsPassword(true)
      else setError(err instanceof ApiError ? (err.field('password') ?? err.field('token') ?? err.message) : 'Something went wrong. Please try again.')
    } finally { setBusy(false) }
  }

  return (
    <main className="staff-login" id="main" data-testid="staff-accept-invitation">
      <form className="staff-login__card db-card" onSubmit={(e) => { void submit(e) }} noValidate aria-labelledby="accept-title">
        <img src="/brand/foodonthego-icon.svg" alt="" width={44} height={44} />
        <p className="staff-login__brand">FoodOnTheGo · Restaurant Dashboard</p>
        <h1 id="accept-title" className="staff-login__title">Accept your invitation</h1>
        {joined !== null ? <>
          <p role="status" data-testid="accept-done">You are now part of {joined}. You can sign in.</p>
          <Link to="/restaurant-dashboard" className="db-btn db-btn--primary staff-login__submit">Go to sign in</Link>
        </> : token.length !== 64 ? <>
          <p role="alert" data-testid="accept-invalid">This invitation link is not complete. Open the link from your invitation e-mail again, or ask the restaurant to send a new one.</p>
          <Link to="/" className="staff-login__back">Back to FoodOnTheGo</Link>
        </> : <>
          <p className="db-muted">{needsPassword ? 'Choose a password to activate your account and join the restaurant.' : 'A restaurant has invited you to its FoodOnTheGo dashboard.'}</p>
          {error && <p className="staff-login__error" role="alert" data-testid="accept-error">{error}</p>}
          {needsPassword && <>
            <div className="db-field"><label htmlFor="accept-password">New password</label><input id="accept-password" className="db-input" type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} data-testid="accept-password" /><p className="db-muted" style={{ margin: 0, fontSize: 13 }}>At least {MIN_LENGTH} characters. A long phrase is better than a short complicated one.</p></div>
            <div className="db-field"><label htmlFor="accept-repeat">Repeat the password</label><input id="accept-repeat" className="db-input" type="password" autoComplete="new-password" value={repeat} onChange={(e) => setRepeat(e.target.value)} data-testid="accept-repeat" /></div>
          </>}
          <button type="submit" className="db-btn db-btn--primary staff-login__submit" disabled={busy || (needsPassword && (!password || !repeat))} data-testid="accept-submit">{busy ? 'Please wait…' : needsPassword ? 'Set password and join' : 'Accept invitation'}</button>
          <Link to="/" className="staff-login__back">Back to FoodOnTheGo</Link>
        </>}
      </form>
    </main>
  )
}
