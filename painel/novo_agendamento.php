<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

$dataSel    = trim($_GET['data'] ?? date('Y-m-d'));
$dataTs     = strtotime($dataSel) ?: time();
$dataSel    = date('Y-m-d', $dataTs);
$diaSemana  = (int) date('w', $dataTs);
$intervalo  = (int) getConfig($pdo, 'intervalo_minutos', '15');
$forca      = !empty($_GET['forca']);

$horario   = null;
$agendados = [];
$bloqueios = [];
$servicos  = [];
$subsPorSv = [];

// Horário do dia — try separado: falha aqui não impede serviços de carregar
try {
    $horStmt = $pdo->prepare(
        'SELECT HoraInicio, HoraFim, AlmocoInicio, AlmocoFim
         FROM HorariosAtendimento WHERE DiaSemana = :d AND Ativo = 1 LIMIT 1'
    );
    $horStmt->execute([':d' => $diaSemana]);
    $horario = $horStmt->fetch() ?: null;
} catch (PDOException $e) {
    error_log('[NovoAg] horario: ' . $e->getMessage());
    try {
        $horStmt2 = $pdo->prepare(
            'SELECT HoraInicio, HoraFim FROM HorariosAtendimento WHERE DiaSemana = :d AND Ativo = 1 LIMIT 1'
        );
        $horStmt2->execute([':d' => $diaSemana]);
        $h2 = $horStmt2->fetch();
        if ($h2) $horario = array_merge($h2, ['AlmocoInicio' => null, 'AlmocoFim' => null]);
    } catch (PDOException) {}
}

