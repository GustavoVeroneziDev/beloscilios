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
                <i class="bi bi-stars text-accent" style="font-size:2.5rem;"></i>
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

            <div class="d-flex align-items-center gap-2 my-3">
                <hr class="flex-grow-1 m-0"><span class="small text-secondary">ou</span><hr class="flex-grow-1 m-0">
            </div>
            <a href="<?= BASE ?>/usuario/login_google.php"
               class="btn btn-outline-secondary w-100 d-flex align-items-center justify-content-center gap-2">
                <svg width="18" height="18" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
                    <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                    <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                    <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                    <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.18 1.48-4.97 2.31-8.16 2.31-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                </svg>
                Entrar com Google
            </a>

            <hr class="my-3">
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
