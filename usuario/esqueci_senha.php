<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
if (!empty($_SESSION['usuario_id'])) {
    header('Location: ' . BASE . '/index.php');
    exit;
}

$paginaTitulo = 'Esqueci a Senha';
$areaAtual    = 'publico';
require_once __DIR__ . '/../geral/header.php';
?>

<div class="row justify-content-center">
    <div class="col-sm-10 col-md-7 col-lg-5 col-xl-4">
        <div class="card p-4 mt-2">
            <div class="text-center mb-4">
                <img src="<?= BASE ?>/geral/img/LogoTransparente.png" alt="Belos Cílios"
                     style="height:64px;width:auto;">
                <h4 class="fw-bold mt-2 mb-1">Esqueci a senha</h4>
                <p class="text-secondary small mb-0">Informe seu e-mail e enviaremos um link para redefinir.</p>
            </div>

            <form action="<?= BASE ?>/usuario/processa_esqueci_senha.php" method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">

                <div class="mb-4">
                    <label class="form-label fw-medium" for="email">E-mail</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" id="email" name="email" class="form-control"
                               placeholder="seu@email.com" required autocomplete="email"
                               value="<?= h($_GET['email'] ?? '') ?>">
                    </div>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-accent btn-lg">
                        <i class="bi bi-send me-2"></i>Enviar link de redefinição
                    </button>
                </div>
            </form>

            <hr class="my-3">
            <p class="text-center text-secondary small mb-0">
                Lembrou a senha?
                <a href="<?= BASE ?>/usuario/login.php" class="fw-medium">Voltar ao login</a>
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
