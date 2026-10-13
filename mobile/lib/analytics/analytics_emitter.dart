/// The behavioural-event emitter of the Android app (Module 38): first-party product telemetry for the company's own
/// funnel report, sent to the FoodOnTheGo backend and nowhere else.
///
///  - It never blocks, slows or changes anything a customer does: [track] returns at once, nothing awaits it, every
///    failure is swallowed, and nothing about it is ever shown.
///  - The SESSION is a random UUID v4 the app makes up — not a device id, nothing from hardware. It is replaced after
///    30 minutes without an event or 12 hours of life.
///  - CONSENT is a local opt-out flag (default on). When it is off nothing is queued, kept or sent.
///  - Events wait in memory and go out in batches of at most 20 a few seconds after the first one was queued. There is
///    no periodic timer: ONE one-shot timer exists only while events are queued.
///  - Without a connection the events are kept for a later attempt — at most 50, none older than 72 hours, the oldest
///    dropped first. An HTTP error answer drops the batch: there is no retry loop.
///  - Disabled (every build that does not talk to the real backend, and every test that does not switch it on) it is a
///    no-op: no request, no timer, no storage access.
library;

import 'dart:async';
import 'dart:convert';
import 'dart:math';

import 'package:flutter/foundation.dart';

import '../auth/auth_repository.dart' show KeyValueStore;
import '../data/api_client.dart';
import 'analytics_registry.dart';

typedef AnalyticsClock = DateTime Function();
typedef AnalyticsTimerFactory = Timer Function(Duration delay, void Function() callback);

class _Session {
  _Session(this.id, this.startedAt, this.lastActivityAt);
  final String id;
  final DateTime startedAt;
  DateTime lastActivityAt;
}

class _Queued {
  const _Queued(this.session, this.at, this.event);
  final String session;
  final DateTime at;
  final Map<String, dynamic> event;
  Map<String, dynamic> toJson() => {'s': session, 'e': event};
}

class AnalyticsEmitter {
  AnalyticsEmitter({required this.api, required this.store, required String appVersion, this.enabled = true, AnalyticsClock? clock, AnalyticsTimerFactory? timer, Random? random, this.flushAfter = defaultFlushAfter})
      : appVersion = cleanAppVersion(appVersion), _clock = clock ?? DateTime.now, _newTimer = timer ?? Timer.new, _random = random ?? Random.secure();

  static const path = '/analytics/events';
  static const platform = 'ANDROID';

  /// The session is replaced when nothing was tracked for this long …
  static const sessionIdle = Duration(minutes: 30);

  /// … or when it has lived this long, whatever happened in it.
  static const sessionMaxAge = Duration(hours: 12);

  /// One request carries at most this many events (the backend takes 25).
  static const batchSize = 20;

  /// Events waiting to be sent — in memory and on the device — never exceed this number: the oldest go first.
  static const bufferLimit = 50;

  /// The backend refuses an event older than this, so the app does not keep one that long.
  static const maxEventAge = Duration(hours: 72);

  /// How long after the first queued event the batch goes out.
  static const defaultFlushAfter = Duration(seconds: 5);

  /// After a send that failed for lack of a connection the next automatic attempt waits at least this long.
  static const retryAfter = Duration(seconds: 30);

  /// "Once per session" keys kept per session. Past this number further once-events of the session are not sent.
  static const onceLimit = 500;

  static const sessionKey = 'fotg.analytics.session';
  static const bufferKey = 'fotg.analytics.buffer';
  static const optOutKey = 'fotg.analytics.optout';

  /// The app's version as the backend accepts it: characters [0-9A-Za-z.+-], at most 24.
  static String cleanAppVersion(String raw) {
    final clean = raw.replaceAll(RegExp(r'[^0-9A-Za-z.+\-]'), '');
    return clean.length > 24 ? clean.substring(0, 24) : clean;
  }

  /// The app's own API client: the request goes out as the signed-in customer when there is one, as a guest otherwise.
  final ApiClient api;

  /// Where the opt-out flag, the session and the bounded buffer are kept.
  final KeyValueStore store;
  final AnalyticsClock _clock;
  final AnalyticsTimerFactory _newTimer;
  final Random _random;
  final bool enabled;
  final String appVersion;
  final Duration flushAfter;

  final _queue = <_Queued>[];
  final _once = <String>{};
  final _early = <void Function()>[];
  _Session? _session;
  bool _consent = true;
  bool _loaded = false;
  bool _bufferStored = false;
  bool _sessionDirty = false;
  Future<void>? _loading;
  Future<void>? _draining;
  Timer? _timer;
  DateTime? _retryNotBefore;

  /// The opt-out flag as last read or set (true until the stored flag has been read).
  bool get consent => _consent;

  @visibleForTesting
  int get queued => _queue.length;
  @visibleForTesting
  String? get sessionId => _session?.id;
  @visibleForTesting
  bool get timerPending => _timer != null;

  /// Resolves once the stored flag, session and buffer have been read. Tests await it; the app never does.
  Future<void> get ready => enabled ? _load() : Future<void>.value();

