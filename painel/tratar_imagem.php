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

$nomeArquivo = $row['NomeArquivo'];
$galeriaDir  = realpath(__DIR__ . '/../geral/img/galeria') . DIRECTORY_SEPARATOR;
$maeDir      = __DIR__ . '/../geral/img/galeria_mae' . DIRECTORY_SEPARATOR;

if (!is_dir($maeDir)) {
    if (!mkdir($maeDir, 0755, true)) jsonErr('Não foi possível criar pasta de backup.');
}

$galeriaPath = $galeriaDir . $nomeArquivo;
$maePath     = $maeDir . $nomeArquivo;

// ── Backup do original ────────────────────────────────────────────
if (!file_exists($maePath)) {
    if (!@copy($galeriaPath, $maePath)) jsonErr('Falha ao criar backup do original.');
    try {
        $pdo->prepare('UPDATE Imagens SET TemMaeBackup = 1 WHERE IDImagem = :id')
            ->execute([':id' => $id]);
    } catch (\Throwable $e) {
        error_log('[TratarImagem] DB: ' . $e->getMessage());
    }
}

// ── Arquivo de saída ──────────────────────────────────────────────
$baseName    = pathinfo($nomeArquivo, PATHINFO_FILENAME);
$tratadaNome = $baseName . '_tratada.jpg';
$tratadaPath = $maeDir . $tratadaNome;

if (file_exists($tratadaPath)) @unlink($tratadaPath);

$aspect = (float)($_POST['aspect'] ?? 0.75);

// ── Chama microserviço na VPS via curl ────────────────────────────
$vpsUrl = 'http://143.95.219.124:5001/smart-crop';
$apiKey = 'bc_crop_k8x2m9p4';

if (!function_exists('curl_init')) jsonErr('cURL não disponível no servidor.');
if (!file_exists($galeriaPath))   jsonErr('Arquivo de imagem não encontrado.');

$ch = curl_init($vpsUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['X-Api-Key: ' . $apiKey],
    CURLOPT_POSTFIELDS     => [
        'image'  => new CURLFile($galeriaPath, 'image/jpeg', $nomeArquivo),
        'aspect' => (string)$aspect,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
]);

$result   = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    error_log('[TratarImagem] cURL: ' . $curlErr);
    jsonErr('Não foi possível conectar ao serviço de tratamento. Tente novamente.');
}
if ($httpCode !== 200) {
    error_log('[TratarImagem] VPS HTTP ' . $httpCode . ': ' . substr($result, 0, 200));
    jsonErr('Serviço de tratamento retornou erro (' . $httpCode . '). Tente novamente.');
}

if (!file_put_contents($tratadaPath, $result)) {
    jsonErr('Falha ao salvar imagem tratada.');
}

echo json_encode([
    'ok'           => true,
    'original_url' => BASE . '/geral/img/galeria_mae/' . $nomeArquivo,
    'tratada_url'  => BASE . '/geral/img/galeria_mae/' . $tratadaNome,
    'tratada_nome' => $tratadaNome,
    'nome_arquivo' => $nomeArquivo,
], JSON_UNESCAPED_UNICODE);
