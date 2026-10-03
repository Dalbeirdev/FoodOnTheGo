import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { MockProfileRepository } from '../account/mock/mockRepositories'
import type { AccountSecurity, Profile, ProfilePatch, ProfileRepository } from '../account/repositories'
import { useAuth } from '../auth/AuthContext'

export type { AccountSecurity, AccountStatus, CodeChallenge, Gender, Option, Profile, ProfilePatch } from '../account/repositories'
export { RepositoryError } from '../account/repositories'

type ProfileApi = {
  profile: Profile | null
  status: 'idle' | 'loading' | 'ready' | 'error'
  error: string | null
  reload: () => Promise<void>
  update: (patch: ProfilePatch) => Promise<Profile>
  setAvatar: (dataUrl: string | null) => Promise<Profile>
  /** Throws RepositoryError 'reauthentication_required' when the backend wants a fresh code first (API mode). */
  requestDeletion: (reason?: string) => Promise<Profile>
  /** Phone change and re-authentication — only when the profile lives on the backend. */
  security: AccountSecurity | null
  /** true when this profile is the real account on the backend (API mode); false for the in-browser development data. */
  live: boolean
}

/** The development data (Module 04). The app passes the implementation chosen by the sign-in mode (accountRepositories.ts). */
export const defaultProfileRepository: ProfileRepository = new MockProfileRepository()
const ProfileContext = createContext<ProfileApi | null>(null)

/** Customer profile for the signed-in user, backed by a ProfileRepository (development data or the backend). */
export function ProfileProvider({ children, repository = defaultProfileRepository }: { children: ReactNode; repository?: ProfileRepository }) {
  const auth = useAuth()
  const user = auth.user
  const [profile, setProfile] = useState<Profile | null>(null)
  const [status, setStatus] = useState<ProfileApi['status']>('idle')
  const [error, setError] = useState<string | null>(null)

  const reload = useCallback(async () => {
    if (!user) { setProfile(null); setStatus('idle'); return }
    setStatus('loading'); setError(null)
    try { setProfile(await repository.get(user.id, { name: user.name, phone: user.phone, email: user.email, memberSince: user.memberSince })); setStatus('ready') } catch (e) { setError(e instanceof Error ? e.message : 'Something went wrong.'); setStatus('error') }
  }, [user, repository])
  useEffect(() => { const t = setTimeout(() => { void reload() }, 0); return () => clearTimeout(t) }, [reload])

  const api = useMemo<ProfileApi>(() => {
    const security = repository.security
    return {
      profile, status, error, reload,
      live: repository.updatesIdentity === true,
      update: async (patch) => {
        if (!user) throw new Error('Sign in to edit your profile.')
        const next = await repository.update(user.id, patch)
        // Keep the auth identity (header, sidebar) in sync for name / e-mail: the backend profile is the identity, so it
        // is re-read; the development data has to be told.
        if (patch.name !== undefined || patch.email !== undefined) {
          if (repository.updatesIdentity) await auth.refresh()
          else await auth.updateProfile({ ...(patch.name !== undefined && { name: patch.name }), ...(patch.email !== undefined && { email: patch.email || null }) })
        }
        setProfile(next)
        return next
      },
      setAvatar: async (dataUrl) => { if (!user) throw new Error('Sign in first.'); const next = await repository.setAvatar(user.id, dataUrl); setProfile(next); return next },
      requestDeletion: async (reason) => { if (!user) throw new Error('Sign in first.'); const next = await repository.requestDeletion(user.id, reason); setProfile(next); return next },
      security: security
        ? {
            requestReauth: () => security.requestReauth(),
            verifyReauth: (id, code) => security.verifyReauth(id, code),
            requestPhoneChange: (phone) => security.requestPhoneChange(phone),
            // A new number is a new identity for the header too.
            verifyPhoneChange: async (id, code) => { const next = await security.verifyPhoneChange(id, code); setProfile(next); await auth.refresh(); return next },
          }
        : null,
    }
  }, [profile, status, error, reload, user, auth, repository])

  return <ProfileContext.Provider value={api}>{children}</ProfileContext.Provider>
}

export function useProfile() {
  const ctx = useContext(ProfileContext)
  if (!ctx) throw new Error('useProfile must be used inside ProfileProvider')
  return ctx
}

export const initials = (name: string) => name.trim().split(/\s+/).map((n) => n[0]).slice(0, 2).join('').toUpperCase() || '?'
