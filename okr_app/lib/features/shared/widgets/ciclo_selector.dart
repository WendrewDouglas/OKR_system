import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/providers/domain_providers.dart';
import '../../../core/utils/milestone_calc.dart';

/// Seletor de ciclo (tipo + sub-parâmetros) com paridade com o web.
/// Reporta via [onChanged]: o tipo, os params no formato esperado pela API
/// (ciclo_tipo, ciclo_anual_ano, ciclo_semestral 'S1/2026'...) e o período
/// (início/fim) já calculado por [MilestoneCalc.datasDoCiclo].
class CicloSelector extends ConsumerStatefulWidget {
  final void Function(String? tipo, Map<String, dynamic> params, DateTime? inicio, DateTime? fim) onChanged;
  const CicloSelector({super.key, required this.onChanged});

  @override
  ConsumerState<CicloSelector> createState() => _CicloSelectorState();
}

class _CicloSelectorState extends ConsumerState<CicloSelector> {
  String? _tipo;
  String? _anualAno;
  String? _semestral;
  String? _trimestral;
  String? _bimestral;
  int? _mensalMes;
  String? _mensalAno;
  String? _persInicio;
  String? _persFim;
  final _persInicioCtrl = TextEditingController();
  final _persFimCtrl = TextEditingController();

  static const List<String> _meses = [
    'Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez',
  ];
  List<int> get _anos {
    final y = DateTime.now().year;
    return [for (int a = y - 1; a <= y + 3; a++) a];
  }

  @override
  void dispose() {
    _persInicioCtrl.dispose();
    _persFimCtrl.dispose();
    super.dispose();
  }

  String _domVal(Map<String, dynamic> m, List<String> keys) {
    for (final k in keys) {
      final v = m[k];
      if (v != null && v.toString().isNotEmpty) return v.toString();
    }
    return '';
  }

  Map<String, dynamic> _subParams() {
    switch (_tipo) {
      case 'anual':
        return {if (_anualAno != null) 'ciclo_anual_ano': _anualAno};
      case 'semestral':
        return {if (_semestral != null) 'ciclo_semestral': _semestral};
      case 'trimestral':
        return {if (_trimestral != null) 'ciclo_trimestral': _trimestral};
      case 'bimestral':
        return {if (_bimestral != null) 'ciclo_bimestral': _bimestral};
      case 'mensal':
        return {
          if (_mensalMes != null) 'ciclo_mensal_mes': _mensalMes,
          if (_mensalAno != null) 'ciclo_mensal_ano': _mensalAno,
        };
      case 'personalizado':
        return {
          if (_persInicio != null) 'ciclo_pers_inicio': _persInicio,
          if (_persFim != null) 'ciclo_pers_fim': _persFim,
        };
      default:
        return {};
    }
  }

  void _emit() {
    final tipo = _tipo;
    final sub = _subParams();
    DateTime? ini, fim;
    if (tipo != null) {
      final r = MilestoneCalc.datasDoCiclo(tipo, sub);
      if (r != null) {
        ini = r.inicio;
        fim = r.fim;
      }
    }
    widget.onChanged(tipo, {if (tipo != null) 'ciclo_tipo': tipo, ...sub}, ini, fim);
  }

  void _resetSub() {
    _anualAno = _semestral = _trimestral = _bimestral = _mensalAno = _persInicio = _persFim = null;
    _mensalMes = null;
    _persInicioCtrl.clear();
    _persFimCtrl.clear();
  }

  Future<void> _pickMonth(TextEditingController ctrl, ValueChanged<String> onPicked) async {
    final now = DateTime.now();
    final dt = await showDatePicker(
      context: context,
      initialDate: now,
      firstDate: DateTime(now.year - 1),
      lastDate: DateTime(now.year + 3, 12),
      initialDatePickerMode: DatePickerMode.year,
    );
    if (dt != null) {
      onPicked('${dt.year}-${dt.month.toString().padLeft(2, '0')}');
      ctrl.text = '${dt.month.toString().padLeft(2, '0')}/${dt.year}';
    }
  }

