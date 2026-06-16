# AGENTS.md — OKR_system

> Arquivo de instruções para agentes de IA (Codex, e equivalente ao `CLAUDE.md`).
> Lido automaticamente pelo Codex CLI quando aberto neste diretório.

## Contexto do projeto

Sistema OKR em PHP hospedado na **HostGator**. Código versionado no GitHub e
publicado no servidor via Git (push para um remote de deploy) ou via SFTP.

- Diretório local: `D:/Meus_Projetos/OKR_system`
- Diretório no servidor: `~/public_html/OKR_system` (home real `/home2/planni40`)

## Conexões (já configuradas a nível de SO — você herda)

As credenciais **não pertencem a nenhum agente**: estão no `~/.ssh/config` do
usuário Windows e nos remotes em `.git/config`. Rodando como o mesmo usuário,
você já consegue conectar sem copiar nada.

### Git remotes
| Remote   | URL                                                            | Uso              |
|----------|----------------------------------------------------------------|------------------|
| `origin` | `https://github.com/WendrewDouglas/OKR_system.git`             | código no GitHub |
| `deploy` | `ssh://planni40@hostgator-deploy/~/repos/OKR_system.git`       | deploy HostGator |

### Aliases SSH (em `~/.ssh/config`)
| Alias              | Host                      | User       | Chave privada              | Uso                          |
|--------------------|---------------------------|------------|----------------------------|------------------------------|
| `hostgator-deploy` | `br822.hostgator.com.br`  | `planni40` | `C:/.ssh/wendrew_pc_new`   | remote git `deploy`          |
| `hostgator`        | `br822.hostgator.com.br`  | `planni40` | `~/.ssh/planningbi_ed25519`| SSH/SFTP direto ao servidor  |

A autenticação é por chave (sem senha). Teste rápido:
```bash
ssh hostgator-deploy "pwd"     # deve responder /home2/planni40
```

## Fluxo de trabalho

### Versionamento
```bash
git add -A
git commit -m "mensagem"
git push origin main           # GitHub
```

### Deploy para o servidor
Opção A — Git (preferida):
```bash
git push deploy main
```
Opção B — SFTP (scripts prontos na raiz):
- `deploy_sftp.bat` — envia arquivos PHP corrigidos
- `sync_from_server_sftp.sh` — baixa o estado atual do servidor

### Conexão direta ao servidor
```bash
ssh hostgator                  # shell no HostGator
sftp planni40@br822.hostgator.com.br
```

## Configuração sensível
- Variáveis de ambiente / credenciais de banco: arquivo `.env` na raiz (NÃO commitar).
- Firebase admin SDK: `myapplication-*-firebase-adminsdk-*.json` (NÃO commitar).
- Respeite o `.gitignore`. Nunca exponha conteúdo de `.env` ou de chaves em logs/saída.

## Convenções
- PHP no backend (`api/`, `auth/`, `views/`, `includes/`).
- App em `okr_app/` (Flutter) e `app/`.
- Ambiente Python em `Ambiente_Python/.venv` (não versionar).
- Antes de alterar comportamento de deploy, leia `CONEXAO.md`.
