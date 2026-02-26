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

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) async {
    // Skip auth for public endpoints
    final publicPaths = ['auth/login', 'auth/register', 'auth/forgot-password', 'auth/reset-password'];
    final isPublic = publicPaths.any((p) => options.path.contains(p));

    if (!isPublic) {
      final token = await _storage.read(key: ApiConstants.tokenKey);
      if (token != null) {
        options.headers['Authorization'] = 'Bearer $token';
      }
    }
    handler.next(options);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) async {
    if (err.response?.statusCode == 401) {
      // Try refresh
      final token = await _storage.read(key: ApiConstants.tokenKey);
      if (token != null) {
        try {
          final res = await _dio.post('/auth/refresh-token',
            options: Options(headers: {'Authorization': 'Bearer $token'}),
          );
          if (res.data['ok'] == true) {
            final newToken = res.data['token'] as String;
            await _storage.write(key: ApiConstants.tokenKey, value: newToken);
            // Retry original request
            final opts = err.requestOptions;
            opts.headers['Authorization'] = 'Bearer $newToken';
            final retryRes = await _dio.fetch(opts);
            return handler.resolve(retryRes);
          }
        } catch (_) {}
      }
      await _storage.delete(key: ApiConstants.tokenKey);
    }
    handler.next(err);
  }
}
