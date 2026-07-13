<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

$dataSel    = trim($_GET['data'] ?? date('Y-m-d'));
$dataTs     = strtotime($dataSel) ?: time();
$dataSel    = date('Y-m-d', $dataTs);
$diaSemana  = (int) date('w', $dataTs);
$intervalo  = (int) getConfig($pdo, 'intervalo_minutos', '15');

$horario   = null;
$agendados = [];
$bloqueios = [];
$servicos  = [];
$subsPorSv = [];

try {
    $horStmt = $pdo->prepare(
        'SELECT HoraInicio, HoraFim, AlmocoInicio, AlmocoFim
         FROM HorariosAtendimento WHERE DiaSemana = :d AND Ativo = 1 LIMIT 1'
    );
    $horStmt->execute([':d' => $diaSemana]);
    $horario = $horStmt->fetch();

    $agStmt = $pdo->prepare(
        'SELECT a.DataHoraAgendamento, a.DataHoraFim,
                u.Nome AS NomeCliente, s.Nome AS NomeServico
         FROM Agendamentos a
         JOIN Usuarios u ON u.IDUsuario = a.FKCliente
         JOIN Servicos s ON s.IDServico  = a.FKServico
         WHERE DATE(a.DataHoraAgendamento) = :data
           AND a.StatusAgendamento NOT IN (\'cancelado\')'
    );
    $agStmt->execute([':data' => $dataSel]);
    $agendados = $agStmt->fetchAll();

    $bloqStmt = $pdo->prepare(
        'SELECT DataInicio, DataFim, Motivo FROM BloqueiosAgenda
         WHERE DATE(DataInicio) <= :data AND DATE(DataFim) >= :data'
    );
    $bloqStmt->execute([':data' => $dataSel]);
    $bloqueios = $bloqStmt->fetchAll();

    $servicos = $pdo->query(
        'SELECT IDServico, Nome, DuracaoMinutos, Preco FROM Servicos WHERE Ativo = 1 ORDER BY Ordem'
    )->fetchAll();

    foreach ($pdo->query(
        'SELECT IDSubServico, FKServico, Nome, DuracaoMinutos, Preco FROM SubServicos WHERE Ativo = 1 ORDER BY Nome'
    )->fetchAll() as $ss) {
        $subsPorSv[$ss['FKServico']][] = $ss;
    }
} catch (PDOException $e) {
    error_log('[NovoAg] ' . $e->getMessage());
}

// Monta grade de slots
$slots = [];
$fimJornadaTs = 0;
if ($horario) {
    $inicioTs     = strtotime("{$dataSel} {$horario['HoraInicio']}");
    $fimJornadaTs = strtotime("{$dataSel} {$horario['HoraFim']}");

    for ($ts = $inicioTs; $ts < $fimJornadaTs; $ts += $intervalo * 60) {
        $status = 'livre';
        $info   = '';

        if (!empty($horario['AlmocoInicio']) && !empty($horario['AlmocoFim'])) {
            $alIni = strtotime("{$dataSel} {$horario['AlmocoInicio']}");
            $alFim = strtotime("{$dataSel} {$horario['AlmocoFim']}");
            if ($ts >= $alIni && $ts < $alFim) { $status = 'almoco'; $info = 'Intervalo'; }
        }

        if ($status === 'livre') {
            foreach ($agendados as $ag) {
                $ini = strtotime($ag['DataHoraAgendamento']);
                $fim = strtotime($ag['DataHoraFim']);
                if ($ts >= $ini && $ts < $fim) {
                    $status = 'ocupado';
                    $info   = $ag['NomeCliente'] . ' — ' . $ag['NomeServico'];
                    break;
                }
            }
        }

        if ($status === 'livre') {
            foreach ($bloqueios as $b) {
                $ini = strtotime($b['DataInicio']);
                $fim = strtotime($b['DataFim']);
                if ($ts >= $ini && $ts < $fim) {
                    $status = 'bloqueado';
                    $info   = $b['Motivo'] ?: 'Bloqueado';
                    break;
                }
            }
        }

        $slots[] = ['hora' => date('H:i', $ts), 'ts' => $ts, 'status' => $status, 'info' => $info];
    }
}

// Dados para JS
$agJson  = json_encode(array_map(fn($ag) => [
    'ini'  => strtotime($ag['DataHoraAgendamento']),
    'fim'  => strtotime($ag['DataHoraFim']),
    'info' => $ag['NomeCliente'] . ' — ' . $ag['NomeServico'],
], $agendados));

$bloqJson = json_encode(array_map(fn($b) => [
    'ini'    => strtotime($b['DataInicio']),
    'fim'    => strtotime($b['DataFim']),
    'info'   => $b['Motivo'] ?: 'Bloqueado',
], $bloqueios));

$almocoJson = ($horario && !empty($horario['AlmocoInicio']) && !empty($horario['AlmocoFim']))
    ? json_encode([
        'ini' => strtotime("{$dataSel} {$horario['AlmocoInicio']}"),
        'fim' => strtotime("{$dataSel} {$horario['AlmocoFim']}"),
      ])
    : 'null';

$svsJson = json_encode(array_map(fn($s) => [
    'id'      => $s['IDServico'],
    'nome'    => $s['Nome'],
    'duracao' => (int)$s['DuracaoMinutos'],
    'preco'   => (float)$s['Preco'],
    'subs'    => array_map(fn($ss) => [
        'id'      => $ss['IDSubServico'],
        'nome'    => $ss['Nome'],
        'duracao' => (int)$ss['DuracaoMinutos'],
        'preco'   => (float)$ss['Preco'],
    ], $subsPorSv[$s['IDServico']] ?? []),
], $servicos));

$diasNomes = ['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'];
$mesesNomes = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
$dataExibida = $diasNomes[$diaSemana] . ', ' . date('d', $dataTs) . ' de ' . $mesesNomes[(int)date('n', $dataTs)];

$dataPrev = date('Y-m-d', $dataTs - 86400);
$dataNext = date('Y-m-d', $dataTs + 86400);

$paginaTitulo = 'Novo Agendamento';
$areaAtual    = 'painel';
require_once __DIR__ . '/../geral/header.php';
?>
<style>
/* ── Grade de slots ─────────────────────────── */
.slot-grid { display: flex; flex-wrap: wrap; gap: .35rem; }

.slot {
    padding: .3rem .65rem;
    border-radius: 8px;
    font-size: .82rem;
    font-weight: 600;
    cursor: default;
    user-select: none;
    transition: background .15s, border-color .15s, transform .1s;
    border: 1.5px solid transparent;
    white-space: nowrap;
    position: relative;
}

/* livre */
.slot-livre {
    border-color: var(--card-border-color);
    color: var(--text-main);
    cursor: pointer;
    background: var(--bg-card);
}
.slot-livre:hover { border-color: var(--accent); background: var(--accent-light); }

/* livre mas o serviço selecionado transbordaria/conflitaria aqui */
.slot-livre.slot-invalido {
    opacity: .38;
    cursor: not-allowed;
    border-color: var(--card-border-color);
}
.slot-livre.slot-invalido:hover { border-color: var(--card-border-color); background: var(--bg-card); }

/* selecionado */
.slot-livre.slot-sel {
    background: var(--accent);
    border-color: var(--accent);
    color: #fff;
    transform: scale(1.06);
}

/* ocupado */
.slot-ocupado {
    background: var(--bg-hover);
    color: var(--text-secondary);
    border-color: var(--card-border-color);
    opacity: .7;
}

/* bloqueado */
.slot-bloqueado {
    background: rgba(192,96,74,.12);
    color: #C0604A;
    border-color: rgba(192,96,74,.3);
}

/* almoço */
.slot-almoco {
    background: rgba(212,150,58,.1);
    color: #D4963A;
    border-color: rgba(212,150,58,.3);
}

/* tooltip */
.slot[data-info]:hover::after {
    content: attr(data-info);
    position: absolute;
    bottom: calc(100% + 6px);
    left: 50%;
    transform: translateX(-50%);
    background: #10002b;
    color: #fff;
    font-size: .72rem;
    font-weight: 400;
    padding: .3rem .65rem;
    border-radius: 6px;
    white-space: nowrap;
    pointer-events: none;
    z-index: 20;
    max-width: 220px;
    text-overflow: ellipsis;
    overflow: hidden;
}

/* cliente search */
#buscaClienteWrap { position: relative; }
#dropClientes {
    position: absolute; top: 100%; left: 0; right: 0; z-index: 30;
    background: var(--bg-card);
    border: 1px solid var(--card-border-color);
    border-top: none;
    border-radius: 0 0 8px 8px;
    max-height: 200px;
    overflow-y: auto;
}
#dropClientes .dc-item {
    padding: .5rem .75rem;
    cursor: pointer;
    font-size: .88rem;
    border-bottom: 1px solid var(--card-border-color);
}
#dropClientes .dc-item:last-child { border-bottom: none; }
#dropClientes .dc-item:hover { background: var(--bg-hover); }
#dropClientes .dc-item small { display: block; color: var(--text-secondary); font-size: .76rem; }

