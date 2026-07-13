<?php
/**
 * Gera novo QR code para conectar o WhatsApp via Evolution API.
 * Executar via CLI:
 *   php cron/gerar_qrcode.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso restrito ao CLI.');
}

// Carrega só as chaves da Evolution — sem precisar do banco
$keysFile = __DIR__ . '/../config/evolution_keys.php';
if (!file_exists($keysFile)) {
    echo '[ERRO] config/evolution_keys.php não encontrado.' . PHP_EOL;
    exit(1);
}
require_once $keysFile;

if (!defined('EVOLUTION_URL') || !defined('EVOLUTION_INSTANCE') || !defined('EVOLUTION_KEY')) {
    echo '[ERRO] Constantes da Evolution API não encontradas em evolution_keys.php' . PHP_EOL;
    exit(1);
}

echo '[INFO] Instância: ' . EVOLUTION_INSTANCE . PHP_EOL;
echo '[INFO] Buscando QR code...' . PHP_EOL;

$url = rtrim(EVOLUTION_URL, '/') . '/instance/connect/' . EVOLUTION_INSTANCE;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_HTTPHEADER     => [
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

// Tenta diferentes campos que a Evolution API pode retornar
$base64 = $data['base64'] ?? $data['qrcode']['base64'] ?? $data['code'] ?? null;

if (!$base64) {
    echo '[AVISO] Resposta da API:' . PHP_EOL;
    echo $resp . PHP_EOL;

    // Talvez já esteja conectado
    if (!empty($data['instance']['state']) && $data['instance']['state'] === 'open') {
        echo PHP_EOL . '[OK] WhatsApp já está conectado! Estado: open' . PHP_EOL;
    } else {
        echo PHP_EOL . '[INFO] Verifique o estado da instância acima.' . PHP_EOL;
    }
    exit(0);
}

// Garante o prefixo data URI
if (!str_starts_with($base64, 'data:')) {
    $base64 = 'data:image/png;base64,' . $base64;
}

// Salva HTML com o QR code para escanear no browser
$geradoEm = time(); // timestamp fixo de geração — não muda com Ctrl+R
$htmlFile = __DIR__ . '/../qrcode_temp.html';
$html = <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Code WhatsApp — Belos Cílios</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: sans-serif; display:flex; flex-direction:column; align-items:center;
               justify-content:center; min-height:100vh; background:#f5f5f5; padding:24px; }
        h2  { color:#5a189a; margin-bottom:6px; font-size:1.3rem; }
        .instrucao { color:#666; font-size:13px; margin-bottom:20px; text-align:center; }
        .qr-wrap { position:relative; display:inline-block; }
        img { border:4px solid #5a189a; border-radius:12px; width:260px; height:260px;
              object-fit:contain; display:block; transition:opacity .4s; }
        .overlay { display:none; position:absolute; inset:0; border-radius:12px;
                   background:rgba(0,0,0,.72); flex-direction:column;
                   align-items:center; justify-content:center; gap:10px; color:#fff; text-align:center; padding:16px; }
        .overlay.visivel { display:flex; }
        .overlay .icone { font-size:2.4rem; }
        .overlay p { font-size:.9rem; line-height:1.4; }
        #timer { margin-top:16px; font-size:1.05rem; font-weight:600; color:#5a189a; }
        #timer.aviso { color:#d97706; }
        #timer.expirado { color:#dc2626; }
        .barra-wrap { width:260px; height:6px; background:#ddd; border-radius:4px; margin-top:8px; overflow:hidden; }
        .barra { height:100%; background:#5a189a; border-radius:4px; transition:width 1s linear, background .5s; }
        .btn { margin-top:20px; padding:10px 28px; background:#5a189a; color:#fff; border:none;
               border-radius:8px; font-size:.95rem; cursor:pointer; display:none; }
        .btn:hover { background:#4a1480; }
    </style>
</head>
<body>
    <h2>Conectar WhatsApp</h2>
    <p class="instrucao">Abra o WhatsApp → Dispositivos conectados → Conectar dispositivo</p>

    <div class="qr-wrap">
        <img id="qrImg" src="{$base64}" alt="QR Code WhatsApp">
        <div class="overlay" id="overlay">
            <span class="icone">⏱️</span>
            <p>QR Code expirado.<br>Gere um novo e abra o arquivo novamente.</p>
        </div>
    </div>

    <div id="timer">⏳ 40 segundos</div>
    <div class="barra-wrap"><div class="barra" id="barra" style="width:100%"></div></div>
    <button class="btn" id="btnFechar" onclick="window.close()">Fechar</button>

    <script>
    const TOTAL = 40;

    // sessionStorage persiste no Ctrl+R dentro da mesma aba
    // mas é zerado se fechar e reabrir o arquivo — aí reseta certo
    if (!sessionStorage.getItem('qrTs')) {
        sessionStorage.setItem('qrTs', Date.now());
    }
    const inicio = +sessionStorage.getItem('qrTs');

    const timerEl = document.getElementById('timer');
    const barraEl = document.getElementById('barra');
    const overlay = document.getElementById('overlay');
    const btn     = document.getElementById('btnFechar');

    function atualizar() {
        const passado   = Math.floor((Date.now() - inicio) / 1000);
        const restante  = Math.max(0, TOTAL - passado);
        const pct       = (restante / TOTAL) * 100;

        barraEl.style.width = pct + '%';

        if (restante === 0) {
            clearInterval(intervalo);
            timerEl.className   = 'expirado';
            timerEl.textContent = '❌ Expirado';
            barraEl.style.background = '#dc2626';
            overlay.classList.add('visivel');
            btn.style.display = 'inline-block';
            return;
        }

        timerEl.textContent = '⏳ ' + restante + 's';
        if (restante <= 10) {
            timerEl.className      = 'aviso';
            barraEl.style.background = '#d97706';
        } else {
            timerEl.className = '';
        }
    }

    atualizar();
    const intervalo = setInterval(atualizar, 500);
    </script>
</body>
</html>
HTML;

file_put_contents($htmlFile, $html);

echo PHP_EOL . '[OK] QR code gerado!' . PHP_EOL;
echo '[>>] Abra no navegador: http://localhost/beloscilios/qrcode_temp.html' . PHP_EOL;
echo PHP_EOL . '[LEMBRETE] Apague o arquivo qrcode_temp.html após escanear.' . PHP_EOL;
