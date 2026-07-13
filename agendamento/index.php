<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('cliente');

// Redireciona para ficha de anamnese se o cliente ainda não preencheu
try {
    $stmtFicha = $pdo->prepare('SELECT IDFicha FROM FichaAnamnese WHERE FKCliente = :id LIMIT 1');
    $stmtFicha->execute([':id' => $_SESSION['usuario_id']]);
    if (!$stmtFicha->fetchColumn()) {
        header('Location: ' . BASE . '/usuario/ficha_anamnese.php?next=agendamento');
        exit;
    }
} catch (PDOException) {
    // Migration pendente — não bloqueia o agendamento
}

try {
    $servicos = $pdo->query(
        'SELECT s.*, GROUP_CONCAT(ss.IDSubServico,"||",ss.Nome,"||",ss.Preco,"||",ss.DuracaoMinutos
                 ORDER BY ss.Nome SEPARATOR ";;") AS SubServicos
         FROM Servicos s
         LEFT JOIN SubServicos ss ON ss.FKServico = s.IDServico AND ss.Ativo = 1
         WHERE s.Ativo = 1
         GROUP BY s.IDServico
         ORDER BY s.Ordem ASC'
    )->fetchAll();
} catch (PDOException $e) {
    error_log('[AgendServicos] ' . $e->getMessage());
    $servicos = [];
}

$paginaTitulo = 'Agendar — Escolha o serviço';
$areaAtual    = 'cliente';
require_once __DIR__ . '/../geral/header.php';
?>

<!-- Progresso -->
<div class="d-flex align-items-center gap-2 mb-5">
    <span class="badge rounded-pill px-3 py-2" style="background:var(--accent);font-size:.9rem;">
        1. Serviço
    </span>
    <div class="flex-grow-1 border-top" style="border-color:var(--card-border-color)!important;"></div>
    <span class="badge rounded-pill px-3 py-2 bg-light text-secondary" style="font-size:.9rem;">
        2. Horário
    </span>
    <div class="flex-grow-1 border-top" style="border-color:var(--card-border-color)!important;"></div>
    <span class="badge rounded-pill px-3 py-2 bg-light text-secondary" style="font-size:.9rem;">
        3. Confirmar
    </span>
</div>

<h5 class="fw-bold mb-1">Qual serviço você deseja?</h5>
<p class="text-secondary mb-4">Escolha o procedimento e selecione o horário.</p>

<?php if (empty($servicos)): ?>
<div class="text-center py-5 text-secondary">
    <img src="<?= BASE ?>/geral/img/mascara.png" alt="" class="d-block mb-2 mx-auto opacity-25" style="width:3rem;height:3rem;object-fit:contain;">
    <p>Nenhum serviço disponível no momento.</p>
</div>
<?php else: ?>
<div class="row g-3" id="listaServicos">
    <?php foreach ($servicos as $sv): ?>
    <?php
    $subs = [];
    if ($sv['SubServicos']) {
        foreach (explode(';;', $sv['SubServicos']) as $sub) {
            [$subId, $subNome, $subPreco, $subDur] = explode('||', $sub);
            $subs[] = compact('subId','subNome','subPreco','subDur');
        }
    }
    ?>
    <div class="col-sm-6 col-lg-4">
        <div class="card h-100 servico-card" style="cursor:pointer;transition:transform .15s,box-shadow .15s;"
             onclick="selecionarServico(this)"
             data-id="<?= h($sv['IDServico']) ?>"
             data-nome="<?= h($sv['Nome']) ?>"
             data-preco="<?= h($sv['Preco']) ?>"
             data-duracao="<?= h($sv['DuracaoMinutos']) ?>">
            <?php if ($sv['FotoUrl']): ?>
            <img src="<?= h($sv['FotoUrl']) ?>" class="card-img-top"
                 style="height:150px;object-fit:cover;border-radius:14px 14px 0 0;">
            <?php endif ?>
            <div class="card-body p-3">
                <h6 class="fw-bold mb-1"><?= h($sv['Nome']) ?></h6>
                <p class="small text-secondary mb-2"><?= h($sv['Descricao'] ?? '') ?></p>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-accent"><?= formatarMoeda((float)$sv['Preco']) ?></span>
                    <span class="small text-secondary">
                        <i class="bi bi-clock me-1"></i><?= $sv['DuracaoMinutos'] ?>min
                    </span>
                </div>

                <!-- Sub-serviços -->
                <?php if (!empty($subs)): ?>
                <hr class="my-2" style="border-color:var(--card-border-color);">
                <div class="small text-secondary fw-medium mb-1">Ou escolha uma manutenção:</div>
                <?php foreach ($subs as $ss): ?>
                <button type="button" class="btn btn-outline-accent btn-sm w-100 mb-1 sub-btn"
                        onclick="event.stopPropagation(); selecionarSubServico(
                            '<?= h($ss['subId']) ?>',
                            '<?= h($ss['subNome']) ?>',
                            '<?= h($ss['subPreco']) ?>',
                            '<?= h($ss['subDur']) ?>',
                            '<?= h($sv['IDServico']) ?>'
                        )">
                    <?= h($ss['subNome']) ?> — <?= formatarMoeda((float)$ss['subPreco']) ?>
                </button>
                <?php endforeach ?>
                <?php endif ?>
            </div>
            <div class="card-footer bg-transparent text-center py-2">
                <span class="small fw-medium text-accent selecionado-txt" style="display:none;">
                    <i class="bi bi-check-circle-fill me-1"></i>Selecionado
                </span>
                <span class="small text-secondary nao-sel-txt">Clique para selecionar</span>
            </div>
        </div>
    </div>
    <?php endforeach ?>
