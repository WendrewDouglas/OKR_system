import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import '../constants/api_constants.dart';

final apiClientProvider = Provider<ApiClient>((ref) => ApiClient());

class ApiClient {
  late final Dio _dio;
  final _storage = const FlutterSecureStorage();

  ApiClient() {
    _dio = Dio(BaseOptions(
      baseUrl: ApiConstants.baseUrl,
      connectTimeout: ApiConstants.connectTimeout,
      receiveTimeout: ApiConstants.receiveTimeout,
      headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
    ));
    _dio.interceptors.add(_AuthInterceptor(_storage, _dio));
  }

  Dio get dio => _dio;

  Future<void> setToken(String token) async {
    await _storage.write(key: ApiConstants.tokenKey, value: token);
  }

  Future<String?> getToken() async {
    return _storage.read(key: ApiConstants.tokenKey);
  }

  Future<void> clearToken() async {
    await _storage.delete(key: ApiConstants.tokenKey);
  }

  Future<bool> hasToken() async {
    final token = await getToken();
    return token != null && token.isNotEmpty;
  }
}

class _AuthInterceptor extends Interceptor {
  final FlutterSecureStorage _storage;
  final Dio _dio;

  _AuthInterceptor(this._storage, this._dio);

  static const List<String> _publicPaths = [
    'auth/login', 'auth/register', 'auth/forgot-password', 'auth/reset-password',
  ];
  static bool _isPublic(String path) => _publicPaths.any((p) => path.contains(p));

  // SEC-11: garante UM único refresh em voo. N requisições com 401 simultâneo
  // aguardam o mesmo Future em vez de dispararem N refreshes concorrentes.
  Future<String?>? _refreshFuture;

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) async {
    if (!_isPublic(options.path)) {
      final token = await _storage.read(key: ApiConstants.tokenKey);
      if (token != null) {
        options.headers['Authorization'] = 'Bearer $token';
      }
    }
    handler.next(options);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) async {
    final reqOpts = err.requestOptions;
    final is401 = err.response?.statusCode == 401;
    // Flag anti-loop: a requisição já reexecutada após refresh não dispara outro refresh.
    final alreadyRetried = reqOpts.extra['__retried__'] == true;
    final isRefreshCall = reqOpts.path.contains('auth/refresh-token');

    if (is401 && !_isPublic(reqOpts.path) && !isRefreshCall && !alreadyRetried) {
      final newToken = await _refresh();
      if (newToken != null) {
        reqOpts.extra['__retried__'] = true;
        reqOpts.headers['Authorization'] = 'Bearer $newToken';
        try {
          final retryRes = await _dio.fetch(reqOpts);
          return handler.resolve(retryRes);
        } catch (_) {
          // cai para handler.next(err) abaixo
        }
      } else {
        // Refresh falhou → sessão expirada
        await _storage.delete(key: ApiConstants.tokenKey);
      }
    }
    handler.next(err);
  }

  /// Coalesce de refresh: chamadas concorrentes recebem o mesmo Future.
  Future<String?> _refresh() {
    return _refreshFuture ??=
        _doRefresh().whenComplete(() => _refreshFuture = null);
  }

  Future<String?> _doRefresh() async {
    final token = await _storage.read(key: ApiConstants.tokenKey);
    if (token == null) return null;
    try {
      final res = await _dio.post(
        '/auth/refresh-token',
        options: Options(
          headers: {'Authorization': 'Bearer $token'},
          extra: {'__retried__': true}, // não reentrar no fluxo de refresh
        ),
      );
      if (res.data is Map && res.data['ok'] == true) {
        final newToken = res.data['token'] as String;
        await _storage.write(key: ApiConstants.tokenKey, value: newToken);
        return newToken;
      }
    } catch (_) {}
    return null;
  }
}
