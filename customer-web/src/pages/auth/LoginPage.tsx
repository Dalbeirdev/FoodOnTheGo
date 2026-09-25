import { useEffect, useId, useState, type FormEvent } from 'react'
import { Link, Navigate, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { useAuth } from '../../auth/AuthContext'
import { COUNTRIES, DEFAULT_COUNTRY, countryByCode, toE164, validatePhone } from '../../auth/phone'
import { AuthError } from '../../auth/repository'
import { ArrowRightIcon } from '../../components/Icons'
import AuthLayout from './AuthLayout'

const ERROR_COPY: Record<string, string> = {
  send_failed: "We couldn't send the code right now. Please try again in a moment.",
  network: 'No internet connection. Check your network and try again.',
  unexpected: 'Something went wrong on our side. Please try again.',
}

/** Step 1: mobile number → request OTP. Guests land here from protected routes with `state.from`. */
export default function LoginPage() {
  const { requestOtp, isAuthenticated, loading, status, pending, notice } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [params] = useSearchParams()
  const id = useId()
  const returnTo = (location.state as { from?: string } | null)?.from ?? pending?.returnTo ?? '/my-profile'
  const [country, setCountry] = useState(DEFAULT_COUNTRY)
  const [phone, setPhone] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const reason = params.get('reason')
  useEffect(() => { document.title = 'Sign in · FoodOnTheGo' }, [])

  if (!loading && isAuthenticated) return <Navigate to={returnTo} replace />

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    const invalid = validatePhone(country, phone)
    if (invalid) return setError(invalid)
    setError(''); setBusy(true)
    try {
      await requestOtp(toE164(country, phone)!, returnTo)
      navigate('/verify-otp', { state: { from: returnTo } })
    } catch (err) {
      setError(err instanceof AuthError ? ERROR_COPY[err.code] ?? err.message : ERROR_COPY.unexpected)
    } finally {
      setBusy(false)
    }
  }

  return (
    <AuthLayout step={1}>
      <h1 id="auth-title">Sign in with your mobile</h1>
      <p className="auth__sub">We'll text you a one-time code. No password needed.</p>
      {reason === 'expired' || status === 'sessionExpired' ? <p className="auth__error" role="alert">Your session has expired. Please sign in again to continue.</p> : null}
      {reason === 'logged-out' || notice === 'logged-out' ? <p className="auth__notice" role="status">You have been signed out.</p> : null}
      <form onSubmit={submit} noValidate aria-busy={busy}>
        <div className="auth__field">
          <label htmlFor={`${id}-phone`}>Mobile Number</label>
          <div className="auth__phone">
            <label className="sr-only" htmlFor={`${id}-country`}>Country</label>
            <select id={`${id}-country`} className="auth__country" value={country.code} onChange={(e) => setCountry(countryByCode(e.target.value))} disabled={COUNTRIES.length === 1} aria-label={`Country: ${country.name} ${country.dial}`}>
              {COUNTRIES.map((c) => <option key={c.code} value={c.code}>{c.flag} {c.dial}</option>)}
            </select>
            <input id={`${id}-phone`} type="tel" inputMode="numeric" autoComplete="tel-national" placeholder={country.example} value={phone} maxLength={16} onChange={(e) => { setPhone(e.target.value); if (error) setError('') }} aria-invalid={!!error} aria-describedby={error ? `${id}-err` : `${id}-hint`} />
          </div>
          {error ? <em id={`${id}-err`} role="alert">{error}</em> : <small id={`${id}-hint`} className="auth__hint">{country.name} ({country.dial}) · {country.nationalLength} digits</small>}
        </div>
        <button type="submit" className="btn btn--primary auth__submit" disabled={busy}>
          {busy ? <><span className="auth__spinner" aria-hidden="true" /> Sending code…</> : <>Send OTP <ArrowRightIcon size={18} /></>}
        </button>
      </form>
      <p className="auth__switch">By continuing you agree to our <Link to="/terms">Terms of Service</Link> and <Link to="/privacy">Privacy Policy</Link>.</p>
      <p className="auth__switch auth__dev" role="note">LOCAL preview: no SMS is sent. Use the development code <b>123456</b>. Test numbers: 98765 43210 = existing customer, 99999 00000 = send failure, 99999 00001 = no network.</p>
    </AuthLayout>
  )
}
