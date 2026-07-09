<?php if ($ehPainel ?? false): ?>
</div><!-- /painel-content -->
<?php else: ?>
</main>

<footer class="border-top py-4 mt-auto" style="background:var(--bg-card);">
    <div class="container-lg text-center text-secondary small">
        <span style="color:var(--accent);font-weight:600;">Belos Cílios</span> &copy; <?= date('Y') ?>
        &nbsp;·&nbsp; Todos os direitos reservados
        &nbsp;·&nbsp; <span style="opacity:.4;font-size:.8em;" title="<?= APP_BUILD_DATE ?>">build <?= APP_VERSAO ?></span>
    </div>
    <div class="container-lg text-center mt-2" style="font-size:.72rem;opacity:.45;">
        Desenvolvido por <a href="https://gustavoveronezi.com" target="_blank" rel="noopener"
            style="color:#3b82f6;text-decoration:none;font-weight:500;">GVTech</a>
    </div>
</footer>
<?php endif ?>

<!-- Bootstrap JS (self-hosted, cache 1 ano) -->
<script src="<?= BASE ?>/geral/vendor/bs/js/bootstrap.bundle.min.js?v=<?= APP_VERSAO ?>"></script>

<!-- Modal de confirmação global -->
<div class="modal fade" id="modalConfirm" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg" style="border-radius:14px;">
            <div class="modal-body text-center px-4 pt-4 pb-2">
                <i class="bi bi-exclamation-circle mb-3 d-block" style="font-size:2.4rem;color:var(--accent);"></i>
                <p class="fw-semibold mb-0" id="modalConfirmMsg" style="color:var(--text-main);"></p>
            </div>
            <div class="modal-footer justify-content-center border-0 pt-2 pb-4 gap-2">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger px-4" id="modalConfirmOk">Confirmar</button>
            </div>
        </div>
    </div>
</div>

<!-- Toast global -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index:9999;">
    <div id="bcToastEl" class="toast align-items-center border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body fw-medium" id="bcToastMsg"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<!-- Modal de instalação PWA -->
<div class="modal fade" id="modalPwa" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg" style="border-radius:20px;overflow:hidden;">
            <div class="modal-body text-center px-4 pt-4 pb-3">
                <img src="<?= BASE ?>/geral/img/LogoCírculo.png" alt="Belos Cílios"
                     style="width:80px;height:80px;object-fit:contain;border-radius:20px;margin-bottom:1rem;box-shadow:0 4px 16px rgba(90,24,154,.25);">
                <h5 class="fw-bold mb-1" style="color:var(--accent);">Instale o app</h5>
                <p class="text-secondary small mb-0">
                    Adicione à tela inicial para acessar rapidamente, como um app nativo — sem precisar abrir o navegador.
                </p>
            </div>
            <div class="modal-footer border-0 pt-0 pb-4 px-4 flex-column gap-2">
                <button class="btn btn-accent w-100" onclick="bcPwaInstalar(true)">
                    <i class="bi bi-download me-2"></i>Instalar agora
                </button>
                <button class="btn btn-link text-secondary small w-100 p-0" data-bs-dismiss="modal" onclick="bcPwaDescartar()">
                    Agora não
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ── PWA ───────────────────────────────────────────────────────────────────────
var _bcPwaEvento = null;

window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    _bcPwaEvento = e;
    // Mostra botões fixos em todos os contextos
    document.querySelectorAll('#btnPwaSidebar,#btnPwaNav').forEach(function (b) { b.style.display = ''; });
    // Mostra o modal automático apenas na página marcada e se não foi descartado
    if (document.body.dataset.pwaModal === '1' && !localStorage.getItem('bc_pwa_ok')) {
        setTimeout(function () {
            var m = document.getElementById('modalPwa');
            if (m) bootstrap.Modal.getOrCreateInstance(m).show();
        }, 1200);
    }
});

window.addEventListener('appinstalled', function () {
    _bcPwaEvento = null;
    localStorage.setItem('bc_pwa_ok', '1');
    document.querySelectorAll('#btnPwaSidebar,#btnPwaNav').forEach(function (b) { b.style.display = 'none'; });
    var m = document.getElementById('modalPwa');
    if (m) bootstrap.Modal.getInstance(m)?.hide();
});

