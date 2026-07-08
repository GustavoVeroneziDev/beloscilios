<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!empty($_SESSION['usuario_id'])) {
    header('Location: ' . BASE . '/index.php');
    exit;
}
require_once __DIR__ . '/../config/conexao.php';

$paginaTitulo = 'Criar Conta';
$areaAtual    = 'publico';
require_once __DIR__ . '/../geral/header.php';
?>

<div class="row justify-content-center">
    <div class="col-sm-10 col-md-8 col-lg-6 col-xl-5">
        <div class="card p-4 mt-2">
            <div class="text-center mb-4">
                <i class="bi bi-flower1 text-accent" style="font-size:2.5rem;"></i>
                <h4 class="fw-bold mt-2 mb-0">Criar minha conta</h4>
                <p class="text-secondary small">Cadastre-se e agende online com facilidade</p>
            </div>

            <form action="<?= BASE ?>/usuario/processa_cadastro.php" method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">

                <div class="mb-3">
                    <label class="form-label fw-medium" for="nome">Nome completo</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" id="nome" name="nome" class="form-control"
                               placeholder="Seu nome" required autocomplete="name"
                               value="<?= h($_GET['nome'] ?? '') ?>">
                    </div>
                </div>

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
                    <label class="form-label fw-medium" for="telefone">
                        WhatsApp <span class="text-secondary">(para lembretes)</span>
                    </label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-whatsapp"></i></span>
                        <input type="tel" id="telefone" name="telefone" class="form-control"
                               placeholder="(11) 99999-9999" autocomplete="tel">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-medium" for="senha">Senha</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" id="senha" name="senha" class="form-control"
                               placeholder="Mínimo 4 caracteres" required minlength="4">
                        <button class="btn btn-outline-secondary" type="button" id="toggleSenha">
                            <i class="bi bi-eye" id="iconeSenha"></i>
                        </button>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-medium" for="senha_conf">Confirmar senha</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                        <input type="password" id="senha_conf" name="senha_conf" class="form-control"
                               placeholder="Repita a senha" required>
                    </div>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-accent btn-lg">
                        <i class="bi bi-person-plus me-2"></i> Criar conta
                    </button>
                </div>
            </form>

            <div class="d-flex align-items-center gap-2 my-3">
                <hr class="flex-grow-1 m-0"><span class="small text-secondary">ou cadastre com</span><hr class="flex-grow-1 m-0">
            </div>

            <div id="g_id_onload"
                 data-client_id="<?= defined('GOOGLE_CLIENT_ID') ? h(GOOGLE_CLIENT_ID) : '' ?>"
                 data-callback="handleGoogleCredential"
                 data-auto_prompt="false">
            </div>
            <div class="d-flex justify-content-center">
                <div class="g_id_signin"
                     data-type="standard"
                     data-size="large"
                     data-theme="outline"
                     data-text="continue_with"
                     data-shape="rectangular"
                     data-width="360">
                </div>
            </div>

            <hr class="my-3">
            <p class="text-center text-secondary small mb-0">
                Já tem conta?
                <a href="<?= BASE ?>/usuario/login.php" class="fw-medium">Entrar</a>
            </p>
        </div>
    </div>
</div>

<script src="https://accounts.google.com/gsi/client" async defer></script>
<script>
function handleGoogleCredential(response) {
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?= BASE ?>/usuario/callback_google.php';
    var inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = 'credential'; inp.value = response.credential;
    form.appendChild(inp);
    document.body.appendChild(form);
    form.submit();
}

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
