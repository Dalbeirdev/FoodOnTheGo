/**
 * "Account security" inside the Restaurant Dashboard and the Platform Admin: the signed-in person's own
 * multi-factor authentication, password and sessions. Every action is the backend's (it re-checks the current
 * password for sensitive changes); nothing here stores a secret.
 */
import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { MfaSetup } from './MfaSetup'
import { PASSWORD_MIN_LENGTH } from './StaffPasswordPages'
import { useStaffSession } from './StaffSession'
import { staffAuth, staffErrorMessage, type StaffContext, type StaffDeviceSession } from './staffAuth'
import './staff-login.css'

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—')

export default function AccountSecurityPage({ context }: { context: StaffContext }) {
  const session = useStaffSession(); const auth = useMemo(() => staffAuth(context), [context])
  useEffect(() => { document.title = 'Account security · FoodOnTheGo' }, [])
  if (session.mode !== 'api') return <div className="db-page" data-testid="account-security"><h1 className="db-page__title">Account security</h1><p className="db-muted">Sign-in, multi-factor authentication and sessions are managed by the backend. This build runs on development fixtures and has no account to manage.</p></div>
  return (
    <div className="db-page" data-testid="account-security">
      <div className="db-page__head"><div><h1 className="db-page__title">Account security</h1><p className="db-page__lead">{session.principal.name} · {session.principal.email}</p></div></div>
      <MfaCard context={context} enabled={session.principal.mfaEnabled} refresh={session.refresh} auth={auth} />
      <PasswordCard auth={auth} />
      <SessionsCard auth={auth} onSignedOut={session.refresh} />
    </div>
  )
}

type Auth = ReturnType<typeof staffAuth>

function MfaCard({ context, enabled, refresh, auth }: { context: StaffContext; enabled: boolean; refresh: () => Promise<void>; auth: Auth }) {
  const [mode, setMode] = useState<'idle' | 'setup' | 'disable'>('idle'); const [password, setPassword] = useState(''); const [code, setCode] = useState('')
  const [error, setError] = useState<string | null>(null); const [busy, setBusy] = useState(false); const [note, setNote] = useState<string | null>(null)
  const disable = async (e: FormEvent) => {
    e.preventDefault(); if (busy) return
    setBusy(true); setError(null)
    try { await auth.mfaDisable(password, code); setPassword(''); setCode(''); setMode('idle'); setNote('Multi-factor authentication is off.'); await refresh() } catch (err) { setError(staffErrorMessage(err)) } finally { setBusy(false) }
  }
  return (
    <section className="db-card staff-sec" aria-labelledby="sec-mfa" data-testid="sec-mfa">
      <div className="staff-sec__head"><h2 id="sec-mfa">Multi-factor authentication</h2><span className={`db-badge db-badge--${enabled ? 'green' : 'amber'}`} data-testid="mfa-state">{enabled ? 'On' : 'Off'}</span></div>
      {note && <p className="staff-login__notice" role="status">{note}</p>}
      {mode === 'idle' && (enabled
        ? <><p>Signing in asks for a 6-digit code from your authenticator app, or a recovery code.</p><div className="staff-mfa__row"><button type="button" className="db-btn db-btn--danger" onClick={() => { setNote(null); setError(null); setMode('disable') }} data-testid="mfa-disable-start">Turn off</button></div></>
        : <><p>Add a second step to your sign-in: a code from an authenticator app on your phone. Strongly recommended for every account, and required for administrators before going live.</p><div className="staff-mfa__row"><button type="button" className="db-btn db-btn--primary" onClick={() => { setNote(null); setMode('setup') }} data-testid="mfa-enable-start">Set up</button></div></>)}
      {mode === 'setup' && <MfaSetup context={context} onCancel={() => setMode('idle')} onDone={() => { setMode('idle'); setNote('Multi-factor authentication is on.'); void refresh() }} />}
      {mode === 'disable' && (
        <form className="staff-mfa" onSubmit={(e) => { void disable(e) }} noValidate data-testid="mfa-disable">
          <p>Turning this off makes your account easier to break into. Confirm with your password and a current code from your app.</p>
          {error && <p className="staff-login__error" role="alert" data-testid="mfa-disable-error">{error}</p>}
          <div className="db-field"><label htmlFor="mfa-off-password">Current password</label><input id="mfa-off-password" className="db-input" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} data-testid="mfa-off-password" /></div>
          <div className="db-field"><label htmlFor="mfa-off-code">6-digit code</label><input id="mfa-off-code" className="db-input" inputMode="numeric" autoComplete="one-time-code" value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))} data-testid="mfa-off-code" /></div>
          <div className="staff-mfa__row"><button type="submit" className="db-btn db-btn--danger" disabled={busy || !password || code.length !== 6} data-testid="mfa-off-submit">{busy ? 'Checking…' : 'Turn off'}</button><button type="button" className="db-btn db-btn--ghost" onClick={() => { setMode('idle'); setError(null) }} disabled={busy}>Cancel</button></div>
        </form>
      )}
    </section>
  )
}

