import 'json_utils.dart';

/// Item de aprovação (em `para_aprovar` ou `minhas_pendentes`).
class AprovacaoItem {
  final String modulo; // objetivo | kr | orcamento
  final String idRef;
  final String descricao;
  final String statusAprovacao;
  final DateTime? dtCriacao;
  final String criador;

  const AprovacaoItem({
    required this.modulo,
    required this.idRef,
    required this.descricao,
    required this.statusAprovacao,
    this.dtCriacao,
    this.criador = '',
  });

  factory AprovacaoItem.fromJson(Map<String, dynamic> json) => AprovacaoItem(
        modulo: asString(json['modulo']),
        idRef: asString(json['id_ref']),
        descricao: asString(json['descricao']),
        statusAprovacao: asString(json['status_aprovacao']),
        dtCriacao: asDateOrNull(json['dt_criacao']),
        criador: asString(json['criador']),
      );
}

/// Payload de `GET /aprovacoes` (campo `data`).
class AprovacoesData {
  final int pendentes;
  final int reprovados;
  final int aprovados;
  final List<AprovacaoItem> paraAprovar;
  /// Itens do próprio usuário (pendentes/reprovados/aprovados) — filtrados na UI.
  final List<AprovacaoItem> minhasPendentes;

  const AprovacoesData({
    this.pendentes = 0,
    this.reprovados = 0,
    this.aprovados = 0,
    this.paraAprovar = const [],
    this.minhasPendentes = const [],
  });

  factory AprovacoesData.fromJson(Map<String, dynamic> json) {
    final stats = asMap(json['stats']);
    return AprovacoesData(
      pendentes: asInt(stats['pendentes']),
      reprovados: asInt(stats['reprovados']),
      aprovados: asInt(stats['aprovados']),
      paraAprovar: asModelList(json['para_aprovar'], AprovacaoItem.fromJson),
      minhasPendentes: asModelList(json['minhas_pendentes'], AprovacaoItem.fromJson),
    );
  }
}
