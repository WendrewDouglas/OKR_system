import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:okr_app/features/shared/widgets/error_retry.dart';

// NOTA: o antigo teste-template "App renders login screen" foi removido porque
// pumpar OkrApp() no boot aciona secure storage / Firebase (canais de plataforma)
// sem mocks, falhando sempre. Um teste de boot completo pertence a um harness de
// integração (a fazer). Aqui ficam widget tests isolados e determinísticos.

void main() {
  testWidgets('ErrorRetry renderiza mensagem e dispara onRetry', (tester) async {
    var calls = 0;
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: ErrorRetry(message: 'Erro X', onRetry: () => calls++),
      ),
    ));

    expect(find.text('Erro X'), findsOneWidget);
    expect(find.text('Tentar novamente'), findsOneWidget);

    await tester.tap(find.text('Tentar novamente'));
    await tester.pump();

    expect(calls, 1);
  });
}
