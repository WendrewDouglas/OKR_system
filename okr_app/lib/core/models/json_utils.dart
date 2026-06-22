/// Utilitários de parsing tolerante para JSON vindo da API.
///
/// O backend às vezes envia números como string (ex.: decimais MySQL) e datas
/// como 'YYYY-MM-DD' ou 'YYYY-MM-DD HH:MM:SS'. Estas funções normalizam isso
/// sem lançar exceção, evitando casts crus espalhados pela UI.
library;

int? asIntOrNull(dynamic v) {
  if (v == null) return null;
  if (v is int) return v;
  if (v is num) return v.toInt();
  return int.tryParse(v.toString());
}

int asInt(dynamic v, [int def = 0]) => asIntOrNull(v) ?? def;

double? asDoubleOrNull(dynamic v) {
  if (v == null) return null;
  if (v is num) return v.toDouble();
  return double.tryParse(v.toString().replaceAll(',', '.'));
}

double asDouble(dynamic v, [double def = 0]) => asDoubleOrNull(v) ?? def;

String asString(dynamic v, [String def = '']) => v?.toString() ?? def;

String? asStringOrNull(dynamic v) {
  if (v == null) return null;
  final s = v.toString();
  return s.isEmpty ? null : s;
}

bool asBool(dynamic v, [bool def = false]) {
  if (v is bool) return v;
  if (v is num) return v != 0;
  final s = v?.toString().toLowerCase();
  if (s == 'true' || s == '1') return true;
  if (s == 'false' || s == '0') return false;
  return def;
}

Map<String, dynamic> asMap(dynamic v) =>
    v is Map ? Map<String, dynamic>.from(v) : <String, dynamic>{};

List<dynamic> asList(dynamic v) => v is List ? v : const [];

/// Mapeia uma lista de JSON em DTOs, ignorando entradas não-mapa.
List<T> asModelList<T>(dynamic v, T Function(Map<String, dynamic>) fromJson) =>
    asList(v)
        .whereType<Map>()
        .map((e) => fromJson(Map<String, dynamic>.from(e)))
        .toList();

DateTime? asDateOrNull(dynamic v) {
  final s = v?.toString();
  if (s == null || s.isEmpty) return null;
  // Aceita 'YYYY-MM-DD', ISO e 'YYYY-MM-DD HH:MM:SS' (espaço → T).
  return DateTime.tryParse(s.contains(' ') ? s.replaceFirst(' ', 'T') : s);
}
