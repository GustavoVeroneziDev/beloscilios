<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE . '/usuario/esqueci_senha.php');
    exit;
}

if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
    redirecionarComMensagem(BASE . '/usuario/esqueci_senha.php', 'Token inválido. Tente novamente.', 'danger');
}

$email = trim($_POST['email'] ?? '');
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirecionarComMensagem(BASE . '/usuario/esqueci_senha.php', 'Informe um e-mail válido.', 'warning');
}

// Sempre retorna a mesma mensagem para não revelar se o e-mail existe
$msgSucesso = 'Se este e-mail estiver cadastrado, você receberá as instruções em instantes. Verifique também a caixa de spam.';

try {
    $stmt = $pdo->prepare('SELECT IDUsuario, Nome, Ativo FROM Usuarios WHERE Email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $usuario = $stmt->fetch();
} catch (PDOException $e) {
    error_log('[ResetSenha] ' . $e->getMessage());
    redirecionarComMensagem(BASE . '/usuario/esqueci_senha.php', 'Erro interno. Tente novamente.', 'danger');
}

if (!$usuario || !$usuario['Ativo']) {
    // Não revela que o e-mail não existe
    redirecionarComMensagem(BASE . '/usuario/login.php', $msgSucesso, 'success');
}

// Invalida tokens anteriores do usuário e cria um novo
try {
    $pdo->prepare('DELETE FROM TokensResetSenha WHERE FKUsuario = :id')
        ->execute([':id' => $usuario['IDUsuario']]);

    $idToken    = gerarUuid();
    $tokenPlain = bin2hex(random_bytes(32));
    $tokenHash  = hash('sha256', $tokenPlain);
    $expira     = date('Y-m-d H:i:s', strtotime('+1 hour'));

    $pdo->prepare(
        'INSERT INTO TokensResetSenha (IDToken, FKUsuario, TokenHash, Expira)
         VALUES (:id, :fku, :hash, :expira)'
    )->execute([':id' => $idToken, ':fku' => $usuario['IDUsuario'], ':hash' => $tokenHash, ':expira' => $expira]);
} catch (PDOException $e) {
    error_log('[ResetSenha] ' . $e->getMessage());
    redirecionarComMensagem(BASE . '/usuario/esqueci_senha.php', 'Erro interno. Tente novamente.', 'danger');
}

enviarEmailResetSenha($email, $usuario['Nome'], $idToken, $tokenPlain);

redirecionarComMensagem(BASE . '/usuario/login.php', $msgSucesso, 'success');
