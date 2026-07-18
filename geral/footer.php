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

<!-- Overlay de carregamento de página -->
<style>
#bcPageLoader{display:none;position:fixed;inset:0;z-index:99999;background:rgba(16,0,43,.84);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);align-items:center;justify-content:center;}
#bcPageLoader .bc-wrap{position:relative;width:76px;height:76px;}
#bcPageLoader .bc-ring{position:absolute;inset:0;border-radius:50%;border:3px solid rgba(199,125,255,.18);border-top-color:#c77dff;animation:bcSpin .85s linear infinite;}
#bcPageLoader .bc-logo{position:absolute;width:54px;height:54px;border-radius:50%;top:50%;left:50%;transform:translate(-50%,-50%);object-fit:cover;box-shadow:0 0 18px rgba(90,24,154,.55);}
@keyframes bcSpin{to{transform:rotate(360deg);}}
</style>
<div id="bcPageLoader" role="status" aria-label="Carregando...">
    <div class="bc-wrap">
        <div class="bc-ring"></div>
        <img src="<?= BASE ?>/geral/img/LogoCírculo.png" class="bc-logo" alt="">
    </div>
</div>

<!-- Modal de mensagem WhatsApp — gerada com dados reais + IA (editável antes de enviar) -->
<div class="modal fade" id="modalWaMensagem" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px;overflow:hidden;">
            <div class="modal-header border-bottom" style="background:var(--bg-card,#fff);">
                <div>
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-whatsapp text-success fs-5"></i>
                        <span class="fw-semibold" id="bcWaMsgLabel" style="color:var(--text-main,#111);"></span>
                    </div>
                    <div class="small text-secondary mt-1">Para: <strong id="bcWaMsgNome" style="color:var(--roxo-principal,#5a189a);"></strong></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar" id="bcWaMsgFechar"></button>
            </div>
            <div class="modal-body" style="background:var(--bg-card,#fff);min-height:160px;">
                <!-- Estado: carregando -->
                <div id="bcWaMsgLoading" class="text-center py-4">
                    <div class="spinner-border text-success mb-2" role="status" style="width:1.8rem;height:1.8rem;"></div>
                    <div class="small text-secondary">Gerando mensagem com os dados do cliente…</div>
                </div>
                <!-- Estado: pronta -->
                <div id="bcWaMsgPronta" style="display:none;">
                    <div class="d-flex align-items-center gap-1 mb-2" style="font-size:.78rem;color:#888;">
                        <i class="bi bi-stars text-warning"></i>
                        <span>Gerada com IA — edite à vontade antes de enviar</span>
                    </div>
                    <textarea id="bcWaMsgTexto" class="form-control" rows="7"
                        style="resize:vertical;font-size:.93rem;line-height:1.6;font-family:inherit;"></textarea>
                    <div class="d-flex justify-content-between mt-1" style="font-size:.72rem;color:#aaa;">
                        <span id="bcWaMsgCount">0 caracteres</span>
                        <span>Enter = nova linha</span>
                    </div>
                </div>
                <!-- Estado: erro -->
                <div id="bcWaMsgErro" style="display:none;" class="text-center py-3">
                    <i class="bi bi-exclamation-circle text-danger fs-2 d-block mb-2"></i>
                    <span class="small text-secondary" id="bcWaMsgErroMsg">Erro ao gerar mensagem.</span>
                </div>
            </div>
            <div class="modal-footer border-top gap-2" style="background:var(--bg-card,#fff);">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success px-4" id="bcWaMsgEnviar" disabled>
                    <i class="bi bi-whatsapp me-1"></i>Abrir no WhatsApp
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Reagendamento -->
<div class="modal fade" id="modalReagendar" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px;overflow:hidden;">
            <div class="modal-header border-bottom" style="background:var(--bg-card,#fff);">
                <div>
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-calendar-event" style="color:var(--accent);font-size:1.1rem;"></i>
                        <span class="fw-semibold" style="color:var(--text-main,#111);">Reagendar atendimento</span>
                    </div>
                    <div class="small text-secondary mt-1">
                        Cliente: <strong id="reagNomeCliente" style="color:var(--roxo-principal,#5a189a);"></strong>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body" style="background:var(--bg-card,#fff);">
                <!-- Estado: picker -->
                <div id="reagPicker">
                    <p class="small text-secondary mb-3">
                        <i class="bi bi-clock me-1"></i>Horário atual:
                        <strong id="reagDataAtual"></strong>
                    </p>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Nova data <span class="text-danger">*</span></label>
                        <input type="date" id="reagNovaData" class="form-control" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-medium">Novo horário <span class="text-danger">*</span></label>
                        <input type="time" id="reagNovaHora" class="form-control" step="900" required>
                    </div>
                </div>
                <!-- Estado: loading -->
                <div id="reagLoading" style="display:none;" class="text-center py-4">
                    <div class="spinner-border mb-2" style="color:var(--accent);width:1.8rem;height:1.8rem;" role="status"></div>
                    <div class="small text-secondary">Reagendando…</div>
                </div>
                <!-- Estado: sucesso -->
                <div id="reagSucesso" style="display:none;" class="text-center py-3">
                    <i class="bi bi-check-circle-fill text-success fs-2 d-block mb-2"></i>
                    <p class="fw-semibold mb-1">Reagendado!</p>
                    <p class="text-secondary small mb-3">Novo horário: <strong id="reagSuccessInfo"></strong></p>
                    <p class="small mb-3">Deseja notificar <strong id="reagNomeNotif"></strong> via WhatsApp?</p>
                    <div class="d-flex gap-2 justify-content-center">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Não, obrigada</button>
                        <button type="button" class="btn btn-success" id="reagNotificarBtn">
                            <i class="bi bi-whatsapp me-1"></i>Sim, notificar
                        </button>
                    </div>
                </div>
                <!-- Estado: erro -->
                <div id="reagErro" style="display:none;" class="text-center py-3">
                    <i class="bi bi-exclamation-circle text-danger fs-2 d-block mb-2"></i>
                    <p class="small text-secondary mb-3" id="reagErroMsg">Erro ao reagendar.</p>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="reagTentarNovamente">
                        Tentar novamente
                    </button>
                </div>
            </div>
            <div class="modal-footer border-top gap-2" style="background:var(--bg-card,#fff);">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-accent" id="reagConfirmarBtn">
                    <i class="bi bi-calendar-check me-1"></i>Confirmar reagendamento
                </button>
            </div>
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

// ── Modal de mensagem WhatsApp com IA ────────────────────────────────────────
(function () {
    var _tel = '';
    var modal, loading, pronta, erro, erroMsg, textarea, count, enviar;

    function init() {
        modal    = document.getElementById('modalWaMensagem');
        loading  = document.getElementById('bcWaMsgLoading');
        pronta   = document.getElementById('bcWaMsgPronta');
        erro     = document.getElementById('bcWaMsgErro');
        erroMsg  = document.getElementById('bcWaMsgErroMsg');
        textarea = document.getElementById('bcWaMsgTexto');
        count    = document.getElementById('bcWaMsgCount');
        enviar   = document.getElementById('bcWaMsgEnviar');
        if (!modal) return;

        textarea.addEventListener('input', function () {
            count.textContent = textarea.value.length + ' caracteres';
        });

        enviar.addEventListener('click', function () {
            if (!_tel) return;
            var txt = textarea.value.trim();
            var url = 'https://wa.me/' + _tel;
            if (txt) url += '?text=' + encodeURIComponent(txt);
            window.open(url, '_blank');
            bootstrap.Modal.getInstance(modal).hide();
        });
    }

    function mostrarEstado(estado) {
        loading.style.display = estado === 'loading' ? '' : 'none';
        pronta.style.display  = estado === 'pronta'  ? '' : 'none';
        erro.style.display    = estado === 'erro'    ? '' : 'none';
        enviar.disabled       = estado === 'loading';
    }

    function dispararMensagem(opts) {
        var acao  = opts.acao  || '';
        var agId  = opts.agId  || '';
        var cliId = opts.cliId || '';
        var nome  = opts.nome  || '';
        var label = opts.label || 'Mensagem';
        var tel   = opts.tel   || '';

        if (!acao) return;
        if (!modal) init();
        if (!modal) return;

        document.getElementById('bcWaMsgLabel').textContent = label;
        document.getElementById('bcWaMsgNome').textContent  = nome;
        _tel = tel;

        mostrarEstado('loading');
        bootstrap.Modal.getOrCreateInstance(modal).show();

        var base = (typeof BASE !== 'undefined' ? BASE : '');
        fetch(base + '/painel/api_wa_mensagem.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ acao: acao, agendamento_id: agId, cliente_id: cliId, tel: tel }),
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.ok) {
                _tel = d.tel || _tel;
                textarea.value = d.mensagem || '';
                count.textContent = textarea.value.length + ' caracteres';
                mostrarEstado('pronta');
                setTimeout(function () { textarea.focus(); }, 200);
            } else {
                erroMsg.textContent = d.msg || 'Erro ao gerar mensagem.';
                mostrarEstado('erro');
            }
        })
        .catch(function () {
            erroMsg.textContent = 'Falha na conexao. Tente novamente.';
            mostrarEstado('erro');
        });
    }

    window.bcAbrirWaMensagem = dispararMensagem;

    document.addEventListener('click', function (e) {
        var el = e.target.closest('.bc-wa-msg');
        if (!el) return;
        e.preventDefault();
        dispararMensagem({
            acao:  el.dataset.acao  || '',
            agId:  el.dataset.agId  || '',
            cliId: el.dataset.cliId || '',
            nome:  el.dataset.nome  || '',
            label: el.dataset.label || 'Mensagem',
            tel:   el.dataset.tel   || '',
        });
    });

    document.addEventListener('DOMContentLoaded', init);
})();
// ── Modal de Reagendamento ───────────────────────────────────────────────────
(function () {
    var _agId, _cliId, _tel, _nome;
    var modalEl, mPicker, mLoading, mSucesso, mErro;
    var dataIn, horaIn, confirmarBtn, erroEl, successInfo;

    function initR() {
        modalEl     = document.getElementById('modalReagendar');
        if (!modalEl) return;
        mPicker     = document.getElementById('reagPicker');
        mLoading    = document.getElementById('reagLoading');
        mSucesso    = document.getElementById('reagSucesso');
        mErro       = document.getElementById('reagErro');
        dataIn      = document.getElementById('reagNovaData');
        horaIn      = document.getElementById('reagNovaHora');
        confirmarBtn = document.getElementById('reagConfirmarBtn');
        erroEl      = document.getElementById('reagErroMsg');
        successInfo = document.getElementById('reagSuccessInfo');
        confirmarBtn.addEventListener('click', submeter);
    }

    function estado(s) {
        mPicker.style.display  = s === 'picker'  ? '' : 'none';
        mLoading.style.display = s === 'loading' ? '' : 'none';
        mSucesso.style.display = s === 'sucesso' ? '' : 'none';
        mErro.style.display    = s === 'erro'    ? '' : 'none';
        confirmarBtn.style.display = s === 'picker' ? '' : 'none';
    }

    function submeter() {
        var data = dataIn.value, hora = horaIn.value;
        if (!data || !hora) { dataIn.reportValidity(); return; }
        estado('loading');
        var base = (typeof BASE !== 'undefined' ? BASE : '');
        fetch(base + '/painel/api_reagendar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ agendamento_id: _agId, nova_data_hora: data + ' ' + hora }),
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.ok) {
                if (d.tel)   _tel   = d.tel;
                if (d.cliId) _cliId = d.cliId;
                successInfo.textContent = d.novaData + ' às ' + d.novaHora;
                var nNotif = document.getElementById('reagNomeNotif');
                if (nNotif) nNotif.textContent = _nome;
                estado('sucesso');
            } else {
                erroEl.textContent = d.msg || 'Erro ao reagendar.';
                estado('erro');
            }
        })
        .catch(function () {
            erroEl.textContent = 'Falha na conexão.';
            estado('erro');
        });
    }

    // Abre o modal ao clicar em .bc-reagendar-btn
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.bc-reagendar-btn');
        if (!btn) return;
        _agId  = btn.dataset.agId  || '';
        _cliId = btn.dataset.cliId || '';
        _tel   = btn.dataset.tel   || '';
        _nome  = btn.dataset.nome  || '';
        var horaAtual   = btn.dataset.hora   || '';
        var dataAtualBr = btn.dataset.dataBr || '';
        if (!modalEl) initR();
        if (!modalEl) return;
        document.getElementById('reagNomeCliente').textContent = _nome;
        document.getElementById('reagDataAtual').textContent   =
            dataAtualBr ? dataAtualBr + ' às ' + horaAtual : horaAtual;
        dataIn.value = '';
        dataIn.min   = new Date().toISOString().slice(0, 10);
        if (horaAtual) horaIn.value = horaAtual;
        estado('picker');
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    });

    // "Notificar" no estado de sucesso
    document.addEventListener('click', function (e) {
        if (!e.target.closest('#reagNotificarBtn')) return;
        bootstrap.Modal.getInstance(modalEl).hide();
        setTimeout(function () {
            if (typeof window.bcAbrirWaMensagem === 'function') {
                window.bcAbrirWaMensagem({
                    acao:  'reagendar',
                    agId:  _agId,
                    cliId: _cliId,
                    tel:   _tel,
                    nome:  _nome,
                    label: 'Reagendamento',
                });
            }
        }, 350);
    });

    // Botão "Tentar novamente"
    document.addEventListener('click', function (e) {
        if (!e.target.closest('#reagTentarNovamente')) return;
        estado('picker');
    });

    document.addEventListener('DOMContentLoaded', initR);
})();
// ─────────────────────────────────────────────────────────────────────────────