/* badge de hora final */
#badgeHoraFim {
    font-size: .78rem;
    padding: .25rem .6rem;
    border-radius: 6px;
    background: var(--accent-light);
    color: var(--accent);
}
</style>

<!-- Cabeçalho da página -->
<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
    <a href="<?= BASE ?>/painel/agenda.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Agenda
    </a>
    <div>
        <h4 class="fw-bold mb-0">Novo agendamento</h4>
        <p class="text-secondary small mb-0">Criação manual pela designer</p>
    </div>
</div>

<div class="row g-4 align-items-start">

    <!-- ── Grade de horários ── -->
    <div class="col-lg-7">
        <div class="card">
            <!-- Navegação de data -->
            <div class="card-header px-4 py-3 d-flex align-items-center justify-content-between gap-2">
                <a href="?data=<?= h($dataPrev) ?>" class="btn btn-sm btn-outline-secondary" title="Dia anterior">
                    <i class="bi bi-chevron-left"></i>
                </a>
                <div class="text-center flex-grow-1">
                    <div class="fw-bold"><?= h($dataExibida) ?></div>
                    <input type="date" id="seletorData" class="form-control form-control-sm mt-1"
                           style="max-width:160px;margin:0 auto;"
                           value="<?= h($dataSel) ?>">
                </div>
                <a href="?data=<?= h($dataNext) ?>" class="btn btn-sm btn-outline-secondary" title="Próximo dia">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </div>

            <div class="card-body p-4">
                <?php if (!$horario): ?>
                    <div class="text-center py-5 text-secondary">
                        <i class="bi bi-calendar-x fs-1 d-block mb-2 opacity-25"></i>
                        <p class="mb-0">Sem expediente neste dia.</p>
                    </div>
                <?php elseif (empty($slots)): ?>
                    <div class="text-center py-5 text-secondary">
                        <i class="bi bi-clock fs-1 d-block mb-2 opacity-25"></i>
                        <p class="mb-0">Nenhum slot gerado para este dia.</p>
                    </div>
                <?php else: ?>
                    <!-- Legenda -->
                    <div class="d-flex flex-wrap gap-2 mb-3" style="font-size:.76rem;">
                        <span class="slot slot-livre" style="pointer-events:none;">Livre</span>
                        <span class="slot slot-ocupado" style="pointer-events:none;">Ocupado</span>
                        <span class="slot slot-bloqueado" style="pointer-events:none;">Bloqueado</span>
                        <span class="slot slot-almoco" style="pointer-events:none;">Almoço</span>
                        <span class="slot slot-livre slot-sel" style="pointer-events:none;transform:none;">Selecionado</span>
                        <span class="slot slot-livre slot-invalido" style="pointer-events:none;">Serviço não cabe</span>
                    </div>

                    <div class="slot-grid" id="slotGrid">
                        <?php foreach ($slots as $s): ?>
                            <?php if ($s['status'] === 'livre'): ?>
                                <button type="button"
                                        class="slot slot-livre"
                                        data-ts="<?= $s['ts'] ?>"
                                        data-hora="<?= h($s['hora']) ?>"
                                        onclick="selecionarSlot(this)">
                                    <?= h($s['hora']) ?>
                                </button>
                            <?php elseif ($s['status'] === 'ocupado'): ?>
                                <span class="slot slot-ocupado"
                                      data-info="<?= h($s['info']) ?>">
                                    <?= h($s['hora']) ?>
                                </span>
                            <?php elseif ($s['status'] === 'bloqueado'): ?>
                                <span class="slot slot-bloqueado"
                                      data-info="<?= h($s['info']) ?>">
                                    <?= h($s['hora']) ?>
                                </span>
                            <?php else: ?>
                                <span class="slot slot-almoco"
                                      data-info="<?= h($s['info']) ?>">
                                    <?= h($s['hora']) ?>
                                </span>
                            <?php endif ?>
                        <?php endforeach ?>
                    </div>
                <?php endif ?>
            </div>
        </div>
    </div>

    <!-- ── Painel de formulário ── -->
    <div class="col-lg-5">
        <div class="card sticky-top" style="top:80px;">
            <div class="card-header px-4 py-3">
                <i class="bi bi-calendar-plus me-2 text-accent"></i>Detalhes do agendamento
            </div>
            <div class="card-body p-4">
                <form method="POST" action="<?= BASE ?>/painel/salvar_agendamento.php" id="formNovoAg">
                    <input type="hidden" name="csrf_token" value="<?= h(gerarTokenCSRF()) ?>">
                    <input type="hidden" name="data"       value="<?= h($dataSel) ?>">
                    <input type="hidden" name="hora"       id="inp_hora">
                    <input type="hidden" name="fk_sub"     id="inp_fk_sub">

                    <!-- Horário selecionado -->
                    <div class="mb-3 p-3 rounded-3" style="background:var(--bg-hover);">
                        <div class="small text-secondary mb-1">Horário selecionado</div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="fw-bold fs-5" id="lblHoraSel" style="color:var(--accent);">—</span>
                            <span id="badgeHoraFim" class="d-none">até <strong id="lblHoraFim"></strong></span>
                        </div>
                    </div>

                    <!-- Serviço -->
                    <div class="mb-3">
                        <label class="form-label fw-medium">Serviço <span class="text-danger">*</span></label>
                        <select name="fk_servico" id="selServico" class="form-select" required onchange="onServicoChange()">
                            <option value="">Selecione…</option>
                            <?php foreach ($servicos as $sv): ?>
                                <option value="<?= h($sv['IDServico']) ?>"
                                        data-duracao="<?= (int)$sv['DuracaoMinutos'] ?>"
                                        data-preco="<?= (float)$sv['Preco'] ?>">
                                    <?= h($sv['Nome']) ?> (<?= (int)$sv['DuracaoMinutos'] ?>min)
                                </option>
                            <?php endforeach ?>
                        </select>
                    </div>

                    <!-- Sub-serviço (aparece quando há manutenções) -->
                    <div class="mb-3 d-none" id="wrapSub">
                        <label class="form-label fw-medium">Manutenção / variante</label>
                        <select name="fk_sub_sel" id="selSub" class="form-select" onchange="onSubChange()">
                            <option value="">— Sem manutenção —</option>
                        </select>
                    </div>

                    <!-- Duração resumida -->
                    <div class="mb-3 small text-secondary" id="resumoDuracao" style="display:none;">
                        <i class="bi bi-clock me-1"></i><span id="txtDuracao"></span> min de duração
                    </div>

                    <!-- ── Cliente ── -->
                    <div class="mb-1 fw-medium">Cliente <span class="text-danger">*</span></div>
                    <div class="mb-3 d-flex gap-2">
                        <label class="d-flex align-items-center gap-1 cursor-pointer">
                            <input type="radio" name="tipo_cliente" value="cadastrada" checked
                                   onchange="toggleCliente('cadastrada')">
                            <span class="small">Cadastrada</span>
                        </label>
                        <label class="d-flex align-items-center gap-1 cursor-pointer">
                            <input type="radio" name="tipo_cliente" value="avulsa"
                                   onchange="toggleCliente('avulsa')">
                            <span class="small">Avulsa / sem cadastro</span>
                        </label>
                    </div>

                    <!-- Busca de cliente cadastrada -->
                    <div id="secCadastrada" class="mb-3">
                        <div id="buscaClienteWrap">
                            <input type="text" id="buscaCliente" class="form-control"
                                   placeholder="Buscar por nome ou e-mail…"
                                   autocomplete="off" oninput="buscarCliente(this.value)">
                            <div id="dropClientes" style="display:none;"></div>
                        </div>
                        <input type="hidden" name="fk_cliente" id="inp_fk_cliente">
                        <div id="clienteSel" class="mt-2 small text-secondary d-none">
                            <i class="bi bi-person-check-fill text-accent me-1"></i>
                            <span id="clienteSelNome"></span>
                            <button type="button" class="btn btn-link btn-sm p-0 ms-1 text-danger"
                                    onclick="limparCliente()">×</button>
                        </div>
                    </div>

                    <!-- Dados de cliente avulsa -->
                    <div id="secAvulsa" class="mb-3 d-none">
                        <input type="text" name="nome_avulso" class="form-control mb-2"
                               placeholder="Nome da cliente *"
                               oninput="validarForm()">
                        <input type="tel" name="tel_avulso" class="form-control"
                               placeholder="Telefone / WhatsApp (opcional)">
                    </div>

                    <!-- Valor -->
                    <div class="mb-3">
                        <label class="form-label fw-medium">Valor (R$)</label>
                        <input type="number" name="valor" id="inp_valor" class="form-control"
                               step="0.01" min="0" placeholder="Preço padrão do serviço">
                    </div>

                    <!-- Observações -->
                    <div class="mb-4">
                        <label class="form-label fw-medium">Observações</label>
                        <textarea name="obs" class="form-control" rows="2"
                                  placeholder="Informações extras, preferências…"></textarea>
                    </div>

                    <!-- Recorrência -->
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="chkRecorrencia"
                                   name="recorrencia" value="1" onchange="toggleRecorrencia()">
                            <label class="form-check-label fw-medium" for="chkRecorrencia">
                                <i class="bi bi-arrow-repeat me-1"></i>Criar série recorrente
                            </label>
                        </div>
                        <div id="secRecorrencia" class="d-none mt-2 p-3 rounded"
                             style="background:var(--bg-hover);border:1px solid var(--card-border-color);">
                            <div class="row g-2 align-items-end">
                                <div class="col-auto">
                                    <label class="form-label small mb-1">Repetir a cada</label>
                                    <input type="number" name="rec_intervalo" id="recIntervalo"
                                           class="form-control form-control-sm" style="width:70px;"
                                           value="1" min="1" max="52" oninput="atualizarPreviewRec()">
                                </div>
                                <div class="col-auto">
                                    <label class="form-label small mb-1">&nbsp;</label>
                                    <select name="rec_unidade" id="recUnidade" class="form-select form-select-sm"
                                            onchange="atualizarPreviewRec()">
                                        <option value="7">semana(s)</option>
                                        <option value="1">dia(s)</option>
                                        <option value="30">mês(es)</option>
                                    </select>
                                </div>
                                <div class="col-auto">
                                    <label class="form-label small mb-1">por</label>
                                    <input type="number" name="rec_vezes" id="recVezes"
                                           class="form-control form-control-sm" style="width:70px;"
                                           value="4" min="2" max="104" oninput="atualizarPreviewRec()">
                                </div>
                                <div class="col-auto">
                                    <label class="form-label small mb-1">&nbsp;</label>
                                    <span class="form-control-plaintext form-control-sm">vezes</span>
                                </div>
                            </div>
                            <div id="previewRec" class="text-secondary small mt-2"></div>
                        </div>
                    </div>

                    <button type="submit" id="btnSalvar" class="btn btn-accent w-100" disabled>
                        <i class="bi bi-calendar-check me-2"></i>Criar agendamento
                    </button>
                    <div id="erroForm" class="text-danger small mt-2" style="display:none;"></div>
                </form>
            </div>
        </div>
    </div>

