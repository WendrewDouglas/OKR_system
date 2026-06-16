import 'json_utils.dart';

/// Referência leve a um usuário (id + nome), usada em dono/responsável/envolvidos.
class UserRef {
  final int idUser;
  final String nome;

  const UserRef({required this.idUser, required this.nome});

  factory UserRef.fromJson(Map<String, dynamic> json) => UserRef(
        idUser: asInt(json['id_user']),
        nome: asString(json['nome']),
      );

  /// Aceita `null` (campos opcionais como responsável).
  static UserRef? fromJsonOrNull(dynamic json) =>
      json is Map ? UserRef.fromJson(Map<String, dynamic>.from(json)) : null;
}
