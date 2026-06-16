import 'json_utils.dart';

/// Paginação do envelope padrão da API (`pagination`).
class Pagination {
  final int page;
  final int perPage;
  final int total;
  final int pages;

  const Pagination({
    required this.page,
    required this.perPage,
    required this.total,
    required this.pages,
  });

  factory Pagination.fromJson(Map<String, dynamic> json) => Pagination(
        page: asInt(json['page'], 1),
        perPage: asInt(json['per_page'], 0),
        total: asInt(json['total'], 0),
        pages: asInt(json['pages'], 1),
      );

  bool get hasMore => page < pages;
}

/// Resposta de lista paginada já desserializada em DTOs.
class Paged<T> {
  final List<T> items;
  final Pagination? pagination;

  const Paged(this.items, [this.pagination]);
}

/// Extrai o payload `data` do envelope padrão.
///
/// Compatibilidade (envelope aditivo): se `data` ainda não existir no endpoint,
/// cai para as [legacyKeys] informadas (ex.: 'krs', 'apontamentos', 'items').
dynamic envelopeData(Map<String, dynamic> body, {List<String> legacyKeys = const []}) {
  if (body.containsKey('data')) return body['data'];
  for (final k in legacyKeys) {
    if (body.containsKey(k)) return body[k];
  }
  return null;
}

/// Lê `pagination` do envelope (ou monta a partir das chaves legadas items/page/...).
Pagination? envelopePagination(Map<String, dynamic> body) {
  if (body['pagination'] is Map) {
    return Pagination.fromJson(asMap(body['pagination']));
  }
  if (body.containsKey('page') || body.containsKey('total')) {
    return Pagination.fromJson(body);
  }
  return null;
}

/// `true` se o envelope indica sucesso.
bool envelopeOk(Map<String, dynamic> body) => asBool(body['ok'], false);