function bcPwaInstalar(fecharModal) {
    if (!_bcPwaEvento) return;
    _bcPwaEvento.prompt();
    _bcPwaEvento.userChoice.then(function (r) {
        if (r.outcome === 'accepted') {
            localStorage.setItem('bc_pwa_ok', '1');
            document.querySelectorAll('#btnPwaSidebar,#btnPwaNav').forEach(function (b) { b.style.display = 'none'; });
        }
        _bcPwaEvento = null;
    });
    if (fecharModal) {
        var m = document.getElementById('modalPwa');
        if (m) bootstrap.Modal.getInstance(m)?.hide();
    }
}

function bcPwaDescartar() {
    localStorage.setItem('bc_pwa_ok', '1');
}

// Registra o Service Worker
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('<?= BASE ?>/sw.php', { scope: '<?= BASE ?>/' })
            .catch(function (e) { console.warn('SW:', e); });
    });
}
// ─────────────────────────────────────────────────────────────────────────────

function abrirSidebar() {
    document.getElementById('sidebar').classList.add('aberta');
    document.getElementById('sidebarOverlay').classList.add('ativo');
}
function fecharSidebar() {
    document.getElementById('sidebar')?.classList.remove('aberta');
    document.getElementById('sidebarOverlay')?.classList.remove('ativo');
}

// Auto-dismiss alerts após 5s
document.querySelectorAll('.alert.fade').forEach(el => {
    setTimeout(() => bootstrap.Alert.getOrCreateInstance(el)?.close(), 5000);
});

// ── Toast global ─────────────────────────────────────────────
function bcToast(msg, tipo) {
    var el = document.getElementById('bcToastEl');
    el.className = 'toast align-items-center border-0 text-bg-' + (tipo || 'warning');
    document.getElementById('bcToastMsg').textContent = msg;
    bootstrap.Toast.getOrCreateInstance(el, { delay: 4500 }).show();
}

// ── Modal de confirmação global ───────────────────────────────
function bcConfirm(msg, onOk, label) {
    document.getElementById('modalConfirmMsg').textContent = msg;
    var okBtn = document.getElementById('modalConfirmOk');
    okBtn.textContent = label || 'Confirmar';
    var novo = okBtn.cloneNode(true);
    okBtn.parentNode.replaceChild(novo, okBtn);
    novo.addEventListener('click', function () {
        bootstrap.Modal.getInstance(document.getElementById('modalConfirm')).hide();
        onOk();
    });
    new bootstrap.Modal(document.getElementById('modalConfirm')).show();
}

// ── Máscara de telefone ───────────────────────────────────────
function bcMascaraTel(input) {
    function fmt() {
        var d = input.value.replace(/\D/g, '').slice(0, 11);
        if (!d)           { input.value = ''; return; }
        if (d.length <= 2)  { input.value = '(' + d; return; }
        if (d.length <= 6)  { input.value = '(' + d.slice(0,2) + ') ' + d.slice(2); return; }
        if (d.length <= 10) { input.value = '(' + d.slice(0,2) + ') ' + d.slice(2,6) + '-' + d.slice(6); return; }
        input.value = '(' + d.slice(0,2) + ') ' + d.slice(2,7) + '-' + d.slice(7);
    }
    input.addEventListener('input', fmt);
    input.setAttribute('inputmode', 'numeric');
}
document.querySelectorAll('[data-mask="tel"]').forEach(bcMascaraTel);

// ── data-confirm em forms e botões ────────────────────────────
document.addEventListener('submit', function (e) {
    var form = e.target, msg = form.dataset.confirm;
    if (msg && !form.dataset.confirmed) {
        e.preventDefault();
        bcConfirm(msg, function () { form.dataset.confirmed = '1'; form.submit(); }, form.dataset.confirmLabel);
    }
});
document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-confirm]');
    if (!el || el.tagName === 'FORM') return;
    e.preventDefault();
    bcConfirm(el.dataset.confirm, function () {
        var form = el.closest('form');
        if (form) { form.dataset.confirmed = '1'; form.submit(); }
        else if (el.href) { location.href = el.href; }
    }, el.dataset.confirmLabel);
});
</script>
</body>
</html>
