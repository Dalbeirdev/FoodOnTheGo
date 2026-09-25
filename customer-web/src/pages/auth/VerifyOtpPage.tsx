import { useEffect, useId, useRef, useState, type ClipboardEvent, type FormEvent, type KeyboardEvent } from 'react'
import { Navigate, useNavigate } from 'react-router-dom'
import { useAuth } from '../../auth/AuthContext'
import { maskPhone } from '../../auth/phone'
import { AuthError } from '../../auth/repository'
import AuthLayout from './AuthLayout'

const LENGTH = 6
const fmt = (ms: number) => { const s = Math.max(0, Math.ceil(ms / 1000)); return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}` }

/** Step 2: six-box OTP entry with paste, auto-advance, countdown, resend, attempt handling. */
export default function VerifyOtpPage() {
  const { pending, verifyOtp, resendOtp, changePhone, loading, isAuthenticated } = useAuth()
  const navigate = useNavigate()
  const id = useId()
  const [digits, setDigits] = useState<string[]>(Array(LENGTH).fill(''))
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [state, setState] = useState<'idle' | 'verifying' | 'resending' | 'expired' | 'locked'>('idle')
  const [now, setNow] = useState(Date.now())
  const inputs = useRef<Array<HTMLInputElement | null>>([])
  useEffect(() => { document.title = 'Verify code · FoodOnTheGo' }, [])
  useEffect(() => { const t = setInterval(() => setNow(Date.now()), 1000); return () => clearInterval(t) }, [])
  useEffect(() => { inputs.current[0]?.focus() }, [])

  const otp = pending?.otp
  const expiresIn = otp ? otp.expiresAt - now : 0
  const resendIn = otp ? otp.resendAfter - now : 0
  const expired = state === 'expired' || (!!otp && expiresIn <= 0)

  if (!loading && isAuthenticated && !pending?.setupToken) return <Navigate to={pending?.returnTo ?? '/my-profile'} replace />
  if (!pending?.phone) return <Navigate to="/login" replace />
  if (pending.setupToken) return <Navigate to="/account-setup" replace />

  const code = digits.join('')

  const setAt = (i: number, v: string) => {
    const next = [...digits]; next[i] = v; setDigits(next); if (error) setError('')
  }
  const onChange = (i: number, raw: string) => {
    const v = raw.replace(/\D/g, '')
    if (!v) return setAt(i, '')
    if (v.length > 1) return fill(v, i)
    setAt(i, v)
    inputs.current[i + 1]?.focus()
  }
  const fill = (text: string, from = 0) => {
    const clean = text.replace(/\D/g, '').slice(0, LENGTH - from)
    const next = [...digits]
    clean.split('').forEach((ch, k) => { next[from + k] = ch })
    setDigits(next); if (error) setError('')
    inputs.current[Math.min(from + clean.length, LENGTH - 1)]?.focus()
  }
  const onPaste = (e: ClipboardEvent<HTMLInputElement>) => { e.preventDefault(); fill(e.clipboardData.getData('text')) }
  const onKey = (i: number, e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Backspace' && !digits[i] && i > 0) { inputs.current[i - 1]?.focus(); setAt(i - 1, '') }
    if (e.key === 'ArrowLeft' && i > 0) inputs.current[i - 1]?.focus()
    if (e.key === 'ArrowRight' && i < LENGTH - 1) inputs.current[i + 1]?.focus()
  }

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    if (expired) return setError('Your code has expired. Request a new one.')
    if (code.length < LENGTH) return setError(`Enter the ${LENGTH}-digit code`)
    setState('verifying'); setError(''); setNotice('')
    try {
      const result = await verifyOtp(code)
      navigate(result.status === 'authenticated' ? pending.returnTo : '/account-setup', { replace: true })
    } catch (err) {
      const ae = err instanceof AuthError ? err : new AuthError('unexpected', 'Something went wrong. Please try again.')
      setError(ae.message)
      setState(ae.code === 'expired_otp' ? 'expired' : ae.code === 'too_many_attempts' ? 'locked' : 'idle')
      if (ae.code === 'invalid_otp') { setDigits(Array(LENGTH).fill('')); inputs.current[0]?.focus() }
    }
  }

  const resend = async () => {
    setState('resending'); setError(''); setNotice('')
    try {
      await resendOtp()
      setDigits(Array(LENGTH).fill(''))
      setNotice(`A new code was sent to ${maskPhone(pending.phone)}.`)
      setState('idle'); inputs.current[0]?.focus()
    } catch (err) {
      setError(err instanceof AuthError ? err.message : 'Could not resend the code. Please try again.'); setState('idle')
    }
  }

  const busy = state === 'verifying' || state === 'resending'
  const locked = state === 'locked' || expired
  return (
    <AuthLayout step={2}>
      <h1 id="auth-title">Enter the code</h1>
      <p className="auth__sub">We sent a 6-digit code to <b>{maskPhone(pending.phone)}</b>. <button type="button" className="auth__link" onClick={() => { changePhone(); navigate('/login', { state: { from: pending.returnTo } }) }}>Change number</button></p>
      <form onSubmit={submit} noValidate aria-busy={busy}>
        <fieldset className="auth__otp" disabled={busy}>
          <legend className="sr-only">One-time code</legend>
          {digits.map((d, i) => (
            <input key={i} ref={(el) => { inputs.current[i] = el }} id={`${id}-${i}`} className="auth__otp-box" type="text" inputMode="numeric" pattern="[0-9]*" autoComplete={i === 0 ? 'one-time-code' : 'off'} maxLength={LENGTH} value={d} aria-label={`Digit ${i + 1} of ${LENGTH}`} aria-invalid={!!error} onChange={(e) => onChange(i, e.target.value)} onKeyDown={(e) => onKey(i, e)} onPaste={onPaste} onFocus={(e) => e.target.select()} />
          ))}
        </fieldset>
        <div className="auth__otp-meta" aria-live="polite">
          {expired ? <span className="auth__expired">Code expired</span> : <span>Code expires in <b>{fmt(expiresIn)}</b></span>}
        </div>
        {error && <p className="auth__error" role="alert">{error}</p>}
        {notice && <p className="auth__notice" role="status">{notice}</p>}
        <button type="submit" className="btn btn--primary auth__submit" disabled={busy || locked}>
          {state === 'verifying' ? <><span className="auth__spinner" aria-hidden="true" /> Verifying…</> : 'Verify'}
        </button>
      </form>
      <p className="auth__switch">
        Didn't get it?{' '}
        {resendIn > 0 && !locked
          ? <span>Resend in {fmt(resendIn)}</span>
          : <button type="button" className="auth__link" onClick={resend} disabled={busy}>{state === 'resending' ? 'Sending…' : 'Resend OTP'}</button>}
      </p>
      <p className="auth__switch auth__dev" role="note">LOCAL preview: the development code is <b>123456</b>.</p>
    </AuthLayout>
  )
}
