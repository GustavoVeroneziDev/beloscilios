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

// Data selecionada
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

// Dia especial sobrepõe o horário padrão
$diaEspecial = null;
try {
    $deStmt = $pdo->prepare(
        'SELECT td.IDTipo, td.Nome, td.Cor, td.BloqueiaTotal, td.HoraInicio, td.HoraFim
         FROM DiasEspeciais de
         JOIN TiposDia td ON td.IDTipo = de.FKTipo
         WHERE de.Data = :data LIMIT 1'
    );
    $deStmt->execute([':data' => $dataSel]);
    $diaEspecial = $deStmt->fetch() ?: null;
} catch (PDOException) {}

// Horário de atendimento neste dia da semana
$diaSemana = (int) date('w', $dataTs); // 0=dom
try {
    $horStmt = $pdo->prepare(
        'SELECT HoraInicio, HoraFim, AlmocoInicio, AlmocoFim FROM HorariosAtendimento
         WHERE DiaSemana = :d AND Ativo = 1 LIMIT 1'
    );
    $horStmt->execute([':d' => $diaSemana]);
    $horario = $horStmt->fetch();

    $agStmt = $pdo->prepare(
        'SELECT DataHoraAgendamento, DataHoraFim FROM Agendamentos
         WHERE DataHoraAgendamento >= :data AND DataHoraAgendamento < :data_next
           AND StatusAgendamento NOT IN (\'cancelado\')'
    );
    $agStmt->execute([':data' => $dataSel, ':data_next' => date('Y-m-d', strtotime($dataSel . ' +1 day'))]);
    $agendados = $agStmt->fetchAll();

    $minhaSessao = session_id();
    if (random_int(1, 20) === 1) {
        try { $pdo->exec("DELETE FROM ReservasTemporarias WHERE ExpiraEm < NOW()"); } catch (PDOException) {}
    }
    $dataSelNext = date('Y-m-d', strtotime($dataSel . ' +1 day'));
    $tempStmt = $pdo->prepare(
        'SELECT DataHoraSlot, DataHoraFim FROM ReservasTemporarias
         WHERE DataHoraSlot >= :data AND DataHoraSlot < :data_next
           AND TokenSessao != :sessao AND ExpiraEm > NOW()'
    );
    $tempStmt->execute([':data' => $dataSel, ':data_next' => $dataSelNext, ':sessao' => $minhaSessao]);
    $reservasTemp = $tempStmt->fetchAll();

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

// Aplica tipo especial do dia ao horário
$intervalosExtras = [];
if ($diaEspecial) {
    if ($diaEspecial['BloqueiaTotal']) {
        $horario = null;
    } elseif ($horario) {
        if (!$diaEspecial['HoraInicio'] || !$diaEspecial['HoraFim']) {
            $horario = null;
        } else {
            $horario['HoraInicio']   = $diaEspecial['HoraInicio'];
            $horario['HoraFim']      = $diaEspecial['HoraFim'];
            $horario['AlmocoInicio'] = null;
            $horario['AlmocoFim']    = null;
            try {
                $stIv = $pdo->prepare('SELECT Inicio, Fim FROM IntervalosTipo WHERE FKTipo = :id ORDER BY Inicio');
                $stIv->execute([':id' => $diaEspecial['IDTipo']]);
                $intervalosExtras = $stIv->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException) {}
        }
    }
}

// Gerar slots disponíveis para o dia selecionado
$slots = [];
if ($horario) {
    $passo    = $intervalo;
    $inicioTs = strtotime("{$dataSel} {$horario['HoraInicio']}");
    $fimTs    = strtotime("{$dataSel} {$horario['HoraFim']}");
    $agora    = time() + ($antecMinH * 3600);

    for ($ts = $inicioTs; ($ts + $duracao * 60) <= $fimTs; $ts += $passo * 60) {
        if ($ts < $agora) continue;
        $slotFim = $ts + $duracao * 60;
        $livre   = true;

        foreach ($agendados as $ag) {
            $agIni = strtotime($ag['DataHoraAgendamento']);
            $agFim = strtotime($ag['DataHoraFim']);
            if ($ts < $agFim && $slotFim > $agIni) { $livre = false; break; }
        }
        if ($livre) {
            foreach ($reservasTemp as $rt) {
                $rtIni = strtotime($rt['DataHoraSlot']);
                $rtFim = strtotime($rt['DataHoraFim']);
                if ($ts < $rtFim && $slotFim > $rtIni) { $livre = false; break; }
            }
        }
        if ($livre) {
            foreach ($bloqueios as $b) {
                $bIni = strtotime($b['DataInicio']);
                $bFim = strtotime($b['DataFim']);
                if ($ts < $bFim && $slotFim > $bIni) { $livre = false; break; }
            }
        }
        if ($livre) {
            $ivsCheck = $intervalosExtras ?: (
                (!empty($horario['AlmocoInicio']) && !empty($horario['AlmocoFim']))
                ? [['Inicio' => $horario['AlmocoInicio'], 'Fim' => $horario['AlmocoFim']]]
                : []
            );
            foreach ($ivsCheck as $iv) {
                $ivIni = strtotime("{$dataSel} {$iv['Inicio']}");
                $ivFim = strtotime("{$dataSel} {$iv['Fim']}");
                if ($ts < $ivFim && $slotFim > $ivIni) { $livre = false; break; }
            }
        }
        if ($livre) $slots[] = date('H:i', $ts);
    }
}

// ── Calendário semanal ────────────────────────────────────────────────────────
$semanaIni     = date('Y-m-d', $dataTs - $diaSemana * 86400);
$semanaFim     = date('Y-m-d', strtotime($semanaIni) + 6 * 86400);
$semanaIniPrev = date('Y-m-d', strtotime($semanaIni) - 7 * 86400);
$semanaIniNext = date('Y-m-d', strtotime($semanaIni) + 7 * 86400);

$diaHoje      = (int) date('w');
$semanaMinIni = date('Y-m-d', strtotime('today') - $diaHoje * 86400);
$podeIrAntes  = $semanaIniPrev >= $semanaMinIni;
$podeIrDepois = $semanaIniNext <= $dataMax;

// Horários regulares por dia da semana (inclui almoço para o check de slot)
$horariosPorDiaSem = [];
try {
    foreach ($pdo->query('SELECT DiaSemana, HoraInicio, HoraFim, AlmocoInicio, AlmocoFim FROM HorariosAtendimento WHERE Ativo = 1')->fetchAll() as $h) {
        $horariosPorDiaSem[$h['DiaSemana']] = $h;
    }
} catch (PDOException) {}

$agoraCal = time() + ($antecMinH * 3600);

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

// Agendamentos, reservas e bloqueios da semana (batch único para verificação de slots)
$agsPorData  = [];
$rtsPorData  = [];
$bloqsSemana = [];
$semFimNext  = date('Y-m-d', strtotime($semanaFim . ' +1 day'));
try {
    $agsSmt = $pdo->prepare(
        'SELECT DataHoraAgendamento, DataHoraFim FROM Agendamentos
         WHERE DataHoraAgendamento >= :ini AND DataHoraAgendamento < :fim
           AND StatusAgendamento NOT IN (\'cancelado\')'
    );
    $agsSmt->execute([':ini' => $semanaIni, ':fim' => $semFimNext]);
    foreach ($agsSmt->fetchAll() as $ag) {
        $agsPorData[date('Y-m-d', strtotime($ag['DataHoraAgendamento']))][] = $ag;
    }

    $rtsSmt = $pdo->prepare(
        'SELECT DataHoraSlot, DataHoraFim FROM ReservasTemporarias
         WHERE DataHoraSlot >= :ini AND DataHoraSlot < :fim
           AND TokenSessao != :sessao AND ExpiraEm > NOW()'
    );
    $rtsSmt->execute([':ini' => $semanaIni, ':fim' => $semFimNext, ':sessao' => $minhaSessao]);
    foreach ($rtsSmt->fetchAll() as $rt) {
        $rtsPorData[date('Y-m-d', strtotime($rt['DataHoraSlot']))][] = $rt;
    }

    $bSmt = $pdo->prepare(
        'SELECT DataInicio, DataFim FROM BloqueiosAgenda
         WHERE DataInicio < :fim AND DataFim > :ini'
    );
    $bSmt->execute([':ini' => $semanaIni, ':fim' => $semFimNext]);
    $bloqsSemana = $bSmt->fetchAll();
} catch (PDOException) {}

// Pré-computa se cada dia da semana tem ao menos 1 slot livre
$diasTemSlot = [];
for ($di = 0; $di < 7; $di++) {
    $dTs2 = strtotime($semanaIni) + $di * 86400;
    $d2   = date('Y-m-d', $dTs2);

    // Eliminação rápida por faixas / expediente / bloqueio total
    if ($d2 < $dataMin || $d2 > $dataMax
        || (isset($diasEspeciaisSemana[$d2]) && $diasEspeciaisSemana[$d2])
        || (!isset($diasEspeciaisSemana[$d2]) && !isset($horariosPorDiaSem[$di]))) {
        $diasTemSlot[$d2] = false;
        continue;
    }

    $hd = $horariosPorDiaSem[$di] ?? null;
    if (!$hd) { $diasTemSlot[$d2] = false; continue; }

    // Antecedência: último slot possível do dia já passou?
    if ((strtotime("{$d2} {$hd['HoraFim']}") - $duracao * 60) < $agoraCal) {
        $diasTemSlot[$d2] = false;
        continue;
    }

    // Verifica slot a slot se há ao menos 1 livre
    $ini2   = strtotime("{$d2} {$hd['HoraInicio']}");
    $fim2   = strtotime("{$d2} {$hd['HoraFim']}");
    $ivs2   = (!empty($hd['AlmocoInicio']) && !empty($hd['AlmocoFim']))
              ? [['Inicio' => $hd['AlmocoInicio'], 'Fim' => $hd['AlmocoFim']]]
              : [];
    $agsDia = $agsPorData[$d2] ?? [];
    $rtsDia = $rtsPorData[$d2] ?? [];

    $temSlot = false;
    for ($ts2 = $ini2; ($ts2 + $duracao * 60) <= $fim2; $ts2 += $intervalo * 60) {
        if ($ts2 < $agoraCal) continue;
        $sf2   = $ts2 + $duracao * 60;
        $livre = true;

        foreach ($agsDia as $ag) {
            if ($ts2 < strtotime($ag['DataHoraFim']) && $sf2 > strtotime($ag['DataHoraAgendamento'])) { $livre = false; break; }
        }
        if ($livre) foreach ($rtsDia as $rt) {
            if ($ts2 < strtotime($rt['DataHoraFim']) && $sf2 > strtotime($rt['DataHoraSlot'])) { $livre = false; break; }
        }
        if ($livre) foreach ($bloqsSemana as $b) {
            if ($ts2 < strtotime($b['DataFim']) && $sf2 > strtotime($b['DataInicio'])) { $livre = false; break; }
        }
        if ($livre) foreach ($ivs2 as $iv) {
            $ivIni = strtotime("{$d2} {$iv['Inicio']}");
            $ivFim = strtotime("{$d2} {$iv['Fim']}");
            if ($ts2 < $ivFim && $sf2 > $ivIni) { $livre = false; break; }
        }
        if ($livre) { $temSlot = true; break; }
    }
    $diasTemSlot[$d2] = $temSlot;
}

// URL base e i18n
$urlBase = BASE . '/agendamento/horarios.php?' . http_build_query([
    'servico_id' => $servicoId,
    'sub_id'     => $subId,
    'nome'       => $nome,
    'preco'      => $preco,
    'duracao'    => $duracao,
]);

$diasNomeCurto = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
$diasNomeCompl = ['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'];
$mesesAbrev    = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
$diaExibido    = $diasNomeCompl[$diaSemana] . ', ' . date('d/m/Y', $dataTs);

$paginaTitulo = 'Agendar — Escolha o horário';
$areaAtual    = 'cliente';
require_once __DIR__ . '/../geral/header.php';
?>
<style>
/* ── Calendário semanal ───────────────────────────── */
.semana-nav {
    display: flex;
    align-items: center;
    gap: .5rem;
}
.semana-dias {
    display: flex;
    gap: .35rem;
    flex: 1;
    justify-content: space-between;
}
.dia-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex: 1;
    min-width: 0;
    max-width: 60px;
    min-height: 70px;
    border-radius: 14px;
    border: 2px solid var(--card-border-color);
    background: var(--bg-card);
    cursor: pointer;
    transition: border-color .15s, background .15s, color .15s, transform .12s;
    text-decoration: none;
    color: var(--text-main);
    padding: .45rem .2rem;
    position: relative;
    gap: .05rem;
    -webkit-tap-highlight-color: transparent;
}
a.dia-card:hover {
    border-color: var(--accent);
    background: var(--accent-light);
    color: var(--accent);
    transform: translateY(-2px);
}
.dia-card.dia-sel {
    background: var(--accent);
    border-color: var(--accent);
    color: #fff;
}
.dia-card.dia-off {
    opacity: .22;
    pointer-events: none;
    cursor: default;
}
.dia-card.dia-hoje:not(.dia-sel) {
    border-color: var(--accent);
    border-width: 2px;
}
.dia-nome {
    font-size: .6rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    line-height: 1;
}
.dia-num {
    font-size: 1.15rem;
    font-weight: 800;
    line-height: 1.2;
}
.dia-mes {
    font-size: .58rem;
    line-height: 1;
    opacity: .65;
}
.dia-dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: var(--accent);
    margin-bottom: 1px;
}
.dia-card.dia-sel .dia-dot { background: rgba(255,255,255,.75); }
.btn-semana {
    width: 36px;
    height: 36px;
    padding: 0;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
}
.slot-btn {
    border-radius: 10px;
    font-weight: 600;
    min-width: 72px;
    transition: background .12s, border-color .12s, transform .1s;
}
.slot-btn:hover:not(:disabled) { transform: translateY(-1px); }
</style>

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

<?php if (!empty($_SESSION['reagendar_id'])): ?>
<div class="alert alert-info d-flex align-items-center gap-2 mb-4 flex-wrap">
    <i class="bi bi-arrow-repeat fs-5 flex-shrink-0"></i>
    <span class="flex-grow-1">
        <strong>Reagendamento em curso.</strong>
        Selecione a nova data e horário — o agendamento anterior será cancelado ao confirmar.
    </span>
    <a href="<?= BASE ?>/usuario/historico.php?cancelar_reagendamento=1"
       class="btn btn-sm btn-outline-secondary flex-shrink-0">
        <i class="bi bi-x me-1"></i>Cancelar
    </a>
</div>
<?php endif ?>

<div class="row g-4">
    <div class="col-lg-8">

        <!-- ── Calendário semanal ── -->
        <div class="card mb-3 p-3">
            <div class="semana-nav">

                <?php if ($podeIrAntes): ?>
                <a href="<?= h($urlBase . '&data=' . $semanaIniPrev) ?>"
                   class="btn btn-outline-secondary btn-semana" title="Semana anterior">
                    <i class="bi bi-chevron-left"></i>
                </a>
                <?php else: ?>
                <button class="btn btn-outline-secondary btn-semana" disabled>
                    <i class="bi bi-chevron-left"></i>
                </button>
                <?php endif ?>

                <div class="semana-dias">
                <?php for ($i = 0; $i < 7; $i++):
                    $dTs    = strtotime($semanaIni) + $i * 86400;
                    $d      = date('Y-m-d', $dTs);
                    $ehHoje = $d === date('Y-m-d');
                    $ehSel  = $d === $dataSel;
                    $off    = !($diasTemSlot[$d] ?? false);

                    $classes = 'dia-card';
                    if ($ehSel && !$off) $classes .= ' dia-sel';
                    if ($ehHoje)         $classes .= ' dia-hoje';
                    if ($off)            $classes .= ' dia-off';

                    $tag  = (!$off && !$ehSel) ? 'a' : 'span';
                    $href = (!$off && !$ehSel) ? ' href="' . h($urlBase . '&data=' . $d) . '"' : '';
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
                <a href="<?= h($urlBase . '&data=' . $semanaIniNext) ?>"
                   class="btn btn-outline-secondary btn-semana" title="Próxima semana">
                    <i class="bi bi-chevron-right"></i>
                </a>
                <?php else: ?>
                <button class="btn btn-outline-secondary btn-semana" disabled>
                    <i class="bi bi-chevron-right"></i>
                </button>
                <?php endif ?>

            </div>
        </div>

        <!-- ── Slots de horário ── -->
        <div class="card">
            <div class="card-header px-4 py-3 d-flex align-items-center gap-2">
                <i class="bi bi-clock text-accent"></i>
                <span class="fw-medium"><?= h($diaExibido) ?></span>
            </div>
            <div class="card-body p-4">
                <?php if (!$horario): ?>
                <div class="text-center py-4 text-secondary">
                    <i class="bi bi-calendar-x fs-2 d-block mb-2 opacity-25"></i>
                    <p class="mb-0">Não atendemos neste dia. Escolha outra data no calendário.</p>
                </div>
                <?php else: ?>
                <?php if ($diaEspecial && !$diaEspecial['BloqueiaTotal']): ?>
                <div class="alert alert-warning py-2 px-3 mb-3 d-flex align-items-center gap-2 small">
                    <i class="bi bi-clock-history flex-shrink-0"></i>
                    Horário diferente
                    (<?= substr($diaEspecial['HoraInicio'], 0, 5) ?>–<?= substr($diaEspecial['HoraFim'], 0, 5) ?>)
                </div>
                <?php endif ?>
                <?php if (empty($slots)): ?>
                <div class="text-center py-4 text-secondary">
                    <i class="bi bi-clock-history fs-2 d-block mb-2 opacity-25"></i>
                    <p class="mb-0">Nenhum horário disponível neste dia. Tente outra data.</p>
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
                <?php endif ?>
            </div>
        </div>

    </div>

    <!-- Resumo (desktop) -->
    <div class="col-lg-4">
        <div class="card p-4 sticky-top" style="top:80px;">
            <h6 class="fw-bold mb-3">
                <img src="<?= BASE ?>/geral/img/mascara.png" alt="" style="width:1.1rem;height:1.1rem;object-fit:contain;vertical-align:middle;" class="me-2">Resumo
            </h6>
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
            <div id="boxCountdown" class="d-none alert alert-warning py-2 px-3 mb-3 d-flex align-items-center gap-2" style="font-size:.9rem;">
                <i class="bi bi-hourglass-split text-warning"></i>
                <span>Horário reservado por <strong id="textoCountdown">10:00</strong></span>
            </div>
            <a href="#" id="btnConfirmar" class="btn btn-accent btn-lg w-100 disabled">
                Confirmar agendamento <i class="bi bi-arrow-right ms-1"></i>
            </a>
        </div>
    </div>
</div>

<!-- Barra mobile -->
<div id="barraConfirmarMobile"
     class="d-lg-none position-fixed bottom-0 start-0 end-0 p-3"
     style="display:none!important;background:var(--bg-card);border-top:2px solid var(--accent);z-index:1050;box-shadow:0 -4px 24px rgba(0,0,0,.14);">
    <div class="d-flex align-items-center gap-2">
        <div class="flex-grow-1 small">
            <span class="fw-semibold text-accent" id="mobResumoHora">—</span>
            <span class="text-secondary ms-1" id="mobResumoData"></span>
        </div>
        <a href="#" id="btnConfirmarMobile" class="btn btn-accent flex-shrink-0">
            Confirmar <i class="bi bi-arrow-right ms-1"></i>
        </a>
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
let slotSelecionado   = null;
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
            if (slotSelecionado) {
                slotSelecionado.classList.add('btn-outline-accent');
                slotSelecionado.classList.remove('btn-accent');
                slotSelecionado.disabled = false;
                slotSelecionado = null;
            }
            document.getElementById('btnConfirmar').classList.add('disabled');
            box.classList.add('d-none');
            const barMob = document.getElementById('barraConfirmarMobile');
            if (barMob) barMob.style.setProperty('display', 'none', 'important');
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
            csrf_token: '<?= h(gerarTokenCSRF()) ?>',
        })
    })
    .then(r => r.json())
    .then(res => {
        document.querySelectorAll('.slot-btn').forEach(b => b.disabled = false);
        if (!res.ok) {
            btn.disabled = true;
            btn.classList.remove('btn-outline-accent', 'btn-accent');
            btn.classList.add('btn-secondary', 'opacity-50');
            bcToast(res.msg || 'Horário indisponível. Tente outro.', 'warning');
            return;
        }
        if (slotSelecionado && slotSelecionado !== btn) {
            slotSelecionado.classList.remove('btn-accent');
            slotSelecionado.classList.add('btn-outline-accent');
        }
        btn.classList.remove('btn-outline-accent');
        btn.classList.add('btn-accent');
        slotSelecionado = btn;
        document.getElementById('inp_hora').value  = hora;
        document.getElementById('inp_token').value = res.token;
        const dataFmt = data.split('-').reverse().join('/');
        document.getElementById('resumoData').textContent = dataFmt;
        document.getElementById('resumoHora').textContent = hora;
        const acao = function(e) {
            e.preventDefault();
            document.getElementById('formConfirmar').submit();
        };
        const btnConf = document.getElementById('btnConfirmar');
        btnConf.classList.remove('disabled');
        btnConf.onclick = acao;
        const barMob = document.getElementById('barraConfirmarMobile');
        if (barMob) {
            barMob.style.setProperty('display', 'block', 'important');
            document.getElementById('mobResumoHora').textContent = hora;
            document.getElementById('mobResumoData').textContent = dataFmt;
            document.getElementById('btnConfirmarMobile').onclick = acao;
        }
        iniciarCountdown(res.expira);
    })
    .catch(() => {
        document.querySelectorAll('.slot-btn').forEach(b => b.disabled = false);
        bcToast('Erro de conexão. Tente novamente.', 'danger');
    });
}
</script>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
