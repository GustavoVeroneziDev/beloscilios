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

    // Busca nível de acesso para montar a sessão
    $row = $pdo->prepare('SELECT NivelAcesso FROM Usuarios WHERE IDUsuario = :id LIMIT 1');
    $row->execute([':id' => $usuario['IDUsuario']]);
    $nivel = $row->fetchColumn() ?: 'cliente';

    // Auto-login: conta verificada, entra direto
    session_regenerate_id(true);
    $_SESSION['usuario_id']       = $usuario['IDUsuario'];
    $_SESSION['usuario_nome']     = $usuario['Nome'];
    $_SESSION['nivel_acesso']     = $nivel;
    $_SESSION['email_verificado'] = true;
    unset($_SESSION['pendente_email'], $_SESSION['pendente_nome']);

    if ($nivel === 'designer') {
        redirecionarComMensagem(BASE . '/painel/index.php', 'E-mail verificado! Seja bem-vinda.', 'success');
    }
    redirecionarComMensagem(BASE . '/agendamento/index.php', 'E-mail verificado! Agora você pode agendar.', 'success');
} catch (PDOException $e) {
    error_log('[VerificarEmail] ' . $e->getMessage());
    redirecionarComMensagem(BASE . '/index.php', 'Erro ao verificar. Tente novamente.', 'danger');
}
