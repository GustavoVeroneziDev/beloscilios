<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!empty($_SESSION['usuario_id'])) {
    header('Location: ' . BASE . '/index.php');
    exit;
}
require_once __DIR__ . '/../config/conexao.php';

$paginaTitulo = 'Entrar';
$areaAtual    = 'publico';
require_once __DIR__ . '/../geral/header.php';
?>

<div class="row justify-content-center">
    <div class="col-sm-10 col-md-7 col-lg-5 col-xl-4">
        <div class="card p-4 mt-2">
            <div class="text-center mb-4">
                <div style="font-size:2.5rem;">✨</div>
                <h4 class="fw-bold mt-2 mb-0">Bem-vinda de volta!</h4>
                <p class="text-secondary small">Entre para acessar seus agendamentos</p>
            </div>

            <form action="<?= BASE ?>/usuario/processa_login.php" method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">

                <div class="mb-3">
                    <label class="form-label fw-medium" for="email">E-mail</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" id="email" name="email" class="form-control"
                               placeholder="seu@email.com" required autocomplete="email"
                               value="<?= h($_GET['email'] ?? '') ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label fw-medium mb-0" for="senha">Senha</label>
                        <a href="#" class="small">Esqueci a senha</a>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" id="senha" name="senha" class="form-control"
                               placeholder="••••••••" required autocomplete="current-password">
                        <button class="btn btn-outline-secondary" type="button" id="toggleSenha">
                            <i class="bi bi-eye" id="iconeSenha"></i>
                        </button>
                    </div>
                </div>

                <div class="d-grid mt-4">
                    <button type="submit" class="btn btn-accent btn-lg">
                        <i class="bi bi-box-arrow-in-right me-2"></i> Entrar
                    </button>
                </div>
            </form>

            <hr class="my-4">
            <p class="text-center text-secondary small mb-0">
                Ainda não tem conta?
                <a href="<?= BASE ?>/usuario/cadastro.php" class="fw-medium">Cadastre-se grátis</a>
            </p>
        </div>
    </div>
</div>

<script>
document.getElementById('toggleSenha')?.addEventListener('click', function () {
    const input = document.getElementById('senha');
    const icone = document.getElementById('iconeSenha');
    if (input.type === 'password') {
        input.type = 'text';
        icone.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        icone.className = 'bi bi-eye';
    }
});
</script>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
