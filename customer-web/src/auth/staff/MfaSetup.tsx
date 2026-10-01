/**
 * Multi-factor enrolment for a restaurant or admin user (authenticator app, TOTP).
 *
 *  1. the backend creates the secret; it is shown as a QR code and as text for manual entry;
 *  2. a first 6-digit code from the app proves it works and switches MFA on;
 *  3. the recovery codes are shown ONCE — the backend keeps only what it needs to verify them.
 *
 * The secret and the recovery codes live in this component's memory only: nothing is written to storage, and the QR
 * image is generated in the browser (the secret is never sent to an image service).
 *
 * Used inside the dashboards (Account security) and at sign-in when the account must enrol first — then `enrolToken`
 * is the enrol-only token from the sign-in answer and the confirmation returns the real session.
 */
import { useEffect, useRef, useState, type FormEvent } from 'react'
import QRCode from 'qrcode'
import { staffAuth, staffErrorMessage, type MfaSetup as Setup, type StaffContext, type StaffPrincipal } from './staffAuth'

export function MfaSetup({ context, enrolToken, onDone, onCancel }: { context: StaffContext; enrolToken?: string; onDone: (principal: StaffPrincipal) => void; onCancel?: () => void }) {
  const auth = staffAuth(context)
  const [setup, setSetup] = useState<Setup | null>(null); const [qr, setQr] = useState<string | null>(null)
  const [code, setCode] = useState(''); const [error, setError] = useState<string | null>(null); const [busy, setBusy] = useState(false)
  const [result, setResult] = useState<{ codes: string[]; principal: StaffPrincipal } | null>(null); const [saved, setSaved] = useState(false); const [copied, setCopied] = useState(false)

  const start = async () => { setBusy(true); setError(null); try { setSetup(await auth.mfaSetup(enrolToken)) } catch (e) { setError(staffErrorMessage(e)) } finally { setBusy(false) } }
  // Asked exactly once per screen: every request creates a NEW secret on the backend, so a second request (React runs
  // effects twice in development) would leave the screen showing a key the backend has already replaced.
  const requested = useRef(false)
  useEffect(() => { if (requested.current) return; requested.current = true; void start() }, []) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { let live = true; if (setup) QRCode.toDataURL(setup.otpauthUri, { margin: 1, width: 200 }).then((url) => { if (live) setQr(url) }).catch(() => { if (live) setQr(null) }); return () => { live = false } }, [setup])

  const confirm = async (e: FormEvent) => {
    e.preventDefault(); if (busy) return
    setBusy(true); setError(null)
    try { const r = await auth.mfaConfirm(code, enrolToken); setSetup(null); setQr(null); setCode(''); setResult({ codes: r.recoveryCodes, principal: r.principal }) } catch (err) { setError(staffErrorMessage(err)) } finally { setBusy(false) }
  }
  const copy = async () => { try { await navigator.clipboard.writeText(result!.codes.join('\n')); setCopied(true) } catch { setCopied(false) } }

  if (result) return (
    <div className="staff-mfa" data-testid="mfa-recovery">
      <h2 className="staff-mfa__title">Save your recovery codes</h2>
      <p>Multi-factor authentication is on. Each code below signs you in once if you lose your authenticator app. <b>They are shown only now.</b> Keep them somewhere safe — not in your e-mail.</p>
      <ul className="staff-mfa__codes" aria-label="Recovery codes">{result.codes.map((c) => <li key={c}><code>{c}</code></li>)}</ul>
      <div className="staff-mfa__row"><button type="button" className="db-btn db-btn--outline" onClick={() => { void copy() }} data-testid="mfa-copy">{copied ? 'Copied' : 'Copy codes'}</button></div>
      <label className="staff-mfa__check"><input type="checkbox" checked={saved} onChange={(e) => setSaved(e.target.checked)} data-testid="mfa-saved" /> I have saved these codes</label>
      <button type="button" className="db-btn db-btn--primary staff-login__submit" disabled={!saved} onClick={() => onDone(result.principal)} data-testid="mfa-finish">Continue</button>
    </div>
  )
  return (
    <form className="staff-mfa" onSubmit={(e) => { void confirm(e) }} noValidate data-testid="mfa-setup">
      <h2 className="staff-mfa__title">Set up multi-factor authentication</h2>
      <ol className="staff-mfa__steps">
        <li>Install an authenticator app (Google Authenticator, Microsoft Authenticator, Authy, 1Password …).</li>
        <li>Scan this code with the app, or type the key by hand.</li>
        <li>Enter the 6-digit code the app shows.</li>
      </ol>
      {error && <p className="staff-login__error" role="alert" data-testid="mfa-error">{error}</p>}
      {!setup ? (busy ? <p className="db-muted" role="status">Preparing…</p> : <button type="button" className="db-btn db-btn--outline" onClick={() => { void start() }}>Try again</button>) : <>
        {qr ? <img src={qr} width={200} height={200} alt="QR code for your authenticator app" className="staff-mfa__qr" data-testid="mfa-qr" /> : <p className="db-muted">The QR code could not be drawn. Use the key below.</p>}
        <p className="staff-mfa__key"><span>Setup key</span> <code data-testid="mfa-secret">{setup.secret.replace(/(.{4})/g, '$1 ').trim()}</code></p>
        <div className="db-field"><label htmlFor="mfa-code">6-digit code</label><input id="mfa-code" className="db-input" value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))} inputMode="numeric" autoComplete="one-time-code" data-testid="mfa-code" /></div>
        <div className="staff-mfa__row">
          <button type="submit" className="db-btn db-btn--primary" disabled={busy || code.length !== 6} data-testid="mfa-confirm">{busy ? 'Checking…' : 'Turn on'}</button>
          {onCancel && <button type="button" className="db-btn db-btn--ghost" onClick={onCancel} disabled={busy}>Cancel</button>}
        </div>
      </>}
    </form>
  )
}
