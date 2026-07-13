<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('cliente');

$uid = $_SESSION['usuario_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
        redirecionarComMensagem(BASE . '/usuario/editar_perfil.php', 'Token inválido.', 'danger');
    }

    $nome      = trim($_POST['nome']      ?? '');
    $telefone  = trim($_POST['telefone']  ?? '');
    $senhaAtual = $_POST['senha_atual']   ?? '';
    $novaSenha  = $_POST['nova_senha']    ?? '';
    $novaSenhaC = $_POST['nova_senha_c']  ?? '';

    if ($nome === '') {
        redirecionarComMensagem(BASE . '/usuario/editar_perfil.php', 'Nome é obrigatório.', 'warning');
    }

    try {
        $usuario = $pdo->prepare('SELECT Senha, Telefone FROM Usuarios WHERE IDUsuario = :id LIMIT 1');
        $usuario->execute([':id' => $uid]);
        $usuario = $usuario->fetch();
        $telefoneAntigo = $usuario['Telefone'] ?? null;

        $params = [':nome' => $nome, ':tel' => sanitizarTelefone($telefone), ':id' => $uid];

        $temSenha = !empty($usuario['Senha']);

        // Trocar senha se informada
        if ($novaSenha !== '') {
            if ($temSenha && !password_verify($senhaAtual, $usuario['Senha'])) {
                redirecionarComMensagem(BASE . '/usuario/editar_perfil.php', 'Senha atual incorreta.', 'danger');
            }
            if (strlen($novaSenha) < 4) {
                redirecionarComMensagem(BASE . '/usuario/editar_perfil.php', 'A nova senha deve ter ao menos 4 caracteres.', 'warning');
            }
            if ($novaSenha !== $novaSenhaC) {
                redirecionarComMensagem(BASE . '/usuario/editar_perfil.php', 'As senhas não coincidem.', 'warning');
            }
            $params[':senha'] = password_hash($novaSenha, PASSWORD_DEFAULT);
            $pdo->prepare(
                'UPDATE Usuarios SET Nome=:nome, Telefone=:tel, Senha=:senha WHERE IDUsuario=:id'
            )->execute($params);
        } else {
            $pdo->prepare(
                'UPDATE Usuarios SET Nome=:nome, Telefone=:tel WHERE IDUsuario=:id'
            )->execute($params);
        }

        $_SESSION['usuario_nome'] = $nome;

        // Boas-vindas se a cliente acabou de cadastrar o número pela primeira vez
        $novoTel = sanitizarTelefone($telefone);
        if ($novoTel && !$telefoneAntigo) {
            try {
                $stmtNome = $pdo->prepare('SELECT Nome FROM Usuarios WHERE IDUsuario = :id LIMIT 1');
                $stmtNome->execute([':id' => $uid]);
                $nomeCompleto = $stmtNome->fetchColumn();
                $primeiroNome = explode(' ', trim((string)$nomeCompleto))[0];
                $tpl = getConfig($pdo, 'msg_boas_vindas', '');
                if ($tpl) {
                    $msg = str_replace('{nome}', $primeiroNome, $tpl);
                    $ok  = enviarWhatsApp($novoTel, $msg);
                    registrarLogWhatsApp($pdo, $novoTel, $msg, 'boas_vindas', $ok ? 'enviado' : 'erro', null);
                }
            } catch (\Throwable $e) {
                error_log('[EditarPerfil][WhatsApp] ' . $e->getMessage());
            }
        }

        redirecionarComMensagem(BASE . '/usuario/perfil.php', 'Dados atualizados com sucesso!', 'success');
    } catch (PDOException $e) {
        error_log('[EditarPerfil] ' . $e->getMessage());
        redirecionarComMensagem(BASE . '/usuario/editar_perfil.php', 'Erro ao salvar.', 'danger');
    }
}

$usuario = $pdo->prepare('SELECT * FROM Usuarios WHERE IDUsuario = :id LIMIT 1');
$usuario->execute([':id' => $uid]);
$usuario = $usuario->fetch();

$paginaTitulo = 'Editar Perfil';
$areaAtual    = 'cliente';
require_once __DIR__ . '/../geral/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="d-flex align-items-center gap-2 mb-4">
            <a href="<?= BASE ?>/usuario/perfil.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i>
            </a>
            <h4 class="fw-bold mb-0">Editar perfil</h4>
        </div>

        <div class="card p-4">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">

                <div class="mb-3">
                    <label class="form-label fw-medium">Nome completo *</label>
                    <input type="text" name="nome" class="form-control"
                           value="<?= h($usuario['Nome']) ?>" required maxlength="100">
                </div>

                <div class="mb-3">
                    <label class="form-label fw-medium">E-mail</label>
                    <input type="email" class="form-control" value="<?= h($usuario['Email']) ?>" disabled>
                    <div class="form-text">O e-mail não pode ser alterado.</div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-medium">WhatsApp</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-whatsapp"></i></span>
                        <input type="tel" name="telefone" class="form-control"
                               value="<?= h(formatarTelefoneExibicao($usuario['Telefone'] ?? '')) ?>"
                               placeholder="(11) 99999-9999"
                               data-mask="tel" maxlength="15">
                    </div>
                </div>

                <?php $temSenha = !empty($usuario['Senha']); ?>
                <hr>
                <?php if ($temSenha): ?>
                    <h6 class="fw-semibold mb-3">Alterar senha <span class="text-secondary fw-normal">(opcional)</span></h6>
                    <div class="mb-3">
                        <label class="form-label">Senha atual</label>
                        <input type="password" name="senha_atual" class="form-control"
                               placeholder="Deixe em branco para não alterar" maxlength="72">
                    </div>
                <?php else: ?>
                    <h6 class="fw-semibold mb-1">Criar senha <span class="text-secondary fw-normal">(opcional)</span></h6>
                    <p class="text-secondary small mb-3">
                        Você entrou com o Google e ainda não tem senha.
                        Defina uma para poder acessar também por e-mail e senha.
                    </p>
                <?php endif ?>
                <div class="mb-3">
                    <label class="form-label">Nova senha</label>
                    <input type="password" name="nova_senha" class="form-control"
                           placeholder="Mínimo 4 caracteres" minlength="4" maxlength="72">
                </div>
                <div class="mb-4">
                    <label class="form-label">Confirmar nova senha</label>
                    <input type="password" name="nova_senha_c" class="form-control"
                           placeholder="Repita a nova senha" maxlength="72">
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-accent btn-lg">
                        <i class="bi bi-save me-2"></i> Salvar alterações
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