  /// Records an event. Returns at once and never throws; with [onceKey] the event is recorded once per session.
  void track(String name, {Map<String, String?> refs = const {}, Map<String, Object?> properties = const {}, String? onceKey}) {
    if (!enabled) return;
    try {
      final at = _clock().toUtc();
      if (!_loaded) {
        if (_early.length < bufferLimit) _early.add(() => _record(name, refs, properties, onceKey, at));
        unawaited(_load());
        return;
      }
      _record(name, refs, properties, onceKey, at);
    } catch (e) {
      _log('track failed', e);
    }
  }

  /// Sends what is queued now (the app going to the background, a test). Never throws.
  Future<void> flush() {
    if (!enabled) return Future<void>.value();
    return _draining ??= _drain();
  }

  /// The local opt-out: off = nothing is queued or sent, and what was waiting is thrown away.
  Future<void> setConsent(bool allowed) async {
    if (!enabled) return;
    try {
      await _load();
      _consent = allowed;
      if (!allowed) {
        _queue.clear();
        _timer?.cancel();
        _timer = null;
        _bufferStored = false;
        await store.write(bufferKey, null);
      }
      await store.write(optOutKey, allowed ? null : '1');
    } catch (e) {
      _log('consent not stored', e);
    }
  }

  /* ------------------------------------------------------------------ recording */

  void _record(String name, Map<String, String?> refs, Map<String, Object?> properties, String? onceKey, DateTime at) {
    if (!_consent) return;
    final clean = cleanEvent(name, refs, properties);
    if (clean == null) {
      _log('not sent: "$name" is not an event of the registry, or a reference it needs is missing');
      return;
    }
    final session = _touch(at);
    if (onceKey != null) {
      if (_once.contains(onceKey) || _once.length >= onceLimit) return;
      _once.add(onceKey);
      _sessionDirty = true;
    }
    _queue.add(_Queued(session.id, at, {
      'id': _uuid(),
      'name': clean.name,
      'occurred_at': _stamp(at),
      if (clean.refs.isNotEmpty) 'refs': clean.refs,
      if (clean.properties.isNotEmpty) 'properties': clean.properties,
    }));
    _trim(at);
    final waiting = _retryNotBefore != null && at.isBefore(_retryNotBefore!);
    if (_queue.length >= batchSize && !waiting && _draining == null) {
      unawaited(flush());
    } else {
      _schedule(at);
    }
  }

  /// The session of an event at [now]: the current one while it is neither idle nor too old, otherwise a new one —
  /// and with a new session "once" starts again.
  _Session _touch(DateTime now) {
    var s = _session;
    if (s == null || now.isBefore(s.startedAt) || now.difference(s.lastActivityAt) > sessionIdle || now.difference(s.startedAt) > sessionMaxAge) {
      s = _Session(_uuid(), now, now);
      _session = s;
      _once.clear();
    }
    s.lastActivityAt = now;
    _sessionDirty = true;
    return s;
  }

  /// Too old to be accepted, or more than the buffer may hold: dropped, oldest first.
  void _trim(DateTime now) {
    _queue.removeWhere((q) => now.difference(q.at) > maxEventAge);
    if (_queue.length > bufferLimit) _queue.removeRange(0, _queue.length - bufferLimit);
  }

  /// ONE one-shot timer, and only while something is queued.
  void _schedule(DateTime now) {
    if (_timer != null || _queue.isEmpty) return;
    var delay = flushAfter;
    final notBefore = _retryNotBefore;
    if (notBefore != null && notBefore.difference(now) > delay) delay = notBefore.difference(now);
    _timer = _newTimer(delay, () {
      _timer = null;
      unawaited(flush());
    });
  }

  /* ------------------------------------------------------------------ sending */

  Future<void> _drain() async {
    try {
      _timer?.cancel();
      _timer = null;
      await _load();
      while (_consent && _queue.isNotEmpty) {
        _trim(_clock().toUtc());
        if (_queue.isEmpty) break;
        // A batch belongs to one session: events kept from an earlier session go out under their own.
        final session = _queue.first.session;
        final batch = _queue.where((q) => q.session == session).take(batchSize).toList();
        try {
          await api.post(path, auth: true, body: {
            'session': session,
            'platform': platform,
            if (appVersion.isNotEmpty) 'app_version': appVersion,
            'events': [for (final q in batch) q.event],
          });
          _queue.removeWhere(batch.contains);
          _retryNotBefore = null;
        } on ApiException catch (e) {
          if (e.kind == ApiErrorKind.network) {
            // No connection: the events stay (bounded) for a later attempt.
            _retryNotBefore = _clock().toUtc().add(retryAfter);
            _log('not sent: no connection (${_queue.length} kept)');
            break;
          }
          // The backend answered with an error: the batch is dropped. No retry loop.
          _queue.removeWhere(batch.contains);
          _log('batch dropped: HTTP ${e.status}');
        } catch (e) {
          _queue.removeWhere(batch.contains);
          _log('batch dropped', e);
        }
      }
    } catch (e) {
      _log('flush failed', e);
    } finally {
      // What is still queued waits for the next event or the next flush — never for a timer of its own.
      _timer?.cancel();
      _timer = null;
      _draining = null;
      await _persist();
    }
  }

