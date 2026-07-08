<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('cliente');

$servicoId = trim($_GET['servico_id'] ?? '');
$subId     = trim($_GET['sub_id']     ?? '');
$nome      = trim($_GET['nome']       ?? '');
$preco     = (float)($_GET['preco']   ?? 0);
$duracao   = (int)($_GET['duracao']   ?? 60);

if (!$servicoId || !$nome) {
    redirecionarComMensagem(BASE . '/agendamento/index.php', 'Selecione um serviço.', 'warning');
}

// Configurações
$intervalo   = (int) getConfig($pdo, 'intervalo_minutos', '15');
$antecMinH   = (int) getConfig($pdo, 'antecedencia_minima_h', '2');
$diasFuturos = (int) getConfig($pdo, 'dias_agenda_futura', '60');

// Data selecionada (default: hoje ou amanhã se já passou do horário)
$dataSel = trim($_GET['data'] ?? date('Y-m-d', strtotime("+{$antecMinH} hours")));
$dataTs  = strtotime($dataSel);
if (!$dataTs) {
    $dataSel = date('Y-m-d');
    $dataTs  = strtotime($dataSel);
}

// Limites de data
$dataMin = date('Y-m-d', strtotime("+{$antecMinH} hours"));
$dataMax = date('Y-m-d', strtotime("+{$diasFuturos} days"));

if ($dataSel < $dataMin) {
    $dataSel = $dataMin;
    $dataTs  = strtotime($dataMin);
}

