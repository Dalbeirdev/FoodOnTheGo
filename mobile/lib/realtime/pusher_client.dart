/// A small Pusher-protocol client for Laravel Reverb (Module 32) — Android.
///
/// One WebSocket per app, private channels authorized by the backend (POST …/broadcasting/auth with the bearer
/// token — the server decides), events delivered to listeners by channel. What arrives is a NOTICE ("something
/// changed, here is the version"), never the order: the screens read the order from the API. The connection may
/// drop; the client reconnects with a bounded exponential backoff and jitter, and callers poll meanwhile.
///
/// Protocol: ws://host:port/app/{key}?protocol=7 → pusher:connection_established {socket_id, activity_timeout} →
/// pusher:subscribe {channel, auth} → events {event, channel, data}; pusher:ping / pusher:pong keep it alive.
library;

import 'dart:async';
import 'dart:convert';
import 'dart:math';

import 'package:stream_channel/stream_channel.dart';
import 'package:web_socket_channel/web_socket_channel.dart';

enum RealtimeTransport { off, connecting, connected, reconnecting, disconnected }

typedef ChannelAuthorizer = Future<String?> Function(String socketId, String channelName);

class PusherClient {
  PusherClient({required this.host, required this.port, required this.key, required this.scheme, required this.authorize, StreamChannel<dynamic> Function(Uri uri)? connector, this.baseBackoff = const Duration(seconds: 2), this.maxBackoff = const Duration(seconds: 30)})
      : _connector = connector ?? ((uri) => WebSocketChannel.connect(uri));

  final String host, key, scheme;
  final int port;
  final ChannelAuthorizer authorize;
  /// The transport: a real WebSocket in the app; tests hand in a StreamChannel they play.
  final StreamChannel<dynamic> Function(Uri uri) _connector;
  final Duration baseBackoff, maxBackoff;

  StreamChannel<dynamic>? _socket;
  StreamSubscription<dynamic>? _sub;
  String? socketId;
  int _attempt = 0;
  bool _closed = false;
  Timer? _reconnect, _ping;
  DateTime _lastFrameAt = DateTime.now();
  RealtimeTransport _transport = RealtimeTransport.off;
  final _transportController = StreamController<RealtimeTransport>.broadcast();
  final Map<String, List<void Function(String event, Map<String, dynamic> data)>> _listeners = {};
  final Set<String> _subscribed = {};
  final Random _random = Random();

  RealtimeTransport get transport => _transport;
  Stream<RealtimeTransport> get transportChanges => _transportController.stream;
  Uri get uri => Uri(scheme: scheme == 'https' ? 'wss' : 'ws', host: host, port: port, path: '/app/$key', queryParameters: {'protocol': '7', 'client': 'foodonthego-android', 'version': '1.0'});

  /// Starts (or keeps) the connection. Idempotent.
  void connect() {
    if (_closed || _socket != null) return;
    _set(_attempt == 0 ? RealtimeTransport.connecting : RealtimeTransport.reconnecting);
    try {
      final socket = _connector(uri);
      _socket = socket;
      // A real WebSocket reports a failed handshake on its `ready` future as well as on the stream: both are caught here,
      // so a refused / unreachable server is a reconnection, never an unhandled error.
      if (socket is WebSocketChannel) {
        unawaited(socket.ready.catchError((Object _) {}));
      }
      _sub = socket.stream.listen(_onMessage, onError: (_) => _dropped(), onDone: _dropped, cancelOnError: true);
    } catch (_) {
      _dropped();
    }
  }

  /// Listens to a private channel (name without the "private-" prefix, as the backend publishes it).
  void Function() subscribe(String channel, void Function(String event, Map<String, dynamic> data) listener) {
    _listeners.putIfAbsent(channel, () => []).add(listener);
    if (socketId != null && !_subscribed.contains(channel)) _subscribeNow(channel);
    connect();
    return () {
      _listeners[channel]?.remove(listener);
      if ((_listeners[channel] ?? const []).isEmpty) {
        _listeners.remove(channel);
        if (_subscribed.remove(channel)) _send({'event': 'pusher:unsubscribe', 'data': {'channel': 'private-$channel'}});
      }
    };
  }

