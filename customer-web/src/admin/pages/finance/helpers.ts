import { ApiError } from '../../../api/client'
import { t } from '../../../i18n/strings'
import type { ApiAdminFinanceRepository, MoneyDto } from '../../api/ApiAdminFinanceRepository'
import { parseMinor } from '../../components/RefundRequestDialog'
import { money } from '../shared'

/**
 * What the tabs of the finance page (Module 36) share that is not a component: the URL state, the labels of the
 * backend's codes, money as the backend sent it, and the parsing of what an administrator types.
 */
export const FINANCE_DEFAULTS = { tab: '', view: '', queue: 'all', page: '1', open: '' }
export type FinanceUrl = typeof FINANCE_DEFAULTS
export type FinanceTabProps = { repo: ApiAdminFinanceRepository; s: FinanceUrl; set: (patch: Partial<FinanceUrl>) => void }
export const PAGE_SIZE = 10

export const humanize = (code: string) => { const s = code.toLowerCase().replace(/_/g, ' '); return s.charAt(0).toUpperCase() + s.slice(1) }
/** The label of a backend code: the string table's when it has one, else the code in plain words. */
export const code = (value: string | null | undefined, locale: string, prefix = 'adm.finance.code') => {
  if (!value) return '—'
  const key = `${prefix}.${value}`; const s = t(key, undefined, locale)
  return s === key ? humanize(value) : s
}
/** Money exactly as the backend sent it — its own amount in its own currency; nothing is added or converted here. */
export const fm = (m: MoneyDto | null | undefined, locale: string) => (m ? money(m.amount, m.currency, locale) : '—')

const TONE: Record<string, string> = {
  CALCULATED: 'amber', REVIEW_REQUIRED: 'red', APPROVED: 'blue', PAYMENT_PENDING: 'blue', PAID: 'green', FAILED: 'red', CLOSED: 'muted', CANCELLED: 'muted',
  REQUIRED: 'amber', SUBMITTING: 'blue', PROCESSING: 'blue', UNKNOWN: 'red',
  ACTIVE: 'green', SCHEDULED: 'blue', ENDED: 'muted', WITHDRAWN: 'muted', RELEASED: 'muted',
  OPEN: 'red', INVESTIGATING: 'amber', RESOLVED: 'green', IGNORED_WITH_REASON: 'muted',
  CRITICAL: 'red', HIGH: 'red', MEDIUM: 'amber', LOW: 'muted',
  PENDING_DECISION: 'amber', PENDING_APPROVAL: 'amber', POSTED: 'green', PENDING: 'amber', MATCHED: 'green', DIFFERENCES: 'red', DIFFERENCE: 'red', UNMATCHED: 'amber',
  // Green only for a word that means "checked and fine": never checked, a development provider and "not configured" are never green.
  BALANCED: 'green', OK: 'green', UP_TO_DATE: 'green', BEHIND: 'amber', PROBLEMS: 'red', RUNNING: 'blue', RECOGNIZED: 'green', VOID: 'muted', NEVER_CHECKED: 'amber', NEVER_RUN: 'amber', FAKE_PROVIDER: 'amber', NOT_CONFIGURED: 'muted', UNAVAILABLE: 'red', CONFIGURED: 'green', DISABLED: 'muted',
  VERIFIED: 'green', PENDING_VERIFICATION: 'amber', SUSPENDED: 'red', TEST: 'amber',
}
export const toneOfCode = (value: string) => TONE[value] ?? 'muted'
/** The backend's refusal in the backend's words (the first field message of a 422), a plain sentence otherwise. */
export const actionError = (e: unknown, locale: string): string => {
  if (e instanceof ApiError && e.kind === 'forbidden') return t('adm.finance.forbidden', undefined, locale)
  if (e instanceof ApiError && e.kind !== 'server' && e.kind !== 'network') return Object.values(e.errors)[0]?.[0] ?? e.message
  return t('adm.finance.actionFailed', undefined, locale)
}

/** "12.5" → 1250 basis points, digit by digit — never through a floating-point number. Null when it is not a percentage from 0 to 100 with at most two decimals. */
export function percentToBps(text: string): number | null {
  const m = /^(\d{1,3})(?:\.(\d{1,2}))?$/.exec(text.trim())
  if (!m) return null
  const bps = Number(m[1]) * 100 + Number((m[2] ?? '').padEnd(2, '0'))
  return bps > 10000 ? null : bps
}
export const bpsToPercent = (bps: number) => `${Math.trunc(bps / 100)}.${String(Math.abs(bps) % 100).padStart(2, '0')}%`
/** Decimal text → integer minor units of the currency; "0" is an amount too when `allowZero`. */
export const amountToMinor = (text: string, currency: string, allowZero = false): number | null => (allowZero && /^0+(\.0+)?$/.test(text.trim()) ? 0 : parseMinor(text.trim(), currency))
export const isUuid = (v: string) => /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(v.trim())
