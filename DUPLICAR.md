# Como duplicar este projeto para outra cliente

Guia para quem já entende o sistema e quer replicá-lo do zero para um novo estúdio.  
Escrito para ser lido por um humano, não por uma IA.

---

## A ordem importa

Existe uma sequência lógica. Fazer fora dela significa corrigir coisa errada depois.  
A sequência é: **infraestrutura → banco → configuração → marca → dados → integrações → deploy → cron → testes**.

---

## 1. Infraestrutura (15 min)

### Repositório
- Fork ou cópia do repositório para um novo nome (ex: `studiolucia`)
- Novo repositório privado no GitHub

### Servidor local (desenvolvimento)
- XAMPP rodando. Banco de desenvolvimento: `studiolucia_dev`
- Criar o banco:
  ```sql
  CREATE DATABASE studiolucia_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  ```

### Hospedagem (produção)
- HostGator compartilhado funciona — mas tem limitações reais (veja seção 7)
- Alternativa mais confortável: VPS com PHP 8.2, mesmo padrão da Evolution API

---

## 2. Banco de dados — rodar as migrations (20 min)

As migrations ficam em `migrations/` numeradas em ordem (`001_`, `002_`, ...).  
Rodar **todas, em ordem, sem pular**:

```powershell
# Cada arquivo, um a um:
C:\xampp\mysql\bin\mysql.exe -u root studiolucia_dev < migrations\001_estrutura_base.sql
C:\xampp\mysql\bin\mysql.exe -u root studiolucia_dev < migrations\002_...sql
# ... e assim por diante
```

**Armadilha comum:** pular uma migration porque "parece que não tem nada importante" — sempre tem. Se uma migration falhar, corrija antes de continuar.

---

## 3. Arquivos de configuração — os arquivos que NÃO estão no Git (30 min)

Estes arquivos são **gitignored** e precisam ser criados manualmente em cada ambiente.  
São os "segredos" do sistema. Sem eles nada funciona.

### `config/conexao.php`
É o bootstrap de tudo. Detecta ambiente pelo hostname e define `$pdo` e `BASE`.

```php
<?php
$host     = 'localhost';
$dbname   = hostname() === 'localhost' ? 'studiolucia_dev' : 'cpaneluser_nomedobanco';
$user     = hostname() === 'localhost' ? 'root' : 'cpaneluser_dbuser';
$password = hostname() === 'localhost' ? ''     : 'senhaaqui';

define('BASE', hostname() === 'localhost' ? '/studiolucia' : '');

// Carrega os segredos (cada um define suas constantes)
if (file_exists(__DIR__ . '/evolution_keys.php'))  require_once __DIR__ . '/evolution_keys.php';
if (file_exists(__DIR__ . '/gemini.php'))           require_once __DIR__ . '/gemini.php';
if (file_exists(__DIR__ . '/smtp_keys.php'))        require_once __DIR__ . '/smtp_keys.php';
if (file_exists(__DIR__ . '/google_oauth.php'))     require_once __DIR__ . '/google_oauth.php';

require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/mailer.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(503);
    die('Erro de conexão com o banco de dados.');
}
```

> **Atenção:** copie o `conexao.php` de produção atual como base e adapte. Nunca commite esse arquivo.

### `config/evolution_keys.php`
```php
<?php
define('EVOLUTION_API_URL', 'https://sua-evolution-api.com');
define('EVOLUTION_API_KEY', 'sua-chave-global');
define('EVOLUTION_INSTANCE', 'NomeDaInstancia');
```

### `config/gemini.php`
```php
<?php
define('GEMINI_API_KEY', 'AIza...');
```

### `config/smtp_keys.php`
```php
<?php
define('SMTP_HOST', 'smtp.seuprovedor.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'noreply@seudominio.com');
define('SMTP_PASS', 'senhaaqui');
define('SMTP_FROM_NAME', 'Studio Lucia');
```

### `config/google_oauth.php` (opcional — só se usar login Google)
```php
<?php
define('GOOGLE_CLIENT_ID', '123...apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'GOCSPX-...');
```

---

## 4. Personalização de marca (30–60 min)

Esta parte define a identidade visual. Vale o tempo — é o que a cliente vê.

### Cores
Tudo em variáveis CSS em `geral/css/estilo.css`. Procure o bloco de tokens `--roxo-*` e substitua pela paleta da nova cliente. O sistema inteiro segue. Não precisa mexer em HTML.

```css
/* Exemplo: trocar roxo por verde-escuro */
--roxo-900: #0a2e1a;
--roxo-700: #1a5c35;
--roxo-500: #2d8a52;  /* accent principal */
--roxo-300: #7dc99a;
--roxo-100: #d4f5e2;
```

### Logo
- Substituir `geral/img/LogoTransparente.png` (versão clara, fundo transparente)
- Substituir `geral/img/mascara.png` (ícone pequeno usado nos cards) se aplicável

