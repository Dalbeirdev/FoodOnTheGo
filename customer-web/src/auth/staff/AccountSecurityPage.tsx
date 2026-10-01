/**
 * "Account security" inside the Restaurant Dashboard and the Platform Admin: the signed-in person's own language,
 * multi-factor authentication, password and sessions. Every action is the backend's (it re-checks the current
 * password for sensitive changes); nothing here stores a secret.
 */
import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { MfaSetup } from './MfaSetup'
import { PASSWORD_MIN_LENGTH } from './StaffPasswordPages'
import { useStaffSession } from './StaffSession'
import { localeName, staffAuth, staffErrorMessage, type StaffContext, type StaffDeviceSession } from './staffAuth'
import { useStaffStrings } from './staffLocale'
import './staff-login.css'

type Tr = ReturnType<typeof useStaffStrings>['tr']

export default function AccountSecurityPage({ context }: { context: StaffContext }) {
  const session = useStaffSession(); const auth = useMemo(() => staffAuth(context), [context]); const { tr, lang } = useStaffStrings()
  useEffect(() => { document.title = `${tr('staff.sec.title')} · FoodOnTheGo` }, [tr])
  if (session.mode !== 'api') return <div className="db-page" data-testid="account-security"><h1 className="db-page__title">{tr('staff.sec.title')}</h1><p className="db-muted">{tr('staff.sec.mock')}</p></div>
  return (
    <div className="db-page" data-testid="account-security">
      <div className="db-page__head"><div><h1 className="db-page__title">{tr('staff.sec.title')}</h1><p className="db-page__lead">{session.principal.name} · {session.principal.email}</p></div></div>
      {session.principal.noticeLocales.length > 1 && <LanguageCard auth={auth} value={session.principal.preferredLocale} options={session.principal.noticeLocales} refresh={session.refresh} tr={tr} />}
      <MfaCard context={context} enabled={session.principal.mfaEnabled} refresh={session.refresh} auth={auth} tr={tr} />
      <PasswordCard auth={auth} tr={tr} />
      <SessionsCard auth={auth} onSignedOut={session.refresh} tr={tr} lang={lang} />
    </div>
  )
}

type Auth = ReturnType<typeof staffAuth>

/** One choice for the screens and the e-mails. Without a choice the screens are English and an e-mail carries every language. */
function LanguageCard({ auth, value, options, refresh, tr }: { auth: Auth; value: string | null; options: string[]; refresh: () => Promise<void>; tr: Tr }) {
  const [busy, setBusy] = useState(false); const [error, setError] = useState<string | null>(null); const [done, setDone] = useState(false)
  const change = async (next: string) => {
    setBusy(true); setError(null); setDone(false)
    try { await auth.setLanguage(next === '' ? null : next); await refresh(); setDone(true) } catch (e) { setError(staffErrorMessage(e, tr)) } finally { setBusy(false) }
  }
  return (
    <section className="db-card staff-sec" aria-labelledby="sec-language" data-testid="sec-language">
      <div className="staff-sec__head"><h2 id="sec-language">{tr('staff.lang.title')}</h2></div>
      <div className="staff-mfa">
        <p>{tr('staff.lang.lead')}</p>
        {done && <p className="staff-login__notice" role="status" data-testid="language-done">{tr('staff.lang.saved')}</p>}
        {error && <p className="staff-login__error" role="alert" data-testid="language-error">{error}</p>}
        <div className="db-field"><label htmlFor="sec-language-select">{tr('staff.lang.label')}</label>
          <select id="sec-language-select" className="db-select" value={value ?? ''} disabled={busy} onChange={(e) => { void change(e.target.value) }} data-testid="language-select">
            <option value="">{tr('staff.lang.none', { languages: options.map(localeName).join(' + ') })}</option>
            {options.map((code) => <option key={code} value={code} lang={code}>{localeName(code)}</option>)}
          </select>
        </div>
      </div>
    </section>
  )
}

