<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';

$token = trim($_GET['token'] ?? '');

if (!$token) {
    redirecionarComMensagem(BASE . '/index.php', 'Link inválido.', 'danger');
}

try {
    $stmt = $pdo->prepare(
        'SELECT IDUsuario, Nome FROM Usuarios
         WHERE TokenVerificacao = :t AND TokenVerificacaoExpira > NOW() LIMIT 1'
    );
    $stmt->execute([':t' => $token]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        redirecionarComMensagem(
            BASE . '/index.php',
            'Link inválido ou expirado. Faça login e solicite um novo link.',
            'warning'
        );
    }

    $pdo->prepare(
        'UPDATE Usuarios
         SET EmailVerificado = 1, TokenVerificacao = NULL, TokenVerificacaoExpira = NULL
         WHERE IDUsuario = :id'
    )->execute([':id' => $usuario['IDUsuario']]);

    // Atualiza sessão se for o próprio usuário logado
    if (estaLogado() && $_SESSION['usuario_id'] === $usuario['IDUsuario']) {
        $_SESSION['email_verificado'] = true;
    }

    redirecionarComMensagem(
        BASE . '/agendamento/index.php',
        'E-mail verificado com sucesso! Seus lembretes estão ativados.',
        'success'
    );
} catch (PDOException $e) {
    error_log('[VerificarEmail] ' . $e->getMessage());
    redirecionarComMensagem(BASE . '/index.php', 'Erro ao verificar. Tente novamente.', 'danger');
}