  Widget _buildSub() {
    switch (_tipo) {
      case 'anual':
        return DropdownButtonFormField<String>(
          initialValue: _anualAno,
          decoration: const InputDecoration(labelText: 'Ano *'),
          items: _anos.map((a) => DropdownMenuItem(value: '$a', child: Text('$a'))).toList(),
          onChanged: (v) => setState(() {
            _anualAno = v;
            _emit();
          }),
        );
      case 'semestral':
        final opts = [for (final a in _anos) for (final s in [1, 2]) 'S$s/$a'];
        return DropdownButtonFormField<String>(
          initialValue: _semestral,
          isExpanded: true,
          decoration: const InputDecoration(labelText: 'Semestre *'),
          items: opts.map((o) => DropdownMenuItem(value: o, child: Text(o))).toList(),
          onChanged: (v) => setState(() {
            _semestral = v;
            _emit();
          }),
        );
      case 'trimestral':
        final opts = [for (final a in _anos) for (final q in [1, 2, 3, 4]) 'Q$q/$a'];
        return DropdownButtonFormField<String>(
          initialValue: _trimestral,
          isExpanded: true,
          decoration: const InputDecoration(labelText: 'Trimestre *'),
          items: opts.map((o) => DropdownMenuItem(value: o, child: Text(o))).toList(),
          onChanged: (v) => setState(() {
            _trimestral = v;
            _emit();
          }),
        );
      case 'bimestral':
        const pairs = [['01', '02'], ['03', '04'], ['05', '06'], ['07', '08'], ['09', '10'], ['11', '12']];
        final opts = <MapEntry<String, String>>[];
        for (final a in _anos) {
          for (final p in pairs) {
            opts.add(MapEntry('${p[0]}-${p[1]}-$a',
                '${_meses[int.parse(p[0]) - 1]}–${_meses[int.parse(p[1]) - 1]}/$a'));
          }
        }
        return DropdownButtonFormField<String>(
          initialValue: _bimestral,
          isExpanded: true,
          decoration: const InputDecoration(labelText: 'Bimestre *'),
          items: opts.map((o) => DropdownMenuItem(value: o.key, child: Text(o.value))).toList(),
          onChanged: (v) => setState(() {
            _bimestral = v;
            _emit();
          }),
        );
      case 'mensal':
        return Row(children: [
          Expanded(
            child: DropdownButtonFormField<int>(
              initialValue: _mensalMes,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'Mês *'),
              items: [for (int m = 1; m <= 12; m++) DropdownMenuItem(value: m, child: Text(_meses[m - 1]))],
              onChanged: (v) => setState(() {
                _mensalMes = v;
                _emit();
              }),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: DropdownButtonFormField<String>(
              initialValue: _mensalAno,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'Ano *'),
              items: _anos.map((a) => DropdownMenuItem(value: '$a', child: Text('$a'))).toList(),
              onChanged: (v) => setState(() {
                _mensalAno = v;
                _emit();
              }),
            ),
          ),
        ]);
      case 'personalizado':
        return Row(children: [
          Expanded(
            child: TextFormField(
              decoration: const InputDecoration(labelText: 'Mês início'),
              readOnly: true,
              controller: _persInicioCtrl,
              onTap: () => _pickMonth(_persInicioCtrl, (ym) => setState(() {
                    _persInicio = ym;
                    _emit();
                  })),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: TextFormField(
              decoration: const InputDecoration(labelText: 'Mês fim'),
              readOnly: true,
              controller: _persFimCtrl,
              onTap: () => _pickMonth(_persFimCtrl, (ym) => setState(() {
                    _persFim = ym;
                    _emit();
                  })),
            ),
          ),
        ]);
      default:
        return const SizedBox.shrink();
    }
  }

  @override
  Widget build(BuildContext context) {
    final ciclos = ref.watch(domainProvider('dom_ciclos'));
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        ciclos.when(
          loading: () => const LinearProgressIndicator(),
          error: (_, __) => const Text('Erro ao carregar ciclos'),
          data: (items) => DropdownButtonFormField<String>(
            initialValue: _tipo,
            decoration: const InputDecoration(labelText: 'Ciclo *'),
            items: items.map((c) {
              final value = _domVal(c, ['nome_ciclo', 'ciclo_tipo', 'id_ciclo']);
              final label = _domVal(c, ['descricao', 'nome_ciclo']);
              return DropdownMenuItem(value: value, child: Text(label));
            }).toList(),
            onChanged: (v) => setState(() {
              _tipo = v;
              _resetSub();
              _emit();
            }),
            validator: (v) => v == null ? 'Obrigatório' : null,
          ),
        ),
        if (_tipo != null) ...[
          const SizedBox(height: 16),
          _buildSub(),
        ],
      ],
    );
  }
}
