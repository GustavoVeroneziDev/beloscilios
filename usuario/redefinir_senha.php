<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';

$idToken    = $_GET['id'] ?? '';
$tokenPlain = $_GET['t']  ?? '';
$tokenValido = false;

if ($idToken !== '' && $tokenPlain !== '') {
    try {
        $stmt = $pdo->prepare(
            'SELECT IDToken, FKUsuario, TokenHash, Expira FROM TokensResetSenha
             WHERE IDToken = :id AND Expira > NOW()
             LIMIT 1'
        );
        $stmt->execute([':id' => $idToken]);
        $row = $stmt->fetch();

        if ($row && hash_equals($row['TokenHash'], hash('sha256', $tokenPlain))) {
            $tokenValido = true;
        }
    } catch (PDOException $e) {
        error_log('[ResetSenha] ' . $e->getMessage());
    }
}

$paginaTitulo = 'Redefinir Senha';
$areaAtual    = 'publico';
require_once __DIR__ . '/../geral/header.php';
?>

<div class="row justify-content-center">
    <div class="col-sm-10 col-md-7 col-lg-5 col-xl-4">
        <div class="card p-4 mt-2">
            <div class="text-center mb-4">
                <img src="<?= BASE ?>/geral/img/LogoTransparente.png" alt="Belos Cílios"
                     style="height:64px;width:auto;">
                <h4 class="fw-bold mt-2 mb-1">Redefinir senha</h4>
            </div>

            <?php if (!$tokenValido): ?>
                <div class="alert alert-danger text-center">
                    <i class="bi bi-x-circle me-2"></i>
                    Este link é inválido ou já expirou.<br>
                    <a href="<?= BASE ?>/usuario/esqueci_senha.php" class="alert-link">Solicitar novo link</a>
                </div>
            <?php else: ?>
                <form action="<?= BASE ?>/usuario/processa_redefinir_senha.php" method="POST" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                    <input type="hidden" name="id" value="<?= h($idToken) ?>">
                    <input type="hidden" name="t"  value="<?= h($tokenPlain) ?>">

                    <div class="mb-3">
                        <label class="form-label fw-medium" for="nova_senha">Nova senha</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" id="nova_senha" name="nova_senha" class="form-control"
                                   placeholder="Mínimo 8 caracteres" required autocomplete="new-password"
                                   minlength="8">
                            <button class="btn btn-outline-secondary" type="button" id="toggleNova">
                                <i class="bi bi-eye" id="iconeNova"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-medium" for="confirmar_senha">Confirmar senha</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                            <input type="password" id="confirmar_senha" name="confirmar_senha" class="form-control"
                                   placeholder="Repita a senha" required autocomplete="new-password">
                            <button class="btn btn-outline-secondary" type="button" id="toggleConfirm">
                                <i class="bi bi-eye" id="iconeConfirm"></i>
                            </button>
                        </div>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-accent btn-lg">
                            <i class="bi bi-check-circle me-2"></i>Salvar nova senha
                        </button>
                    </div>
                </form>
            <?php endif ?>

            <hr class="my-3">
            <p class="text-center text-secondary small mb-0">
                <a href="<?= BASE ?>/usuario/login.php" class="fw-medium">Voltar ao login</a>
            </p>
        </div>
    </div>
</div>

<script>
function toggleSenha(btnId, inputId, iconeId) {
    document.getElementById(btnId)?.addEventListener('click', function () {
        const input = document.getElementById(inputId);
        const icone = document.getElementById(iconeId);
        if (input.type === 'password') {
            input.type = 'text';
            icone.className = 'bi bi-eye-slash';
        } else {
            input.type = 'password';
            icone.className = 'bi bi-eye';
        }
    });
}
toggleSenha('toggleNova',    'nova_senha',      'iconeNova');
toggleSenha('toggleConfirm', 'confirmar_senha', 'iconeConfirm');
</script>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