### Nome do estúdio
- `geral/header.php` — nome exibido na navbar e e-mails
- Título das páginas (`$paginaTitulo` em cada arquivo) — só se quiser personalizar por página

---

## 5. Dados iniciais no banco (45 min)

### Criar a designer (usuária admin)

Não existe tela de cadastro para designer — crie diretamente no banco:

```sql
INSERT INTO Usuarios (
  IDUsuario, Nome, Email, Senha, NivelAcesso, Ativo, EmailVerificado, MomentoRegistro
) VALUES (
  UUID(),
  'Nome da Designer',
  'designer@estudio.com',
  '$2y$12$HASH_GERADO_PELO_PHP',  -- use password_hash() no PHP para gerar
  'designer',
  1,
  1,
  NOW()
);
```

Para gerar a senha corretamente via PHP:
```php
echo password_hash('senhadesejada', PASSWORD_DEFAULT);
```

### Serviços e subserviços
Cadastrar pelo painel (`/painel/servicos.php`) após logar como designer.  
Ordem sugerida: serviços principais primeiro, subserviços depois.

### Horários de atendimento
Cadastrar pelo painel (`/painel/configuracoes.php`) — dia da semana + horário de início/fim + intervalo de almoço.  
**Atenção:** o sistema só exibe slots se houver entrada em `HorariosAtendimento` para aquele dia da semana. Dias sem entrada ficam bloqueados automaticamente.

### ConfiguracoesSistema — parâmetros críticos

Verificar e ajustar via painel ou direto no banco:

| Chave | O que faz | Valor sugerido |
|---|---|---|
| `intervalo_minutos` | Espaçamento entre slots | `30` ou `60` |
| `antecedencia_minima_h` | Horas mínimas de antecedência para agendar | `2` a `24` |
| `dias_agenda_futura` | Quantos dias à frente a cliente pode ver | `30` a `60` |
| `msg_wa_lembrete` | Template da mensagem de lembrete WA | personalizar |
| `msg_wa_confirmacao` | Template de confirmação pós-agendamento | personalizar |
| `msg_wa_followup` | Template de follow-up pós-atendimento | personalizar |

> **Sobre os templates de WhatsApp:** este é o ponto mais subestimado. A mensagem que a cliente recebe forma a percepção do profissionalismo do estúdio. Vale uma tarde para acertar o tom certo — nem robótico demais, nem informal demais. Use as variáveis `{nome}`, `{servico}`, `{horario}`, `{data}`.

---

## 6. WhatsApp via Evolution API (1–2 horas)

### Criar a instância
1. Acesse o painel da Evolution API (ou o da VPS se for auto-hospedado)
2. Crie uma nova instância com o nome do estúdio (ex: `StudioLucia`)
3. Gere o QR Code e aponte o celular da cliente para conectar

### Configurar as chaves
Preencher `config/evolution_keys.php` com a URL da API, a API key global e o nome da instância.

### Testar
Ir em `/painel/agenda.php`, abrir um agendamento existente e clicar em "Enviar WhatsApp".  
Se aparecer erro de conexão: checar URL da API, instance name e se o número está no formato correto (`5511999999999` — DDI + DDD + número, sem espaços ou símbolos).

### Reconexão (acontece)
O WhatsApp desconecta com certa frequência em instâncias compartilhadas.  
O procedimento: acessar o painel da Evolution API → selecionar instância → reconectar → QR Code → celular da cliente.

---

## 7. Deploy — GitHub Actions + FTP (30 min)

### Configurar secrets no GitHub
No repositório → Settings → Secrets → Actions:
- `FTP_HOST` — host FTP da hospedagem
- `FTP_USER` — usuário FTP
- `FTP_PASS` — senha FTP

O arquivo `.github/workflows/deploy.yml` já está configurado. Push na `main` dispara o deploy automaticamente.

### O que NÃO vai pelo deploy (FTP)
Os arquivos de `config/` com segredos são excluídos do FTP intencionalmente.  
Eles precisam ser copiados manualmente para o servidor via cPanel → File Manager ou via FTP direto.

Checklist de arquivos a copiar manualmente na primeira vez:
- `config/conexao.php`
- `config/evolution_keys.php`
- `config/gemini.php`
- `config/smtp_keys.php`
- `config/google_oauth.php` (se usar)
- `.user.ini` (na raiz — controla sessões PHP no HostGator)

### `.user.ini` — obrigatório no HostGator
Sem este arquivo, as sessões expiram em 24 minutos (padrão do PHP).  
Criar na raiz do projeto (junto com `index.php`):

```ini
session.gc_maxlifetime = 86400
session.cookie_lifetime = 86400
```

