@echo off
echo =============================================
echo  Deploy: User Delete com Cascade/Reatribuicao
echo =============================================
echo.
echo Arquivos a enviar:
echo   - auth/usuarios_api.php (helpers + DELETE reescrito + pre_delete_check)
echo   - views/usuarios.php (modal contextual + toasts)
echo   - api/migrations/004_user_delete_prep.sql (referencia)
echo.
echo Destino: planni40@br822.hostgator.com.br:~/public_html/OKR_system/
echo.
pause

cd /d "C:\Meus_Projetos\OKRsystem\public_html\OKR_system"
sftp -b deploy_user_delete_commands.txt planni40@br822.hostgator.com.br

echo.
echo Deploy concluido!
pause
