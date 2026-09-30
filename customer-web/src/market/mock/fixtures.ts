import type { City, Market, MarketConfiguration, MarketRegion, RouteCorridor, ServiceArea } from '../types'

/**
 * Development fixtures for the market domain (Module 18A). Names are DEVELOPMENT FIXTURES: a city or route listed
 * here does not mean FoodOnTheGo operates there. Statuses are deliberately mixed (active / pilot / planned / paused /
 * unavailable) so availability logic can be exercised.
 */
const T0 = '2026-01-05T09:00:00.000Z', T1 = '2026-09-30T09:00:00.000Z'
const market = (m: Partial<Market> & Pick<Market, 'countryCode' | 'slug' | 'displayName' | 'status' | 'defaultLocale' | 'defaultCurrency' | 'defaultTimezone' | 'distanceUnit' | 'phoneCountryCode'>): Market => ({
  id: `mkt-${m.countryCode.toLowerCase()}`, supportedLocales: [m.defaultLocale], plannedLocales: [], supportedCurrencies: [m.defaultCurrency], timezoneStrategy: 'per-location',
  paymentConfigurationId: null, taxConfigurationId: null, featureConfigurationId: null, legalConfigurationId: null, launchedAt: null, createdAt: T0, updatedAt: T1, ...m,
})

export const INDIA: Market = market({
  countryCode: 'IN', slug: 'india', displayName: 'India', status: 'ACTIVE', defaultLocale: 'en-IN', supportedLocales: ['en-IN'],
  plannedLocales: ['hi-IN', 'pa-IN', 'bn-IN', 'ta-IN', 'te-IN', 'mr-IN', 'gu-IN', 'kn-IN', 'ml-IN'],
  defaultCurrency: 'INR', defaultTimezone: 'Asia/Kolkata', timezoneStrategy: 'single', distanceUnit: 'metric', phoneCountryCode: '+91',
  paymentConfigurationId: 'pay-in', taxConfigurationId: 'tax-in', featureConfigurationId: 'feat-in', legalConfigurationId: 'legal-in', launchedAt: '2026-09-01T00:00:00.000Z',
})
const LATER = 'Coming later — not available to customers'
/** India launch configuration: India is the only ACTIVE market; every other country is a future draft. */
export const INDIA_SCOPE_MARKETS: Market[] = [
  INDIA,
  market({ countryCode: 'US', slug: 'united-states', displayName: 'United States', status: 'DRAFT', defaultLocale: 'en-US', defaultCurrency: 'USD', defaultTimezone: 'America/New_York', distanceUnit: 'imperial', phoneCountryCode: '+1', note: LATER }),
  market({ countryCode: 'GB', slug: 'united-kingdom', displayName: 'United Kingdom', status: 'DRAFT', defaultLocale: 'en-GB', defaultCurrency: 'GBP', defaultTimezone: 'Europe/London', distanceUnit: 'imperial', phoneCountryCode: '+44', note: LATER }),
  market({ countryCode: 'AE', slug: 'united-arab-emirates', displayName: 'United Arab Emirates', status: 'DRAFT', defaultLocale: 'ar-AE', defaultCurrency: 'AED', defaultTimezone: 'Asia/Dubai', distanceUnit: 'metric', phoneCountryCode: '+971', note: LATER }),
  market({ countryCode: 'SG', slug: 'singapore', displayName: 'Singapore', status: 'DRAFT', defaultLocale: 'en-SG', defaultCurrency: 'SGD', defaultTimezone: 'Asia/Singapore', distanceUnit: 'metric', phoneCountryCode: '+65', note: LATER }),
  market({ countryCode: 'AU', slug: 'australia', displayName: 'Australia', status: 'DRAFT', defaultLocale: 'en-AU', defaultCurrency: 'AUD', defaultTimezone: 'Australia/Sydney', distanceUnit: 'metric', phoneCountryCode: '+61', note: LATER }),
]
/** CONTROLLED GLOBAL TEST FIXTURES (tests only): several markets active to prove multi-currency / time zone / unit support. */
export const GLOBAL_SCOPE_MARKETS: Market[] = [
  INDIA,
  market({ countryCode: 'US', slug: 'united-states', displayName: 'United States', status: 'ACTIVE', defaultLocale: 'en-US', defaultCurrency: 'USD', defaultTimezone: 'America/New_York', distanceUnit: 'imperial', phoneCountryCode: '+1' }),
  market({ countryCode: 'GB', slug: 'united-kingdom', displayName: 'United Kingdom', status: 'ACTIVE', defaultLocale: 'en-GB', defaultCurrency: 'GBP', defaultTimezone: 'Europe/London', distanceUnit: 'imperial', phoneCountryCode: '+44' }),
  market({ countryCode: 'JP', slug: 'japan', displayName: 'Japan', status: 'ACTIVE', defaultLocale: 'ja-JP', defaultCurrency: 'JPY', defaultTimezone: 'Asia/Tokyo', distanceUnit: 'metric', phoneCountryCode: '+81' }),
  market({ countryCode: 'FR', slug: 'france', displayName: 'France', status: 'ACTIVE', defaultLocale: 'fr-FR', defaultCurrency: 'EUR', defaultTimezone: 'Europe/Paris', distanceUnit: 'metric', phoneCountryCode: '+33' }),
  market({ countryCode: 'AE', slug: 'united-arab-emirates', displayName: 'United Arab Emirates', status: 'PILOT', defaultLocale: 'ar-AE', defaultCurrency: 'AED', defaultTimezone: 'Asia/Dubai', distanceUnit: 'metric', phoneCountryCode: '+971' }),
  market({ countryCode: 'IE', slug: 'ireland', displayName: 'Ireland', status: 'DRAFT', defaultLocale: 'en-IE', defaultCurrency: 'EUR', defaultTimezone: 'Europe/Dublin', distanceUnit: 'metric', phoneCountryCode: '+353' }),
]

