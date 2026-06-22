import 'package:dio/dio.dart';
import '../models/models.dart';

/// Erro de domínio da API (ok:false em resposta 2xx ou regra de negócio).
class ApiException implements Exception {
  final String message;
  final int? statusCode;
  final String? code;

  ApiException(this.message, {this.statusCode, this.code});

  @override
  String toString() => 'ApiException(${statusCode ?? '-'}): $message';
}

/// Base dos repositórios: encapsula o Dio e o parsing do envelope padrão.
///
/// Erros HTTP (4xx/5xx) já chegam como [DioException] (validateStatus do Dio);
/// [ensureOk] cobre o caso raro de 2xx com `ok:false`.
abstract class BaseRepository {
  final Dio dio;
  BaseRepository(this.dio);

  Map<String, dynamic> bodyOf(Response res) =>
      res.data is Map ? Map<String, dynamic>.from(res.data as Map) : <String, dynamic>{};

  void ensureOk(Response res) {
    final b = bodyOf(res);
    if (!envelopeOk(b)) {
      throw ApiException(
        asString(b['message'], 'Falha na operação.'),
        statusCode: res.statusCode,
        code: asStringOrNull(b['error']),
      );
    }
  }
}

/// Mensagem amigável a partir de um erro de repositório (ApiException/DioException).
/// Centraliza o tratamento usado pelas telas.
String apiErrorMessage(Object e, {String fallback = 'Falha na operação. Tente novamente.'}) {
  if (e is ApiException) return e.message;
  if (e is DioException) {
    final d = e.response?.data;
    if (d is Map && d['message'] != null) return d['message'].toString();
    return 'Falha de conexão. Tente novamente.';
  }
  return fallback;
}