</div>

<!-- Painel de confirmação de seleção -->
<div id="painelSelecionado" class="d-none position-fixed bottom-0 start-0 end-0 p-3"
     style="background:var(--bg-card);border-top:1px solid var(--card-border-color);z-index:1000;box-shadow:0 -4px 20px rgba(0,0,0,.08);">
    <div class="container-lg d-flex align-items-center gap-3">
        <div class="flex-grow-1">
            <div class="fw-semibold" id="svSelecionadoNome">—</div>
            <div class="small text-secondary">
                <span id="svSelecionadoPreco"></span>
                &nbsp;·&nbsp; <i class="bi bi-clock me-1"></i><span id="svSelecionadoDur"></span>min
            </div>
        </div>
        <a href="<?= BASE ?>/agendamento/horarios.php" id="btnProximo"
           class="btn btn-accent btn-lg">
            Escolher horário <i class="bi bi-arrow-right ms-1"></i>
        </a>
    </div>
</div>

<form id="formSelecionado" method="GET" action="<?= BASE ?>/agendamento/horarios.php" style="display:none;">
    <input type="hidden" name="servico_id"  id="inp_servico_id">
    <input type="hidden" name="sub_id"      id="inp_sub_id">
    <input type="hidden" name="nome"        id="inp_nome">
    <input type="hidden" name="preco"       id="inp_preco">
    <input type="hidden" name="duracao"     id="inp_duracao">
</form>
<?php endif ?>

<script>
let cardAtivo = null;

function selecionarServico(card) {
    if (cardAtivo) {
        cardAtivo.style.transform = '';
        cardAtivo.style.boxShadow = '';
        cardAtivo.querySelector('.selecionado-txt').style.display = 'none';
        cardAtivo.querySelector('.nao-sel-txt').style.display = '';
    }
    cardAtivo = card;
    card.style.transform = 'translateY(-3px)';
    card.style.boxShadow = '0 8px 24px rgba(176,125,98,.25)';
    card.querySelector('.selecionado-txt').style.display = '';
    card.querySelector('.nao-sel-txt').style.display = 'none';

    const nome = card.dataset.nome;
    const preco = parseFloat(card.dataset.preco).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
    const dur = card.dataset.duracao;

    document.getElementById('svSelecionadoNome').textContent = nome;
    document.getElementById('svSelecionadoPreco').textContent = preco;
    document.getElementById('svSelecionadoDur').textContent = dur;
    document.getElementById('painelSelecionado').classList.remove('d-none');

    // Preparar form
    document.getElementById('inp_servico_id').value = card.dataset.id;
    document.getElementById('inp_sub_id').value = '';
    document.getElementById('inp_nome').value = nome;
    document.getElementById('inp_preco').value = card.dataset.preco;
    document.getElementById('inp_duracao').value = dur;

    document.getElementById('btnProximo').onclick = function(e) {
        e.preventDefault();
        document.getElementById('formSelecionado').submit();
    };
}

function selecionarSubServico(subId, subNome, subPreco, subDur, svId) {
    document.getElementById('inp_servico_id').value = svId;
    document.getElementById('inp_sub_id').value = subId;
    document.getElementById('inp_nome').value = subNome;
    document.getElementById('inp_preco').value = subPreco;
    document.getElementById('inp_duracao').value = subDur;

    const preco = parseFloat(subPreco).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
    document.getElementById('svSelecionadoNome').textContent = subNome;
    document.getElementById('svSelecionadoPreco').textContent = preco;
    document.getElementById('svSelecionadoDur').textContent = subDur;
    document.getElementById('painelSelecionado').classList.remove('d-none');

    document.getElementById('btnProximo').onclick = function(e) {
        e.preventDefault();
        document.getElementById('formSelecionado').submit();
    };
}
</script>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
