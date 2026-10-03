import 'package:flutter/foundation.dart';

import '../account/account_repositories.dart';
import '../account/api_account.dart';
import '../auth/api_auth_repository.dart' show ApiAuthRepository;
import '../auth/auth_repository.dart' show AuthUser, SecureKeyValueStore;
import '../core/app_config.dart';
import '../data/api_client.dart';

export '../account/account_repositories.dart';

enum LoadStatus { idle, loading, ready, error }

/// One loadable account resource with loading / ready / error and a retry.
class Resource<T> {
  Resource(this.empty) : data = empty;
  final T empty;
  LoadStatus status = LoadStatus.idle;
  T data;
  String? error;
  bool get isLoading => status == LoadStatus.loading || status == LoadStatus.idle;
  bool get hasError => status == LoadStatus.error;
}

/// Account data for the signed-in customer. Screens never touch repositories directly.
class AccountState extends ChangeNotifier {
  AccountState({AccountRepositories? repositories, void Function()? onUnauthenticated}) : repos = repositories ?? defaultRepositories(onUnauthenticated: onUnauthenticated);
  final AccountRepositories repos;

  /// Account data belongs to a real session, so it follows the sign-in mode: AUTH_MODE=api → the backend account
  /// (Module 25) with the same stored token; otherwise the in-app development data (Module 04).
  static AccountRepositories defaultRepositories({void Function()? onUnauthenticated}) {
    final store = SecureKeyValueStore();
    if (AppConfig.authMode == 'api') {
      return apiAccountRepositories(client: ApiClient(tokenProvider: () => store.read(ApiAuthRepository.tokenKey), onUnauthenticated: onUnauthenticated), devStore: store);
    }
    return AccountRepositories.mock(MockAccountStore(store: store));
  }

  /// true when the profile is the real account on the backend (API mode); false for the development data.
  bool get live => repos.profile.updatesIdentity;

  /// Phone change and re-authentication — only when the profile lives on the backend.
  AccountSecurity? get security => repos.profile.security;
  bool get notificationsAreDevelopmentData => repos.notifications.inboxIsDevelopmentData;

  AuthUser? _user;
  final profile = Resource<CustomerProfile?>(null);
  final favorites = Resource<List<Favorite>>(const []);
  final addresses = Resource<List<SavedAddress>>(const []);
  final payments = Resource<List<PaymentMethod>>(const []);
  final notifications = Resource<List<AppNotification>>(const []);
  final preferences = Resource<NotificationPreferences?>(null);

  String get _uid { final u = _user; if (u == null) throw RepositoryException('account', 'Sign in to manage your account.'); return u.id; }
  int get unreadCount => notifications.data.where((n) => !n.read).length;
  bool isFavorite(String restaurantId) => favorites.data.any((f) => f.restaurantId == restaurantId);

  /// Called by the app whenever the authenticated user changes (sign-in, sign-out).
  Future<void> bind(AuthUser? user) async {
    if (user?.id == _user?.id && user != null && profile.status == LoadStatus.ready) return;
    _user = user;
    if (user == null) {
      for (final r in [profile, favorites, addresses, payments, notifications, preferences]) {
        r.status = LoadStatus.idle;
      }
      profile.data = null; favorites.data = const []; addresses.data = const []; payments.data = const []; notifications.data = const []; preferences.data = null;
      notifyListeners();
      return;
    }
    await Future.wait([loadProfile(), loadFavorites(), loadAddresses(), loadPayments(), loadNotifications(), loadPreferences()]);
  }

  Future<void> _load<T>(Resource<T> r, Future<T> Function(String uid) fn) async {
    final u = _user;
    if (u == null) return;
    r.status = LoadStatus.loading; r.error = null;
    notifyListeners();
    try {
      r.data = await fn(u.id);
      r.status = LoadStatus.ready;
    } catch (e) {
      r.error = e is RepositoryException ? e.message : 'Something went wrong. Please try again.';
      r.status = LoadStatus.error;
    }
    notifyListeners();
  }

