<?php
// views/politica_privacidade.php — Política de Privacidade PÚBLICA do app OKR System.
// URL para usar nas fichas da Play Store e App Store e nos formulários de privacidade.
// >>> REVISE os campos marcados com [REVISAR]: razão social, CNPJ, e-mail e data. <<<
declare(strict_types=1);
$empresa   = 'PlanningBI'; // [REVISAR] razão social completa
$cnpj      = '[REVISAR: CNPJ]';
$contato   = 'privacidade@planningbi.com.br'; // [REVISAR] e-mail do encarregado/DPO
$vigencia  = '30 de julho de 2026'; // [REVISAR] data de vigência
$appNome   = 'OKR System';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Política de Privacidade — <?= htmlspecialchars($appNome) ?></title>
  <style>
    body{font-family:system-ui,Arial,sans-serif;background:#0d1117;color:#e6e9f2;margin:0;padding:24px;line-height:1.6}
    .card{max-width:760px;margin:0 auto;background:#161b22;border:1px solid #30363d;border-radius:14px;padding:28px}
    h1{font-size:1.5rem;margin:0 0 4px}
    h2{font-size:1.1rem;margin:24px 0 8px;color:#f6c343}
    a{color:#f6c343}
    ul{padding-left:20px} li{margin:4px 0}
    table{width:100%;border-collapse:collapse;margin:8px 0}
    th,td{border:1px solid #30363d;padding:8px;text-align:left;font-size:.92rem;vertical-align:top}
    th{background:#0d1117}
    .muted{color:#9aa4b2;font-size:.9rem}
  </style>
</head>
<body>
  <div class="card">
    <h1>Política de Privacidade — <?= htmlspecialchars($appNome) ?></h1>
    <p class="muted">Vigência: <?= htmlspecialchars($vigencia) ?> · Controlador: <?= htmlspecialchars($empresa) ?> (CNPJ <?= htmlspecialchars($cnpj) ?>)</p>

    <p>Esta Política descreve como o aplicativo <b><?= htmlspecialchars($appNome) ?></b> ("app") coleta,
    usa, compartilha e protege dados pessoais, em conformidade com a Lei Geral de Proteção de Dados
    (LGPD — Lei 13.709/2018) e com as políticas da Google Play e da Apple App Store.</p>

    <h2>1. Dados que coletamos</h2>
    <table>
      <tr><th>Dado</th><th>Finalidade</th></tr>
      <tr><td>Nome, e-mail corporativo e telefone</td><td>Criar e autenticar a conta; identificar você no sistema.</td></tr>
      <tr><td>Foto de perfil (avatar), quando você envia</td><td>Personalizar o perfil.</td></tr>
      <tr><td>Token de notificação (Firebase Cloud Messaging) e identificador do dispositivo</td><td>Enviar notificações push.</td></tr>
      <tr><td>Conteúdo que você cria (objetivos, KRs, iniciativas, orçamentos, apontamentos)</td><td>Prestar o serviço de gestão de OKRs.</td></tr>
      <tr><td>Dados técnicos (registro de acesso, IP)</td><td>Segurança, prevenção a fraudes e diagnóstico.</td></tr>
    </table>
    <p class="muted">Não coletamos dados de localização precisa, contatos, nem categorias sensíveis. Não usamos os dados para rastreamento entre apps/sites de terceiros (no tracking).</p>

    <h2>2. Como usamos os dados</h2>
    <ul>
      <li>Fornecer, manter e melhorar o app e suas funcionalidades.</li>
      <li>Autenticar o acesso e proteger a conta.</li>
      <li>Enviar notificações relacionadas ao uso (aprovações, lembretes, avisos).</li>
      <li>Cumprir obrigações legais e responder a solicitações.</li>
    </ul>
    <p><b>Não vendemos</b> seus dados pessoais.</p>

    <h2>3. Compartilhamento</h2>
    <ul>
      <li><b>Google/Firebase</b> — processa o envio de notificações push (Firebase Cloud Messaging).</li>
      <li><b>Provedor de e-mail (SMTP)</b> — envio de e-mails transacionais (ex.: recuperação de senha).</li>
      <li><b>Infraestrutura de hospedagem</b> — armazenamento dos dados do serviço.</li>
      <li>Autoridades, quando exigido por lei.</li>
    </ul>

    <h2>4. Armazenamento e segurança</h2>
    <ul>
      <li>Tráfego criptografado (HTTPS/TLS).</li>
      <li>Senhas armazenadas com hash forte; tokens guardados em armazenamento seguro do dispositivo.</li>
      <li>Controle de acesso por papéis e isolamento por empresa (multi-tenant).</li>
    </ul>

    <h2>5. Retenção</h2>
    <p>Mantemos os dados enquanto sua conta estiver ativa. Após a exclusão, os dados pessoais são
    removidos, ressalvados registros que a lei exija reter por prazo determinado.</p>

    <h2>6. Seus direitos (LGPD)</h2>
    <p>Você pode solicitar acesso, correção, portabilidade, anonimização e <b>exclusão</b> dos seus dados,
    além de revogar consentimentos. Para exercer:</p>
    <ul>
      <li><b>Exclusão da conta:</b> no app, em <b>Meu Perfil &rarr; Excluir conta</b>, ou pela página
        <a href="/OKR_system/views/excluir_conta.php">Excluir minha conta</a>.</li>
      <li><b>Demais solicitações:</b> escreva para <a href="mailto:<?= htmlspecialchars($contato) ?>"><?= htmlspecialchars($contato) ?></a>.</li>
    </ul>

    <h2>7. Crianças</h2>
    <p>O app é destinado ao uso corporativo por adultos e não é direcionado a menores de idade.</p>

    <h2>8. Alterações</h2>
    <p>Podemos atualizar esta Política. A data de vigência acima indica a versão atual; mudanças
    relevantes serão comunicadas pelos canais do app.</p>

    <h2>9. Contato</h2>
    <p>Encarregado de Dados (DPO) / Privacidade: <a href="mailto:<?= htmlspecialchars($contato) ?>"><?= htmlspecialchars($contato) ?></a> — <?= htmlspecialchars($empresa) ?>.</p>
  </div>
</body>
</html>