</div>

<script>
var AGENDAMENTOS   = <?= $agJson ?>;
var BLOQUEIOS      = <?= $bloqJson ?>;
var ALMOCO         = <?= $almocoJson ?>;
var SERVICOS       = <?= $svsJson ?>;
var FIM_JORNADA_TS = <?= $fimJornadaTs ?>;

var slotSelecionadoTs   = null;
var duracaoAtual        = 0;
var tipoCliente         = 'cadastrada';
var clienteIdSelecionado = null;
var buscaTimer          = null;

/* ── Navegação de data ────────────────────────── */
document.getElementById('seletorData').addEventListener('change', function () {
    if (this.value) location.href = '?data=' + this.value;
});

/* ── Seleção de slot ──────────────────────────── */
function selecionarSlot(btn) {
    if (btn.classList.contains('slot-invalido')) return;

    document.querySelectorAll('.slot-livre.slot-sel').forEach(b => b.classList.remove('slot-sel'));
    btn.classList.add('slot-sel');

    slotSelecionadoTs = parseInt(btn.dataset.ts);
    document.getElementById('inp_hora').value = btn.dataset.hora;
    document.getElementById('lblHoraSel').textContent = btn.dataset.hora;

    atualizarHoraFim();
    atualizarValidezSlots();
    validarForm();
    if (document.getElementById('chkRecorrencia').checked) atualizarPreviewRec();
}

