<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
// Remove token lembrar-me do banco antes de destruir a sessão
if (!empty($_COOKIE['bc_lembrar'])) {
    $partes = explode(':', $_COOKIE['bc_lembrar'], 2);
    if (count($partes) === 2) {
        try {
            $pdo->prepare('DELETE FROM TokensLembrarMe WHERE IDToken = :id')
                ->execute([':id' => $partes[0]]);
        } catch (PDOException) {}
    }
    _limparCookieLembrarMe();
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: ' . BASE . '/usuario/login.php');
exit;
