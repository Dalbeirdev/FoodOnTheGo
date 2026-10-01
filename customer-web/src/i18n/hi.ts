/** Hindi bundle: the two staff tools (Restaurant Dashboard, Platform Admin) and their sign-in screens. Loaded on demand — the customer site is not translated. */
import { adminStringsHi } from '../admin/strings.hi'
import { staffStringsHi } from '../auth/staff/strings.hi'
import { dashStringsHi } from '../dashboard/strings.hi'
import { sharedStringsHi } from './shared.hi'

export const hi: Record<string, string> = { ...sharedStringsHi, ...dashStringsHi, ...adminStringsHi, ...staffStringsHi }
