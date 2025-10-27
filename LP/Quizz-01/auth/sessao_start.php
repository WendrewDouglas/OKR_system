<?php
require __DIR__ . '/_bootstrap.php';
$in = json_input();

$id_versao = (int)($in['id_versao'] ?? 0);
$id_lead   = (int)($in['id_lead']   ?? 0);
if (!$id_versao || !$id_lead) fail('Parâmetros inválidos');

function uuidv4(): string {
  $d = random_bytes(16);
  $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
  $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
  $hex = bin2hex($d);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex,4));
}

$token = uuidv4();
$pdo = pdo();
$st = $pdo->prepare("INSERT INTO lp001_quiz_sessoes
  (id_versao,id_lead,session_token,ip,user_agent,status,dt_inicio)
  VALUES (?,?,?,?,?,'started',NOW())");
$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
$st->execute([$id_versao, $id_lead, $token, ip_bin(), $ua]);

ok(['session_token'=>$token]);
