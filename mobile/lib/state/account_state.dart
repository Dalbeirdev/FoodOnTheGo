import 'package:flutter/foundation.dart';

import '../account/account_repositories.dart';
import '../auth/auth_repository.dart' show AuthUser, SecureKeyValueStore;

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
  AccountState({AccountRepositories? repositories}) : repos = repositories ?? AccountRepositories.mock(MockAccountStore(store: SecureKeyValueStore()));
  final AccountRepositories repos;

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
  Future<CustomerProfile> setAvatar(String? path) => _mutate(profile, (u) => repos.profile.setAvatar(u, path)).then((p) => p!);
  Future<CustomerProfile> requestDeletion() => _mutate(profile, (u) => repos.profile.requestDeletion(u)).then((p) => p!);

  // Favorites
  Future<void> addFavorite(String id) => _mutate(favorites, (u) => repos.favorites.add(u, id));
  Future<void> removeFavorite(String id) => _mutate(favorites, (u) => repos.favorites.remove(u, id));
  Future<void> toggleFavorite(String id) => isFavorite(id) ? removeFavorite(id) : addFavorite(id);

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
}