// Registra o Service Worker
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('<?= BASE ?>/sw.php', {
            scope: '<?= BASE ?>/',
            updateViaCache: 'none'
        }).then(function (reg) {
            reg.update(); // força verificação imediata de nova versão
        }).catch(function (e) { console.warn('SW:', e); });
    });
}

// ── Fix: dropdowns cortados por overflow:auto em .table-responsive / cards ───
// Bootstrap 5 usa Popper com position:absolute por padrão — dentro de
// containers com overflow:hidden/auto o menu é clipado. strategy:'fixed'
// renderiza relativo ao viewport e resolve.
(function () {
    function initDropdown(el) {
        if (!el.matches || !el.matches('[data-bs-toggle="dropdown"]')) return;
        var old = bootstrap.Dropdown.getInstance(el);
        if (old) old.dispose();
        new bootstrap.Dropdown(el, { popperConfig: { strategy: 'fixed' } });
    }
    window.addEventListener('load', function () {
        document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(initDropdown);
    });
    // Cobre dropdowns criados dinamicamente (ex: calendário da agenda)
    new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
            m.addedNodes.forEach(function (node) {
                if (node.nodeType !== 1) return;
                if (node.matches && node.matches('[data-bs-toggle="dropdown"]')) initDropdown(node);
                node.querySelectorAll && node.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(initDropdown);
            });
        });
    }).observe(document.body, { childList: true, subtree: true });
})();
// ─────────────────────────────────────────────────────────────────────────────

