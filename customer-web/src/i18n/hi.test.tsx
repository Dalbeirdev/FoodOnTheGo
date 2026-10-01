/** Hindi for the staff tools: the translation is complete, keeps every placeholder, and both dashboards really show it. */
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { AdminProvider } from '../admin/AdminContext'
import AdminLayout from '../admin/AdminLayout'
import { resetAdminStores, setMockAdminLatency } from '../admin/mock/mockAdmin'
import AdminOverviewPage from '../admin/pages/OverviewPage'
import { adminStrings } from '../admin/strings'
import { adminStringsHi } from '../admin/strings.hi'
import { ForgotPasswordPage } from '../auth/staff/StaffPasswordPages'
import { staffUiLocale } from '../auth/staff/staffLocale'
import { staffStrings } from '../auth/staff/strings'
import { staffStringsHi } from '../auth/staff/strings.hi'
import { DashboardProvider } from '../dashboard/DashboardContext'
import DashboardLayout from '../dashboard/DashboardLayout'
import { seedDashboardFixtures, setMockDashboardLatency } from '../dashboard/mock/mockDashboard'
import OverviewPage from '../dashboard/pages/OverviewPage'
import { dashStrings } from '../dashboard/strings'
import { dashStringsHi } from '../dashboard/strings.hi'
import { LocaleProvider } from './LocaleProvider'
import { sharedStringsHi } from './shared.hi'
import { ensureBundle, hasBundle, t } from './strings'

const placeholders = (s: string) => (s.match(/\{\w+\}/g) ?? []).sort().join(',')
const TABLES: Array<[string, Record<string, string>, Record<string, string>]> = [['dashboard', dashStrings, dashStringsHi], ['admin', adminStrings, adminStringsHi], ['staff', staffStrings, staffStringsHi]]

describe('Hindi translation tables', () => {
  it.each(TABLES)('%s: every English text has a Hindi one with the same placeholders, and nothing extra', (_name, en, hi) => {
    expect(Object.keys(hi).sort()).toEqual(Object.keys(en).sort())
    const broken = Object.keys(en).filter((k) => placeholders(en[k]) !== placeholders(hi[k]) || hi[k].trim() === '')
    expect(broken).toEqual([])
  })

  it('the customer-side texts the dashboards also show are translated and exist in English', () => {
    for (const [k, v] of Object.entries(sharedStringsHi)) { expect(t(k), k).not.toBe(k); expect(placeholders(v)).toBe(placeholders(t(k))); expect(v).toMatch(/[\u0900-\u097F]/) }
  })

  it('is real Hindi: apart from codes and symbols every text is written in Devanagari', () => {
    const all = { ...dashStringsHi, ...adminStringsHi, ...staffStringsHi }
    const latinOnly = Object.entries(all).filter(([, v]) => !/[ऀ-ॿ]/.test(v) && /[a-z]{3,}/.test(v.replace(/\{\w+\}/g, ''))).map(([k]) => k)
    expect(latinOnly).toEqual(['adm.geo.f.lineGeojson']) // a GeoJSON type name
    expect(Object.keys(all).length).toBeGreaterThan(1900)
  })

  it('is loaded on demand and falls back to English for a missing key or language', async () => {
    await ensureBundle('hi'); expect(hasBundle('hi')).toBe(true)
    expect(t('dash.nav.orders', undefined, 'hi-IN')).toBe('ऑर्डर'); expect(t('adm.nav.restaurants', undefined, 'hi')).toBe('रेस्टोरेंट')
    expect(t('discovery.planJourney', undefined, 'hi-IN')).toBe('Plan a Journey') // the customer site is not translated
    await ensureBundle('fr'); expect(hasBundle('fr')).toBe(false); expect(t('dash.nav.orders', undefined, 'fr-FR')).toBe('Orders')
    expect(t('staff.err.rateWait', { seconds: 37 }, 'hi')).toBe('बहुत ज़्यादा कोशिशें हुईं। 37 सेकंड बाद फिर से कोशिश करें।')
  })

  it('keeps the market region for dates, numbers and money', () => {
    expect(staffUiLocale('hi', 'en-IN')).toBe('hi-IN'); expect(staffUiLocale('en', 'en-IN')).toBe('en-IN'); expect(staffUiLocale('hi', 'en')).toBe('hi')
    expect(new Intl.NumberFormat(staffUiLocale('hi', 'en-IN'), { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(125000)).toBe('₹1,25,000')
  })
})

describe('Dashboards in Hindi', () => {
  beforeEach(() => { localStorage.clear(); sessionStorage.clear(); resetAdminStores(); setMockAdminLatency(0); setMockDashboardLatency(0); seedDashboardFixtures() })
  afterEach(() => { cleanup(); window.history.pushState({}, '', '/') })
  const restaurant = () => render(<LocaleProvider><MemoryRouter initialEntries={['/restaurant-dashboard/overview']}><Routes><Route path="/restaurant-dashboard" element={<DashboardProvider><DashboardLayout /></DashboardProvider>}><Route path="overview" element={<OverviewPage />} /></Route></Routes></MemoryRouter></LocaleProvider>)
  const admin = () => render(<LocaleProvider><MemoryRouter initialEntries={['/admin/overview']}><Routes><Route path="/admin" element={<AdminProvider><AdminLayout /></AdminProvider>}><Route path="overview" element={<AdminOverviewPage />} /></Route></Routes></MemoryRouter></LocaleProvider>)

  it('Restaurant Dashboard: English by default, Hindi when that language was chosen in this browser', async () => {
    restaurant()
    expect((await screen.findAllByText('Pickup Verification')).length).toBeGreaterThan(0); expect(screen.getByTestId('db-shell').getAttribute('lang')).toMatch(/^en-/)
    cleanup(); localStorage.setItem('fotg.staff.lang', 'hi'); restaurant()
    await waitFor(() => expect(screen.getAllByText('पिकअप सत्यापन').length).toBeGreaterThan(0))
    expect(screen.getByTestId('db-shell').getAttribute('lang')).toMatch(/^hi-/); expect(screen.queryByText('Pickup Verification')).toBeNull()
    expect(document.body.textContent).not.toMatch(/\bdash\.[a-z]+\.[a-zA-Z_.]+/) // no untranslated key shows through
  })

  it('Platform Admin: navigation and overview in Hindi', async () => {
    localStorage.setItem('fotg.staff.lang', 'hi'); admin()
    await waitFor(() => expect(screen.getAllByText('रेस्टोरेंट').length).toBeGreaterThan(0))
    expect(screen.getByTestId('admin-shell').getAttribute('lang')).toMatch(/^hi-/); expect(screen.queryByText('Restaurants')).toBeNull()
    expect(document.body.textContent).not.toMatch(/\badm\.[a-z]+\.[a-zA-Z_.]+/)
  })

  it('a signed-out page follows the language of the link that was opened (?lang=hi)', async () => {
    window.history.pushState({}, '', '/admin/forgot-password?lang=hi')
    render(<MemoryRouter><ForgotPasswordPage context="admin" /></MemoryRouter>)
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('पासवर्ड भूल गए?'))
    expect(screen.getByTestId('forgot-submit')).toHaveTextContent('रीसेट लिंक भेजें'); expect(screen.getByRole('main')).toHaveAttribute('lang', 'hi')
    cleanup(); window.history.pushState({}, '', '/admin/forgot-password?lang=zz'); render(<MemoryRouter><ForgotPasswordPage context="admin" /></MemoryRouter>)
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Forgot your password?') // unknown language: English
  })
})
