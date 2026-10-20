import 'api.dart';
import 'poller.dart';
import 'push.dart';
import 'session.dart';
import 'store.dart';

/// The app's singletons, passed down the widget tree.
class Services {
  Services({required this.api, required this.session, required this.store, required this.poller, this.push});
  final Api api; final Session session; final ChatStore store; final Poller poller; final PushService? push;
}
