<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/conexao.php';
// mailer.php pode não estar carregado se o conexao.php em produção for antigo
if (!function_exists('enviarEmail')) {
    require_once __DIR__ . '/../config/mailer.php';
}
exigirLogin('designer');

$resultado  = null;
$detalhes   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validarTokenCSRF($_POST['csrf_token'] ?? '')) {
    $para = trim($_POST['para'] ?? '');

    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
        $resultado = 'erro';
        $detalhes[] = 'E-mail de destino inválido.';
    } else {
        // Diagnóstico antes de enviar
        $detalhes[] = 'PHP: ' . phpversion();
        $detalhes[] = 'enviarEmail() carregada: ' . (function_exists('enviarEmail') ? 'Sim' : 'Não — mailer.php não foi incluído pelo conexao.php do servidor');
        $detalhes[] = 'SMTP_HOST definido: ' . (defined('SMTP_HOST') && SMTP_HOST ? 'Sim (' . SMTP_HOST . ')' : 'Não — usando mail() nativo');

        if (defined('SMTP_HOST') && SMTP_HOST) {
            $detalhes[] = 'SMTP_PORT: ' . (defined('SMTP_PORT') ? SMTP_PORT : '465 (padrão)');
            $detalhes[] = 'SMTP_SECURE: ' . (defined('SMTP_SECURE') ? SMTP_SECURE : 'ssl (padrão)');
            $detalhes[] = 'SMTP_USER: ' . (defined('SMTP_USER') ? SMTP_USER : '(não definido)');
            $detalhes[] = 'SMTP_FROM: ' . (defined('SMTP_FROM') ? SMTP_FROM : '(não definido)');
        }

        $corpo = '<h2>Teste de e-mail — Belos Cílios</h2>'
               . '<p>Este é um e-mail de teste enviado em ' . date('d/m/Y H:i:s') . '.</p>'
               . '<p>Se você recebeu esta mensagem, o sistema de e-mail está funcionando corretamente.</p>';

        ob_start();
        $ok = enviarEmail($para, 'Teste de e-mail — Belos Cílios', emailHtml('Teste', $corpo));
        $saida = ob_get_clean();

        if ($ok) {
            $resultado  = 'ok';
            $detalhes[] = '✓ enviarEmail() retornou true — e-mail aceito pelo servidor.';
        } else {
            $resultado  = 'erro';
            $detalhes[] = '✗ enviarEmail() retornou false — falha no envio.';
            if ($saida) $detalhes[] = 'Output capturado: ' . $saida;
            $detalhes[] = 'Verifique o error_log do servidor para mais detalhes.';
        }
    }
}

$paginaTitulo = 'Testar E-mail';
$areaAtual    = 'painel';
require_once __DIR__ . '/../geral/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0">Diagnóstico de E-mail</h4>
</div>

