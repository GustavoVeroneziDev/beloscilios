<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

// Salvar / editar serviço
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
        redirecionarComMensagem(BASE . '/painel/servicos.php', 'Token inválido.', 'danger');
    }
    $acao  = $_POST['acao']  ?? '';
    $id    = $_POST['id']    ?? '';
    $nome  = trim($_POST['nome']     ?? '');
    $desc  = trim($_POST['desc']     ?? '');
    $preco = trim($_POST['preco']    ?? '0');
    $dur   = (int)($_POST['dur']     ?? 60);
    $ordem = (int)($_POST['ordem']   ?? 0);
    $ativo = isset($_POST['ativo']) ? 1 : 0;

    // Upload de foto
    $fotoUrl = $_POST['foto_atual'] ?? null;
    if (!empty($_FILES['foto']['name'])) {
        $ext   = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
        $exts  = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $exts)) {
            redirecionarComMensagem(BASE . '/painel/servicos.php', 'Formato de imagem inválido.', 'warning');
        }
        $fname = gerarUuid() . '.' . $ext;
        $dir   = __DIR__ . '/../geral/img/servicos/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $dest  = $dir . $fname;
        if (move_uploaded_file($_FILES['foto']['tmp_name'], $dest)) {
            $fotoUrl = BASE . '/geral/img/servicos/' . $fname;
        }
    }

    try {
        if ($acao === 'salvar' && $nome) {
            if ($id) {
                $stmt = $pdo->prepare(
                    'UPDATE Servicos SET Nome=:n,Descricao=:d,Preco=:p,DuracaoMinutos=:dur,
                     FotoUrl=:f,Ordem=:o,Ativo=:a WHERE IDServico=:id'
                );
                $stmt->execute([
                    ':n' => $nome,
                    ':d' => $desc,
                    ':p' => $preco,
                    ':dur' => $dur,
                    ':f' => $fotoUrl,
                    ':o' => $ordem,
                    ':a' => $ativo,
                    ':id' => $id
                ]);
                $msg = 'Serviço atualizado.';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO Servicos (IDServico,Nome,Descricao,Preco,DuracaoMinutos,FotoUrl,Ordem,Ativo)
                     VALUES (:id,:n,:d,:p,:dur,:f,:o,:a)'
                );
                $stmt->execute([
                    ':id' => gerarUuid(),
                    ':n' => $nome,
                    ':d' => $desc,
                    ':p' => $preco,
                    ':dur' => $dur,
                    ':f' => $fotoUrl,
                    ':o' => $ordem,
                    ':a' => 1
                ]);
                $msg = 'Serviço criado com sucesso!';
            }
            redirecionarComMensagem(BASE . '/painel/servicos.php', $msg, 'success');
        }

        if ($acao === 'excluir' && $id) {
            $pdo->prepare('UPDATE Servicos SET Ativo=0 WHERE IDServico=:id')->execute([':id' => $id]);
            redirecionarComMensagem(BASE . '/painel/servicos.php', 'Serviço desativado.', 'success');
        }

        // Sub-serviço
        if ($acao === 'sub_salvar') {
            $fkServ  = $_POST['fk_servico'] ?? '';
            $subId   = $_POST['sub_id']     ?? '';
            $subNome = trim($_POST['sub_nome']  ?? '');
            $subDesc = trim($_POST['sub_desc']  ?? '');
            $subPrec = (float)($_POST['sub_preco'] ?? 0);
            $subDur  = (int)($_POST['sub_dur']   ?? 60);

            if ($subNome && $fkServ) {
                if ($subId) {
                    $pdo->prepare(
                        'UPDATE SubServicos SET Nome=:n,Descricao=:d,Preco=:p,DuracaoMinutos=:dur WHERE IDSubServico=:id'
                    )->execute([':n' => $subNome, ':d' => $subDesc, ':p' => $subPrec, ':dur' => $subDur, ':id' => $subId]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO SubServicos (IDSubServico,FKServico,Nome,Descricao,Preco,DuracaoMinutos)
                         VALUES (:id,:fk,:n,:d,:p,:dur)'
                    )->execute([
                        ':id' => gerarUuid(),
                        ':fk' => $fkServ,
                        ':n' => $subNome,
                        ':d' => $subDesc,
                        ':p' => $subPrec,
                        ':dur' => $subDur
                    ]);
                }
                redirecionarComMensagem(BASE . '/painel/servicos.php', 'Manutenção salva.', 'success');
            }
        }
    } catch (PDOException $e) {
        error_log('[Servicos] ' . $e->getMessage());
        redirecionarComMensagem(BASE . '/painel/servicos.php', 'Erro ao salvar.', 'danger');
    }
}

