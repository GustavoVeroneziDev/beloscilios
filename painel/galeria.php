<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

// Carrega categorias do banco
$categorias = $pdo->query('SELECT * FROM CategoriasGaleria ORDER BY Ordem ASC')->fetchAll();
$catMap = [];
foreach ($categorias as $c) $catMap[$c['IDCategoria']] = $c['Nome'];

$catFiltro = trim($_GET['cat'] ?? '');
if ($catFiltro && !isset($catMap[$catFiltro])) $catFiltro = '';

$sql    = 'SELECT i.*, c.Nome AS CategoriaNome
           FROM Imagens i
           LEFT JOIN CategoriasGaleria c ON c.IDCategoria = i.Categoria';
$params = [];
if ($catFiltro) {
    $sql .= ' WHERE i.Categoria = :cat';
    $params[':cat'] = $catFiltro;
}
$sql .= ' ORDER BY i.MomentoRegistro DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$imagens = $stmt->fetchAll();

$totalGeral = (int) $pdo->query('SELECT COUNT(*) FROM Imagens')->fetchColumn();

$csrfToken    = gerarTokenCSRF();
$paginaTitulo = 'Galeria de Imagens';
$areaAtual    = 'painel';
require_once __DIR__ . '/../geral/header.php';
?>

<style>
.gal-card { border-radius:12px;overflow:hidden;transition:box-shadow .2s; }
.gal-card:hover { box-shadow:0 6px 24px rgba(90,24,154,.18)!important; }
.gal-thumb { aspect-ratio:4/3;overflow:hidden;background:#1a003a;position:relative; }
.gal-thumb img { width:100%;height:100%;object-fit:cover;transition:transform .35s; }
.gal-card:hover .gal-thumb img { transform:scale(1.05); }
.gal-cat-badge {
    position:absolute;top:8px;right:8px;
    background:rgba(16,0,43,.72);color:#e0aaff;
    font-size:.62rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
    padding:.2rem .55rem;border-radius:6px;backdrop-filter:blur(4px);
}
.gal-overlay {
    position:absolute;inset:0;
    background:linear-gradient(to top, rgba(16,0,43,.55) 0%, transparent 55%);
    opacity:0;transition:opacity .25s;
    display:flex;align-items:flex-end;justify-content:flex-end;padding:.5rem;gap:.35rem;
}
.gal-card:hover .gal-overlay { opacity:1; }
.upload-dropzone {
    display:block;
    border:2px dashed var(--accent);
    border-radius:14px;
    padding:2.5rem 1.5rem;
    text-align:center;
    cursor:pointer;
    transition:background .2s, border-color .2s;
}
.upload-dropzone:hover,
.upload-dropzone.dragover { background:rgba(90,24,154,.07);border-color:var(--roxo-400,#9d4edd); }
.cat-row { display:flex;align-items:center;gap:.5rem;padding:.6rem .75rem;border-radius:8px;transition:background .15s; }
.cat-row:hover { background:var(--bg-hover); }
.cat-row-nome { flex:1;font-size:.9rem;font-weight:500; }
.cat-form-section { border-top:1px solid var(--card-border-color);padding-top:1rem;margin-top:.5rem; }
</style>

<!-- Cabeçalho -->
<div class="d-flex align-items-start justify-content-between mb-4 flex-wrap gap-3">
    <div>
        <h4 class="fw-bold mb-0">Galeria de Imagens</h4>
        <p class="text-secondary small mb-0">
            <?= $totalGeral ?> imagem<?= $totalGeral != 1 ? 's' : '' ?> no total
        </p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-accent" data-bs-toggle="modal" data-bs-target="#modalCats">
            <i class="bi bi-tags me-2"></i>Categorias
        </button>
        <button class="btn btn-accent" data-bs-toggle="modal" data-bs-target="#modalUpload">
            <i class="bi bi-plus-lg me-2"></i>Adicionar imagem
        </button>
    </div>
</div>

<!-- Filtros -->
<div class="d-flex gap-2 mb-4 flex-wrap">
    <a href="<?= BASE ?>/painel/galeria.php"
       class="btn btn-sm <?= !$catFiltro ? 'btn-accent' : 'btn-outline-secondary' ?>">
        Todas <span class="badge bg-secondary ms-1"><?= $totalGeral ?></span>
    </a>
    <?php foreach ($categorias as $cat):
        $stm2 = $pdo->prepare('SELECT COUNT(*) FROM Imagens WHERE Categoria = :c');
        $stm2->execute([':c' => $cat['IDCategoria']]);
        $cnt = (int) $stm2->fetchColumn();
    ?>
    <a href="?cat=<?= h($cat['IDCategoria']) ?>"
       class="btn btn-sm <?= $catFiltro === $cat['IDCategoria'] ? 'btn-accent' : 'btn-outline-secondary' ?>">
        <?= h($cat['Nome']) ?> <span class="badge bg-secondary ms-1"><?= $cnt ?></span>
    </a>
    <?php endforeach ?>
</div>

<!-- Grid -->
<?php if (empty($imagens)): ?>
<div class="text-center py-5 text-secondary">
    <i class="bi bi-images" style="font-size:3.5rem;opacity:.25"></i>
    <p class="mt-3 mb-0">Nenhuma imagem<?= $catFiltro ? ' nesta categoria' : '' ?>.</p>
    <?php if ($catFiltro): ?>
    <a href="<?= BASE ?>/painel/galeria.php" class="btn btn-sm btn-outline-secondary mt-3">Ver todas</a>
    <?php endif ?>
</div>
<?php else: ?>
<div class="row g-3" id="galeriaGrid">
    <?php foreach ($imagens as $img): ?>
    <div class="col-6 col-md-4 col-xl-3" id="gcard-<?= h($img['IDImagem']) ?>">
        <div class="card border-0 shadow-sm gal-card h-100">
            <div class="gal-thumb">
                <img src="<?= BASE ?>/geral/img/galeria/<?= h($img['NomeArquivo']) ?>"
                     alt="<?= h($img['TituloExibicao'] ?? '') ?>"
                     loading="lazy">
                <span class="gal-cat-badge"><?= h($img['CategoriaNome'] ?? '—') ?></span>
                <div class="gal-overlay">
                    <button class="btn btn-sm btn-light py-1 px-2"
                            onclick="copiarUrl('<?= BASE ?>/geral/img/galeria/<?= h($img['NomeArquivo']) ?>')"
                            title="Copiar URL">
                        <i class="bi bi-link-45deg"></i>
                    </button>
                    <button class="btn btn-sm btn-light py-1 px-2"
                            onclick="abrirEditar('<?= h($img['IDImagem']) ?>','<?= h(addslashes($img['TituloExibicao'] ?? '')) ?>','<?= h($img['Categoria']) ?>')"
                            title="Editar">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-sm btn-danger py-1 px-2"
                            onclick="confirmarDelete('<?= h($img['IDImagem']) ?>','<?= h(addslashes($img['TituloExibicao'] ?? $img['NomeArquivo'])) ?>')"
                            title="Deletar">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
            <div class="px-2 pt-2 pb-2">
                <?php if ($img['TituloExibicao']): ?>
                <div class="small fw-semibold text-truncate mb-1 gal-titulo" title="<?= h($img['TituloExibicao']) ?>">
                    <?= h($img['TituloExibicao']) ?>
                </div>
                <?php endif ?>
                <div class="d-flex align-items-center justify-content-between">
                    <span class="text-secondary" style="font-size:.68rem;">
                        <?php if ($img['Largura']): ?>
                            <?= $img['Largura'] ?>×<?= $img['Altura'] ?>px · <?= round($img['TamanhoBytes'] / 1024) ?> KB
                        <?php else: ?>
                            <?= h($img['NomeArquivo']) ?>
                        <?php endif ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach ?>
</div>
<?php endif ?>


<!-- ══════════════════════════════════
     Modal: Gerenciar Categorias
════════════════════════════════════ -->
<div class="modal fade" id="modalCats" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:440px">
        <div class="modal-content border-0" style="border-radius:16px">

            <div class="modal-header border-0 pb-1">
                <h5 class="modal-title fw-bold"><i class="bi bi-tags me-2 text-accent"></i>Categorias</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body pt-1">
                <!-- Lista -->
                <div id="catLista">
                    <?php if (empty($categorias)): ?>
                    <p class="text-secondary small text-center py-3">Nenhuma categoria ainda.</p>
                    <?php else: ?>
                    <?php foreach ($categorias as $cat): ?>
                    <div class="cat-row" id="catrow-<?= h($cat['IDCategoria']) ?>">
                        <span class="cat-row-nome"><?= h($cat['Nome']) ?></span>
                        <button class="btn btn-sm btn-outline-secondary py-0 px-2"
                                onclick="editarCat('<?= h($cat['IDCategoria']) ?>','<?= h(addslashes($cat['Nome'])) ?>')"
                                title="Editar">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger py-0 px-2"
                                onclick="deletarCat('<?= h($cat['IDCategoria']) ?>','<?= h(addslashes($cat['Nome'])) ?>')"
                                title="Excluir">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                    <?php endforeach ?>
                    <?php endif ?>
                </div>

                <!-- Formulário inline (criar / editar) -->
                <div class="cat-form-section" id="catFormSection" style="display:none">
                    <p class="small fw-semibold mb-2" id="catFormTitulo">Nova categoria</p>
                    <input type="hidden" id="catFormId">
                    <div class="input-group input-group-sm">
                        <input type="text" id="catFormNome" class="form-control"
                               placeholder="Nome da categoria" maxlength="100">
                        <button class="btn btn-accent" onclick="salvarCat()">
                            <i class="bi bi-check-lg"></i>
                        </button>
                        <button class="btn btn-outline-secondary" onclick="fecharFormCat()">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-accent btn-sm" onclick="abrirFormCat()">
                    <i class="bi bi-plus-lg me-1"></i>Nova categoria
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm ms-auto" data-bs-dismiss="modal">
                    Fechar
                </button>
            </div>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════
     Modal: Editar Imagem
════════════════════════════════════ -->
<div class="modal fade" id="modalEditarImg" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:400px">
        <div class="modal-content border-0" style="border-radius:16px">
            <div class="modal-header border-0 pb-1">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil me-2 text-accent"></i>Editar imagem</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-1">
                <input type="hidden" id="editImgId">
                <div class="mb-3">
                    <label class="form-label small fw-semibold mb-1">Título <span class="text-secondary fw-normal">(opcional)</span></label>
                    <input type="text" id="editImgTitulo" class="form-control" placeholder="Ex: Volume Russo — resultado final" maxlength="255">
                </div>
                <div>
                    <label class="form-label small fw-semibold mb-1">Categoria</label>
                    <select id="editImgCat" class="form-select">
                        <?php foreach ($categorias as $cat): ?>
                        <option value="<?= h($cat['IDCategoria']) ?>"><?= h($cat['Nome']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-accent" id="btnSalvarEditar">
                    <i class="bi bi-check-lg me-1"></i>Salvar
                </button>
            </div>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════
     Modal de Upload
════════════════════════════════════ -->
<div class="modal fade" id="modalUpload" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0" style="border-radius:16px;">

            <div class="modal-header border-0 pb-1">
                <h5 class="modal-title fw-bold">Adicionar imagem</h5>
                <button type="button" class="btn-close" onclick="fecharModal()"></button>
            </div>

            <div class="modal-body pt-2">
                <!-- Passo 1 -->
                <div id="stepSelect">
                    <label for="inputImg" class="upload-dropzone" id="dropZone">
                        <i class="bi bi-cloud-arrow-up" style="font-size:3rem;color:var(--accent)"></i>
                        <p class="mt-2 mb-1 fw-semibold">Clique para escolher ou arraste aqui</p>
                        <p class="text-secondary small mb-0">JPEG · PNG · WebP — máx. 20 MB</p>
                    </label>
                    <input type="file" id="inputImg" accept="image/jpeg,image/png,image/webp" class="d-none">
                </div>

                <!-- Passo 2 -->
                <div id="stepCrop" style="display:none">
                    <div id="cropWrap" style="max-height:400px;background:#0d0020;border-radius:10px;overflow:hidden;">
                        <img id="imgCropper" style="max-width:100%;display:block;">
                    </div>

                    <div class="mt-3 d-flex align-items-center gap-2 flex-wrap">
                        <span class="text-secondary small fw-semibold me-1">Proporção:</span>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-secondary" onclick="setAspect(NaN)">Livre</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="setAspect(1)">1:1</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="setAspect(4/3)">4:3</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="setAspect(16/9)">16:9</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="setAspect(3/4)">3:4</button>
                        </div>
                        <div class="btn-group btn-group-sm ms-auto" role="group">
                            <button type="button" class="btn btn-outline-secondary"
                                    onclick="cropperIns&&cropperIns.rotate(-90)" title="Girar esquerda">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </button>
                            <button type="button" class="btn btn-outline-secondary"
                                    onclick="cropperIns&&cropperIns.rotate(90)" title="Girar direita">
                                <i class="bi bi-arrow-clockwise"></i>
                            </button>
                            <button type="button" class="btn btn-outline-secondary"
                                    onclick="cropperIns&&cropperIns.reset()" title="Resetar">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                    </div>
                    <p class="text-secondary" style="font-size:.72rem;margin-top:.4rem;">
                        Arraste para mover · Scroll para zoom · Ajuste as alças para recortar
                    </p>

                    <div class="row g-2 mt-1">
                        <div class="col-sm-7">
                            <label class="form-label small fw-semibold mb-1">Título <span class="text-secondary fw-normal">(opcional)</span></label>
                            <input type="text" id="inputTitulo" class="form-control form-control-sm"
                                   placeholder="Ex: Volume Russo — resultado final">
                        </div>
                        <div class="col-sm-5">
                            <label class="form-label small fw-semibold mb-1">Categoria</label>
                            <select id="selectCat" class="form-select form-select-sm">
                                <?php foreach ($categorias as $cat): ?>
                                <option value="<?= h($cat['IDCategoria']) ?>"><?= h($cat['Nome']) ?></option>
                                <?php endforeach ?>
                            </select>
                        </div>
                    </div>

                    <div id="uploadProg" style="display:none" class="mt-3">
                        <div class="progress" style="height:5px">
                            <div class="progress-bar progress-bar-striped progress-bar-animated"
                                 style="width:100%;background:var(--accent)"></div>
                        </div>
                        <p class="text-secondary small mt-1 mb-0">Enviando…</p>
                    </div>
                </div>
            </div>

            <div class="modal-footer border-0 pt-1 gap-2">
                <button type="button" class="btn btn-outline-secondary" onclick="fecharModal()">Cancelar</button>
                <button type="button" class="btn btn-outline-accent" id="btnOriginal"
                        style="display:none" onclick="salvarOriginal()">
                    <i class="bi bi-image me-1"></i>Salvar original
                </button>
                <button type="button" class="btn btn-accent" id="btnCrop"
                        style="display:none" onclick="salvarCrop()">
                    <i class="bi bi-crop me-1"></i>Salvar recortada
                </button>
            </div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://unpkg.com/cropperjs@1.6.2/dist/cropper.min.css">
<script src="https://unpkg.com/cropperjs@1.6.2/dist/cropper.min.js"></script>

<script>
(function () {
    var CSRF    = '<?= $csrfToken ?>';
    var BASE    = '<?= BASE ?>';
    var cropperIns  = null;
    var arquivoOrig = null;
    var enviando    = false;
    window.cropperIns = null;

    // ── Categorias CRUD ──────────────────────────────────────────
    window.abrirFormCat = function(id, nome) {
        document.getElementById('catFormSection').style.display = 'block';
        document.getElementById('catFormId').value    = id   || '';
        document.getElementById('catFormNome').value  = nome || '';
        document.getElementById('catFormTitulo').textContent = id ? 'Editar categoria' : 'Nova categoria';
        document.getElementById('catFormNome').focus();
    };
    window.editarCat = function(id, nome) { abrirFormCat(id, nome); };
    window.fecharFormCat = function() {
        document.getElementById('catFormSection').style.display = 'none';
        document.getElementById('catFormId').value   = '';
        document.getElementById('catFormNome').value = '';
    };

    window.salvarCat = function() {
        var id   = document.getElementById('catFormId').value.trim();
        var nome = document.getElementById('catFormNome').value.trim();
        if (!nome) { bcToast('Informe um nome.', 'warning'); return; }

        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('acao', id ? 'editar' : 'criar');
        fd.append('nome', nome);
        if (id) fd.append('id', id);

        fetch(BASE + '/painel/categoria_galeria.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d.ok) { bcToast(d.msg, 'danger'); return; }
                bcToast(id ? 'Categoria atualizada.' : 'Categoria criada.', 'success');
                fecharFormCat();
                // Atualiza a linha ou adiciona nova sem reload
                if (id) {
                    var row = document.getElementById('catrow-' + id);
                    if (row) row.querySelector('.cat-row-nome').textContent = nome;
                } else {
                    // Reload para refletir novos filtros e select
                    location.reload();
                }
            })
            .catch(function() { bcToast('Falha de conexão.', 'danger'); });
    };

    window.deletarCat = function(id, nome) {
        bcConfirm('Excluir a categoria "' + nome + '"? Ela não pode ter imagens vinculadas.', function() {
            var fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('acao', 'deletar');
            fd.append('id', id);
            fetch(BASE + '/painel/categoria_galeria.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (!d.ok) { bcToast(d.msg, 'danger'); return; }
                    var row = document.getElementById('catrow-' + id);
                    if (row) row.remove();
                    bcToast('Categoria excluída.', 'success');
                });
        }, 'Excluir');
    };

    // Enter no campo nome do formulário
    document.getElementById('catFormNome').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); salvarCat(); }
    });

    // ── Upload ───────────────────────────────────────────────────
    var dz = document.getElementById('dropZone');
    dz.addEventListener('dragover',  function(e){ e.preventDefault(); dz.classList.add('dragover'); });
    dz.addEventListener('dragleave', function()  { dz.classList.remove('dragover'); });
    dz.addEventListener('drop',      function(e){
        e.preventDefault(); dz.classList.remove('dragover');
        var f = e.dataTransfer.files[0];
        if (f) abrirCrop(f);
    });
    document.getElementById('inputImg').addEventListener('change', function(){
        if (this.files[0]) abrirCrop(this.files[0]);
    });

    function abrirCrop(file) {
        if (file.size > 20 * 1024 * 1024) { bcToast('Arquivo muito grande (máx. 20 MB).', 'danger'); return; }
        arquivoOrig = file;
        var reader  = new FileReader();
        reader.onload = function(ev) {
            document.getElementById('stepSelect').style.display = 'none';
            document.getElementById('stepCrop').style.display   = 'block';
            document.getElementById('btnOriginal').style.display = 'inline-flex';
            document.getElementById('btnCrop').style.display    = 'inline-flex';
            var img = document.getElementById('imgCropper');
            img.src = ev.target.result;
            if (cropperIns) { cropperIns.destroy(); cropperIns = null; }
            cropperIns = new Cropper(img, {
                viewMode: 1, autoCropArea: 0.88, responsive: true,
                background: false, movable: true, zoomable: true, rotatable: true, scalable: true,
            });
            window.cropperIns = cropperIns;
        };
        reader.readAsDataURL(file);
    }

    window.setAspect = function(r) { if (cropperIns) cropperIns.setAspectRatio(r); };

    window.salvarCrop = function() {
        if (!cropperIns || enviando) return;
        var canvas = cropperIns.getCroppedCanvas({
            maxWidth: 9999, maxHeight: 9999,
            fillColor: '#fff', imageSmoothingEnabled: true, imageSmoothingQuality: 'high',
        });
        canvas.toBlob(function(blob) { enviar(blob, 'upload.jpg'); }, 'image/jpeg', 0.95);
    };

    window.salvarOriginal = function() {
        if (!arquivoOrig || enviando) return;
        enviar(arquivoOrig, arquivoOrig.name);
    };

    function enviar(blob, nome) {
        enviando = true;
        document.getElementById('uploadProg').style.display = 'block';
        document.getElementById('btnCrop').disabled     = true;
        document.getElementById('btnOriginal').disabled = true;

        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('titulo',    document.getElementById('inputTitulo').value.trim());
        fd.append('categoria', document.getElementById('selectCat').value);
        fd.append('imagem',    blob, nome);

        fetch(BASE + '/painel/upload_imagem.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                enviando = false;
                if (data.ok) { fecharModal(); location.reload(); }
                else {
                    bcToast(data.msg || 'Erro ao enviar.', 'danger');
                    document.getElementById('uploadProg').style.display = 'none';
                    document.getElementById('btnCrop').disabled     = false;
                    document.getElementById('btnOriginal').disabled = false;
                }
            })
            .catch(function() {
                enviando = false;
                bcToast('Falha de conexão.', 'danger');
                document.getElementById('uploadProg').style.display = 'none';
                document.getElementById('btnCrop').disabled     = false;
                document.getElementById('btnOriginal').disabled = false;
            });
    }

    window.fecharModal = function() {
        if (enviando) return;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalUpload')).hide();
        resetModal();
    };

    function resetModal() {
        if (cropperIns) { cropperIns.destroy(); cropperIns = null; window.cropperIns = null; }
        arquivoOrig = null; enviando = false;
        document.getElementById('stepSelect').style.display  = 'block';
        document.getElementById('stepCrop').style.display    = 'none';
        document.getElementById('btnCrop').style.display     = 'none';
        document.getElementById('btnOriginal').style.display = 'none';
        document.getElementById('uploadProg').style.display  = 'none';
        document.getElementById('btnCrop').disabled     = false;
        document.getElementById('btnOriginal').disabled = false;
        document.getElementById('inputImg').value       = '';
        document.getElementById('inputTitulo').value    = '';
        document.getElementById('imgCropper').src       = '';
    }
    document.getElementById('modalUpload').addEventListener('hidden.bs.modal', resetModal);

    // ── Copiar URL ────────────────────────────────────────────────
    window.copiarUrl = function(url) {
        navigator.clipboard.writeText(url)
            .then(function() { bcToast('URL copiada!', 'success'); })
            .catch(function() { bcToast('Não foi possível copiar.', 'warning'); });
    };

    // ── Editar imagem ─────────────────────────────────────────────
    window.abrirEditar = function(id, titulo, cat) {
        document.getElementById('editImgId').value      = id;
        document.getElementById('editImgTitulo').value  = titulo;
        document.getElementById('editImgCat').value     = cat;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEditarImg')).show();
    };

    document.getElementById('btnSalvarEditar').addEventListener('click', function() {
        var id    = document.getElementById('editImgId').value;
        var titulo = document.getElementById('editImgTitulo').value.trim();
        var cat   = document.getElementById('editImgCat').value;
        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('id', id);
        fd.append('titulo', titulo);
        fd.append('categoria', cat);
        fetch(BASE + '/painel/editar_imagem.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.ok) { bcToast(data.msg, 'danger'); return; }
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEditarImg')).hide();
                bcToast('Imagem atualizada.', 'success');
                // Atualiza card sem reload
                var card = document.getElementById('gcard-' + id);
                if (card) {
                    var tituloEl = card.querySelector('.gal-titulo');
                    if (titulo) {
                        if (tituloEl) { tituloEl.textContent = titulo; }
                        else {
                            var info = card.querySelector('.px-2.pt-2');
                            var d = document.createElement('div');
                            d.className = 'small fw-semibold text-truncate mb-1 gal-titulo';
                            d.title = titulo; d.textContent = titulo;
                            info.insertBefore(d, info.firstChild);
                        }
                    } else if (tituloEl) {
                        tituloEl.remove();
                    }
                    var badge = card.querySelector('.gal-cat-badge');
                    if (badge) badge.textContent = data.catNome;
                }
            })
            .catch(function() { bcToast('Falha de conexão.', 'danger'); });
    });

    // ── Deletar imagem ────────────────────────────────────────────
    window.confirmarDelete = function(id, nome) {
        bcConfirm('Deletar "' + nome + '"? Essa ação não pode ser desfeita.', function() {
            var fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('id', id);
            fetch(BASE + '/painel/deletar_imagem.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.ok) {
                        var card = document.getElementById('gcard-' + id);
                        if (card) card.remove();
                        bcToast('Imagem deletada.', 'success');
                        if (!document.querySelectorAll('#galeriaGrid .col-6').length) location.reload();
                    } else { bcToast(data.msg, 'danger'); }
                });
        }, 'Deletar');
    };
}());
</script>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
