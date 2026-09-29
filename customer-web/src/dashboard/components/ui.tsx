import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { formatMoney } from '../../i18n/format'
import { t } from '../../i18n/strings'
import type { OrderStatus } from '../../order/repositories'
import type { ItemAvailability } from '../../menu/repositories'

/** Shared dashboard UI primitives (Module 17): cards, badges, tabs, drawer, dialog, table, states, charts helpers. */
export const Icon = ({ name, size = 20 }: { name: string; size?: number }) => {
  const P: Record<string, ReactNode> = {
    overview: <><rect x="3" y="3" width="8" height="8" rx="2" /><rect x="13" y="3" width="8" height="8" rx="2" /><rect x="3" y="13" width="8" height="8" rx="2" /><rect x="13" y="13" width="8" height="8" rx="2" /></>,
    orders: <><path d="M6 3h12l2 4v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7z" /><path d="M4 7h16M9 11a3 3 0 0 0 6 0" /></>,
    menu: <><path d="M4 6h16M4 12h16M4 18h10" /></>,
    profile: <><path d="M3 21h18M5 21V9l7-5 7 5v12" /><path d="M10 21v-6h4v6" /></>,
    hours: <><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></>,
    pickup: <><path d="M4 8h16l-1.5 12h-13z" /><path d="M9 8V6a3 3 0 0 1 6 0v2" /></>,
    reviews: <><path d="m12 3 2.7 5.6 6.2.9-4.5 4.3 1.1 6.1L12 17l-5.5 2.9 1.1-6.1L3.1 9.5l6.2-.9z" /></>,
    staff: <><circle cx="9" cy="8" r="3.5" /><path d="M2.5 20a6.5 6.5 0 0 1 13 0" /><circle cx="17" cy="9" r="2.5" /><path d="M15.5 14.5a5 5 0 0 1 6 4.5" /></>,
    analytics: <><path d="M4 20V10M10 20V4M16 20v-8M22 20H2" /></>,
    notifications: <><path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4z" /><path d="M10 20a2 2 0 0 0 4 0" /></>,
    settings: <><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z" /></>,
    help: <><circle cx="12" cy="12" r="9" /><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 1-1 1.7" /><path d="M12 17h.01" /></>,
    check: <path d="m5 12 4 4L19 7" />, x: <path d="M6 6l12 12M18 6 6 18" />, plus: <path d="M12 5v14M5 12h14" />, search: <><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></>,
    chevron: <path d="m6 9 6 6 6-6" />, up: <path d="M12 19V5M5 12l7-7 7 7" />, down: <path d="M12 5v14M19 12l-7 7-7-7" />, more: <><circle cx="5" cy="12" r="1.5" /><circle cx="12" cy="12" r="1.5" /><circle cx="19" cy="12" r="1.5" /></>,
    burger: <path d="M4 7h16M4 12h16M4 17h16" />, back: <path d="M19 12H5m7-7-7 7 7 7" />, warning: <><path d="M12 3 2 21h20z" /><path d="M12 10v5M12 18h.01" /></>, money: <><rect x="3" y="6" width="18" height="12" rx="2" /><circle cx="12" cy="12" r="2.5" /></>,
    star: <path d="m12 3 2.7 5.6 6.2.9-4.5 4.3 1.1 6.1L12 17l-5.5 2.9 1.1-6.1L3.1 9.5l6.2-.9z" />, edit: <><path d="M4 20h4l10-10-4-4L4 16z" /><path d="m12.5 7.5 4 4" /></>, copy: <><rect x="8" y="8" width="12" height="12" rx="2" /><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2" /></>,
    trash: <><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" /></>, qr: <><rect x="3" y="3" width="7" height="7" /><rect x="14" y="3" width="7" height="7" /><rect x="3" y="14" width="7" height="7" /><path d="M14 14h3v3h-3zM20 14v7h-3" /></>, keypad: <><rect x="4" y="4" width="16" height="16" rx="2" /><path d="M8 8h.01M12 8h.01M16 8h.01M8 12h.01M12 12h.01M16 12h.01M8 16h8" /></>,
    timer: <><path d="M10 2h4M12 8v5l3 2" /><circle cx="12" cy="14" r="8" /></>, image: <><rect x="3" y="4" width="18" height="16" rx="2" /><circle cx="9" cy="10" r="2" /><path d="m21 17-6-6-9 9" /></>, location: <><path d="M12 22s7-6.2 7-12a7 7 0 0 0-14 0c0 5.8 7 12 7 12z" /><circle cx="12" cy="10" r="2.5" /></>, logout: <><path d="M10 17l5-5-5-5M15 12H3" /><path d="M13 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6" /></>,
    eye: <><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z" /><circle cx="12" cy="12" r="3" /></>, info: <><circle cx="12" cy="12" r="9" /><path d="M12 8h.01M11 12h1v4h1" /></>,
  }
  return <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" className="db-icon">{P[name] ?? P.info}</svg>
}

