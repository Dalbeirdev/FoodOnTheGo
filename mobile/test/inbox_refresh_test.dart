// Module 33: the quiet inbox refresh of AccountState — the list on screen stays while it loads, a failure keeps it,
// and a list that belongs to a customer who has signed out meanwhile is never shown to the next one.
import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/state/account_state.dart';

AppNotification notice(String id, {bool read = false}) => AppNotification(id: id, kind: 'orders', title: 'Order placed', text: 'Your order has reached the restaurant.', at: '2026-10-11T09:00:00Z', read: read);

/// The development repository with a list this test controls.
class ScriptedInbox extends MockNotificationRepository {
  ScriptedInbox(super.store);
  int calls = 0;
  Future<List<AppNotification>> Function(String userId)? next;
  @override
  Future<List<AppNotification>> list(String userId) { calls += 1; return next!(userId); }
}

const rahul = AuthUser(id: 'cust-rahul', name: 'Rahul Sharma', phone: '+919876543210', memberSince: '2026-01-01');
const asha = AuthUser(id: 'cust-asha', name: 'Asha Verma', phone: '+919876543212', memberSince: '2026-01-01');

void main() {
  late ScriptedInbox inbox;
  late AccountState account;

  setUp(() async {
    final store = MockAccountStore(store: MemoryKeyValueStore(), latency: Duration.zero);
    final mock = AccountRepositories.mock(store);
    inbox = ScriptedInbox(store)..next = (_) async => [notice('n1')];
    account = AccountState(repositories: AccountRepositories(profile: mock.profile, favorites: mock.favorites, addresses: mock.addresses, payments: mock.payments, notifications: inbox));
    await account.bind(rahul);
  });

  test('a refresh keeps the list ready (no skeleton) and shows the new list when it arrives', () async {
    expect(account.notifications.status, LoadStatus.ready);
    expect(account.notifications.data.map((n) => n.id), ['n1']);
    final gate = Completer<List<AppNotification>>();
    inbox.next = (_) => gate.future;
    final statuses = <LoadStatus>[];
    account.addListener(() => statuses.add(account.notifications.status));
    final pending = account.refreshNotifications();
    await Future<void>.delayed(Duration.zero);
    expect(account.notifications.status, LoadStatus.ready, reason: 'the list stays on screen while the read is in flight');
    expect(account.notifications.data.map((n) => n.id), ['n1']);
    gate.complete([notice('n2'), notice('n1', read: true)]);
    await pending;
    expect(account.notifications.data.map((n) => n.id), ['n2', 'n1']);
    expect(account.unreadCount, 1);
    expect(statuses.contains(LoadStatus.loading), isFalse);
  });

  test('a failed refresh keeps what is shown and raises no error state', () async {
    inbox.next = (_) async => throw RepositoryException('notifications', 'offline');
    await account.refreshNotifications();
    expect(account.notifications.status, LoadStatus.ready);
    expect(account.notifications.error, isNull);
    expect(account.notifications.data.map((n) => n.id), ['n1']);
  });

  test('a list that arrives after another customer signed in is dropped', () async {
    final gate = Completer<List<AppNotification>>();
    inbox.next = (_) => gate.future;
    final pending = account.refreshNotifications();
    inbox.next = (_) async => [notice('asha-1')];
    await account.bind(asha);
    gate.complete([notice('rahul-late')]);
    await pending;
    expect(account.notifications.data.map((n) => n.id), ['asha-1']);
  });

  test('signed out: a refresh does nothing', () async {
    await account.bind(null);
    final before = inbox.calls;
    await account.refreshNotifications();
    expect(inbox.calls, before);
    expect(account.notifications.data, isEmpty);
  });
}
