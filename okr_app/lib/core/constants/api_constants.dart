class ApiConstants {
  // Ambiente parametrizável: passe --dart-define=API_BASE_URL=... no build/run.
  // Default mantém produção para não quebrar builds existentes.
  static const String baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://planningbi.com.br/OKR_system/api/api_platform/v1',
  );
  static const Duration connectTimeout = Duration(seconds: 15);
  static const Duration receiveTimeout = Duration(seconds: 30);
  static const String tokenKey = 'auth_token';
  static const String userKey = 'user_data';
}
