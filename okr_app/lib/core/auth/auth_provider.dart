import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../network/api_client.dart';

enum AuthStatus { unknown, authenticated, unauthenticated }

class AuthState {
  final AuthStatus status;
  final Map<String, dynamic>? user;
  final bool isLoading;

  const AuthState({this.status = AuthStatus.unknown, this.user, this.isLoading = false});

  AuthState copyWith({AuthStatus? status, Map<String, dynamic>? user, bool? isLoading}) {
    return AuthState(
      status: status ?? this.status,
      user: user ?? this.user,
      isLoading: isLoading ?? this.isLoading,
    );
  }

  int get userId => (user?['id_user'] as int?) ?? 0;
  String get userName => (user?['primeiro_nome'] as String?) ?? '';
  String get userFullName => '${(user?['primeiro_nome'] ?? '')} ${(user?['ultimo_nome'] ?? '')}'.trim();
  String get userEmail => (user?['email'] as String?) ?? '';
  String get userRole => (user?['role_label'] as String?) ?? (user?['funcao'] as String?) ?? '';
  String get userInitials {
    final first = (user?['primeiro_nome'] as String?) ?? '';
    final last = (user?['ultimo_nome'] as String?) ?? '';
    return '${first.isNotEmpty ? first[0] : ''}${last.isNotEmpty ? last[0] : ''}'.toUpperCase();
  }
  int get companyId => (user?['id_company'] as int?) ?? 0;
  String? get avatarUrl => user?['avatar_url'] as String?;
}

class AuthNotifier extends StateNotifier<AuthState> {
  final ApiClient _api;

  AuthNotifier(this._api) : super(const AuthState()) {
    _checkAuth();
  }

  Future<void> _checkAuth() async {
    state = state.copyWith(isLoading: true);
    final hasToken = await _api.hasToken();
    if (!hasToken) {
      state = state.copyWith(status: AuthStatus.unauthenticated, isLoading: false);
      return;
    }
    try {
      final res = await _api.dio.get('/auth/me');
      if (res.data['ok'] == true) {
        state = state.copyWith(
          status: AuthStatus.authenticated,
          user: res.data['user'] as Map<String, dynamic>,
          isLoading: false,
        );
      } else {
        await _api.clearToken();
        state = state.copyWith(status: AuthStatus.unauthenticated, isLoading: false);
      }
    } catch (_) {
      await _api.clearToken();
      state = state.copyWith(status: AuthStatus.unauthenticated, isLoading: false);
    }
  }

  Future<String?> login(String email, String password) async {
    state = state.copyWith(isLoading: true);
    try {
      final res = await _api.dio.post('/auth/login', data: {
        'email': email,
        'password': password,
      });
      if (res.data['ok'] == true) {
        await _api.setToken(res.data['token'] as String);
        state = state.copyWith(
          status: AuthStatus.authenticated,
          user: res.data['user'] as Map<String, dynamic>,
          isLoading: false,
        );
        return null;
      }
      // Generic message — never expose API details
      state = state.copyWith(isLoading: false);
      return 'E-mail ou senha incorretos.';
    } on DioException catch (e) {
      state = state.copyWith(isLoading: false);
      if (e.response?.statusCode == 429) {
        return 'Muitas tentativas. Aguarde alguns minutos.';
      }
      if (e.type == DioExceptionType.connectionTimeout ||
          e.type == DioExceptionType.receiveTimeout ||
          e.type == DioExceptionType.connectionError) {
        return 'Sem conexão. Verifique sua internet.';
      }
      return 'E-mail ou senha incorretos.';
    } catch (_) {
      state = state.copyWith(isLoading: false);
      return 'Erro inesperado. Tente novamente.';
    }
  }

  Future<void> logout() async {
    await _api.clearToken();
    state = const AuthState(status: AuthStatus.unauthenticated);
  }

  Future<void> refreshUser() async => _checkAuth();
}

final authProvider = StateNotifierProvider<AuthNotifier, AuthState>((ref) {
  return AuthNotifier(ref.read(apiClientProvider));
});
