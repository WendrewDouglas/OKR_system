import 'dart:math' as math;
import 'package:flutter/foundation.dart';

/// Porte fiel do cálculo de milestones do sistema web.
///
/// Espelha `views/novo_key_result.php` (client-side: gerarSerieDatas /
/// calcularEsperados / normNat) que por sua vez espelha
/// `auth/salvar_kr.php::gerarMilestonesParaKR` (server-side, fonte da verdade).
///
/// Objetivo: a prévia exibida no app é IDÊNTICA aos milestones que o backend
/// gera ao salvar o KR (mesmas datas, mesmos valores esperados).
@immutable
class Milestone {
  final int ordem; // 1..N
  final DateTime dataRef;
  final double? esperado; // série única (natureza)
  final double? esperadoMin; // faixa (INTERVALO_IDEAL)
  final double? esperadoMax;

  const Milestone({
    required this.ordem,
    required this.dataRef,
    this.esperado,
    this.esperadoMin,
    this.esperadoMax,
  });

  bool get isIntervalo => esperadoMin != null && esperadoMax != null;
}

class MilestoneCalc {
  /// Normaliza a natureza para um dos slugs canônicos.
  static String normNat(String n) {
    var s = _stripAccents(n.trim().toLowerCase())
        .replaceAll(RegExp(r'\s+'), '_')
        .replaceAll(RegExp(r'[^a-z0-9_]'), '');
    if (const ['acumulativo', 'acumulativa', 'acumulativo_constante', 'acumulado_constante', 'constante'].contains(s)) {
      return 'acumulativo_constante';
    }
    if (s == 'acumulativo_exponencial' ||
        s.startsWith('acumulativo_exponen') ||
        s == 'acumulado_exponencial' ||
        s == 'exponencial' ||
        s == 'expo') {
      return 'acumulativo_exponencial';
    }
    if (const ['binario', 'binaria'].contains(s)) return 'binario';
    if (const ['pontual', 'flutuante'].contains(s)) return 'pontual';
    return s;
  }

  /// Unidades de contagem → milestones arredondados a inteiro.
  static bool unidadeRequerInteiro(String? u) {
    final s = (u ?? '').toLowerCase().trim();
    const ints = ['unid', 'itens', 'pcs', 'ord', 'proc', 'contratos', 'processos', 'pessoas', 'casos', 'tickets', 'visitas'];
    return ints.contains(s);
  }

  // ---- séries de datas (espelha gerarSerieDatas) ----

  static DateTime _endOfMonth(DateTime d) => DateTime(d.year, d.month + 1, 0);

  static DateTime _endOfMonthOffsetFromStart(DateTime d, int stepMonths) =>
      _endOfMonth(DateTime(d.year, d.month + (stepMonths - 1), 1));

  static DateTime _endOfMonthAdvance(DateTime d, int stepMonths) =>
      _endOfMonth(DateTime(d.year, d.month + stepMonths, 1));