function atualizarHoraFim() {
    if (!slotSelecionadoTs || !duracaoAtual) return;
    var fimTs   = slotSelecionadoTs + duracaoAtual * 60;
    var fimHora = new Date(fimTs * 1000).toLocaleTimeString('pt-BR', {hour:'2-digit',minute:'2-digit'});
    document.getElementById('lblHoraFim').textContent = fimHora;
    document.getElementById('badgeHoraFim').classList.remove('d-none');
}

/* ── Marca slots inválidos com o serviço atual ── */
function atualizarValidezSlots() {
    document.querySelectorAll('.slot-livre').forEach(function (btn) {
        var ts    = parseInt(btn.dataset.ts);
        var fim   = ts + duracaoAtual * 60;
        var invalido = false;

        // Serviço ultrapassa o fim da jornada
        if (duracaoAtual > 0 && fim > FIM_JORNADA_TS) invalido = true;

        // Conflito com agendamentos
        if (!invalido) {
            invalido = AGENDAMENTOS.some(function (ag) {
                return ts < ag.fim && fim > ag.ini;
            });
        }

        // Conflito com bloqueios
        if (!invalido) {
            invalido = BLOQUEIOS.some(function (b) {
                return ts < b.fim && fim > b.ini;
            });
        }

        // Conflito com almoço
        if (!invalido && ALMOCO) {
            invalido = (ts < ALMOCO.fim && fim > ALMOCO.ini);
        }

        btn.classList.toggle('slot-invalido', invalido && !btn.classList.contains('slot-sel'));
    });
}

