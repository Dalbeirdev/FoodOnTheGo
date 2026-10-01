/**
 * /admin/accept-invitation#<token> — an invited administrator chooses their own password.
 *
 * The token travels in the URL fragment, so it is never sent to a server in a request line or a Referer header;
 * it is read once and removed from the address bar. The backend validates it (single use, expiring) and the
 * password rule; nothing is stored in the browser. Afterwards the person signs in normally.
 */
import { useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { ApiError } from '../../api/client'
import { acceptAdminInvitation } from '../api/ApiAdminUserRepository'
import '../../dashboard/dashboard.css'
import '../../auth/staff/staff-login.css'

const MIN_LENGTH = 12

export default function AcceptInvitationPage() {
  const [token] = useState(() => (typeof location !== 'undefined' ? location.hash.replace(/^#/, '') : ''))
  const [password, setPassword] = useState(''); const [repeat, setRepeat] = useState('')
  const [error, setError] = useState<string | null>(null); const [busy, setBusy] = useState(false); const [done, setDone] = useState(false)
  useEffect(() => { document.title = 'Set your password · FoodOnTheGo Platform Admin'; if (token) history.replaceState(null, '', location.pathname) }, [token])

  const submit = async (e: FormEvent) => {
    e.preventDefault(); setError(null)
    if (password.length < MIN_LENGTH) { setError(`Use at least ${MIN_LENGTH} characters.`); return }
    if (password !== repeat) { setError('The two passwords are not the same.'); return }
    setBusy(true)
    try { await acceptAdminInvitation(token, password); setPassword(''); setRepeat(''); setDone(true) }
    catch (err) { setError(err instanceof ApiError ? (err.field('password') ?? err.field('token') ?? err.message) : 'Something went wrong. Please try again.') }
    finally { setBusy(false) }
  }

  return (
    <main className="staff-login" id="main" data-testid="accept-invitation">
      <form className="staff-login__card db-card" onSubmit={(e) => { void submit(e) }} noValidate aria-labelledby="accept-title">
        <img src="/brand/foodonthego-icon.svg" alt="" width={44} height={44} />
        <p className="staff-login__brand">FoodOnTheGo · Platform Admin</p>
        <h1 id="accept-title" className="staff-login__title">Set your password</h1>
        {done ? <>
          <p role="status" data-testid="accept-done">Your password is set. You can sign in now.</p>
          <Link to="/admin" className="db-btn db-btn--primary staff-login__submit">Go to sign in</Link>
        </> : token.length !== 64 ? <>
          <p role="alert" data-testid="accept-invalid">This invitation link is not complete. Open the link from your invitation e-mail again, or ask an administrator to send a new one.</p>
          <Link to="/" className="staff-login__back">Back to FoodOnTheGo</Link>
        </> : <>
          <p className="db-muted">Choose a password to activate your administrator account.</p>
          {error && <p className="staff-login__error" role="alert" data-testid="accept-error">{error}</p>}
          <div className="db-field"><label htmlFor="accept-password">New password</label><input id="accept-password" className="db-input" type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} data-testid="accept-password" /><p className="db-muted" style={{ margin: 0, fontSize: 13 }}>At least {MIN_LENGTH} characters. A long phrase is better than a short complicated one.</p></div>
          <div className="db-field"><label htmlFor="accept-repeat">Repeat the password</label><input id="accept-repeat" className="db-input" type="password" autoComplete="new-password" value={repeat} onChange={(e) => setRepeat(e.target.value)} data-testid="accept-repeat" /></div>
          <button type="submit" className="db-btn db-btn--primary staff-login__submit" disabled={busy || !password || !repeat} data-testid="accept-submit">{busy ? 'Saving…' : 'Set password'}</button>
          <Link to="/" className="staff-login__back">Back to FoodOnTheGo</Link>
        </>}
      </form>
    </main>
  )
}
