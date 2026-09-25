import { useEffect, useId, useState, type FormEvent } from 'react'
import { Link, Navigate, useNavigate } from 'react-router-dom'
import { useAuth } from '../../auth/AuthContext'
import { formatPhone } from '../../auth/phone'
import { AuthError } from '../../auth/repository'
import { ArrowRightIcon } from '../../components/Icons'
import AuthLayout from './AuthLayout'

/** Step 3 (new customers only): essential details — name, optional email, consent. */
export default function AccountSetupPage() {
  const { pending, completeSetup, loading, isAuthenticated } = useAuth()
  const navigate = useNavigate()
  const id = useId()
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [agree, setAgree] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)
  useEffect(() => { document.title = 'Set up your account · FoodOnTheGo' }, [])

  if (!loading && isAuthenticated) return <Navigate to={pending?.returnTo ?? '/my-profile'} replace />
  if (!pending?.setupToken) return <Navigate to="/login" replace />

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    const err: Record<string, string> = {}
    if (name.trim().length < 2) err.name = 'Enter your full name'
    if (email.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) err.email = 'Enter a valid email address or leave it empty'
    if (!agree) err.agree = 'Please accept the Terms and Privacy Policy to continue'
    setErrors(err)
    if (Object.keys(err).length) return
    setBusy(true)
    try {
      await completeSetup({ name: name.trim(), email: email.trim() || undefined, acceptTerms: agree })
      navigate(pending.returnTo, { replace: true })
    } catch (e2) {
      if (e2 instanceof AuthError && e2.code === 'expired_otp') navigate('/login?reason=expired', { replace: true })
      else setErrors({ form: e2 instanceof Error ? e2.message : 'Something went wrong. Please try again.' })
    } finally {
      setBusy(false)
    }
  }

  return (
    <AuthLayout step={3}>
      <h1 id="auth-title">Welcome! Tell us your name</h1>
      <p className="auth__sub">Your number <b>{formatPhone(pending.phone)}</b> is verified. Just a couple of details to finish.</p>
      <form onSubmit={submit} noValidate aria-busy={busy}>
        <div className="auth__field">
          <label htmlFor={`${id}-name`}>Full Name</label>
          <input id={`${id}-name`} value={name} autoComplete="name" autoFocus onChange={(e) => setName(e.target.value)} aria-invalid={!!errors.name} aria-describedby={errors.name ? `${id}-name-err` : undefined} />
          {errors.name && <em id={`${id}-name-err`} role="alert">{errors.name}</em>}
        </div>
        <div className="auth__field">
          <label htmlFor={`${id}-email`}>Email <span className="auth__optional">(optional, for receipts)</span></label>
          <input id={`${id}-email`} type="email" value={email} autoComplete="email" placeholder="you@example.com" onChange={(e) => setEmail(e.target.value)} aria-invalid={!!errors.email} aria-describedby={errors.email ? `${id}-email-err` : undefined} />
          {errors.email && <em id={`${id}-email-err`} role="alert">{errors.email}</em>}
        </div>
        <label className={`auth__check ${errors.agree ? 'is-error' : ''}`}>
          <input type="checkbox" checked={agree} onChange={(e) => setAgree(e.target.checked)} aria-invalid={!!errors.agree} aria-describedby={errors.agree ? `${id}-agree-err` : undefined} />
          <span>I agree to the <Link to="/terms">Terms of Service</Link> and <Link to="/privacy">Privacy Policy</Link></span>
        </label>
        {errors.agree && <em className="auth__field-err" id={`${id}-agree-err`} role="alert">{errors.agree}</em>}
        {errors.form && <p className="auth__error" role="alert">{errors.form}</p>}
        <button type="submit" className="btn btn--primary auth__submit" disabled={busy}>{busy ? <><span className="auth__spinner" aria-hidden="true" /> Creating your account…</> : <>Continue <ArrowRightIcon size={18} /></>}</button>
      </form>
      <p className="auth__switch">Date of birth, addresses and payment details can be added later from your profile.</p>
    </AuthLayout>
  )
}
