import { createContext, useContext, useMemo, useState, type ReactNode } from 'react'
import { useAuth } from '../auth/AuthContext'

export type Profile = {
  name: string
  email: string
  emailVerified: boolean
  phone: string
  phoneVerified: boolean
  dob: string
  gender: 'male' | 'female' | 'other' | ''
  language: string
  cuisines: string[]
  searchRadiusKm: number
  notifications: boolean
  emailUpdates: boolean
  memberSince: string
  accountType: string
  status: 'active' | 'suspended'
}

type ProfileApi = {
  profile: Profile
  /** Identity fields (name/email/phone) persist through the API; preferences stay local until their module. */
  update: (patch: Partial<Profile>) => Promise<void>
}

/** Preference defaults shown until the preferences module stores them server-side. */
const LOCAL_DEFAULTS: Omit<Profile, 'name' | 'email' | 'emailVerified' | 'phone' | 'phoneVerified' | 'memberSince'> = {
  dob: '',
  gender: '',
  language: 'English',
  cuisines: [],
  searchRadiusKm: 20,
  notifications: true,
  emailUpdates: true,
  accountType: 'Individual',
  status: 'active',
}

const EMPTY: Profile = { name: '', email: '', emailVerified: false, phone: '', phoneVerified: false, memberSince: '', ...LOCAL_DEFAULTS }

const ProfileContext = createContext<ProfileApi | null>(null)

export function ProfileProvider({ children }: { children: ReactNode }) {
  const auth = useAuth()
  const [local, setLocal] = useState(LOCAL_DEFAULTS)

  // Identity comes from the authenticated user; nothing is invented when signed out.
  const profile = useMemo<Profile>(() => {
    const u = auth.user
    if (!u) return EMPTY
    return { ...local, name: u.name, email: u.email ?? '', emailVerified: false, phone: u.phone, phoneVerified: true, memberSince: u.memberSince }
  }, [auth.user, local])

  const api = useMemo<ProfileApi>(() => ({
    profile,
    update: async (patch) => {
      const { name, email, phone, ...rest } = patch
      if (name !== undefined || email !== undefined || phone !== undefined) {
        // Phone is the verified identity and changes only through a fresh OTP flow (later module).
        void phone
        await auth.updateProfile({ ...(name !== undefined && { name }), ...(email !== undefined && { email: email || null }) })
      }
      if (Object.keys(rest).length) setLocal((l) => ({ ...l, ...rest }))
    },
  }), [profile, auth])

  return <ProfileContext.Provider value={api}>{children}</ProfileContext.Provider>
}

export function useProfile() {
  const ctx = useContext(ProfileContext)
  if (!ctx) throw new Error('useProfile must be used inside ProfileProvider')
  return ctx
}

export const initials = (name: string) => name.trim().split(/\s+/).map((n) => n[0]).slice(0, 2).join('').toUpperCase() || '?'
