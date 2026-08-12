<?php
declare(strict_types=1);

// =============================================================
// PONTE — vive em ~/public_html/briefing_kauana/index.php
//
// Fica FORA do repo (o docroot do WordPress), e só delega para o
// módulo versionado em OKR_system/LP/briefing-crm/. Assim a URL fica
// limpa (planningbi.com.br/briefing_kauana) sem que o código perca
// versionamento nem o deploy automático via git push.
//
// Não editar este arquivo: todo o conteúdo está no repo.
// =============================================================

$target = __DIR__ . '/../OKR_system/LP/briefing-crm/public/index.php';

if (!is_file($target)) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Indisponível</title>'
       . '<p style="font:16px/1.6 system-ui,sans-serif;padding:2rem">'
       . 'Esta página está temporariamente indisponível. Tente novamente em alguns minutos.</p>';
    exit;
}

require $target;
