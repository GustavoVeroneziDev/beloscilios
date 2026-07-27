<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

header('Content-Type: application/json; charset=UTF-8');

function jsonErr(string $msg): never {
    echo json_encode(['ok' => false, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) jsonErr('Token inválido.');

$id          = trim($_POST['id']           ?? '');
$tratadaNome = trim($_POST['tratada_nome'] ?? '');

if (!$id || !$tratadaNome) jsonErr('Parâmetros inválidos.');
// Segurança: só permite nomes simples (sem path traversal)
if (!preg_match('/^[a-f0-9\-]+_tratada\.jpg$/i', $tratadaNome)) jsonErr('Nome de arquivo inválido.');

$stm = $pdo->prepare('SELECT NomeArquivo FROM Imagens WHERE IDImagem = :id');
$stm->execute([':id' => $id]);
$row = $stm->fetch();
if (!$row) jsonErr('Imagem não encontrada.');

$nomeArquivo = $row['NomeArquivo'];
$galeriaPath = __DIR__ . '/../geral/img/galeria/'     . $nomeArquivo;
$tratadaPath = __DIR__ . '/../geral/img/galeria_mae/' . $tratadaNome;

if (!file_exists($tratadaPath)) jsonErr('Arquivo tratado não encontrado. Repita o tratamento.');

if (!@copy($tratadaPath, $galeriaPath)) jsonErr('Falha ao aplicar imagem tratada.');

// Limpa o arquivo temporário da tratada
@unlink($tratadaPath);

$dims    = @getimagesize($galeriaPath);
$tamanho = @filesize($galeriaPath);

try {
    $pdo->prepare(
        'UPDATE Imagens SET Largura = :w, Altura = :h, TamanhoBytes = :tam WHERE IDImagem = :id'
    )->execute([
        ':w'   => $dims[0] ?? null,
        ':h'   => $dims[1] ?? null,
        ':tam' => $tamanho ?: null,
        ':id'  => $id,
    ]);
} catch (\Throwable $e) {
    error_log('[AplicarTratada] ' . $e->getMessage());
}

echo json_encode([
    'ok'  => true,
    'url' => BASE . '/geral/img/galeria/' . $nomeArquivo,
], JSON_UNESCAPED_UNICODE);
