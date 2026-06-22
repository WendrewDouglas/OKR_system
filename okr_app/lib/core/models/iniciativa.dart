import 'json_utils.dart';
import 'user_ref.dart';

/// Resumo financeiro de uma iniciativa (bloco `orcamento`).
class OrcamentoResumo {
  final double aprovado;
  final double realizado;
  final double saldo;

  const OrcamentoResumo({
    this.aprovado = 0,
    this.realizado = 0,
    this.saldo = 0,
  });

  factory OrcamentoResumo.fromJson(Map<String, dynamic> json) => OrcamentoResumo(
        aprovado: asDouble(json['aprovado']),
        realizado: asDouble(json['realizado']),
        saldo: asDouble(json['saldo']),
      );
}

/// Iniciativa (item de `GET /krs/:id/iniciativas`).
class Iniciativa {
  final String idIniciativa;
  final int numIniciativa;
  final String descricao;
  final String status;
  final DateTime? dtPrazo;
  final DateTime? dtCriacao;
  final UserRef? responsavel;
  final List<UserRef> envolvidos;
  final OrcamentoResumo? orcamento;

  const Iniciativa({
    required this.idIniciativa,
    required this.numIniciativa,
    required this.descricao,
    required this.status,
    this.dtPrazo,
    this.dtCriacao,
    this.responsavel,
    this.envolvidos = const [],
    this.orcamento,
  });

  factory Iniciativa.fromJson(Map<String, dynamic> json) => Iniciativa(
        idIniciativa: asString(json['id_iniciativa']),
        numIniciativa: asInt(json['num_iniciativa']),
        descricao: asString(json['descricao']),
        status: asString(json['status']),
        dtPrazo: asDateOrNull(json['dt_prazo']),
        dtCriacao: asDateOrNull(json['dt_criacao']),
        responsavel: UserRef.fromJsonOrNull(json['responsavel']),
        envolvidos: asModelList(json['envolvidos'], UserRef.fromJson),
        orcamento: json['orcamento'] is Map
            ? OrcamentoResumo.fromJson(asMap(json['orcamento']))
            : null,
      );
}