try {
    $servicos = $pdo->query(
        'SELECT s.*,
                (SELECT COUNT(*) FROM SubServicos ss WHERE ss.FKServico=s.IDServico AND ss.Ativo=1) AS QtdSub
         FROM Servicos s ORDER BY s.Ordem ASC, s.Nome ASC'
    )->fetchAll();

    $subServicos = $pdo->query(
        'SELECT * FROM SubServicos WHERE Ativo=1 ORDER BY FKServico, Nome'
    )->fetchAll();

    $imagensGaleria = $pdo->query(
        'SELECT i.IDImagem, i.NomeArquivo, i.TituloExibicao, c.Nome AS CategoriaNome
         FROM Imagens i
         LEFT JOIN CategoriasGaleria c ON c.IDCategoria = i.Categoria
         ORDER BY i.MomentoRegistro DESC'
    )->fetchAll();
} catch (PDOException $e) {
    error_log('[Servicos] ' . $e->getMessage());
    $servicos = $subServicos = $imagensGaleria = [];
}

// Agrupar sub-serviços por serviço pai
$subPorServico = [];
foreach ($subServicos as $ss) {
    $subPorServico[$ss['FKServico']][] = $ss;
}

$paginaTitulo = 'Serviços';
$areaAtual    = 'painel';
require_once __DIR__ . '/../geral/header.php';
?>

<style>
.bc-tabs {
    display: flex;
    gap: .25rem;
    border-bottom: 2px solid var(--card-border-color);
    margin-bottom: 1.5rem;
}
.bc-tab {
    background: none;
    border: none;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    padding: .55rem 1.1rem;
    font-size: .875rem;
    font-weight: 600;
    color: var(--text-muted);
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: .4rem;
    transition: color .15s, border-color .15s;
    border-radius: 0;
}
.bc-tab:hover  { color: var(--text-main); }
.bc-tab.active { color: var(--accent); border-bottom-color: var(--accent); }
.bc-tab .bc-badge {
    background: var(--bg-hover);
    color: var(--text-muted);
    font-size: .65rem;
    font-weight: 700;
    padding: .1rem .45rem;
    border-radius: 20px;
    min-width: 1.4rem;
    text-align: center;
}
.bc-tab.active .bc-badge {
    background: rgba(90,24,154,.15);
    color: var(--accent);
}
</style>

<!-- Cabeçalho -->
<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="fw-bold mb-0">Serviços</h4>
    <div>
        <button id="btnAcaoServico" class="btn btn-accent btn-sm"
                data-bs-toggle="modal" data-bs-target="#modalServico">
            <i class="bi bi-plus-lg me-1"></i>Novo serviço
        </button>
        <button id="btnAcaoManutencao" class="btn btn-accent btn-sm" style="display:none"
                data-bs-toggle="modal" data-bs-target="#modalSubServico">
            <i class="bi bi-plus-lg me-1"></i>Nova manutenção
        </button>
    </div>
</div>

<!-- Nav tabs -->
<div class="bc-tabs">
    <button class="bc-tab active" data-tab="servicos">
        <i class="bi bi-grid-3x3-gap-fill"></i>
        Serviços
        <span class="bc-badge"><?= count($servicos) ?></span>
    </button>
    <button class="bc-tab" data-tab="manutencoes">
        <i class="bi bi-wrench-adjustable"></i>
        Manutenções
        <span class="bc-badge"><?= count($subServicos) ?></span>
    </button>
</div>

