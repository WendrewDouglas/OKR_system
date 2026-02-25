<?php
// /OKR_system/auth/avatars_list.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$dir = realpath(__DIR__ . '/../assets/img/avatars/default_avatar');
$webBase = '/OKR_system/assets/img/avatars/default_avatar/';

if (!$dir || !is_dir($dir)) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Avatar directory not found']); exit;
}

$files = glob($dir . '/*.{png,PNG}', GLOB_BRACE) ?: [];
$avatars = [];

foreach ($files as $path) {
  $base = basename($path);
  // Aceita: default.png, userNN.png, femNN.png
  if (!preg_match('/^(default\.png|user\d+\.png|fem\d+\.png)$/i', $base)) continue;

  $gender = 'all';
  if (preg_match('/^user\d+\.png$/i', $base)) $gender = 'masculino';
  if (preg_match('/^fem\d+\.png$/i',  $base)) $gender = 'feminino';

  $avatars[] = ['file'=>$base, 'gender'=>$gender];
}

// embaralha a cada requisição
shuffle($avatars);

// Garante que default.png venha selecionado por padrão (não precisa aparecer primeiro)
echo json_encode(['ok'=>true, 'base'=>$webBase, 'avatars'=>$avatars], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
