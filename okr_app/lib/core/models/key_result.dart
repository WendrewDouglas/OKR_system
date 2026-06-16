import 'json_utils.dart';
import 'user_ref.dart';

/// Progresso atual de um KR (bloco `progress`).
class KrProgress {
  final double? valorAtual;
  final double? valorEsperado;
  final double? pctAtual;

  const KrProgress({this.valorAtual, this.valorEsperado, this.pctAtual});

  factory KrProgress.fromJson(Map<String, dynamic> json) => KrProgress(
        valorAtual: asDoubleOrNull(json['valor_atual']),
        valorEsperado: asDoubleOrNull(json['valor_esperado']),
        pctAtual: asDoubleOrNull(json['pct_atual']),
      );
}

/// Key Result (item de `GET /objetivos/:id/krs`).
class KeyResult {
  final String idKr;
  final int keyResultNum;
  final String descricao;
  final String status;
  final String statusAprovacao;
  final double baseline;
  final double meta;
  final String? unidadeMedida;
  final String? direcaoMetrica;
  final String? farol;
  final String? naturezaKr;
  final String? tipoFrequenciaMilestone;
  final DateTime? dataInicio;
  final DateTime? dataFim;
  final UserRef? responsavel;
  final KrProgress progress;

  const KeyResult({
    required this.idKr,
    required this.keyResultNum,
    required this.descricao,
    required this.status,
    required this.statusAprovacao,
    this.baseline = 0,
    this.meta = 0,
    this.unidadeMedida,
    this.direcaoMetrica,
    this.farol,
    this.naturezaKr,
    this.tipoFrequenciaMilestone,
    this.dataInicio,
    this.dataFim,
    this.responsavel,
    this.progress = const KrProgress(),
  });

  factory KeyResult.fromJson(Map<String, dynamic> json) => KeyResult(
        idKr: asString(json['id_kr']),
        keyResultNum: asInt(json['key_result_num']),
        descricao: asString(json['descricao']),
        status: asString(json['status']),
        statusAprovacao: asString(json['status_aprovacao']),
        baseline: asDouble(json['baseline']),
        meta: asDouble(json['meta']),
        unidadeMedida: asStringOrNull(json['unidade_medida']),
        direcaoMetrica: asStringOrNull(json['direcao_metrica']),
        farol: asStringOrNull(json['farol']),
        naturezaKr: asStringOrNull(json['natureza_kr']),
        tipoFrequenciaMilestone: asStringOrNull(json['tipo_frequencia_milestone']),
        dataInicio: asDateOrNull(json['data_inicio']),
        dataFim: asDateOrNull(json['data_fim']),
        responsavel: UserRef.fromJsonOrNull(json['responsavel']),
        progress: KrProgress.fromJson(asMap(json['progress'])),
      );
}
