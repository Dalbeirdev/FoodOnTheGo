import { useCallback, useEffect, useId, useMemo, useRef, useState, type ReactNode } from 'react'
import { useSearchParams } from 'react-router-dom'
import { t } from '../../i18n/strings'
import { EmptyState, ErrorState, Icon, Skeleton } from '../../dashboard/components/ui'

/**
 * Reusable admin table primitives (Module 18): URL-backed filter state, search, filters, sorting, pagination,
 * loading / empty / error states, row actions and a mobile card fallback. Same styling as the Restaurant Dashboard tables.
 */

/** Filter / tab / page state lives in the URL so views are shareable and survive reloads. */
export function useUrlState<T extends Record<string, string>>(defaults: T): [T, (patch: Partial<T>) => void] {
  const [params, setParams] = useSearchParams()
  const state = useMemo(() => { const s = { ...defaults } as Record<string, string>; for (const k of Object.keys(defaults)) { const v = params.get(k); if (v !== null) s[k] = v } return s as T }, [params, defaults])
  const set = useCallback((patch: Partial<T>) => { const next = new URLSearchParams(params); for (const [k, v] of Object.entries(patch)) { if (v === undefined || v === null || v === '' || v === defaults[k]) next.delete(k); else next.set(k, String(v)) } if (!('page' in patch)) next.delete('page'); setParams(next, { replace: true }) }, [params, setParams, defaults])
  return [state, set]
}

export type Column<T> = { id: string; label: string; render: (row: T) => ReactNode; sortable?: boolean; width?: string; align?: 'start' | 'end'; hideMobile?: boolean; primary?: boolean }
export function DataTable<T>({ columns, rows, keyOf, state, total, page, pageSize, onPage, sort, onSort, empty, error, onRetry, locale, testId, actions, onRowClick, caption }: {
  columns: Array<Column<T>>; rows: T[]; keyOf: (r: T) => string; state: 'loading' | 'ready' | 'error'; total: number; page: number; pageSize: number; onPage: (p: number) => void
  sort?: string; onSort?: (id: string) => void; empty: { title: string; text?: string; icon?: string }; error?: { title: string; text?: string }; onRetry?: () => void; locale: string; testId?: string
  actions?: (row: T) => ReactNode; onRowClick?: (row: T) => void; caption: string
}) {
  const pages = Math.max(1, Math.ceil(total / pageSize))
  if (state === 'error') return <ErrorState title={error?.title ?? t('adm.error.loadTitle', undefined, locale)} text={error?.text ?? t('adm.error.loadText', undefined, locale)} onRetry={onRetry} locale={locale} />
  if (state === 'loading') return <div className="adm-table-loading" data-testid={testId ? `${testId}-loading` : undefined}><Skeleton rows={5} /></div>
  if (rows.length === 0) return <EmptyState icon={empty.icon ?? 'info'} title={empty.title} text={empty.text} />
  return (
    <div className="adm-table" data-testid={testId}>
      <div className="db-table-wrap" tabIndex={0}>
        <table className="db-table adm-table__table">
          <caption className="db-sr-only">{caption}</caption>
          <thead><tr>{columns.map((c) => <th key={c.id} scope="col" style={{ width: c.width }} className={`${c.align === 'end' ? 'adm-td--end' : ''} ${c.hideMobile ? 'adm-hide-mobile' : ''}`} aria-sort={sort && c.sortable ? (sort === c.id ? 'descending' : 'none') : undefined}>{c.sortable && onSort ? <button type="button" className={`adm-sort ${sort === c.id ? 'is-on' : ''}`} onClick={() => onSort(c.id)}>{c.label}<Icon name={sort === c.id ? 'down' : 'chevron'} size={12} /></button> : c.label}</th>)}{actions && <th scope="col" className="adm-td--end">{t('adm.table.actions', undefined, locale)}</th>}</tr></thead>
          <tbody>{rows.map((r) => <tr key={keyOf(r)} className={onRowClick ? 'adm-row--link' : ''} onClick={onRowClick ? () => onRowClick(r) : undefined}>{columns.map((c) => <td key={c.id} className={`${c.align === 'end' ? 'adm-td--end' : ''} ${c.hideMobile ? 'adm-hide-mobile' : ''} ${c.primary ? 'adm-td--primary' : ''}`}>{c.render(r)}</td>)}{actions && <td className="adm-td--end adm-td--actions" onClick={(e) => e.stopPropagation()}><div className="db-table__actions">{actions(r)}</div></td>}</tr>)}</tbody>
        </table>
      </div>
      <Pagination page={page} pages={pages} total={total} pageSize={pageSize} onPage={onPage} locale={locale} />
    </div>
  )
}
export function Pagination({ page, pages, total, pageSize, onPage, locale }: { page: number; pages: number; total: number; pageSize: number; onPage: (p: number) => void; locale: string }) {
  if (total <= pageSize && page === 1) return <p className="adm-pager__info">{t('adm.table.showing', { from: total ? 1 : 0, to: total, total }, locale)}</p>
  return (
    <nav className="adm-pager" aria-label={t('adm.table.pagination', undefined, locale)}>
      <p className="adm-pager__info">{t('adm.table.showing', { from: (page - 1) * pageSize + 1, to: Math.min(total, page * pageSize), total }, locale)}</p>
      <div className="adm-pager__btns">
        <button type="button" className="db-btn db-btn--outline db-btn--sm" disabled={page <= 1} onClick={() => onPage(page - 1)}>{t('adm.table.prev', undefined, locale)}</button>
        <span className="adm-pager__page" aria-current="page">{t('adm.table.pageOf', { page, pages }, locale)}</span>
        <button type="button" className="db-btn db-btn--outline db-btn--sm" disabled={page >= pages} onClick={() => onPage(page + 1)}>{t('adm.table.next', undefined, locale)}</button>
      </div>
    </nav>
  )
}

