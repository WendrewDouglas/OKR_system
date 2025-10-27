<?php
require __DIR__ . '/_bootstrap.php';
$in = json_input();

$token = (string)($in['session_token'] ?? '');
$phone = (string)($in['telefone_e164'] ?? '');
$optin = !empty($in['whatsapp_optin']);
if (!$token || !$phone || !$optin) fail('Parâmetros inválidos');

$pdo = pdo();
// pega lead/sessão
$S = $pdo->prepare("SELECT s.id_sessao, s.id_lead, l.nome FROM lp001_quiz_sessoes s JOIN lp001_quiz_leads l ON l.id_lead=s.id_lead WHERE s.session_token=? LIMIT 1");
$S->execute([$token]);
$ses = $S->fetch(); if (!$ses) fail('Sessão não encontrada',404);

// pega link do PDF
$Q = $pdo->prepare("SELECT pdf_path FROM lp001_quiz_scores WHERE id_sessao=? LIMIT 1");
$Q->execute([(int)$ses['id_sessao']]);
$pdf = $Q->fetchColumn();
if (!$pdf) fail('PDF ainda não gerado', 400);

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
$link = $baseUrl . $pdf;

$status = 'queued'; $provider_msg_id = null; $last_error = null;

// Tenta enviar via Meta Cloud API se configurado
if (defined('WHATSAPP_TOKEN') && WHATSAPP_TOKEN && defined('WHATSAPP_PHONE_ID') && WHATSAPP_PHONE_ID) {
  $payload = [
    'messaging_product' => 'whatsapp',
    'to'   => $phone,
    'type' => 'text',
    'text' => ['preview_url'=>true, 'body'=>"Seu relatório de diagnóstico está pronto.\nAcesse: {$link}"]
  ];
  $url = "https://graph.facebook.com/v20.0/" . WHATSAPP_PHONE_ID . "/messages";
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER      => ['Authorization: Bearer '.WHATSAPP_TOKEN, 'Content-Type: application/json'],
    CURLOPT_POST            => true,
    CURLOPT_POSTFIELDS      => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER  => true,
    CURLOPT_TIMEOUT         => 15
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  curl_close($ch);
  if ($resp === false) { $status='failed'; $last_error=$err; }
  else {
    $j = json_decode($resp, true);
    if (!empty($j['messages'][0]['id'])) { $status='sent'; $provider_msg_id=$j['messages'][0]['id']; }
    else { $status = 'failed'; $last_error = $resp; }
  }
}

// salva lead com telefone/optin
$upd = $pdo->prepare("UPDATE lp001_quiz_leads SET telefone_whatsapp_e164=?, whatsapp_optin=1, whatsapp_optin_dt=NOW() WHERE id_lead=?");
$upd->execute([$phone, (int)$ses['id_lead']]);

// log
$log = $pdo->prepare("INSERT INTO lp001_quiz_whatsapp_log (id_lead,id_sessao,provider,template_name,status_envio,provider_msg_id,last_error,status_dt)
                      VALUES (?,?, 'meta_cloud_api', NULL, ?, ?, ?, NOW())");
$log->execute([(int)$ses['id_lead'], (int)$ses['id_sessao'], $status, $provider_msg_id, $last_error]);

ok(['status'=>$status, 'provider_msg_id'=>$provider_msg_id]);