/** Image thumbnail with initials fallback — item / restaurant photography is still pending (CF-097). */
export function Thumb({ src, name, size = 40 }: { src: string | null | undefined; name: string; size?: number }) {
  const [broken, setBroken] = useState(false)
  const ini = name.trim().slice(0, 2).toUpperCase()
  if (!src || broken) return <span className="db-thumb db-thumb--ph" style={{ width: size, height: size }} aria-hidden="true">{ini}</span>
  return <img src={src} alt="" className="db-thumb" style={{ width: size, height: size }} onError={() => setBroken(true)} />
}
export function PageHeader({ title, lead, actions }: { title: string; lead?: string; actions?: ReactNode }) {
  return <div className="db-page__head"><div><h1 className="db-page__title">{title}</h1>{lead && <p className="db-page__lead">{lead}</p>}</div>{actions && <div className="db-page__actions">{actions}</div>}</div>
}
export function Card({ title, subtitle, actions, children, className = '', id, tone }: { title?: ReactNode; subtitle?: ReactNode; actions?: ReactNode; children: ReactNode; className?: string; id?: string; tone?: 'warn' | 'error' }) {
  const hid = useId()
  return (
    <section className={`db-card ${tone ? `db-card--${tone}` : ''} ${className}`} aria-labelledby={title ? hid : undefined} id={id}>
      {(title || actions) && <div className="db-card__head">{title && <div><h2 id={hid} className="db-card__title">{title}</h2>{subtitle && <p className="db-card__sub">{subtitle}</p>}</div>}{actions && <div className="db-card__actions">{actions}</div>}</div>}
      {children}
    </section>
  )
}
export function Delta({ value, suffix = '%', invert = false, locale }: { value: number; suffix?: string; invert?: boolean; locale: string }) {
  const good = invert ? value <= 0 : value >= 0
  return <span className={`db-delta ${good ? 'db-delta--up' : 'db-delta--down'}`}><Icon name={value >= 0 ? 'up' : 'down'} size={12} /> {Math.abs(value).toLocaleString(locale, { maximumFractionDigits: 1 })}{suffix}</span>
}
export function KpiCard({ icon, tone = 'orange', value, label, delta, invert, locale, testId }: { icon: string; tone?: 'orange' | 'blue' | 'green' | 'purple' | 'amber' | 'red'; value: ReactNode; label: string; delta?: number; invert?: boolean; locale: string; testId?: string }) {
  return <div className="db-kpi" data-testid={testId}><span className={`db-kpi__icon db-kpi__icon--${tone}`}><Icon name={icon} size={22} /></span><div className="db-kpi__body"><p className="db-kpi__value">{value}</p><p className="db-kpi__label">{label}</p></div>{delta !== undefined && <Delta value={delta} invert={invert} locale={locale} />}</div>
}

