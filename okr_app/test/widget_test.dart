import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:okr_app/app.dart';

void main() {
  testWidgets('App renders login screen', (WidgetTester tester) async {
    await tester.pumpWidget(const ProviderScope(child: OkrApp()));
    await tester.pump();
    // App should render without errors
    expect(find.text('OKR System'), findsAny);
  });
}
