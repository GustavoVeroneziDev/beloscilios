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

// Anti brute-force simples via sessão
$tentativas = $_SESSION['login_tentativas'] ?? 0;
$ultimaTentativa = $_SESSION['login_ultima'] ?? 0;

if ($tentativas >= 5 && (time() - $ultimaTentativa) < 300) {
    redirecionarComMensagem(BASE . '/usuario/login.php', 'Muitas tentativas. Aguarde 5 minutos.', 'warning');
}

$email = trim($_POST['email'] ?? '');
$senha = $_POST['senha'] ?? '';

if ($email === '' || $senha === '') {
    redirecionarComMensagem(BASE . '/usuario/login.php', 'Preencha e-mail e senha.', 'warning');
}

try {
    $stmt = $pdo->prepare(
        'SELECT IDUsuario, Nome, Email, Senha, NivelAcesso, Ativo, EmailVerificado FROM Usuarios WHERE Email = :email LIMIT 1'
    );
    $stmt->execute([':email' => $email]);
    $usuario = $stmt->fetch();
} catch (PDOException $e) {
    error_log('[Login] ' . $e->getMessage());
    redirecionarComMensagem(BASE . '/usuario/login.php', 'Erro interno. Tente novamente.', 'danger');
}

if (!$usuario || !password_verify($senha, $usuario['Senha'])) {
    $_SESSION['login_tentativas'] = $tentativas + 1;
    $_SESSION['login_ultima']     = time();
    redirecionarComMensagem(
        BASE . '/usuario/login.php',
        'E-mail ou senha incorretos.',
        'danger'
    );
}

if (!$usuario['Ativo']) {
    redirecionarComMensagem(BASE . '/usuario/login.php', 'Conta desativada. Entre em contato.', 'warning');
}

if (!$usuario['EmailVerificado']) {
    $_SESSION['pendente_email'] = $usuario['Email'];
    $_SESSION['pendente_nome']  = $usuario['Nome'];
    header('Location: ' . BASE . '/usuario/aguardando_verificacao.php?motivo=login');
    exit;
}

// Login bem-sucedido
session_regenerate_id(true);
unset($_SESSION['login_tentativas'], $_SESSION['login_ultima']);

$_SESSION['usuario_id']       = $usuario['IDUsuario'];
$_SESSION['usuario_nome']     = $usuario['Nome'];
$_SESSION['nivel_acesso']     = $usuario['NivelAcesso'];
$_SESSION['email_verificado'] = (bool) $usuario['EmailVerificado'];

if (!empty($_POST['lembrar_me'])) {
    criarTokenLembrarMe($pdo, $usuario['IDUsuario']);
}

if ($usuario['NivelAcesso'] === 'designer') {
    header('Location: ' . BASE . '/painel/index.php');
} else {
    header('Location: ' . BASE . '/usuario/perfil.php');
}
exit;
