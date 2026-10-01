/**
 * /admin/accept-invitation#<token> — an invited administrator chooses their own password.
 *
 * The token travels in the URL fragment, so it is never sent to a server in a request line or a Referer header;
 * it is read once and removed from the address bar. The backend validates it (single use, expiring) and the
 * password rule; nothing is stored in the browser. Afterwards the person signs in normally. The page uses the
 * language the invitation was written in (`?lang=` of the link).
 */
import { useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { ApiError } from '../../api/client'
import { useStaffStrings } from '../../auth/staff/staffLocale'
import { acceptAdminInvitation } from '../api/ApiAdminUserRepository'
import '../../dashboard/dashboard.css'
import '../../auth/staff/staff-login.css'

const MIN_LENGTH = 12

export default function AcceptInvitationPage() {
  const { tr, lang } = useStaffStrings()
  const [token] = useState(() => (typeof location !== 'undefined' ? location.hash.replace(/^#/, '') : ''))
  const [password, setPassword] = useState(''); const [repeat, setRepeat] = useState('')
  const [error, setError] = useState<string | null>(null); const [busy, setBusy] = useState(false); const [done, setDone] = useState(false)
  useEffect(() => { if (token) history.replaceState(null, '', location.pathname + location.search) }, [token])
  useEffect(() => { document.title = tr('staff.accept.docTitle') }, [tr])

  const submit = async (e: FormEvent) => {
    e.preventDefault(); setError(null)
    if (password.length < MIN_LENGTH) { setError(tr('staff.pw.minError', { n: MIN_LENGTH })); return }
    if (password !== repeat) { setError(tr('staff.pw.mismatch')); return }
    setBusy(true)
    try { await acceptAdminInvitation(token, password); setPassword(''); setRepeat(''); setDone(true) }
    catch (err) { setError(err instanceof ApiError ? (err.field('password') ?? err.field('token') ?? err.message) : tr('staff.err.generic')) }
    finally { setBusy(false) }
  }

  return (
    <main className="staff-login" id="main" data-testid="accept-invitation" lang={lang}>
      <form className="staff-login__card db-card" onSubmit={(e) => { void submit(e) }} noValidate aria-labelledby="accept-title">
        <img src="/brand/foodonthego-icon.svg" alt="" width={44} height={44} />
        <p className="staff-login__brand">{tr('staff.accept.brand')}</p>
        <h1 id="accept-title" className="staff-login__title">{tr('staff.accept.title')}</h1>
        {done ? <>
          <p role="status" data-testid="accept-done">{tr('staff.accept.done')}</p>
          <Link to="/admin" className="db-btn db-btn--primary staff-login__submit">{tr('staff.goSignIn')}</Link>
        </> : token.length !== 64 ? <>
          <p role="alert" data-testid="accept-invalid">{tr('staff.accept.incomplete')}</p>
          <Link to="/" className="staff-login__back">{tr('staff.back')}</Link>
        </> : <>
          <p className="db-muted">{tr('staff.accept.lead')}</p>
          {error && <p className="staff-login__error" role="alert" data-testid="accept-error">{error}</p>}
          <div className="db-field"><label htmlFor="accept-password">{tr('staff.pw.new')}</label><input id="accept-password" className="db-input" type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} data-testid="accept-password" /><p className="db-muted" style={{ margin: 0, fontSize: 13 }}>{tr('staff.pw.hint', { n: MIN_LENGTH })}</p></div>
          <div className="db-field"><label htmlFor="accept-repeat">{tr('staff.pw.repeat')}</label><input id="accept-repeat" className="db-input" type="password" autoComplete="new-password" value={repeat} onChange={(e) => setRepeat(e.target.value)} data-testid="accept-repeat" /></div>
          <button type="submit" className="db-btn db-btn--primary staff-login__submit" disabled={busy || !password || !repeat} data-testid="accept-submit">{busy ? tr('staff.saving') : tr('staff.accept.set')}</button>
          <Link to="/" className="staff-login__back">{tr('staff.back')}</Link>
        </>}
      </form>
    </main>
  )
}
