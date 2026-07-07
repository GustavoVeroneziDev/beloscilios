<?php
/**
 * Webhook de deploy — acionado pelo GitHub Actions via HTTPS POST.
 * O token fica em config/deploy_secret.php (gitignored), nunca no código.
 */

define('REPO',       'GustavoVeroneziDev/beloscilios');
define('BRANCH',     'main');
define('DEPLOY_DIR', realpath(__DIR__ . '/..'));

$secretFile = DEPLOY_DIR . '/config/deploy_secret.php';
if (!file_exists($secretFile)) {
    http_response_code(500);
    exit('config/deploy_secret.php não encontrado na hospedagem');
}
require $secretFile; // define('DEPLOY_SECRET', 'seu-token-aqui')

$token = $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? $_POST['token'] ?? '';
if (!defined('DEPLOY_SECRET') || !hash_equals(DEPLOY_SECRET, $token)) {
    http_response_code(403);
    exit('Forbidden');
}

// Baixa o ZIP do branch main do GitHub
$zipUrl  = 'https://github.com/' . REPO . '/archive/refs/heads/' . BRANCH . '.zip';
$tmpFile = sys_get_temp_dir() . '/beloscilios_' . time() . '.zip';

$ctx = stream_context_create(['http' => ['timeout' => 120, 'follow_location' => true]]);
$zip = file_get_contents($zipUrl, false, $ctx);
if ($zip === false) {
    http_response_code(500);
    exit('Falha ao baixar o repositório do GitHub');
}
file_put_contents($tmpFile, $zip);

$z = new ZipArchive;
if ($z->open($tmpFile) !== true) {
    http_response_code(500);
    exit('Falha ao abrir o ZIP');
}
$extractDir = sys_get_temp_dir() . '/beloscilios_ext_' . time();
$z->extractTo($extractDir);
$z->close();
unlink($tmpFile);

// O ZIP extrai numa pasta "beloscilios-main"
$src = $extractDir . '/' . basename(REPO) . '-' . BRANCH;

// Esses arquivos nunca são sobrescritos pelo deploy
$protegidos = [
    'config/conexao.php',
    'config/gemini.php',
    'config/evolution_keys.php',
    'config/smtp_keys.php',
    'config/deploy_secret.php',
];

// Essas pastas são completamente ignoradas
$ignorarPastas = ['.git', '.github', 'uploads', 'logs'];

function copiarRecursivo(string $src, string $dst, array $protegidos, array $ignorarPastas): void
{
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iter as $item) {
        $rel  = str_replace('\\', '/', substr($item->getPathname(), strlen($src) + 1));
        $dest = $dst . '/' . $rel;

        foreach ($ignorarPastas as $pasta) {
            if ($rel === $pasta || str_starts_with($rel, $pasta . '/')) continue 2;
        }
        if (in_array($rel, $protegidos, true)) continue;

        if ($item->isDir()) {
            if (!is_dir($dest)) mkdir($dest, 0755, true);
        } else {
            copy($item->getPathname(), $dest);
        }
    }
}

function deletarDiretorio(string $dir): void
{
    if (!is_dir($dir)) return;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

copiarRecursivo($src, DEPLOY_DIR, $protegidos, $ignorarPastas);
deletarDiretorio($extractDir);

header('Content-Type: application/json');
echo json_encode([
    'ok'          => true,
    'deployed_at' => date('c'),
    'branch'      => BRANCH,
]);