/** Search box + filter selects row above a table. */
export function Toolbar({ children, search, onSearch, placeholder, locale }: { children?: ReactNode; search?: string; onSearch?: (v: string) => void; placeholder?: string; locale: string }) {
  const [local, setLocal] = useState(search ?? ''); const first = useRef(true)
  useEffect(() => { setLocal(search ?? '') }, [search])
  useEffect(() => { if (first.current) { first.current = false; return } if (!onSearch || local === (search ?? '')) return; const id = setTimeout(() => onSearch(local), 250); return () => clearTimeout(id) }, [local, onSearch, search])
  return (
    <div className="db-toolbar adm-toolbar">
      {onSearch && <label className="db-search"><span className="db-sr-only">{placeholder ?? t('adm.search.placeholder', undefined, locale)}</span><Icon name="search" size={18} /><input className="db-input" type="search" value={local} onChange={(e) => setLocal(e.target.value)} placeholder={placeholder ?? t('adm.search.placeholder', undefined, locale)} data-testid="table-search" /></label>}
      {children}
    </div>
  )
}
export function Select({ label, value, onChange, options, testId }: { label: string; value: string; onChange: (v: string) => void; options: Array<{ value: string; label: string }>; testId?: string }) {
  const id = useId()
  return <label className="adm-select"><span className="db-sr-only">{label}</span><select id={id} className="db-select db-input--sm" value={value} onChange={(e) => onChange(e.target.value)} aria-label={label} data-testid={testId}>{options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select></label>
}
export function Badge({ tone, children, dot }: { tone: string; children: ReactNode; dot?: boolean }) { return <span className={`db-badge db-badge--${tone}`}>{dot && <span className="db-badge__dot" aria-hidden="true" />}{children}</span> }

/** Structured confirmation for destructive / reversible-with-audit actions: reason is mandatory when `requireReason`. */
export function ReasonDialog({ open, title, text, confirmLabel, onConfirm, onCancel, locale, danger = true, requireReason = true, categories, categoryLabel, extraFields, impact, busy, testId, reasonLabel }: {
  open: boolean; title: string; text?: string; confirmLabel: string; onConfirm: (reason: string, category: string) => void | Promise<void>; onCancel: () => void; locale: string; danger?: boolean; requireReason?: boolean
  categories?: Array<{ value: string; label: string }>; categoryLabel?: string; extraFields?: ReactNode; impact?: string[]; busy?: boolean; testId?: string; reasonLabel?: string
}) {
  const [reason, setReason] = useState(''); const [category, setCategory] = useState(categories?.[0]?.value ?? ''); const [touched, setTouched] = useState(false)
  const hid = useId(); const ref = useRef<HTMLDivElement>(null); const cancelRef = useRef(onCancel); cancelRef.current = onCancel
  useEffect(() => { if (!open) return; setReason(''); setTouched(false); setCategory(categories?.[0]?.value ?? ''); const prev = document.activeElement as HTMLElement | null; ref.current?.querySelector<HTMLElement>('select, textarea, input, button')?.focus(); const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') cancelRef.current() }; document.addEventListener('keydown', onKey); return () => { document.removeEventListener('keydown', onKey); prev?.focus?.() } }, [open]) // eslint-disable-line react-hooks/exhaustive-deps
  if (!open) return null
  const invalid = requireReason && reason.trim().length < 3
  return (
    <div className="db-drawer__backdrop db-drawer__backdrop--center" onMouseDown={(e) => { if (e.target === e.currentTarget) onCancel() }}>
      <div className="db-dialog adm-dialog" role="dialog" aria-modal="true" aria-labelledby={hid} ref={ref} data-testid={testId ?? 'reason-dialog'}>
        <h2 id={hid}>{title}</h2>{text && <p className="db-muted">{text}</p>}
        {impact && impact.length > 0 && <div className="adm-impact" role="note"><Icon name="warning" size={16} /><ul>{impact.map((i) => <li key={i}>{i}</li>)}</ul></div>}
        {categories && <div className="db-field"><label className="db-field__label" htmlFor={`${hid}-cat`}>{categoryLabel ?? t('adm.dialog.category', undefined, locale)}</label><select id={`${hid}-cat`} className="db-select" value={category} onChange={(e) => setCategory(e.target.value)} data-testid="reason-category">{categories.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}</select></div>}
        {extraFields}
        <div className={`db-field ${touched && invalid ? 'db-field--error' : ''}`}><label className="db-field__label" htmlFor={`${hid}-reason`}>{reasonLabel ?? (requireReason ? t('adm.dialog.reasonRequired', undefined, locale) : t('adm.dialog.reasonOptional', undefined, locale))}</label><textarea id={`${hid}-reason`} className="db-textarea" value={reason} onChange={(e) => setReason(e.target.value)} onBlur={() => setTouched(true)} rows={3} data-testid="reason-input" aria-invalid={touched && invalid} />{touched && invalid && <p className="db-field__error" role="alert">{t('adm.dialog.reasonError', undefined, locale)}</p>}</div>
        <div className="db-dialog__actions"><button type="button" className="db-btn db-btn--ghost" onClick={onCancel} disabled={busy}>{t('dash.action.cancel', undefined, locale)}</button><button type="button" className={`db-btn ${danger ? 'db-btn--danger' : 'db-btn--primary'}`} disabled={busy} onClick={() => { setTouched(true); if (invalid) return; void onConfirm(reason.trim(), category) }} data-testid="reason-confirm">{busy ? t('dash.action.saving', undefined, locale) : confirmLabel}</button></div>
      </div>
    </div>
  )
}

/** Definition list used by details drawers. */
export function Details({ rows }: { rows: Array<[string, ReactNode]> }) { return <dl className="adm-details">{rows.map(([k, v]) => <div key={k}><dt>{k}</dt><dd>{v}</dd></div>)}</dl> }
export function Stat({ label, value, tone }: { label: string; value: ReactNode; tone?: string }) { return <div className={`adm-stat ${tone ? `adm-stat--${tone}` : ''}`}><span className="adm-stat__value">{value}</span><span className="adm-stat__label">{label}</span></div> }
export function DevNote({ children }: { children: ReactNode }) { return <p className="adm-devnote" role="note"><Icon name="info" size={14} /> {children}</p> }