const ORDER_TONE: Record<OrderStatus, string> = { PAYMENT_PENDING: 'muted', CONFIRMED: 'red', AWAITING_RESTAURANT_ACCEPTANCE: 'red', ACCEPTED: 'blue', PREPARING: 'blue', READY_FOR_PICKUP: 'green', PICKUP_VERIFICATION: 'green', PICKED_UP: 'muted', COMPLETED: 'muted', CANCELLED: 'red', REJECTED: 'red', REFUND_PENDING: 'amber', REFUNDED: 'muted' }
export function StatusBadge({ status, locale, className = '' }: { status: OrderStatus; locale: string; className?: string }) {
  return <span className={`db-badge db-badge--${ORDER_TONE[status]} ${className}`} data-status={status}><span className="db-badge__dot" aria-hidden="true" />{t(`dash.status.${status}`, undefined, locale)}</span>
}
const AV_TONE: Record<ItemAvailability | 'inactive', string> = { available: 'green', sold_out: 'red', temporarily_unavailable: 'amber', unavailable: 'muted', inactive: 'muted' }
export function AvailabilityBadge({ availability, inactive, locale }: { availability: ItemAvailability; inactive?: boolean; locale: string }) {
  const key = inactive ? 'inactive' : availability
  return <span className={`db-badge db-badge--${AV_TONE[key]}`} data-availability={key}><span className="db-badge__dot" aria-hidden="true" />{t(`dash.availability.${key}`, undefined, locale)}</span>
}
export function Pill({ tone = 'muted', children }: { tone?: string; children: ReactNode }) { return <span className={`db-badge db-badge--${tone}`}>{children}</span> }
export const Money = ({ minor, currency, locale }: { minor: number; currency: string; locale: string }) => <span className="db-money">{formatMoney(minor, currency, locale)}</span>

export function Tabs<T extends string>({ tabs, value, onChange, label }: { tabs: Array<{ id: T; label: string; count?: number }>; value: T; onChange: (v: T) => void; label: string }) {
  return (
    <div className="db-tabs" role="tablist" aria-label={label}>
      {tabs.map((tab) => <button key={tab.id} type="button" role="tab" aria-selected={value === tab.id} className={`db-tab ${value === tab.id ? 'is-on' : ''}`} onClick={() => onChange(tab.id)} data-tab={tab.id}>{tab.label}{tab.count !== undefined && <span className={`db-tab__count ${tab.count > 0 && (tab.id === 'new') ? 'db-tab__count--hot' : ''}`}>{tab.count}</span>}</button>)}
    </div>
  )
}
export function EmptyState({ icon = 'info', title, text, action }: { icon?: string; title: string; text?: string; action?: ReactNode }) {
  return <div className="db-empty" role="status"><span className="db-empty__icon"><Icon name={icon} size={26} /></span><p className="db-empty__title">{title}</p>{text && <p className="db-empty__text">{text}</p>}{action && <div className="db-empty__action">{action}</div>}</div>
}
export function ErrorState({ title, text, onRetry, locale }: { title: string; text?: string; onRetry?: () => void; locale: string }) {
  return <div className="db-error" role="alert"><span className="db-empty__icon db-empty__icon--error"><Icon name="warning" size={24} /></span><p className="db-empty__title">{title}</p>{text && <p className="db-empty__text">{text}</p>}{onRetry && <button type="button" className="db-btn db-btn--primary" onClick={onRetry}>{t('dash.action.retry', undefined, locale)}</button>}</div>
}
export function Skeleton({ rows = 3, className = '' }: { rows?: number; className?: string }) {
  return <div className={`db-skeleton ${className}`} aria-busy="true" aria-live="polite">{Array.from({ length: rows }, (_, i) => <span key={i} className="db-skeleton__row" style={{ width: `${88 - (i % 3) * 14}%` }} />)}</div>
}

