import 'json_utils.dart';
import 'user_ref.dart';

/// Objetivo estratégico (item de `GET /objetivos`).
class Objetivo {
  final String idObjetivo;
  final String descricao;
  final String status;
  final String statusAprovacao;
  final String? pilarBsc;
  final String? tipo;
  final String? qualidade;
  final DateTime? dtCriacao;
  final DateTime? dtPrazo;
  final DateTime? dtConclusao;
  final int qtdKrs;
  final UserRef? dono;

  const Objetivo({
    required this.idObjetivo,
    required this.descricao,
    required this.status,
    required this.statusAprovacao,
    this.pilarBsc,
    this.tipo,
    this.qualidade,
    this.dtCriacao,
    this.dtPrazo,
    this.dtConclusao,
    this.qtdKrs = 0,
    this.dono,
  });

  factory Objetivo.fromJson(Map<String, dynamic> json) => Objetivo(
        idObjetivo: asString(json['id_objetivo']),
        descricao: asString(json['descricao']),
        status: asString(json['status']),
        statusAprovacao: asString(json['status_aprovacao']),
        pilarBsc: asStringOrNull(json['pilar_bsc']),
        tipo: asStringOrNull(json['tipo']),
        qualidade: asStringOrNull(json['qualidade']),
        dtCriacao: asDateOrNull(json['dt_criacao']),
        dtPrazo: asDateOrNull(json['dt_prazo']),
        dtConclusao: asDateOrNull(json['dt_conclusao']),
        qtdKrs: asInt(json['qtd_krs']),
        dono: UserRef.fromJsonOrNull(json['dono']),
      );
}
