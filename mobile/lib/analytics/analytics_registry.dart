/// The registry of behavioural events the app may send (Module 38) — a mirror of the backend's registry
/// (`analytics.events.registry`, source CLIENT) and of the web application's: the same ten names with the same meaning.
///
/// An event that is not in this table does not exist for the app: it is dropped before it is queued. So is an event
/// that carries a kind of reference or a property the table does not list for it. Nothing personal can be put into an
/// event through this door: there is no phone number, e-mail address, name, address, coordinate, route, search text or
/// amount in the table, and free text has no rule that would let it through.
library;

/// The four kinds of reference an event may carry.
const analyticsRefKinds = <String>['restaurant', 'journey', 'item', 'checkout'];

/// What a property may be.
sealed class PropertyRule {
  const PropertyRule();
  bool accepts(Object? value);
}

class BoolRule extends PropertyRule {
  const BoolRule();
  @override
  bool accepts(Object? value) => value is bool;
}

class IntRule extends PropertyRule {
  const IntRule(this.min, this.max);
  final int min, max;
  @override
  bool accepts(Object? value) => value is int && value >= min && value <= max;
}

class EnumRule extends PropertyRule {
  const EnumRule(this.values);
  final Set<String> values;
  @override
  bool accepts(Object? value) => value is String && values.contains(value);
}

class EventDefinition {
  const EventDefinition({this.refs = const [], this.required = const [], this.properties = const {}});

  /// The references the event may carry, and those it cannot do without.
  final List<String> refs, required;
  final Map<String, PropertyRule> properties;
}

/// THE EVENTS — nothing else may be sent. (There is no "payment verified", "order created" or "order completed": those
/// are facts of the backend's own records and no application reports them.)
const analyticsEvents = <String, EventDefinition>{
  'journey_search_started': EventDefinition(),
  'journey_results_viewed': EventDefinition(refs: ['journey'], required: ['journey'], properties: {'results': IntRule(0, 500)}),
  'restaurant_viewed': EventDefinition(refs: ['restaurant', 'journey'], required: ['restaurant'], properties: {'origin': EnumRule({'JOURNEY', 'BROWSE', 'DIRECT'})}),
  'menu_viewed': EventDefinition(refs: ['restaurant', 'journey'], required: ['restaurant']),
  'item_viewed': EventDefinition(refs: ['restaurant', 'item'], required: ['restaurant', 'item']),
  'item_added_to_cart': EventDefinition(refs: ['restaurant', 'item'], required: ['restaurant'], properties: {'quantity': IntRule(1, 50)}),
  'pickup_viewed': EventDefinition(refs: ['restaurant']),
  'pickup_selected': EventDefinition(refs: ['restaurant'], properties: {'mode': EnumRule({'ASAP', 'SCHEDULED'}), 'method': EnumRule({'COUNTER', 'CURBSIDE', 'DRIVE_THROUGH'}), 'recommended': BoolRule()}),
  'checkout_started': EventDefinition(refs: ['restaurant', 'checkout'], required: ['checkout']),
  'payment_started': EventDefinition(refs: ['checkout'], required: ['checkout'], properties: {'method': EnumRule({'CARD', 'UPI', 'WALLET', 'NET_BANKING', 'OTHER'})}),
};

final _uuid = RegExp(r'^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$');
final _slug = RegExp(r'^[a-z0-9]+(?:-[a-z0-9]+)*$');

bool isAnalyticsUuid(String value) => _uuid.hasMatch(value);

/// A reference as the backend reads it: a restaurant by its location id (a UUID) or its slug, everything else by UUID.
String? _reference(String kind, String value) {
  if (_uuid.hasMatch(value)) return value.toLowerCase();
  if (kind == 'restaurant' && value.length <= 120 && _slug.hasMatch(value)) return value;
  return null;
}

/// An event as it may be queued.
class CleanEvent {
  const CleanEvent(this.name, this.refs, this.properties);
  final String name;
  final Map<String, String> refs;
  final Map<String, Object> properties;
}

/// What of an event may leave the phone — or null when none of it may. The same rules as the web application's registry:
///
///  - refused as a whole: a name, a kind of reference or a property the registry does not list for the event (a
///    mistake in the code), and an event without a usable reference it cannot do without;
///  - left out, the event still sent: an OPTIONAL reference that is not an id of the backend (a development journey,
///    say) and a listed property whose value is outside its rule — those come from data, not from code.
///
/// A null reference or property is simply "not known" and is left out.
CleanEvent? cleanEvent(String name, Map<String, String?> refs, Map<String, Object?> properties) {
  final definition = analyticsEvents[name];
  if (definition == null) return null;
  final cleanRefs = <String, String>{};
  for (final ref in refs.entries) {
    final raw = ref.value;
    if (raw == null) continue;
    if (!definition.refs.contains(ref.key)) return null;
    final value = _reference(ref.key, raw);
    if (value != null) cleanRefs[ref.key] = value;
  }
  for (final kind in definition.required) {
    if (!cleanRefs.containsKey(kind)) return null;
  }
  final cleanProperties = <String, Object>{};
  for (final property in properties.entries) {
    final value = property.value;
    if (value == null) continue;
    final rule = definition.properties[property.key];
    if (rule == null) return null;
    if (rule.accepts(value)) cleanProperties[property.key] = value;
  }
  return CleanEvent(name, cleanRefs, cleanProperties);
}
