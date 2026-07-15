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

    // UMA única chamada com número → retorna pairingCode E o campo "code" (dados brutos do QR)
    // Obs: a chamada SEM número retorna base64 mas invalida o pairing code da chamada anterior,
    //      por isso usamos apenas esta chamada e geramos o QR no frontend via qrcode.js
    if ($numero) {
        $res = evolutionGet('/instance/connect/' . EVOLUTION_INSTANCE . '?number=' . urlencode($numero));
    } else {
        $res = evolutionGet('/instance/connect/' . EVOLUTION_INSTANCE);
    }

    $d           = $res['data'];
    $qrCode      = $d['code']        ?? null; // dados brutos para gerar QR no frontend
    $qrBase64    = $d['base64']      ?? null; // fallback: imagem já renderizada
    $pairingCode = $d['pairingCode'] ?? null;

    if (!$qrCode && !$qrBase64) {
        $state = $d['instance']['state'] ?? $d['state'] ?? null;
        if ($state === 'open') {
            echo json_encode(['ok' => true, 'state' => 'open', 'msg' => 'WhatsApp já está conectado!']);
            exit;
        }
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
