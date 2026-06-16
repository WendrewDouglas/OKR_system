import 'dart:io';
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../network/api_client.dart';

/// Handler para mensagens em background (top-level function obrigatoria)
@pragma('vm:entry-point')
Future<void> _firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  await Firebase.initializeApp();
  debugPrint('[Push] Background message: ${message.messageId}');
}

/// Provider global do servico de push
final pushServiceProvider = Provider<PushService>((ref) {
  return PushService(ref.read(apiClientProvider));
});

class PushService {
  final ApiClient _api;
  final FlutterLocalNotificationsPlugin _localNotifications =
      FlutterLocalNotificationsPlugin();

  String? _currentToken;
  void Function(String route)? onNavigate;

  PushService(this._api);

  /// Inicializa Firebase + FCM + permissoes + listeners
  Future<void> initialize() async {
    try {
      await Firebase.initializeApp();
    } catch (e) {
      debugPrint('[Push] Firebase already initialized or error: $e');
    }

    // Background handler
    FirebaseMessaging.onBackgroundMessage(_firebaseMessagingBackgroundHandler);

    // Permissao
    final settings = await FirebaseMessaging.instance.requestPermission(
      alert: true,
      badge: true,
      sound: true,
    );
    debugPrint('[Push] Permission: ${settings.authorizationStatus}');

    // Local notifications (para foreground)
    const androidSettings = AndroidInitializationSettings('@mipmap/ic_launcher');
    const iosSettings = DarwinInitializationSettings(
      requestAlertPermission: true,
      requestBadgePermission: true,
      requestSoundPermission: true,
    );
    await _localNotifications.initialize(
      const InitializationSettings(android: androidSettings, iOS: iosSettings),
      onDidReceiveNotificationResponse: _onNotificationTap,
    );

    // Canal Android
    const androidChannel = AndroidNotificationChannel(
      'push_campaigns',
      'Campanhas Push',
      description: 'Notificacoes de campanhas do OKR System',
      importance: Importance.high,
    );
    await _localNotifications
        .resolvePlatformSpecificImplementation<
            AndroidFlutterLocalNotificationsPlugin>()
        ?.createNotificationChannel(androidChannel);

    // Foreground messages
    FirebaseMessaging.onMessage.listen(_handleForegroundMessage);

    // Quando o app abre via push (background -> foreground)
    FirebaseMessaging.onMessageOpenedApp.listen(_handleMessageOpenedApp);

    // Verifica se o app foi aberto via push (terminated -> foreground)
    final initialMessage = await FirebaseMessaging.instance.getInitialMessage();
    if (initialMessage != null) {
      _handleMessageOpenedApp(initialMessage);
    }

    // Token
    _currentToken = await FirebaseMessaging.instance.getToken();
    // SEC-10: nunca logar o token (credencial). Só um indicador mascarado, em debug.
    if (kDebugMode) {
      debugPrint('[Push] Token obtido: '
          '${_currentToken == null ? 'null' : '***${_currentToken!.length} chars'}');
    }

    // Token refresh
    FirebaseMessaging.instance.onTokenRefresh.listen((newToken) {
      _currentToken = newToken;
      _registerToken(newToken);
    });
  }

  /// Registra token no servidor (chamar apos login)
  Future<void> registerDevice() async {
    // Retry ate 3 vezes com delay (SERVICE_NOT_AVAILABLE em MIUI/Xiaomi)
    for (int attempt = 0; attempt < 3; attempt++) {
      try {
        _currentToken ??= await FirebaseMessaging.instance.getToken();
        if (_currentToken != null) {
          await _registerToken(_currentToken!);
          return;
        }
      } catch (e) {
        debugPrint('[Push] Token attempt ${attempt + 1} failed: $e');
        if (attempt < 2) {
          await Future.delayed(Duration(seconds: 3 * (attempt + 1)));
        }
      }
    }
    debugPrint('[Push] Could not get FCM token after 3 attempts');
  }

  Future<void> _registerToken(String token) async {
    try {
      await _api.dio.post('/push/devices/register', data: {
        'token': token,
        'platform': Platform.isIOS ? 'ios' : 'android',
        'app_version': '1.0.0',
        'os_version': Platform.operatingSystemVersion,
        'device_model': Platform.localHostname,
        'locale': Platform.localeName,
        'timezone': DateTime.now().timeZoneName,
        'notifications_enabled': 1,
      });
      debugPrint('[Push] Token registered successfully');
    } catch (e) {
      debugPrint('[Push] Token registration failed: $e');
    }
  }

  /// Desregistra token (chamar no logout)
  Future<void> unregisterDevice() async {
    if (_currentToken != null) {
      try {
        await _api.dio.post('/push/devices/unregister', data: {
          'token': _currentToken,
        });
      } catch (_) {}
    }
  }

  /// Trata mensagem em foreground: mostra notificacao local
  void _handleForegroundMessage(RemoteMessage message) {
    debugPrint('[Push] Foreground: ${message.notification?.title}');
    final notification = message.notification;
    if (notification == null) return;

    _localNotifications.show(
      message.hashCode,
      notification.title,
      notification.body,
      NotificationDetails(
        android: AndroidNotificationDetails(
          'push_campaigns',
          'Campanhas Push',
          icon: '@mipmap/ic_launcher',
          importance: Importance.high,
          priority: Priority.high,
          styleInformation: notification.body != null
              ? BigTextStyleInformation(notification.body!)
              : null,
        ),
        iOS: const DarwinNotificationDetails(
          presentAlert: true,
          presentBadge: true,
          presentSound: true,
        ),
      ),
      payload: message.data['route'] ?? '',
    );

    // Registra evento de entrega
    _trackEvent('delivered', message.data);
  }

  /// Trata clique no push (app em background/terminated)
  void _handleMessageOpenedApp(RemoteMessage message) {
    if (kDebugMode) debugPrint('[Push] Opened: ${message.data}');
    final route = message.data['route'] as String?;
    if (route != null && route.isNotEmpty && onNavigate != null) {
      onNavigate!(route);
    }
    _trackEvent('open', message.data);
  }

  /// Trata clique na notificacao local (foreground)
  void _onNotificationTap(NotificationResponse response) {
    final route = response.payload;
    if (route != null && route.isNotEmpty && onNavigate != null) {
      onNavigate!(route);
    }
  }

  /// Rastreia evento no servidor
  Future<void> _trackEvent(String eventType, Map<String, dynamic> data) async {
    final campaignId = data['campaign_id'];
    if (campaignId == null) return;
    try {
      await _api.dio.post('/push/events/$eventType', data: {
        'campaign_id': campaignId,
      });
    } catch (_) {}
  }
}
