import { formatMoney } from '../i18n/format'
import { t } from '../i18n/strings'
import { SupportError } from './api/apiSupport'
import type { SupportLink, SupportMoney, SupportStatus } from './repositories'

/**
 * The words of support cases (Module 37). The backend sends CODES (categories, statuses, the status of a linked
 * record); the labels are client strings. A code without a label is shown in plain words — never as SNAKE_CASE and
 * never replaced by a guess.
 */
export const humanize = (code: string): string => { const s = code.toLowerCase().replace(/_/g, ' '); return s.charAt(0).toUpperCase() + s.slice(1) }
const label = (prefix: string, code: string, locale: string): string => { const key = `${prefix}.${code}`; const s = t(key, undefined, locale); return s === key ? humanize(code) : s }

export const categoryLabel = (code: string, locale: string): string => label('sup.category', code, locale)
export const subcategoryLabel = (code: string, locale: string): string => label('sup.subcategory', code, locale)
export const statusLabel = (status: string, locale: string): string => label('sup.status', status, locale)
export const linkTypeLabel = (type: string, locale: string): string => label('sup.link.type', type, locale)
/** The linked record's own status, in the words of its own screens. */
export const linkStatusLabel = (link: Pick<SupportLink, 'type' | 'status'>, locale: string): string | null =>
  link.status ? label(link.type === 'SETTLEMENT' ? 'dash.earn.settlementStatus' : link.type === 'PAYOUT' ? 'dash.earn.payoutStatus' : `sup.link.status.${link.type}`, link.status, locale) : null
export const refundStatusLabel = (status: string, locale: string): string => label('sup.link.status.REFUND', status, locale)

export type SupportTone = 'open' | 'waiting' | 'done' | 'closed'
/** How a status chip is coloured: waiting for the requester stands out; closed and withdrawn are quiet. */
export const statusTone = (status: SupportStatus | string): SupportTone =>
  status === 'WAITING_FOR_YOU' ? 'waiting' : status === 'RESOLVED' ? 'done' : status === 'CLOSED' || status === 'CANCELLED' ? 'closed' : 'open'

export const supportMoney = (m: SupportMoney, locale: string): string => formatMoney(m.amount, m.currency, locale)
export const supportDay = (iso: string | null, locale: string): string => (iso ? new Date(iso).toLocaleDateString(locale, { dateStyle: 'medium' }) : '')
export const supportDayTime = (iso: string | null, locale: string): string => (iso ? new Date(iso).toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' }) : '')

/**
 * What a failed call says on screen: the backend's own sentence for a refusal it explains (the first field message of
 * a 422), a fixed sentence for "not allowed", and a plain one for everything else. Never "sent" when it was not.
 */
export function supportErrorText(e: unknown, locale: string): string {
  if (!(e instanceof SupportError)) return t('sup.error.generic', undefined, locale)
  if (e.kind === 'forbidden') return t('sup.error.forbidden', undefined, locale)
  if (!e.presentable) return t('sup.error.generic', undefined, locale)
  return e.kind === 'validation' ? Object.values(e.fields)[0]?.[0] ?? e.message : e.message
}

/** Unicode code points, as the backend counts a length. */
export const textLength = (s: string): number => Array.from(s).length