<!-- ── Tab: Serviços ──────────────────────────────────────────── -->
<div id="tab-servicos">
    <?php if (empty($servicos)): ?>
        <div class="text-center py-5 text-secondary card">
            <img src="<?= BASE ?>/geral/img/mascara.png" alt="" class="d-block mb-2 mx-auto opacity-25" style="width:3rem;height:3rem;object-fit:contain;">
            <p>Nenhum serviço cadastrado.</p>
            <div>
                <button class="btn btn-accent btn-sm" data-bs-toggle="modal" data-bs-target="#modalServico">
                    Criar primeiro serviço
                </button>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($servicos as $sv): ?>
                <div class="col-sm-6 col-lg-4">
                    <div class="card h-100 <?= !$sv['Ativo'] ? 'opacity-50' : '' ?>">
                        <?php if ($sv['FotoUrl']): ?>
                            <img src="<?= h($sv['FotoUrl']) ?>" class="card-img-top"
                                style="height:160px;object-fit:cover;border-radius:14px 14px 0 0;">
                        <?php endif ?>
                        <div class="card-body pb-2">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <h6 class="fw-bold mb-0"><?= h($sv['Nome']) ?></h6>
                                <?php if (!$sv['Ativo']): ?>
                                    <span class="badge bg-secondary">Inativo</span>
                                <?php endif ?>
                            </div>
                            <p class="small text-secondary mb-2"><?= h($sv['Descricao'] ?? '') ?></p>
                            <div class="d-flex gap-3 mb-2">
                                <span class="fw-bold text-accent"><?= formatarMoeda((float)$sv['Preco']) ?></span>
                                <span class="small text-secondary">
                                    <i class="bi bi-clock me-1"></i><?= $sv['DuracaoMinutos'] ?>min
                                </span>
                            </div>
                            <?php $qtdSub = count($subPorServico[$sv['IDServico']] ?? []); ?>
                            <?php if ($qtdSub): ?>
                                <span class="badge" style="background:rgba(90,24,154,.12);color:var(--accent);font-weight:600;">
                                    <?= $qtdSub ?> manutenç<?= $qtdSub == 1 ? 'ão' : 'ões' ?>
                                </span>
                            <?php endif ?>
                        </div>
                        <div class="card-footer bg-transparent d-flex gap-2">
                            <button class="btn btn-sm btn-outline-accent flex-grow-1"
                                data-bs-toggle="modal" data-bs-target="#modalServico"
                                onclick="editarServico(<?= htmlspecialchars(json_encode($sv), ENT_QUOTES) ?>)">
                                <i class="bi bi-pencil me-1"></i>Editar
                            </button>
                            <button class="btn btn-sm btn-outline-secondary"
                                data-bs-toggle="modal" data-bs-target="#modalSubServico"
                                onclick="document.getElementById('subFkServico').value='<?= h($sv['IDServico']) ?>';
                                         document.getElementById('subServicoNome').textContent='<?= h($sv['Nome']) ?>'">
                                <i class="bi bi-plus"></i> Manutenção
                            </button>
                            <?php if ($sv['Ativo']): ?>
                                <form method="POST"
                                    data-confirm="Desativar este serviço?"
                                    data-confirm-label="Desativar">
                                    <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                                    <input type="hidden" name="acao" value="excluir">
                                    <input type="hidden" name="id" value="<?= h($sv['IDServico']) ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            <?php endif ?>
                        </div>
                    </div>
                </div>
            <?php endforeach ?>
        </div>
    <?php endif ?>
</div>

