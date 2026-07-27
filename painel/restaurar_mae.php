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

$id = trim($_POST['id'] ?? '');
if (!$id) jsonErr('ID inválido.');

$stm = $pdo->prepare('SELECT NomeArquivo, TemMaeBackup FROM Imagens WHERE IDImagem = :id');
$stm->execute([':id' => $id]);
$row = $stm->fetch();
if (!$row) jsonErr('Imagem não encontrada.');
if (!$row['TemMaeBackup']) jsonErr('Nenhum backup original disponível para esta imagem.');

$nomeArquivo = $row['NomeArquivo'];
$galeriaPath = __DIR__ . '/../geral/img/galeria/'     . $nomeArquivo;
$maePath     = __DIR__ . '/../geral/img/galeria_mae/' . $nomeArquivo;

if (!file_exists($maePath)) jsonErr('Arquivo de backup não encontrado no servidor.');

if (!@copy($maePath, $galeriaPath)) jsonErr('Falha ao restaurar original.');

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
    error_log('[RestaurarMae] ' . $e->getMessage());
}

echo json_encode([
    'ok'  => true,
    'url' => BASE . '/geral/img/galeria/' . $nomeArquivo,
], JSON_UNESCAPED_UNICODE);