  /* ------------------------------------------------------------------ the device */

  Future<void> _load() => _loading ??= _restore();

  Future<void> _restore() async {
    try {
      _consent = (await store.read(optOutKey)) != '1';
    } catch (_) {}
    try {
      final raw = await store.read(sessionKey);
      final j = raw == null || raw.isEmpty ? null : jsonDecode(raw);
      if (_session == null && j is Map && j['id'] is String && isAnalyticsUuid(j['id'] as String) && j['startedAt'] is int && j['lastActivityAt'] is int) {
        _session = _Session((j['id'] as String).toLowerCase(), DateTime.fromMillisecondsSinceEpoch(j['startedAt'] as int, isUtc: true), DateTime.fromMillisecondsSinceEpoch(j['lastActivityAt'] as int, isUtc: true));
        final once = j['once'];
        if (once is List) _once.addAll(once.whereType<String>().take(onceLimit));
      }
    } catch (_) {}
    try {
      final raw = await store.read(bufferKey);
      if (raw != null && raw.isNotEmpty) {
        _bufferStored = true;
        final list = jsonDecode(raw);
        final kept = <_Queued>[];
        if (_consent && list is List) {
          for (final row in list) {
            final q = _fromStored(row);
            if (q != null) kept.add(q);
          }
        }
        _queue.insertAll(0, kept);
        _trim(_clock().toUtc());
      }
    } catch (_) {}
    _loaded = true;
    final early = List.of(_early);
    _early.clear();
    for (final record in early) {
      try {
        record();
      } catch (e) {
        _log('track failed', e);
      }
    }
    if (_consent) _schedule(_clock().toUtc());
  }

  /// A stored event is read back through the registry: what the device kept is trusted no more than a new event.
  _Queued? _fromStored(Object? row) {
    if (row is! Map || row['s'] is! String || row['e'] is! Map) return null;
    final session = row['s'] as String;
    final e = (row['e'] as Map).cast<String, dynamic>();
    final id = e['id'], name = e['name'], occurred = e['occurred_at'];
    if (!isAnalyticsUuid(session) || id is! String || !isAnalyticsUuid(id) || name is! String || occurred is! String) return null;
    final at = DateTime.tryParse(occurred)?.toUtc();
    if (at == null) return null;
    final refs = e['refs'], properties = e['properties'];
    final clean = cleanEvent(name, refs is Map ? {for (final r in refs.entries) if (r.value is String) '${r.key}': r.value as String} : const {}, properties is Map ? {for (final p in properties.entries) '${p.key}': p.value} : const {});
    if (clean == null) return null;
    return _Queued(session.toLowerCase(), at, {'id': id.toLowerCase(), 'name': clean.name, 'occurred_at': _stamp(at), if (clean.refs.isNotEmpty) 'refs': clean.refs, if (clean.properties.isNotEmpty) 'properties': clean.properties});
  }

  /// After a flush: what could not be sent is kept on the device as one bounded JSON string (and removed once it is
  /// sent), and the session is remembered so that a quick restart of the app stays in it. It still ends by the same rules.
  Future<void> _persist() async {
    try {
      if (_queue.isEmpty) {
        if (_bufferStored) {
          _bufferStored = false;
          await store.write(bufferKey, null);
        }
      } else {
        _bufferStored = true;
        await store.write(bufferKey, jsonEncode([for (final q in _queue) q.toJson()]));
      }
      final s = _session;
      if (s != null && _sessionDirty) {
        _sessionDirty = false;
        await store.write(sessionKey, jsonEncode({'id': s.id, 'startedAt': s.startedAt.millisecondsSinceEpoch, 'lastActivityAt': s.lastActivityAt.millisecondsSinceEpoch, 'once': _once.toList()}));
      }
    } catch (e) {
      _log('not stored', e);
    }
  }

  /* ------------------------------------------------------------------ small things */

  /// A random (version 4) UUID. Nothing of the device goes into it.
  String _uuid() {
    final b = List<int>.generate(16, (_) => _random.nextInt(256));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    final h = [for (final x in b) x.toRadixString(16).padLeft(2, '0')].join();
    return '${h.substring(0, 8)}-${h.substring(8, 12)}-${h.substring(12, 16)}-${h.substring(16, 20)}-${h.substring(20)}';
  }

  /// UTC, ISO-8601, with "Z" (milliseconds, no microseconds).
  static String _stamp(DateTime at) => DateTime.fromMillisecondsSinceEpoch(at.millisecondsSinceEpoch, isUtc: true).toIso8601String();

  /// Debug builds only, and never a token, an id of a person or the content of an event.
  void _log(String message, [Object? error]) {
    if (kDebugMode) debugPrint('analytics: $message${error == null ? '' : ' (${error.runtimeType})'}');
  }
}