  Future<void> loadProfile() => _load(profile, (u) => repos.profile.get(u, name: _user!.name, phone: _user!.phone, email: _user!.email, memberSince: _user!.memberSince));
  Future<void> loadFavorites() => _load(favorites, repos.favorites.list);
  Future<void> loadAddresses() => _load(addresses, repos.addresses.list);
  Future<void> loadPayments() => _load(payments, repos.payments.list);
  Future<void> loadNotifications() => _load(notifications, repos.notifications.list);
  Future<void> loadPreferences() => _load(preferences, repos.notifications.getPreferences);

  Future<T> _mutate<T>(Resource<T> r, Future<T> Function(String uid) fn) async {
    final next = await fn(_uid);
    r.data = next; r.status = LoadStatus.ready; r.error = null;
    notifyListeners();
    return next;
  }

  // Profile
  Future<CustomerProfile> updateProfile(CustomerProfile Function(CustomerProfile) change) => _mutate(profile, (u) => repos.profile.update(u, change)).then((p) => p!);
  Future<CustomerProfile> setAvatar(AvatarUpload? upload) => _mutate(profile, (u) => repos.profile.setAvatar(u, upload)).then((p) => p!);
  Future<CustomerProfile> requestDeletion({String? reason}) => _mutate(profile, (u) => repos.profile.requestDeletion(u, reason: reason)).then((p) => p!);

  /// The verified new number becomes the profile at once (the caller re-reads the auth identity).
  Future<CustomerProfile> verifyPhoneChange(String challengeId, String code) async {
    final s = security;
    if (s == null) throw RepositoryException('profile', 'Changing the number needs the backend.');
    final next = await s.verifyPhoneChange(challengeId, code);
    profile.data = next; profile.status = LoadStatus.ready; profile.error = null;
    notifyListeners();
    return next;
  }

  // Favorites
  Future<void> addFavorite(String id) => _mutate(favorites, (u) => repos.favorites.add(u, id));
  Future<void> removeFavorite(String id) => _mutate(favorites, (u) => repos.favorites.remove(u, id));

  /// Optimistic: the heart changes at once and is put back when the backend refuses.
  Future<void> toggleFavorite(String id) async {
    final uid = _uid;
    final before = favorites.data;
    final had = isFavorite(id);
    favorites.data = had ? before.where((f) => f.restaurantId != id).toList() : [Favorite(restaurantId: id, addedAt: DateTime.now().toIso8601String()), ...before];
    notifyListeners();
    try {
      await _mutate(favorites, (_) => had ? repos.favorites.remove(uid, id) : repos.favorites.add(uid, id));
    } catch (e) {
      favorites.data = before;
      notifyListeners();
      rethrow;
    }
  }

  // Addresses
  Future<void> saveAddress(SavedAddress a) => _mutate(addresses, (u) => repos.addresses.save(u, a));
  Future<void> removeAddress(String id) => _mutate(addresses, (u) => repos.addresses.remove(u, id));
  Future<void> setDefaultAddress(String id) => _mutate(addresses, (u) => repos.addresses.setDefault(u, id));

  // Payment methods
  Future<void> setDefaultPayment(String id) => _mutate(payments, (u) => repos.payments.setDefault(u, id));
  Future<void> removePayment(String id) => _mutate(payments, (u) => repos.payments.remove(u, id));

  // Notifications
  Future<void> markRead(String id) => _mutate(notifications, (u) => repos.notifications.markRead(u, id));
  Future<void> markAllRead() => _mutate(notifications, repos.notifications.markAllRead);
  Future<void> updatePreferences(NotificationPreferences prefs) => _mutate(preferences, (u) => repos.notifications.updatePreferences(u, prefs));
  Future<void> updateCells(List<NotificationCellChange> cells) => _mutate(preferences, (u) => repos.notifications.updateCells(u, cells));
}
