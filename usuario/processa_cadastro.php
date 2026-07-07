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

if (strlen($senha) < 8) {
    redirecionarComMensagem(BASE . '/usuario/cadastro.php', 'A senha deve ter pelo menos 8 caracteres.', 'warning');
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

    $id   = gerarUuid();
    $hash = password_hash($senha, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare(
        'INSERT INTO Usuarios (IDUsuario, Nome, Email, Telefone, Senha, NivelAcesso)
         VALUES (:id, :nome, :email, :tel, :senha, :nivel)'
    );
    $stmt->execute([
        ':id'    => $id,
        ':nome'  => $nome,
        ':email' => $email,
        ':tel'   => $telefoneFmt,
        ':senha' => $hash,
        ':nivel' => 'cliente',
    ]);
} catch (PDOException $e) {
    error_log('[Cadastro] ' . $e->getMessage());
    redirecionarComMensagem(BASE . '/usuario/cadastro.php', 'Erro ao criar conta. Tente novamente.', 'danger');
}

// Login automático após cadastro
session_regenerate_id(true);
$_SESSION['usuario_id']   = $id;
$_SESSION['usuario_nome'] = $nome;
$_SESSION['nivel_acesso'] = 'cliente';

redirecionarComMensagem(
    BASE . '/agendamento/index.php',
    'Conta criada com sucesso! Que tal agendar agora? 🌸',
    'success'
);
