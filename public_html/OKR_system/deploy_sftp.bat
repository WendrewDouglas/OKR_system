@echo off
REM Deploy Security Fixes via SFTP
REM Destino: planni40@br822.hostgator.com.br:~/public_html/OKR_system/

sftp planni40@br822.hostgator.com.br <<EOF
cd public_html/OKR_system

put .htaccess
put index.php

cd api
put api/avatar_ai.php avatar_ai.php
put api/chat_api.php chat_api.php
put api/mapa_estrategico.php mapa_estrategico.php
cd ..

cd auth
put auth/aprovacao_api.php aprovacao_api.php
put auth/auth_login.php auth_login.php
put auth/bootstrap.php bootstrap.php
put auth/get_company.php get_company.php
put auth/get_company_style.php get_company_style.php
put auth/guards.php guards.php
put auth/notificacoes_api.php notificacoes_api.php
put auth/relatorio_gerarpdf.php relatorio_gerarpdf.php
put auth/relatorio_gerarpdflote.php relatorio_gerarpdflote.php
put auth/reset_company_style.php reset_company_style.php
put auth/salvar_company.php salvar_company.php
put auth/salvar_company_style.php salvar_company_style.php
put auth/salvar_iniciativas.php salvar_iniciativas.php
put auth/salvar_kr.php salvar_kr.php
put auth/salvar_missao_visao.php salvar_missao_visao.php
put auth/salvar_objetivo.php salvar_objetivo.php
put auth/sync_avatars.php sync_avatars.php
put auth/usuarios_api.php usuarios_api.php
cd ..

cd views
put views/aprovacao.php aprovacao.php
put views/config_style.php config_style.php
put views/dashboard.php dashboard.php
cd ..

bye
EOF
