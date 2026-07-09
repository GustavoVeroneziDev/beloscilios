<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';

// Aceita usuário logado OU pendente na sessão (não-logado recém-cadastrado / bloqueado no login)
if (estaLogado()) {
    $uid = $_SESSION['usuario_id'];
} elseif (!empty($_SESSION['pendente_email'])) {
    // Busca o ID pelo e-mail pendente
    try {
        $find = $pdo->prepare('SELECT IDUsuario FROM Usuarios WHERE Email = :e AND EmailVerificado = 0 LIMIT 1');
        $find->execute([':e' => $_SESSION['pendente_email']]);
        $uid = $find->fetchColumn() ?: null;
    } catch (PDOException) {
        $uid = null;
    }
} else {
    $uid = null;
}

if (!$uid) {
    redirecionarComMensagem(BASE . '/usuario/login.php', 'Sessão expirada. Faça login novamente.', 'warning');
}

try {
    $stmt = $pdo->prepare(
        'SELECT Nome, Email, EmailVerificado, TokenVerificacaoExpira FROM Usuarios WHERE IDUsuario = :id LIMIT 1'
    );
    $stmt->execute([':id' => $uid]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        redirecionarComMensagem(BASE . '/usuario/login.php', 'Usuário não encontrado.', 'danger');
    }

    if ($usuario['EmailVerificado']) {
        if (estaLogado()) {
            $_SESSION['email_verificado'] = true;
        }
        redirecionarComMensagem(BASE . '/agendamento/index.php', 'E-mail já verificado!', 'info');
    }

    // Rate limit: 30 segundos (sincronizado com o countdown da UI)
    $ultimoEnvio = (int)($_SESSION['pendente_email_enviado_em'] ?? 0);
    if ($ultimoEnvio && (time() - $ultimoEnvio) < 30) {
        header('Location: ' . BASE . '/usuario/aguardando_verificacao.php');
        exit;
    }

    $token  = bin2hex(random_bytes(32));
    $expira = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $pdo->prepare(
        'UPDATE Usuarios SET TokenVerificacao = :t, TokenVerificacaoExpira = :e WHERE IDUsuario = :id'
    )->execute([':t' => $token, ':e' => $expira, ':id' => $uid]);

    // Mantém a sessão pendente atualizada
    $_SESSION['pendente_email']          = $usuario['Email'];
    $_SESSION['pendente_nome']           = $usuario['Nome'];
    $_SESSION['pendente_email_enviado_em'] = time();

    try {
        $emailOk = enviarEmailVerificacao($usuario['Email'], $usuario['Nome'], $token);
        if (!$emailOk) {
            error_log('[ReenviarVerif][Email] enviarEmail() retornou false para ' . $usuario['Email']);
        }
    } catch (\Throwable $e) {
        error_log('[ReenviarVerif][Email] ' . $e->getMessage());
    }

    redirecionarComMensagem(
        BASE . '/usuario/aguardando_verificacao.php',
        'Link reenviado para ' . h($usuario['Email']) . '. Verifique também o spam.',
        'success'
    );
} catch (PDOException $e) {
    error_log('[ReenviarVerif] ' . $e->getMessage());
    redirecionarComMensagem(BASE . '/usuario/aguardando_verificacao.php', 'Erro ao reenviar. Tente novamente.', 'danger');
}