// Dia especial: busca tipo e intervalos
$diaEspecialId = null;
$intervalosEspeciais = [];
try {
    $deStmt = $pdo->prepare(
        'SELECT td.IDTipo, td.BloqueiaTotal, td.HoraInicio, td.HoraFim
         FROM DiasEspeciais de
         JOIN TiposDia td ON td.IDTipo = de.FKTipo
         WHERE de.Data = :data LIMIT 1'
    );
    $deStmt->execute([':data' => $dataSel]);
    $diaEsp = $deStmt->fetch() ?: null;
    if ($diaEsp && !$diaEsp['BloqueiaTotal'] && $diaEsp['HoraInicio'] && $diaEsp['HoraFim']) {
        $horario['HoraInicio'] = $diaEsp['HoraInicio'];
        $horario['HoraFim']    = $diaEsp['HoraFim'];
        $horario['AlmocoInicio'] = null;
        $horario['AlmocoFim']    = null;
        $diaEspecialId = $diaEsp['IDTipo'];
        $stIv = $pdo->prepare('SELECT Inicio, Fim FROM IntervalosTipo WHERE FKTipo = :id ORDER BY Inicio');
        $stIv->execute([':id' => $diaEspecialId]);
        $intervalosEspeciais = $stIv->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($diaEsp && $diaEsp['BloqueiaTotal']) {
        $horario = null;
    }
} catch (PDOException) {}

// Agendamentos e bloqueios do dia
try {
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
} catch (PDOException $e) {
    error_log('[NovoAg] agendados: ' . $e->getMessage());
}

// Serviços e clientes
$clientes = [];
try {
    $servicos = $pdo->query(
        'SELECT IDServico, Nome, DuracaoMinutos, Preco FROM Servicos WHERE Ativo = 1 ORDER BY Ordem'
    )->fetchAll();

    foreach ($pdo->query(
        'SELECT IDSubServico, FKServico, Nome, DuracaoMinutos, Preco FROM SubServicos WHERE Ativo = 1 ORDER BY Nome'
    )->fetchAll() as $ss) {
        $subsPorSv[$ss['FKServico']][] = $ss;
    }

    $clientes = $pdo->query(
        'SELECT IDUsuario AS id, Nome AS nome, Email AS email, Telefone AS telefone
         FROM Usuarios WHERE NivelAcesso = \'cliente\' ORDER BY Nome ASC LIMIT 500'
    )->fetchAll();
} catch (PDOException $e) {
    error_log('[NovoAg] servicos/clientes: ' . $e->getMessage());
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

        $ivsGrade = $intervalosEspeciais ?: (
            (!empty($horario['AlmocoInicio']) && !empty($horario['AlmocoFim']))
            ? [['Inicio' => $horario['AlmocoInicio'], 'Fim' => $horario['AlmocoFim']]]
            : []
        );
        foreach ($ivsGrade as $iv) {
            $ivIni = strtotime("{$dataSel} {$iv['Inicio']}");
            $ivFim = strtotime("{$dataSel} {$iv['Fim']}");
            if ($ts >= $ivIni && $ts < $ivFim) { $status = 'almoco'; $info = 'Intervalo'; break; }
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

// Inserção forçada: dia sem expediente — gera grade horária das 07h às 21h
if ($forca && !$horario && empty($slots)) {
    $fimJornadaTs = strtotime("{$dataSel} 21:00:00");
    for ($ts = strtotime("{$dataSel} 07:00:00"); $ts < $fimJornadaTs; $ts += 3600) {
        $status = 'livre';
        $info   = '';
        foreach ($agendados as $ag) {
            $agIni = strtotime($ag['DataHoraAgendamento']);
            $agFim = strtotime($ag['DataHoraFim']);
            if ($ts >= $agIni && $ts < $agFim) {
                $status = 'ocupado';
                $info   = $ag['NomeCliente'] . ' — ' . $ag['NomeServico'];
                break;
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

$_ivsJs = $intervalosEspeciais ?: (
    ($horario && !empty($horario['AlmocoInicio']) && !empty($horario['AlmocoFim']))
    ? [['Inicio' => $horario['AlmocoInicio'], 'Fim' => $horario['AlmocoFim']]]
    : []
);
$almocoJson = $horario && $_ivsJs
    ? json_encode(array_map(fn($iv) => [
        'ini' => strtotime("{$dataSel} {$iv['Inicio']}"),
        'fim' => strtotime("{$dataSel} {$iv['Fim']}"),
      ], $_ivsJs))
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

$clientesJson = json_encode(array_map(fn($c) => [
    'id'   => $c['id'],
    'nome' => $c['nome'],
    'tel'  => $c['telefone'] ? formatarTelefoneExibicao($c['telefone']) : '',
], $clientes));

// Semana corrente (segunda=0 … domingo=6, padrão brasileiro ISO 8601)
$semanaIni     = date('Y-m-d', $dataTs - (($diaSemana + 6) % 7) * 86400);
$semanaFim     = date('Y-m-d', strtotime($semanaIni) + 6 * 86400);
$semanaIniPrev = date('Y-m-d', strtotime($semanaIni) - 7 * 86400);
$semanaIniNext = date('Y-m-d', strtotime($semanaIni) + 7 * 86400);

$hoje         = date('Y-m-d');
$diaHoje      = (int) date('w');
$semanaMinIni = date('Y-m-d', strtotime('today') - (($diaHoje + 6) % 7) * 86400);
$podeIrAntes  = $semanaIniPrev >= $semanaMinIni;
$podeIrDepois = true;

// Dias com expediente regular (para marcar no calendário)
$horariosPorDiaSem = [];
try {
    foreach ($pdo->query('SELECT DiaSemana FROM HorariosAtendimento WHERE Ativo = 1')->fetchAll() as $h) {
        $horariosPorDiaSem[$h['DiaSemana']] = true;
    }
} catch (PDOException) {}

// Dias especiais da semana
$diasEspeciaisSemana = [];
try {
    $dsSmt = $pdo->prepare(
        'SELECT de.Data, td.BloqueiaTotal FROM DiasEspeciais de
         JOIN TiposDia td ON td.IDTipo = de.FKTipo
         WHERE de.Data BETWEEN :ini AND :fim'
    );
    $dsSmt->execute([':ini' => $semanaIni, ':fim' => $semanaFim]);
    foreach ($dsSmt->fetchAll() as $de) {
        $diasEspeciaisSemana[$de['Data']] = (bool)$de['BloqueiaTotal'];
    }
} catch (PDOException) {}

$diasNomeCurto = ['Seg','Ter','Qua','Qui','Sex','Sáb','Dom'];
$diasNomeCompl = ['Segunda','Terça','Quarta','Quinta','Sexta','Sábado','Domingo'];
$mesesAbrev    = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
$mesesNomes    = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
$dataExibida   = $diasNomeCompl[($diaSemana + 6) % 7] . ', ' . date('d', $dataTs) . ' de ' . $mesesNomes[(int)date('n', $dataTs)];

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
.slot-livre {
    border-color: var(--card-border-color);
    color: var(--text-main);
    cursor: pointer;
    background: var(--bg-card);
}
.slot-livre:hover { border-color: var(--accent); background: var(--accent-light); }
.slot-livre.slot-invalido {
    opacity: .38;
    cursor: not-allowed;
    border-color: var(--card-border-color);
}
.slot-livre.slot-invalido:hover { border-color: var(--card-border-color); background: var(--bg-card); }
.slot-livre.slot-sel {
    background: var(--accent);
    border-color: var(--accent);
    color: #fff;
    transform: scale(1.06);
}
.slot-ocupado {
    background: var(--bg-hover);
    color: var(--text-secondary);
    border-color: var(--card-border-color);
    opacity: .7;
    cursor: pointer;
}
.slot-ocupado:hover  { opacity: 1; border-color: var(--accent); }
.slot-bloqueado {
    background: rgba(192,96,74,.12);
    color: #C0604A;
    border-color: rgba(192,96,74,.3);
    cursor: pointer;
}
.slot-bloqueado:hover { border-color: rgba(192,96,74,.7); background: rgba(192,96,74,.2); }
.slot-almoco {
    background: rgba(212,150,58,.1);
    color: #D4963A;
    border-color: rgba(212,150,58,.3);
    cursor: pointer;
}
.slot-almoco:hover { border-color: rgba(212,150,58,.7); background: rgba(212,150,58,.18); }

/* ── Popover de info de slot ─────────────────── */
#slotPopover {
    position: fixed;
    z-index: 600;
    background: var(--bg-card);
    border: 1.5px solid var(--accent);
    border-radius: 10px;
    padding: .6rem .9rem;
    box-shadow: 0 6px 24px rgba(0,0,0,.22);
    font-size: .82rem;
    max-width: 240px;
    line-height: 1.45;
    pointer-events: none;
    opacity: 1;
    transition: opacity .1s;
}
#slotPopover .sp-hora { font-weight: 700; color: var(--accent); margin-bottom: .2rem; font-size: .88rem; }
#slotPopover .sp-desc { color: var(--text-main); }
#slotPopover .sp-sub  { color: var(--text-secondary); font-size: .78rem; margin-top: .1rem; }
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
#badgeHoraFim {
    font-size: .78rem;
    padding: .25rem .6rem;
    border-radius: 6px;
    background: var(--accent-light);
    color: var(--accent);
}

/* ── Calendário semanal ──────────────────────── */
.semana-nav { display:flex; align-items:center; gap:.5rem; }
.semana-dias { display:flex; gap:.35rem; flex:1; justify-content:space-between; }
.dia-card {
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    flex:1; min-width:0; max-width:60px; min-height:70px;
    border-radius:14px; border:2px solid var(--card-border-color);
    background:var(--bg-card); cursor:pointer;
    transition:border-color .15s,background .15s,color .15s,transform .12s;
    text-decoration:none; color:var(--text-main);
    padding:.45rem .2rem; position:relative; gap:.05rem;
    -webkit-tap-highlight-color:transparent;
}
a.dia-card:hover {
    border-color:var(--accent); background:var(--accent-light);
    color:var(--accent); transform:translateY(-2px);
}
.dia-card.dia-sel  { background:var(--accent); border-color:var(--accent); color:#fff; }
.dia-card.dia-fora { opacity:.38; }
.dia-card.dia-hoje:not(.dia-sel) { border-color:var(--accent); border-width:2px; }
.dia-nome { font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; line-height:1; }
.dia-num  { font-size:1.15rem; font-weight:800; line-height:1.2; }
.dia-mes  { font-size:.58rem; line-height:1; opacity:.65; }
.dia-dot  { width:5px; height:5px; border-radius:50%; background:var(--accent); margin-bottom:1px; }
.dia-card.dia-sel .dia-dot { background:rgba(255,255,255,.75); }
.btn-semana {
    width:36px; height:36px; padding:0; flex-shrink:0;
    display:flex; align-items:center; justify-content:center; border-radius:10px;
}

/* ── Custom client picker ────────────────────── */
.cliente-picker { position:relative; }
.cp-trigger {
    display:flex; align-items:center; justify-content:space-between;
    padding:.45rem .75rem;
    border:1px solid var(--card-border-color);
    border-radius:8px;
    background:var(--bg-card);
    cursor:pointer;
    transition:border-color .15s, box-shadow .15s;
    min-height:42px;
    user-select:none;
    gap:.5rem;
}
.cp-trigger:hover  { border-color:var(--accent); }
.cp-trigger.open   { border-color:var(--accent); border-radius:8px 8px 0 0; box-shadow:0 0 0 3px rgba(var(--accent-rgb),.1); }
.cp-placeholder    { color:var(--text-secondary); font-size:.9rem; flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.cp-selected       { color:var(--text-main); font-size:.9rem; font-weight:500; flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.cp-caret          { color:var(--text-secondary); transition:transform .2s; flex-shrink:0; }
.cp-trigger.open .cp-caret { transform:rotate(180deg); }
.cp-dropdown {
    position:absolute; top:100%; left:0; right:0;
    background:var(--bg-card);
    border:1px solid var(--accent);
    border-top:none;
    border-radius:0 0 10px 10px;
    z-index:300;
    box-shadow:0 8px 28px rgba(0,0,0,.18);
    max-height:260px;
    display:flex; flex-direction:column;
}
.cp-search-wrap {
    padding:.5rem .75rem;
    border-bottom:1px solid var(--card-border-color);
    display:flex; align-items:center; gap:.5rem;
    flex-shrink:0;
    background:var(--bg-hover);
    border-radius:0;
}
.cp-search-icon { color:var(--text-secondary); font-size:.82rem; flex-shrink:0; }
.cp-search {
    border:none; outline:none; background:transparent;
    color:var(--text-main); font-size:.875rem; width:100%; min-width:0;
}
.cp-list { overflow-y:auto; flex:1; }
.cp-item {
    padding:.55rem .9rem;
    cursor:pointer;
    font-size:.875rem;
    transition:background .1s;
    border-bottom:1px solid var(--card-border-color);
}
.cp-item:last-child { border-bottom:none; }
.cp-item:hover, .cp-item.cp-active { background:var(--accent-light); }
.cp-item-nome { font-weight:600; color:var(--text-main); line-height:1.3; }
.cp-item-tel  { font-size:.78rem; color:var(--text-secondary); margin-top:.1rem; }
.cp-item:hover .cp-item-nome, .cp-item.cp-active .cp-item-nome { color:var(--accent); }
.cp-empty { padding:.9rem; text-align:center; color:var(--text-secondary); font-size:.85rem; }
.cp-clear {
    flex-shrink:0; width:18px; height:18px; border-radius:50%;
    background:var(--text-secondary); color:var(--bg-card);
    border:none; cursor:pointer; font-size:.65rem;
    display:flex; align-items:center; justify-content:center;
    opacity:.6; transition:opacity .15s;
}
.cp-clear:hover { opacity:1; }
</style>

<!-- Cabeçalho -->
<div class="d-flex align-items-center gap-2 mb-4">
    <a href="<?= BASE ?>/painel/agenda.php" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1">
        <i class="bi bi-arrow-left"></i>Agenda
    </a>
    <h4 class="fw-bold mb-0">Novo agendamento</h4>
</div>

<div class="row g-4 align-items-start">

    <!-- ── Grade de horários ── -->
    <div class="col-lg-7">

        <!-- Calendário semanal -->
        <div class="card mb-3 p-3">

            <!-- Barra de atalhos -->
            <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                <a href="?data=<?= h($hoje) ?>" class="btn btn-sm btn-outline-accent">
                    <i class="bi bi-calendar-event me-1"></i>Hoje
                </a>
                <div class="ms-auto d-flex align-items-center gap-2">
                    <label for="saltarData" class="small text-secondary mb-0 flex-shrink-0">Ir para:</label>
                    <input type="date" id="saltarData"
                           class="form-control form-control-sm"
                           style="max-width:145px;"
                           value="<?= h($dataSel) ?>">
                </div>
            </div>

            <div class="semana-nav">
                <?php if ($podeIrAntes): ?>
                <a href="?data=<?= h($semanaIniPrev) ?>" class="btn btn-outline-secondary btn-semana" title="Semana anterior">
                    <i class="bi bi-chevron-left"></i>
                </a>
                <?php else: ?>
                <button class="btn btn-outline-secondary btn-semana" disabled title="Semana mais antiga disponível">
                    <i class="bi bi-chevron-left"></i>
                </button>
                <?php endif ?>

                <div class="semana-dias">
                <?php for ($i = 0; $i < 7; $i++):
                    $dTs    = strtotime($semanaIni) + $i * 86400;
                    $d      = date('Y-m-d', $dTs);
                    $ehHoje = $d === $hoje;
                    $ehSel  = $d === $dataSel;
                    $phpDow = ($i + 1) % 7;
                    $bloqTotal      = isset($diasEspeciaisSemana[$d]) && $diasEspeciaisSemana[$d];
                    $temExpBase     = isset($horariosPorDiaSem[$phpDow]);
                    $temExpEspecial = isset($diasEspeciaisSemana[$d]) && !$diasEspeciaisSemana[$d];
                    $semExp         = $bloqTotal || (!$temExpBase && !$temExpEspecial);

                    $classes = 'dia-card';
                    if ($ehSel)             $classes .= ' dia-sel';
                    if ($ehHoje)            $classes .= ' dia-hoje';
                    if ($semExp && !$ehSel) $classes .= ' dia-fora';

                    $tag  = !$ehSel ? 'a' : 'span';
                    $href = !$ehSel ? ' href="?data=' . h($d) . '"' : '';
                ?>
                    <<?= $tag ?><?= $href ?> class="<?= $classes ?>" title="<?= $diasNomeCompl[$i] . ', ' . date('d/m', $dTs) ?>">
                        <?php if ($ehHoje): ?><span class="dia-dot"></span><?php endif ?>
                        <span class="dia-nome"><?= $diasNomeCurto[$i] ?></span>
                        <span class="dia-num"><?= date('j', $dTs) ?></span>
                        <span class="dia-mes"><?= $mesesAbrev[(int)date('n', $dTs) - 1] ?></span>
                    </<?= $tag ?>>
                <?php endfor ?>
                </div>

                <?php if ($podeIrDepois): ?>
                <a href="?data=<?= h($semanaIniNext) ?>" class="btn btn-outline-secondary btn-semana" title="Próxima semana">
                    <i class="bi bi-chevron-right"></i>
                </a>
                <?php else: ?>
                <button class="btn btn-outline-secondary btn-semana" disabled>
                    <i class="bi bi-chevron-right"></i>
                </button>
                <?php endif ?>
            </div>
        </div>

        <!-- Grade de slots -->
        <div class="card">
            <div class="card-header px-4 py-3 d-flex align-items-center gap-2">
                <i class="bi bi-clock text-accent"></i>
                <span class="fw-medium flex-grow-1"><?= h($dataExibida) ?></span>
                <button type="button" id="btnLegenda"
                        class="btn btn-sm btn-outline-secondary"
                        onclick="toggleLegenda()" title="Legenda dos horários">
                    <i class="bi bi-info-circle me-1"></i>Legenda
                </button>
            </div>

            <!-- Legenda (oculta por padrão) -->
            <div id="legendaBox" class="px-4 pt-3 pb-1 d-none border-bottom" style="border-color:var(--card-border-color)!important;">
                <div class="d-flex flex-wrap gap-2 mb-3" style="font-size:.76rem;">
                    <span class="slot slot-livre" style="pointer-events:none;">Livre</span>
                    <span class="slot slot-ocupado" style="pointer-events:none;">Ocupado</span>
                    <span class="slot slot-bloqueado" style="pointer-events:none;">Bloqueado</span>
                    <span class="slot slot-almoco" style="pointer-events:none;">Almoço</span>
                    <span class="slot slot-livre slot-sel" style="pointer-events:none;transform:none;">Selecionado</span>
                    <span class="slot slot-livre slot-invalido" style="pointer-events:none;">Serviço não cabe</span>
                </div>
            </div>

            <div class="card-body p-4">
                <?php if (!$horario && !$forca): ?>
                    <div class="text-center py-5 text-secondary">
                        <i class="bi bi-calendar-x fs-1 d-block mb-2 opacity-25"></i>
                        <p class="mb-2">Sem expediente neste dia.</p>
                        <a href="?data=<?= h($dataSel) ?>&forca=1" class="btn btn-sm btn-warning mt-1">
                            <i class="bi bi-lightning-charge me-1"></i>Inserção forçada
                        </a>
                    </div>
                <?php elseif (empty($slots)): ?>
                    <div class="text-center py-5 text-secondary">
                        <i class="bi bi-clock fs-1 d-block mb-2 opacity-25"></i>
                        <p class="mb-0">Nenhum slot gerado para este dia.</p>
                    </div>
                <?php else: ?>
                    <?php if ($forca): ?>
                    <div class="alert alert-warning py-2 px-3 mb-3 d-flex align-items-center gap-2" role="alert" style="font-size:.85rem;">
                        <i class="bi bi-lightning-charge-fill flex-shrink-0"></i>
                        <div><strong>Inserção forçada</strong> — dia fora do expediente normal. Escolha um horário abaixo ou digite o horário exato no campo ao final.</div>
                    </div>
                    <?php endif ?>

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
                                <span class="slot slot-ocupado" tabindex="0"
                                      data-hora="<?= h($s['hora']) ?>" data-info="<?= h($s['info']) ?>"
                                      onclick="verInfoSlot(event,this)"
                                      onkeydown="if(event.key==='Enter')verInfoSlot(event,this)">
                                    <?= h($s['hora']) ?>
                                </span>
                            <?php elseif ($s['status'] === 'bloqueado'): ?>
                                <span class="slot slot-bloqueado" tabindex="0"
                                      data-hora="<?= h($s['hora']) ?>" data-info="<?= h($s['info']) ?>"
                                      onclick="verInfoSlot(event,this)"
                                      onkeydown="if(event.key==='Enter')verInfoSlot(event,this)">
                                    <?= h($s['hora']) ?>
                                </span>
                            <?php else: ?>
                                <span class="slot slot-almoco" tabindex="0"
                                      data-hora="<?= h($s['hora']) ?>" data-info="<?= h($s['info']) ?>"
                                      onclick="verInfoSlot(event,this)"
                                      onkeydown="if(event.key==='Enter')verInfoSlot(event,this)">
                                    <?= h($s['hora']) ?>
                                </span>
                            <?php endif ?>
                        <?php endforeach ?>
                    </div>
                    <div id="slotPopover" style="display:none;"></div>

                    <?php if ($forca): ?>
                    <div class="mt-3 p-3 rounded-3" style="background:var(--bg-hover);border:1.5px dashed var(--card-border-color);">
                        <div class="small fw-medium mb-2 text-secondary"><i class="bi bi-clock me-1"></i>Horário específico (minuto exato)</div>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <input type="time" id="inpHoraForca" class="form-control form-control-sm"
                                   style="max-width:130px;" step="300"
                                   oninput="aplicarHoraForca(this.value)">
                            <span class="small text-secondary">Digite se o horário não estiver nos botões acima</span>
                        </div>
                    </div>
                    <?php endif ?>
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
                    <?php if ($forca): ?><input type="hidden" name="forca" value="1"><?php endif ?>

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
                        <input type="hidden" name="fk_servico" id="inpServicoId">
                        <div class="cliente-picker" id="svPicker">
                            <div class="cp-trigger" id="svTrigger" tabindex="0"
                                 onclick="svpToggle()" onkeydown="if(event.key==='Enter'||event.key===' ')svpToggle()">
                                <span id="svLabel" class="cp-placeholder">Selecione o serviço…</span>
                                <span class="cp-caret"><i class="bi bi-chevron-down"></i></span>
                            </div>
                            <div class="cp-dropdown d-none" id="svDropdown">
                                <div class="cp-search-wrap">
                                    <i class="bi bi-search cp-search-icon"></i>
                                    <input type="text" class="cp-search" id="svSearch"
                                           placeholder="Buscar serviço…" autocomplete="off"
                                           oninput="svpFiltrar(this.value)">
                                </div>
                                <div class="cp-list" id="svList"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Sub-serviço -->
                    <div class="mb-3 d-none" id="wrapSub">
                        <label class="form-label fw-medium">Manutenção / variante</label>
                        <input type="hidden" name="fk_sub" id="inpSubId">
                        <div class="cliente-picker" id="ssPicker">
                            <div class="cp-trigger" id="ssTrigger" tabindex="0"
                                 onclick="sspToggle()" onkeydown="if(event.key==='Enter'||event.key===' ')sspToggle()">
                                <span id="ssLabel" class="cp-placeholder">— Sem manutenção —</span>
                                <span class="cp-caret"><i class="bi bi-chevron-down"></i></span>
                            </div>
                            <div class="cp-dropdown d-none" id="ssDropdown">
                                <div class="cp-search-wrap">
                                    <i class="bi bi-search cp-search-icon"></i>
                                    <input type="text" class="cp-search" id="ssSearch"
                                           placeholder="Buscar manutenção…" autocomplete="off"
                                           oninput="sspFiltrar(this.value)">
                                </div>
                                <div class="cp-list" id="ssList"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Duração -->
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

                    <!-- Picker de cliente cadastrada -->
                    <div id="secCadastrada" class="mb-3">
                        <input type="hidden" name="fk_cliente" id="inpClienteId">
                        <div class="cliente-picker" id="clientePicker">
                            <div class="cp-trigger" id="cpTrigger" tabindex="0"
                                 onclick="cpToggle()" onkeydown="if(event.key==='Enter'||event.key===' ')cpToggle()">
                                <span id="cpLabel" class="cp-placeholder">Buscar cliente…</span>
                                <span class="cp-caret"><i class="bi bi-chevron-down"></i></span>
                            </div>
                            <div class="cp-dropdown d-none" id="cpDropdown">
                                <div class="cp-search-wrap">
                                    <i class="bi bi-search cp-search-icon"></i>
                                    <input type="text" class="cp-search" id="cpSearch"
                                           placeholder="Nome ou telefone…"
                                           autocomplete="off"
                                           oninput="cpFiltrar(this.value)">
                                </div>
                                <div class="cp-list" id="cpList"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Dados de cliente avulsa -->
                    <div id="secAvulsa" class="mb-3 d-none">
                        <input type="text" name="nome_avulso" class="form-control mb-2"
                               placeholder="Nome da cliente *"
                               oninput="validarForm()">
                        <input type="tel" name="tel_avulso" id="inp_tel_avulso" class="form-control"
                               placeholder="(17) 99999-9999" maxlength="15"
                               oninput="mascaraTel(this)" autocomplete="tel">
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
var CP_CLIENTES    = <?= $clientesJson ?>;
var FIM_JORNADA_TS = <?= $fimJornadaTs ?>;
var MODO_FORCA     = <?= $forca ? 'true' : 'false' ?>;
var DATA_SEL       = '<?= $dataSel ?>';

var slotSelecionadoTs = null;
var duracaoAtual      = 0;
var tipoCliente       = 'cadastrada';

/* ── "Ir para" date jump ──────────────────────── */
document.getElementById('saltarData').addEventListener('change', function () {
    if (this.value) location.href = '?data=' + this.value;
});

/* ── Legenda toggle ───────────────────────────── */
function toggleLegenda() {
    var box = document.getElementById('legendaBox');
    var btn = document.getElementById('btnLegenda');
    var hidden = box.classList.toggle('d-none');
    btn.classList.toggle('btn-outline-secondary', hidden);
    btn.classList.toggle('btn-accent', !hidden);
}

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
}

function atualizarHoraFim() {
    if (!slotSelecionadoTs || !duracaoAtual) return;
    var fimTs   = slotSelecionadoTs + duracaoAtual * 60;
    var fimHora = new Date(fimTs * 1000).toLocaleTimeString('pt-BR', {hour:'2-digit',minute:'2-digit'});
    document.getElementById('lblHoraFim').textContent = fimHora;
    document.getElementById('badgeHoraFim').classList.remove('d-none');
}

/* ── Slots inválidos ──────────────────────────── */
function atualizarValidezSlots() {
    document.querySelectorAll('.slot-livre').forEach(function (btn) {
        var ts  = parseInt(btn.dataset.ts);
        var fim = ts + duracaoAtual * 60;
        var invalido = false;

        if (!MODO_FORCA && duracaoAtual > 0 && fim > FIM_JORNADA_TS) invalido = true;
        if (!invalido) invalido = AGENDAMENTOS.some(function (ag) { return ts < ag.fim && fim > ag.ini; });
        if (!MODO_FORCA) {
            if (!invalido) invalido = BLOQUEIOS.some(function (b) { return ts < b.fim && fim > b.ini; });
            if (!invalido && ALMOCO) invalido = ALMOCO.some(function(iv) { return ts < iv.fim && fim > iv.ini; });
        }
        btn.classList.toggle('slot-invalido', invalido && !btn.classList.contains('slot-sel'));
    });
}

/* ── Serviço — picker (svp) ──────────────────── */
var svAberto      = false;
var svSelecionado = null;

function svpToggle() { svAberto ? svpFechar() : svpAbrir(); }

function svpAbrir() {
    svAberto = true;
    document.getElementById('svTrigger').classList.add('open');
    document.getElementById('svDropdown').classList.remove('d-none');
    document.getElementById('svSearch').value = '';
    svpRenderizar(SERVICOS);
    setTimeout(function() { document.getElementById('svSearch').focus(); }, 40);
    document.addEventListener('click', svpClickFora, true);
}

function svpFechar() {
    svAberto = false;
    document.getElementById('svTrigger').classList.remove('open');
    document.getElementById('svDropdown').classList.add('d-none');
    document.removeEventListener('click', svpClickFora, true);
}

function svpClickFora(e) {
    if (!document.getElementById('svPicker').contains(e.target)) svpFechar();
}

function svpFiltrar(q) {
    q = q.toLowerCase();
    svpRenderizar(SERVICOS.filter(function(sv) { return sv.nome.toLowerCase().indexOf(q) !== -1; }));
}

function svpRenderizar(lista) {
    var el = document.getElementById('svList');
    if (!lista.length) { el.innerHTML = '<div class="cp-empty">Nenhum serviço encontrado.</div>'; return; }
    el.innerHTML = '';
    lista.forEach(function(sv) {
        var div = document.createElement('div');
        div.className = 'cp-item' + (svSelecionado && svSelecionado.id === sv.id ? ' cp-active' : '');
        div.innerHTML = '<div class="cp-item-nome">' + escHtml(sv.nome) + '</div>'
            + '<div class="cp-item-tel">' + sv.duracao + ' min · R$ ' + sv.preco.toFixed(2).replace('.', ',') + '</div>';
        div.addEventListener('mousedown', function(e) { e.preventDefault(); svpSelecionar(sv); });
        el.appendChild(div);
    });
}

function svpSelecionar(sv) {
    svSelecionado = sv;
    document.getElementById('inpServicoId').value = sv.id;
    var lbl = document.getElementById('svLabel');
    lbl.textContent = sv.nome;
    lbl.className = 'cp-selected';
    svpFechar();

    // Reinicia sub-serviço
    ssSelecionado = null;
    ssListaAtual  = sv.subs || [];
    document.getElementById('inpSubId').value = '';
    var ssLbl = document.getElementById('ssLabel');
    ssLbl.textContent = '— Sem manutenção —';
    ssLbl.className = 'cp-placeholder';

    if (ssListaAtual.length > 0) {
        document.getElementById('wrapSub').classList.remove('d-none');
    } else {
        document.getElementById('wrapSub').classList.add('d-none');
    }
    aplicarDuracaoPreco(sv.duracao, sv.preco);
}

/* ── Sub-serviço — picker (ssp) ──────────────── */
var ssAberto      = false;
var ssSelecionado = null;
var ssListaAtual  = [];

function sspToggle() { ssAberto ? sspFechar() : sspAbrir(); }

function sspAbrir() {
    ssAberto = true;
    document.getElementById('ssTrigger').classList.add('open');
    document.getElementById('ssDropdown').classList.remove('d-none');
    document.getElementById('ssSearch').value = '';
    sspRenderizar(ssListaAtual);
    setTimeout(function() { document.getElementById('ssSearch').focus(); }, 40);
    document.addEventListener('click', sspClickFora, true);
}

function sspFechar() {
    ssAberto = false;
    document.getElementById('ssTrigger').classList.remove('open');
    document.getElementById('ssDropdown').classList.add('d-none');
    document.removeEventListener('click', sspClickFora, true);
}

function sspClickFora(e) {
    if (!document.getElementById('ssPicker').contains(e.target)) sspFechar();
}

function sspFiltrar(q) {
    q = q.toLowerCase();
    sspRenderizar(ssListaAtual.filter(function(ss) { return ss.nome.toLowerCase().indexOf(q) !== -1; }));
}

function sspRenderizar(lista) {
    var el = document.getElementById('ssList');
    el.innerHTML = '';

    var semDiv = document.createElement('div');
    semDiv.className = 'cp-item' + (!ssSelecionado ? ' cp-active' : '');
    semDiv.innerHTML = '<div class="cp-item-nome" style="font-weight:400;color:var(--text-secondary);">— Sem manutenção —</div>';
    semDiv.addEventListener('mousedown', function(e) { e.preventDefault(); sspLimpar(); });
    el.appendChild(semDiv);

    lista.forEach(function(ss) {
        var div = document.createElement('div');
        div.className = 'cp-item' + (ssSelecionado && ssSelecionado.id === ss.id ? ' cp-active' : '');
        div.innerHTML = '<div class="cp-item-nome">' + escHtml(ss.nome) + '</div>'
            + '<div class="cp-item-tel">' + ss.duracao + ' min · R$ ' + ss.preco.toFixed(2).replace('.', ',') + '</div>';
        div.addEventListener('mousedown', function(e) { e.preventDefault(); sspSelecionar(ss); });
        el.appendChild(div);
    });
}

function sspSelecionar(ss) {
    ssSelecionado = ss;
    document.getElementById('inpSubId').value = ss.id;
    var lbl = document.getElementById('ssLabel');
    lbl.textContent = ss.nome;
    lbl.className = 'cp-selected';
    sspFechar();
    aplicarDuracaoPreco(ss.duracao, ss.preco);
}

function sspLimpar() {
    ssSelecionado = null;
    document.getElementById('inpSubId').value = '';
    var lbl = document.getElementById('ssLabel');
    lbl.textContent = '— Sem manutenção —';
    lbl.className = 'cp-placeholder';
    sspFechar();
    if (svSelecionado) aplicarDuracaoPreco(svSelecionado.duracao, svSelecionado.preco);
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
    if (preco > 0) document.getElementById('inp_valor').value = preco.toFixed(2);
    atualizarHoraFim();
    atualizarValidezSlots();

    if (slotSelecionadoTs && duracaoAtual > 0) {
        var fimSel   = slotSelecionadoTs + duracaoAtual * 60;
        var invalSel = false;
        if (!MODO_FORCA && fimSel > FIM_JORNADA_TS) invalSel = true;
        if (!invalSel) invalSel = AGENDAMENTOS.some(function (ag) { return slotSelecionadoTs < ag.fim && fimSel > ag.ini; });
        if (!MODO_FORCA && !invalSel) invalSel = BLOQUEIOS.some(function (b) { return slotSelecionadoTs < b.fim && fimSel > b.ini; });
        if (!MODO_FORCA && !invalSel && ALMOCO) invalSel = ALMOCO.some(function(iv) { return slotSelecionadoTs < iv.fim && fimSel > iv.ini; });
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

/* ── Cliente cadastrada (custom picker) ───────── */
var cpAberto      = false;
var cpSelecionado = null;

function cpToggle() { cpAberto ? cpFechar() : cpAbrir(); }

function cpAbrir() {
    cpAberto = true;
    document.getElementById('cpTrigger').classList.add('open');
    document.getElementById('cpDropdown').classList.remove('d-none');
    document.getElementById('cpSearch').value = '';
    cpRenderizar(CP_CLIENTES);
    setTimeout(function() { document.getElementById('cpSearch').focus(); }, 40);
    document.addEventListener('click', cpClickFora, true);
}

function cpFechar() {
    cpAberto = false;
    document.getElementById('cpTrigger').classList.remove('open');
    document.getElementById('cpDropdown').classList.add('d-none');
    document.removeEventListener('click', cpClickFora, true);
}

function cpClickFora(e) {
    var picker = document.getElementById('clientePicker');
    if (picker && !picker.contains(e.target)) cpFechar();
}

function cpFiltrar(q) {
    q = q.toLowerCase();
    cpRenderizar(CP_CLIENTES.filter(function(c) {
        return c.nome.toLowerCase().indexOf(q) !== -1 || c.tel.indexOf(q) !== -1;
    }));
}

function cpRenderizar(lista) {
    var el = document.getElementById('cpList');
    if (!lista.length) {
        el.innerHTML = '<div class="cp-empty">Nenhuma cliente encontrada.</div>';
        return;
    }
    el.innerHTML = '';
    lista.forEach(function(c) {
        var div = document.createElement('div');
        div.className = 'cp-item' + (cpSelecionado && cpSelecionado.id === c.id ? ' cp-active' : '');
        var tel = c.tel ? '<div class="cp-item-tel">' + escHtml(c.tel) + '</div>' : '';
        div.innerHTML = '<div class="cp-item-nome">' + escHtml(c.nome) + '</div>' + tel;
        div.addEventListener('mousedown', function(e) { e.preventDefault(); cpSelecionar(c); });
        el.appendChild(div);
    });
}

function cpSelecionar(c) {
    cpSelecionado = c;
    document.getElementById('inpClienteId').value = c.id;
    var lbl = document.getElementById('cpLabel');
    lbl.textContent = c.nome + (c.tel ? ' — ' + c.tel : '');
    lbl.className = 'cp-selected';
    cpFechar();
    validarForm();
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── Alternar cadastrada / avulsa ─────────────── */
function toggleCliente(tipo) {
    tipoCliente = tipo;
    document.getElementById('secCadastrada').classList.toggle('d-none', tipo !== 'cadastrada');
    document.getElementById('secAvulsa').classList.toggle('d-none',    tipo !== 'avulsa');
    validarForm();
}

/* ── Validação geral ─────────────────────────── */
function validarForm() {
    var ok = true;
    var erro = '';

    if (!slotSelecionadoTs) { ok = false; erro = 'Selecione um horário na grade.'; }
    if (!document.getElementById('inpServicoId').value) { ok = false; erro = erro || 'Selecione um serviço.'; }

    if (tipoCliente === 'cadastrada' && !document.getElementById('inpClienteId').value) {
        ok = false; erro = erro || 'Selecione uma cliente.';
    }
    if (tipoCliente === 'avulsa') {
        var nome = document.querySelector('[name="nome_avulso"]').value.trim();
        if (!nome) { ok = false; erro = erro || 'Informe o nome da cliente avulsa.'; }
    }

    document.getElementById('btnSalvar').disabled = !ok;
    var divErro = document.getElementById('erroForm');
    if (!ok && erro) { divErro.textContent = erro; divErro.style.display = ''; }
    else { divErro.style.display = 'none'; }
}

/* ── Máscara de telefone ─────────────────────── */
function mascaraTel(input) {
    var d = input.value.replace(/\D/g, '').substring(0, 11);
    if (d.length === 0) { input.value = ''; return; }
    if (d.length <= 2)  { input.value = '(' + d; return; }
    if (d.length <= 6)  { input.value = '(' + d.substring(0,2) + ') ' + d.substring(2); return; }
    if (d.length <= 10) { input.value = '(' + d.substring(0,2) + ') ' + d.substring(2,6) + '-' + d.substring(6); }
    else                { input.value = '(' + d.substring(0,2) + ') ' + d.substring(2,7) + '-' + d.substring(7); }
}

/* ── Horário forçado (modo força) ─────────────── */
function aplicarHoraForca(value) {
    if (!value) return;
    var parts = value.split(':');
    var h = parseInt(parts[0]), m = parseInt(parts[1] || 0);
    var dp = DATA_SEL.split('-');
    var d = new Date(parseInt(dp[0]), parseInt(dp[1]) - 1, parseInt(dp[2]), h, m, 0);
    var ts = Math.floor(d.getTime() / 1000);
    document.querySelectorAll('.slot-livre.slot-sel').forEach(function(b) { b.classList.remove('slot-sel'); });
    slotSelecionadoTs = ts;
    document.getElementById('inp_hora').value = value;
    document.getElementById('lblHoraSel').textContent = value;
    atualizarHoraFim();
    validarForm();
}

/* ── Popover de info de slot ─────────────────── */
function verInfoSlot(e, el) {
    e.stopPropagation();
    var hora = el.dataset.hora || el.textContent.trim();
    var info = el.dataset.info || '';
    var tipo = el.classList.contains('slot-ocupado')   ? 'ocupado'
             : el.classList.contains('slot-bloqueado') ? 'bloqueado' : 'intervalo';

    var icone = tipo === 'ocupado'   ? '<i class="bi bi-person-fill me-1"></i>'
              : tipo === 'bloqueado' ? '<i class="bi bi-lock-fill me-1"></i>'
              :                       '<i class="bi bi-pause-circle-fill me-1"></i>';

    var descHtml;
    if (tipo === 'ocupado' && info.indexOf(' — ') !== -1) {
        var partes = info.split(' — ');
        descHtml = '<div class="sp-desc" style="font-weight:600;">' + escHtml(partes[0]) + '</div>'
                 + '<div class="sp-sub">' + escHtml(partes.slice(1).join(' — ')) + '</div>';
    } else {
        descHtml = '<div class="sp-desc">' + escHtml(info) + '</div>';
    }

    var pop = document.getElementById('slotPopover');
    pop.innerHTML = '<div class="sp-hora">' + icone + hora + '</div>' + descHtml;
    pop.style.display = '';

    var pw   = 220;
    var rect = el.getBoundingClientRect();
    var left = Math.max(8, Math.min(rect.left + rect.width / 2 - pw / 2, window.innerWidth - pw - 8));
    pop.style.width = pw + 'px';
    pop.style.left  = left + 'px';
    pop.style.top   = '0px';

    requestAnimationFrame(function () {
        var ph  = pop.offsetHeight;
        var top = rect.top - ph - 8;
        if (top < 8) top = rect.bottom + 8;
        pop.style.top = top + 'px';
    });
}

document.addEventListener('click', function () {
    var pop = document.getElementById('slotPopover');
    if (pop) pop.style.display = 'none';
});

/* Observa campos de texto avulso */
document.querySelector('[name="nome_avulso"]').addEventListener('input', validarForm);

/* Inicializa */
validarForm();
</script>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
