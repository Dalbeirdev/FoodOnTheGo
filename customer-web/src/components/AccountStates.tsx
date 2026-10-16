import { useEffect, useId, useRef, useState, type KeyboardEvent, type ReactNode } from 'react'
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

/**
 * Accessible confirmation dialog used for destructive actions. Module 41: the first focus is the safe choice (Cancel),
 * Escape cancels, Tab stays inside the dialog and the focus goes back to what opened it.
 */
export function ConfirmDialog(props: { open: boolean; title: string; text: ReactNode; confirmLabel?: string; danger?: boolean; busy?: boolean; onCancel: () => void; onConfirm: () => void }) {
  if (!props.open) return null
  return <ConfirmDialogBox {...props} />
}

function ConfirmDialogBox({ title, text, confirmLabel = 'Confirm', danger = false, busy = false, onCancel, onConfirm }: { title: string; text: ReactNode; confirmLabel?: string; danger?: boolean; busy?: boolean; onCancel: () => void; onConfirm: () => void }) {
  const titleId = useId()
  const box = useRef<HTMLDivElement>(null)
  // Read while rendering: autoFocus moves the focus to Cancel before any effect runs.
  const [opener] = useState(() => (document.activeElement instanceof HTMLElement ? document.activeElement : null))
  useEffect(() => () => { if (opener?.isConnected) opener.focus() }, [opener])
  const onKeyDown = (e: KeyboardEvent<HTMLDivElement>) => {
    if (e.key === 'Escape' && !busy) { e.preventDefault(); onCancel(); return }
    if (e.key !== 'Tab' || !box.current) return
    const focusable = Array.from(box.current.querySelectorAll<HTMLElement>('button:not([disabled]), a[href], input:not([disabled]), [tabindex]:not([tabindex="-1"])'))
    if (focusable.length === 0) return
    const first = focusable[0], last = focusable[focusable.length - 1]
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus() } else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus() }
  }
  return (
    <div className="ac-modal" role="dialog" aria-modal="true" aria-labelledby={titleId} onKeyDown={onKeyDown} onClick={(e) => { if (e.target === e.currentTarget && !busy) onCancel() }}>
      <div className="ac-modal__box ac-modal__box--sm" ref={box}>
        <h2 id={titleId}>{title}</h2>
        <div className="ac-confirm__text">{text}</div>
        <div className="ac-modal__actions">
          <button type="button" className="btn btn--outline" onClick={onCancel} disabled={busy} autoFocus>Cancel</button>
          <button type="button" className={`btn ${danger ? 'btn--danger' : 'btn--primary'}`} onClick={onConfirm} disabled={busy}>{busy ? 'Please wait…' : confirmLabel}</button>
        </div>
      </div>
    </div>
  )
}
