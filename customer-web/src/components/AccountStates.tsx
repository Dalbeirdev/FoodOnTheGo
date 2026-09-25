import type { ReactNode } from 'react'
import './AccountStates.css'

/** Loading skeleton, error-with-retry and empty states shared by the account pages. */
export function LoadingState({ label = 'Loading…', rows = 3 }: { label?: string; rows?: number }) {
  return (
    <div className="ac-card ac-loading" role="status" aria-busy="true" aria-label={label}>
      {Array.from({ length: rows }).map((_, i) => <div key={i} className="ac-skel" />)}
      <span className="sr-only">{label}</span>
    </div>
  )
}

export function ErrorState({ message, onRetry }: { message: string; onRetry: () => void }) {
  return (
    <div className="ac-card ac-error" role="alert">
      <h3>Something went wrong</h3>
      <p>{message}</p>
      <button type="button" className="btn btn--outline" onClick={onRetry}>Try again</button>
    </div>
  )
}

export function EmptyState({ icon, title, text, action }: { icon: ReactNode; title: string; text: string; action?: ReactNode }) {
  return (
    <div className="ac-card ac-empty">
      <span className="ac-empty__icon" aria-hidden="true">{icon}</span>
      <h3>{title}</h3>
      <p>{text}</p>
      {action}
    </div>
  )
}

/** Accessible confirmation dialog used for destructive actions. */
export function ConfirmDialog({ open, title, text, confirmLabel = 'Confirm', danger = false, busy = false, onCancel, onConfirm }: { open: boolean; title: string; text: ReactNode; confirmLabel?: string; danger?: boolean; busy?: boolean; onCancel: () => void; onConfirm: () => void }) {
  if (!open) return null
  return (
    <div className="ac-modal" role="dialog" aria-modal="true" aria-labelledby="confirm-title" onClick={(e) => { if (e.target === e.currentTarget && !busy) onCancel() }}>
      <div className="ac-modal__box ac-modal__box--sm">
        <h2 id="confirm-title">{title}</h2>
        <div className="ac-confirm__text">{text}</div>
        <div className="ac-modal__actions">
          <button type="button" className="btn btn--outline" onClick={onCancel} disabled={busy}>Cancel</button>
          <button type="button" className={`btn ${danger ? 'btn--danger' : 'btn--primary'}`} onClick={onConfirm} disabled={busy} autoFocus>{busy ? 'Please wait…' : confirmLabel}</button>
        </div>
      </div>
    </div>
  )
}
