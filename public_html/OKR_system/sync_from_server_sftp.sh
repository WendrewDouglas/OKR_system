#!/bin/bash
# ============================================================
# Sync code files from Hostgator via SFTP (fallback method)
# Downloads only code/config files using sftp get -r
# ============================================================

REMOTE_USER="planni40"
REMOTE_HOST="br822.hostgator.com.br"
REMOTE_PATH="public_html/OKR_system"
LOCAL_PATH="/c/Meus_Projetos/OKRsystem/public_html/OKR_system"
TEMP_DIR="/c/Meus_Projetos/OKRsystem/public_html/_temp_sync"

echo "========================================="
echo " Sync OKR_system via SFTP (fallback)"
echo "========================================="
echo ""

# Clean temp dir
rm -rf "$TEMP_DIR"
mkdir -p "$TEMP_DIR"

# Step 1: Get full recursive listing from server
echo "[1/4] Obtendo listagem de arquivos do servidor..."
echo "      (Digite sua senha quando solicitado)"
echo ""

sftp "${REMOTE_USER}@${REMOTE_HOST}" <<'SFTP_LIST' > /tmp/sftp_listing.txt 2>/dev/null
cd public_html/OKR_system
ls -la
cd api
ls -la
cd ..
cd app
ls -la
cd ..
cd auth
ls -la
cd ..
cd includes
ls -la
cd ..
cd views
ls -la
cd ..
cd tools
ls -la
cd ..
cd LP
ls -la
cd ..
bye
SFTP_LIST

if [ $? -ne 0 ]; then
  echo "ERRO: Falha na conexao SFTP."
  exit 1
fi

# Step 2: Download directories one by one, only code files
echo ""
echo "[2/4] Baixando arquivos de codigo..."
echo "      (Digite sua senha quando solicitado)"
echo ""

# Define code file extensions to include
CODE_EXTENSIONS="php js css html htm json xml yml yaml sql sh bat py twig ini cfg conf htaccess env lock md txt"

# Build sftp batch commands
BATCH_FILE="/tmp/sftp_sync_batch.txt"
cat > "$BATCH_FILE" <<'EOF'
cd public_html/OKR_system
lcd /c/Meus_Projetos/OKRsystem/public_html/_temp_sync

# Root files
get .htaccess
get index.php
get bootstrap.php
get check_env.php
get composer.json
get composer.lock
get error_log

# api directory
-mkdir api
lcd api
cd api
mget *.php
mget *.js
mget *.json
lcd ..
cd ..

# app directory
-mkdir app
lcd app
cd app
mget *.php
mget *.js
mget *.json
lcd ..
cd ..

# auth directory
-mkdir auth
lcd auth
cd auth
mget *.php
mget *.js
mget *.json
lcd ..
cd ..

# includes directory
-mkdir includes
lcd includes
cd includes
mget *.php
mget *.js
mget *.json
mget *.css
lcd ..
cd ..

# views directory
-mkdir views
lcd views
cd views
mget *.php
mget *.js
mget *.json
mget *.css
mget *.html
lcd ..
cd ..

# tools directory
-mkdir tools
lcd tools
cd tools
mget *.php
mget *.js
mget *.json
mget *.sh
mget *.py
lcd ..
cd ..

# LP directory
-mkdir LP
lcd LP
cd LP
mget *.php
mget *.html
mget *.css
mget *.js
mget *.json
lcd ..
cd ..

# assets - only css and js, not images/fonts
-mkdir assets
lcd assets
cd assets
-mkdir css
lcd css
cd css
mget *.css
lcd ..
cd ..
-mkdir js
lcd js
cd js
mget *.js
lcd ..
cd ..
lcd ..
cd ..

bye
EOF

sftp -b "$BATCH_FILE" "${REMOTE_USER}@${REMOTE_HOST}" 2>&1 | grep -v "^sftp>"

if [ $? -ne 0 ]; then
  echo ""
  echo "Alguns arquivos podem ter falhado (normal se nao existem)."
fi

# Step 3: Also try to get subdirectories we might have missed
echo ""
echo "[3/4] Verificando subdiretorios adicionais..."
echo ""

# Get a listing of all directories
ssh "${REMOTE_USER}@${REMOTE_HOST}" "find ~/${REMOTE_PATH} -type d -maxdepth 3 \
  ! -path '*/vendor/*' ! -path '*/node_modules/*' ! -path '*/uploads/*' \
  ! -path '*/.git/*' ! -path '*/logs/*' ! -path '*/Ambiente_Python/*' \
  2>/dev/null" 2>/dev/null | while read -r dir; do
  # Create directory locally
  local_dir=$(echo "$dir" | sed "s|.*/OKR_system/||")
  if [ -n "$local_dir" ] && [ "$local_dir" != "$dir" ]; then
    mkdir -p "${TEMP_DIR}/${local_dir}" 2>/dev/null
  fi
done

# Step 4: Copy from temp to final location
echo ""
echo "[4/4] Atualizando arquivos locais..."
echo ""

if [ -d "$TEMP_DIR" ] && [ "$(ls -A "$TEMP_DIR" 2>/dev/null)" ]; then
  # Copy files, overwriting existing
  cp -rv "$TEMP_DIR"/* "$LOCAL_PATH"/ 2>/dev/null

  echo ""
  echo "Sync concluido!"
  echo ""

  # Count files synced
  SYNCED=$(find "$TEMP_DIR" -type f 2>/dev/null | wc -l)
  echo "Total de arquivos sincronizados: ${SYNCED}"
  echo ""

  # List synced files
  echo "Arquivos baixados:"
  find "$TEMP_DIR" -type f 2>/dev/null | sed "s|${TEMP_DIR}/||" | sort
else
  echo "Nenhum arquivo foi baixado."
fi

# Cleanup
rm -rf "$TEMP_DIR"
rm -f "$BATCH_FILE" /tmp/sftp_listing.txt

echo ""
echo "Concluido. Arquivos em: ${LOCAL_PATH}"
