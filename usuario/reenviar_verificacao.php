<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('cliente');

$uid = $_SESSION['usuario_id'];

try {
    $stmt = $pdo->prepare(
        'SELECT Nome, Email, EmailVerificado FROM Usuarios WHERE IDUsuario = :id LIMIT 1'
    );
    $stmt->execute([':id' => $uid]);
    $usuario = $stmt->fetch();

    if (!$usuario || $usuario['EmailVerificado']) {
        $_SESSION['email_verificado'] = true;
        redirecionarComMensagem(BASE . '/agendamento/index.php', 'E-mail já verificado.', 'info');
    }

    // Rate limit: não reenvia se enviou nos últimos 5 minutos
    $chkStmt = $pdo->prepare(
        'SELECT TokenVerificacaoExpira FROM Usuarios WHERE IDUsuario = :id LIMIT 1'
    );
    $chkStmt->execute([':id' => $uid]);
    $expiraAtual = $chkStmt->fetchColumn();

    if ($expiraAtual && strtotime($expiraAtual) > strtotime('+23 hours')) {
        redirecionarComMensagem(
            BASE . '/agendamento/index.php',
            'Aguarde alguns minutos antes de solicitar outro link.',
            'warning'
        );
    }

    $token  = bin2hex(random_bytes(32));
    $expira = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $pdo->prepare(
        'UPDATE Usuarios SET TokenVerificacao = :t, TokenVerificacaoExpira = :e WHERE IDUsuario = :id'
    )->execute([':t' => $token, ':e' => $expira, ':id' => $uid]);

    enviarEmailVerificacao($usuario['Email'], $usuario['Nome'], $token);

    redirecionarComMensagem(
        BASE . '/agendamento/index.php',
        'Link de verificação reenviado para ' . h($usuario['Email']) . '.',
        'success'
    );
} catch (PDOException $e) {
    error_log('[ReenviarVerif] ' . $e->getMessage());
    redirecionarComMensagem(BASE . '/agendamento/index.php', 'Erro ao reenviar. Tente novamente.', 'danger');
}
