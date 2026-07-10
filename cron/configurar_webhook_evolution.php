<?php
/**
 * Script de configuração do webhook na Evolution API.
 * Executar UMA VEZ após deploy (ou sempre que a URL mudar):
 *
 *   php cron/configurar_webhook_evolution.php
 *
 * Requer WEBHOOK_URL configurado em ConfiguracoesSistema:
 *   INSERT INTO ConfiguracoesSistema (IDConfig, Chave, Valor)
 *   VALUES (UUID(), 'webhook_url', 'https://seudominio.com.br/webhook/whatsapp.php?token=SEU_TOKEN');
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso restrito ao CLI.');
}

require_once __DIR__ . '/../config/conexao.php';

if (!defined('EVOLUTION_URL') || !defined('EVOLUTION_INSTANCE') || !defined('EVOLUTION_KEY')) {
    echo '[ERRO] Evolution API não configurada em evolution_keys.php' . PHP_EOL;
    exit(1);
}

$webhookUrl = getConfig($pdo, 'webhook_url', '');
if (!$webhookUrl) {
    echo '[ERRO] Configure a chave "webhook_url" em ConfiguracoesSistema com a URL completa do webhook.' . PHP_EOL;
    echo 'Exemplo: https://seudominio.com.br/webhook/whatsapp.php?token=SEU_TOKEN' . PHP_EOL;
    exit(1);
}

$url = rtrim(EVOLUTION_URL, '/') . '/webhook/set/' . EVOLUTION_INSTANCE;

$payload = json_encode([
    'webhook' => [
        'enabled'        => true,
        'url'            => $webhookUrl,
        'byEvents'       => false,
        'base64'         => false,
        'events'         => ['MESSAGES_UPSERT'],
    ],
], JSON_UNESCAPED_UNICODE);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'apikey: ' . EVOLUTION_KEY,
    ],
]);

$resp     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode >= 200 && $httpCode < 300) {
    echo "[OK] Webhook configurado com sucesso para: {$webhookUrl}" . PHP_EOL;
    echo "Resposta: {$resp}" . PHP_EOL;
} else {
    echo "[ERRO] HTTP {$httpCode}: {$resp}" . PHP_EOL;
    exit(1);
}
