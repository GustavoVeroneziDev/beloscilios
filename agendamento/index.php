<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('cliente');

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
    $temSubs = !empty($subs);
    ?>
    <div class="col-sm-6 col-lg-4">
        <div class="card h-100 servico-card" style="cursor:pointer;transition:transform .15s,box-shadow .15s;"
             onclick="selecionarServico(this)"
             data-id="<?= h($sv['IDServico']) ?>"
             data-nome="<?= h($sv['Nome']) ?>"
             data-preco="<?= h($sv['Preco']) ?>"
             data-duracao="<?= h($sv['DuracaoMinutos']) ?>"
             data-subs="<?= h(json_encode(array_values($subs))) ?>">
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
                <?php if ($temSubs): ?>
                <div class="mt-2">
                    <span class="badge rounded-pill fw-normal"
                          style="background:rgba(90,24,154,.08);color:var(--roxo-600,#7b2fbe);font-size:.72rem;border:1px solid rgba(90,24,154,.15);">
                        <i class="bi bi-scissors me-1"></i>Manutenção disponível
                    </span>
                </div>
                <?php endif ?>
            </div>
            <div class="card-footer bg-transparent text-center py-2">
                <span class="small fw-medium text-accent selecionado-txt" style="display:none;">
                    <i class="bi bi-check-circle-fill me-1"></i>Selecionado
                </span>
                <span class="small text-secondary nao-sel-txt">
                    <?php if ($temSubs): ?>
                        Ver opções <i class="bi bi-chevron-up"></i>
                    <?php else: ?>
                        Toque para selecionar
                    <?php endif ?>
                </span>
            </div>
        </div>
    </div>
    <?php endforeach ?>
</div>

<!-- Bottom sheet para escolher tipo de serviço -->
<div class="offcanvas offcanvas-bottom" id="ocServico" tabindex="-1"
     style="border-radius:20px 20px 0 0;max-height:80vh;" aria-labelledby="ocTitulo">
    <div class="offcanvas-header pb-3" style="border-bottom:1px solid var(--card-border-color);">
        <div>
            <div class="text-secondary mb-0"
                 style="font-size:.7rem;letter-spacing:.06em;text-transform:uppercase;font-weight:500;">
                Escolha o tipo
            </div>
            <h6 class="offcanvas-title fw-bold mb-0 mt-1" id="ocTitulo"></h6>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
    </div>
    <div class="offcanvas-body px-3 pt-3 pb-5" id="ocBody" style="overflow-y:auto;"></div>
</div>

<!-- Painel fixo de confirmação -->
<div id="painelSelecionado" class="d-none position-fixed bottom-0 start-0 end-0 p-3"
     style="background:var(--bg-card);border-top:1px solid var(--card-border-color);z-index:1050;box-shadow:0 -4px 20px rgba(0,0,0,.08);">
    <div class="container-lg d-flex align-items-center gap-3">
        <div class="flex-grow-1 overflow-hidden">
            <div class="fw-semibold text-truncate" id="svSelecionadoNome">—</div>
            <div class="small text-secondary">
                <span id="svSelecionadoPreco"></span>
                &nbsp;·&nbsp;<i class="bi bi-clock me-1"></i><span id="svSelecionadoDur"></span>min
            </div>
        </div>
        <button type="button" id="btnProximo" class="btn btn-accent btn-lg flex-shrink-0">
            Escolher horário <i class="bi bi-arrow-right ms-1"></i>
        </button>
    </div>
</div>

<form id="formSelecionado" method="GET" action="<?= BASE ?>/agendamento/horarios.php" style="display:none;">
    <input type="hidden" name="servico_id" id="inp_servico_id">
    <input type="hidden" name="sub_id"     id="inp_sub_id">
    <input type="hidden" name="nome"       id="inp_nome">
    <input type="hidden" name="preco"      id="inp_preco">
    <input type="hidden" name="duracao"    id="inp_duracao">
</form>
<?php endif ?>

<script>
let cardPendente = null;
let cardAtivo    = null;

