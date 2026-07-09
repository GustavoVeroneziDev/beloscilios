<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
if (!empty($_SESSION['usuario_id'])) {
    header('Location: ' . BASE . '/index.php');
    exit;
}

$paginaTitulo = 'Entrar';
$areaAtual    = 'publico';
require_once __DIR__ . '/../geral/header.php';
?>

<div class="row justify-content-center">
    <div class="col-sm-10 col-md-7 col-lg-5 col-xl-4">
        <div class="card p-4 mt-2">
            <div class="text-center mb-4">
                <img src="<?= BASE ?>/geral/img/LogoTransparente.png" alt="Belos Cílios"
                     style="height:64px;width:auto;">
                <h4 class="fw-bold mt-2 mb-0">Entrar na sua conta</h4>
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
                    <label class="form-label fw-medium mb-1" for="senha">Senha</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" id="senha" name="senha" class="form-control"
                               placeholder="••••••••" required autocomplete="current-password">
                        <button class="btn btn-outline-secondary" type="button" id="toggleSenha">
                            <i class="bi bi-eye" id="iconeSenha"></i>
                        </button>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-3 mb-1">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" name="lembrar_me" id="lembrarMe" value="1">
                        <label class="form-check-label small text-secondary" for="lembrarMe">
                            Lembrar-me por 30 dias
                        </label>
                    </div>
                    <a href="#" class="small">Esqueci a senha</a>
                </div>

                <div class="d-grid mt-3">
                    <button type="submit" class="btn btn-accent btn-lg">
                        <i class="bi bi-box-arrow-in-right me-2"></i> Entrar
                    </button>
                </div>
            </form>

            <div class="d-flex align-items-center gap-2 my-3">
                <hr class="flex-grow-1 m-0"><span class="small text-secondary">ou</span><hr class="flex-grow-1 m-0">
            </div>

            <div id="g_id_onload"
                 data-client_id="808511905880-9jd31jmci1m9ibikht6r2vlerjeb8r4l.apps.googleusercontent.com"
                 data-login_uri="https://beloscilios.com/usuario/login_google.php"
                 data-auto_prompt="false"></div>
            <div class="d-flex justify-content-center">
                <div class="g_id_signin"
                     data-type="standard"
                     data-size="large"
                     data-theme="outline"
                     data-text="sign_in_with"
                     data-locale="pt-BR"
                     data-shape="rectangular"
                     data-width="340"></div>
            </div>

            <hr class="my-3">
            <p class="text-center text-secondary small mb-0">
                Ainda não tem conta?
                <a href="<?= BASE ?>/usuario/cadastro.php" class="fw-medium">Cadastre-se grátis</a>
            </p>
        </div>
    </div>
</div>

<script src="https://accounts.google.com/gsi/client" async defer></script>
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
