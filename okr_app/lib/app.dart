import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_native_splash/flutter_native_splash.dart';
import 'core/router/app_router.dart';
import 'core/theme/app_theme.dart';
import 'core/services/push_service.dart';
import 'core/auth/auth_provider.dart';

class OkrApp extends ConsumerStatefulWidget {
  const OkrApp({super.key});

  @override
  ConsumerState<OkrApp> createState() => _OkrAppState();
}

class _OkrAppState extends ConsumerState<OkrApp> {
  bool _pushInitialized = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      FlutterNativeSplash.remove();
      _initPush();
    });
  }

  Future<void> _initPush() async {
    if (_pushInitialized) return;
    _pushInitialized = true;
    try {
      final pushService = ref.read(pushServiceProvider);
      await pushService.initialize();
      // Conecta navegacao via push
      pushService.onNavigate = (route) {
        final router = ref.read(routerProvider);
        router.go(route);
      };
    } catch (e) {
      debugPrint('[Push] Init error: $e');
    }
  }

  @override
  Widget build(BuildContext context) {
    final router = ref.watch(routerProvider);
    final authState = ref.watch(authProvider);

    // Registra device apos autenticacao
    ref.listen<AuthState>(authProvider, (prev, next) {
      if (prev?.status != AuthStatus.authenticated &&
          next.status == AuthStatus.authenticated) {
        ref.read(pushServiceProvider).registerDevice();
      }
    });

    return MaterialApp.router(
      title: 'OKR System',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.dark,
      routerConfig: router,
    );
  }
}