/* ── Serviço / sub-serviço ───────────────────── */
function onServicoChange() {
    var sel   = document.getElementById('selServico');
    var opt   = sel.options[sel.selectedIndex];
    var svId  = sel.value;
    var durac = svId ? parseInt(opt.dataset.duracao) : 0;
    var preco = svId ? parseFloat(opt.dataset.preco)  : 0;

    // Sub-serviços
    var wrapSub = document.getElementById('wrapSub');
    var selSub  = document.getElementById('selSub');
    var sv      = SERVICOS.find(function (s) { return s.id === svId; });

    selSub.innerHTML = '<option value="">— Sem manutenção —</option>';
    document.getElementById('inp_fk_sub').value = '';

    if (sv && sv.subs.length > 0) {
        sv.subs.forEach(function (ss) {
            var o = document.createElement('option');
            o.value       = ss.id;
            o.textContent = ss.nome + ' (' + ss.duracao + 'min)';
            o.dataset.duracao = ss.duracao;
            o.dataset.preco   = ss.preco;
            selSub.appendChild(o);
        });
        wrapSub.classList.remove('d-none');
    } else {
        wrapSub.classList.add('d-none');
    }

    aplicarDuracaoPreco(durac, preco);
}

function onSubChange() {
    var selSub = document.getElementById('selSub');
    var opt    = selSub.options[selSub.selectedIndex];

    if (selSub.value) {
        document.getElementById('inp_fk_sub').value = selSub.value;
        aplicarDuracaoPreco(parseInt(opt.dataset.duracao), parseFloat(opt.dataset.preco));
    } else {
        document.getElementById('inp_fk_sub').value = '';
        // Volta para o serviço pai
        var selSv  = document.getElementById('selServico');
        var optSv  = selSv.options[selSv.selectedIndex];
        if (selSv.value) {
            aplicarDuracaoPreco(parseInt(optSv.dataset.duracao), parseFloat(optSv.dataset.preco));
        }
    }
}

