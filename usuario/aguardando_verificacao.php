<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';

// Usuário logado e já verificado não tem nada para fazer aqui
if (estaLogado() && !empty($_SESSION['email_verificado'])) {
    header('Location: ' . BASE . '/usuario/perfil.php');
    exit;
}

// Precisa ter e-mail pendente na sessão — ou ser usuário logado não-verificado
$emailExibir = '';
$nomeExibir  = '';

if (!empty($_SESSION['pendente_email'])) {
    $emailExibir = $_SESSION['pendente_email'];
    $nomeExibir  = $_SESSION['pendente_nome'] ?? '';
} elseif (estaLogado()) {
    // Logado mas não verificado (fluxo raro, mas possível)
    $emailExibir = ''; // não sabemos sem consulta extra
} else {
    // Sem contexto nenhum → manda para o login
    header('Location: ' . BASE . '/usuario/login.php');
    exit;
}

$motivoBloqueio = isset($_GET['motivo']) && $_GET['motivo'] === 'login';

$paginaTitulo = 'Verifique seu e-mail';
$areaAtual    = 'publico';
require_once __DIR__ . '/../geral/header.php';
?>

<style>
.verif-wrap {
    min-height: calc(100vh - 160px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem 1rem;
}
.verif-card {
    background: var(--bg-card, #fff);
    border: 1px solid var(--card-border-color, #e0aaff);
    border-radius: 20px;
    box-shadow: 0 4px 32px rgba(16,0,43,.09);
    padding: 3rem 2.5rem;
    max-width: 480px;
    width: 100%;
    text-align: center;
}
.verif-icon-wrap {
    width: 88px; height: 88px;
    border-radius: 50%;
    background: linear-gradient(135deg, rgba(90,24,154,.12), rgba(157,78,221,.08));
    border: 2px solid rgba(90,24,154,.18);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 1.75rem;
}
.verif-icon-wrap i { font-size: 2.6rem; color: var(--accent, #5a189a); }
.verif-email-pill {
    display: inline-block;
    background: rgba(90,24,154,.07);
    border: 1px solid rgba(90,24,154,.18);
    border-radius: 30px;
    padding: .35rem 1.1rem;
    font-size: .88rem;
    font-weight: 600;
    color: var(--accent, #5a189a);
    margin: .75rem 0 1.75rem;
    word-break: break-all;
}
.verif-steps {
    background: rgba(90,24,154,.04);
    border-radius: 12px;
    padding: 1.1rem 1.25rem;
    margin-bottom: 1.75rem;
    text-align: left;
}
.verif-step {
    display: flex;
    align-items: flex-start;
    gap: .75rem;
    font-size: .85rem;
    color: var(--text-secondary, #6c757d);
}
.verif-step + .verif-step { margin-top: .65rem; }
.verif-step-num {
    flex-shrink: 0;
    width: 22px; height: 22px;
    border-radius: 50%;
    background: var(--accent, #5a189a);
    color: #fff;
    font-size: .7rem;
    font-weight: 800;
    display: flex; align-items: center; justify-content: center;
    margin-top: .05rem;
}
</style>

<div class="verif-wrap">
    <div class="verif-card">

        <?php flashMsg() ?>

        <!-- Ícone -->
        <div class="verif-icon-wrap">
            <i class="bi bi-envelope-open"></i>
        </div>

        <!-- Título -->
        <?php if ($motivoBloqueio): ?>
            <h4 class="fw-bold mb-1">Conta não ativada</h4>
            <p class="text-secondary small mb-0">
                Para acessar o sistema você precisa confirmar seu e-mail primeiro.
            </p>
        <?php else: ?>
            <h4 class="fw-bold mb-1">Confirme seu e-mail</h4>
            <p class="text-secondary small mb-0">
                Enviamos um link de ativação para:
            </p>
        <?php endif ?>

        <?php if ($emailExibir): ?>
            <div class="verif-email-pill">
                <i class="bi bi-envelope me-1"></i><?= h($emailExibir) ?>
            </div>
        <?php else: ?>
            <div style="height:1.5rem"></div>
        <?php endif ?>

        <!-- Passos -->
        <div class="verif-steps">
            <div class="verif-step">
                <div class="verif-step-num">1</div>
                <span>Abra seu e-mail e procure a mensagem de <strong>Belos Cílios</strong>.</span>
            </div>
            <div class="verif-step">
                <div class="verif-step-num">2</div>
                <span>Clique em <strong>"Verificar e-mail"</strong> — o link é válido por 24 horas.</span>
            </div>
            <div class="verif-step">
                <div class="verif-step-num">3</div>
                <span>Pronto! Você será redirecionada automaticamente.</span>
            </div>
        </div>

        <!-- Ações -->
        <div class="d-grid gap-2">
            <a href="<?= BASE ?>/usuario/reenviar_verificacao.php" class="btn btn-accent">
                <i class="bi bi-arrow-repeat me-2"></i>Reenviar e-mail de verificação
            </a>
            <a href="<?= BASE ?>/usuario/login.php" class="btn btn-outline-secondary">
                <i class="bi bi-box-arrow-in-right me-2"></i>Já verifiquei — ir para o login
            </a>
        </div>

        <p class="text-secondary mt-3 mb-0" style="font-size:.78rem;">
            Não recebeu? Verifique a pasta de <strong>spam</strong> ou lixo eletrônico.
        </p>

    </div>
</div>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
