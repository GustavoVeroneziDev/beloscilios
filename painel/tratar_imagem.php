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

// ── Arquivo de saída do Python ────────────────────────────────────
$baseName    = pathinfo($nomeArquivo, PATHINFO_FILENAME);
$tratadaNome = $baseName . '_tratada.jpg';
$tratadaPath = $maeDir . $tratadaNome;

// Remove resultado anterior se existir
if (file_exists($tratadaPath)) @unlink($tratadaPath);

$aspect    = (float)($_POST['aspect'] ?? 0.75);
$pyScript  = realpath(__DIR__ . '/../tools/smart_crop.py');
if (!$pyScript) jsonErr('Script Python não encontrado em tools/smart_crop.py.');

// Testa binários comuns do Python
$candidates = ['python', 'python3'];
// Windows: procura em locais típicos
foreach (['311','310','312','313','39','38'] as $v) {
    $candidates[] = "C:\\Python{$v}\\python.exe";
    $candidates[] = "C:\\Users\\Public\\Python\\python.exe";
}
$pythonBin = null;
foreach ($candidates as $bin) {
    $test = @shell_exec(escapeshellcmd($bin) . ' -c "print(1)" 2>&1');
    if (trim($test) === '1') { $pythonBin = $bin; break; }
}
if (!$pythonBin) jsonErr('Python não encontrado. Instale Python e Pillow para usar o tratamento inteligente.');

$cmd     = escapeshellcmd($pythonBin)
         . ' ' . escapeshellarg($pyScript)
         . ' ' . escapeshellarg($galeriaPath)
         . ' ' . escapeshellarg($tratadaPath)
         . ' ' . escapeshellarg((string)$aspect)
         . ' 2>&1';

$retCode = 0;
@exec($cmd, $outLines, $retCode);

if ($retCode !== 0 || !file_exists($tratadaPath)) {
    $err = implode(' | ', $outLines);
    error_log('[TratarImagem] Python: ' . $err);
    $hint = str_contains($err, 'Pillow') ? ' Instale com: pip install Pillow' : '';
    jsonErr('Tratamento inteligente falhou.' . $hint);
}

echo json_encode([
    'ok'           => true,
    'original_url' => BASE . '/geral/img/galeria_mae/' . $nomeArquivo,
    'tratada_url'  => BASE . '/geral/img/galeria_mae/' . $tratadaNome,
    'tratada_nome' => $tratadaNome,
    'nome_arquivo' => $nomeArquivo,
], JSON_UNESCAPED_UNICODE);
