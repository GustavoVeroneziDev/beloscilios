<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

header('Content-Type: application/json');

function jsonErr(string $msg): never {
    echo json_encode(['ok' => false, 'msg' => $msg]);
    exit;
}

if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) jsonErr('Token inválido.');

if (!isset($_FILES['imagem']) || $_FILES['imagem']['error'] !== UPLOAD_ERR_OK) {
    jsonErr('Nenhum arquivo recebido ou erro no upload.');
}

$file = $_FILES['imagem'];

if ($file['size'] > 20 * 1024 * 1024) jsonErr('Arquivo muito grande (máx. 20 MB).');

$tipo = mime_content_type($file['tmp_name']);
$tiposValidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($tiposValidos[$tipo])) jsonErr('Tipo inválido. Use JPEG, PNG ou WebP.');

$ext      = $tiposValidos[$tipo];
$titulo   = trim($_POST['titulo']   ?? '');
$catInput = trim($_POST['categoria'] ?? 'galeria');
$cat      = in_array($catInput, ['galeria', 'servico', 'espaco', 'outro']) ? $catInput : 'galeria';

$dir = __DIR__ . '/../geral/img/galeria/';
if (!is_dir($dir) && !mkdir($dir, 0755, true)) jsonErr('Não foi possível criar diretório de upload.');

$id          = gerarUuid();
$nomeArquivo = $id . '.' . $ext;
$destino     = $dir . $nomeArquivo;

if (!move_uploaded_file($file['tmp_name'], $destino)) jsonErr('Falha ao mover arquivo para o destino.');

$dims    = @getimagesize($destino);
$largura = $dims[0] ?? null;
$altura  = $dims[1] ?? null;
$tamanho = filesize($destino);

try {
    $pdo->prepare(
        'INSERT INTO Imagens (IDImagem, NomeArquivo, TituloExibicao, Categoria, Largura, Altura, TamanhoBytes)
         VALUES (:id, :nome, :titulo, :cat, :w, :h, :tam)'
    )->execute([
        ':id'     => $id,
        ':nome'   => $nomeArquivo,
        ':titulo' => $titulo ?: null,
        ':cat'    => $cat,
        ':w'      => $largura,
        ':h'      => $altura,
        ':tam'    => $tamanho,
    ]);
} catch (PDOException $e) {
    error_log('[Upload] ' . $e->getMessage());
    @unlink($destino);
    jsonErr('Erro ao registrar imagem no banco.');
}

echo json_encode([
    'ok'   => true,
    'msg'  => 'Imagem salva!',
    'url'  => BASE . '/geral/img/galeria/' . $nomeArquivo,
    'nome' => $nomeArquivo,
    'id'   => $id,
    'w'    => $largura,
    'h'    => $altura,
]);