export const INDIA_CONFIGURATION: MarketConfiguration = {
  marketCode: 'IN',
  payment: { id: 'pay-in', providerStrategy: 'PaymentProvider abstraction — the provider is resolved per market by the backend', candidateProviders: ['Razorpay (candidate, not contracted)'], methods: [
    { method: 'upi', status: 'ENABLED', note: 'Development sandbox' }, { method: 'card', status: 'ENABLED', note: 'Development sandbox' },
    { method: 'netbanking', status: 'PLANNED' }, { method: 'wallet', status: 'PLANNED' }, { method: 'cash_at_pickup', status: 'NOT_APPROVED', note: 'Platform decision: CASH AT PICKUP = NOT APPROVED' },
  ] },
  tax: { id: 'tax-in', regime: 'GST (India)', status: 'PENDING_BACKEND', note: 'No tax rate is stored in the frontend. Checkout shows taxes only when the backend / configuration supplies them.' },
  legal: { id: 'legal-in', documents: [{ key: 'terms', version: '0.4', status: 'DRAFT_PENDING_APPROVAL' }, { key: 'privacy', version: '0.4', status: 'DRAFT_PENDING_APPROVAL' }, { key: 'refund', version: '0.3', status: 'DRAFT_PENDING_APPROVAL' }, { key: 'cookie', version: '0.2', status: 'DRAFT_PENDING_APPROVAL' }] },
  features: [
    { key: 'journey_ordering', enabled: true, locked: false }, { key: 'scheduled_pickup', enabled: true, locked: false }, { key: 'asap_pickup', enabled: true, locked: false },
    { key: 'reviews', enabled: true, locked: false }, { key: 'promotions', enabled: true, locked: false }, { key: 'curbside_pickup', enabled: false, locked: false },
    { key: 'restaurant_responses', enabled: true, locked: false }, { key: 'customer_notifications', enabled: true, locked: false },
    { key: 'cross_border_ordering', enabled: false, locked: true, note: 'FUTURE — not part of the India launch' }, { key: 'cash_at_pickup', enabled: false, locked: true, note: 'NOT APPROVED' },
  ],
  address: { fields: ['Address line', 'Area / locality', 'City', 'State / UT', 'Postal code', 'Country'], postalCodeLabel: 'PIN code', postalCodeExample: '201309', adminAreaLabel: 'State / UT' },
}

