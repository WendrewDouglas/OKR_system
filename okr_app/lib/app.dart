import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_native_splash/flutter_native_splash.dart';
import 'core/router/app_router.dart';
import 'core/theme/app_theme.dart';

class OkrApp extends ConsumerStatefulWidget {
  const OkrApp({super.key});

  @override
  ConsumerState<OkrApp> createState() => _OkrAppState();
}

class _OkrAppState extends ConsumerState<OkrApp> {
  @override
  void initState() {
    super.initState();
    // Remove splash after first frame is rendered
    WidgetsBinding.instance.addPostFrameCallback((_) {
      FlutterNativeSplash.remove();
    });
  }

  @override
  Widget build(BuildContext context) {
    final router = ref.watch(routerProvider);
    return MaterialApp.router(
      title: 'OKR System',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.dark,
      routerConfig: router,
    );
  }
}
