<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

$vista = $_GET['vista'] ?? 'lista';

// ── Ação POST ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
        redirecionarComMensagem(BASE . '/painel/agenda.php', 'Token inválido.', 'danger');
    }
    $acao = $_POST['acao'] ?? '';
    $id   = $_POST['id']   ?? '';

    // Confirmar / cancelar / concluir agendamento
    $statusMap = ['confirmar' => 'confirmado', 'cancelar' => 'cancelado', 'concluir' => 'concluido'];
    if (isset($statusMap[$acao]) && $id) {
        try {
            $pdo->prepare('UPDATE Agendamentos SET StatusAgendamento = :status WHERE IDAgendamento = :id')
                ->execute([':status' => $statusMap[$acao], ':id' => $id]);
            $params = array_filter(['vista' => $_GET['vista'] ?? null, 'mes' => $_GET['mes'] ?? null, 'semana' => $_GET['semana'] ?? null]);
            $qs = $params ? '?' . http_build_query($params) : '';
            redirecionarComMensagem(BASE . '/painel/agenda.php' . $qs, 'Status atualizado.', 'success');
        } catch (PDOException $e) {
            error_log('[Agenda] ' . $e->getMessage());
            redirecionarComMensagem(BASE . '/painel/agenda.php', 'Erro ao atualizar.', 'danger');
        }
    }

    // Adicionar bloqueio de horário
    if ($acao === 'bloquear') {
        $ini    = trim($_POST['bloq_ini'] ?? '');
        $fim    = trim($_POST['bloq_fim'] ?? '');
        $motivo = trim($_POST['bloq_motivo'] ?? '');
        if ($ini && $fim && $fim > $ini) {
            try {
                $pdo->prepare('INSERT INTO BloqueiosAgenda (IDBloqueio,DataInicio,DataFim,Motivo) VALUES (:id,:ini,:fim,:mot)')
                    ->execute([':id' => gerarUuid(), ':ini' => $ini, ':fim' => $fim, ':mot' => $motivo ?: null]);
            } catch (PDOException $e) { error_log('[Bloqueio] ' . $e->getMessage()); }
        }
        $params = array_filter(['vista' => $_GET['vista'] ?? null, 'mes' => $_GET['mes'] ?? null, 'semana' => $_GET['semana'] ?? null]);
        header('Location: ' . BASE . '/painel/agenda.php' . ($params ? '?' . http_build_query($params) : ''));
        exit;
    }

    // Cancelar série recorrente (este + futuros ou somente este)
    if ($acao === 'cancelar_serie' && $id) {
        $modo = $_POST['modo'] ?? 'este'; // 'este' | 'futuros'
        try {
            if ($modo === 'futuros') {
                // Busca grupo e data do agendamento clicado
                $rowRec = $pdo->prepare(
                    'SELECT GrupoRecorrencia, DataHoraAgendamento FROM Agendamentos WHERE IDAgendamento = :id LIMIT 1'
                );
                $rowRec->execute([':id' => $id]);
                $recInfo = $rowRec->fetch();
                if ($recInfo && $recInfo['GrupoRecorrencia']) {
                    $pdo->prepare(
                        'UPDATE Agendamentos SET StatusAgendamento = \'cancelado\'
                         WHERE GrupoRecorrencia = :grupo
                           AND DataHoraAgendamento >= :data
                           AND StatusAgendamento IN (\'pendente\',\'confirmado\')'
                    )->execute([':grupo' => $recInfo['GrupoRecorrencia'], ':data' => $recInfo['DataHoraAgendamento']]);
                } else {
                    // Sem grupo — cancela só este
                    $pdo->prepare('UPDATE Agendamentos SET StatusAgendamento = \'cancelado\' WHERE IDAgendamento = :id')
                        ->execute([':id' => $id]);
                }
            } else {
                $pdo->prepare('UPDATE Agendamentos SET StatusAgendamento = \'cancelado\' WHERE IDAgendamento = :id')
                    ->execute([':id' => $id]);
            }
            $params = array_filter(['vista' => $_GET['vista'] ?? null, 'mes' => $_GET['mes'] ?? null, 'semana' => $_GET['semana'] ?? null]);
            $qs = $params ? '?' . http_build_query($params) : '';
            $msgRec = $modo === 'futuros' ? 'Série cancelada a partir deste agendamento.' : 'Agendamento cancelado.';
            redirecionarComMensagem(BASE . '/painel/agenda.php' . $qs, $msgRec, 'success');
        } catch (PDOException $e) {
            error_log('[Agenda] ' . $e->getMessage());
            redirecionarComMensagem(BASE . '/painel/agenda.php', 'Erro ao cancelar.', 'danger');
        }
    }

    // Remover bloqueio
    if ($acao === 'rem_bloqueio' && $id) {
        try {
            $pdo->prepare('DELETE FROM BloqueiosAgenda WHERE IDBloqueio = :id')->execute([':id' => $id]);
        } catch (PDOException $e) { error_log('[Bloqueio] ' . $e->getMessage()); }
        $params = array_filter(['vista' => $_GET['vista'] ?? null, 'mes' => $_GET['mes'] ?? null, 'semana' => $_GET['semana'] ?? null]);
        header('Location: ' . BASE . '/painel/agenda.php' . ($params ? '?' . http_build_query($params) : ''));
        exit;
    }
}

$servicos = $pdo->query('SELECT IDServico, Nome, DuracaoMinutos, Preco FROM Servicos WHERE Ativo = 1 ORDER BY Ordem')->fetchAll();

// Tipos de dia (disponíveis em ambas as visões)
$tiposDia = [];
try {
    $tiposDia = $pdo->query(
        'SELECT IDTipo, Nome, Cor, BloqueiaTotal, HoraInicio, HoraFim FROM TiposDia ORDER BY Nome ASC'
    )->fetchAll();
} catch (PDOException) {}

// Horários de atendimento por dia da semana (0=Dom..6=Sáb) para limitar o datetime-local do modal de bloqueio
$horariosAtend = [];
try {
    $rowsHor = $pdo->query('SELECT DiaSemana, HoraInicio, HoraFim FROM HorariosAtendimento WHERE Ativo = 1')->fetchAll();
    foreach ($rowsHor as $rh) {
        $horariosAtend[(int)$rh['DiaSemana']] = [
            'ini' => substr($rh['HoraInicio'], 0, 5),
            'fim' => substr($rh['HoraFim'],    0, 5),
        ];
    }
} catch (PDOException $e) { error_log('[Agenda] horariosAtend: ' . $e->getMessage()); }

// Inicializa variáveis condicionais para evitar warnings de análise estática
$semanaOffset  = 0;
$inicioPeriodo = $fimPeriodo = 0;
$porDia        = [];
$bloqueiosDia  = [];
$diasEspSemana = [];
$mesSel        = $mesPrev = $mesNext = $mesNome = '';
$porDiaCal     = $semanasCal = $calJson = [];
$bloqueiosCal  = [];
$diasEspCal    = [];
$diasEspCalJson = [];
$tiposDiaJson  = array_map(fn($tp) => [
    'id'           => $tp['IDTipo'],
    'nome'         => $tp['Nome'],
    'cor'          => $tp['Cor'],
    'bloqueiaTotal'=> (bool)$tp['BloqueiaTotal'],
    'horaInicio'   => $tp['HoraInicio'] ? substr($tp['HoraInicio'], 0, 5) : null,
    'horaFim'      => $tp['HoraFim']    ? substr($tp['HoraFim'],    0, 5) : null,
], $tiposDia);