function MfaCard({ context, enabled, refresh, auth, tr }: { context: StaffContext; enabled: boolean; refresh: () => Promise<void>; auth: Auth; tr: Tr }) {
  const [mode, setMode] = useState<'idle' | 'setup' | 'disable'>('idle'); const [password, setPassword] = useState(''); const [code, setCode] = useState('')
  const [error, setError] = useState<string | null>(null); const [busy, setBusy] = useState(false); const [note, setNote] = useState<'staff.sec.mfaOffNote' | 'staff.sec.mfaOnNote' | null>(null)
  const disable = async (e: FormEvent) => {
    e.preventDefault(); if (busy) return
    setBusy(true); setError(null)
    try { await auth.mfaDisable(password, code); setPassword(''); setCode(''); setMode('idle'); setNote('staff.sec.mfaOffNote'); await refresh() } catch (err) { setError(staffErrorMessage(err, tr)) } finally { setBusy(false) }
  }
  return (
    <section className="db-card staff-sec" aria-labelledby="sec-mfa" data-testid="sec-mfa">
      <div className="staff-sec__head"><h2 id="sec-mfa">{tr('staff.sec.mfa')}</h2><span className={`db-badge db-badge--${enabled ? 'green' : 'amber'}`} data-testid="mfa-state">{tr(enabled ? 'staff.on' : 'staff.off')}</span></div>
      {note && <p className="staff-login__notice" role="status">{tr(note)}</p>}
      {mode === 'idle' && (enabled
        ? <><p>{tr('staff.sec.mfaOnText')}</p><div className="staff-mfa__row"><button type="button" className="db-btn db-btn--danger" onClick={() => { setNote(null); setError(null); setMode('disable') }} data-testid="mfa-disable-start">{tr('staff.sec.turnOff')}</button></div></>
        : <><p>{tr('staff.sec.mfaOffText')}</p><div className="staff-mfa__row"><button type="button" className="db-btn db-btn--primary" onClick={() => { setNote(null); setMode('setup') }} data-testid="mfa-enable-start">{tr('staff.sec.setUp')}</button></div></>)}
      {mode === 'setup' && <MfaSetup context={context} onCancel={() => setMode('idle')} onDone={() => { setMode('idle'); setNote('staff.sec.mfaOnNote'); void refresh() }} />}
      {mode === 'disable' && (
        <form className="staff-mfa" onSubmit={(e) => { void disable(e) }} noValidate data-testid="mfa-disable">
          <p>{tr('staff.sec.disableLead')}</p>
          {error && <p className="staff-login__error" role="alert" data-testid="mfa-disable-error">{error}</p>}
          <div className="db-field"><label htmlFor="mfa-off-password">{tr('staff.sec.currentPassword')}</label><input id="mfa-off-password" className="db-input" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} data-testid="mfa-off-password" /></div>
          <div className="db-field"><label htmlFor="mfa-off-code">{tr('staff.mfa.code6')}</label><input id="mfa-off-code" className="db-input" inputMode="numeric" autoComplete="one-time-code" value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))} data-testid="mfa-off-code" /></div>
          <div className="staff-mfa__row"><button type="submit" className="db-btn db-btn--danger" disabled={busy || !password || code.length !== 6} data-testid="mfa-off-submit">{busy ? tr('staff.checkingCode') : tr('staff.sec.turnOff')}</button><button type="button" className="db-btn db-btn--ghost" onClick={() => { setMode('idle'); setError(null) }} disabled={busy}>{tr('staff.cancel')}</button></div>
        </form>
      )}
    </section>
  )
}

