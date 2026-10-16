import { useEffect, useId, useRef, useState, type FormEvent, type KeyboardEvent } from 'react'
import { api, ApiError, setStepUpHandler } from '../api/client'
import '../components/AccountStates.css'
import '../pages/account/AccountPage.css'

type Pending = { mfa: boolean; resolve: (ok: boolean) => void }

/**
 * Module 41 — step-up re-authentication in the Platform Admin. A high-risk action (refund approval, payouts,
 * settlements, adjustments, administrator role / status / MFA, restaurant status, exports) answers 403 step_up_required
 * when the session was not authenticated recently; the API client then asks this dialog. The administrator enters the
 * password (and the authenticator code when MFA is on); POST /auth/admin/step-up confirms the session and the action is
 * repeated once. Cancel leaves the action undone. Nothing is remembered in the browser.
 */
export default function StepUpDialog() {
  const [pending, setPending] = useState<Pending | null>(null)
  useEffect(() => {
    setStepUpHandler((info) => new Promise<boolean>((resolve) => setPending({ mfa: info.mfa, resolve })))
    return () => setStepUpHandler(null)
  }, [])
  if (!pending) return null
  const close = (ok: boolean) => { pending.resolve(ok); setPending(null) }
  return <StepUpForm mfa={pending.mfa} onDone={close} />
}

function StepUpForm({ mfa, onDone }: { mfa: boolean; onDone: (ok: boolean) => void }) {
  const titleId = useId()
  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const first = useRef<HTMLInputElement>(null)
  useEffect(() => { first.current?.focus() }, [])

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true); setError(null)
    try {
      await api('/auth/admin/step-up', { method: 'POST', context: 'admin', body: { password, ...(mfa ? { code: code.trim() } : {}) } })
      onDone(true)
    } catch (err) {
      setError(err instanceof ApiError && err.kind !== 'server' && err.kind !== 'network' ? err.message : 'Could not confirm right now. Please try again.')
      setBusy(false)
    }
  }
  const onKeyDown = (e: KeyboardEvent<HTMLDivElement>) => { if (e.key === 'Escape' && !busy) { e.preventDefault(); onDone(false) } }

  return (
    <div className="ac-modal" role="dialog" aria-modal="true" aria-labelledby={titleId} onKeyDown={onKeyDown} data-testid="admin-step-up">
      <form className="ac-modal__box ac-modal__box--sm" onSubmit={submit}>
        <h2 id={titleId}>Confirm it is you</h2>
        <p className="ac-confirm__text">This action needs a recent sign-in. Enter your password{mfa ? ' and the code from your authenticator app' : ''} to continue.</p>
        <div className="ac-fields">
          <label className="span2">Password
            <input ref={first} type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} required disabled={busy} aria-invalid={!!error} />
          </label>
          {mfa && (
            <label className="span2">Authenticator code
              <input inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" maxLength={6} value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} required disabled={busy} aria-invalid={!!error} />
            </label>
          )}
          {error && <em className="span2" role="alert">{error}</em>}
        </div>
        <div className="ac-modal__actions">
          <button type="button" className="btn btn--outline" onClick={() => onDone(false)} disabled={busy}>Cancel</button>
          <button type="submit" className="btn btn--primary" disabled={busy || password === '' || (mfa && code.length !== 6)}>{busy ? 'Checking…' : 'Confirm'}</button>
        </div>
      </form>
    </div>
  )
}