function aplicarDuracaoPreco(duracao, preco) {
    duracaoAtual = duracao || 0;

    var divDur = document.getElementById('resumoDuracao');
    if (duracaoAtual > 0) {
        document.getElementById('txtDuracao').textContent = duracaoAtual;
        divDur.style.display = '';
    } else {
        divDur.style.display = 'none';
    }

    if (preco > 0) {
        document.getElementById('inp_valor').value = preco.toFixed(2);
    }

    atualizarHoraFim();
    atualizarValidezSlots();

    // Se o slot selecionado ficou inválido com o novo serviço, desmarca
    // (não podemos checar slot-invalido no btn porque a guarda no toggle o omite do slot-sel)
    if (slotSelecionadoTs && duracaoAtual > 0) {
        var fimSel   = slotSelecionadoTs + duracaoAtual * 60;
        var invalSel = false;
        if (fimSel > FIM_JORNADA_TS) invalSel = true;
        if (!invalSel) invalSel = AGENDAMENTOS.some(function (ag) { return slotSelecionadoTs < ag.fim && fimSel > ag.ini; });
        if (!invalSel) invalSel = BLOQUEIOS.some(function (b) { return slotSelecionadoTs < b.fim && fimSel > b.ini; });
        if (!invalSel && ALMOCO) invalSel = (slotSelecionadoTs < ALMOCO.fim && fimSel > ALMOCO.ini);

        if (invalSel) {
            var btnSel = document.querySelector('.slot-livre.slot-sel');
            if (btnSel) btnSel.classList.remove('slot-sel');
            slotSelecionadoTs = null;
            document.getElementById('inp_hora').value = '';
            document.getElementById('lblHoraSel').textContent = '—';
            document.getElementById('badgeHoraFim').classList.add('d-none');
        }
    }

    validarForm();
}

