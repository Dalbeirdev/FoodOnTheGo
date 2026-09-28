/// Market configuration (Module 06) — the ONLY place where per-country defaults live.
///
/// Reusable discovery code never hardcodes a country, currency, unit or locale; it asks this
/// table by ISO 3166-1 alpha-2 code. Production markets are administered in the backend
/// (MARKET CONFIGURATION = NOT STARTED). Unknown countries get the neutral defaults.
library;

enum UnitSystem { metric, imperial }

class MarketConfig {
  const MarketConfig({required this.countryCode, required this.locale, required this.currency, required this.unitSystem, required this.corridorM, this.postalCodeUsed = false, this.postalCodeLabel = 'Postal code', this.adminAreaLabel = 'Region'});
  final String countryCode, locale, currency, postalCodeLabel, adminAreaLabel;
  final UnitSystem unitSystem;
  final int corridorM;
  final bool postalCodeUsed;
}

const _defaultMarket = MarketConfig(countryCode: 'ZZ', locale: 'en', currency: 'USD', unitSystem: UnitSystem.metric, corridorM: 5000);

/// Development fixtures only.
const _markets = <String, MarketConfig>{
  'IN': MarketConfig(countryCode: 'IN', locale: 'en_IN', currency: 'INR', unitSystem: UnitSystem.metric, corridorM: 5000, postalCodeUsed: true, postalCodeLabel: 'PIN code', adminAreaLabel: 'State'),
  'US': MarketConfig(countryCode: 'US', locale: 'en_US', currency: 'USD', unitSystem: UnitSystem.imperial, corridorM: 8000, postalCodeUsed: true, postalCodeLabel: 'ZIP code', adminAreaLabel: 'State'),
  'GB': MarketConfig(countryCode: 'GB', locale: 'en_GB', currency: 'GBP', unitSystem: UnitSystem.imperial, corridorM: 6000, postalCodeUsed: true, postalCodeLabel: 'Postcode', adminAreaLabel: 'County'),
  'JP': MarketConfig(countryCode: 'JP', locale: 'ja_JP', currency: 'JPY', unitSystem: UnitSystem.metric, corridorM: 4000, postalCodeUsed: true, postalCodeLabel: '郵便番号', adminAreaLabel: 'Prefecture'),
  'FR': MarketConfig(countryCode: 'FR', locale: 'fr_FR', currency: 'EUR', unitSystem: UnitSystem.metric, corridorM: 6000, postalCodeUsed: true, postalCodeLabel: 'Code postal', adminAreaLabel: 'Département'),
  'AE': MarketConfig(countryCode: 'AE', locale: 'ar_AE', currency: 'AED', unitSystem: UnitSystem.metric, corridorM: 8000, adminAreaLabel: 'Emirate'),
};

MarketConfig marketFor(String? countryCode) {
  final cc = (countryCode ?? '').toUpperCase();
  return _markets[cc] ?? MarketConfig(countryCode: cc.isEmpty ? 'ZZ' : cc, locale: _defaultMarket.locale, currency: _defaultMarket.currency, unitSystem: _defaultMarket.unitSystem, corridorM: _defaultMarket.corridorM);
}

/// Explicit user preference → market → metric.
UnitSystem resolveUnitSystem(UnitSystem? preference, String? countryCode) => preference ?? marketFor(countryCode).unitSystem;
