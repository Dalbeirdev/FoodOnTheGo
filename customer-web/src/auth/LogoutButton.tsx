import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from './AuthContext'
import './LogoutButton.css'

/** Logout with a confirmation dialog: clears the session and returns to Home. */
export default function LogoutButton({ className = '', children }: { className?: string; children: ReactNode }) {
  const { logout } = useAuth()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const dialog = useRef<HTMLDialogElement>(null)

  useEffect(() => {
    const d = dialog.current
    if (!d) return
    if (open && !d.open) d.showModal()
    if (!open && d.open) d.close()
  }, [open])

  const confirm = async () => {
    setBusy(true)
    try { await logout() } finally {
      setBusy(false); setOpen(false)
      navigate('/login?reason=logged-out', { replace: true })
    }
  }

  return (
    <>
      <button type="button" className={className} onClick={() => setOpen(true)}>{children}</button>
      <dialog ref={dialog} className="logout-dialog" aria-labelledby="logout-title" onClose={() => setOpen(false)} onCancel={() => setOpen(false)}>
        <h2 id="logout-title">Sign out of FoodOnTheGo?</h2>
        <p>You'll need to verify your mobile number again to see your orders and profile.</p>
        <div className="logout-dialog__actions">
          <button type="button" className="btn btn--outline" onClick={() => setOpen(false)} disabled={busy}>Cancel</button>
          <button type="button" className="btn btn--primary" onClick={confirm} disabled={busy} autoFocus>{busy ? 'Signing out…' : 'Sign Out'}</button>
        </div>
      </dialog>
    </>
  )
}
