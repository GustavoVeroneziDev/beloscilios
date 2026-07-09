<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

header('Content-Type: application/json');

if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'Token inválido.']);
    exit;
}

$id     = trim($_POST['id']        ?? '');
$titulo = trim($_POST['titulo']    ?? '');
$cat    = trim($_POST['categoria'] ?? '');

if ($id === '') {
    echo json_encode(['ok' => false, 'msg' => 'ID inválido.']);
    exit;
}

// Valida categoria
$stmCat = $pdo->prepare('SELECT Nome FROM CategoriasGaleria WHERE IDCategoria = :id');
$stmCat->execute([':id' => $cat]);
$catRow = $stmCat->fetch();
if (!$catRow) {
    echo json_encode(['ok' => false, 'msg' => 'Categoria inválida.']);
    exit;
}

try {
    $pdo->prepare(
        'UPDATE Imagens SET TituloExibicao = :titulo, Categoria = :cat WHERE IDImagem = :id'
    )->execute([
        ':titulo' => $titulo ?: null,
        ':cat'    => $cat,
        ':id'     => $id,
    ]);
    echo json_encode(['ok' => true, 'catNome' => $catRow['Nome']]);
} catch (PDOException $e) {
    error_log('[EditarImagem] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Erro ao atualizar imagem.']);
}
