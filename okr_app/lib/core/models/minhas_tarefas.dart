import 'json_utils.dart';

/// KR atribuído ao usuário (bloco `krs` de `GET /minhas-tarefas`).
class TarefaKr {
  final String idKr;
  final String descricao;
  final String status;
  final String farol;
  final String objetivoDescricao;
  final DateTime? dataInicio;
  final DateTime? dataFim;
  final double progressoPct;

  const TarefaKr({
    required this.idKr,
    required this.descricao,
    required this.status,
    this.farol = '',
    this.objetivoDescricao = '',
    this.dataInicio,
    this.dataFim,
    this.progressoPct = 0,
  });

  factory TarefaKr.fromJson(Map<String, dynamic> json) => TarefaKr(
        idKr: asString(json['id_kr']),
        descricao: asString(json['descricao']),
        status: asString(json['status']),
        farol: asString(json['farol']),
        objetivoDescricao: asString(json['objetivo_descricao']),
        dataInicio: asDateOrNull(json['data_inicio']),
        dataFim: asDateOrNull(json['data_fim']),
        progressoPct: asDouble(json['progresso_pct']),
      );
}

/// Iniciativa atribuída ao usuário (bloco `iniciativas` de `GET /minhas-tarefas`).
class TarefaIniciativa {
  final String idIniciativa;
  final String descricao;
  final String status;
  final DateTime? dtPrazo;
  final DateTime? dtCriacao;
  final String krDescricao;

  const TarefaIniciativa({
    required this.idIniciativa,
    required this.descricao,
    required this.status,
    this.dtPrazo,
    this.dtCriacao,
    this.krDescricao = '',
  });

  factory TarefaIniciativa.fromJson(Map<String, dynamic> json) => TarefaIniciativa(
        idIniciativa: asString(json['id_iniciativa']),
        descricao: asString(json['descricao']),
        status: asString(json['status']),
        dtPrazo: asDateOrNull(json['dt_prazo']),
        dtCriacao: asDateOrNull(json['dt_criacao']),
        krDescricao: asString(json['kr_descricao']),
      );
}

/// Payload completo de `GET /minhas-tarefas` (campo `data`).
class MinhasTarefas {
  final List<TarefaKr> krs;
  final List<TarefaIniciativa> iniciativas;

  const MinhasTarefas({this.krs = const [], this.iniciativas = const []});

  factory MinhasTarefas.fromJson(Map<String, dynamic> json) => MinhasTarefas(
        krs: asModelList(json['krs'], TarefaKr.fromJson),
        iniciativas: asModelList(json['iniciativas'], TarefaIniciativa.fromJson),
      );
}