function PasswordCard({ auth, tr }: { auth: Auth; tr: Tr }) {
  const [f, setF] = useState({ current: '', next: '', repeat: '' }); const [error, setError] = useState<string | null>(null); const [busy, setBusy] = useState(false); const [done, setDone] = useState(false)
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => { setDone(false); setF((p) => ({ ...p, [k]: e.target.value })) }
  const submit = async (e: FormEvent) => {
    e.preventDefault(); if (busy) return
    if (f.next.length < PASSWORD_MIN_LENGTH) { setError(tr('staff.pw.minError', { n: PASSWORD_MIN_LENGTH })); return }
    if (f.next !== f.repeat) { setError(tr('staff.sec.pwMismatch')); return }
    if (f.next === f.current) { setError(tr('staff.sec.pwDifferent')); return }
    setBusy(true); setError(null)
    try { await auth.changePassword(f.current, f.next); setF({ current: '', next: '', repeat: '' }); setDone(true) } catch (err) { setError(staffErrorMessage(err, tr)) } finally { setBusy(false) }
  }
  return (
    <section className="db-card staff-sec" aria-labelledby="sec-password" data-testid="sec-password">
      <div className="staff-sec__head"><h2 id="sec-password">{tr('staff.password')}</h2></div>
      <form className="staff-mfa" onSubmit={(e) => { void submit(e) }} noValidate>
        {done && <p className="staff-login__notice" role="status" data-testid="password-done">{tr('staff.sec.pwDone')}</p>}
        {error && <p className="staff-login__error" role="alert" data-testid="password-error">{error}</p>}
        <div className="db-field"><label htmlFor="pw-current">{tr('staff.sec.currentPassword')}</label><input id="pw-current" className="db-input" type="password" autoComplete="current-password" value={f.current} onChange={set('current')} data-testid="pw-current" /></div>
        <div className="db-field"><label htmlFor="pw-next">{tr('staff.pw.new')}</label><input id="pw-next" className="db-input" type="password" autoComplete="new-password" value={f.next} onChange={set('next')} data-testid="pw-next" /><p className="db-muted" style={{ margin: 0, fontSize: 13 }}>{tr('staff.sec.pwMin', { n: PASSWORD_MIN_LENGTH })}</p></div>
        <div className="db-field"><label htmlFor="pw-repeat">{tr('staff.sec.pwRepeatNew')}</label><input id="pw-repeat" className="db-input" type="password" autoComplete="new-password" value={f.repeat} onChange={set('repeat')} data-testid="pw-repeat" /></div>
        <div className="staff-mfa__row"><button type="submit" className="db-btn db-btn--primary" disabled={busy || !f.current || !f.next || !f.repeat} data-testid="pw-submit">{busy ? tr('staff.saving') : tr('staff.pw.change')}</button></div>
      </form>
    </section>
  )
}

function SessionsCard({ auth, onSignedOut, tr, lang }: { auth: Auth; onSignedOut: () => Promise<void>; tr: Tr; lang: string }) {
  const [list, setList] = useState<StaffDeviceSession[] | null>(null); const [error, setError] = useState<string | null>(null); const [busy, setBusy] = useState(false)
  const load = useCallback(async () => { try { setList(await auth.sessions()); setError(null) } catch (e) { setError(staffErrorMessage(e, tr)) } }, [auth, tr])
  useEffect(() => { void load() }, [load])
  const revoke = async (id: string) => { setBusy(true); try { await auth.revokeSession(id); await load() } catch (e) { setError(staffErrorMessage(e, tr)) } finally { setBusy(false) } }
  const everywhere = async () => { setBusy(true); try { await auth.logoutAll() } finally { setBusy(false); await onSignedOut() } }
  const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString(lang === 'en' ? undefined : lang, { dateStyle: 'medium', timeStyle: 'short' }) : '—')
  return (
    <section className="db-card staff-sec" aria-labelledby="sec-sessions" data-testid="sec-sessions">
      <div className="staff-sec__head"><h2 id="sec-sessions">{tr('staff.sec.devices')}</h2></div>
      {error && <p className="staff-login__error" role="alert">{error}</p>}
      {list === null ? <p className="db-muted" role="status">{tr('staff.loading')}</p> : (
        <div className="db-table-wrap" tabIndex={0}><table className="db-table" data-testid="sessions-table"><caption className="db-sr-only">{tr('staff.sec.devices')}</caption>
          <thead><tr><th scope="col">{tr('staff.sec.col.device')}</th><th scope="col">{tr('staff.sec.col.signedIn')}</th><th scope="col">{tr('staff.sec.col.lastUsed')}</th><th scope="col">{tr('staff.sec.col.expires')}</th><th scope="col"><span className="db-sr-only">{tr('staff.sec.col.actions')}</span></th></tr></thead>
          <tbody>{list.map((s) => <tr key={s.id}><td>{s.device ?? tr('staff.sec.unknown')} {s.current && <span className="db-badge db-badge--blue">{tr('staff.sec.thisDevice')}</span>}</td><td>{when(s.createdAt)}</td><td>{when(s.lastUsedAt)}</td><td>{when(s.expiresAt)}</td><td className="adm-td--end">{!s.current && <button type="button" className="db-btn db-btn--outline db-btn--sm" disabled={busy} onClick={() => { void revoke(s.id) }} data-testid={`session-revoke-${s.id}`}>{tr('staff.sec.signOut')}</button>}</td></tr>)}</tbody>
        </table></div>
      )}
      <div className="staff-mfa__row"><button type="button" className="db-btn db-btn--danger" disabled={busy} onClick={() => { void everywhere() }} data-testid="sessions-all">{tr('staff.sec.everywhere')}</button></div>
    </section>
  )
}
