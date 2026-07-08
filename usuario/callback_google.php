<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/google_oauth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['credential'])) {
    redirecionarComMensagem(BASE . '/usuario/login.php', 'Requisição inválida. Tente novamente.', 'danger');
}

$credential = trim($_POST['credential']);

// Valida o JWT via tokeninfo — sem troca de code, sem parâmetros GET
$tokenInfo = @file_get_contents(
    'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential)
);

if (!$tokenInfo) {
    error_log('[GoogleOAuth] Falha ao chamar tokeninfo');
    redirecionarComMensagem(BASE . '/usuario/login.php', 'Erro ao validar com Google. Tente novamente.', 'danger');
}

$data = json_decode($tokenInfo, true);

// Garante que o token é para esta aplicação
if (($data['aud'] ?? '') !== GOOGLE_CLIENT_ID) {
    error_log('[GoogleOAuth] aud inválido: ' . ($data['aud'] ?? 'none'));
    redirecionarComMensagem(BASE . '/usuario/login.php', 'Token inválido. Tente novamente.', 'danger');
}

$googleId = $data['sub']        ?? '';
$email    = $data['email']      ?? '';
$nome     = $data['name']       ?? ($data['given_name'] ?? '');

if (!$googleId || !$email) {
    redirecionarComMensagem(BASE . '/usuario/login.php', 'Dados insuficientes retornados pelo Google.', 'danger');
}

try {
    // Busca por GoogleId (login recorrente)
    $stmt = $pdo->prepare('SELECT IDUsuario, Nome, NivelAcesso FROM Usuarios WHERE GoogleId = :gid LIMIT 1');
    $stmt->execute([':gid' => $googleId]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        // Busca por e-mail (conta já existe com senha)
        $stmt = $pdo->prepare('SELECT IDUsuario, Nome, NivelAcesso FROM Usuarios WHERE Email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $usuario = $stmt->fetch();

        if ($usuario) {
            // Vincula GoogleId à conta existente
            $pdo->prepare('UPDATE Usuarios SET GoogleId = :gid WHERE IDUsuario = :id')
                ->execute([':gid' => $googleId, ':id' => $usuario['IDUsuario']]);
        } else {
            // Cria nova conta — e-mail verificado pelo Google
            $novoId = gerarUuid();
            $pdo->prepare(
                'INSERT INTO Usuarios (IDUsuario, Nome, Email, GoogleId, NivelAcesso, EmailVerificado)
                 VALUES (:id, :nome, :email, :gid, \'cliente\', 1)'
            )->execute([
                ':id'    => $novoId,
                ':nome'  => $nome,
                ':email' => $email,
                ':gid'   => $googleId,
            ]);
            $usuario = ['IDUsuario' => $novoId, 'Nome' => $nome, 'NivelAcesso' => 'cliente'];
        }
    }

    // Garante e-mail verificado em contas vinculadas ao Google
    $pdo->prepare('UPDATE Usuarios SET EmailVerificado = 1 WHERE IDUsuario = :id AND EmailVerificado = 0')
        ->execute([':id' => $usuario['IDUsuario']]);

    session_regenerate_id(true);
    $_SESSION['usuario_id']       = $usuario['IDUsuario'];
    $_SESSION['usuario_nome']     = $usuario['Nome'];
    $_SESSION['nivel_acesso']     = $usuario['NivelAcesso'];
    $_SESSION['email_verificado'] = true;

    if ($usuario['NivelAcesso'] === 'designer') {
        header('Location: ' . BASE . '/painel/index.php');
    } else {
        header('Location: ' . BASE . '/agendamento/index.php');
    }
    exit;
} catch (PDOException $e) {
    error_log('[GoogleOAuth] ' . $e->getMessage());
    redirecionarComMensagem(BASE . '/usuario/login.php', 'Erro interno. Tente novamente.', 'danger');
}