// ── Overlay de carregamento de página ────────────────────────────────────────
(function () {
    var loader = document.getElementById('bcPageLoader');
    if (!loader) return;
    function mostrar() { loader.style.display = 'flex'; }
    function esconder() { loader.style.display = 'none'; }
    window._bcLoaderMostrar = mostrar;

    // Esconde quando a página carrega (inclui restauração por bfcache no back/forward)
    window.addEventListener('pageshow', esconder);

    // Clique em links internos (exclui âncoras, modais Bootstrap, data-confirm e externos)
    document.addEventListener('click', function (e) {
        var a = e.target.closest('a[href]');
        if (!a || a.dataset.confirm || e.defaultPrevented) return;
        if (a.target === '_blank') return;
        if (a.dataset.bsToggle || a.dataset.bsDismiss || a.dataset.bsTarget) return;
        var href = a.getAttribute('href') || '';
        if (!href || href === '#' || href.charAt(0) === '#') return;
        if (/^(javascript|mailto|tel):/.test(href)) return;
        mostrar();
    });

    // Submit de form (só quando a navegação vai mesmo acontecer)
    document.addEventListener('submit', function (e) {
        if (e.target.dataset.noLoader) return;
        var ev = e;
        setTimeout(function () { if (!ev.defaultPrevented) mostrar(); }, 0);
    });

    // form.submit() programático não dispara o evento — captura aqui
    var origSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function () {
        if (!this.dataset.noLoader) mostrar();
        origSubmit.call(this);
    };
})();
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
        else if (el.href) { window._bcLoaderMostrar?.(); location.href = el.href; }
    }, el.dataset.confirmLabel);
});
</script>
</body>
</html>
