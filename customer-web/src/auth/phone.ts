/**
 * Reusable phone/country handling. India first, but nothing else in the app assumes India:
 * add a country here and the login form, validation and masking follow.
 */
import { marketRepository } from '../market/mock/mockMarket'

export type Country = { code: string; name: string; dial: string; nationalLength: number; example: string; flag: string; /** Market-specific national-number rule (data, not logic). */ nationalPattern?: RegExp }

export const COUNTRIES: Country[] = [
  { code: 'IN', name: 'India', dial: '+91', nationalLength: 10, example: '98765 43210', flag: '🇮🇳', nationalPattern: /^[6-9]/ },
]

/** UX default = the active market's phone country code; storage stays E.164 for every market. */
export const DEFAULT_COUNTRY = COUNTRIES.find((c) => c.dial === marketRepository.getActiveMarket().phoneCountryCode) ?? COUNTRIES[0]

export const countryByCode = (code: string): Country => COUNTRIES.find((c) => c.code === code) ?? DEFAULT_COUNTRY

/** Digits only (drops spaces, dashes, brackets). */
export const nationalDigits = (input: string) => input.replace(/\D/g, '')

/** Full E.164 number, or null when the national part is not valid for the country. */
export function toE164(country: Country, input: string): string | null {
  let digits = nationalDigits(input)
  const dial = country.dial.replace('+', '')
  if (digits.length === country.nationalLength + dial.length && digits.startsWith(dial)) digits = digits.slice(dial.length)
  if (digits.length !== country.nationalLength) return null
  if (country.nationalPattern && !country.nationalPattern.test(digits)) return null
  return `${country.dial}${digits}`
}

export function validatePhone(country: Country, input: string): string | null {
  const digits = nationalDigits(input)
  if (!digits) return 'Enter your mobile number'
  if (toE164(country, input) === null) return `Enter a valid ${country.nationalLength}-digit ${country.name} mobile number`
  return null
}

/** "+91 •••••43210" — never shows the full number on the OTP screen. */
export function maskPhone(e164: string): string {
  const m = COUNTRIES.find((c) => e164.startsWith(c.dial))
  const national = m ? e164.slice(m.dial.length) : e164
  return `${m?.dial ?? ''} ${'•'.repeat(Math.max(0, national.length - 4))}${national.slice(-4)}`.trim()
}

/** "+91 98765 43210" for display where the full number is appropriate. */
export function formatPhone(e164: string): string {
  const m = COUNTRIES.find((c) => e164.startsWith(c.dial))
  if (!m) return e164
  const n = e164.slice(m.dial.length)
  return `${m.dial} ${n.slice(0, 5)} ${n.slice(5)}`.trim()
}
