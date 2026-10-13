import { createContext, useContext } from 'react'
import { orderMode, type OrderSource } from '../order/orderMode'
import { ApiSupportRepository } from './api/apiSupport'
import type { SupportRepository } from './repositories'

/**
 * Where support cases come from (Module 37).
 *
 * Support follows the ORDER domain, like refunds (Module 35) and finance (Module 36): with the backend's orders
 * (`VITE_ORDER_MODE=api`) cases are the backend's; otherwise there is NO support backend and no repository — the
 * screens say so and the help links stay what they were. There is no development data for cases: a made-up
 * conversation with support would be worse than none.
 */
export function selectSupportRepository(mode: OrderSource): SupportRepository | null {
  return mode === 'api' ? new ApiSupportRepository() : null
}

export const supportRepository: SupportRepository | null = selectSupportRepository(orderMode())

/**
 * What the screens read. The default is the module's own choice (frozen at import, like every other domain); a test
 * — or a host that wants another source — provides a repository through the context instead of flipping a mode.
 */
export type SupportSource = { repository: SupportRepository | null }
export const SupportContext = createContext<SupportSource>({ repository: supportRepository })
export const useSupport = (): SupportSource => useContext(SupportContext)

const query = (params: Record<string, string | null | undefined>): string => {
  const parts = Object.entries(params).filter(([, v]) => typeof v === 'string' && v !== '').map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v as string)}`)
  return parts.length > 0 ? `?${parts.join('&')}` : ''
}
/** The "new case" page with a record of the customer's own already attached (an order number, a refund or payment reference — never personal data). */
export const newCaseLink = (link: { order?: string | null; payment?: string | null; refund?: string | null; category?: string | null } = {}): string =>
  `/help/cases/new${query({ refund: link.refund, payment: link.payment, order: link.order, category: link.category })}`
export const caseLink = (reference: string): string => `/help/cases/${encodeURIComponent(reference)}`
