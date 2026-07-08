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
        $dest  = __DIR__ . '/../uploads/' . $fname;
        if (move_uploaded_file($_FILES['foto']['tmp_name'], $dest)) {
            $fotoUrl = BASE . '/uploads/' . $fname;
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
} catch (PDOException $e) {
    error_log('[Servicos] ' . $e->getMessage());
    $servicos = $subServicos = [];
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

<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="fw-bold mb-0">Serviços</h4>
    <button class="btn btn-accent btn-sm" data-bs-toggle="modal" data-bs-target="#modalServico">
        <i class="bi bi-plus-lg me-1"></i> Novo serviço
    </button>
</div>

<?php if (empty($servicos)): ?>
    <div class="text-center py-5 text-secondary card">
        <img src="<?= BASE ?>/geral/img/mascara.png" alt="" class="d-block mb-2 mx-auto opacity-25" style="width:3rem;height:3rem;object-fit:contain;">
        <p>Nenhum serviço cadastrado.</p>
        <div><button class="btn btn-accent btn-sm" data-bs-toggle="modal" data-bs-target="#modalServico">
                Criar primeiro serviço
            </button></div>
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

                        <!-- Sub-serviços (manutenções) -->
                        <?php if (!empty($subPorServico[$sv['IDServico']])): ?>
                            <div class="mb-2">
                                <div class="small text-secondary fw-medium mb-1">Manutenções:</div>
                                <?php foreach ($subPorServico[$sv['IDServico']] as $ss): ?>
                                    <div class="d-flex justify-content-between align-items-center py-1
                                border-bottom" style="border-color:var(--card-border-color)!important">
                                        <span class="small"><?= h($ss['Nome']) ?></span>
                                        <span class="small text-accent fw-medium"><?= formatarMoeda((float)$ss['Preco']) ?></span>
                                    </div>
                                <?php endforeach ?>
                            </div>
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

<!-- Modal: Serviço -->
<div class="modal fade" id="modalServico" tabindex="-1">
    <div class="modal-dialog">
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
                        <input type="text" name="nome" id="svNome" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea name="desc" id="svDesc" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">Preço (R$) *</label>
                            <input type="number" name="preco" id="svPreco" class="form-control"
                                step="0.01" min="0" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Duração (min) *</label>
                            <input type="number" name="dur" id="svDur" class="form-control"
                                min="15" step="15" required>
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
                    <div class="mb-3">
                        <label class="form-label">Foto do serviço</label>
                        <input type="file" name="foto" class="form-control" accept="image/*">
                        <div id="svFotoPreview"></div>
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
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold">
                        Manutenção — <span id="subServicoNome"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nome da manutenção *</label>
                        <input type="text" name="sub_nome" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea name="sub_desc" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">Preço (R$)</label>
                            <input type="number" name="sub_preco" class="form-control" step="0.01" min="0">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Duração (min)</label>
                            <input type="number" name="sub_dur" class="form-control" value="60" min="15" step="15">
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

<script>
    function editarServico(sv) {
        document.getElementById('tituloModalSv').textContent = 'Editar serviço';
        document.getElementById('svId').value = sv.IDServico;
        document.getElementById('svNome').value = sv.Nome;
        document.getElementById('svDesc').value = sv.Descricao || '';
        document.getElementById('svPreco').value = sv.Preco;
        document.getElementById('svDur').value = sv.DuracaoMinutos;
        document.getElementById('svOrdem').value = sv.Ordem;
        document.getElementById('svAtivo').checked = sv.Ativo == 1;
        document.getElementById('svFotoAtual').value = sv.FotoUrl || '';
        if (sv.FotoUrl) {
            document.getElementById('svFotoPreview').innerHTML =
                `<img src="${sv.FotoUrl}" class="mt-2 rounded" style="max-height:100px;">`;
        }
    }

    document.getElementById('modalServico')?.addEventListener('hidden.bs.modal', function() {
        document.getElementById('tituloModalSv').textContent = 'Novo serviço';
        this.querySelector('form').reset();
        document.getElementById('svId').value = '';
        document.getElementById('svFotoPreview').innerHTML = '';
    });
</script>

<?php require_once __DIR__ . '/../geral/footer.php' ?>