// ── VISÃO LISTA (semanal) ─────────────────────────────────────
if ($vista !== 'calendario') {
    $semanaOffset  = (int)($_GET['semana'] ?? 0);
    $inicioPeriodo = strtotime("monday this week +{$semanaOffset} week");
    $fimPeriodo    = strtotime("sunday this week +{$semanaOffset} week");
    $iniSQL = date('Y-m-d', $inicioPeriodo);
    $fimSQL = date('Y-m-d', $fimPeriodo);

    try {
        $stmt = $pdo->prepare(
            'SELECT a.*, u.Nome AS NomeCliente, u.Telefone,
                    s.Nome AS NomeServico, s.DuracaoMinutos,
                    ss.Nome AS NomeSubServico,
                    IF(fa.IDFicha IS NOT NULL, 1, 0) AS TemFicha,
                    IF(fa.Gravida OR fa.Amamentando OR fa.AlergiaAdesivo OR fa.AlergiaLatex
                       OR fa.ReacaoAnterior OR fa.ProblemaOcular OR fa.QuimioRadio
                       OR fa.Tricotilomania, 1, 0) AS AlertaAlto,
                    IF(fa.Tireoide OR fa.Diabetes OR fa.PressaoAlterada OR fa.UsaMedicamentos
                       OR fa.Retinoide OR fa.CondicaoPele, 1, 0) AS AlertaMedio
             FROM Agendamentos a
             JOIN Usuarios u ON u.IDUsuario = a.FKCliente
             JOIN Servicos s ON s.IDServico = a.FKServico
             LEFT JOIN SubServicos ss ON ss.IDSubServico = a.FKSubServico
             LEFT JOIN FichaAnamnese fa ON fa.FKCliente = a.FKCliente
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

    $porDia = [];
    foreach ($agendamentos as $ag) {
        $dia = date('Y-m-d', strtotime($ag['DataHoraAgendamento']));
        $porDia[$dia][] = $ag;
    }

    // Bloqueios que tocam a semana
    try {
        $bStmt = $pdo->prepare(
            'SELECT IDBloqueio, DataInicio, DataFim, Motivo FROM BloqueiosAgenda
             WHERE DataFim > :ini AND DataInicio < :fim ORDER BY DataInicio ASC'
        );
        $bStmt->execute([':ini' => $iniSQL . ' 00:00:00', ':fim' => $fimSQL . ' 23:59:59']);
        foreach ($bStmt->fetchAll() as $b) {
            // Distribui o bloqueio em cada dia que ele cobre dentro da semana
            $bIniTs = strtotime($b['DataInicio']);
            $bFimTs = strtotime($b['DataFim']);
            for ($d = $inicioPeriodo; $d <= $fimPeriodo; $d += 86400) {
                $dStr  = date('Y-m-d', $d);
                $dIni  = strtotime($dStr . ' 00:00:00');
                $dFim  = strtotime($dStr . ' 23:59:59');
                if ($bIniTs < $dFim && $bFimTs > $dIni) {
                    $bloqueiosDia[$dStr][] = $b;
                }
            }
        }
    } catch (PDOException) {}

    // Dias especiais da semana
    try {
        $deStmt = $pdo->prepare(
            'SELECT de.Data, td.IDTipo, td.Nome, td.Cor, td.BloqueiaTotal
             FROM DiasEspeciais de
             JOIN TiposDia td ON td.IDTipo = de.FKTipo
             WHERE de.Data BETWEEN :ini AND :fim'
        );
        $deStmt->execute([':ini' => $iniSQL, ':fim' => $fimSQL]);
        foreach ($deStmt->fetchAll() as $de) {
            $diasEspSemana[$de['Data']] = $de;
        }
    } catch (PDOException) {}
}

// ── VISÃO CALENDÁRIO (mensal) ─────────────────────────────────
if ($vista === 'calendario') {
    $mesSel = $_GET['mes'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $mesSel)) $mesSel = date('Y-m');

    $mesTs       = strtotime($mesSel . '-01');
    $mesPrev     = date('Y-m', strtotime('-1 month', $mesTs));
    $mesNext     = date('Y-m', strtotime('+1 month', $mesTs));
    $mesesPT     = [
        '',
        'Janeiro',
        'Fevereiro',
        'Março',
        'Abril',
        'Maio',
        'Junho',
        'Julho',
        'Agosto',
        'Setembro',
        'Outubro',
        'Novembro',
        'Dezembro'
    ];
    $mesNome     = $mesesPT[(int)date('n', $mesTs)] . ' de ' . date('Y', $mesTs);
    $primeiroDia = date('Y-m-d', $mesTs);
    $ultimoDia   = date('Y-m-d', strtotime('last day of', $mesTs));
    $diasNoMes   = (int) date('t', $mesTs);
    $colInicio   = (int) date('w', $mesTs); // 0=Dom

    try {
        $stmtCal = $pdo->prepare(
            'SELECT a.IDAgendamento, a.DataHoraAgendamento, a.StatusAgendamento,
                    a.StatusPagamento, a.GrupoRecorrencia,
                    u.Nome AS NomeCliente, u.Telefone,
                    s.Nome AS NomeServico, s.DuracaoMinutos,
                    ss.Nome AS NomeSubServico,
                    IF(fa.IDFicha IS NOT NULL, 1, 0) AS TemFicha,
                    IF(fa.Gravida OR fa.Amamentando OR fa.AlergiaAdesivo OR fa.AlergiaLatex
                       OR fa.ReacaoAnterior OR fa.ProblemaOcular OR fa.QuimioRadio
                       OR fa.Tricotilomania, 1, 0) AS AlertaAlto,
                    IF(fa.Tireoide OR fa.Diabetes OR fa.PressaoAlterada OR fa.UsaMedicamentos
                       OR fa.Retinoide OR fa.CondicaoPele, 1, 0) AS AlertaMedio
             FROM Agendamentos a
             JOIN Usuarios u ON u.IDUsuario = a.FKCliente
             JOIN Servicos s ON s.IDServico = a.FKServico
             LEFT JOIN SubServicos ss ON ss.IDSubServico = a.FKSubServico
             LEFT JOIN FichaAnamnese fa ON fa.FKCliente = a.FKCliente
             WHERE DATE(a.DataHoraAgendamento) BETWEEN :ini AND :fim
               AND a.StatusAgendamento != \'cancelado\'
             ORDER BY a.DataHoraAgendamento ASC'
        );
        $stmtCal->execute([':ini' => $primeiroDia, ':fim' => $ultimoDia]);
        $agsCal = $stmtCal->fetchAll();
    } catch (PDOException $e) {
        error_log('[Agenda] ' . $e->getMessage());
        $agsCal = [];
    }

    $porDiaCal = [];
    foreach ($agsCal as $ag) {
        $dia = date('Y-m-d', strtotime($ag['DataHoraAgendamento']));
        $porDiaCal[$dia][] = $ag;
    }

    // Monta semanas para o grid
    $semanasCal = [];
    $semana     = array_fill(0, $colInicio, null);
    for ($d = 1; $d <= $diasNoMes; $d++) {
        $semana[] = $d;
        if (count($semana) === 7) {
            $semanasCal[] = $semana;
            $semana = [];
        }
    }
    if (!empty($semana)) {
        while (count($semana) < 7) $semana[] = null;
        $semanasCal[] = $semana;
    }

    // Bloqueios do mês para o calendário
    try {
        $bCalStmt = $pdo->prepare(
            'SELECT IDBloqueio, DataInicio, DataFim, Motivo FROM BloqueiosAgenda
             WHERE DataFim > :ini AND DataInicio < :fim ORDER BY DataInicio ASC'
        );
        $bCalStmt->execute([':ini' => $primeiroDia . ' 00:00:00', ':fim' => $ultimoDia . ' 23:59:59']);
        foreach ($bCalStmt->fetchAll() as $b) {
            $bIniTs = strtotime($b['DataInicio']);
            $bFimTs = strtotime($b['DataFim']);
            for ($d = strtotime($primeiroDia); $d <= strtotime($ultimoDia); $d += 86400) {
                $dStr = date('Y-m-d', $d);
                $dFim = strtotime($dStr . ' 23:59:59');
                $dIni = strtotime($dStr . ' 00:00:00');
                if ($bIniTs < $dFim && $bFimTs > $dIni) {
                    $bloqueiosCal[$dStr][] = ['id' => $b['IDBloqueio'], 'ini' => date('H:i', $bIniTs), 'fim' => date('H:i', $bFimTs), 'motivo' => $b['Motivo'] ?? ''];
                }
            }
        }
    } catch (PDOException) {}

    // Dias especiais do mês
    try {
        $deCalStmt = $pdo->prepare(
            'SELECT de.Data, td.IDTipo, td.Nome, td.Cor, td.BloqueiaTotal
             FROM DiasEspeciais de
             JOIN TiposDia td ON td.IDTipo = de.FKTipo
             WHERE de.Data BETWEEN :ini AND :fim'
        );
        $deCalStmt->execute([':ini' => $primeiroDia, ':fim' => $ultimoDia]);
        foreach ($deCalStmt->fetchAll() as $de) {
            $diasEspCal[$de['Data']] = $de;
        }
    } catch (PDOException) {}
    foreach ($diasEspCal as $data => $de) {
        $diasEspCalJson[$data] = [
            'id'           => $de['IDTipo'],
            'nome'         => $de['Nome'],
            'cor'          => $de['Cor'],
            'bloqueiaTotal'=> (bool)$de['BloqueiaTotal'],
        ];
    }

    // JSON para o JS (detalhe do dia clicado)
    $calJson = [];
    foreach ($porDiaCal as $dataKey => $ags) {
        $calJson[$dataKey] = array_map(fn($ag) => [
            'id'      => $ag['IDAgendamento'],
            'hora'    => date('H:i', strtotime($ag['DataHoraAgendamento'])),
            'nome'    => $ag['NomeCliente'],
            'servico' => $ag['NomeSubServico'] ?? $ag['NomeServico'],
            'duracao' => $ag['DuracaoMinutos'],
            'status'  => $ag['StatusAgendamento'],
            'pag'     => $ag['StatusPagamento'],
            'tel'     => $ag['Telefone'] ?? '',
            'alerta'  => $ag['AlertaAlto'] ? 'alto' : ($ag['AlertaMedio'] ? 'medio' : ($ag['TemFicha'] ? 'ok' : 'sem_ficha')),
            'grupo'   => $ag['GrupoRecorrencia'] ?? null,
        ], $ags);
    }
}

$paginaTitulo = 'Agenda';
$areaAtual    = 'painel';
require_once __DIR__ . '/../geral/header.php';

// ── Helper: botões de ação do agendamento ─────────────────────
function botoesAgendamento(array $ag, string $csrfToken, array $extraGet = []): string
{
    $qs = $extraGet ? '?' . http_build_query($extraGet) : '';
    $out = '<div class="d-flex gap-1 flex-shrink-0">';
    if ($ag['Telefone']) {
        $out .= '<a href="https://wa.me/' . h($ag['Telefone']) . '" target="_blank"
                    class="btn btn-sm btn-outline-success" title="WhatsApp">
                    <i class="bi bi-whatsapp"></i></a>';
    }
    if ($ag['StatusAgendamento'] === 'pendente') {
        $out .= '<form method="POST" class="d-inline">
                    <input type="hidden" name="csrf_token" value="' . $csrfToken . '">
                    <input type="hidden" name="acao" value="confirmar">
                    <input type="hidden" name="id" value="' . h($ag['IDAgendamento']) . '">
                    <button class="btn btn-sm btn-outline-success" title="Confirmar">
                        <i class="bi bi-check-lg"></i></button></form>';
    }
    if (in_array($ag['StatusAgendamento'], ['pendente', 'confirmado'])) {
        if (!empty($ag['GrupoRecorrencia'])) {
            $out .= '<button class="btn btn-sm btn-outline-danger" title="Cancelar"
                         onclick="cancelarComOpcoesRec(\'' . h($ag['IDAgendamento']) . '\',\'lista\')">
                         <i class="bi bi-x-lg"></i></button>';
        } else {
            $out .= '<form method="POST" class="d-inline"
                          data-confirm="Confirma o cancelamento deste agendamento?"
                          data-confirm-label="Cancelar agendamento">
                        <input type="hidden" name="csrf_token" value="' . $csrfToken . '">
                        <input type="hidden" name="acao" value="cancelar">
                        <input type="hidden" name="id" value="' . h($ag['IDAgendamento']) . '">
                        <button class="btn btn-sm btn-outline-danger" title="Cancelar">
                            <i class="bi bi-x-lg"></i></button></form>';
        }
    }
    if ($ag['StatusAgendamento'] === 'confirmado') {
        $out .= '<form method="POST" class="d-inline">
                    <input type="hidden" name="csrf_token" value="' . $csrfToken . '">
                    <input type="hidden" name="acao" value="concluir">
                    <input type="hidden" name="id" value="' . h($ag['IDAgendamento']) . '">
                    <button class="btn btn-sm btn-outline-secondary" title="Concluído">
                        <i class="bi bi-check2-all"></i></button></form>';
    }
    $out .= '</div>';
    return $out;
}

$csrfToken = gerarTokenCSRF();
?>

<!-- ── Cabeçalho com toggle de visão ─────────────────────────── -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <h4 class="fw-bold mb-0 d-flex align-items-center gap-2">
        <i class="bi bi-calendar-week fs-4" style="color:var(--accent);"></i>
        Agenda
    </h4>

    <div class="d-flex align-items-center gap-2 flex-wrap">
        <!-- Switch Lista / Calendário (só ícones) -->
        <div class="bc-view-switch" role="group" aria-label="Visão da agenda">
            <a href="?vista=lista<?= $vista === 'lista' ? '&semana=' . ($semanaOffset ?? 0) : '' ?>"
               class="bc-view-btn <?= $vista !== 'calendario' ? 'ativo' : '' ?>" title="Lista">
                <i class="bi bi-list-ul"></i>
            </a>
            <a href="?vista=calendario&mes=<?= $vista === 'calendario' ? h($mesSel) : date('Y-m') ?>"
               class="bc-view-btn <?= $vista === 'calendario' ? 'ativo' : '' ?>" title="Calendário">
                <i class="bi bi-grid-3x3-gap"></i>
            </a>
        </div>

        <!-- Navegação de período agrupada -->
        <div class="bc-nav-periodo">
            <?php if ($vista !== 'calendario'): ?>
                <a href="?vista=lista&semana=<?= ($semanaOffset ?? 0) - 1 ?>" class="bc-nav-btn" title="Semana anterior">
                    <i class="bi bi-chevron-left"></i>
                </a>
                <span class="bc-nav-label"><?= date('d/m', $inicioPeriodo) ?> – <?= date('d/m', $fimPeriodo) ?></span>
                <a href="?vista=lista&semana=<?= ($semanaOffset ?? 0) + 1 ?>" class="bc-nav-btn" title="Próxima semana">
                    <i class="bi bi-chevron-right"></i>
                </a>
            <?php else: ?>
                <a href="?vista=calendario&mes=<?= h($mesPrev) ?>" class="bc-nav-btn" title="Mês anterior">
                    <i class="bi bi-chevron-left"></i>
                </a>
                <span class="bc-nav-label"><?= h($mesNome) ?></span>
                <a href="?vista=calendario&mes=<?= h($mesNext) ?>" class="bc-nav-btn" title="Próximo mês">
                    <i class="bi bi-chevron-right"></i>
                </a>
            <?php endif ?>
        </div>

        <?php if (($vista !== 'calendario' && ($semanaOffset ?? 0) !== 0) || ($vista === 'calendario' && $mesSel !== date('Y-m'))): ?>
            <a href="?vista=<?= $vista ?>&<?= $vista === 'calendario' ? 'mes=' . date('Y-m') : 'semana=0' ?>"
               class="btn btn-outline-accent btn-sm">Hoje</a>
        <?php endif ?>

        <?php if ($vista === 'calendario' && !empty($tiposDia)): ?>
        <button class="btn btn-outline-accent btn-sm" id="btnModoSelecao" onclick="toggleModoSelecao()">
            <i class="bi bi-ui-checks me-1"></i> Selecionar
        </button>
        <?php endif ?>
        <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#modalBloqueio" title="Bloquear horário">
            <i class="bi bi-slash-circle me-1"></i> Bloquear
        </button>
        <a href="<?= BASE ?>/painel/novo_agendamento.php" class="btn btn-accent btn-sm">
            <i class="bi bi-plus-lg me-1"></i> Novo
        </a>
    </div>
</div>

<?php if ($vista !== 'calendario'): ?>
    <!-- ══════════════════════════════════════════════════════════
     VISÃO LISTA (semanal)
════════════════════════════════════════════════════════════ -->
    <?php
    $diasSemana = ['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo'];
    for ($d = 0; $d < 7; $d++):
        $ts    = strtotime("+{$d} days", $inicioPeriodo);
        $key   = date('Y-m-d', $ts);
        $ags      = $porDia[$key] ?? [];
        $bloqs    = $bloqueiosDia[$key] ?? [];
        $eHoje    = $key === date('Y-m-d');
        $temItens = !empty($ags) || !empty($bloqs);
    ?>
        <div class="card mb-3 <?= $eHoje ? 'border-accent' : '' ?>">
            <div class="card-header d-flex align-items-center gap-2 px-4 py-2"
                style="<?= $eHoje ? 'background:var(--accent-light)' : '' ?>">
                <span class="fw-semibold <?= $eHoje ? 'text-accent' : '' ?>"><?= $diasSemana[$d] ?></span>
                <span class="text-secondary small"><?= date('d/m', $ts) ?></span>
                <?php if ($eHoje): ?><span class="badge ms-1" style="background:var(--accent);">Hoje</span><?php endif ?>
                <?php if (!empty($bloqs)): ?>
                    <span class="badge ms-1 bg-danger bg-opacity-75"><i class="bi bi-slash-circle me-1"></i><?= count($bloqs) ?> bloqueio<?= count($bloqs) > 1 ? 's' : '' ?></span>
                <?php endif ?>
                <?php if (isset($diasEspSemana[$key])): $deL = $diasEspSemana[$key]; ?>
                    <span class="badge ms-1 rounded-pill" id="tipoBadgeLista_<?= $key ?>"
                          style="background:<?= h($deL['Cor']) ?>;">
                        <i class="bi bi-<?= $deL['BloqueiaTotal'] ? 'moon' : 'clock' ?> me-1"></i><?= h($deL['Nome']) ?>
                    </span>
                <?php else: ?>
                    <span class="badge ms-1 rounded-pill d-none" id="tipoBadgeLista_<?= $key ?>"></span>
                <?php endif ?>
                <div class="ms-auto d-flex align-items-center gap-2">
                    <?php if (!empty($tiposDia)): ?>
                    <select class="form-select form-select-sm"
                            style="width:auto;max-width:140px;font-size:.75rem;padding:.15rem .5rem 0.15rem .4rem;"
                            id="sltTipoDiaLista_<?= $key ?>"
                            onchange="alterarTipoDiaLista(this.value, '<?= $key ?>', this)">
                        <option value="">— Tipo —</option>
                        <?php foreach ($tiposDia as $tp): ?>
                        <option value="<?= h($tp['IDTipo']) ?>"
                            data-cor="<?= h($tp['Cor']) ?>"
                            <?= (isset($diasEspSemana[$key]) && $diasEspSemana[$key]['IDTipo'] === $tp['IDTipo']) ? 'selected' : '' ?>>
                            <?= h($tp['Nome']) ?>
                        </option>
                        <?php endforeach ?>
                    </select>
                    <?php endif ?>
                    <span class="badge bg-secondary"><?= count($ags) ?> ag.</span>
                </div>
            </div>
            <?php if (!$temItens): ?>
                <div class="text-center py-3 text-secondary small">Sem agendamentos</div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($bloqs as $b): ?>
                        <li class="list-group-item px-3 px-md-4 py-2" style="background:rgba(220,53,69,.06);">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="fw-bold text-danger" style="min-width:40px;">
                                    <?= date('H:i', strtotime($b['DataInicio'])) ?>
                                </span>
                                <div class="flex-grow-1">
                                    <span class="fw-medium text-danger"><i class="bi bi-slash-circle me-1"></i>Indisponível</span>
                                    <?php if ($b['Motivo']): ?>
                                        <span class="text-secondary small ms-1">— <?= h($b['Motivo']) ?></span>
                                    <?php endif ?>
                                    <span class="text-secondary small d-block">até <?= date('H:i', strtotime($b['DataFim'])) ?></span>
                                </div>
                                <form method="POST" class="d-inline" data-confirm="Remover este bloqueio?" data-confirm-label="Remover">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="acao" value="rem_bloqueio">
                                    <input type="hidden" name="id" value="<?= h($b['IDBloqueio']) ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Remover bloqueio"><i class="bi bi-x-lg"></i></button>
                                </form>
                            </div>
                        </li>
                    <?php endforeach ?>
                    <?php foreach ($ags as $ag): ?>
                        <li class="list-group-item px-3 px-md-4 py-2">
                            <div class="d-flex align-items-center gap-2 gap-md-3 flex-wrap">
                                <span class="fw-bold text-accent" style="min-width:40px;">
                                    <?= date('H:i', strtotime($ag['DataHoraAgendamento'])) ?>
                                </span>
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center gap-1 flex-wrap">
                                        <span class="fw-medium"><?= h($ag['NomeCliente']) ?></span>
                                        <?php if (!empty($ag['GrupoRecorrencia'])): ?>
                                        <span class="badge bg-secondary" title="Agendamento recorrente" style="font-size:.7rem;"><i class="bi bi-arrow-repeat"></i></span>
                                        <?php endif ?>
                                        <?php if (!empty($ag['AlertaAlto'])): ?>
                                        <span class="badge bg-danger" title="Ficha: atenção alta — verificar antes do atendimento"><i class="bi bi-heart-pulse-fill"></i></span>
                                        <?php elseif (!empty($ag['AlertaMedio'])): ?>
                                        <span class="badge bg-warning text-dark" title="Ficha: atenção moderada"><i class="bi bi-heart-pulse"></i></span>
                                        <?php elseif (empty($ag['TemFicha'])): ?>
                                        <span class="badge bg-secondary" style="opacity:.6;" title="Sem ficha de anamnese"><i class="bi bi-clipboard2-x"></i></span>
                                        <?php endif ?>
                                    </div>
                                    <span class="text-secondary small d-block d-md-inline">
                                        <?= h($ag['NomeSubServico'] ?? $ag['NomeServico']) ?>
                                        (<?= $ag['DuracaoMinutos'] ?>min)
                                    </span>
                                </div>
                                <div class="d-flex align-items-center gap-1 flex-wrap">
                                    <?= labelStatus($ag['StatusAgendamento']) ?>
                                    <?= labelStatusPag($ag['StatusPagamento']) ?>
                                </div>
                                <?= botoesAgendamento($ag, $csrfToken, ['vista' => 'lista', 'semana' => $semanaOffset]) ?>
                            </div>
                        </li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        </div>
    <?php endfor ?>

    <?php if (!empty($tiposDia)): ?>
    <script>
    (function(){
        const CSRF_LISTA   = '<?= h(gerarTokenCSRF()) ?>';
        const BASE_LISTA   = '<?= BASE ?>';
        const tiposDiaLista = <?= json_encode($tiposDiaJson, JSON_UNESCAPED_UNICODE) ?>;

        window.alterarTipoDiaLista = function(tipoId, data, slt) {
            const params = new URLSearchParams({
                acao: tipoId ? 'set' : 'remove',
                data: data,
                csrf_token: CSRF_LISTA,
            });
            if (tipoId) params.set('fk_tipo', tipoId);
            fetch(BASE_LISTA + '/painel/api_tipo_dia.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: params.toString()
            })
            .then(r => r.json())
            .then(res => {
                if (!res.ok) { bcToast('Erro ao salvar tipo.', 'danger'); return; }
                const badge = document.getElementById('tipoBadgeLista_' + data);
                if (tipoId && res.tipo) {
                    const tp = res.tipo;
                    badge.style.background = tp.cor;
                    badge.innerHTML = '<i class="bi bi-' + (tp.bloqueiaTotal ? 'moon' : 'clock') + ' me-1"></i>' + escHtmlLista(tp.nome);
                    badge.classList.remove('d-none');
                } else if (badge) {
                    badge.innerHTML = '';
                    badge.classList.add('d-none');
                }
                bcToast(tipoId ? 'Tipo atribuído!' : 'Tipo removido!', 'success');
            })
            .catch(() => bcToast('Erro de conexão.', 'danger'));
        };

        function escHtmlLista(s) {
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
    }());
    </script>
    <?php endif ?>

<?php else: ?>
    <!-- ══════════════════════════════════════════════════════════
     VISÃO CALENDÁRIO (mensal)
════════════════════════════════════════════════════════════ -->
    <div class="card p-3 mb-3">

        <!-- Legenda -->
        <div class="d-flex gap-3 mb-3 flex-wrap">
            <span class="small d-flex align-items-center gap-1">
                <span class="bc-cal-dot confirmado d-inline-block"></span> Confirmado
            </span>
            <span class="small d-flex align-items-center gap-1">
                <span class="bc-cal-dot pendente d-inline-block"></span> Pendente
            </span>
            <span class="small d-flex align-items-center gap-1">
                <span class="bc-cal-dot concluido d-inline-block"></span> Concluído
            </span>
        </div>

        <!-- Grid de dias da semana -->
        <div class="bc-cal-grid mb-1">
            <?php foreach (['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'] as $dn): ?>
                <div class="bc-cal-header"><?= $dn ?></div>
            <?php endforeach ?>
        </div>

        <!-- Grid de dias do mês -->
        <div class="bc-cal-grid" id="gridCalendario">
            <?php
            $hoje = date('Y-m-d');
            foreach ($semanasCal as $semana):
                foreach ($semana as $dia):
                    if ($dia === null):
            ?>
                        <div class="bc-cal-day bc-vazio"></div>
                    <?php else:
                        $key       = sprintf('%s-%02d', $mesSel, $dia);
                        $agsNoDia  = $porDiaCal[$key] ?? [];
                        $bloqNoDia = $bloqueiosCal[$key] ?? [];
                        $eHojeDia  = ($key === $hoje);
                        $classes   = 'bc-cal-day' . ($eHojeDia ? ' bc-hoje' : '') . (empty($agsNoDia) && empty($bloqNoDia) ? ' bc-sem-ag' : '');
                    ?>
                        <div class="<?= $classes ?>"
                            data-data="<?= $key ?>"
                            role="button"
                            tabindex="0"
                            aria-label="<?= $dia . ' — ' . count($agsNoDia) . ' agendamento(s)' ?>"
                            onclick="mostrarDia('<?= $key ?>', <?= $dia ?>)"
                            onkeydown="if(event.key==='Enter')mostrarDia('<?= $key ?>', <?= $dia ?>)">
                            <div class="bc-cal-num"><?= $dia ?></div>
                            <?php if (isset($diasEspCal[$key])): $deC = $diasEspCal[$key]; ?>
                                <div class="bc-tipo-strip"
                                     style="color:<?= h($deC['Cor']) ?>;border-top:2px solid <?= h($deC['Cor']) ?>;background:<?= h($deC['Cor']) ?>18;">
                                    <?= h($deC['Nome']) ?>
                                </div>
                            <?php endif ?>
                            <?php if (!empty($bloqNoDia)): ?>
                                <span class="bc-cal-dot" style="background:#ef4444;" title="Horário bloqueado"></span>
                            <?php endif ?>
                            <?php if (!empty($agsNoDia)): ?>
                                <div class="bc-cal-dots">
                                    <?php foreach (array_slice($agsNoDia, 0, 4) as $ag): ?>
                                        <span class="bc-cal-dot <?= h($ag['StatusAgendamento']) ?>"></span>
                                    <?php endforeach ?>
                                </div>
                                <span class="bc-cal-count"><?= count($agsNoDia) ?></span>
                            <?php endif ?>
                        </div>
            <?php
                    endif;
                endforeach;
            endforeach;
            ?>
        </div>
    </div>

    <!-- Barra de seleção múltipla (bulk) -->
    <?php if (!empty($tiposDia)): ?>
    <div id="barraSelecao" style="display:none;"
         class="position-fixed bottom-0 start-0 end-0 p-3"
         aria-live="polite"
         style="background:var(--bg-card);border-top:2px solid var(--accent);z-index:1050;box-shadow:0 -4px 24px rgba(0,0,0,.14);">
        <div class="container-lg d-flex align-items-center gap-2 flex-wrap">
            <span class="fw-semibold text-accent" id="contagemSel"></span>
            <select id="sltTipoBulk" class="form-select form-select-sm" style="width:auto;max-width:200px;">
                <option value="">— Atribuir tipo —</option>
                <?php foreach ($tiposDia as $tp): ?>
                <option value="<?= h($tp['IDTipo']) ?>" data-cor="<?= h($tp['Cor']) ?>">
                    <?= h($tp['Nome']) ?>
                    <?php if ($tp['BloqueiaTotal']): ?>(dia inteiro)<?php elseif ($tp['HoraInicio']): ?>(<?= substr($tp['HoraInicio'],0,5) ?>–<?= substr($tp['HoraFim'],0,5) ?>)<?php endif ?>
                </option>
                <?php endforeach ?>
            </select>
            <button class="btn btn-accent btn-sm" onclick="aplicarTipoBulk()">
                <i class="bi bi-tag me-1"></i> Aplicar tipo
            </button>
            <button class="btn btn-outline-danger btn-sm" onclick="bloquearBulk()">
                <i class="bi bi-slash-circle me-1"></i> Bloquear dias
            </button>
            <button class="btn btn-outline-secondary btn-sm ms-auto" onclick="cancelarSelecao()">
                <i class="bi bi-x me-1"></i> Cancelar
            </button>
        </div>
    </div>
    <?php endif ?>

    <!-- Painel de detalhes do dia (preenchido pelo JS) -->
    <div id="painelDia" class="bc-dia-detalhe p-0 mb-3" style="display:none;">
        <div class="card-header d-flex align-items-center justify-content-between gap-2 px-3 px-md-4 py-2 py-md-3 flex-wrap">
            <h6 class="fw-bold mb-0 flex-grow-1" id="tituloDia" style="min-width:0;"></h6>
            <div class="d-flex gap-2 flex-shrink-0">
                <a id="btnNovoDiaLink" href="#" class="btn btn-accent btn-sm">
                    <i class="bi bi-plus me-1"></i><span class="d-none d-sm-inline">Novo agendamento</span><span class="d-sm-none">Novo</span>
                </a>
                <button class="btn btn-sm btn-outline-secondary" onclick="fecharDia()" title="Fechar">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </div>
        <?php if (!empty($tiposDia)): ?>
        <div class="d-flex align-items-center gap-2 px-3 px-md-4 py-2 flex-wrap"
             style="border-bottom:1px solid var(--card-border-color);background:var(--bg-card);">
            <i class="bi bi-tags text-secondary small flex-shrink-0"></i>
            <span class="small text-secondary flex-shrink-0">Tipo do dia:</span>
            <select id="sltTipoDia" class="form-select form-select-sm flex-grow-1" style="max-width:220px;"
                    onchange="alterarTipoDia(this.value)">
                <option value="">— Normal —</option>
                <?php foreach ($tiposDia as $tp): ?>
                <option value="<?= h($tp['IDTipo']) ?>" data-cor="<?= h($tp['Cor']) ?>">
                    <?= h($tp['Nome']) ?>
                    <?php if ($tp['BloqueiaTotal']): ?>
                        (dia inteiro)
                    <?php elseif ($tp['HoraInicio']): ?>
                        (<?= substr($tp['HoraInicio'], 0, 5) ?>–<?= substr($tp['HoraFim'], 0, 5) ?>)
                    <?php endif ?>
                </option>
                <?php endforeach ?>
            </select>
            <span id="tipoDiaInfo" class="small text-secondary" style="display:none;"></span>
        </div>
        <?php endif ?>
        <div id="conteudoDia" class="p-0"></div>
    </div>

    <!-- Formulários de ação ocultos (usados pelo JS para confirmar/cancelar/concluir) -->
    <div id="formsAcao" style="display:none;">
        <form id="formConfirmar" method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="vista" value="calendario">
            <input type="hidden" name="mes" value="<?= h($mesSel) ?>">
            <input type="hidden" name="acao" value="confirmar">
            <input type="hidden" name="id" id="frmConfId">
        </form>
        <form id="formCancelar" method="POST"
            data-confirm="Confirma o cancelamento deste agendamento?"
            data-confirm-label="Cancelar agendamento">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="vista" value="calendario">
            <input type="hidden" name="mes" value="<?= h($mesSel) ?>">
            <input type="hidden" name="acao" value="cancelar">
            <input type="hidden" name="id" id="frmCancelId">
        </form>
        <form id="formConcluir" method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="vista" value="calendario">
            <input type="hidden" name="mes" value="<?= h($mesSel) ?>">
            <input type="hidden" name="acao" value="concluir">
            <input type="hidden" name="id" id="frmConcluirId">
        </form>
    </div>

    <script>
        const dadosCal     = <?= json_encode($calJson,        JSON_UNESCAPED_UNICODE) ?>;
        const bloqueiosCal = <?= json_encode($bloqueiosCal,  JSON_UNESCAPED_UNICODE) ?>;
        const BASE_URL     = '<?= BASE ?>';
        const MES_SEL      = '<?= h($mesSel) ?>';
        const tiposDiaJS   = <?= json_encode($tiposDiaJson,  JSON_UNESCAPED_UNICODE) ?>;
        let diasEspCalJS   = <?= json_encode($diasEspCalJson, JSON_UNESCAPED_UNICODE) ?>;
        const CSRF_AGENDA  = '<?= h(gerarTokenCSRF()) ?>';

        const statusLabel = {
            pendente: '<span class="badge bg-warning text-dark">Pendente</span>',
            confirmado: '<span class="badge bg-success">Confirmado</span>',
            cancelado: '<span class="badge bg-danger">Cancelado</span>',
            concluido: '<span class="badge bg-secondary">Concluído</span>',
        };
        const pagLabel = {
            pendente: '<span class="badge bg-warning text-dark">A receber</span>',
            pago: '<span class="badge bg-success">Pago</span>',
            cancelado: '<span class="badge bg-danger">Cancelado</span>',
        };

        let diaAberto = null;
        let modoSelecao = false;
        const diasSelecionados = new Set();

        function toggleModoSelecao() {
            modoSelecao = !modoSelecao;
            const btn = document.getElementById('btnModoSelecao');
            if (modoSelecao) {
                btn.classList.replace('btn-outline-accent', 'btn-accent');
                btn.innerHTML = '<i class="bi bi-x me-1"></i> Cancelar seleção';
                fecharDia();
            } else {
                cancelarSelecao();
            }
        }

        function toggleDiaSelecionado(data) {
            const cel = document.querySelector('[data-data="' + data + '"]');
            if (!cel || cel.classList.contains('bc-vazio')) return;
            if (diasSelecionados.has(data)) {
                diasSelecionados.delete(data);
                cel.classList.remove('bc-selecionado');
            } else {
                diasSelecionados.add(data);
                cel.classList.add('bc-selecionado');
            }
            atualizarBarraSelecao();
        }

        function atualizarBarraSelecao() {
            const n = diasSelecionados.size;
            const barra = document.getElementById('barraSelecao');
            if (!barra) return;
            document.getElementById('contagemSel').textContent =
                n + (n === 1 ? ' dia selecionado' : ' dias selecionados');
            barra.style.display = n > 0 ? '' : 'none';
        }

        function cancelarSelecao() {
            diasSelecionados.clear();
            document.querySelectorAll('.bc-cal-day.bc-selecionado')
                .forEach(el => el.classList.remove('bc-selecionado'));
            const barra = document.getElementById('barraSelecao');
            if (barra) barra.style.display = 'none';
            modoSelecao = false;
            const btn = document.getElementById('btnModoSelecao');
            if (btn) {
                btn.classList.replace('btn-accent', 'btn-outline-accent');
                btn.innerHTML = '<i class="bi bi-ui-checks me-1"></i> Selecionar';
            }
        }

        function aplicarTipoBulk() {
            const tipoId = document.getElementById('sltTipoBulk').value;
            if (!tipoId) { bcToast('Escolha um tipo na lista.', 'warning'); return; }
            const datas = Array.from(diasSelecionados);
            const params = new URLSearchParams({ acao: 'set_tipo_bulk', csrf_token: CSRF_AGENDA, fk_tipo: tipoId });
            datas.forEach(d => params.append('datas[]', d));
            fetch(BASE_URL + '/painel/api_agenda_bulk.php', {
                method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: params.toString()
            })
            .then(r => r.json())
            .then(res => {
                if (!res.ok) { bcToast('Erro: ' + res.msg, 'danger'); return; }
                datas.forEach(d => {
                    diasEspCalJS[d] = res.tipo;
                    atualizarCelulaTipo(d);
                });
                bcToast(res.total + (res.total === 1 ? ' dia atualizado.' : ' dias atualizados.'), 'success');
                cancelarSelecao();
            })
            .catch(() => bcToast('Erro de conexão.', 'danger'));
        }

        function bloquearBulk(force) {
            const datas = Array.from(diasSelecionados);
            if (!force) {
                const motivo = prompt('Motivo do bloqueio (opcional):');
                if (motivo === null) return;
                bloquearBulk._motivo = motivo.trim();
            }
            const params = new URLSearchParams({
                acao: 'bloquear_bulk', csrf_token: CSRF_AGENDA,
                motivo: bloquearBulk._motivo || '',
            });
            if (force) params.set('force', '1');
            datas.forEach(d => params.append('datas[]', d));
            fetch(BASE_URL + '/painel/api_agenda_bulk.php', {
                method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: params.toString()
            })
            .then(r => r.json())
            .then(res => {
                if (!res.ok && res.aviso) {
                    if (confirm(res.msg)) bloquearBulk(true);
                    return;
                }
                if (!res.ok) { bcToast('Erro: ' + res.msg, 'danger'); return; }
                const n = res.total;
                bcToast(n + (n === 1 ? ' dia bloqueado.' : ' dias bloqueados.'), 'success');
                cancelarSelecao();
                setTimeout(() => location.reload(), 900);
            })
            .catch(() => bcToast('Erro de conexão.', 'danger'));
        }

        function mostrarDia(data, dia) {
            if (modoSelecao) { toggleDiaSelecionado(data); return; }
            // Remove seleção anterior
            document.querySelectorAll('.bc-cal-day.bc-selecionado')
                .forEach(el => el.classList.remove('bc-selecionado'));
            const cel = document.querySelector('[data-data="' + data + '"]');
            if (cel) cel.classList.add('bc-selecionado');

            diaAberto = data;
            const ags   = dadosCal[data]    || [];
            const bloqs = bloqueiosCal[data] || [];

            // Sincroniza seletor de tipo com o dia aberto
            const slt = document.getElementById('sltTipoDia');
            if (slt) {
                const tipoAtual = diasEspCalJS[data];
                slt.value = tipoAtual ? tipoAtual.id : '';
            }

            // Título
            const partes = data.split('-');
            document.getElementById('tituloDia').textContent =
                partes[2] + '/' + partes[1] + '/' + partes[0] +
                ' — ' + ags.length + (ags.length === 1 ? ' agendamento' : ' agendamentos');

            // Link novo agendamento com data pré-preenchida
            document.getElementById('btnNovoDiaLink').href =
                BASE_URL + '/painel/novo_agendamento.php?data=' + data;

            // Conteúdo
            const cont = document.getElementById('conteudoDia');
            if (ags.length === 0 && bloqs.length === 0) {
                cont.innerHTML = '<p class="text-center text-secondary py-4 mb-0">Nenhum agendamento neste dia.</p>';
            } else {
                const ul = document.createElement('ul');
                ul.className = 'list-group list-group-flush';

                // Bloqueios primeiro
                bloqs.forEach(b => {
                    const li = document.createElement('li');
                    li.className = 'list-group-item px-4 py-2';
                    li.style.background = 'rgba(220,53,69,.06)';
                    li.innerHTML =
                        '<div class="d-flex align-items-center gap-2 flex-wrap">' +
                        '<span class="fw-bold text-danger" style="min-width:40px;">' + escHtml(b.ini) + '</span>' +
                        '<div class="flex-grow-1">' +
                        '<span class="fw-medium text-danger"><i class="bi bi-slash-circle me-1"></i>Indisponível</span>' +
                        (b.motivo ? '<span class="text-secondary small ms-1">— ' + escHtml(b.motivo) + '</span>' : '') +
                        '<span class="text-secondary small d-block">até ' + escHtml(b.fim) + '</span>' +
                        '</div>' +
                        '</div>';
                    ul.appendChild(li);
                });

                ags.forEach(ag => {
                    const li = document.createElement('li');
                    li.className = 'list-group-item px-4 py-2';

                    let botoes = '';
                    if (ag.tel) {
                        botoes += '<a href="https://wa.me/' + escHtml(ag.tel) + '" target="_blank" class="btn btn-sm btn-outline-success" title="WhatsApp"><i class="bi bi-whatsapp"></i></a>';
                    }
                    if (ag.status === 'pendente') {
                        botoes += '<button class="btn btn-sm btn-outline-success" onclick="acaoAg(\'confirmar\',\'' + ag.id + '\')" title="Confirmar"><i class="bi bi-check-lg"></i></button>';
                    }
                    if (ag.status === 'pendente' || ag.status === 'confirmado') {
                        if (ag.grupo) {
                            botoes += '<button class="btn btn-sm btn-outline-danger" onclick="cancelarComOpcoesRec(\'' + escHtml(ag.id) + '\',\'cal\')" title="Cancelar"><i class="bi bi-x-lg"></i></button>';
                        } else {
                            botoes += '<button class="btn btn-sm btn-outline-danger" onclick="acaoAg(\'cancelar\',\'' + ag.id + '\')" title="Cancelar"><i class="bi bi-x-lg"></i></button>';
                        }
                    }
                    if (ag.status === 'confirmado') {
                        botoes += '<button class="btn btn-sm btn-outline-secondary" onclick="acaoAg(\'concluir\',\'' + ag.id + '\')" title="Concluído"><i class="bi bi-check2-all"></i></button>';
                    }

                    const alertaBadges = {
                        alto:     '<span class="badge bg-danger ms-1" title="Ficha: atenção alta — verificar antes do atendimento"><i class="bi bi-heart-pulse-fill"></i></span>',
                        medio:    '<span class="badge bg-warning text-dark ms-1" title="Ficha: atenção moderada"><i class="bi bi-heart-pulse"></i></span>',
                        sem_ficha:'<span class="badge bg-secondary ms-1" style="opacity:.6;" title="Sem ficha de anamnese"><i class="bi bi-clipboard2-x"></i></span>',
                        ok:       '',
                    };
                    const alertaBadge = alertaBadges[ag.alerta] || '';
                    const recBadge = ag.grupo ? '<span class="badge bg-secondary ms-1" style="font-size:.7rem;" title="Agendamento recorrente"><i class="bi bi-arrow-repeat"></i></span>' : '';

                    li.innerHTML =
                        '<div class="d-flex align-items-center gap-2 flex-wrap">' +
                        '<span class="fw-bold text-accent" style="min-width:40px;">' + escHtml(ag.hora) + '</span>' +
                        '<div class="flex-grow-1">' +
                        '<span class="fw-medium">' + escHtml(ag.nome) + '</span>' + recBadge + alertaBadge +
                        '<span class="text-secondary small ms-1 d-block d-md-inline">' +
                        escHtml(ag.servico) + ' (' + ag.duracao + 'min)</span>' +
                        '</div>' +
                        '<div class="d-flex gap-1 align-items-center flex-wrap">' +
                        (statusLabel[ag.status] || '') +
                        (pagLabel[ag.pag] || '') +
                        '</div>' +
                        '<div class="d-flex gap-1">' + botoes + '</div>' +
                        '</div>';
                    ul.appendChild(li);
                });
                cont.innerHTML = '';
                cont.appendChild(ul);
            }

            const painel = document.getElementById('painelDia');
            painel.style.display = 'block';
            // Scroll suave até o painel
            setTimeout(() => painel.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            }), 50);
        }

        function fecharDia() {
            document.getElementById('painelDia').style.display = 'none';
            document.querySelectorAll('.bc-cal-day.bc-selecionado')
                .forEach(el => el.classList.remove('bc-selecionado'));
            diaAberto = null;
        }

        function acaoAg(acao, id) {
            const formMap = {
                confirmar: ['formConfirmar', 'frmConfId'],
                cancelar: ['formCancelar', 'frmCancelId'],
                concluir: ['formConcluir', 'frmConcluirId'],
            };
            const [formId, inputId] = formMap[acao];
            document.getElementById(inputId).value = id;
            const form = document.getElementById(formId);
            if (acao === 'cancelar') {
                // usa o sistema de data-confirm já existente no footer
                form.dataset.confirmed = '';
                form.dispatchEvent(new Event('submit', {
                    bubbles: true,
                    cancelable: true
                }));
            } else {
                form.submit();
            }
        }

        function escHtml(s) {
            return String(s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function alterarTipoDia(tipoId) {
            if (!diaAberto) return;
            const params = new URLSearchParams({
                acao:       tipoId ? 'set' : 'remove',
                data:       diaAberto,
                csrf_token: CSRF_AGENDA,
            });
            if (tipoId) params.set('fk_tipo', tipoId);

            fetch(BASE_URL + '/painel/api_tipo_dia.php', {
                method:  'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body:    params.toString(),
            })
            .then(r => r.json())
            .then(res => {
                if (!res.ok) { bcToast('Erro ao salvar tipo.', 'danger'); return; }
                if (tipoId && res.tipo) {
                    diasEspCalJS[diaAberto] = res.tipo;
                } else {
                    delete diasEspCalJS[diaAberto];
                }
                atualizarCelulaTipo(diaAberto);
                if (res.aviso) bcToast('⚠ ' + res.aviso, 'warning');
            })
            .catch(() => bcToast('Erro de conexão.', 'danger'));
        }

        function atualizarCelulaTipo(data) {
            const cel = document.querySelector('[data-data="' + data + '"]');
            if (!cel) return;
            const old = cel.querySelector('.bc-tipo-strip');
            if (old) old.remove();
            const tipo = diasEspCalJS[data];
            if (tipo) {
                const strip = document.createElement('div');
                strip.className = 'bc-tipo-strip';
                strip.style.cssText =
                    'color:' + tipo.cor + ';border-top:2px solid ' + tipo.cor +
                    ';background:' + tipo.cor + '18;';
                strip.textContent = tipo.nome;
                const num = cel.querySelector('.bc-cal-num');
                if (num) num.after(strip); else cel.appendChild(strip);
            }
        }
    </script>
<?php endif ?>

<!-- Modal de cancelamento de série recorrente (global — lista e calendário) -->
<div class="modal fade" id="modalCancelRec" tabindex="-1" aria-labelledby="modalCancelRecLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalCancelRecLabel">
                    <i class="bi bi-arrow-repeat me-2 text-accent"></i>Cancelar agendamento recorrente
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Este agendamento faz parte de uma série recorrente. Como deseja cancelar?</p>
            </div>
            <div class="modal-footer flex-column align-items-stretch gap-2">
                <button class="btn btn-outline-danger" onclick="confirmarCancelRec('este')">
                    <i class="bi bi-x-circle me-1"></i> Somente este agendamento
                </button>
                <button class="btn btn-danger" onclick="confirmarCancelRec('futuros')">
                    <i class="bi bi-x-circle-fill me-1"></i> Este e todos os futuros
                </button>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-arrow-left me-1"></i> Não cancelar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Formulários ocultos para cancelar série (usados pelo JS em ambas as views) -->
<div style="display:none;">
    <form id="formListaCancel" method="POST">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="vista" value="lista">
        <input type="hidden" name="semana" value="<?= (int)($semanaOffset ?? 0) ?>">
        <input type="hidden" name="acao" value="cancelar_serie">
        <input type="hidden" name="id" id="listaCancelId">
        <input type="hidden" name="modo" id="listaCancelModo">
    </form>
    <form id="formCancelarSerie" method="POST">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="vista" value="calendario">
        <input type="hidden" name="mes" value="<?= h($mesSel) ?>">
        <input type="hidden" name="acao" value="cancelar_serie">
        <input type="hidden" name="id" id="serieCancelId">
        <input type="hidden" name="modo" id="serieCancelModo">
    </form>
</div>

<script>
(function(){
    var _cancelRecId  = null;
    var _cancelRecCtx = null;
    window.cancelarComOpcoesRec = function(id, ctx) {
        _cancelRecId  = id;
        _cancelRecCtx = ctx;
        new bootstrap.Modal(document.getElementById('modalCancelRec')).show();
    };
    window.confirmarCancelRec = function(modo) {
        var inst = bootstrap.Modal.getInstance(document.getElementById('modalCancelRec'));
        if (inst) inst.hide();
        if (_cancelRecCtx === 'lista') {
            document.getElementById('listaCancelId').value   = _cancelRecId;
            document.getElementById('listaCancelModo').value = modo;
            document.getElementById('formListaCancel').submit();
        } else {
            document.getElementById('serieCancelId').value   = _cancelRecId;
            document.getElementById('serieCancelModo').value = modo;
            document.getElementById('formCancelarSerie').submit();
        }
    };
}());
</script>

<!-- ══════════════════════════════════════════════════════════
     Modal: Novo agendamento (manual)
════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalNovoAg" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <form action="<?= BASE ?>/painel/salvar_agendamento.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
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
                            <input type="datetime-local" name="data_hora" id="inputDataHora" class="form-control" required>
                        </div>
                        <div class="col-5">
                            <label class="form-label">Valor (R$)</label>
                            <input type="number" name="valor" id="valorAg"
                                class="form-control" step="0.01" min="0" placeholder="0,00">
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label">Observações</label>
                        <textarea name="obs" class="form-control" rows="2" placeholder="Opcional"></textarea>
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
    // Valor automático ao escolher serviço
    document.getElementById('selectServico')?.addEventListener('change', function() {
        document.getElementById('valorAg').value = this.options[this.selectedIndex].dataset.preco || '';
    });

    // Busca de clientes
    let buscaTimer;
    document.getElementById('buscaCliente')?.addEventListener('input', function() {
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
                        btn.addEventListener('click', () => selecionarCliente(c.id, c.nome));
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

    // Abre modal após Bootstrap carregar (Bootstrap está no footer)
    window.addEventListener('load', function () {
        const params = new URLSearchParams(location.search);
        if (params.get('acao') === 'novo') {
            const dataParam = params.get('data');
            location.href = BASE_URL + '/painel/novo_agendamento.php' + (dataParam ? '?data=' + dataParam : '');
        }
    });
</script>

<!-- Modal: Bloquear horário -->
<div class="modal fade" id="modalBloqueio" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="<?= BASE ?>/painel/agenda.php?vista=<?= h($vista) ?><?= $vista === 'calendario' ? '&mes=' . h($mesSel) : '&semana=' . $semanaOffset ?>" method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="acao" value="bloquear">
                <div class="modal-header border-0 pb-0 px-4 pt-4">
                    <h6 class="modal-title fw-semibold">
                        <i class="bi bi-slash-circle me-2 text-danger"></i>Bloquear horário
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pt-3">
                    <p class="text-secondary small mb-3">Os horários dentro deste período ficarão indisponíveis para agendamento.</p>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-medium">Início</label>
                            <input type="datetime-local" name="bloq_ini" id="bloqIni" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-medium">Fim</label>
                            <input type="datetime-local" name="bloq_fim" id="bloqFim" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium">Motivo <span class="text-secondary fw-normal">(opcional)</span></label>
                            <input type="text" name="bloq_motivo" class="form-control" placeholder="Ex: consulta médica, viagem...">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 pb-4 px-4">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-slash-circle me-1"></i>Bloquear
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    // Horários de atendimento por dia da semana vindos do servidor
    const horariosAtend = <?= json_encode($horariosAtend, JSON_THROW_ON_ERROR) ?>;
    const FALLBACK_INI = '09:00', FALLBACK_FIM = '18:00';

    function dowDe(dateStr) {
        // Usa meio-dia para evitar problemas de DST
        return new Date(dateStr + 'T12:00').getDay();
    }

    function horarioDoDia(dateStr) {
        return horariosAtend[dowDe(dateStr)] || { ini: FALLBACK_INI, fim: FALLBACK_FIM };
    }

    function aplicarLimites() {
        const ini = document.getElementById('bloqIni');
        const fim = document.getElementById('bloqFim');
        if (!ini || !fim) return;

        const dIni = ini.value ? ini.value.split('T')[0] : null;
        const dFim = fim.value ? fim.value.split('T')[0] : null;

        if (dIni) {
            const h = horarioDoDia(dIni);
            ini.min = dIni + 'T' + h.ini;
            ini.max = dIni + 'T' + h.fim;
        }
        if (dFim) {
            const h = horarioDoDia(dFim);
            fim.min = (dFim === dIni && ini.value) ? ini.value : dFim + 'T' + h.ini;
            fim.max = dFim + 'T' + h.fim;
        }
        // Garante fim >= ini
        if (ini.value && fim.value && fim.value < ini.value) {
            fim.value = ini.value;
        }
    }

    document.getElementById('modalBloqueio')?.addEventListener('show.bs.modal', function () {
        const dia = document.querySelector('.bc-cal-day.bc-selecionado')?.dataset.data;
        const ini = document.getElementById('bloqIni');
        const fim = document.getElementById('bloqFim');
        if (!ini || !fim) return;
        if (dia && !ini.value) {
            const h = horarioDoDia(dia);
            ini.value = dia + 'T' + h.ini;
            fim.value = dia + 'T' + h.fim;
        }
        aplicarLimites();
    });

    // Limpa os campos ao fechar para que a próxima abertura refaça os defaults
    document.getElementById('modalBloqueio')?.addEventListener('hidden.bs.modal', function () {
        const ini = document.getElementById('bloqIni');
        const fim = document.getElementById('bloqFim');
        if (ini) { ini.value = ''; ini.min = ''; ini.max = ''; }
        if (fim) { fim.value = ''; fim.min = ''; fim.max = ''; }
    });

    document.getElementById('bloqIni')?.addEventListener('change', function () {
        aplicarLimites();
        const fim = document.getElementById('bloqFim');
        if (fim && (!fim.value || fim.value < this.value)) {
            const dIni = this.value.split('T')[0];
            fim.value = dIni + 'T' + horarioDoDia(dIni).fim;
            aplicarLimites();
        }
    });

    document.getElementById('bloqFim')?.addEventListener('change', aplicarLimites);
})();
</script>

<?php require_once __DIR__ . '/../geral/footer.php' ?>