import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { Icon } from '../../../dashboard/components/ui'
import { t } from '../../../i18n/strings'
import { Badge } from '../../components/DataTable'
import { code, humanize, toneOfCode } from './helpers'

/**
 * The components the tabs of the finance page (Module 36) share: a status chip, plain rows for the backend's own JSON,
 * the buttons of a record, and one form dialog for every action — each action sends a reason, a confirmation or a
 * reference, never a status or a total.
 */
export function Chip({ value, locale, prefix, dot = true }: { value: string | null | undefined; locale: string; prefix?: string; dot?: boolean }) {
  return value ? <span data-status={value}><Badge tone={toneOfCode(value)} dot={dot}>{code(value, locale, prefix)}</Badge></span> : <span>—</span>
}

/** Unknown JSON of the backend (a run's result, a difference's detail) as plain rows: scalars as they are, anything nested as JSON text. */
export function Facts({ value, testId }: { value: unknown; testId?: string }) {
  if (value === null || value === undefined) return <p className="db-muted" data-testid={testId}>—</p>
  if (typeof value !== 'object') return <p data-testid={testId}>{String(value)}</p>
  const rows = Object.entries(value as Record<string, unknown>)
  if (rows.length === 0) return <p className="db-muted" data-testid={testId}>—</p>
  return <dl className="adm-details" data-testid={testId}>{rows.map(([k, v]) => <div key={k}><dt>{humanize(k)}</dt><dd>{v !== null && typeof v === 'object' ? <code className="adm-code">{JSON.stringify(v)}</code> : String(v ?? '—')}</dd></div>)}</dl>
}

export type FormField = {
  name: string; label: string; type?: 'text' | 'textarea' | 'date' | 'datetime-local' | 'select'
  options?: Array<{ value: string; label: string }>; required?: boolean; minLength?: number; hint?: string; initial?: string; placeholder?: string
}
/**
 * One dialog for every finance action: what is about to happen (`impact`), the fields the backend asks for, and the
 * backend's refusal when it comes. Mount it only while it is open (with a `key` per action) — it starts from the
 * fields' initial values each time.
 */
export function FormDialog({ title, text, impact, fields, confirmLabel, danger, busy, error, validate, onConfirm, onCancel, locale, testId }: {
  title: string; text?: string; impact?: string[]; fields: FormField[]; confirmLabel: string; danger?: boolean; busy?: boolean; error?: string | null
  validate?: (values: Record<string, string>) => Record<string, string> | null
  onConfirm: (values: Record<string, string>) => void | Promise<void>; onCancel: () => void; locale: string; testId?: string
}) {
  const [values, setValues] = useState<Record<string, string>>(() => Object.fromEntries(fields.map((f) => [f.name, f.initial ?? (f.type === 'select' ? f.options?.[0]?.value ?? '' : '')])))
  const [errors, setErrors] = useState<Record<string, string>>({})
  const hid = useId(); const ref = useRef<HTMLDivElement>(null); const cancelRef = useRef(onCancel)
  useEffect(() => { cancelRef.current = onCancel })
  useEffect(() => { const prev = document.activeElement as HTMLElement | null; ref.current?.querySelector<HTMLElement>('select, textarea, input, button')?.focus(); const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') cancelRef.current() }; document.addEventListener('keydown', onKey); return () => { document.removeEventListener('keydown', onKey); prev?.focus?.() } }, [])
  const submit = () => {
    const found: Record<string, string> = {}
    for (const f of fields) {
      const v = (values[f.name] ?? '').trim()
      if (f.required && v === '') found[f.name] = t('adm.finance.form.required', undefined, locale)
      else if (f.minLength && v !== '' && v.length < f.minLength) found[f.name] = t('adm.finance.form.minLength', { n: f.minLength }, locale)
    }
    const all = Object.keys(found).length ? found : validate?.(values) ?? {}
    setErrors(all)
    if (Object.keys(all).length === 0) void onConfirm(Object.fromEntries(Object.entries(values).map(([k, v]) => [k, v.trim()])))
  }
  const control = (f: FormField, fid: string) => {
    const common = { id: fid, value: values[f.name] ?? '', 'data-testid': `ff-${f.name}`, 'aria-invalid': !!errors[f.name], onChange: (e: { target: { value: string } }) => setValues((v) => ({ ...v, [f.name]: e.target.value })) }
    if (f.type === 'select') return <select className="db-select" {...common}>{(f.options ?? []).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select>
    if (f.type === 'textarea') return <textarea className="db-textarea" rows={3} {...common} />
    return <input className="db-input" type={f.type ?? 'text'} placeholder={f.placeholder} {...common} />
  }
  return (
    <div className="db-drawer__backdrop db-drawer__backdrop--center" onMouseDown={(e) => { if (e.target === e.currentTarget) onCancel() }}>
      <div className="db-dialog adm-dialog" role="dialog" aria-modal="true" aria-labelledby={hid} ref={ref} data-testid={testId ?? 'finance-dialog'}>
        <h2 id={hid}>{title}</h2>{text && <p className="db-muted">{text}</p>}
        {impact && impact.length > 0 && <div className="adm-impact" role="note"><Icon name="warning" size={16} /><ul>{impact.map((i) => <li key={i}>{i}</li>)}</ul></div>}
        {fields.map((f) => { const fid = `${hid}-${f.name}`; return (
          <div key={f.name} className={`db-field ${errors[f.name] ? 'db-field--error' : ''}`}>
            <label className="db-field__label" htmlFor={fid}>{f.label}{f.required && <span className="db-field__req" aria-hidden="true"> *</span>}</label>
            {control(f, fid)}
            {f.hint && !errors[f.name] && <p className="db-field__hint">{f.hint}</p>}
            {errors[f.name] && <p className="db-field__error" role="alert" data-testid={`ff-${f.name}-error`}>{errors[f.name]}</p>}
          </div>
        ) })}
        {error && <p className="db-field__error" role="alert" data-testid="finance-dialog-error">{error}</p>}
        <div className="db-dialog__actions"><button type="button" className="db-btn db-btn--ghost" onClick={onCancel} disabled={busy}>{t('dash.action.cancel', undefined, locale)}</button><button type="button" className={`db-btn ${danger ? 'db-btn--danger' : 'db-btn--primary'}`} disabled={busy} onClick={submit} data-testid="finance-dialog-confirm">{busy ? t('dash.action.saving', undefined, locale) : confirmLabel}</button></div>
      </div>
    </div>
  )
}

/** The buttons of a record: only the actions the backend's `can` allows; a sentence when there are none. */
export function Actions({ items, busy, locale, testId, prefix, children }: { items: Array<{ id: string; label: string; primary?: boolean; onClick: () => void } | null | false>; busy?: boolean; locale: string; testId: string; /** A button's test id is `${prefix}-${id}`. */ prefix: string; children?: ReactNode }) {
  const shown = items.filter((x): x is { id: string; label: string; primary?: boolean; onClick: () => void } => !!x)
  return <>
    <div className="db-actions" data-testid={testId}>{shown.map((x) => <button key={x.id} type="button" className={`btn ${x.primary ? 'btn--primary' : 'btn--outline'}`} disabled={busy} onClick={x.onClick} data-testid={`${prefix}-${x.id}`}>{x.label}</button>)}</div>
    {shown.length === 0 && <p className="db-muted" data-testid={`${testId}-none`}>{t('adm.finance.noActions', undefined, locale)}</p>}
    {children}
  </>
}
