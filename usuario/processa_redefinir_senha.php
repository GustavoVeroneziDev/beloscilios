<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE . '/usuario/login.php');
    exit;
}

if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
    redirecionarComMensagem(BASE . '/usuario/login.php', 'Token inválido. Tente novamente.', 'danger');
}

$idToken    = trim($_POST['id'] ?? '');
$tokenPlain = trim($_POST['t']  ?? '');
$novaSenha  = $_POST['nova_senha']      ?? '';
$confirmar  = $_POST['confirmar_senha'] ?? '';

// Valida presença dos campos
if ($idToken === '' || $tokenPlain === '' || $novaSenha === '' || $confirmar === '') {
    redirecionarComMensagem(BASE . '/usuario/login.php', 'Dados incompletos. Solicite um novo link.', 'warning');
}

if (strlen($novaSenha) < 4) {
    redirecionarComMensagem(
        BASE . '/usuario/redefinir_senha.php?id=' . urlencode($idToken) . '&t=' . urlencode($tokenPlain),
        'A senha deve ter pelo menos 4 caracteres.',
        'warning'
    );
}

if ($novaSenha !== $confirmar) {
    redirecionarComMensagem(
        BASE . '/usuario/redefinir_senha.php?id=' . urlencode($idToken) . '&t=' . urlencode($tokenPlain),
        'As senhas não coincidem.',
        'warning'
    );
}

// Revalida o token
try {
    $stmt = $pdo->prepare(
        'SELECT IDToken, FKUsuario, TokenHash, Expira FROM TokensResetSenha
         WHERE IDToken = :id AND Expira > NOW()
         LIMIT 1'
    );
    $stmt->execute([':id' => $idToken]);
    $row = $stmt->fetch();
} catch (PDOException $e) {
    error_log('[ResetSenha] ' . $e->getMessage());
    redirecionarComMensagem(BASE . '/usuario/login.php', 'Erro interno. Tente novamente.', 'danger');
}

if (!$row || !hash_equals($row['TokenHash'], hash('sha256', $tokenPlain))) {
    redirecionarComMensagem(BASE . '/usuario/esqueci_senha.php', 'Link inválido ou expirado. Solicite um novo.', 'danger');
}

// Atualiza senha e remove o token (uso único)
try {
    $pdo->beginTransaction();

    $pdo->prepare('UPDATE Usuarios SET Senha = :senha, AtualizadoEm = NOW() WHERE IDUsuario = :id')
        ->execute([':senha' => password_hash($novaSenha, PASSWORD_DEFAULT), ':id' => $row['FKUsuario']]);

    $pdo->prepare('DELETE FROM TokensResetSenha WHERE IDToken = :id')
        ->execute([':id' => $idToken]);

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('[ResetSenha] ' . $e->getMessage());
    redirecionarComMensagem(BASE . '/usuario/login.php', 'Erro ao salvar a senha. Tente novamente.', 'danger');
}

// Invalida sessão e cookies lembrar-me ativos desse usuário
if (!empty($_COOKIE['bc_lembrar'])) {
    _limparCookieLembrarMe();
}
try {
    $pdo->prepare('DELETE FROM TokensLembrarMe WHERE FKUsuario = :id')
        ->execute([':id' => $row['FKUsuario']]);
} catch (PDOException) {}

header('Location: ' . BASE . '/usuario/login.php');
exit;