<div class="row g-4">
    <div class="col-md-6">
        <div class="card p-4">
            <h6 class="fw-semibold mb-3">Enviar e-mail de teste</h6>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Enviar para</label>
                    <input type="email" name="para" class="form-control"
                           placeholder="seu@email.com" required
                           value="<?= h($_POST['para'] ?? '') ?>">
                    <div class="form-text">Use seu próprio e-mail para confirmar a entrega.</div>
                </div>
                <button class="btn btn-accent w-100">
                    <i class="bi bi-send me-2"></i>Enviar e-mail de teste
                </button>
            </form>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card p-4">
            <h6 class="fw-semibold mb-3">Configuração SMTP detectada</h6>
            <dl class="mb-0 small">
                <dt class="text-secondary">SMTP_HOST</dt>
                <dd class="fw-semibold <?= (defined('SMTP_HOST') && SMTP_HOST) ? 'text-success' : 'text-danger' ?>">
                    <?= (defined('SMTP_HOST') && SMTP_HOST) ? h(SMTP_HOST) : 'Não configurado — usará mail() nativo' ?>
                </dd>
                <?php if (defined('SMTP_HOST') && SMTP_HOST): ?>
                <dt class="text-secondary mt-2">SMTP_PORT</dt>
                <dd class="fw-semibold"><?= defined('SMTP_PORT') ? SMTP_PORT : '465 (padrão)' ?></dd>
                <dt class="text-secondary mt-2">SMTP_SECURE</dt>
                <dd class="fw-semibold"><?= defined('SMTP_SECURE') ? h(SMTP_SECURE) : 'ssl (padrão)' ?></dd>
                <dt class="text-secondary mt-2">SMTP_USER</dt>
                <dd class="fw-semibold"><?= defined('SMTP_USER') ? h(SMTP_USER) : '(não definido)' ?></dd>
                <dt class="text-secondary mt-2">SMTP_FROM</dt>
                <dd class="fw-semibold"><?= defined('SMTP_FROM') ? h(SMTP_FROM) : '(não definido)' ?></dd>
                <?php endif ?>
                <dt class="text-secondary mt-2">enviarEmail() disponível</dt>
                <dd class="fw-semibold <?= function_exists('enviarEmail') ? 'text-success' : 'text-danger' ?>">
                    <?= function_exists('enviarEmail') ? 'Sim' : 'Não — conexao.php do servidor não carrega mailer.php' ?>
                </dd>
                <dt class="text-secondary mt-2">Função mail() disponível</dt>
                <dd class="fw-semibold <?= function_exists('mail') ? 'text-success' : 'text-danger' ?>">
                    <?= function_exists('mail') ? 'Sim' : 'Não' ?>
                </dd>
            </dl>
        </div>
    </div>

    <?php if ($resultado !== null): ?>
    <div class="col-12">
        <div class="alert <?= $resultado === 'ok' ? 'alert-success' : 'alert-danger' ?> mb-0">
            <strong><?= $resultado === 'ok' ? 'Enviado com sucesso' : 'Falha no envio' ?></strong>
            <ul class="mt-2 mb-0 small">
                <?php foreach ($detalhes as $d): ?>
                    <li><?= h($d) ?></li>
                <?php endforeach ?>
            </ul>
        </div>
    </div>
    <?php endif ?>

    <div class="col-12">
        <div class="card p-4">
            <h6 class="fw-semibold mb-3">Como configurar o SMTP (HostGator + TITAN)</h6>
            <ol class="small text-secondary mb-0 lh-lg">
                <li>Você já tem a conta <strong>noreply@beloscilios.com</strong> criada no TITAN.</li>
                <li>No webmail do TITAN, clique em <strong>Configurações → Conectar aplicativos</strong> para ver o hostname SMTP exato.</li>
                <li>Edite <code>config/smtp_keys.php</code> no servidor via <strong>cPanel → Gerenciador de Arquivos</strong>:</li>
            </ol>
            <pre class="mt-3 p-3 rounded small" style="background:rgba(90,24,154,.06);border:1px solid var(--card-border-color);overflow-x:auto;">&lt;?php
// TITAN Email (HostGator) — ajuste o HOST conforme "Conectar aplicativos" no webmail
define('SMTP_HOST',      'smtp.titan.email');   // ou mail.beloscilios.com
define('SMTP_PORT',      587);                  // 587 (STARTTLS) ou 465 (SSL)
define('SMTP_SECURE',    'tls');                // 'tls' para porta 587, 'ssl' para 465
define('SMTP_USER',      'noreply@beloscilios.com');
define('SMTP_PASS',      'SENHA_DO_NOREPLY_AQUI');
define('SMTP_FROM',      'noreply@beloscilios.com');
define('SMTP_FROM_NAME', 'Belos Cílios');</pre>
            <div class="alert alert-warning small mt-3 mb-0 py-2">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <strong>Atenção:</strong> o arquivo <code>smtp_keys.php</code> precisa ser editado diretamente no servidor — ele é ignorado pelo deploy automático para proteger as credenciais.
                Edite também o <code>conexao.php</code> do servidor e confirme que a linha
                <code>require_once __DIR__ . '/mailer.php';</code> está presente logo no início.
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