/* ── Cliente cadastrada (busca) ──────────────── */
function toggleCliente(tipo) {
    tipoCliente = tipo;
    document.getElementById('secCadastrada').classList.toggle('d-none', tipo !== 'cadastrada');
    document.getElementById('secAvulsa').classList.toggle('d-none',    tipo !== 'avulsa');
    validarForm();
}

function buscarCliente(q) {
    clearTimeout(buscaTimer);
    var drop = document.getElementById('dropClientes');
    if (q.length < 2) { drop.style.display = 'none'; return; }

    buscaTimer = setTimeout(function () {
        fetch('<?= BASE ?>/painel/api_busca_clientes.php?q=' + encodeURIComponent(q))
            .then(function (r) { return r.json(); })
            .then(function (lista) {
                drop.innerHTML = '';
                if (!lista.length) { drop.style.display = 'none'; return; }
                lista.forEach(function (c) {
                    var item = document.createElement('div');
                    item.className = 'dc-item';
                    item.addEventListener('click', function () { escolherCliente(c.id, c.nome); });

                    var nome = document.createElement('span');
                    nome.textContent = c.nome;
                    item.appendChild(nome);

                    var sub = document.createElement('small');
                    sub.textContent = c.telefone || c.email || '';
                    item.appendChild(sub);

                    drop.appendChild(item);
                });
                drop.style.display = 'block';
            });
    }, 280);
}