  Future<void> _subscribeNow(String channel) async {
    final id = socketId;
    if (id == null) return;
    String? auth;
    try {
      auth = await authorize(id, 'private-$channel');
    } catch (_) {
      auth = null;
    }
    if (auth == null || socketId != id) return; // refused (not ours / signed out) or reconnected meanwhile: no subscription
    _subscribed.add(channel);
    _send({'event': 'pusher:subscribe', 'data': {'channel': 'private-$channel', 'auth': auth}});
  }

  void _onMessage(dynamic raw) {
    _lastFrameAt = DateTime.now();
    Map<String, dynamic> frame;
    try {
      frame = Map<String, dynamic>.from(jsonDecode(raw is String ? raw : utf8.decode(raw as List<int>)) as Map);
    } catch (_) {
      return;
    }
    final event = '${frame['event']}';
    final data = frame['data'];
    final decoded = data is String ? (data.isEmpty ? <String, dynamic>{} : _decodeMap(data)) : (data is Map ? Map<String, dynamic>.from(data) : <String, dynamic>{});
    switch (event) {
      case 'pusher:connection_established':
        socketId = '${decoded['socket_id']}';
        _attempt = 0;
        _set(RealtimeTransport.connected);
        final seconds = max(10, (decoded['activity_timeout'] as num?)?.toInt() ?? 30);
        _ping?.cancel();
        // A ping every activity timeout; a socket that answered nothing for a ping interval and a grace period is dead
        // (a network cut under an open socket): it is dropped and reconnected — otherwise the app would say "live" for ever.
        _ping = Timer.periodic(Duration(seconds: seconds), (_) {
          if (DateTime.now().difference(_lastFrameAt).inSeconds > seconds + 10) {
            _sub?.cancel();
            _socket?.sink.close().catchError((_) {});
            _dropped();
            return;
          }
          _send({'event': 'pusher:ping', 'data': {}});
        });
        for (final channel in _listeners.keys.toList()) {
          if (!_subscribed.contains(channel)) _subscribeNow(channel);
        }
      case 'pusher:ping':
        _send({'event': 'pusher:pong', 'data': {}});
      case 'pusher:pong':
      case 'pusher_internal:subscription_succeeded':
        break;
      case 'pusher:error':
        break;
      default:
        final channel = '${frame['channel'] ?? ''}'.replaceFirst('private-', '');
        for (final l in List.of(_listeners[channel] ?? const [])) {
          l(event, decoded);
        }
    }
  }

  Map<String, dynamic> _decodeMap(String s) {
    try {
      final v = jsonDecode(s);
      return v is Map ? Map<String, dynamic>.from(v) : <String, dynamic>{};
    } catch (_) {
      return <String, dynamic>{};
    }
  }

  void _send(Map<String, dynamic> frame) {
    try {
      _socket?.sink.add(jsonEncode(frame));
    } catch (_) {}
  }

  void _dropped() {
    _ping?.cancel();
    _ping = null;
    _sub?.cancel();
    _sub = null;
    _socket = null;
    socketId = null;
    _subscribed.clear();
    if (_closed) return;
    _set(_attempt == 0 ? RealtimeTransport.disconnected : RealtimeTransport.reconnecting);
    if (_listeners.isEmpty) return;
    final wait = backoff(_attempt++);
    _reconnect?.cancel();
    _reconnect = Timer(wait, connect);
  }

  /// Bounded exponential backoff with jitter: 2 s, 4 s, 8 s … up to 30 s, each with up to half of it added at random.
  Duration backoff(int attempt) {
    final exp = min(maxBackoff.inMilliseconds, baseBackoff.inMilliseconds * pow(2, attempt).toInt());
    return Duration(milliseconds: exp ~/ 2 + _random.nextInt(max(1, exp ~/ 2)));
  }

  /// Asks for a reconnection now (the app came back, the network changed).
  void reconnectNow() {
    if (_closed) return;
    _reconnect?.cancel();
    if (_socket == null) {
      _attempt = 0;
      connect();
    }
  }

  void _set(RealtimeTransport t) {
    if (_transport == t) return;
    _transport = t;
    if (!_transportController.isClosed) _transportController.add(t);
  }

  /// Closes for good (sign-out): nothing of a gone session stays subscribed.
  Future<void> close() async {
    _closed = true;
    _reconnect?.cancel();
    _ping?.cancel();
    await _sub?.cancel();
    try {
      await _socket?.sink.close();
    } catch (_) {}
    _socket = null;
    socketId = null;
    _subscribed.clear();
    _listeners.clear();
    _set(RealtimeTransport.off);
    await _transportController.close();
  }
}
