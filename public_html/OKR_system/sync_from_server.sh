#!/bin/bash
# ============================================================
# Sync code files from Hostgator server via SSH+tar
# Downloads only code/config files, excludes media/binaries
# ============================================================

REMOTE_USER="planni40"
REMOTE_HOST="br822.hostgator.com.br"
REMOTE_PATH="public_html/OKR_system"
LOCAL_PATH="/c/Meus_Projetos/OKRsystem/public_html/OKR_system"

echo "========================================="
echo " Sync OKR_system - Code Files Only"
echo "========================================="
echo ""
echo "Remote: ${REMOTE_USER}@${REMOTE_HOST}:~/${REMOTE_PATH}"
echo "Local:  ${LOCAL_PATH}"
echo ""

# Step 1: Test connection and list remote structure
echo "[1/3] Testando conexao e listando estrutura remota..."
echo "      (Digite sua senha quando solicitado)"
echo ""

REMOTE_LISTING=$(ssh "${REMOTE_USER}@${REMOTE_HOST}" "
  cd ~/${REMOTE_PATH} 2>/dev/null || { echo 'ERRO: Diretorio remoto nao encontrado'; exit 1; }
  echo '=== ESTRUTURA REMOTA ==='
  find . -type f \
    ! -iname '*.jpg' ! -iname '*.jpeg' ! -iname '*.png' ! -iname '*.gif' \
    ! -iname '*.bmp' ! -iname '*.svg' ! -iname '*.ico' ! -iname '*.webp' \
    ! -iname '*.mp4' ! -iname '*.avi' ! -iname '*.mov' ! -iname '*.wmv' \
    ! -iname '*.flv' ! -iname '*.webm' ! -iname '*.mkv' ! -iname '*.m4v' \
    ! -iname '*.mp3' ! -iname '*.wav' ! -iname '*.ogg' ! -iname '*.flac' ! -iname '*.m4a' \
    ! -iname '*.woff' ! -iname '*.woff2' ! -iname '*.ttf' ! -iname '*.eot' ! -iname '*.otf' \
    ! -iname '*.pdf' ! -iname '*.doc' ! -iname '*.docx' ! -iname '*.xls' ! -iname '*.xlsx' \
    ! -iname '*.zip' ! -iname '*.tar' ! -iname '*.gz' ! -iname '*.rar' ! -iname '*.7z' \
    ! -iname '*.exe' ! -iname '*.dll' ! -iname '*.so' ! -iname '*.phar' \
    ! -path './vendor/*' \
    ! -path './node_modules/*' \
    ! -path './uploads/*' \
    ! -path './.git/*' \
    ! -path './logs/*' \
    ! -path './Ambiente_Python/*' \
    2>/dev/null | sort
  echo '=== FIM ==='
")

if [ $? -ne 0 ]; then
  echo "ERRO: Falha na conexao SSH. Verifique suas credenciais."
  exit 1
fi

FILE_COUNT=$(echo "$REMOTE_LISTING" | grep -c '^\.\/')
echo ""
echo "Encontrados ${FILE_COUNT} arquivos de codigo no servidor."
echo ""

# Show summary by extension
echo "Resumo por tipo de arquivo:"
echo "$REMOTE_LISTING" | grep '^\.\/' | sed 's/.*\.//' | sort | uniq -c | sort -rn | head -20
echo ""

read -p "Deseja baixar esses arquivos? (s/n): " CONFIRM
if [[ "$CONFIRM" != "s" && "$CONFIRM" != "S" ]]; then
  echo "Operacao cancelada."
  exit 0
fi

# Step 2: Download via SSH+tar
echo ""
echo "[2/3] Baixando arquivos de codigo..."
echo "      (Digite sua senha novamente quando solicitado)"
echo ""

ssh "${REMOTE_USER}@${REMOTE_HOST}" "
  cd ~ && tar czf - \
    --exclude='*.jpg' --exclude='*.jpeg' --exclude='*.png' --exclude='*.gif' \
    --exclude='*.bmp' --exclude='*.svg' --exclude='*.ico' --exclude='*.webp' \
    --exclude='*.mp4' --exclude='*.avi' --exclude='*.mov' --exclude='*.wmv' \
    --exclude='*.flv' --exclude='*.webm' --exclude='*.mkv' --exclude='*.m4v' \
    --exclude='*.mp3' --exclude='*.wav' --exclude='*.ogg' --exclude='*.flac' --exclude='*.m4a' \
    --exclude='*.woff' --exclude='*.woff2' --exclude='*.ttf' --exclude='*.eot' --exclude='*.otf' \
    --exclude='*.pdf' --exclude='*.doc' --exclude='*.docx' --exclude='*.xls' --exclude='*.xlsx' \
    --exclude='*.zip' --exclude='*.tar' --exclude='*.gz' --exclude='*.rar' --exclude='*.7z' \
    --exclude='*.exe' --exclude='*.dll' --exclude='*.so' --exclude='*.phar' \
    --exclude='${REMOTE_PATH}/vendor' \
    --exclude='${REMOTE_PATH}/node_modules' \
    --exclude='${REMOTE_PATH}/uploads' \
    --exclude='${REMOTE_PATH}/.git' \
    --exclude='${REMOTE_PATH}/logs' \
    --exclude='${REMOTE_PATH}/Ambiente_Python' \
    ${REMOTE_PATH}
" | tar xzf - -C "/c/Meus_Projetos/OKRsystem/public_html/" 2>&1

if [ $? -eq 0 ]; then
  echo ""
  echo "[3/3] Sync concluido com sucesso!"
  echo ""
  echo "Arquivos atualizados em: ${LOCAL_PATH}"
  echo ""
  # Show what was downloaded
  find "${LOCAL_PATH}" -type f -newer "${LOCAL_PATH}/sync_from_server.sh" 2>/dev/null | head -30
else
  echo ""
  echo "ERRO durante o download. Tentando metodo alternativo com sftp..."
  echo ""
  # Fallback: sftp batch approach
  bash "$(dirname "$0")/sync_from_server_sftp.sh"
fi
