<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

header('Content-Type: application/json');

if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'Token inválido.']);
    exit;
}

$acao = trim($_POST['acao'] ?? '');

// ── Criar ────────────────────────────────────────────────────────
if ($acao === 'criar') {
    $nome = trim($_POST['nome'] ?? '');
    if ($nome === '') {
        echo json_encode(['ok' => false, 'msg' => 'Informe um nome para a categoria.']);
        exit;
    }
    try {
        $maxOrdem = (int) $pdo->query('SELECT COALESCE(MAX(Ordem),0) FROM CategoriasGaleria')->fetchColumn();
        $id = gerarUuid();
        $pdo->prepare('INSERT INTO CategoriasGaleria (IDCategoria, Nome, Ordem) VALUES (:id, :nome, :ordem)')
            ->execute([':id' => $id, ':nome' => $nome, ':ordem' => $maxOrdem + 1]);
        echo json_encode(['ok' => true, 'id' => $id, 'nome' => $nome]);
    } catch (PDOException $e) {
        error_log('[CatGaleria] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Erro ao criar categoria.']);
    }
    exit;
}

// ── Editar ───────────────────────────────────────────────────────
if ($acao === 'editar') {
    $id   = trim($_POST['id']   ?? '');
    $nome = trim($_POST['nome'] ?? '');
    if ($id === '' || $nome === '') {
        echo json_encode(['ok' => false, 'msg' => 'Dados incompletos.']);
        exit;
    }
    try {
        $pdo->prepare('UPDATE CategoriasGaleria SET Nome = :nome WHERE IDCategoria = :id')
            ->execute([':nome' => $nome, ':id' => $id]);
        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        error_log('[CatGaleria] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Erro ao editar categoria.']);
    }
    exit;
}

// ── Deletar ──────────────────────────────────────────────────────
if ($acao === 'deletar') {
    $id = trim($_POST['id'] ?? '');
    if ($id === '') {
        echo json_encode(['ok' => false, 'msg' => 'ID inválido.']);
        exit;
    }
    try {
        $stm = $pdo->prepare('SELECT COUNT(*) FROM Imagens WHERE Categoria = :id');
        $stm->execute([':id' => $id]);
        $total = (int) $stm->fetchColumn();

        if ($total > 0) {
            echo json_encode(['ok' => false, 'msg' => "Não é possível excluir: há {$total} imagem(ns) nesta categoria. Mova-as primeiro."]);
            exit;
        }
        $pdo->prepare('DELETE FROM CategoriasGaleria WHERE IDCategoria = :id')->execute([':id' => $id]);
        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        error_log('[CatGaleria] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Erro ao deletar categoria.']);
    }
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Ação desconhecida.']);
