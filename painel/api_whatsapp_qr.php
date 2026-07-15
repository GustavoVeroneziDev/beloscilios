<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

header('Content-Type: application/json');

if (!defined('EVOLUTION_URL') || !defined('EVOLUTION_INSTANCE') || !defined('EVOLUTION_KEY')) {
    echo json_encode(['ok' => false, 'msg' => 'Evolution API não configurada.']);
    exit;
}

$acao = $_GET['acao'] ?? 'status';

function evolutionGet(string $endpoint): array
{
    $url = rtrim(EVOLUTION_URL, '/') . $endpoint;
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "apikey: " . EVOLUTION_KEY . "\r\nContent-Type: application/json\r\n",
            'timeout' => 15,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $raw  = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header)) {
        preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m);
        $code = (int)($m[1] ?? 0);
    }
    $data = $raw ? (json_decode($raw, true) ?? []) : [];
    return ['code' => $code, 'data' => $data];
}

function evolutionDelete(string $endpoint): array
{
    $url = rtrim(EVOLUTION_URL, '/') . $endpoint;
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'DELETE',
            'header'  => "apikey: " . EVOLUTION_KEY . "\r\nContent-Type: application/json\r\n",
            'timeout' => 15,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $raw  = @file_get_contents($url, false, $ctx);
    $data = $raw ? (json_decode($raw, true) ?? []) : [];
    return ['data' => $data];
}

// ── Status da conexão ─────────────────────────────────────────
if ($acao === 'status') {
    $res = evolutionGet('/instance/connectionState/' . EVOLUTION_INSTANCE);
    $state = $res['data']['instance']['state'] ?? $res['data']['state'] ?? 'unknown';
    echo json_encode(['ok' => true, 'state' => $state]);
    exit;
}

// ── Gerar QR / conectar ───────────────────────────────────────
if ($acao === 'conectar') {
    // Pega o número registrado na instância para poder gerar o pairing code
    $instRes = evolutionGet('/instance/fetchInstances');
    $numero  = null;
    foreach (($instRes['data'] ?? []) as $inst) {
        if (($inst['name'] ?? '') === EVOLUTION_INSTANCE) {
            $jid    = $inst['ownerJid'] ?? '';
            $numero = $jid ? preg_replace('/@.*/', '', $jid) : null;
            break;
        }
    }

    // Logout garante estado limpo — pairing code só vem com instância em "close"
    evolutionDelete('/instance/logout/' . EVOLUTION_INSTANCE);
    usleep(800000);

    // Conecta com número → retorna pairingCode + code (dados brutos do QR)
    if ($numero) {
        $res = evolutionGet('/instance/connect/' . EVOLUTION_INSTANCE . '?number=' . urlencode($numero));
    } else {
        $res = evolutionGet('/instance/connect/' . EVOLUTION_INSTANCE);
    }

    $d           = $res['data'];
    $qrCode      = $d['code']        ?? null;
    $qrBase64    = $d['base64']      ?? null;
    $pairingCode = $d['pairingCode'] ?? null;

    if (!$qrCode && !$qrBase64) {
        echo json_encode(['ok' => false, 'msg' => 'Não foi possível gerar o QR code. Tente novamente.']);
        exit;
    }

    echo json_encode([
        'ok'          => true,
        'state'       => 'connecting',
        'qrCode'      => $qrCode,
        'qr'          => $qrBase64,
        'pairingCode' => $pairingCode,
    ]);
    exit;
}

// ── Desconectar ───────────────────────────────────────────────
if ($acao === 'desconectar') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'msg' => 'Token inválido.']);
        exit;
    }
    evolutionDelete('/instance/logout/' . EVOLUTION_INSTANCE);
    echo json_encode(['ok' => true, 'msg' => 'Desconectado com sucesso.']);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Ação inválida.']);
