<?php if ($ehPainel ?? false): ?>
</div><!-- /painel-content -->
<?php else: ?>
</main>

<footer class="border-top py-4 mt-5" style="background:var(--bg-card);">
    <div class="container-lg text-center text-secondary small">
        <span style="color:var(--accent);font-weight:600;">Belos Cílios</span> &copy; <?= date('Y') ?>
        &nbsp;·&nbsp; Todos os direitos reservados
        &nbsp;·&nbsp; <span style="opacity:.4;font-size:.8em;" title="<?= APP_BUILD_DATE ?>">build <?= APP_VERSAO ?></span>
    </div>
</footer>
<?php endif ?>

<!-- Bootstrap JS (self-hosted, cache 1 ano) -->
<script src="<?= BASE ?>/geral/vendor/bs/js/bootstrap.bundle.min.js"></script>

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

<script>
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
