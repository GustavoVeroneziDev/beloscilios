<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';

if (($_SESSION['nivel_acesso'] ?? '') !== 'designer') {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 1) {
    echo json_encode([]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        'SELECT IDUsuario AS id, Nome AS nome, Email AS email, Telefone AS telefone
         FROM Usuarios
         WHERE NivelAcesso = \'cliente\'
           AND (Nome LIKE :q OR Email LIKE :q OR Telefone LIKE :q)
         ORDER BY Nome ASC
         LIMIT 15'
    );
    $stmt->execute([':q' => '%' . $q . '%']);
    $resultados = $stmt->fetchAll();
} catch (PDOException) {
    $resultados = [];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($resultados);