// Horário de atendimento neste dia da semana
$diaSemana = (int) date('w', $dataTs); // 0=dom
try {
    $horStmt = $pdo->prepare(
        'SELECT HoraInicio, HoraFim FROM HorariosAtendimento
         WHERE DiaSemana = :d AND Ativo = 1 LIMIT 1'
    );
    $horStmt->execute([':d' => $diaSemana]);
    $horario = $horStmt->fetch();

    // Agendamentos existentes neste dia
    $agStmt = $pdo->prepare(
        'SELECT DataHoraAgendamento, DataHoraFim FROM Agendamentos
         WHERE DATE(DataHoraAgendamento) = :data
           AND StatusAgendamento NOT IN (\'cancelado\')'
    );
    $agStmt->execute([':data' => $dataSel]);
    $agendados = $agStmt->fetchAll();

    // Reservas temporárias de outras sessões
    $minhaSessao = session_id();
    $pdo->exec("DELETE FROM ReservasTemporarias WHERE ExpiraEm < NOW()");
    $tempStmt = $pdo->prepare(
        'SELECT DataHoraSlot, DataHoraFim FROM ReservasTemporarias
         WHERE DATE(DataHoraSlot) = :data
           AND TokenSessao != :sessao
           AND ExpiraEm > NOW()'
    );
    $tempStmt->execute([':data' => $dataSel, ':sessao' => $minhaSessao]);
    $reservasTemp = $tempStmt->fetchAll();

    // Bloqueios que interceptam este dia
    $bloqStmt = $pdo->prepare(
        'SELECT DataInicio, DataFim FROM BloqueiosAgenda
         WHERE DATE(DataInicio) <= :data AND DATE(DataFim) >= :data2'
    );
    $bloqStmt->execute([':data' => $dataSel, ':data2' => $dataSel]);
    $bloqueios = $bloqStmt->fetchAll();
} catch (PDOException $e) {
    error_log('[Horarios] ' . $e->getMessage());
    $horario = null;
    $agendados = $bloqueios = $reservasTemp = [];
    $minhaSessao = session_id();
}

// Gerar slots disponíveis
$slots = [];
if ($horario) {
    $passo    = ($duracao + $intervalo);  // duração do serviço + intervalo
    $inicioTs = strtotime("{$dataSel} {$horario['HoraInicio']}");
    $fimTs    = strtotime("{$dataSel} {$horario['HoraFim']}");
    $agora    = time() + ($antecMinH * 3600);

    for ($ts = $inicioTs; ($ts + $duracao * 60) <= $fimTs; $ts += $passo * 60) {
        if ($ts < $agora) continue; // já passou

        $slotFim = $ts + $duracao * 60;
        $livre   = true;

        // Verificar conflito com agendamentos
        foreach ($agendados as $ag) {
            $agIni = strtotime($ag['DataHoraAgendamento']);
            $agFim = strtotime($ag['DataHoraFim']);
            if ($ts < $agFim && $slotFim > $agIni) { $livre = false; break; }
        }

        // Verificar reservas temporárias de outras sessões
        if ($livre) {
            foreach ($reservasTemp as $rt) {
                $rtIni = strtotime($rt['DataHoraSlot']);
                $rtFim = strtotime($rt['DataHoraFim']);
                if ($ts < $rtFim && $slotFim > $rtIni) { $livre = false; break; }
            }
        }

        // Verificar bloqueios
        if ($livre) {
            foreach ($bloqueios as $b) {
                $bIni = strtotime($b['DataInicio']);
                $bFim = strtotime($b['DataFim']);
                if ($ts < $bFim && $slotFim > $bIni) { $livre = false; break; }
            }
        }

        if ($livre) {
            $slots[] = date('H:i', $ts);
        }
    }
}

$paginaTitulo = 'Agendar — Escolha o horário';
$areaAtual    = 'cliente';
require_once __DIR__ . '/../geral/header.php';
?>

<!-- Progresso -->
<div class="d-flex align-items-center gap-2 mb-5">
    <a href="<?= BASE ?>/agendamento/index.php"
       class="badge rounded-pill px-3 py-2 text-decoration-none"
       style="background:var(--card-border-color);color:var(--text-secondary);font-size:.9rem;">
        1. Serviço
    </a>
    <div class="flex-grow-1 border-top" style="border-color:var(--card-border-color)!important;"></div>
    <span class="badge rounded-pill px-3 py-2" style="background:var(--accent);font-size:.9rem;">
        2. Horário
    </span>
    <div class="flex-grow-1 border-top" style="border-color:var(--card-border-color)!important;"></div>
    <span class="badge rounded-pill px-3 py-2 bg-light text-secondary" style="font-size:.9rem;">
        3. Confirmar
    </span>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <!-- Seletor de data -->
        <div class="card mb-3 p-3">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <label class="fw-medium mb-0">Data:</label>
                <input type="date" id="seletorData" class="form-control" style="width:auto;"
                       min="<?= h($dataMin) ?>" max="<?= h($dataMax) ?>"
                       value="<?= h($dataSel) ?>">
                <button onclick="irParaData()" class="btn btn-accent btn-sm">Ver horários</button>
            </div>
        </div>

        <!-- Slots de horário -->
        <div class="card">
            <div class="card-header px-4 py-3">
                <i class="bi bi-clock me-2 text-accent"></i>
                Horários disponíveis — <?= date('d/m/Y (l)', $dataTs) ?>
            </div>
            <div class="card-body">
                <?php if (!$horario): ?>
                <div class="text-center py-4 text-secondary">
                    <i class="bi bi-calendar-x fs-2 d-block mb-2 opacity-25"></i>
                    <p>Não atendemos neste dia. Escolha outra data.</p>
                </div>
                <?php elseif (empty($slots)): ?>
                <div class="text-center py-4 text-secondary">
                    <i class="bi bi-clock-history fs-2 d-block mb-2 opacity-25"></i>
                    <p>Nenhum horário disponível neste dia. Tente outra data.</p>
                </div>
                <?php else: ?>
                <div class="d-flex flex-wrap gap-2" id="listaSlots">
                    <?php foreach ($slots as $slot): ?>
                    <button type="button"
                            class="btn btn-outline-accent slot-btn"
                            data-hora="<?= h($slot) ?>"
                            onclick="selecionarSlot(this)">
                        <?= h($slot) ?>
                    </button>
                    <?php endforeach ?>
                </div>
                <?php endif ?>
            </div>
        </div>
    </div>

    <!-- Resumo do serviço -->
    <div class="col-lg-4">
        <div class="card p-4 sticky-top" style="top:80px;">
            <h6 class="fw-bold mb-3"><i class="bi bi-scissors me-2 text-accent"></i>Resumo</h6>
            <dl class="mb-3">
                <dt class="small text-secondary">Serviço</dt>
                <dd class="fw-medium"><?= h($nome) ?></dd>
                <dt class="small text-secondary">Duração</dt>
                <dd><?= $duracao ?> minutos</dd>
                <dt class="small text-secondary">Valor</dt>
                <dd class="fw-bold text-accent fs-5"><?= formatarMoeda($preco) ?></dd>
                <dt class="small text-secondary">Data</dt>
                <dd id="resumoData">—</dd>
                <dt class="small text-secondary">Horário</dt>
                <dd id="resumoHora">—</dd>
            </dl>
            <!-- Countdown de reserva -->
            <div id="boxCountdown" class="d-none alert alert-warning py-2 px-3 mb-3 d-flex align-items-center gap-2" style="font-size:.9rem;">
                <i class="bi bi-hourglass-split text-warning"></i>
                <span>Horário reservado por <strong id="textoCountdown">10:00</strong></span>
            </div>

            <a href="#" id="btnConfirmar"
               class="btn btn-accent btn-lg w-100 disabled">
                Confirmar agendamento <i class="bi bi-arrow-right ms-1"></i>
            </a>
        </div>
    </div>
</div>

<form id="formConfirmar" method="GET" action="<?= BASE ?>/agendamento/confirmar.php">
    <input type="hidden" name="servico_id" value="<?= h($servicoId) ?>">
    <input type="hidden" name="sub_id"     value="<?= h($subId) ?>">
    <input type="hidden" name="nome"       value="<?= h($nome) ?>">
    <input type="hidden" name="preco"      value="<?= h($preco) ?>">
    <input type="hidden" name="duracao"    value="<?= h($duracao) ?>">
    <input type="hidden" name="data"       value="<?= h($dataSel) ?>">
    <input type="hidden" name="hora"       id="inp_hora">
    <input type="hidden" name="token"      id="inp_token">
</form>

<script>
function irParaData() {
    const data = document.getElementById('seletorData').value;
    if (!data) return;
    const url = new URL(location.href);
    url.searchParams.set('data', data);
    location.href = url.toString();
}
document.getElementById('seletorData')?.addEventListener('change', irParaData);

let slotSelecionado = null;
let countdownInterval = null;
let countdownExpira   = null;

function iniciarCountdown(expiraISO) {
    clearInterval(countdownInterval);
    const box = document.getElementById('boxCountdown');
    const txt = document.getElementById('textoCountdown');
    if (!box || !txt) return;

    countdownExpira = new Date(expiraISO.replace(' ', 'T'));
    box.classList.remove('d-none');

    function tick() {
        const diff = Math.round((countdownExpira - new Date()) / 1000);
        if (diff <= 0) {
            clearInterval(countdownInterval);
            txt.textContent = '00:00';
            // Remove seleção e desativa botão
            if (slotSelecionado) {
                slotSelecionado.classList.add('btn-outline-accent');
                slotSelecionado.classList.remove('btn-accent');
                slotSelecionado.disabled = false;
                slotSelecionado = null;
            }
            document.getElementById('btnConfirmar').classList.add('disabled');
            box.classList.add('d-none');
            return;
        }
        const m = String(Math.floor(diff / 60)).padStart(2, '0');
        const s = String(diff % 60).padStart(2, '0');
        txt.textContent = m + ':' + s;
    }
    tick();
    countdownInterval = setInterval(tick, 1000);
}

function selecionarSlot(btn) {
    if (btn.disabled) return;

    // Desativa todos os botões enquanto faz a reserva
    document.querySelectorAll('.slot-btn').forEach(b => b.disabled = true);

    const hora = btn.dataset.hora;
    const data = '<?= $dataSel ?>';

    fetch('<?= BASE ?>/agendamento/reservar_slot.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            data:       data,
            hora:       hora,
            servico_id: '<?= h($servicoId) ?>',
            sub_id:     '<?= h($subId) ?>',
            duracao:    '<?= $duracao ?>',
        })
    })
    .then(r => r.json())
    .then(res => {
        document.querySelectorAll('.slot-btn').forEach(b => b.disabled = false);

        if (!res.ok) {
            // Marca slot como indisponível e mostra aviso
            btn.disabled = true;
            btn.classList.remove('btn-outline-accent', 'btn-accent');
            btn.classList.add('btn-secondary', 'opacity-50');
            alert(res.msg || 'Horário indisponível. Tente outro.');
            return;
        }

        // Desmarca slot anterior
        if (slotSelecionado && slotSelecionado !== btn) {
            slotSelecionado.classList.remove('btn-accent');
            slotSelecionado.classList.add('btn-outline-accent');
        }

        btn.classList.remove('btn-outline-accent');
        btn.classList.add('btn-accent');
        slotSelecionado = btn;

        document.getElementById('inp_hora').value  = hora;
        document.getElementById('inp_token').value = res.token;
        document.getElementById('resumoData').textContent = data.split('-').reverse().join('/');
        document.getElementById('resumoHora').textContent = hora;

        const btnConf = document.getElementById('btnConfirmar');
        btnConf.classList.remove('disabled');
        btnConf.onclick = function(e) {
            e.preventDefault();
            document.getElementById('formConfirmar').submit();
        };

        iniciarCountdown(res.expira);
    })
    .catch(() => {
        document.querySelectorAll('.slot-btn').forEach(b => b.disabled = false);
        alert('Erro de conexão. Tente novamente.');
    });
}
</script>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
