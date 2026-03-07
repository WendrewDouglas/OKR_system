<?php
/**
 * chat_system_docs.php
 * Static documentation about the OKR system for the AI chat context.
 * Included in the system prompt so the assistant can answer "how-to" questions.
 */
declare(strict_types=1);

/**
 * Return a formatted string with system usage documentation (~2200 chars).
 */
function chat_get_system_docs(): string {
    return <<<'DOCS'
## Como usar o sistema OKR

### Navegação principal (menu lateral)
- **Dashboard** — visão geral com indicadores de progresso
- **Mapa Estratégico** — BSC com 4 pilares (Financeiro, Clientes, Processos Internos, Aprendizado & Crescimento), mostra objetivos posicionados por pilar
- **Meus OKRs** — lista de objetivos e KRs do usuário; submenus: Objetivos, Key Results
- **Orçamento** — iniciativas vinculadas a KRs com valores orçados, consolidado por pilar
- **Relatório One-Page** — resumo executivo dos OKRs com filtros de período
- **Aprovações** — fila de solicitações pendentes (criação, edição, movimentação)
- **Configurações** — submenus: Estilo (logo, cores), Organização (missão, visão, pilares), Usuários (convites, perfis)

### Ações rápidas (header)
- Botão **"+ Objetivo"** — abre formulário de novo objetivo (requer permissão)
- Botão **"+ KR"** — abre formulário de novo Key Result (requer permissão)
- Sino de notificações — alertas de aprovação, menções, prazos
- Menu de perfil — configurações pessoais e logout

### Fluxos passo a passo

**Criar Objetivo:**
1. Acesse Meus OKRs > Objetivos ou clique "+ Objetivo" no header
2. Preencha: descrição, pilar BSC, ciclo, prazo, responsável
3. Envie para aprovação
4. O aprovador recebe notificação em Aprovações
5. Após aprovação, o objetivo fica ativo no sistema
6. Objetivos aparecem no Mapa Estratégico e no Dashboard

**Criar Key Result:**
1. Acesse Meus OKRs > Key Results ou clique "+ KR" no header
2. Etapa 1: selecione o objetivo-pai, descreva o KR
3. Etapa 2: defina baseline, meta, unidade de medida
4. Etapa 3: defina milestones (pontos de verificação com datas)
5. Envie para aprovação; após aprovado, o KR fica vinculado ao objetivo

**Atualizar progresso (Apontamentos):**
1. Acesse Meus OKRs > clique no objetivo desejado (Detalhe OKR)
2. Localize o KR que quer atualizar
3. Clique no milestone do período atual
4. Informe o valor real atingido e salve
5. O progresso (%) é recalculado automaticamente

**Editar Objetivo ou KR:**
1. Acesse o detalhe do objetivo ou KR
2. Clique em Editar; faça as alterações
3. Alterações significativas geram uma movimentação que vai para aprovação
4. Após aprovação, as mudanças são aplicadas

**Orçamento:**
1. Dentro de um KR, cadastre Iniciativas com valor orçado
2. Cada iniciativa pode ser aprovada individualmente
3. O consolidado por pilar aparece na tela de Orçamento

### Permissões
- **admin_master** — acesso total: criar, editar, aprovar, configurar
- **user_colab** — pode criar e editar próprios OKRs; não aprova nem configura
- Se vir "Acesso negado", peça ao administrador para ajustar suas permissões

### Este chat (OKR Master)
- Fica no canto superior direito (ícone de chat)
- Pode responder sobre seus OKRs, progresso, aprovações, orçamento e como usar o sistema
- Usa dados reais da empresa para respostas contextualizadas
DOCS;
}