const region = (code: string, name: string, status: MarketRegion['status'], kind: MarketRegion['kind'] = 'state'): MarketRegion => ({ id: `in-${code.toLowerCase()}`, marketCode: 'IN', name, code: `IN-${code}`, kind, status })
export const INDIA_REGIONS: MarketRegion[] = [
  region('UP', 'Uttar Pradesh', 'AVAILABLE'), region('DL', 'Delhi', 'AVAILABLE', 'union_territory'), region('HR', 'Haryana', 'AVAILABLE'), region('PB', 'Punjab', 'PILOT'),
  region('CH', 'Chandigarh', 'PILOT', 'union_territory'), region('RJ', 'Rajasthan', 'AVAILABLE'), region('GJ', 'Gujarat', 'AVAILABLE'), region('MH', 'Maharashtra', 'PLANNED'),
  region('KA', 'Karnataka', 'PLANNED'), region('TS', 'Telangana', 'PLANNED'), region('TN', 'Tamil Nadu', 'PLANNED'), region('GA', 'Goa', 'PLANNED'),
  region('WB', 'West Bengal', 'DISABLED'), region('JK', 'Jammu and Kashmir', 'DISABLED', 'union_territory'),
]
const city = (id: string, name: string, reg: string, lat: number, lng: number, status: City['status'], launchStage: string, launchDate: string | null = null, aliases: string[] = []): City => ({ id: `in-${id}`, marketCode: 'IN', regionId: `in-${reg.toLowerCase()}`, name, aliases, lat, lng, timezone: 'Asia/Kolkata', status, launchStage, launchDate })
export const INDIA_CITIES: City[] = [
  city('noida', 'Noida', 'UP', 28.5355, 77.391, 'ACTIVE', 'Launched', '2026-09-01'),
  city('delhi', 'Delhi', 'DL', 28.6139, 77.209, 'ACTIVE', 'Launched', '2026-09-01', ['New Delhi']),
  city('gurugram', 'Gurugram', 'HR', 28.4595, 77.0266, 'PILOT', 'Pilot', null, ['Gurgaon']),
  city('karnal', 'Karnal', 'HR', 29.6857, 76.9905, 'PILOT', 'Highway pilot'),
  city('ambala', 'Ambala', 'HR', 30.3782, 76.7767, 'ACTIVE', 'Launched', '2026-09-08'),
  city('chandigarh', 'Chandigarh', 'CH', 30.7333, 76.7794, 'PILOT', 'Pilot'),
  city('rupnagar', 'Rupnagar', 'PB', 30.9685, 76.5265, 'ACTIVE', 'Launched', '2026-09-15', ['Ropar']),
  city('hoshiarpur', 'Hoshiarpur', 'PB', 31.5273, 75.9115, 'PILOT', 'Pilot'),
  city('pathankot', 'Pathankot', 'PB', 32.2643, 75.6421, 'PILOT', 'Pilot'),
  city('ludhiana', 'Ludhiana', 'PB', 30.901, 75.8573, 'PLANNED', 'Planned'),
  city('jaipur', 'Jaipur', 'RJ', 26.9124, 75.7873, 'ACTIVE', 'Launched', '2026-09-10'),
  city('udaipur', 'Udaipur', 'RJ', 24.5854, 73.7125, 'PLANNED', 'Planned'),
  city('ahmedabad', 'Ahmedabad', 'GJ', 23.0225, 72.5714, 'ACTIVE', 'Launched', '2026-09-12'),
  city('vadodara', 'Vadodara', 'GJ', 22.3072, 73.1812, 'ACTIVE', 'Launched', '2026-09-12'),
  city('surat', 'Surat', 'GJ', 21.1702, 72.8311, 'ACTIVE', 'Launched', '2026-09-18'),
  city('vapi', 'Vapi', 'GJ', 20.3893, 72.9106, 'PAUSED', 'Paused — restaurant onboarding'),
  city('mumbai', 'Mumbai', 'MH', 19.076, 72.8777, 'PLANNED', 'Planned', null, ['Bombay']),
  city('pune', 'Pune', 'MH', 18.5204, 73.8567, 'PLANNED', 'Planned'),
  city('bengaluru', 'Bengaluru', 'KA', 12.9716, 77.5946, 'PLANNED', 'Planned', null, ['Bangalore']),
  city('hyderabad', 'Hyderabad', 'TS', 17.385, 78.4867, 'PLANNED', 'Planned'),
  city('chennai', 'Chennai', 'TN', 13.0827, 80.2707, 'PLANNED', 'Planned'),
  city('panaji', 'Panaji', 'GA', 15.4909, 73.8278, 'PLANNED', 'Planned'),
  city('kolkata', 'Kolkata', 'WB', 22.5726, 88.3639, 'UNAVAILABLE', 'Not planned'),
]
const area = (id: string, name: string, cityId: string, lat: number, lng: number, radiusKm: number, status: ServiceArea['status'], launchStage: string): ServiceArea => ({ id: `sa-${id}`, marketCode: 'IN', cityId: `in-${cityId}`, name, status, geometry: { type: 'radius', center: [lat, lng], radiusM: radiusKm * 1000 }, launchStage, updatedAt: T1 })
export const INDIA_SERVICE_AREAS: ServiceArea[] = [
  area('noida-62', 'Noida · Sector 62 & NH24', 'noida', 28.622, 77.372, 6, 'ACTIVE', 'Launched'),
  area('noida-expressway', 'Noida · Expressway / Sector 18', 'noida', 28.57, 77.324, 5, 'ACTIVE', 'Launched'),
  area('delhi-central', 'Delhi · Central', 'delhi', 28.6139, 77.209, 12, 'ACTIVE', 'Launched — restaurant onboarding'),
  area('gurugram-cyber', 'Gurugram · Cyber City / NH48', 'gurugram', 28.4595, 77.0266, 8, 'PILOT', 'Pilot'),
  area('karnal-nh44', 'Karnal · NH44', 'karnal', 29.6857, 76.9905, 8, 'PILOT', 'Highway pilot'),
  area('ambala-nh44', 'Ambala · Cantt / NH44', 'ambala', 30.3782, 76.7767, 10, 'ACTIVE', 'Launched'),
  area('chandigarh-tricity', 'Chandigarh · Tricity', 'chandigarh', 30.7333, 76.7794, 12, 'PILOT', 'Pilot'),
  area('rupnagar-nh205', 'Rupnagar · NH205', 'rupnagar', 30.9685, 76.5265, 8, 'ACTIVE', 'Launched'),
  area('hoshiarpur', 'Hoshiarpur · Town', 'hoshiarpur', 31.5273, 75.9115, 8, 'PILOT', 'Pilot'),
  area('pathankot', 'Pathankot · NH44', 'pathankot', 32.2643, 75.6421, 8, 'PILOT', 'Pilot'),
  area('jaipur-mi-road', 'Jaipur · MI Road / NH48', 'jaipur', 26.9124, 75.7873, 12, 'ACTIVE', 'Launched'),
  area('ahmedabad-sg', 'Ahmedabad · SG Highway', 'ahmedabad', 23.0225, 72.5714, 12, 'ACTIVE', 'Launched'),
  area('vadodara-ne1', 'Vadodara · NE1 Expressway', 'vadodara', 22.3072, 73.1812, 10, 'ACTIVE', 'Launched'),
  area('surat-nh48', 'Surat · NH48', 'surat', 21.1702, 72.8311, 10, 'ACTIVE', 'Launched'),
  area('vapi-nh48', 'Vapi · NH48', 'vapi', 20.3893, 72.9106, 8, 'PAUSED', 'Paused'),
]
const route = (id: string, name: string, from: string, to: string, via: string[], highway: string | null, status: RouteCorridor['status']): RouteCorridor => ({ id: `rc-${id}`, marketCode: 'IN', name, originCityId: `in-${from}`, destinationCityId: `in-${to}`, viaCityIds: via.map((v) => `in-${v}`), highway, corridorWidthM: 5000, status, updatedAt: T1 })
export const INDIA_ROUTES: RouteCorridor[] = [
  route('delhi-chandigarh', 'Delhi NCR → Chandigarh', 'delhi', 'chandigarh', ['karnal', 'ambala'], 'NH44', 'ACTIVE'),
  route('chandigarh-pathankot', 'Chandigarh → Pathankot', 'chandigarh', 'pathankot', ['rupnagar', 'hoshiarpur'], 'NH205 / NH503A', 'TESTING'),
  route('delhi-jaipur', 'Delhi NCR → Jaipur', 'delhi', 'jaipur', ['gurugram'], 'NH48', 'ACTIVE'),
  route('ahmedabad-surat', 'Ahmedabad → Surat', 'ahmedabad', 'surat', ['vadodara'], 'NE1 / NH48', 'ACTIVE'),
  route('surat-vapi', 'Surat → Vapi', 'surat', 'vapi', [], 'NH48', 'PAUSED'),
  route('jaipur-udaipur', 'Jaipur → Udaipur', 'jaipur', 'udaipur', [], 'NH48', 'PLANNED'),
  route('mumbai-pune', 'Mumbai → Pune', 'mumbai', 'pune', [], 'Expressway', 'PLANNED'),
]
