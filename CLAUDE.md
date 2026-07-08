# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## O que é

Sistema de agendamento para estúdio de cílios (Belos Cílios). PHP 8.2 puro (sem framework, sem Composer), MySQL via PDO, Bootstrap 5 via CDN, sessões nativas. Todo o código, banco e commits são em português (pt-BR).

## Comandos

Não há testes, linter nem build. Verificação de sintaxe e execução local:

```powershell
C:\xampp\php\php.exe -l caminho\do\arquivo.php          # lint de sintaxe
C:\xampp\mysql\bin\mysql.exe -u root beloscilios_dev < migrations\00X_arquivo.sql  # aplicar migration local
C:\xampp\php\php.exe cron\whatsapp_lembretes.php        # rodar cron manualmente (CLI only)
```

App local: `http://localhost/beloscilios` (XAMPP; Apache serve direto de `C:\xampp\htdocs\beloscilios`). Banco local: `beloscilios_dev`, usuário `root` sem senha.

## Deploy e ambientes

- **Push direto na `main`** — sem branch beta, sem PRs. Commits em português com prefixos `feat:`/`fix:`/`chore:`.
- Push na `main` dispara `.github/workflows/deploy.yml`: deploy via FTP para HostGator (hospedagem compartilhada).
- **`config/conexao.php` é gitignored E excluído do deploy FTP.** Existe uma cópia local e outra em produção; mudanças nele NÃO chegam em produção automaticamente — precisam ser replicadas manualmente (cPanel/FTP). O mesmo vale para os arquivos de segredo (`evolution_keys.php`, `gemini.php`, `smtp_keys.php`, `google_oauth.php`), que definem constantes e são carregados condicionalmente pelo `conexao.php`.
- `conexao.php` detecta ambiente pelo hostname: `localhost` → dev (`beloscilios_dev`); qualquer outro → produção. Define a constante `BASE` (`/beloscilios` local, string vazia em produção) e cria o `$pdo` global.
- **Gotcha HostGator**: hospedagem compartilhada — diretivas como `SecRuleEngine` no `.htaccess` causam erro 500. Migrations são aplicadas manualmente em produção (não há runner).

## Arquitetura

Cada arquivo `.php` é uma página ou endpoint acessado diretamente (sem roteador). Áreas:

- `config/` — `conexao.php` é o bootstrap único: carrega `funcoes.php` (helpers globais), `mailer.php` (SMTP via socket puro, fallback `mail()`), os arquivos de segredo, define `BASE` e conecta o PDO. Todo arquivo começa com `require_once` dele.
- `usuario/` — autenticação de clientes: cadastro com verificação de e-mail por token, login por senha ou Google (GIS — Google Identity Services, não redirect flow), perfil, histórico.
- `agendamento/` — fluxo de agendamento do cliente: `index.php` (escolhe serviço/subserviço) → `horarios.php` (grade de slots) → `reservar_slot.php` (AJAX, cria reserva temporária) → `confirmar.php` (POST cria o agendamento).
- `painel/` — área da designer (admin): agenda, clientes, serviços, relatório, configurações.
- `cron/` — scripts CLI-only (recusam acesso web) que enviam WhatsApp via Evolution API: confirmações, lembretes 24h antes, follow-up pós-atendimento. Flags `Notificacao*Enviada` em `Agendamentos` evitam reenvio; tudo é logado em `LogsWhatsApp`.
- `geral/` — `header.php` e `footer.php` compartilhados. Todo o CSS do site vive inline no `header.php` (paleta roxa em variáveis CSS `--roxo-*`). Antes de incluir o header, a página define `$paginaTitulo` e `$areaAtual` (`'painel'` ativa layout com sidebar).
- `migrations/` — `.sql` numerados (`001_...`), aplicados na ordem, manualmente.

### Concorrência de slots

Para evitar duplo-booking: `ReservasTemporarias` guarda uma pré-reserva de 10 minutos atrelada ao `session_id()`. `reservar_slot.php` limpa expiradas, checa conflito contra `Agendamentos` (status ≠ cancelado, overlap de `DataHoraAgendamento`/`DataHoraFim`) e contra reservas de outras sessões. `confirmar.php` reconfere a reserva ativa e o conflito antes do INSERT final.

### Convenções (seguir à risca)

- Padrão de toda página: guard de `session_start()` → `require_once config/conexao.php` → `exigirLogin('cliente'|'designer')` se protegida → HTML com header/footer de `geral/`.
- Níveis de acesso: `cliente` e `designer` (enum `NivelAcesso` em `Usuarios`; sessão guarda `usuario_id`, `usuario_nome`, `nivel_acesso`).
- PKs são UUIDs `VARCHAR(36)` gerados por `gerarUuid()`. Tabelas e colunas em PascalCase português (`Agendamentos`, `FKCliente`, `MomentoRegistro`, `AtualizadoEm`).
- SQL sempre via prepared statements com parâmetros nomeados (`:param`).
- Escapar saída HTML com `h()`. URLs sempre prefixadas com `BASE`.
- POSTs protegidos por CSRF: `gerarTokenCSRF()` no form, `validarTokenCSRF()` no processamento.
- Feedback ao usuário via flash message: `redirecionarComMensagem($url, $msg, $tipo)` grava na sessão; `flashMsg()` renderiza o alert Bootstrap.
- Configurações dinâmicas (templates de mensagem WhatsApp, intervalo de slots, antecedência mínima etc.) ficam na tabela chave/valor `ConfiguracoesSistema`, lidas com `getConfig($pdo, 'chave', 'padrao')`.
- Endpoints AJAX respondem JSON no formato `['ok' => bool, 'msg' => string, ...]`.
