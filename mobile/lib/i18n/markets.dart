/// Market configuration (Module 06) — the ONLY place where per-country defaults live.
///
/// Reusable discovery code never hardcodes a country, currency, unit or locale; it asks this
/// table by ISO 3166-1 alpha-2 code. Production markets are administered in the backend
/// (MARKET CONFIGURATION = NOT STARTED). Unknown countries get the neutral defaults.
library;

import '../market/market.dart';


enum UnitSystem { metric, imperial }

class MarketConfig {
  const MarketConfig({required this.countryCode, required this.locale, required this.currency, required this.unitSystem, required this.corridorM, this.postalCodeUsed = false, this.postalCodeLabel = 'Postal code', this.adminAreaLabel = 'Region', this.scopeRadiusM = 25000, this.regionAdjacency = const {}});
  final String countryCode, locale, currency, postalCodeLabel, adminAreaLabel;
  final UnitSystem unitSystem;
  final int corridorM;
  final bool postalCodeUsed;
  /// Radius of ring 0 ("near you") in metres.
  final int scopeRadiusM;
  /// Neighbouring regions used for ring 2 — admin-managed later; development table here.
  final Map<String, List<String>> regionAdjacency;
}

const _defaultMarket = MarketConfig(countryCode: 'ZZ', locale: 'en', currency: 'USD', unitSystem: UnitSystem.metric, corridorM: 5000);

/// Development fixtures only.
const _markets = <String, MarketConfig>{
  'IN': MarketConfig(countryCode: 'IN', locale: 'en_IN', currency: 'INR', unitSystem: UnitSystem.metric, corridorM: 5000, postalCodeUsed: true, postalCodeLabel: 'PIN code', adminAreaLabel: 'State', regionAdjacency: {'Punjab': ['Haryana', 'Himachal Pradesh', 'Rajasthan', 'Chandigarh', 'Jammu and Kashmir'], 'Haryana': ['Punjab', 'Delhi', 'Uttar Pradesh', 'Rajasthan', 'Himachal Pradesh', 'Chandigarh', 'Uttarakhand'], 'Chandigarh': ['Punjab', 'Haryana'], 'Delhi': ['Haryana', 'Uttar Pradesh'], 'Uttar Pradesh': ['Delhi', 'Haryana', 'Rajasthan', 'Madhya Pradesh', 'Uttarakhand', 'Bihar', 'Himachal Pradesh'], 'Rajasthan': ['Punjab', 'Haryana', 'Uttar Pradesh', 'Gujarat', 'Madhya Pradesh'], 'Gujarat': ['Rajasthan', 'Maharashtra', 'Madhya Pradesh'], 'Maharashtra': ['Gujarat', 'Madhya Pradesh', 'Karnataka', 'Goa', 'Telangana'], 'Karnataka': ['Maharashtra', 'Goa', 'Kerala', 'Tamil Nadu', 'Telangana', 'Andhra Pradesh'], 'Jammu and Kashmir': ['Punjab', 'Himachal Pradesh', 'Ladakh']}),
  'US': MarketConfig(countryCode: 'US', locale: 'en_US', currency: 'USD', unitSystem: UnitSystem.imperial, corridorM: 8000, postalCodeUsed: true, postalCodeLabel: 'ZIP code', adminAreaLabel: 'State', scopeRadiusM: 40000, regionAdjacency: {'CA': ['OR', 'NV', 'AZ'], 'NV': ['CA', 'OR', 'ID', 'UT', 'AZ'], 'OR': ['CA', 'WA', 'NV', 'ID'], 'AZ': ['CA', 'NV', 'UT', 'NM']}),
  'GB': MarketConfig(countryCode: 'GB', locale: 'en_GB', currency: 'GBP', unitSystem: UnitSystem.imperial, corridorM: 6000, postalCodeUsed: true, postalCodeLabel: 'Postcode', adminAreaLabel: 'County', regionAdjacency: {'Northamptonshire': ['Warwickshire', 'Leicestershire', 'West Midlands', 'Buckinghamshire'], 'West Midlands': ['Staffordshire', 'Warwickshire', 'Worcestershire', 'Northamptonshire'], 'Staffordshire': ['West Midlands', 'Cheshire', 'Derbyshire', 'Shropshire']}),
  'JP': MarketConfig(countryCode: 'JP', locale: 'ja_JP', currency: 'JPY', unitSystem: UnitSystem.metric, corridorM: 4000, postalCodeUsed: true, postalCodeLabel: '郵便番号', adminAreaLabel: 'Prefecture', scopeRadiusM: 20000, regionAdjacency: {'静岡県': ['愛知県', '神奈川県', '山梨県', '長野県'], '愛知県': ['静岡県', '岐阜県', '三重県', '長野県'], '東京都': ['神奈川県', '埼玉県', '千葉県', '山梨県'], '大阪府': ['京都府', '兵庫県', '奈良県', '和歌山県']}),
  'FR': MarketConfig(countryCode: 'FR', locale: 'fr_FR', currency: 'EUR', unitSystem: UnitSystem.metric, corridorM: 6000, postalCodeUsed: true, postalCodeLabel: 'Code postal', adminAreaLabel: 'Département', regionAdjacency: {'Yonne': ["Côte-d'Or", 'Nièvre', 'Aube', 'Seine-et-Marne', 'Loiret'], "Côte-d'Or": ['Yonne', 'Nièvre', 'Saône-et-Loire', 'Jura', 'Haute-Marne', 'Aube']}),
  'AE': MarketConfig(countryCode: 'AE', locale: 'ar_AE', currency: 'AED', unitSystem: UnitSystem.metric, corridorM: 8000, adminAreaLabel: 'Emirate', scopeRadiusM: 30000, regionAdjacency: {'Dubai': ['Abu Dhabi', 'Sharjah'], 'Abu Dhabi': ['Dubai'], 'Sharjah': ['Dubai', 'Ajman', 'Umm Al Quwain']}),
};

MarketConfig marketFor(String? countryCode) {
  final cc = (countryCode ?? '').toUpperCase();
  return _markets[cc] ?? MarketConfig(countryCode: cc.isEmpty ? 'ZZ' : cc, locale: _defaultMarket.locale, currency: _defaultMarket.currency, unitSystem: _defaultMarket.unitSystem, corridorM: _defaultMarket.corridorM);
}

/// Explicit user preference → market → metric.
UnitSystem resolveUnitSystem(UnitSystem? preference, String? countryCode) => (marketAvailability.unitChoiceAvailable ? preference : null) ?? marketFor(countryCode).unitSystem;

/// True when two regions of the same country are configured as neighbours (symmetric).
bool regionsAdjacent(String countryCode, String? a, String? b) {
  if (a == null || b == null) return false;
  final adj = marketFor(countryCode).regionAdjacency;
  return (adj[a] ?? const []).contains(b) || (adj[b] ?? const []).contains(a);
}
