import { useCallback, useEffect, useRef, useState, type DependencyList, type ReactNode } from 'react'
import { formatMoney } from '../../i18n/format'
import { t } from '../../i18n/strings'
import { Badge } from '../components/DataTable'
import { useAdmin } from '../AdminContext'

/** Async loader with loading / ready / error state and reload. */
export function useLoad<T>(fn: () => Promise<T>, deps: DependencyList) {
  const [data, setData] = useState<T | null>(null); const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading'); const seq = useRef(0)
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const load = useCallback(async () => { const n = ++seq.current; setState('loading'); try { const d = await fn(); if (n === seq.current) { setData(d); setState('ready') } } catch { if (n === seq.current) setState('error') } }, deps)
  useEffect(() => { void load() }, [load])
  const refresh = useCallback(async () => { try { const d = await fn(); setData(d); setState('ready') } catch { setState('error') } }, [fn]) // eslint-disable-line react-hooks/exhaustive-deps
  return { data, state, reload: load, refresh, setData }
}
export function usePageTitle(key: string) { const a = useAdmin(); useEffect(() => { document.title = `${t(key, undefined, a.locale)} · ${t('adm.title', undefined, a.locale)}` }, [key, a.locale]) }

export const fmtDate = (iso: string | null | undefined, locale: string) => (iso ? new Date(iso).toLocaleDateString(locale, { year: 'numeric', month: 'short', day: 'numeric' }) : '—')
export const fmtDateTime = (iso: string | null | undefined, locale: string) => (iso ? new Date(iso).toLocaleString(locale, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—')
export const fmtTime = (iso: string | null | undefined, locale: string, timeZone?: string) => (iso ? new Date(iso).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit', timeZone }) : '—')
export const money = (minor: number, currency: string, locale: string) => formatMoney(minor, currency, locale)
export const pct = (v: number, locale: string) => `${v.toLocaleString(locale, { maximumFractionDigits: 1 })}%`

const TONES: Record<string, string> = {
  DRAFT: 'muted', SUBMITTED: 'blue', UNDER_REVIEW: 'amber', APPROVED: 'green', REJECTED: 'red', SUSPENDED: 'red',
  ACTIVE: 'green', RESTRICTED: 'amber', DEACTIVATED: 'muted', INVITED: 'blue', DISABLED: 'muted',
  CREATED: 'muted', PENDING: 'amber', AUTHORIZED: 'blue', CAPTURED: 'green', FAILED: 'red', CANCELLED: 'muted', REFUND_PENDING: 'amber', PARTIALLY_REFUNDED: 'purple', REFUNDED: 'purple', PROCESSING: 'blue', PARTIAL: 'purple', COMPLETED: 'green', PAID: 'green', ON_HOLD: 'amber',
  PUBLISHED: 'green', HIDDEN: 'muted', FLAGGED: 'red', PENDING_MODERATION: 'amber',
  SCHEDULED: 'blue', PAUSED: 'amber', EXPIRED: 'muted', SENT: 'green',
  OPEN: 'red', IN_PROGRESS: 'blue', WAITING_CUSTOMER: 'amber', WAITING_RESTAURANT: 'amber', RESOLVED: 'green', CLOSED: 'muted',
  LOW: 'muted', MEDIUM: 'amber', HIGH: 'red', URGENT: 'red',
  OPERATIONAL: 'green', DEGRADED: 'amber', OUTAGE: 'red', UNKNOWN: 'muted', SUCCESS: 'green', DENIED: 'red', PILOT: 'amber', INACTIVE: 'muted',
  NOT_SUBMITTED: 'muted', low: 'muted', medium: 'amber', high: 'red',
}
export const toneOf = (status: string) => TONES[status] ?? 'muted'
/** Status pill: `prefix` is the string-table namespace (e.g. adm.restaurants.status). */
export function StatusPill({ status, prefix, dot }: { status: string; prefix: string; dot?: boolean }) { const a = useAdmin(); return <Badge tone={toneOf(status)} dot={dot}>{t(`${prefix}.${status}`, undefined, a.locale)}</Badge> }
export function Stars({ rating }: { rating: number }) { return <span className="adm-stars" aria-label={`${rating} / 5`} role="img">{'★'.repeat(Math.round(rating))}<span style={{ color: '#d1d5db' }}>{'★'.repeat(5 - Math.round(rating))}</span></span> }
export function Cell({ primary, secondary, thumb }: { primary: ReactNode; secondary?: ReactNode; thumb?: ReactNode }) { return <span className="adm-cell">{thumb}<span className="adm-cell__text"><b dir="auto">{primary}</b>{secondary && <small dir="auto">{secondary}</small>}</span></span> }
export const marketOptions = (codes: string[], locale: string) => [{ value: 'all', label: t('adm.market.all', undefined, locale) }, ...codes.map((c) => ({ value: c, label: c }))]
/** Shows the market the list is scoped to (set in the header selector). */
export function MarketScopeChip() { const a = useAdmin(); return a.marketModel ? <span className="adm-scope-chip" data-testid="market-scope"><span className="db-sr-only">{t('adm.market.label', undefined, a.locale)}: </span>{a.marketModel.displayName} · {a.marketModel.defaultCurrency}</span> : null }
export const KNOWN_MARKET_CODES = ['IN', 'US', 'GB', 'JP', 'FR', 'AE']