function escolherCliente(id, nome) {
    clienteIdSelecionado = id;
    document.getElementById('inp_fk_cliente').value = id;
    document.getElementById('dropClientes').style.display = 'none';
    document.getElementById('buscaCliente').value = nome;
    document.getElementById('clienteSelNome').textContent = nome;
    document.getElementById('clienteSel').classList.remove('d-none');
    validarForm();
}

function limparCliente() {
    clienteIdSelecionado = null;
    document.getElementById('inp_fk_cliente').value = '';
    document.getElementById('buscaCliente').value = '';
    document.getElementById('clienteSel').classList.add('d-none');
    validarForm();
}

document.addEventListener('click', function (e) {
    if (!document.getElementById('buscaClienteWrap').contains(e.target)) {
        document.getElementById('dropClientes').style.display = 'none';
    }
});

/* ── Validação geral ─────────────────────────── */
function validarForm() {
    var ok = true;
    var erro = '';

    if (!slotSelecionadoTs) { ok = false; erro = 'Selecione um horário na grade.'; }
    if (!document.getElementById('selServico').value) { ok = false; erro = erro || 'Selecione um serviço.'; }

    if (tipoCliente === 'cadastrada' && !clienteIdSelecionado) {
        ok = false; erro = erro || 'Busque e selecione uma cliente cadastrada.';
    }
    if (tipoCliente === 'avulsa') {
        var nome = document.querySelector('[name="nome_avulso"]').value.trim();
        if (!nome) { ok = false; erro = erro || 'Informe o nome da cliente avulsa.'; }
    }

    document.getElementById('btnSalvar').disabled = !ok;
    var divErro = document.getElementById('erroForm');
    if (!ok && erro) {
        divErro.textContent = erro;
        divErro.style.display = '';
    } else {
        divErro.style.display = 'none';
    }
}

/* Observa campos de texto avulso */
document.querySelector('[name="nome_avulso"]').addEventListener('input', validarForm);

/* ── Recorrência ─────────────────────────────── */
function toggleRecorrencia() {
    var on = document.getElementById('chkRecorrencia').checked;
    document.getElementById('secRecorrencia').classList.toggle('d-none', !on);
    if (on) atualizarPreviewRec();
}

function atualizarPreviewRec() {
    var intervalo = parseInt(document.getElementById('recIntervalo').value) || 1;
    var unidade   = parseInt(document.getElementById('recUnidade').value)   || 7;
    var vezes     = parseInt(document.getElementById('recVezes').value)     || 2;
    var div       = document.getElementById('previewRec');

    if (!slotSelecionadoTs) {
        div.textContent = 'Selecione um horário para ver o resumo.';
        return;
    }

    var dias  = intervalo * unidade;
    var datas = [];
    for (var i = 0; i < Math.min(vezes, 104); i++) {
        var d = new Date((slotSelecionadoTs + i * dias * 86400) * 1000);
        datas.push(d.toLocaleDateString('pt-BR', {day:'2-digit', month:'2-digit', year:'numeric'}));
    }

    var nomePeriodo = unidade === 1 ? 'dia(s)' : unidade === 7 ? 'semana(s)' : 'mês(es)';
    var preview = vezes + ' agendamentos · a cada ' + intervalo + ' ' + nomePeriodo;
    if (datas.length <= 6) {
        preview += '<br><span class="text-accent">' + datas.join('  →  ') + '</span>';
    } else {
        var primeiras = datas.slice(0, 3);
        var ultimas   = datas.slice(-2);
        preview += '<br><span class="text-accent">' + primeiras.join('  →  ') + '  →  …  →  ' + ultimas.join('  →  ') + '</span>';
    }
    div.innerHTML = preview;
}

/* Inicializa */
validarForm();
</script>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