function PasswordCard({ auth }: { auth: Auth }) {
  const [f, setF] = useState({ current: '', next: '', repeat: '' }); const [error, setError] = useState<string | null>(null); const [busy, setBusy] = useState(false); const [done, setDone] = useState(false)
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => { setDone(false); setF((p) => ({ ...p, [k]: e.target.value })) }
  const submit = async (e: FormEvent) => {
    e.preventDefault(); if (busy) return
    if (f.next.length < PASSWORD_MIN_LENGTH) { setError(`Use at least ${PASSWORD_MIN_LENGTH} characters.`); return }
    if (f.next !== f.repeat) { setError('The two new passwords are not the same.'); return }
    if (f.next === f.current) { setError('The new password must be different from the current one.'); return }
    setBusy(true); setError(null)
    try { await auth.changePassword(f.current, f.next); setF({ current: '', next: '', repeat: '' }); setDone(true) } catch (err) { setError(staffErrorMessage(err)) } finally { setBusy(false) }
  }
  return (
    <section className="db-card staff-sec" aria-labelledby="sec-password" data-testid="sec-password">
      <div className="staff-sec__head"><h2 id="sec-password">Password</h2></div>
      <form className="staff-mfa" onSubmit={(e) => { void submit(e) }} noValidate>
        {done && <p className="staff-login__notice" role="status" data-testid="password-done">Password changed. Your other devices have been signed out.</p>}
        {error && <p className="staff-login__error" role="alert" data-testid="password-error">{error}</p>}
        <div className="db-field"><label htmlFor="pw-current">Current password</label><input id="pw-current" className="db-input" type="password" autoComplete="current-password" value={f.current} onChange={set('current')} data-testid="pw-current" /></div>
        <div className="db-field"><label htmlFor="pw-next">New password</label><input id="pw-next" className="db-input" type="password" autoComplete="new-password" value={f.next} onChange={set('next')} data-testid="pw-next" /><p className="db-muted" style={{ margin: 0, fontSize: 13 }}>At least {PASSWORD_MIN_LENGTH} characters.</p></div>
        <div className="db-field"><label htmlFor="pw-repeat">Repeat the new password</label><input id="pw-repeat" className="db-input" type="password" autoComplete="new-password" value={f.repeat} onChange={set('repeat')} data-testid="pw-repeat" /></div>
        <div className="staff-mfa__row"><button type="submit" className="db-btn db-btn--primary" disabled={busy || !f.current || !f.next || !f.repeat} data-testid="pw-submit">{busy ? 'Saving…' : 'Change password'}</button></div>
      </form>
    </section>
  )
}

function SessionsCard({ auth, onSignedOut }: { auth: Auth; onSignedOut: () => Promise<void> }) {
  const [list, setList] = useState<StaffDeviceSession[] | null>(null); const [error, setError] = useState<string | null>(null); const [busy, setBusy] = useState(false)
  const load = useCallback(async () => { try { setList(await auth.sessions()); setError(null) } catch (e) { setError(staffErrorMessage(e)) } }, [auth])
  useEffect(() => { void load() }, [load])
  const revoke = async (id: string) => { setBusy(true); try { await auth.revokeSession(id); await load() } catch (e) { setError(staffErrorMessage(e)) } finally { setBusy(false) } }
  const everywhere = async () => { setBusy(true); try { await auth.logoutAll() } finally { setBusy(false); await onSignedOut() } }
  return (
    <section className="db-card staff-sec" aria-labelledby="sec-sessions" data-testid="sec-sessions">
      <div className="staff-sec__head"><h2 id="sec-sessions">Signed-in devices</h2></div>
      {error && <p className="staff-login__error" role="alert">{error}</p>}
      {list === null ? <p className="db-muted" role="status">Loading…</p> : (
        <div className="db-table-wrap" tabIndex={0}><table className="db-table" data-testid="sessions-table"><caption className="db-sr-only">Signed-in devices</caption>
          <thead><tr><th scope="col">Device</th><th scope="col">Signed in</th><th scope="col">Last used</th><th scope="col">Expires</th><th scope="col"><span className="db-sr-only">Actions</span></th></tr></thead>
          <tbody>{list.map((s) => <tr key={s.id}><td>{s.device ?? 'Unknown'} {s.current && <span className="db-badge db-badge--blue">This device</span>}</td><td>{when(s.createdAt)}</td><td>{when(s.lastUsedAt)}</td><td>{when(s.expiresAt)}</td><td className="adm-td--end">{!s.current && <button type="button" className="db-btn db-btn--outline db-btn--sm" disabled={busy} onClick={() => { void revoke(s.id) }} data-testid={`session-revoke-${s.id}`}>Sign out</button>}</td></tr>)}</tbody>
        </table></div>
      )}
      <div className="staff-mfa__row"><button type="button" className="db-btn db-btn--danger" disabled={busy} onClick={() => { void everywhere() }} data-testid="sessions-all">Sign out everywhere</button></div>
    </section>
  )
}
