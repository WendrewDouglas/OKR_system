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
  String get userEmail => (user?['email'] as String?) ?? '';
  int get companyId => (user?['id_company'] as int?) ?? 0;
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
      state = state.copyWith(isLoading: false);
      return res.data['message'] as String? ?? 'Erro desconhecido';
    } catch (e) {
      state = state.copyWith(isLoading: false);
      return 'Erro de conexão. Verifique sua internet.';
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
