import { createContext, useContext } from 'react'
import type { StaffPrincipal } from './staffAuth'

/** refresh: re-reads the session from the backend (after the person changed MFA, their language, or signed out everywhere). */
export type StaffSession = { mode: 'mock' } | { mode: 'api'; principal: StaffPrincipal; logout: () => Promise<void>; refresh: () => Promise<void> }
export const StaffSessionContext = createContext<StaffSession>({ mode: 'mock' })
export const useStaffSession = (): StaffSession => useContext(StaffSessionContext)
