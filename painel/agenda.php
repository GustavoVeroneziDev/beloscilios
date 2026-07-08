<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

// Semana em exibição
$semanaOffset = (int)($_GET['semana'] ?? 0);
$inicioPeriodo = strtotime("monday this week +{$semanaOffset} week");
$fimPeriodo    = strtotime("sunday this week +{$semanaOffset} week");
$iniSQL = date('Y-m-d', $inicioPeriodo);
$fimSQL = date('Y-m-d', $fimPeriodo);

// Ação de cancelar/confirmar via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
        redirecionarComMensagem(BASE . '/painel/agenda.php', 'Token inválido.', 'danger');
    }
    $acao = $_POST['acao'] ?? '';
    $id   = $_POST['id']   ?? '';

    $statusMap = ['confirmar' => 'confirmado', 'cancelar' => 'cancelado', 'concluir' => 'concluido'];
    if (isset($statusMap[$acao]) && $id) {
        try {
            $upd = $pdo->prepare(
                'UPDATE Agendamentos SET StatusAgendamento = :status WHERE IDAgendamento = :id'
            );
            $upd->execute([':status' => $statusMap[$acao], ':id' => $id]);
            redirecionarComMensagem(BASE . '/painel/agenda.php', 'Status atualizado.', 'success');
        } catch (PDOException $e) {
            error_log('[Agenda] ' . $e->getMessage());
            redirecionarComMensagem(BASE . '/painel/agenda.php', 'Erro ao atualizar.', 'danger');
        }
    }
}

// Buscar agendamentos da semana
try {
    $stmt = $pdo->prepare(
        'SELECT a.*, u.Nome AS NomeCliente, u.Telefone,
                s.Nome AS NomeServico, s.DuracaoMinutos,
                ss.Nome AS NomeSubServico
         FROM Agendamentos a
         JOIN Usuarios u ON u.IDUsuario = a.FKCliente
         JOIN Servicos s ON s.IDServico = a.FKServico
         LEFT JOIN SubServicos ss ON ss.IDSubServico = a.FKSubServico
         WHERE DATE(a.DataHoraAgendamento) BETWEEN :ini AND :fim
           AND a.StatusAgendamento != \'cancelado\'
         ORDER BY a.DataHoraAgendamento ASC'
    );
    $stmt->execute([':ini' => $iniSQL, ':fim' => $fimSQL]);
    $agendamentos = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('[Agenda] ' . $e->getMessage());
    $agendamentos = [];
}

// Agrupar por data
$porDia = [];
foreach ($agendamentos as $ag) {
    $dia = date('Y-m-d', strtotime($ag['DataHoraAgendamento']));
    $porDia[$dia][] = $ag;
}

$servicos = $pdo->query('SELECT IDServico, Nome, DuracaoMinutos, Preco FROM Servicos WHERE Ativo = 1 ORDER BY Ordem')->fetchAll();

$paginaTitulo = 'Agenda';
$areaAtual    = 'painel';
require_once __DIR__ . '/../geral/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
    <h4 class="fw-bold mb-0">Agenda</h4>
    <div class="d-flex gap-2">
        <a href="?semana=<?= $semanaOffset - 1 ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-chevron-left"></i>
        </a>
        <span class="btn btn-outline-secondary btn-sm disabled">
            <?= date('d/m', $inicioPeriodo) ?> – <?= date('d/m', $fimPeriodo) ?>
        </span>
        <a href="?semana=<?= $semanaOffset + 1 ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-chevron-right"></i>
        </a>
        <?php if ($semanaOffset !== 0): ?>
        <a href="?semana=0" class="btn btn-outline-accent btn-sm">Hoje</a>
        <?php endif ?>
        <button class="btn btn-accent btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovoAg">
            <i class="bi bi-plus-lg me-1"></i> Novo
        </button>
    </div>
</div>

<!-- Grade semanal -->
<?php
$diasSemana = ['Segunda','Terça','Quarta','Quinta','Sexta','Sábado','Domingo'];
for ($d = 0; $d < 7; $d++):
    $ts  = strtotime("+{$d} days", $inicioPeriodo);
    $key = date('Y-m-d', $ts);
    $ags = $porDia[$key] ?? [];
    $eHoje = $key === date('Y-m-d');
