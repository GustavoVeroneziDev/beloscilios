<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/conexao.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE . '/usuario/cadastro.php');
    exit;
}

if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
    redirecionarComMensagem(BASE . '/usuario/cadastro.php', 'Token inválido. Tente novamente.', 'danger');
}

$nome     = trim($_POST['nome']     ?? '');
$email    = trim($_POST['email']    ?? '');
$telefone = trim($_POST['telefone'] ?? '');
$senha    = $_POST['senha']         ?? '';
$senhaCf  = $_POST['senha_conf']    ?? '';

if ($nome === '' || $email === '' || $senha === '') {
    redirecionarComMensagem(BASE . '/usuario/cadastro.php', 'Preencha todos os campos obrigatórios.', 'warning');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirecionarComMensagem(BASE . '/usuario/cadastro.php', 'E-mail inválido.', 'warning');
}

if (strlen($senha) < 4) {
    redirecionarComMensagem(BASE . '/usuario/cadastro.php', 'A senha deve ter pelo menos 4 caracteres.', 'warning');
}

if ($senha !== $senhaCf) {
    redirecionarComMensagem(BASE . '/usuario/cadastro.php', 'As senhas não coincidem.', 'warning');
}

$telefoneFmt = $telefone !== '' ? sanitizarTelefone($telefone) : null;

try {
    $check = $pdo->prepare('SELECT IDUsuario FROM Usuarios WHERE Email = :email LIMIT 1');
    $check->execute([':email' => $email]);
    if ($check->fetch()) {
        redirecionarComMensagem(BASE . '/usuario/cadastro.php', 'E-mail já cadastrado.', 'warning');
    }

    $id     = gerarUuid();
    $hash   = password_hash($senha, PASSWORD_DEFAULT);
    $tokenV = bin2hex(random_bytes(32));
    $expira = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $stmt = $pdo->prepare(
        'INSERT INTO Usuarios
            (IDUsuario, Nome, Email, Telefone, Senha, NivelAcesso,
             EmailVerificado, TokenVerificacao, TokenVerificacaoExpira)
         VALUES (:id, :nome, :email, :tel, :senha, :nivel, 0, :token, :expira)'
    );
    $stmt->execute([
        ':id'     => $id,
        ':nome'   => $nome,
        ':email'  => $email,
        ':tel'    => $telefoneFmt,
        ':senha'  => $hash,
        ':nivel'  => 'cliente',
        ':token'  => $tokenV,
        ':expira' => $expira,
    ]);

} catch (PDOException $e) {
    error_log('[Cadastro] ' . $e->getMessage());
    redirecionarComMensagem(BASE . '/usuario/cadastro.php', 'Erro ao criar conta. Tente novamente.', 'danger');
}

// Envia e-mail de verificação — fora do try do PDO para não bloquear cadastro se falhar
try {
    enviarEmailVerificacao($email, $nome, $tokenV);
} catch (\Throwable $e) {
    error_log('[Cadastro][Email] ' . $e->getMessage());
}

// Guarda na sessão para a página de aguardo (sem fazer login ainda)
$_SESSION['pendente_email'] = $email;
$_SESSION['pendente_nome']  = $nome;

header('Location: ' . BASE . '/usuario/aguardando_verificacao.php');
exit;
