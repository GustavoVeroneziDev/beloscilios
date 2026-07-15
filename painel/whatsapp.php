<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

$paginaTitulo = 'WhatsApp';
$areaAtual    = 'painel';
$csrfToken    = gerarTokenCSRF();
require_once __DIR__ . '/../geral/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-whatsapp fs-4" style="color:#25d366;"></i>
    <h4 class="fw-bold mb-0">WhatsApp</h4>
</div>

<?php flashMsg() ?>

<div class="row g-4 justify-content-center">
    <div class="col-12 col-md-8 col-lg-6">

        <!-- Status -->
        <div class="card mb-4">
            <div class="card-body d-flex align-items-center gap-3 py-3 px-4">
                <div id="statusDot" style="width:14px;height:14px;border-radius:50%;background:#adb5bd;flex-shrink:0;transition:background .4s;"></div>
                <div class="flex-grow-1">
                    <div class="fw-semibold" id="statusLabel">Verificando…</div>
                    <div class="text-secondary small" id="statusSub"></div>
                </div>
                <button class="btn btn-sm btn-outline-secondary" id="btnAtualizar" onclick="verificarStatus()">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
            </div>
        </div>

        <!-- Painel de ação -->
        <div class="card">
            <div class="card-body p-4 text-center" id="painelAcao">

                <!-- Estado: conectado -->
                <div id="blocoConectado" style="display:none;">
                    <i class="bi bi-check-circle-fill fs-1 mb-3" style="color:#25d366;"></i>
                    <h5 class="fw-semibold mb-1">WhatsApp conectado!</h5>
                    <p class="text-secondary small mb-4">O robô está ativo e respondendo normalmente.</p>
                    <button class="btn btn-outline-danger btn-sm" onclick="desconectar()">
                        <i class="bi bi-box-arrow-right me-1"></i> Desconectar
                    </button>
                </div>

                <!-- Estado: desconectado / pronto para conectar -->
                <div id="blocoDesconectado">
                    <i class="bi bi-whatsapp fs-1 mb-3" style="color:#25d366;opacity:.4;"></i>
                    <h5 class="fw-semibold mb-1">WhatsApp desconectado</h5>
                    <p class="text-secondary small mb-4">Clique no botão abaixo para gerar o QR code e o código de pareamento.</p>
                    <button class="btn btn-accent" id="btnConectar" onclick="conectar()">
                        <i class="bi bi-qr-code me-2"></i> Gerar QR Code
                    </button>
                </div>

                <!-- Estado: aguardando leitura do QR -->
                <div id="blocoQR" style="display:none;">
                    <p class="text-secondary small mb-3">Abra o WhatsApp Business → <strong>Dispositivos conectados</strong> → <strong>Conectar dispositivo</strong></p>

                    <!-- QR Code -->
                    <div class="mb-4">
                        <div class="fw-semibold small text-secondary mb-2 text-uppercase" style="letter-spacing:.05em;">QR Code</div>
                        <!-- div renderizado pelo qrcode.js -->
                        <div id="divQR" style="display:inline-block;background:#fff;padding:10px;border-radius:8px;box-shadow:0 2px 16px rgba(0,0,0,.12);"></div>
                        <!-- fallback: img base64 quando code não disponível -->
                        <img id="imgQR" src="" alt="QR Code" class="img-fluid rounded"
                             style="display:none;max-width:220px;border:4px solid #fff;box-shadow:0 2px 16px rgba(0,0,0,.12);">
                    </div>

                    <!-- Código de pareamento -->
                    <div id="blocoCodigo" style="display:none;" class="mb-4">
                        <div class="fw-semibold small text-secondary mb-2 text-uppercase" style="letter-spacing:.05em;">Ou use o código</div>
                        <div id="pairingCode"
                             style="font-size:2rem;font-weight:700;letter-spacing:.35em;color:var(--accent);
                                    background:var(--bg-hover);border-radius:12px;padding:.5rem 1.5rem;display:inline-block;">
                        </div>
                    </div>

                    <p class="text-secondary" style="font-size:.78rem;">
                        <i class="bi bi-clock me-1"></i> O QR code expira em ~45 segundos. Se expirar,
                        <a href="#" onclick="conectar();return false;" class="text-accent">clique aqui para gerar um novo</a>.
                    </p>

                    <button class="btn btn-outline-secondary btn-sm mt-2" onclick="resetUI()">
                        <i class="bi bi-x me-1"></i> Cancelar
                    </button>
                </div>

            </div>
        </div>

    </div>
</div>

<input type="hidden" id="csrfToken" value="<?= h($csrfToken) ?>">

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
const API = '<?= BASE ?>/painel/api_whatsapp_qr.php';
let _pollTimer = null;