<!-- ── Tab: Manutenções ──────────────────────────────────────── -->
<div id="tab-manutencoes" style="display:none">
    <?php if (empty($subServicos)): ?>
        <div class="text-center py-5 text-secondary card">
            <i class="bi bi-wrench-adjustable" style="font-size:2.5rem;opacity:.2"></i>
            <p class="mt-3 mb-0">Nenhuma manutenção cadastrada.</p>
        </div>
    <?php else: ?>
        <div class="d-flex flex-column gap-3">
            <?php foreach ($servicos as $sv):
                if (empty($subPorServico[$sv['IDServico']])) continue;
            ?>
            <div class="card">
                <div class="card-header px-4 py-3 d-flex align-items-center justify-content-between">
                    <span class="fw-semibold">
                        <?php if ($sv['FotoUrl']): ?>
                            <img src="<?= h($sv['FotoUrl']) ?>" alt=""
                                 style="width:28px;height:28px;object-fit:cover;border-radius:6px;margin-right:.5rem;vertical-align:middle;">
                        <?php endif ?>
                        <?= h($sv['Nome']) ?>
                    </span>
                    <button class="btn btn-sm btn-outline-accent"
                            data-bs-toggle="modal" data-bs-target="#modalSubServico"
                            onclick="document.getElementById('subFkServico').value='<?= h($sv['IDServico']) ?>';
                                     document.getElementById('subServicoNome').textContent='<?= h($sv['Nome']) ?>'">
                        <i class="bi bi-plus me-1"></i>Adicionar
                    </button>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover align-middle mb-0" style="font-size:.875rem;">
                        <thead style="background:var(--bg-hover);">
                            <tr>
                                <th class="px-4 py-2">Nome</th>
                                <th>Duração</th>
                                <th>Preço</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subPorServico[$sv['IDServico']] as $ss): ?>
                            <tr>
                                <td class="px-4"><?= h($ss['Nome']) ?></td>
                                <td class="text-secondary"><?= $ss['DuracaoMinutos'] ?>min</td>
                                <td class="fw-semibold text-accent"><?= formatarMoeda((float)$ss['Preco']) ?></td>
                                <td class="text-end pe-3">
                                    <button class="btn btn-sm btn-outline-secondary py-0 px-2"
                                            data-bs-toggle="modal" data-bs-target="#modalSubServico"
                                            onclick="editarSub(<?= htmlspecialchars(json_encode($ss), ENT_QUOTES) ?>, '<?= h($sv['Nome']) ?>')">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach ?>
        </div>
    <?php endif ?>
</div>

<!-- Modal: Serviço -->
<div class="modal fade" id="modalServico" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                <input type="hidden" name="acao" value="salvar">
                <input type="hidden" name="id" id="svId">
                <input type="hidden" name="foto_atual" id="svFotoAtual">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold" id="tituloModalSv">Novo serviço</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nome *</label>
                        <input type="text" name="nome" id="svNome" class="form-control" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea name="desc" id="svDesc" class="form-control" rows="2" maxlength="500"></textarea>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">Preço (R$) *</label>
                            <input type="number" name="preco" id="svPreco" class="form-control"
                                step="0.01" min="0" max="99999.99" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Duração (min) *</label>
                            <input type="number" name="dur" id="svDur" class="form-control"
                                min="15" max="480" step="15" required>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">Ordem de exibição</label>
                            <input type="number" name="ordem" id="svOrdem" class="form-control" min="0" value="0">
                        </div>
                        <div class="col-6 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="ativo" id="svAtivo" checked>
                                <label class="form-check-label" for="svAtivo">Ativo</label>
                            </div>
                        </div>
                    </div>

                    <!-- Foto -->
                    <div class="mb-0">
                        <label class="form-label">Foto do serviço</label>

                        <!-- Preview -->
                        <div id="svFotoPreview" class="mb-2"></div>

                        <!-- Upload de arquivo -->
                        <div class="d-flex gap-2 align-items-center mb-2">
                            <input type="file" name="foto" id="svFotoFile" class="form-control form-control-sm" accept="image/*">
                            <button type="button" class="btn btn-sm btn-outline-accent text-nowrap"
                                    onclick="togglePickerGaleria()">
                                <i class="bi bi-images me-1"></i>Da galeria
                            </button>
                        </div>

                        <!-- Picker inline da galeria -->
                        <div id="svGaleriaPicker" style="display:none">
                            <div style="border:1px solid var(--card-border-color);border-radius:10px;overflow:hidden;">
                                <!-- Barra de busca -->
                                <div class="p-2 border-bottom" style="border-color:var(--card-border-color)!important;background:var(--bg-hover);">
                                    <input type="text" id="svGaleriaFiltro" class="form-control form-control-sm"
                                           placeholder="Buscar por título ou categoria…"
                                           oninput="filtrarPickerGaleria(this.value)">
                                </div>
                                <!-- Grid de imagens -->
                                <div id="svGaleriaGrid"
                                     style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;padding:10px;max-height:280px;overflow-y:auto;">
                                    <?php foreach ($imagensGaleria as $gi):
                                        $label = $gi['TituloExibicao'] ?: $gi['CategoriaNome'] ?: $gi['NomeArquivo'];
                                    ?>
                                    <button type="button"
                                            class="picker-img-btn"
                                            data-url="<?= BASE ?>/geral/img/galeria/<?= h($gi['NomeArquivo']) ?>"
                                            data-busca="<?= h(strtolower(($gi['TituloExibicao'] ?? '') . ' ' . ($gi['CategoriaNome'] ?? ''))) ?>"
                                            onclick="selecionarFotoGaleria(this)"
                                            style="border:2px solid transparent;border-radius:8px;padding:0;overflow:hidden;cursor:pointer;background:none;aspect-ratio:1;position:relative;">
                                        <img src="<?= BASE ?>/geral/img/galeria/<?= h($gi['NomeArquivo']) ?>"
                                             alt="<?= h($label) ?>"
                                             loading="lazy"
                                             style="width:100%;height:100%;object-fit:cover;display:block;">
                                        <span style="position:absolute;top:0;left:0;right:0;
                                                     background:linear-gradient(to bottom,rgba(16,0,43,.72) 0%,transparent 100%);
                                                     color:#fff;font-size:.6rem;font-weight:600;
                                                     padding:4px 5px 10px;
                                                     line-height:1.2;text-align:left;
                                                     overflow:hidden;display:-webkit-box;
                                                     -webkit-line-clamp:2;-webkit-box-orient:vertical;
                                                     pointer-events:none;">
                                            <?= h($label) ?>
                                        </span>
                                    </button>
                                    <?php endforeach ?>
                                    <?php if (empty($imagensGaleria)): ?>
                                    <p class="text-secondary small col-span-3 text-center py-3 m-0" style="grid-column:1/-1">
                                        Nenhuma imagem na galeria.
                                    </p>
                                    <?php endif ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-accent">Salvar serviço</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Sub-serviço -->
