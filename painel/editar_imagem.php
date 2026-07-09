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

// Busca nome do arquivo atual
$stmImg = $pdo->prepare('SELECT NomeArquivo FROM Imagens WHERE IDImagem = :id');
$stmImg->execute([':id' => $id]);
$imgRow = $stmImg->fetch();
if (!$imgRow) {
    echo json_encode(['ok' => false, 'msg' => 'Imagem não encontrada.']);
    exit;
}
$nomeArquivo = $imgRow['NomeArquivo'];

$largura = null;
$altura  = null;
$tamanho = null;
$urlNova = null;

// Substituição de arquivo (opcional)
if (isset($_FILES['imagem']) && $_FILES['imagem']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['imagem'];
    if ($file['size'] > 20 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'msg' => 'Arquivo muito grande (máx. 20 MB).']);
        exit;
    }
    $tiposValidos = ['image/jpeg', 'image/png', 'image/webp'];
    $tipo = mime_content_type($file['tmp_name']);
    if (!in_array($tipo, $tiposValidos)) {
        echo json_encode(['ok' => false, 'msg' => 'Tipo inválido. Use JPEG, PNG ou WebP.']);
        exit;
    }

    $destino = __DIR__ . '/../geral/img/galeria/' . $nomeArquivo;
    // Força extensão .jpg ao salvar recorte (o nome no banco não muda — URL permanece válida)
    if (!move_uploaded_file($file['tmp_name'], $destino)) {
        echo json_encode(['ok' => false, 'msg' => 'Falha ao salvar arquivo.']);
        exit;
    }

    $dims    = @getimagesize($destino);
    $largura = $dims[0] ?? null;
    $altura  = $dims[1] ?? null;
    $tamanho = filesize($destino);
    $urlNova = BASE . '/geral/img/galeria/' . $nomeArquivo;
}

try {
    if ($largura !== null) {
        $pdo->prepare(
            'UPDATE Imagens
             SET TituloExibicao = :titulo, Categoria = :cat,
                 Largura = :w, Altura = :h, TamanhoBytes = :tam
             WHERE IDImagem = :id'
        )->execute([
            ':titulo' => $titulo ?: null,
            ':cat'    => $cat,
            ':w'      => $largura,
            ':h'      => $altura,
            ':tam'    => $tamanho,
            ':id'     => $id,
        ]);
    } else {
        $pdo->prepare(
            'UPDATE Imagens SET TituloExibicao = :titulo, Categoria = :cat WHERE IDImagem = :id'
        )->execute([
            ':titulo' => $titulo ?: null,
            ':cat'    => $cat,
            ':id'     => $id,
        ]);
    }
    echo json_encode(['ok' => true, 'catNome' => $catRow['Nome'], 'url' => $urlNova]);
} catch (PDOException $e) {
    error_log('[EditarImagem] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Erro ao atualizar imagem.']);
}