  static String _iso(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  static List<DateTime> gerarSerieDatas(DateTime start, DateTime end, String freq) {
    final out = <DateTime>[];
    final f = _stripAccents(freq.toLowerCase().trim());

    void pushUnique(DateTime d) {
      final day = DateTime(d.year, d.month, d.day);
      if (out.isEmpty || _iso(out.last) != _iso(day)) out.add(day);
    }

    if (f == 'semanal' || f == 'quinzenal') {
      final stepDays = f == 'semanal' ? 7 : 15;
      var d = start.add(Duration(days: stepDays));
      while (d.isBefore(end)) {
        pushUnique(d);
        d = d.add(Duration(days: stepDays));
      }
      pushUnique(end);
    } else {
      const map = {'mensal': 1, 'bimestral': 2, 'trimestral': 3, 'semestral': 6, 'anual': 12};
      final stepMonths = map[f] ?? 1;
      final firstEnd = _endOfMonthOffsetFromStart(start, stepMonths);
      if (firstEnd.isAfter(end)) {
        pushUnique(end);
      } else {
        pushUnique(firstEnd);
        var d = _endOfMonthAdvance(firstEnd, stepMonths);
        while (d.isBefore(end)) {
          pushUnique(d);
          d = _endOfMonthAdvance(d, stepMonths);
        }
        pushUnique(end);
      }
    }
    if (out.isEmpty) out.add(DateTime(end.year, end.month, end.day));
    return out;
  }

  // ---- valores esperados (espelha calcularEsperados / gerarMilestonesParaKR) ----

  /// Deriva (início, fim) a partir do tipo + sub-parâmetros do ciclo.
  /// Espelha `auth/helpers/cycle_calc.php::calcularDatasCiclo` (backend), para
  /// que o período da prévia seja igual ao usado na geração server-side.
  /// [d] usa as mesmas chaves enviadas à API: ciclo_anual_ano, ciclo_semestral
  /// ('S1/2026'), ciclo_trimestral ('Q1/2026'), ciclo_bimestral ('01-02-2026'),
  /// ciclo_mensal_mes/ciclo_mensal_ano, ciclo_pers_inicio/fim ('2026-03').
  static ({DateTime inicio, DateTime fim})? datasDoCiclo(String tipo, Map<String, dynamic> d) {
    String s(String k) => (d[k] ?? '').toString();
    DateTime? ini, fim;
    switch (tipo) {
      case 'anual':
        final ano = int.tryParse(s('ciclo_anual_ano'));
        if (ano != null) {
          ini = DateTime(ano, 1, 1);
          fim = DateTime(ano, 12, 31);
        }
        break;
      case 'semestral':
        final m = RegExp(r'^S([12])/(\d{4})$').firstMatch(s('ciclo_semestral'));
        if (m != null) {
          final ano = int.parse(m.group(2)!);
          if (m.group(1) == '1') {
            ini = DateTime(ano, 1, 1);
            fim = DateTime(ano, 6, 30);
          } else {
            ini = DateTime(ano, 7, 1);
            fim = DateTime(ano, 12, 31);
          }
        }
        break;
      case 'trimestral':
        final m = RegExp(r'^Q([1-4])/(\d{4})$').firstMatch(s('ciclo_trimestral'));
        if (m != null) {
          final ano = int.parse(m.group(2)!);
          final sm = (int.parse(m.group(1)!) - 1) * 3 + 1;
          ini = DateTime(ano, sm, 1);
          fim = _endOfMonth(DateTime(ano, sm + 2, 1));
        }
        break;
      case 'bimestral':
        final m = RegExp(r'^(\d{2})-(\d{2})-(\d{4})$').firstMatch(s('ciclo_bimestral'));
        if (m != null) {
          final ano = int.parse(m.group(3)!);
          ini = DateTime(ano, int.parse(m.group(1)!), 1);
          fim = _endOfMonth(DateTime(ano, int.parse(m.group(2)!), 1));
        }
        break;
      case 'mensal':
        final mes = int.tryParse(s('ciclo_mensal_mes'));
        final ano = int.tryParse(s('ciclo_mensal_ano'));
        if (mes != null && ano != null) {
          ini = DateTime(ano, mes, 1);
          fim = _endOfMonth(DateTime(ano, mes, 1));
        }
        break;
      case 'personalizado':
        final mi = RegExp(r'^(\d{4})-(\d{2})$').firstMatch(s('ciclo_pers_inicio'));
        final mf = RegExp(r'^(\d{4})-(\d{2})$').firstMatch(s('ciclo_pers_fim'));
        if (mi != null && mf != null) {
          ini = DateTime(int.parse(mi.group(1)!), int.parse(mi.group(2)!), 1);
          fim = _endOfMonth(DateTime(int.parse(mf.group(1)!), int.parse(mf.group(2)!), 1));
        }
        break;
    }
    if (ini == null || fim == null) return null;
    return (inicio: ini, fim: fim);
  }

  static double _expoR(int n) {
    if (n <= 4) return 1.8;
    if (n <= 8) return 1.5;
    if (n <= 16) return 1.3;
    if (n <= 32) return 1.2;
    return 1.12;
  }

  static double _round(double v, bool isInt) =>
      isInt ? v.roundToDouble() : (v * 100).round() / 100;

  /// Gera os milestones completos (datas + valores) idênticos ao web.
  static List<Milestone> gerar({
    required DateTime inicio,
    required DateTime fim,
    required String frequencia,
    required double baseline,
    required double meta,
    required String natureza,
    String? direcao,
    String? unidade,
  }) {
    final datas = gerarSerieDatas(inicio, fim, frequencia);
    final n = datas.length;
    if (n == 0) return const [];

    final isInt = unidadeRequerInteiro(unidade);
    final isIntervalo = (direcao ?? '').toUpperCase() == 'INTERVALO_IDEAL';

    if (isIntervalo) {
      final lo = _round(math.min(baseline, meta), isInt);
      final hi = _round(math.max(baseline, meta), isInt);
      final mid = _round((lo + hi) / 2, isInt);
      return [
        for (var i = 0; i < n; i++)
          Milestone(ordem: i + 1, dataRef: datas[i], esperado: mid, esperadoMin: lo, esperadoMax: hi),
      ];
    }

    final slug = normNat(natureza);
    final isBin = slug == 'binario';
    final isConst = slug == 'acumulativo_constante';
    final isExpo = slug == 'acumulativo_exponencial';
    final delta = meta - baseline;

    final out = <Milestone>[];
    for (var i = 1; i <= n; i++) {
      double progress;
      if (isBin) {
        progress = i == n ? 1.0 : 0.0;
      } else if (isConst) {
        progress = n > 0 ? i / n : 1.0;
      } else if (isExpo) {
        final r = _expoR(n);
        if ((r - 1.0).abs() < 1e-9) {
          progress = n > 0 ? i / n : 1.0;
        } else {
          progress = (math.pow(r, i) - 1.0) / (math.pow(r, n) - 1.0);
        }
      } else {
        // pontual
        progress = i == n ? 1.0 : 0.0;
      }
      out.add(Milestone(ordem: i, dataRef: datas[i - 1], esperado: _round(baseline + delta * progress, isInt)));
    }
    return out;
  }

  static String _stripAccents(String s) {
    const map = {
      'á': 'a', 'à': 'a', 'â': 'a', 'ã': 'a', 'ä': 'a',
      'é': 'e', 'è': 'e', 'ê': 'e', 'ë': 'e',
      'í': 'i', 'ì': 'i', 'î': 'i', 'ï': 'i',
      'ó': 'o', 'ò': 'o', 'ô': 'o', 'õ': 'o', 'ö': 'o',
      'ú': 'u', 'ù': 'u', 'û': 'u', 'ü': 'u',
      'ç': 'c', 'ñ': 'n',
    };
    final sb = StringBuffer();
    for (final ch in s.split('')) {
      sb.write(map[ch] ?? ch);
    }
    return sb.toString();
  }
}