<div class="modal fade" id="modalSubServico" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                <input type="hidden" name="acao" value="sub_salvar">
                <input type="hidden" name="fk_servico" id="subFkServico">
                <input type="hidden" name="sub_id" id="subId">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold" id="subModalTitulo">
                        Manutenção — <span id="subServicoNome"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nome da manutenção *</label>
                        <input type="text" name="sub_nome" id="subNome" class="form-control" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea name="sub_desc" id="subDesc" class="form-control" rows="2" maxlength="500"></textarea>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">Preço (R$)</label>
                            <input type="number" name="sub_preco" id="subPreco" class="form-control" step="0.01" min="0" max="99999.99">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Duração (min)</label>
                            <input type="number" name="sub_dur" id="subDur" class="form-control" value="60" min="15" max="480" step="15">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-accent">Salvar manutenção</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.picker-img-btn:hover,
.picker-img-btn.selecionada { border-color: var(--accent) !important; }
.picker-img-btn.selecionada { box-shadow: 0 0 0 2px var(--accent); }
</style>

<script>
    // ── Serviço ──────────────────────────────────────────────────────────────
    function setFotoPreview(url) {
        var prev = document.getElementById('svFotoPreview');
        if (url) {
            prev.innerHTML = '<div class="d-flex align-items-center gap-2">'
                + '<img src="' + url + '" class="rounded" style="height:72px;width:72px;object-fit:cover;border:2px solid var(--accent);">'
                + '<button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="limparFoto()" title="Remover foto">'
                + '<i class="bi bi-x-lg"></i></button></div>';
        } else {
            prev.innerHTML = '';
        }
    }

    function limparFoto() {
        document.getElementById('svFotoAtual').value = '';
        document.getElementById('svFotoFile').value  = '';
        document.getElementById('svFotoPreview').innerHTML = '';
        document.querySelectorAll('.picker-img-btn').forEach(function(b){ b.classList.remove('selecionada'); });
    }

    function editarServico(sv) {
        document.getElementById('tituloModalSv').textContent = 'Editar serviço';
        document.getElementById('svId').value        = sv.IDServico;
        document.getElementById('svNome').value      = sv.Nome;
        document.getElementById('svDesc').value      = sv.Descricao || '';
        document.getElementById('svPreco').value     = sv.Preco;
        document.getElementById('svDur').value       = sv.DuracaoMinutos;
        document.getElementById('svOrdem').value     = sv.Ordem;
        document.getElementById('svAtivo').checked   = sv.Ativo == 1;
        document.getElementById('svFotoAtual').value = sv.FotoUrl || '';
        setFotoPreview(sv.FotoUrl || '');
    }

    // Preview ao selecionar arquivo
    document.getElementById('svFotoFile')?.addEventListener('change', function () {
        if (this.files && this.files[0]) {
            document.getElementById('svFotoAtual').value = '';
            document.querySelectorAll('.picker-img-btn').forEach(function(b){ b.classList.remove('selecionada'); });
            var reader = new FileReader();
            reader.onload = function (e) { setFotoPreview(e.target.result); };
            reader.readAsDataURL(this.files[0]);
        }
    });

    document.getElementById('modalServico')?.addEventListener('hidden.bs.modal', function () {
        document.getElementById('tituloModalSv').textContent = 'Novo serviço';
        this.querySelector('form').reset();
        document.getElementById('svId').value           = '';
        document.getElementById('svFotoAtual').value    = '';
        document.getElementById('svFotoPreview').innerHTML = '';
        document.getElementById('svGaleriaPicker').style.display = 'none';
        document.getElementById('svGaleriaFiltro').value = '';
        document.querySelectorAll('.picker-img-btn').forEach(function(b){ b.classList.remove('selecionada'); });
        filtrarPickerGaleria('');
    });

    // ── Picker da galeria ─────────────────────────────────────────────────────
    function togglePickerGaleria() {
        var p = document.getElementById('svGaleriaPicker');
        p.style.display = p.style.display === 'none' ? 'block' : 'none';
        if (p.style.display === 'block') {
            document.getElementById('svGaleriaFiltro').focus();
        }
    }

    function selecionarFotoGaleria(btn) {
        var url = btn.dataset.url;
        document.getElementById('svFotoAtual').value = url;
        document.getElementById('svFotoFile').value  = '';
        setFotoPreview(url);
        document.querySelectorAll('.picker-img-btn').forEach(function(b){ b.classList.remove('selecionada'); });
        btn.classList.add('selecionada');
        document.getElementById('svGaleriaPicker').style.display = 'none';
    }

    function filtrarPickerGaleria(q) {
        q = q.toLowerCase().trim();
        document.querySelectorAll('.picker-img-btn').forEach(function(btn) {
            var busca = btn.dataset.busca || '';
            btn.style.display = (!q || busca.includes(q)) ? '' : 'none';
        });
    }

    // ── Manutenção ───────────────────────────────────────────────────────────
    function editarSub(ss, nomeServico) {
        document.getElementById('subModalTitulo').childNodes[0].textContent = 'Editar manutenção — ';
        document.getElementById('subServicoNome').textContent = nomeServico;
        document.getElementById('subId').value       = ss.IDSubServico;
        document.getElementById('subFkServico').value = ss.FKServico;
        document.getElementById('subNome').value     = ss.Nome;
        document.getElementById('subDesc').value     = ss.Descricao || '';
        document.getElementById('subPreco').value    = ss.Preco;
        document.getElementById('subDur').value      = ss.DuracaoMinutos;
    }

    document.getElementById('modalSubServico')?.addEventListener('hidden.bs.modal', function () {
        this.querySelector('form').reset();
        document.getElementById('subId').value = '';
        document.getElementById('subModalTitulo').childNodes[0].textContent = 'Manutenção — ';
        document.getElementById('subDur').value = '60';
    });

    // ── Tabs ─────────────────────────────────────────────────────────────────
    var panels = { servicos: document.getElementById('tab-servicos'), manutencoes: document.getElementById('tab-manutencoes') };
    var btns   = { servicos: document.getElementById('btnAcaoServico'), manutencoes: document.getElementById('btnAcaoManutencao') };

    document.querySelectorAll('.bc-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            var id = this.dataset.tab;
            document.querySelectorAll('.bc-tab').forEach(function(t){ t.classList.remove('active'); });
            this.classList.add('active');
            Object.keys(panels).forEach(function(k){ panels[k].style.display = k === id ? '' : 'none'; });
            Object.keys(btns).forEach(function(k){ btns[k].style.display = k === id ? '' : 'none'; });
        });
    });
</script>

<?php require_once __DIR__ . '/../geral/footer.php' ?>