/** Accessible side drawer (dialog): Escape closes, focus moves in and returns, backdrop click closes. */
export function Drawer({ open, onClose, title, children, footer, wide }: { open: boolean; onClose: () => void; title: string; children: ReactNode; footer?: ReactNode; wide?: boolean }) {
  const ref = useRef<HTMLDivElement>(null); const hid = useId(); const closeRef = useRef(onClose); closeRef.current = onClose
  // Focus management runs once per open (BUG-050: depending on an inline onClose re-ran it on every keystroke and stole focus).
  useEffect(() => { if (!open) return; const prev = document.activeElement as HTMLElement | null; ref.current?.querySelector<HTMLElement>('.db-drawer__body input, .db-drawer__body select, .db-drawer__body textarea, .db-drawer__body button, button')?.focus(); const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') closeRef.current() }; document.addEventListener('keydown', onKey); document.body.classList.add('db-lock'); return () => { document.removeEventListener('keydown', onKey); document.body.classList.remove('db-lock'); prev?.focus() } }, [open])
  if (!open) return null
  return (
    <div className="db-drawer__backdrop" onMouseDown={(e) => { if (e.target === e.currentTarget) onClose() }}>
      <div className={`db-drawer ${wide ? 'db-drawer--wide' : ''}`} role="dialog" aria-modal="true" aria-labelledby={hid} ref={ref}>
        <div className="db-drawer__head"><h2 id={hid}>{title}</h2><button type="button" className="db-iconbtn" onClick={onClose} aria-label={t('dash.action.close')}><Icon name="x" /></button></div>
        <div className="db-drawer__body">{children}</div>
        {footer && <div className="db-drawer__foot">{footer}</div>}
      </div>
    </div>
  )
}
export function ConfirmDialog({ open, title, text, confirmLabel, cancelLabel, onConfirm, onCancel, danger, children }: { open: boolean; title: string; text?: string; confirmLabel: string; cancelLabel: string; onConfirm: () => void; onCancel: () => void; danger?: boolean; children?: ReactNode }) {
  const hid = useId(); const ref = useRef<HTMLDivElement>(null); const cancelRef = useRef(onCancel); cancelRef.current = onCancel
  useEffect(() => { if (!open) return; const prev = document.activeElement as HTMLElement | null; ref.current?.querySelector<HTMLElement>('select, input, textarea, button')?.focus(); const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') cancelRef.current() }; document.addEventListener('keydown', onKey); return () => { document.removeEventListener('keydown', onKey); prev?.focus() } }, [open])
  if (!open) return null
  return (
    <div className="db-drawer__backdrop db-drawer__backdrop--center" onMouseDown={(e) => { if (e.target === e.currentTarget) onCancel() }}>
      <div className="db-dialog" role="dialog" aria-modal="true" aria-labelledby={hid} ref={ref}>
        <h2 id={hid}>{title}</h2>{text && <p className="db-muted">{text}</p>}{children}
        <div className="db-dialog__actions"><button type="button" className="db-btn db-btn--ghost" onClick={onCancel}>{cancelLabel}</button><button type="button" className={`db-btn ${danger ? 'db-btn--danger' : 'db-btn--primary'}`} onClick={onConfirm}>{confirmLabel}</button></div>
      </div>
    </div>
  )
}
export function Field({ label, hint, error, required, children, id }: { label: string; hint?: string; error?: string; required?: boolean; children: ReactNode; id?: string }) {
  const hid = useId(); const fid = id ?? hid
  return <div className={`db-field ${error ? 'db-field--error' : ''}`}><label htmlFor={fid} className="db-field__label">{label}{required && <span className="db-field__req" aria-hidden="true"> *</span>}</label>{children}{hint && !error && <p className="db-field__hint" id={`${fid}-hint`}>{hint}</p>}{error && <p className="db-field__error" role="alert" id={`${fid}-err`}>{error}</p>}</div>
}
export function Toggle({ checked, onChange, label, disabled, testId }: { checked: boolean; onChange: (v: boolean) => void; label: string; disabled?: boolean; testId?: string }) {
  return <button type="button" role="switch" aria-checked={checked} aria-label={label} className={`db-toggle ${checked ? 'is-on' : ''}`} onClick={() => onChange(!checked)} disabled={disabled} data-testid={testId}><span className="db-toggle__knob" /></button>
}
export function Avatar({ name, size = 36 }: { name: string; size?: number }) { const ini = name.split(/\s+/).map((p) => p[0]).filter(Boolean).slice(0, 2).join('').toUpperCase(); return <span className="db-avatar" style={{ width: size, height: size, fontSize: size * 0.38 }} aria-hidden="true">{ini}</span> }
export function useToastMessage() { const [msg, setMsg] = useState<string | null>(null); useEffect(() => { if (!msg) return; const id = setTimeout(() => setMsg(null), 3500); return () => clearTimeout(id) }, [msg]); return { msg, toast: setMsg } }
export const ToastLine = ({ msg }: { msg: string | null }) => (msg ? <div className="db-toast" role="status" aria-live="polite">{msg}</div> : null)
