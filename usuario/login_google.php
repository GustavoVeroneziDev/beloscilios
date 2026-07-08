<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/google_oauth.php';

if (!empty($_SESSION['usuario_id'])) {
    header('Location: ' . BASE . '/index.php');
    exit;
}

$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

$redirectUri = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . BASE . '/usuario/callback_google.php';

$params = http_build_query([
    'client_id'             => GOOGLE_CLIENT_ID,
    'redirect_uri'          => $redirectUri,
    'response_type'         => 'code',
    'scope'                 => 'openid email profile',
    'state'                 => $state,
    'access_type'           => 'online',
    'prompt'                => 'select_account',
]);

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
exit;
