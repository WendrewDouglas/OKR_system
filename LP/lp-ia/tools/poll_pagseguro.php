<?php
declare(strict_types=1);

// =============================================================
// Poller PagSeguro (clássico) — detecta vendas pagas e dispara os e-mails
// (confirmação ao comprador + alerta interno), sem depender de webhook/allowlist.
//
// Fluxo:
//   1) busca transações recentes (/v3/transactions, janela <= 30 dias);
//   2) para cada PAGA (status 3) ou DISPONÍVEL (status 4) ainda não notificada,
//      consulta os detalhes (/v3/transactions/{code}) e processa via lp_process_payment.
//
// Idempotente por charge_id (código da transação). Seguro para rodar em cron.
//
// Uso (na raiz do OKR_system):
//   php LP/lp-ia/tools/poll_pagseguro.php [--days=3] [--verbose]
// =============================================================

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/payments.php';

$opts    = getopt('', ['days::', 'verbose']);
$days    = isset($opts['days']) ? max(1, min(30, (int) $opts['days'])) : 3;
$verbose = array_key_exists('verbose', $opts);

$email = getenv('LP_PAGSEGURO_EMAIL');
$token = getenv('LP_PAGSEGURO_TOKEN');
if (!is_string($email) || $email === '' || !is_string($token) || $token === '') {
    fwrite(STDERR, "ERRO: LP_PAGSEGURO_EMAIL/LP_PAGSEGURO_TOKEN ausentes no .env.\n");
    exit(1);
}

$ws = rtrim((string) (getenv('LP_PAGSEGURO_WS') ?: 'https://ws.pagseguro.uol.com.br'), '/');

try {
    $landingId = lp_landing_id(LP_IA_SLUG);
} catch (\Throwable $e) {
    fwrite(STDERR, "ERRO: landing indisponível.\n");
    exit(1);
}

function lp_ws_get(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => ['Accept: application/xml'],
    ]);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($r === false || $code >= 400) {
        error_log('[LP_IA][poller] HTTP ' . $code . ' em ' . preg_replace('/token=[^&]+/', 'token=***', $url));
        return null;
    }
    return (string) $r;
}

$pdo = lp_db();
// buffer de 5 min na data final para evitar erro por diferença de relógio
$final   = date('Y-m-d\TH:i', time() - 300);
$initial = date('Y-m-d\TH:i', time() - $days * 86400);

$paidFound = 0; $processed = 0; $notified = 0; $page = 1; $totalPages = 1;

do {
    $url = $ws . '/v3/transactions'
         . '?email=' . urlencode($email) . '&token=' . urlencode($token)
         . '&initialDate=' . urlencode($initial) . '&finalDate=' . urlencode($final)
         . '&page=' . $page . '&maxPageResults=100';

    $xmlStr = lp_ws_get($url);
    if ($xmlStr === null) { fwrite(STDERR, "Falha na busca (página {$page}).\n"); break; }

    $prev = libxml_use_internal_errors(true);
    $x = simplexml_load_string($xmlStr);
    libxml_use_internal_errors($prev);
    if ($x === false) { fwrite(STDERR, "XML inválido na busca.\n"); break; }

    $totalPages = max(1, (int) $x->totalPages);

    foreach ($x->transactions->transaction ?? [] as $t) {
        $status = (int) $t->status;
        if (!in_array($status, [3, 4], true)) { continue; } // 3=Paga, 4=Disponível
        $paidFound++;
        $code = (string) $t->code;
        if ($code === '') { continue; }

        // já notificada? evita consulta desnecessária
        $st = $pdo->prepare('SELECT buyer_notified_at, admin_notified_at FROM lp_payments WHERE charge_id = ? LIMIT 1');
        $st->execute([$code]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && (!empty($row['buyer_notified_at']) || !empty($row['admin_notified_at']))) {
            if ($verbose) { echo "skip (já notificada): {$code}\n"; }
            continue;
        }

        // consulta detalhes para obter comprador
        $detailStr = lp_ws_get($ws . '/v3/transactions/' . rawurlencode($code)
            . '?email=' . urlencode($email) . '&token=' . urlencode($token));
        if ($detailStr === null) { continue; }
        $prev = libxml_use_internal_errors(true);
        $d = simplexml_load_string($detailStr);
        libxml_use_internal_errors($prev);
        if ($d === false) { continue; }

        $res = lp_process_payment($landingId, [
            'provider'     => 'pagseguro',
            'order_id'     => (string) $d->code,
            'charge_id'    => $code,
            'reference_id' => (string) ($d->reference ?? ''),
            'status'       => 'PAID',
            'amount_cents' => (int) round(((float) ($d->grossAmount ?? 0)) * 100),
            'name'         => (string) ($d->sender->name ?? ''),
            'email'        => (string) ($d->sender->email ?? ''),
            'tax'          => null,
            'raw'          => $detailStr,
        ]);
        $processed++;
        if (!empty($res['notified'])) { $notified++; }
        if ($verbose) { echo "processada {$code} -> pagamento {$res['payment_id']}" . (!empty($res['notified']) ? ' (e-mails enviados)' : '') . "\n"; }
    }
    $page++;
} while ($page <= $totalPages);

echo "POLL_OK window={$initial}..{$final} paid_found={$paidFound} processed={$processed} emails={$notified}\n";
