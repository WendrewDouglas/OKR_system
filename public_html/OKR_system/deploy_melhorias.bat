@echo off
REM ============================================
REM  Deploy: Melhorias Chat IA + UX (FASE 1+2)
REM  Destino: planni40@br822.hostgator.com.br
REM ============================================
echo.
echo === Deploy OKR System - Melhorias Chat IA + UX ===
echo Arquivos: 27 (3 novos + 24 alterados)
echo Destino: br822.hostgator.com.br
echo.
echo Pressione Ctrl+C para cancelar ou...
pause

cd /d "%~dp0"
sftp -b deploy_melhorias_commands.txt hostgator

echo.
echo === Deploy concluido! ===
echo.
echo PROXIMO PASSO: Execute o SQL migration acessando:
echo   https://planningbi.com.br/OKR_system/run_migration.php
echo.
echo Depois de rodar, DELETE o run_migration.php do servidor!
echo.
pause
