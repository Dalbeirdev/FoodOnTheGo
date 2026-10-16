import { describe, expect, it } from 'vitest'
import { RELEASE_MODES, releaseConfigurationProblems } from './releaseBuild'

const allApi = Object.fromEntries(RELEASE_MODES.map((k) => [k, 'api']))
const production: Record<string, string> = { ...allApi, VITE_ENVIRONMENT: 'production', VITE_API_BASE_URL: 'https://api.foodonthego.example/api/v1', VITE_REVERB_SCHEME: 'https', VITE_REVERB_HOST: 'ws.foodonthego.example', VITE_REVERB_APP_KEY: 'public-key' }

describe('Module 41 — the release build gate', () => {
  it('fails closed: a build that does not say which environment it is for stops', () => {
    expect(releaseConfigurationProblems({})).toEqual(['VITE_ENVIRONMENT is not set — set local, staging or production'])
    expect(releaseConfigurationProblems({ ...production, VITE_ENVIRONMENT: 'prod' })).toEqual(['VITE_ENVIRONMENT is "prod" — set local, staging or production'])
  })

  it('a local build and the share preview are not judged', () => {
    expect(releaseConfigurationProblems({ VITE_ENVIRONMENT: 'local', VITE_API_BASE_URL: 'http://127.0.0.1:8001/api/v1' })).toEqual([])
    expect(releaseConfigurationProblems({ VITE_SHARE_BUILD: '1' })).toEqual([])
  })

  it('a correctly configured production or staging build passes', () => {
    expect(releaseConfigurationProblems(production)).toEqual([])
    expect(releaseConfigurationProblems({ ...production, VITE_ENVIRONMENT: 'Staging' })).toEqual([])
  })

  it('every domain must be on the backend — a missing or mock switch would ship development data and an open admin', () => {
    const { VITE_AUTH_MODE: _unset, ...withoutAuth } = production
    expect(releaseConfigurationProblems({ ...withoutAuth, VITE_PAYMENT_MODE: 'mock' })).toEqual(['VITE_AUTH_MODE is not set, not "api"', 'VITE_PAYMENT_MODE is "mock", not "api"'])
  })

  it('the API over https and not local; the socket over wss to a real host with its key; no local test code', () => {
    expect(releaseConfigurationProblems({ ...production, VITE_API_BASE_URL: '' })).toEqual(['VITE_API_BASE_URL is not set'])
    expect(releaseConfigurationProblems({ ...production, VITE_API_BASE_URL: 'http://api.foodonthego.example/api/v1' })).toEqual(['VITE_API_BASE_URL is not an https:// address'])
    expect(releaseConfigurationProblems({ ...production, VITE_API_BASE_URL: 'https://127.0.0.1:8001/api/v1' })).toEqual(['VITE_API_BASE_URL points at a local address'])
    expect(releaseConfigurationProblems({ ...production, VITE_REVERB_SCHEME: 'http', VITE_REVERB_HOST: 'localhost', VITE_REVERB_APP_KEY: '' })).toEqual([
      'VITE_REVERB_SCHEME is not "https" (the socket must be wss)', 'VITE_REVERB_HOST is not set to the realtime host', 'VITE_REVERB_APP_KEY is not set',
    ])
    const problems = releaseConfigurationProblems({ ...production, VITE_DEV_OTP: '999999' })
    expect(problems).toEqual(['VITE_DEV_OTP is set (a release build must not carry the local test code)'])
    expect(problems.join(' ')).not.toContain('999999')
  })
})