?>
<div class="card mb-3 <?= $eHoje ? 'border-accent' : '' ?>">
    <div class="card-header d-flex align-items-center gap-2 px-4 py-2"
         style="<?= $eHoje ? 'background:var(--accent-light)' : '' ?>">
        <span class="fw-semibold <?= $eHoje ? 'text-accent' : '' ?>">
            <?= $diasSemana[$d] ?>
        </span>
        <span class="text-secondary small"><?= date('d/m', $ts) ?></span>
        <?php if ($eHoje): ?><span class="badge ms-1" style="background:var(--accent);">Hoje</span><?php endif ?>
        <span class="ms-auto badge bg-secondary"><?= count($ags) ?> ag.</span>
    </div>
    <?php if (empty($ags)): ?>
    <div class="text-center py-3 text-secondary small">Sem agendamentos</div>
    <?php else: ?>
    <ul class="list-group list-group-flush">
        <?php foreach ($ags as $ag): ?>
        <li class="list-group-item px-4 py-2">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <span class="fw-bold text-accent" style="min-width:40px;">
                    <?= date('H:i', strtotime($ag['DataHoraAgendamento'])) ?>
                </span>
                <div class="flex-grow-1">
                    <span class="fw-medium"><?= h($ag['NomeCliente']) ?></span>
                    <span class="text-secondary small ms-2">
                        <?= h($ag['NomeSubServico'] ?? $ag['NomeServico']) ?>
                        (<?= $ag['DuracaoMinutos'] ?>min)
                    </span>
                </div>
                <?= labelStatus($ag['StatusAgendamento']) ?>
                <?= labelStatusPag($ag['StatusPagamento']) ?>
                <!-- Ações -->
                <div class="d-flex gap-1">
                    <?php if ($ag['Telefone']): ?>
                    <a href="https://wa.me/<?= h($ag['Telefone']) ?>" target="_blank"
                       class="btn btn-sm btn-outline-success" title="WhatsApp">
                        <i class="bi bi-whatsapp"></i>
                    </a>
                    <?php endif ?>
                    <?php if ($ag['StatusAgendamento'] === 'pendente'): ?>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                        <input type="hidden" name="acao" value="confirmar">
                        <input type="hidden" name="id" value="<?= h($ag['IDAgendamento']) ?>">
                        <button class="btn btn-sm btn-outline-success" title="Confirmar">
                            <i class="bi bi-check-lg"></i>
                        </button>
                    </form>
                    <?php endif ?>
                    <?php if (in_array($ag['StatusAgendamento'], ['pendente','confirmado'])): ?>
                    <form method="POST" class="d-inline"
                          data-confirm="Confirma o cancelamento deste agendamento?"
                          data-confirm-label="Cancelar agendamento">
                        <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                        <input type="hidden" name="acao" value="cancelar">
                        <input type="hidden" name="id" value="<?= h($ag['IDAgendamento']) ?>">
                        <button class="btn btn-sm btn-outline-danger" title="Cancelar">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </form>
                    <?php endif ?>
                    <?php if ($ag['StatusAgendamento'] === 'confirmado'): ?>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                        <input type="hidden" name="acao" value="concluir">
                        <input type="hidden" name="id" value="<?= h($ag['IDAgendamento']) ?>">
                        <button class="btn btn-sm btn-outline-secondary" title="Marcar concluído">
                            <i class="bi bi-check2-all"></i>
                        </button>
                    </form>
                    <?php endif ?>
                </div>
            </div>
        </li>
        <?php endforeach ?>
    </ul>
    <?php endif ?>
</div>
<?php endfor ?>

<!-- Modal: Novo agendamento (manual) -->
<div class="modal fade" id="modalNovoAg" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="<?= BASE ?>/painel/salvar_agendamento.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold">Novo agendamento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Cliente (e-mail ou nome)</label>
                        <input type="text" name="busca_cliente" id="buscaCliente"
                               class="form-control" placeholder="Digite para buscar..." autocomplete="off">
                        <input type="hidden" name="fk_cliente" id="fkCliente">
                        <div id="resultadosBusca" class="list-group mt-1"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Serviço</label>
                        <select name="fk_servico" class="form-select" id="selectServico">
                            <option value="">Selecione</option>
                            <?php foreach ($servicos as $sv): ?>
                            <option value="<?= h($sv['IDServico']) ?>"
                                    data-duracao="<?= $sv['DuracaoMinutos'] ?>"
                                    data-preco="<?= $sv['Preco'] ?>">
                                <?= h($sv['Nome']) ?> — <?= formatarMoeda((float)$sv['Preco']) ?>
                            </option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <div class="row g-2">
                        <div class="col-7">
                            <label class="form-label">Data e hora</label>
                            <input type="datetime-local" name="data_hora" class="form-control" required>
                        </div>
                        <div class="col-5">
                            <label class="form-label">Valor (R$)</label>
                            <input type="number" name="valor" id="valorAg"
                                   class="form-control" step="0.01" min="0" placeholder="0,00">
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label">Observações</label>
                        <textarea name="obs" class="form-control" rows="2"
                                  placeholder="Opcional"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-accent">
                        <i class="bi bi-calendar-plus me-1"></i> Salvar agendamento
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Preenche valor automaticamente ao selecionar serviço
document.getElementById('selectServico')?.addEventListener('change', function () {
    const opt = this.options[this.selectedIndex];
    document.getElementById('valorAg').value = opt.dataset.preco || '';
});

// Busca de clientes (AJAX)
let buscaTimer;
document.getElementById('buscaCliente')?.addEventListener('input', function () {
    clearTimeout(buscaTimer);
    const q = this.value.trim();
    if (q.length < 2) {
        document.getElementById('resultadosBusca').innerHTML = '';
        return;
    }
    buscaTimer = setTimeout(() => {
        fetch('<?= BASE ?>/painel/api_busca_clientes.php?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                const el = document.getElementById('resultadosBusca');
                el.innerHTML = '';
                data.forEach(c => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'list-group-item list-group-item-action';
                    btn.addEventListener('click', function () { selecionarCliente(c.id, c.nome); });
                    btn.textContent = c.nome + ' ';
                    const small = document.createElement('small');
                    small.className = 'text-secondary';
                    small.textContent = c.email;
                    btn.appendChild(small);
                    el.appendChild(btn);
                });
            });
    }, 300);
});

function selecionarCliente(id, nome) {
    document.getElementById('fkCliente').value = id;
    document.getElementById('buscaCliente').value = nome;
    document.getElementById('resultadosBusca').innerHTML = '';
}

// Abrir modal se vier ?acao=novo na URL
if (new URLSearchParams(location.search).get('acao') === 'novo') {
    new bootstrap.Modal(document.getElementById('modalNovoAg')).show();
}
</script>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