### Gotchas do HostGator
- **Não use `SecRuleEngine` no `.htaccess`** — causa erro 500 em hospedagem compartilhada
- PHP 8.2 precisa estar selecionado no cPanel (Multi PHP Manager)
- MySQL: criar o banco e o usuário pelo cPanel antes de rodar as migrations em produção
- Migrations em produção: copiar cada `.sql` e rodar via phpMyAdmin ou MySQL CLI do cPanel

---

## 8. Cron jobs (15 min)

Os scripts em `cron/` recusam acesso web (retornam 403 se chamados via navegador).  
Precisam ser agendados no servidor.

### No cPanel (HostGator)
Cron Jobs → adicionar:

| Frequência | Comando |
|---|---|
| A cada hora | `php /home/usuario/public_html/cron/whatsapp_lembretes.php` |
| A cada hora | `php /home/usuario/public_html/cron/whatsapp_confirmacoes.php` |
| A cada hora | `php /home/usuario/public_html/cron/whatsapp_followup.php` |

> Os scripts usam flags no banco (`NotificacaoLembreteEnviada`, etc.) para não reenviar. Rodar de hora em hora é seguro.

### Testar manualmente
```powershell
C:\xampp\php\php.exe cron\whatsapp_lembretes.php
```
Ou em produção via SSH (se disponível) ou simplesmente verificar os logs em `LogsWhatsApp`.

---

## 9. Testes antes de passar para a cliente (1 hora)

Não pule esta etapa. É onde os problemas aparecem antes de virarem problemas reais.

**Fluxo completo do cliente:**
- [ ] Criar conta com e-mail real (verificar chegada do e-mail de confirmação)
- [ ] Login normal e via Google (se configurado)
- [ ] Escolher serviço → ver calendário semanal
- [ ] Selecionar horário → ver reserva de 10 minutos
- [ ] Confirmar agendamento → verificar que aparece na agenda da designer
- [ ] Receber WhatsApp de confirmação

**Painel da designer:**
- [ ] Agenda carregando corretamente
- [ ] Criar agendamento manual (novo_agendamento.php)
- [ ] Enviar mensagem WhatsApp manualmente para um agendamento
- [ ] Editar/cancelar agendamento
- [ ] Inserção forçada em dia sem expediente (parâmetro `?forca=1`)

**Cron:**
- [ ] Rodar `whatsapp_lembretes.php` manualmente e verificar log em `LogsWhatsApp`

---

## O que personalizar com mais cuidado

Três coisas que definem se o sistema vai ter "alma" para a nova cliente, ou vai parecer genérico:

### 1. Os templates de WhatsApp
São o ponto de contato mais direto entre o sistema e a cliente final.  
Escreva com a voz do estúdio — se o estúdio é descontraído, a mensagem pode ser. Se é sofisticado, que a mensagem reflita. Teste enviando para você mesmo antes de passar para a cliente.

### 2. Os horários e as regras de agendamento
`antecedencia_minima_h`, `intervalo_minutos`, dias de folga em `DiasEspeciais` — conversas com a designer antes de configurar. Esses parâmetros determinam a experiência real de agendamento.

### 3. A paleta de cores
Cinco minutos trocando as variáveis CSS transformam o sistema. Peça o brand guide ou pelo menos o Instagram do estúdio para pegar as cores. Um sistema que parece "da dela" tem um valor percebido muito maior.

---

## Estrutura de arquivos para referência rápida

```
/
├── agendamento/        # Fluxo de agendamento do cliente
│   ├── index.php       # Escolha do serviço
│   ├── horarios.php    # Calendário semanal + seleção de slot
│   ├── confirmar.php   # Tela de confirmação e POST final
│   └── reservar_slot.php  # AJAX — reserva temporária de 10 min
│
├── painel/             # Área da designer (admin)
│   ├── agenda.php      # Agenda do dia
│   ├── novo_agendamento.php  # Criação manual
│   ├── servicos.php    # Gestão de serviços
│   └── configuracoes.php    # Horários, parâmetros do sistema
│
├── usuario/            # Auth e perfil do cliente
│   ├── login.php / cadastro.php
│   ├── perfil.php / historico.php
│   └── processa_login.php / processa_cadastro.php
│
├── cron/               # Scripts de envio automático de WA
│
├── config/             # Bootstrap (gitignored os segredos)
│   ├── conexao.php     # ← criar manualmente em cada ambiente
│   ├── funcoes.php     # Helpers globais
│   └── *.php           # Segredos — nunca commitar
│
├── geral/
│   ├── header.php      # Nav + CSS inline do sistema
│   ├── footer.php      # JS global + modal WA
│   └── css/estilo.css  # Tokens de cor, layout, responsivo
│
├── migrations/         # SQL numerado — rodar em ordem
│
├── .user.ini           # Configuração de sessão PHP (HostGator)
├── CLAUDE.md           # Instruções para a IA
└── DUPLICAR.md         # Este arquivo
```

---

*Escrito em agosto de 2026 com base na experiência real de construção do sistema Belos Cílios.*
