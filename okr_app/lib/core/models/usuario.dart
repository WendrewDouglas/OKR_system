import 'json_utils.dart';

/// Usuário (itens de `GET /usuarios` e `GET /usuarios/:id`).
class Usuario {
  final int idUser;
  final String primeiroNome;
  final String ultimoNome;
  final String email;
  final String telefone;
  final int? idCompany;
  final String empresa;
  final String roleKey;
  final String roleName;
  final DateTime? dtCadastro;
  final String? avatarUrl;

  const Usuario({
    required this.idUser,
    required this.primeiroNome,
    this.ultimoNome = '',
    this.email = '',
    this.telefone = '',
    this.idCompany,
    this.empresa = '',
    this.roleKey = '',
    this.roleName = '',
    this.dtCadastro,
    this.avatarUrl,
  });

  factory Usuario.fromJson(Map<String, dynamic> json) => Usuario(
        idUser: asInt(json['id_user']),
        primeiroNome: asString(json['primeiro_nome']),
        ultimoNome: asString(json['ultimo_nome']),
        email: asString(json['email']),
        telefone: asString(json['telefone']),
        idCompany: asIntOrNull(json['id_company']),
        empresa: asString(json['empresa']),
        roleKey: asString(json['role_key']),
        roleName: asString(json['role_name']),
        dtCadastro: asDateOrNull(json['dt_cadastro']),
        avatarUrl: json['avatar_url'] as String?,
      );

  String get nomeCompleto => '$primeiroNome $ultimoNome'.trim();

  String get iniciais {
    final a = primeiroNome.isNotEmpty ? primeiroNome[0] : '';
    final b = ultimoNome.isNotEmpty ? ultimoNome[0] : '';
    return '$a$b'.toUpperCase();
  }
}