function selecionarServico(card) {
    cardPendente = card;
    const subs = JSON.parse(card.dataset.subs || '[]');

    if (subs.length > 0) {
        document.getElementById('ocTitulo').textContent = card.dataset.nome;

        const body = document.getElementById('ocBody');
        body.innerHTML = '';

        // Opção principal: aplicação completa
        body.appendChild(criarOpcaoBtn(
            'Aplicação completa',
            card.dataset.preco,
            card.dataset.duracao,
            true,
            () => confirmarSelecao(
                card.dataset.id, '', card.dataset.nome,
                card.dataset.preco, card.dataset.duracao
            )
        ));

        // Opções de manutenção
        const sep = document.createElement('p');
        sep.className = 'text-secondary small fw-medium mt-3 mb-2';
        sep.textContent = 'Manutenção:';
        body.appendChild(sep);

        subs.forEach(ss => {
            body.appendChild(criarOpcaoBtn(
                ss.subNome, ss.subPreco, ss.subDur, false,
                () => confirmarSelecao(
                    card.dataset.id, ss.subId, ss.subNome,
                    ss.subPreco, ss.subDur
                )
            ));
        });

        bootstrap.Offcanvas.getOrCreateInstance(
            document.getElementById('ocServico')
        ).show();
    } else {
        confirmarSelecao(
            card.dataset.id, '', card.dataset.nome,
            card.dataset.preco, card.dataset.duracao
        );
    }
}

function criarOpcaoBtn(titulo, preco, dur, destaque, callback) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = (destaque
        ? 'btn btn-accent'
        : 'btn btn-outline-accent'
    ) + ' w-100 text-start p-3 mb-2 rounded-3';
    btn.style.whiteSpace = 'normal';

    const t = document.createElement('div');
    t.className = 'fw-semibold';
    t.textContent = titulo;

    const s = document.createElement('div');
    s.className = 'small mt-1 opacity-75';
    s.textContent = formatBRL(preco) + ' · ' + dur + ' min';

    btn.appendChild(t);
    btn.appendChild(s);

    btn.addEventListener('click', () => {
        const ocEl = document.getElementById('ocServico');
        const oc = bootstrap.Offcanvas.getInstance(ocEl);
        if (oc) {
            ocEl.addEventListener('hidden.bs.offcanvas', () => {
                callback();
                mostrarPainel();
            }, { once: true });
            oc.hide();
        } else {
            callback();
            mostrarPainel();
        }
    });
    return btn;
}

function confirmarSelecao(svId, subId, nome, preco, dur) {
    // Atualiza destaque do card
    if (cardAtivo) {
        cardAtivo.style.transform = '';
        cardAtivo.style.boxShadow = '';
        cardAtivo.querySelector('.selecionado-txt').style.display = 'none';
        cardAtivo.querySelector('.nao-sel-txt').style.display    = '';
    }
    cardAtivo = cardPendente;
    if (cardAtivo) {
        cardAtivo.style.transform = 'translateY(-3px)';
        cardAtivo.style.boxShadow = '0 8px 24px rgba(176,125,98,.25)';
        cardAtivo.querySelector('.selecionado-txt').style.display = '';
        cardAtivo.querySelector('.nao-sel-txt').style.display    = 'none';
    }

    // Preenche form oculto
    document.getElementById('inp_servico_id').value = svId;
    document.getElementById('inp_sub_id').value     = subId;
    document.getElementById('inp_nome').value        = nome;
    document.getElementById('inp_preco').value       = preco;
    document.getElementById('inp_duracao').value     = dur;

    // Atualiza conteúdo do painel
    document.getElementById('svSelecionadoNome').textContent  = nome;
    document.getElementById('svSelecionadoPreco').textContent = formatBRL(preco);
    document.getElementById('svSelecionadoDur').textContent   = dur;

    document.getElementById('btnProximo').onclick = function() {
        document.getElementById('formSelecionado').submit();
    };
}

function mostrarPainel() {
    document.getElementById('painelSelecionado').classList.remove('d-none');
}

function formatBRL(v) {
    return parseFloat(v).toLocaleString('pt-BR', {style:'currency', currency:'BRL'});
}
</script>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
