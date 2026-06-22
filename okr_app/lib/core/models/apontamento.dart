import 'json_utils.dart';

/// Apontamento de progresso de um KR (item de `GET /krs/:id/apontamentos`).
class Apontamento {
  final int idApontamento;
  final int? idMilestone;
  final DateTime? milestoneData;
  final double? valorEsperado;
  final double? valorReal;
  final DateTime? dtEvidencia;
  final String? observacao;
  final String? justificativa;
  final String? origem;
  final DateTime? dtApontamento;
  final String? usuarioId;

  const Apontamento({
    required this.idApontamento,
    this.idMilestone,
    this.milestoneData,
    this.valorEsperado,
    this.valorReal,
    this.dtEvidencia,
    this.observacao,
    this.justificativa,
    this.origem,
    this.dtApontamento,
    this.usuarioId,
  });

  factory Apontamento.fromJson(Map<String, dynamic> json) => Apontamento(
        idApontamento: asInt(json['id_apontamento']),
        idMilestone: asIntOrNull(json['id_milestone']),
        milestoneData: asDateOrNull(json['milestone_data']),
        valorEsperado: asDoubleOrNull(json['valor_esperado']),
        valorReal: asDoubleOrNull(json['valor_real']),
        dtEvidencia: asDateOrNull(json['dt_evidencia']),
        observacao: asStringOrNull(json['observacao']),
        justificativa: asStringOrNull(json['justificativa']),
        origem: asStringOrNull(json['origem']),
        dtApontamento: asDateOrNull(json['dt_apontamento']),
        usuarioId: asStringOrNull(json['usuario_id']),
      );
}
