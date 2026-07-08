<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/google_oauth.php';

// Valida state CSRF
if (
    empty($_GET['state']) ||
    empty($_SESSION['oauth_state']) ||
    !hash_equals($_SESSION['oauth_state'], $_GET['state'])
) {
    unset($_SESSION['oauth_state']);
    redirecionarComMensagem(BASE . '/usuario/login.php', 'Requisição inválida. Tente novamente.', 'danger');
}
unset($_SESSION['oauth_state']);

if (!empty($_GET['error'])) {
    redirecionarComMensagem(BASE . '/usuario/login.php', 'Login com Google cancelado.', 'warning');
}

$code = trim($_GET['code'] ?? '');
if (!$code) {
    redirecionarComMensagem(BASE . '/usuario/login.php', 'Código de autorização ausente.', 'danger');
}

$redirectUri = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . BASE . '/usuario/callback_google.php';

// Troca code por access_token
$tokenResp = @file_get_contents('https://oauth2.googleapis.com/token', false, stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query([
            'code'          => $code,
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]),
    ],
]));

if (!$tokenResp) {
    error_log('[GoogleOAuth] Falha ao trocar code por token');
    redirecionarComMensagem(BASE . '/usuario/login.php', 'Erro ao comunicar com Google. Tente novamente.', 'danger');
}

$tokenData   = json_decode($tokenResp, true);
$accessToken = $tokenData['access_token'] ?? '';
if (!$accessToken) {
    error_log('[GoogleOAuth] access_token ausente: ' . $tokenResp);
    redirecionarComMensagem(BASE . '/usuario/login.php', 'Autenticação com Google falhou. Tente novamente.', 'danger');
}

// Busca dados do usuário
$userResp = @file_get_contents('https://www.googleapis.com/oauth2/v3/userinfo', false, stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => 'Authorization: Bearer ' . $accessToken,
    ],
]));

if (!$userResp) {
    redirecionarComMensagem(BASE . '/usuario/login.php', 'Não foi possível obter dados do Google.', 'danger');
}

$gUser    = json_decode($userResp, true);
$googleId = $gUser['sub']            ?? '';
$email    = $gUser['email']          ?? '';
$nome     = $gUser['name']           ?? '';

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
            // Cria nova conta
            $novoId = gerarUuid();
            $pdo->prepare(
                'INSERT INTO Usuarios (IDUsuario, Nome, Email, GoogleId, NivelAcesso)
                 VALUES (:id, :nome, :email, :gid, \'cliente\')'
            )->execute([
                ':id'   => $novoId,
                ':nome' => $nome,
                ':email'=> $email,
                ':gid'  => $googleId,
            ]);
            $usuario = ['IDUsuario' => $novoId, 'Nome' => $nome, 'NivelAcesso' => 'cliente'];
        }
    }

    session_regenerate_id(true);
    $_SESSION['usuario_id']   = $usuario['IDUsuario'];
    $_SESSION['usuario_nome'] = $usuario['Nome'];
    $_SESSION['nivel_acesso'] = $usuario['NivelAcesso'];

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
