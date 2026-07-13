<?php
/**
 * Gera pairing code para conectar WhatsApp por número (sem QR code).
 * Uso:
 *   php cron/gerar_pairing_code.php 5511999999999
 *
 * No WhatsApp: Dispositivos conectados → Conectar dispositivo
 *              → Conectar com número de telefone → digitar o código
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso restrito ao CLI.');
}

$telefone = trim($argv[1] ?? '');
if (!$telefone || !ctype_digit($telefone)) {
    echo '[ERRO] Informe o número com DDI+DDD, só dígitos. Ex:' . PHP_EOL;
    echo '  php cron/gerar_pairing_code.php 5511999999999' . PHP_EOL;
    exit(1);
}

$keysFile = __DIR__ . '/../config/evolution_keys.php';
if (!file_exists($keysFile)) {
    echo '[ERRO] config/evolution_keys.php não encontrado.' . PHP_EOL;
    exit(1);
}
require_once $keysFile;

if (!defined('EVOLUTION_URL') || !defined('EVOLUTION_INSTANCE') || !defined('EVOLUTION_KEY')) {
    echo '[ERRO] Constantes da Evolution API não encontradas.' . PHP_EOL;
    exit(1);
}

echo '[INFO] Instância : ' . EVOLUTION_INSTANCE . PHP_EOL;
echo '[INFO] Telefone  : ' . $telefone . PHP_EOL;
echo '[INFO] Solicitando pairing code...' . PHP_EOL;

$url     = rtrim(EVOLUTION_URL, '/') . '/instance/pairingCode/' . EVOLUTION_INSTANCE;
$payload = json_encode(['phoneNumber' => $telefone]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'apikey: ' . EVOLUTION_KEY,
    ],
]);

$resp     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    echo '[ERRO] cURL: ' . $curlErr . PHP_EOL;
    exit(1);
}

if ($httpCode < 200 || $httpCode >= 300) {
    echo "[ERRO] HTTP {$httpCode}: {$resp}" . PHP_EOL;
    exit(1);
}

$data = json_decode($resp, true);
$code = $data['code'] ?? $data['pairingCode'] ?? null;

if (!$code) {
    echo '[AVISO] Resposta inesperada:' . PHP_EOL;
    echo $resp . PHP_EOL;
    exit(1);
}

echo PHP_EOL;
echo '╔══════════════════════╗' . PHP_EOL;
echo '║  PAIRING CODE: ' . str_pad($code, 8) . ' ║' . PHP_EOL;
echo '╚══════════════════════╝' . PHP_EOL;
echo PHP_EOL;
echo 'No WhatsApp:' . PHP_EOL;
echo '  Dispositivos conectados → Conectar dispositivo' . PHP_EOL;
echo '  → "Conectar com número de telefone"' . PHP_EOL;
echo '  → Digite o código acima' . PHP_EOL;