async function verificarStatus() {
    setStatusLoading();
    try {
        const r = await fetch(API + '?acao=status');
        const d = await r.json();
        aplicarStatus(d.state ?? 'unknown');
    } catch(e) {
        setStatus('gray', 'Erro ao verificar', '');
    }
}

function aplicarStatus(state) {
    if (state === 'open') {
        setStatus('#25d366', 'Conectado', 'WhatsApp está ativo');
        mostrar('blocoConectado');
        ocultar('blocoDesconectado');
        ocultar('blocoQR');
        pararPoll();
    } else if (state === 'connecting') {
        setStatus('#f59e0b', 'Conectando…', 'Aguardando leitura');
    } else {
        setStatus('#ef4444', 'Desconectado', 'O robô não está ativo');
        mostrar('blocoDesconectado');
        ocultar('blocoConectado');
        ocultar('blocoQR');
        pararPoll();
    }
}

async function conectar() {
    const btn = document.getElementById('btnConectar');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Gerando…'; }
    setStatus('#f59e0b', 'Aguardando conexão…', 'Escaneie o QR code ou insira o código');

    try {
        const r = await fetch(API + '?acao=conectar');
        const d = await r.json();

        if (!d.ok) {
            bcToast(d.msg || 'Erro ao gerar QR code.', 'danger');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-qr-code me-2"></i> Gerar QR Code'; }
            return;
        }
        if (d.state === 'open') {
            bcToast('WhatsApp já está conectado!', 'success');
            aplicarStatus('open');
            return;
        }

        // Exibe QR — preferência pelo campo "code" (dados brutos) via qrcode.js
        const divQR = document.getElementById('divQR');
        const imgQR = document.getElementById('imgQR');
        divQR.innerHTML = '';
        if (d.qrCode) {
            new QRCode(divQR, {
                text: d.qrCode,
                width: 220,
                height: 220,
                correctLevel: QRCode.CorrectLevel.M,
            });
            divQR.style.display = 'inline-block';
            imgQR.style.display = 'none';
        } else if (d.qr) {
            const src = d.qr.startsWith('data:') ? d.qr : 'data:image/png;base64,' + d.qr;
            imgQR.src = src;
            imgQR.style.display = '';
            divQR.style.display = 'none';
        }

        // Exibe pairing code
        const blocoCod = document.getElementById('blocoCodigo');
        if (d.pairingCode) {
            document.getElementById('pairingCode').textContent = d.pairingCode;
            blocoCod.style.display = '';
        } else {
            blocoCod.style.display = 'none';
        }

        ocultar('blocoDesconectado');
        ocultar('blocoConectado');
        mostrar('blocoQR');

        // Poll a cada 5s para detectar quando conectar
        iniciarPoll();

    } catch(e) {
        bcToast('Erro de conexão com a API.', 'danger');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-qr-code me-2"></i> Gerar QR Code'; }
    }
}

async function desconectar() {
    if (!confirm('Tem certeza que deseja desconectar o WhatsApp?')) return;
    try {
        const r = await fetch(API + '?acao=desconectar', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'csrf_token=' + encodeURIComponent(document.getElementById('csrfToken').value),
        });
        const d = await r.json();
        bcToast(d.msg || 'Desconectado.', d.ok ? 'success' : 'danger');
        if (d.ok) setTimeout(verificarStatus, 1000);
    } catch(e) {
        bcToast('Erro ao desconectar.', 'danger');
    }
}

function resetUI() {
    pararPoll();
    verificarStatus();
}

function iniciarPoll() {
    pararPoll();
    _pollTimer = setInterval(async () => {
        try {
            const r = await fetch(API + '?acao=status');
            const d = await r.json();
            if (d.state === 'open') {
                pararPoll();
                bcToast('WhatsApp conectado com sucesso! 🎉', 'success');
                aplicarStatus('open');
            }
        } catch(e) {}
    }, 5000);
}

function pararPoll() {
    if (_pollTimer) { clearInterval(_pollTimer); _pollTimer = null; }
}

// ── Helpers UI ─────────────────────────────────────────────────
function setStatus(cor, label, sub) {
    document.getElementById('statusDot').style.background   = cor;
    document.getElementById('statusLabel').textContent      = label;
    document.getElementById('statusSub').textContent        = sub;
}
function setStatusLoading() {
    setStatus('#adb5bd', 'Verificando…', '');
}
function mostrar(id) { document.getElementById(id).style.display = ''; }
function ocultar(id) { document.getElementById(id).style.display = 'none'; }

// Inicia verificando
verificarStatus();
</script>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
