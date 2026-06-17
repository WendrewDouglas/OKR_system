import 'package:flutter_test/flutter_test.dart';
import 'package:okr_app/core/utils/milestone_calc.dart';

String iso(DateTime d) =>
    '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

void main() {
  group('normNat', () {
    test('mapeia aliases para slugs canônicos', () {
      expect(MilestoneCalc.normNat('Constante'), 'acumulativo_constante');
      expect(MilestoneCalc.normNat('Acumulativo (Constante)'), 'acumulativo_constante');
      expect(MilestoneCalc.normNat('exponencial'), 'acumulativo_exponencial');
      expect(MilestoneCalc.normNat('Acumulativo Exponencial'), 'acumulativo_exponencial');
      expect(MilestoneCalc.normNat('Binário'), 'binario');
      expect(MilestoneCalc.normNat('flutuante'), 'pontual');
    });
  });

  group('gerarSerieDatas', () {
    test('mensal no trimestre Q1/2026 → fim de cada mês', () {
      final datas = MilestoneCalc.gerarSerieDatas(
        DateTime(2026, 1, 1), DateTime(2026, 3, 31), 'mensal');
      expect(datas.map(iso).toList(), ['2026-01-31', '2026-02-28', '2026-03-31']);
    });

    test('trimestral no ano/2026 → fim de cada trimestre', () {
      final datas = MilestoneCalc.gerarSerieDatas(
        DateTime(2026, 1, 1), DateTime(2026, 12, 31), 'trimestral');
      expect(datas.map(iso).toList(),
          ['2026-03-31', '2026-06-30', '2026-09-30', '2026-12-31']);
    });

    test('semanal inclui sempre a data final', () {
      final datas = MilestoneCalc.gerarSerieDatas(
        DateTime(2026, 1, 1), DateTime(2026, 1, 31), 'semanal');
      // 08,15,22,29 (< 31) + 31
      expect(datas.map(iso).toList(),
          ['2026-01-08', '2026-01-15', '2026-01-22', '2026-01-29', '2026-01-31']);
    });
  });

  group('valores esperados por natureza (N=3, baseline 0, meta 9)', () {
    List<Milestone> gen(String natureza, {String? direcao, String unidade = ''}) =>
        MilestoneCalc.gerar(
          inicio: DateTime(2026, 1, 1),
          fim: DateTime(2026, 3, 31),
          frequencia: 'mensal',
          baseline: 0,
          meta: 9,
          natureza: natureza,
          direcao: direcao,
          unidade: unidade,
        );

    test('constante = linear (3,6,9)', () {
      expect(gen('acumulativo_constante').map((m) => m.esperado).toList(), [3.0, 6.0, 9.0]);
    });

    test('binário = degrau só no fim (0,0,9)', () {
      expect(gen('binario').map((m) => m.esperado).toList(), [0.0, 0.0, 9.0]);
    });

    test('pontual = degrau só no fim (0,0,9)', () {
      expect(gen('pontual').map((m) => m.esperado).toList(), [0.0, 0.0, 9.0]);
    });

    test('exponencial monotônico acelerando (r=1.8)', () {
      final vals = gen('acumulativo_exponencial').map((m) => m.esperado!).toList();
      // (1.8^i-1)/(1.8^3-1)*9 → ~1.49, ~4.17, 9.0
      expect(vals[0], closeTo(1.49, 0.01));
      expect(vals[1], closeTo(4.17, 0.01));
      expect(vals[2], 9.0);
      // monotônico crescente
      expect(vals[0] < vals[1] && vals[1] < vals[2], isTrue);
    });

    test('INTERVALO_IDEAL → faixa min/max constante', () {
      final ms = MilestoneCalc.gerar(
        inicio: DateTime(2026, 1, 1), fim: DateTime(2026, 3, 31),
        frequencia: 'mensal', baseline: 20, meta: 10,
        natureza: 'pontual', direcao: 'INTERVALO_IDEAL', unidade: '');
      for (final m in ms) {
        expect(m.esperadoMin, 10.0);
        expect(m.esperadoMax, 20.0);
        expect(m.isIntervalo, isTrue);
      }
    });
  });

  group('arredondamento', () {
    test('unidade de contagem → inteiro', () {
      final ms = MilestoneCalc.gerar(
        inicio: DateTime(2026, 1, 1), fim: DateTime(2026, 3, 31),
        frequencia: 'mensal', baseline: 0, meta: 10,
        natureza: 'acumulativo_constante', unidade: 'contratos');
      // 10/3=3.33→3, 6.67→7, 10
      expect(ms.map((m) => m.esperado).toList(), [3.0, 7.0, 10.0]);
    });

    test('unidade decimal → 2 casas', () {
      final ms = MilestoneCalc.gerar(
        inicio: DateTime(2026, 1, 1), fim: DateTime(2026, 3, 31),
        frequencia: 'mensal', baseline: 0, meta: 10,
        natureza: 'acumulativo_constante', unidade: '%');
      expect(ms[0].esperado, closeTo(3.33, 0.001));
      expect(ms[1].esperado, closeTo(6.67, 0.001));
      expect(ms[2].esperado, 10.0);
    });
  });
}
