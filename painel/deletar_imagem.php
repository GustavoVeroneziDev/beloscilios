<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

header('Content-Type: application/json');

if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'Token inválido.']);
    exit;
}

$id = trim($_POST['id'] ?? '');
if (!$id) {
    echo json_encode(['ok' => false, 'msg' => 'ID inválido.']);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT NomeArquivo FROM Imagens WHERE IDImagem = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $img = $stmt->fetch();

    if (!$img) {
        echo json_encode(['ok' => false, 'msg' => 'Imagem não encontrada.']);
        exit;
    }

    $arquivo = __DIR__ . '/../geral/img/galeria/' . $img['NomeArquivo'];
    if (file_exists($arquivo)) @unlink($arquivo);

    $pdo->prepare('DELETE FROM Imagens WHERE IDImagem = :id')->execute([':id' => $id]);

    echo json_encode(['ok' => true, 'msg' => 'Imagem deletada.']);
} catch (PDOException $e) {
    error_log('[DeleteImagem] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Erro ao deletar.']);
}
