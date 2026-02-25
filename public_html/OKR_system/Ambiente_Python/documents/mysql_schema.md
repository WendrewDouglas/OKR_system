# Schema: planni40_okr

- MySQL: 5.7.23-23
- Gerado em: 2025-11-13 19:53:53

## Diagrama ER (Mermaid)

```mermaid
erDiagram
  apontamentos_kr {
    BIGINT id_apontamento
    VARCHAR id_kr
    INT id_milestone
    DATE dt_evidencia
    DATETIME dt_apontamento
    DECIMAL valor_real
    VARCHAR usuario_id
    VARCHAR url_evidencia
    TEXT observacao
    TEXT justificativa
    TEXT justificativa_edicao
    VARCHAR origem
    TINYINT pendente_exclusao
    TEXT justificativa_exclusao
    INT id_user_solicitante_exclusao
    DATETIME dt_solicitacao_exclusao
    VARCHAR id_company
  }
  apontamentos_kr_anexos {
    INT id_anexo
    BIGINT id_apontamento
    VARCHAR nome_anexo
    TEXT descricao_anexo
    VARCHAR tipo_arquivo
    TEXT caminho_arquivo
    BIGINT tamanho_bytes
    CHAR sha256_hash
    INT ordem
    VARCHAR status_scan
    DATETIME data_envio
    VARCHAR id_user_envio
    TINYINT is_deleted
    DATETIME deleted_at
    VARCHAR deleted_by
    TEXT justificativa_exclusao
    VARCHAR id_company
  }
  apontamentos_status_iniciativas {
    INT id_apontamento
    VARCHAR id_iniciativa
    VARCHAR status
    DATETIME data_hora
    VARCHAR id_user
    TEXT observacao
    VARCHAR origem_apontamento
    DATETIME dt_ultima_alteracao
    VARCHAR id_user_alteracao
  }
  aprovacao_movimentos {
    INT id_movimento
    ENUM tipo_estrutura
    VARCHAR id_referencia
    ENUM tipo_movimento
    TEXT campos_diff_json
    TEXT justificativa
    ENUM status
    INT id_user_criador
    INT id_user_aprovador
    DATETIME dt_decisao
    DATETIME dt_registro
  }
  aprovadores {
    INT id_aprovador
    VARCHAR id_user
    TINYINT tudo
    TINYINT habilitado
    DATETIME dt_cadastro
    DATETIME dt_ultima_atividade
  }
  avatars {
    INT id
    VARCHAR filename
    ENUM gender
    TINYINT active
    TIMESTAMP created_at
  }
  company {
    INT id_company
    VARCHAR organizacao
    VARCHAR cnpj
    VARCHAR razao_social
    VARCHAR natureza_juridica_code
    VARCHAR natureza_juridica_desc
    VARCHAR logradouro
    VARCHAR numero
    VARCHAR complemento
    VARCHAR cep
    VARCHAR bairro
    VARCHAR municipio
    CHAR uf
    VARCHAR email
    VARCHAR telefone
    TEXT missao
    TEXT visao
    VARCHAR situacao_cadastral
    DATE data_situacao_cadastral
    DATETIME created_at
    DATETIME updated_at
    INT created_by
    INT updated_by
  }
  company_style {
    INT id_style
    INT id_company
    CHAR bg1_hex
    CHAR bg2_hex
    LONGTEXT logo_base64
    INT okr_master_user_id
    DATETIME created_at
    DATETIME updated_at
    INT created_by
    INT updated_by
  }
  dom_cargos {
    INT id_cargo
    INT id_company
    INT id_departamento
    TINYINT id_nivel_cargo
    VARCHAR nome_exibicao
    TINYINT ativo
  }
  dom_ciclos {
    TINYINT id_ciclo
    VARCHAR nome_ciclo
    VARCHAR descricao
  }
  dom_departamentos {
    INT id_departamento
    INT id_company
    VARCHAR codigo
    VARCHAR nome
    TINYINT ativo
    SMALLINT display_order
  }
  dom_modulo_aprovacao {
    VARCHAR id_modulo
    VARCHAR descricao_exibicao
  }
  dom_natureza_kr {
    VARCHAR id_natureza
    VARCHAR descricao_exibicao
    TEXT descricao_detalhada
  }
  dom_niveis_cargo {
    TINYINT id_nivel
    VARCHAR codigo
    VARCHAR nome
    TINYINT ordem
    TINYINT is_gestao
  }
  dom_paginas {
    VARCHAR id_pagina
    VARCHAR path
    VARCHAR descricao
    TINYINT ativo
    VARCHAR requires_cap
  }
  dom_permissoes {
    INT id_dominio
    VARCHAR chave_dominio
    VARCHAR descricao
  }
  dom_pilar_bsc {
    VARCHAR id_pilar
    VARCHAR descricao_exibicao
    INT ordem_pilar
  }
  dom_qualidade_objetivo {
    VARCHAR id_qualidade
    VARCHAR descricao_exibicao
  }
  dom_status_aprovacao {
    VARCHAR id_status
    VARCHAR descricao_exibicao
  }
  dom_status_financeiro {
    VARCHAR id_status
    VARCHAR descricao_exibicao
  }
  dom_status_kr {
    VARCHAR id_status
    VARCHAR descricao_exibicao
  }
  dom_tipo_frequencia_milestone {
    VARCHAR id_frequencia
    VARCHAR descricao_exibicao
  }
  dom_tipo_kr {
    VARCHAR id_tipo
    VARCHAR descricao_exibicao
  }
  dom_tipo_objetivo {
    VARCHAR id_tipo
    VARCHAR descricao_exibicao
  }
  fluxo_aprovacoes {
    INT id_fluxo
    VARCHAR tipo_estrutura
    VARCHAR id_referencia
    VARCHAR id_entidade
    VARCHAR tipo_operacao
    TEXT motivo_solicitacao
    TEXT dados_originais
    TEXT dados_solicitados
    VARCHAR id_user_solicitante
    VARCHAR status
    VARCHAR id_user_aprovador
    TEXT justificativa
    VARCHAR contexto_origem
    DATETIME data_solicitacao
    DATETIME data_aprovacao
    VARCHAR ip
    VARCHAR user_agent
  }
  iniciativas {
    VARCHAR id_iniciativa
    VARCHAR id_kr
    INT num_iniciativa
    TEXT descricao
    VARCHAR status
    VARCHAR status_aprovacao
    VARCHAR id_user_criador
    DATE dt_criacao
    VARCHAR id_user_aprovador
    DATETIME dt_aprovacao
    VARCHAR id_user_ult_alteracao
    DATETIME dt_ultima_atualizacao
    TEXT observacoes
    DATE dt_prazo
    VARCHAR id_user_responsavel
  }
  iniciativas_envolvidos {
    VARCHAR id_iniciativa
    VARCHAR id_user
    DATETIME dt_inclusao
  }
  key_results {
    VARCHAR id_kr
    INT id_objetivo
    INT key_result_num
    TEXT descricao
    VARCHAR usuario_criador
    INT id_user_criador
    DATE dt_criacao
    VARCHAR tipo_kr
    VARCHAR natureza_kr
    VARCHAR status
    VARCHAR status_aprovacao
    VARCHAR tipo_frequencia_milestone
    VARCHAR qualidade
    DECIMAL baseline
    DECIMAL meta
    VARCHAR unidade_medida
    VARCHAR direcao_metrica
    VARCHAR farol
    DECIMAL margem_confianca
    DATE data_inicio
    DATE data_fim
    VARCHAR responsavel
    DATE dt_novo_prazo
    DATE dt_conclusao
    VARCHAR aprovador
    INT id_user_aprovador
    DATETIME dt_aprovacao
    TEXT comentarios_aprovacao
    DECIMAL peso
    DATETIME dt_ultima_atualizacao
    VARCHAR usuario_ult_alteracao
    TEXT observacoes
  }
  lp001_dom_cargos {
    SMALLINT id_cargo
    VARCHAR nome
    TINYINT ordem_hierarquia
    TIMESTAMP dt_cadastro
  }
  lp001_dom_faixa_colaboradores {
    SMALLINT id_faixa_colab
    VARCHAR descricao
    SMALLINT ordem
    TIMESTAMP dt_cadastro
  }
  lp001_dom_faixa_faturamento {
    SMALLINT id_faixa_fat
    VARCHAR descricao
    SMALLINT ordem
    TIMESTAMP dt_cadastro
  }
  lp001_quiz {
    BIGINT id_quiz
    VARCHAR nome
    VARCHAR slug
    BIGINT versao_ativa
    ENUM status
    TIMESTAMP dt_criacao
    TIMESTAMP dt_ultima_atualizacao
  }
  lp001_quiz_ab_test {
    BIGINT id_test
    BIGINT id_versao
    VARCHAR nome
    TEXT descricao
    LONGTEXT variantes_json
    TIMESTAMP dt_criacao
  }
  lp001_quiz_agendamentos {
    BIGINT id_agendamento
    BIGINT id_lead
    BIGINT id_sessao
    ENUM origem_cta
    DATETIME dt_agendada
    ENUM status
    TIMESTAMP dt_log
  }
  lp001_quiz_benchmark_rolling {
    BIGINT id_bench
    BIGINT id_versao
    SMALLINT id_cargo
    BIGINT id_modelo
    BIGINT id_dominio
    ENUM janela
    DECIMAL benchmark_pct
    INT amostra_n
    DATETIME updated_at
  }
  lp001_quiz_cargo_map {
    SMALLINT id_cargo
    BIGINT id_versao
  }
  lp001_quiz_checklist_result {
    BIGINT id_result
    BIGINT id_sessao
    BIGINT id_regra
    INT ordem
    VARCHAR item
    TINYINT prioridade
    VARCHAR tag
    DATETIME created_at
  }
  lp001_quiz_checklist_rules {
    BIGINT id_regra
    BIGINT id_versao
    SMALLINT id_cargo
    BIGINT id_modelo
    BIGINT id_dominio
    ENUM condicao
    DECIMAL threshold_num
    DECIMAL threshold_max
    VARCHAR check_item
    VARCHAR check_owner_sugerido
    SMALLINT prazo_sugerido_dias
    VARCHAR tag
    TINYINT prioridade
    TINYINT max_sugeridos
    TINYINT ativo
    VARCHAR versao
    DATETIME created_at
  }
  lp001_quiz_domain_weights_overrides {
    BIGINT id_override
    BIGINT id_versao
    BIGINT id_dominio
    SMALLINT id_cargo
    DECIMAL peso_base
    DECIMAL peso_extra
    VARCHAR obs
    TIMESTAMP dt_criacao
  }
  lp001_quiz_dominios {
    BIGINT id_dominio
    BIGINT id_versao
    VARCHAR nome
    DECIMAL peso
    SMALLINT ordem
  }
  lp001_quiz_email_log {
    BIGINT id_email
    BIGINT id_lead
    BIGINT id_sessao
    ENUM tipo
    ENUM status_envio
    VARCHAR provider
    VARCHAR provider_msg_id
    VARCHAR erro_msg
    DATETIME dt_envio
    TIMESTAMP dt_log
  }
  lp001_quiz_leads {
    BIGINT id_lead
    VARCHAR nome
    VARCHAR email
    TINYINT email_validado
    DATETIME email_validado_dt
    VARCHAR empresa
    SMALLINT id_cargo
    SMALLINT id_faixa_fat
    SMALLINT id_faixa_colab
    VARCHAR telefone_whatsapp_e164
    TINYINT whatsapp_optin
    DATETIME whatsapp_optin_dt
    TINYINT consent_termos
    TINYINT consent_marketing
    DATETIME dt_consent
    VARCHAR origem
    VARCHAR utm_source
    VARCHAR utm_medium
    VARCHAR utm_campaign
    VARCHAR utm_content
    VARCHAR utm_term
    TIMESTAMP dt_cadastro
    TIMESTAMP dt_update
  }
  lp001_quiz_opcoes {
    BIGINT id_opcao
    BIGINT id_pergunta
    SMALLINT ordem
    TEXT texto
    TEXT explicacao
    SMALLINT score
    ENUM categoria_resposta
  }
  lp001_quiz_perguntas {
    BIGINT id_pergunta
    BIGINT id_versao
    BIGINT id_dominio
    SMALLINT ordem
    TEXT texto
    JSON glossario_json
    ENUM tipo
    VARCHAR branch_key
  }
  lp001_quiz_recommendation_rules {
    BIGINT id_regra
    BIGINT id_versao
    SMALLINT id_cargo
    BIGINT id_modelo
    BIGINT id_dominio
    ENUM condicao
    DECIMAL threshold_num
    DECIMAL threshold_max
    VARCHAR titulo
    TEXT recomendacao_md
    TINYINT prioridade
    TINYINT ativo
    VARCHAR versao
    DATETIME created_at
  }
  lp001_quiz_respostas {
    BIGINT id_resposta
    BIGINT id_sessao
    BIGINT id_pergunta
    BIGINT id_opcao
    SMALLINT ordem
    SMALLINT score_opcao
    INT tempo_na_tela_ms
    DATETIME dt_resposta
  }
  lp001_quiz_result_profiles {
    BIGINT id_profile
    BIGINT id_versao
    VARCHAR nome
    SMALLINT intervalo_score_min
    SMALLINT intervalo_score_max
    TEXT resumo_executivo
    MEDIUMTEXT recomendacoes_html
  }
  lp001_quiz_scores {
    BIGINT id_score
    BIGINT id_sessao
    SMALLINT score_total
    DECIMAL score_pct_bruto
    DECIMAL score_pct_ponderado
    BIGINT maturidade_id
    JSON detalhes_json
    ENUM classificacao_global
    LONGTEXT score_por_dominio
    BIGINT id_profile
    VARCHAR pdf_path
    CHAR pdf_hash
    DATETIME pdf_gerado_dt
    DATETIME dt_calculo
    DATETIME updated_at
  }
  lp001_quiz_scores_det {
    BIGINT id_score_det
    BIGINT id_score
    BIGINT id_sessao
    BIGINT id_dominio
    DECIMAL media_nota
    DECIMAL pct_0_100
    INT perguntas_respondidas
    DATETIME created_at
  }
  lp001_quiz_sessoes {
    BIGINT id_sessao
    BIGINT id_versao
    BIGINT id_lead
    CHAR session_token
    VARBINARY ip
    VARCHAR user_agent
    VARCHAR ab_variant
    ENUM status
    DATETIME dt_inicio
    DATETIME dt_fim
  }
  lp001_quiz_versao {
    BIGINT id_versao
    BIGINT id_quiz
    VARCHAR descricao
    DATETIME data_publicacao
    TINYINT is_ab_test
    TEXT nota_de_relevo
    TIMESTAMP dt_criacao
  }
  lp001_quiz_whatsapp_log {
    BIGINT id_msg
    BIGINT id_lead
    BIGINT id_sessao
    ENUM provider
    VARCHAR template_name
    ENUM status_envio
    VARCHAR provider_msg_id
    VARCHAR last_error
    DATETIME status_dt
    TIMESTAMP dt_log
  }
  milestones_kr {
    INT id_milestone
    VARCHAR id_kr
    INT num_ordem
    DATE data_ref
    DECIMAL valor_esperado
    DECIMAL valor_esperado_min
    DECIMAL valor_esperado_max
    DECIMAL valor_real_consolidado
    DATETIME dt_ultimo_apontamento
    INT qtde_apontamentos
    TINYINT gerado_automatico
    TINYINT editado_manual
    TEXT justificativa_edicao
    TEXT comentario_analise
    TINYINT bloqueado_para_edicao
    VARCHAR status_aprovacao
    VARCHAR id_user_solicitante
    VARCHAR id_user_aprovador
    DATETIME dt_aprovacao
    VARCHAR id_company
  }
  notificacoes {
    INT id_notificacao
    INT id_user
    VARCHAR tipo
    VARCHAR titulo
    TEXT mensagem
    VARCHAR url
    TINYINT lida
    DATETIME dt_criado
    DATETIME dt_lida
    JSON meta_json
  }
  objetivos {
    INT id_objetivo
    TEXT descricao
    VARCHAR tipo
    VARCHAR pilar_bsc
    VARCHAR dono
    VARCHAR usuario_criador
    INT id_user_criador
    INT id_company
    VARCHAR status
    DATE dt_criacao
    DATE dt_prazo
    DATE dt_conclusao
    VARCHAR status_aprovacao
    VARCHAR aprovador
    INT id_user_aprovador
    DATETIME dt_aprovacao
    TEXT comentarios_aprovacao
    DATETIME dt_ultima_atualizacao
    VARCHAR usuario_ult_alteracao
    VARCHAR qualidade
    TEXT observacoes
    VARCHAR tipo_ciclo
    VARCHAR ciclo
    DATE dt_inicio
    TEXT justificativa_ia
  }
  objetivo_links {
    BIGINT id_link
    INT id_company
    INT id_src
    INT id_dst
    TEXT justificativa
    TINYINT ativo
    VARCHAR observacao
    INT criado_por
    DATETIME criado_em
    DATETIME atualizado_em
  }
  okr_email_verifications {
    BIGINT id
    VARCHAR email
    CHAR token
    VARCHAR code_hash
    ENUM status
    TINYINT attempts
    DATETIME expires_at
    VARCHAR ip_address
    VARCHAR user_agent
    DATETIME created_at
    DATETIME verified_at
  }
  okr_kr_envolvidos {
    BIGINT id
    VARCHAR id_kr
    BIGINT id_user
    VARCHAR papel
    DATETIME dt_incl
  }
  orcamentos {
    INT id_orcamento
    VARCHAR id_iniciativa
    DECIMAL valor
    DATE data_desembolso
    VARCHAR status_aprovacao
    VARCHAR id_user_aprovador
    DATETIME dt_aprovacao
    VARCHAR id_user_criador
    DATE dt_criacao
    VARCHAR id_user_ult_alteracao
    DATETIME dt_ultima_atualizacao
    TEXT justificativa_orcamento
    DECIMAL valor_realizado
    VARCHAR status_financeiro
    VARCHAR codigo_orcamento
    TEXT comentarios_aprovacao
  }
  orcamentos_detalhes {
    INT id_despesa
    INT id_orcamento
    DECIMAL valor
    TEXT descricao
    DATE data_pagamento
    TEXT evidencia_pagamento
    VARCHAR id_user_criador
    DATETIME dt_criacao
  }
  orcamentos_envolvidos {
    INT id_orcamento
    VARCHAR id_user
    DATETIME dt_inclusao
  }
  permissoes_aprovador {
    INT id_permissao
    VARCHAR id_user
    VARCHAR tipo_estrutura
    VARCHAR status_aprovacao
  }
  rbac_capabilities {
    INT capability_id
    VARCHAR resource
    ENUM action
    ENUM scope
    JSON conditions_json
    VARCHAR cap_key
  }
  rbac_roles {
    INT role_id
    VARCHAR role_key
    VARCHAR role_name
    TEXT role_desc
    TINYINT is_system
    TINYINT is_active
    DATETIME created_at
    DATETIME updated_at
  }
  rbac_role_capability {
    INT role_id
    INT capability_id
    ENUM effect
  }
  rbac_user_capability {
    INT user_id
    INT capability_id
    ENUM effect
    VARCHAR note
  }
  rbac_user_role {
    INT user_id
    INT role_id
    DATETIME valid_from
    DATETIME valid_to
  }
  rbac_user_roles {
    INT user_id
    INT role_id
    TIMESTAMP valid_from
    TIMESTAMP valid_to
  }
  usuarios {
    INT id_user
    VARCHAR primeiro_nome
    VARCHAR ultimo_nome
    INT avatar_id
    VARCHAR telefone
    VARCHAR empresa
    INT id_company
    INT id_departamento
    TINYINT id_nivel_cargo
    VARCHAR faixa_qtd_funcionarios
    VARCHAR email_corporativo
    TEXT imagem_url
    DATETIME dt_cadastro
    VARCHAR ip_criacao
    INT id_user_criador
    INT id_permissao
    DATETIME dt_alteracao
    INT id_user_alteracao
  }
  usuarios_credenciais {
    INT id_user
    VARCHAR senha_hash
  }
  usuarios_paginas {
    INT id_user
    VARCHAR id_pagina
  }
  usuarios_password_resets {
    INT id_reset
    INT user_id
    VARCHAR token
    DATETIME expira_em
    CHAR selector
    CHAR verifier_hash
    DATETIME created_at
    DATETIME used_at
    VARCHAR ip_request
    VARCHAR user_agent_request
    VARCHAR ip_use
    VARCHAR user_agent_use
  }
  usuarios_perfis {
    INT id_user
    VARCHAR id_perfil
  }
  usuarios_permissoes {
    INT id_user
    VARCHAR id_permissao
  }
  usuarios_planos {
    INT id_user
    VARCHAR id_plano
    DATE dt_inicio
    DATE dt_fim
  }
  wp_leads {
    BIGINT id
    TIMESTAMP created_at
    VARCHAR full_name
    VARCHAR phone
    VARCHAR email
    CHAR uf
    VARCHAR city
    INT city_ibge_id
    VARCHAR geo_country
    VARCHAR geo_region
    VARCHAR geo_city
    VARCHAR geo_postal
    DECIMAL geo_latitude
    DECIMAL geo_longitude
    VARCHAR geo_timezone
    VARCHAR geo_org
    VARCHAR geo_asn
    VARCHAR ip
    TEXT user_agent
    ENUM device_type
    TEXT referrer
    TEXT landing_url
    VARCHAR referer_domain
    VARCHAR utm_source
    VARCHAR utm_medium
    VARCHAR utm_campaign
    VARCHAR utm_term
    VARCHAR utm_content
    VARCHAR gclid
    VARCHAR fbclid
    VARCHAR msclkid
    VARCHAR ttclid
    VARCHAR first_source
    VARCHAR first_medium
    VARCHAR first_campaign
    TEXT first_referrer
    VARCHAR browser_lang
    VARCHAR timezone
    INT screen_w
    INT screen_h
    CHAR fingerprint
    VARCHAR attribution
  }
  apontamentos_kr }o--|| key_results : "id_kr→id_kr"
  apontamentos_kr }o--|| milestones_kr : "id_milestone→id_milestone"
  apontamentos_kr_anexos }o--|| apontamentos_kr : "id_apontamento→id_apontamento"
  apontamentos_status_iniciativas }o--|| iniciativas : "id_iniciativa→id_iniciativa"
  apontamentos_status_iniciativas }o--|| dom_status_kr : "status→id_status"
  company_style }o--|| company : "id_company→id_company"
  dom_cargos }o--|| dom_departamentos : "id_departamento→id_departamento"
  dom_cargos }o--|| dom_niveis_cargo : "id_nivel_cargo→id_nivel"
  iniciativas }o--|| key_results : "id_kr→id_kr"
  iniciativas }o--|| key_results : "id_kr→id_kr"
  iniciativas }o--|| dom_status_aprovacao : "status_aprovacao→id_status"
  iniciativas }o--|| dom_status_kr : "status→id_status"
  iniciativas_envolvidos }o--|| iniciativas : "id_iniciativa→id_iniciativa"
  key_results }o--|| objetivos : "id_objetivo→id_objetivo"
  key_results }o--|| dom_tipo_kr : "tipo_kr→id_tipo"
  key_results }o--|| dom_tipo_frequencia_milestone : "tipo_frequencia_milestone→id_frequencia"
  key_results }o--|| dom_natureza_kr : "natureza_kr→id_natureza"
  key_results }o--|| objetivos : "id_objetivo→id_objetivo"
  key_results }o--|| dom_qualidade_objetivo : "qualidade→id_qualidade"
  key_results }o--|| dom_status_kr : "status→id_status"
  key_results }o--|| dom_status_aprovacao : "status_aprovacao→id_status"
  lp001_quiz }o--|| lp001_quiz_versao : "versao_ativa→id_versao"
  lp001_quiz_ab_test }o--|| lp001_quiz_versao : "id_versao→id_versao"
  lp001_quiz_agendamentos }o--|| lp001_quiz_leads : "id_lead→id_lead"
  lp001_quiz_agendamentos }o--|| lp001_quiz_sessoes : "id_sessao→id_sessao"
  lp001_quiz_benchmark_rolling }o--|| lp001_dom_cargos : "id_cargo→id_cargo"
  lp001_quiz_benchmark_rolling }o--|| lp001_quiz_dominios : "id_dominio→id_dominio"
  lp001_quiz_benchmark_rolling }o--|| lp001_quiz_versao : "id_versao→id_versao"
  lp001_quiz_cargo_map }o--|| lp001_dom_cargos : "id_cargo→id_cargo"
  lp001_quiz_cargo_map }o--|| lp001_quiz_versao : "id_versao→id_versao"
  lp001_quiz_checklist_result }o--|| lp001_quiz_checklist_rules : "id_regra→id_regra"
  lp001_quiz_checklist_result }o--|| lp001_quiz_sessoes : "id_sessao→id_sessao"
  lp001_quiz_checklist_rules }o--|| lp001_dom_cargos : "id_cargo→id_cargo"
  lp001_quiz_checklist_rules }o--|| lp001_quiz_dominios : "id_dominio→id_dominio"
  lp001_quiz_checklist_rules }o--|| lp001_quiz_versao : "id_versao→id_versao"
  lp001_quiz_domain_weights_overrides }o--|| lp001_dom_cargos : "id_cargo→id_cargo"
  lp001_quiz_domain_weights_overrides }o--|| lp001_quiz_dominios : "id_dominio→id_dominio"
  lp001_quiz_domain_weights_overrides }o--|| lp001_quiz_versao : "id_versao→id_versao"
  lp001_quiz_dominios }o--|| lp001_quiz_versao : "id_versao→id_versao"
  lp001_quiz_email_log }o--|| lp001_quiz_leads : "id_lead→id_lead"
  lp001_quiz_email_log }o--|| lp001_quiz_sessoes : "id_sessao→id_sessao"
  lp001_quiz_leads }o--|| lp001_dom_cargos : "id_cargo→id_cargo"
  lp001_quiz_leads }o--|| lp001_dom_faixa_colaboradores : "id_faixa_colab→id_faixa_colab"
  lp001_quiz_leads }o--|| lp001_dom_faixa_faturamento : "id_faixa_fat→id_faixa_fat"
  lp001_quiz_opcoes }o--|| lp001_quiz_perguntas : "id_pergunta→id_pergunta"
  lp001_quiz_perguntas }o--|| lp001_quiz_dominios : "id_dominio→id_dominio"
  lp001_quiz_perguntas }o--|| lp001_quiz_versao : "id_versao→id_versao"
  lp001_quiz_recommendation_rules }o--|| lp001_dom_cargos : "id_cargo→id_cargo"
  lp001_quiz_recommendation_rules }o--|| lp001_quiz_dominios : "id_dominio→id_dominio"
  lp001_quiz_recommendation_rules }o--|| lp001_quiz_versao : "id_versao→id_versao"
  lp001_quiz_respostas }o--|| lp001_quiz_opcoes : "id_opcao→id_opcao"
  lp001_quiz_respostas }o--|| lp001_quiz_perguntas : "id_pergunta→id_pergunta"
  lp001_quiz_respostas }o--|| lp001_quiz_sessoes : "id_sessao→id_sessao"
  lp001_quiz_result_profiles }o--|| lp001_quiz_versao : "id_versao→id_versao"
  lp001_quiz_scores }o--|| lp001_quiz_result_profiles : "id_profile→id_profile"
  lp001_quiz_scores }o--|| lp001_quiz_sessoes : "id_sessao→id_sessao"
  lp001_quiz_scores_det }o--|| lp001_quiz_dominios : "id_dominio→id_dominio"
  lp001_quiz_scores_det }o--|| lp001_quiz_scores : "id_score→id_score"
  lp001_quiz_scores_det }o--|| lp001_quiz_sessoes : "id_sessao→id_sessao"
  lp001_quiz_sessoes }o--|| lp001_quiz_leads : "id_lead→id_lead"
  lp001_quiz_sessoes }o--|| lp001_quiz_versao : "id_versao→id_versao"
  lp001_quiz_versao }o--|| lp001_quiz : "id_quiz→id_quiz"
  lp001_quiz_whatsapp_log }o--|| lp001_quiz_leads : "id_lead→id_lead"
  lp001_quiz_whatsapp_log }o--|| lp001_quiz_sessoes : "id_sessao→id_sessao"
  milestones_kr }o--|| key_results : "id_kr→id_kr"
  milestones_kr }o--|| dom_status_aprovacao : "status_aprovacao→id_status"
  objetivos }o--|| company : "id_company→id_company"
  objetivos }o--|| dom_pilar_bsc : "pilar_bsc→id_pilar"
  objetivos }o--|| dom_qualidade_objetivo : "qualidade→id_qualidade"
  objetivos }o--|| dom_status_kr : "status→id_status"
  objetivos }o--|| dom_status_aprovacao : "status_aprovacao→id_status"
  objetivos }o--|| dom_tipo_objetivo : "tipo→id_tipo"
  objetivos }o--|| dom_ciclos : "tipo_ciclo→nome_ciclo"
  objetivo_links }o--|| objetivos : "id_dst→id_objetivo"
  objetivo_links }o--|| objetivos : "id_src→id_objetivo"
  orcamentos_detalhes }o--|| orcamentos : "id_orcamento→id_orcamento"
  orcamentos_envolvidos }o--|| orcamentos : "id_orcamento→id_orcamento"
  permissoes_aprovador }o--|| dom_status_aprovacao : "status_aprovacao→id_status"
  rbac_role_capability }o--|| rbac_capabilities : "capability_id→capability_id"
  rbac_role_capability }o--|| rbac_roles : "role_id→role_id"
  rbac_user_capability }o--|| rbac_capabilities : "capability_id→capability_id"
  rbac_user_capability }o--|| usuarios : "user_id→id_user"
  rbac_user_role }o--|| rbac_roles : "role_id→role_id"
  rbac_user_role }o--|| usuarios : "user_id→id_user"
  rbac_user_roles }o--|| rbac_roles : "role_id→role_id"
  rbac_user_roles }o--|| usuarios : "user_id→id_user"
  usuarios }o--|| dom_departamentos : "id_departamento→id_departamento"
  usuarios }o--|| dom_niveis_cargo : "id_nivel_cargo→id_nivel"
  usuarios }o--|| avatars : "avatar_id→id"
  usuarios }o--|| company : "id_company→id_company"
  usuarios }o--|| usuarios : "id_user_criador→id_user"
  usuarios }o--|| dom_permissoes : "id_permissao→id_dominio"
  usuarios }o--|| usuarios : "id_user_alteracao→id_user"
  usuarios_credenciais }o--|| usuarios : "id_user→id_user"
  usuarios_paginas }o--|| dom_paginas : "id_pagina→id_pagina"
  usuarios_paginas }o--|| usuarios : "id_user→id_user"
  usuarios_password_resets }o--|| usuarios : "user_id→id_user"
  usuarios_perfis }o--|| usuarios : "id_user→id_user"
  usuarios_permissoes }o--|| usuarios : "id_user→id_user"
  usuarios_planos }o--|| usuarios : "id_user→id_user"
```

## Resumo

- Tabelas: 76
- Views: 9
- Triggers: 1
