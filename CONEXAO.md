# CONEXAO.md — Runbook de conexão e deploy (OKR_system)

Guia técnico para conectar ao servidor HostGator e ao Git. Vale para qualquer
agente (Claude, Codex) ou pessoa rodando como o usuário Windows `wendrewgomes`.

---

## 1. Como a conexão funciona (modelo mental)

Nenhuma ferramenta "tem" os acessos. Os acessos vêm de **arquivos do sistema
operacional** que qualquer processo do mesmo usuário enxerga:

1. `~/.ssh/config` → aliases de host + qual chave usar.
2. Chaves privadas em `C:/.ssh/` e `~/.ssh/`.
3. Remotes do Git em `OKR_system/.git/config`.

Logo, o Codex (mesmo usuário, mesma máquina) **já tem tudo**. Não copie chaves.

---

## 2. Inventário de credenciais

### Aliases SSH (`~/.ssh/config`)
```
Host hostgator-deploy
  HostName br822.hostgator.com.br
  User planni40
  IdentityFile C:/.ssh/wendrew_pc_new
  IdentitiesOnly yes

Host hostgator
  HostName br822.hostgator.com.br
  User planni40
  IdentityFile ~/.ssh/planningbi_ed25519
  IdentitiesOnly yes
```

### Git remotes (`.git/config`)
```
origin  https://github.com/WendrewDouglas/OKR_system.git
deploy  ssh://planni40@hostgator-deploy/~/repos/OKR_system.git
```

Servidor: `br822.hostgator.com.br` · user `planni40` · home `/home2/planni40`
App em `~/public_html/OKR_system`.

---

## 3. Verificação rápida (faça isto primeiro)

```bash
# 1. SSH por chave deve autenticar sem pedir senha
ssh hostgator-deploy "echo OK; pwd"        # esperado: OK / /home2/planni40

# 2. Git enxerga os remotes
git remote -v

# 3. Acesso de leitura ao remote de deploy
git ls-remote deploy            # lista refs sem erro de auth
```
Se algum passo pedir senha ou falhar com "Permission denied (publickey)",
veja a seção 6 (Troubleshooting).

---

## 4. Versionamento (GitHub)

```bash
git status
git add -A
git commit -m "feat: descrição"
git push origin main
git pull origin main            # trazer mudanças
```

## 5. Deploy para o HostGator

### Opção A — Git (preferida, atômica)
```bash
git push deploy main
```
O remote `deploy` aponta para um repositório bare no servidor
(`~/repos/OKR_system.git`); um hook de `post-receive` (se configurado) publica
em `public_html`. Confirme o resultado com `ssh hostgator "ls -la public_html/OKR_system"`.

### Opção B — SFTP (envio seletivo de arquivos PHP)
- Windows: `deploy_sftp.bat`
- Lista de comandos: `deploy_sftp_commands.txt`
- Manual:
  ```bash
  sftp planni40@br822.hostgator.com.br
  sftp> cd public_html/OKR_system
  sftp> put auth/usuarios_api.php usuarios_api.php
  sftp> bye
  ```

### Trazer o estado do servidor de volta
```bash
bash sync_from_server_sftp.sh   # baixa arquivos de código (não toca vendor/uploads)
```

---

## 6. Troubleshooting

| Sintoma | Causa provável | Ação |
|---|---|---|
| `Permission denied (publickey)` no `deploy` | chave `C:/.ssh/wendrew_pc_new` ausente ou sem permissão | confirme `ls C:/.ssh/wendrew_pc_new`; teste `ssh -i C:/.ssh/wendrew_pc_new planni40@br822.hostgator.com.br` |
| SSH pede senha | alias não foi lido / `IdentitiesOnly` | rode `ssh -v hostgator-deploy` e veja qual chave foi oferecida |
| `Host key verification failed` | host novo | `ssh-keyscan br822.hostgator.com.br >> ~/.ssh/known_hosts` |
| `git push deploy` ok mas site não atualiza | sem hook `post-receive` | publique via SFTP ou configure o hook no bare repo |
| GitHub pede credencial | token/credential manager | use `gh auth status`; o origin é HTTPS |

---

## 7. Regras de segurança
- **Nunca** commitar `.env`, `*firebase-adminsdk*.json`, ou qualquer chave.
- **Nunca** imprimir conteúdo de `.env`/chaves em logs ou respostas.
- Deploy direto em produção: avisar antes de sobrescrever arquivos do servidor.
