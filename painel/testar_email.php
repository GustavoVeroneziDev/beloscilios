<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/conexao.php';
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
            <h6 class="fw-semibold mb-3">Como configurar o SMTP no HostGator</h6>
            <ol class="small text-secondary mb-0 lh-lg">
                <li>Acesse o <strong>cPanel do HostGator</strong> → <em>E-mail</em> → <em>Contas de E-mail</em></li>
                <li>Crie (ou anote) uma conta como <strong>noreply@beloscilios.com</strong></li>
                <li>Edite o arquivo <code>config/smtp_keys.php</code> no servidor (via Gerenciador de Arquivos do cPanel ou FTP) com o conteúdo abaixo:</li>
            </ol>
            <pre class="mt-3 p-3 rounded small" style="background:rgba(90,24,154,.06);border:1px solid var(--card-border-color);overflow-x:auto;">&lt;?php
define('SMTP_HOST',      'mail.beloscilios.com'); // ou o hostname do servidor
define('SMTP_PORT',      465);
define('SMTP_SECURE',    'ssl');
define('SMTP_USER',      'noreply@beloscilios.com');
define('SMTP_PASS',      'SUA_SENHA_AQUI');
define('SMTP_FROM',      'noreply@beloscilios.com');
define('SMTP_FROM_NAME', 'Belos Cílios');</pre>
            <p class="text-secondary small mt-2 mb-0">
                <i class="bi bi-info-circle me-1"></i>
                O hostname pode ser encontrado em cPanel → <em>Contas de E-mail</em> → <em>Conectar Dispositivos</em>.
                Geralmente é <code>mail.seudominio.com</code> ou o hostname do servidor HostGator.
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
