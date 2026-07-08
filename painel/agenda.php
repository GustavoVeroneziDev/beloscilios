<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

$vista = $_GET['vista'] ?? 'lista';

// ── Ação POST (confirmar / cancelar / concluir) ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
        redirecionarComMensagem(BASE . '/painel/agenda.php', 'Token inválido.', 'danger');
    }
    $acao = $_POST['acao'] ?? '';
    $id   = $_POST['id']   ?? '';

    $statusMap = ['confirmar' => 'confirmado', 'cancelar' => 'cancelado', 'concluir' => 'concluido'];
    if (isset($statusMap[$acao]) && $id) {
        try {
            $pdo->prepare('UPDATE Agendamentos SET StatusAgendamento = :status WHERE IDAgendamento = :id')
                ->execute([':status' => $statusMap[$acao], ':id' => $id]);

            // Redireciona preservando vista/mês/semana ativos
            $params = array_filter([
                'vista'  => $_GET['vista']  ?? null,
                'mes'    => $_GET['mes']    ?? null,
                'semana' => $_GET['semana'] ?? null,
            ]);
            $qs = $params ? '?' . http_build_query($params) : '';
            redirecionarComMensagem(BASE . '/painel/agenda.php' . $qs, 'Status atualizado.', 'success');
        } catch (PDOException $e) {
            error_log('[Agenda] ' . $e->getMessage());
            redirecionarComMensagem(BASE . '/painel/agenda.php', 'Erro ao atualizar.', 'danger');
        }
    }
}

$servicos = $pdo->query('SELECT IDServico, Nome, DuracaoMinutos, Preco FROM Servicos WHERE Ativo = 1 ORDER BY Ordem')->fetchAll();

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

    $porDia = [];
    foreach ($agendamentos as $ag) {
        $dia = date('Y-m-d', strtotime($ag['DataHoraAgendamento']));
        $porDia[$dia][] = $ag;
    }
}

// ── VISÃO CALENDÁRIO (mensal) ─────────────────────────────────
if ($vista === 'calendario') {
    $mesSel = $_GET['mes'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $mesSel)) $mesSel = date('Y-m');

    $mesTs       = strtotime($mesSel . '-01');
    $mesPrev     = date('Y-m', strtotime('-1 month', $mesTs));
    $mesNext     = date('Y-m', strtotime('+1 month', $mesTs));
    $mesNome     = strftime('%B de %Y', $mesTs);
    // Fallback para strftime sem locale
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
                    a.StatusPagamento, u.Nome AS NomeCliente, u.Telefone,
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
        $out .= '<form method="POST" class="d-inline"
                      data-confirm="Confirma o cancelamento deste agendamento?"
                      data-confirm-label="Cancelar agendamento">
                    <input type="hidden" name="csrf_token" value="' . $csrfToken . '">
                    <input type="hidden" name="acao" value="cancelar">
                    <input type="hidden" name="id" value="' . h($ag['IDAgendamento']) . '">
                    <button class="btn btn-sm btn-outline-danger" title="Cancelar">
                        <i class="bi bi-x-lg"></i></button></form>';
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
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-calendar3 me-2 text-accent"></i>Agenda</h4>

    <div class="d-flex align-items-center gap-2 flex-wrap">
        <!-- Toggle Lista / Calendário -->
        <div class="btn-group bc-vista-toggle" role="group" aria-label="Visão da agenda">
            <a href="?vista=lista<?= $vista === 'lista' ? '&semana=' . ($semanaOffset ?? 0) : '' ?>"
                class="btn btn-sm <?= $vista !== 'calendario' ? 'btn-accent' : 'btn-outline-secondary' ?>">
                <i class="bi bi-list-ul me-1"></i>Lista
            </a>
            <a href="?vista=calendario&mes=<?= $vista === 'calendario' ? h($mesSel) : date('Y-m') ?>"
                class="btn btn-sm <?= $vista === 'calendario' ? 'btn-accent' : 'btn-outline-secondary' ?>">
                <i class="bi bi-grid-3x3 me-1"></i>Calendário
            </a>
        </div>

        <?php if ($vista !== 'calendario'): ?>
            <!-- Navegação semanal -->
            <a href="?vista=lista&semana=<?= ($semanaOffset ?? 0) - 1 ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-chevron-left"></i>
            </a>
            <span class="btn btn-outline-secondary btn-sm disabled" style="min-width:120px;text-align:center;">
                <?= date('d/m', $inicioPeriodo) ?> – <?= date('d/m', $fimPeriodo) ?>
            </span>
            <a href="?vista=lista&semana=<?= ($semanaOffset ?? 0) + 1 ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-chevron-right"></i>
            </a>
            <?php if (($semanaOffset ?? 0) !== 0): ?>
                <a href="?vista=lista&semana=0" class="btn btn-outline-accent btn-sm">Hoje</a>
            <?php endif ?>
        <?php else: ?>
            <!-- Navegação mensal -->
            <a href="?vista=calendario&mes=<?= h($mesPrev) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-chevron-left"></i>
            </a>
            <span class="btn btn-outline-secondary btn-sm disabled" style="min-width:150px;text-align:center;">
                <?= h($mesNome) ?>
            </span>
            <a href="?vista=calendario&mes=<?= h($mesNext) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-chevron-right"></i>
            </a>
            <?php if ($mesSel !== date('Y-m')): ?>
                <a href="?vista=calendario&mes=<?= date('Y-m') ?>" class="btn btn-outline-accent btn-sm">Hoje</a>
            <?php endif ?>
        <?php endif ?>

        <button class="btn btn-accent btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovoAg">
            <i class="bi bi-plus-lg me-1"></i> Novo
        </button>
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
        $ags   = $porDia[$key] ?? [];
        $eHoje = $key === date('Y-m-d');
    ?>
        <div class="card mb-3 <?= $eHoje ? 'border-accent' : '' ?>">
            <div class="card-header d-flex align-items-center gap-2 px-4 py-2"
                style="<?= $eHoje ? 'background:var(--accent-light)' : '' ?>">
                <span class="fw-semibold <?= $eHoje ? 'text-accent' : '' ?>"><?= $diasSemana[$d] ?></span>
                <span class="text-secondary small"><?= date('d/m', $ts) ?></span>
                <?php if ($eHoje): ?><span class="badge ms-1" style="background:var(--accent);">Hoje</span><?php endif ?>
                <span class="ms-auto badge bg-secondary"><?= count($ags) ?> ag.</span>
            </div>
            <?php if (empty($ags)): ?>
                <div class="text-center py-3 text-secondary small">Sem agendamentos</div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($ags as $ag): ?>
                        <li class="list-group-item px-3 px-md-4 py-2">
                            <div class="d-flex align-items-center gap-2 gap-md-3 flex-wrap">
                                <span class="fw-bold text-accent" style="min-width:40px;">
                                    <?= date('H:i', strtotime($ag['DataHoraAgendamento'])) ?>
                                </span>
                                <div class="flex-grow-1">
                                    <span class="fw-medium"><?= h($ag['NomeCliente']) ?></span>
                                    <span class="text-secondary small ms-1 d-block d-md-inline">
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
                        $eHojeDia  = ($key === $hoje);
                        $classes   = 'bc-cal-day' . ($eHojeDia ? ' bc-hoje' : '') . (empty($agsNoDia) ? ' bc-sem-ag' : '');
                    ?>
                        <div class="<?= $classes ?>"
                            data-data="<?= $key ?>"
                            role="button"
                            tabindex="0"
                            aria-label="<?= $dia . ' — ' . count($agsNoDia) . ' agendamento(s)' ?>"
                            onclick="mostrarDia('<?= $key ?>', <?= $dia ?>)"
                            onkeydown="if(event.key==='Enter')mostrarDia('<?= $key ?>', <?= $dia ?>)">
                            <div class="bc-cal-num"><?= $dia ?></div>
                            <?php if (!empty($agsNoDia)): ?>
                                <div class="bc-cal-dots">
                                    <?php foreach (array_slice($agsNoDia, 0, 5) as $ag): ?>
                                        <span class="bc-cal-dot <?= h($ag['StatusAgendamento']) ?>"></span>
                                    <?php endforeach ?>
                                </div>
                                <?php if (count($agsNoDia) > 0): ?>
                                    <span class="bc-cal-count"><?= count($agsNoDia) ?></span>
                                <?php endif ?>
                            <?php endif ?>
                        </div>
            <?php
                    endif;
                endforeach;
            endforeach;
            ?>
        </div>
    </div>

    <!-- Painel de detalhes do dia (preenchido pelo JS) -->
    <div id="painelDia" class="bc-dia-detalhe p-0 mb-3" style="display:none;">
        <div class="card-header d-flex align-items-center justify-content-between px-4 py-3">
            <h6 class="fw-bold mb-0" id="tituloDia"></h6>
            <div class="d-flex gap-2">
                <a id="btnNovoDiaLink" href="#" class="btn btn-accent btn-sm">
                    <i class="bi bi-plus me-1"></i>Novo agendamento
                </a>
                <button class="btn btn-sm btn-outline-secondary" onclick="fecharDia()">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </div>
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
        const dadosCal = <?= json_encode($calJson, JSON_UNESCAPED_UNICODE) ?>;
        const BASE_URL = '<?= BASE ?>';
        const MES_SEL = '<?= h($mesSel) ?>';

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

        function mostrarDia(data, dia) {
            // Remove seleção anterior
            document.querySelectorAll('.bc-cal-day.bc-selecionado')
                .forEach(el => el.classList.remove('bc-selecionado'));
            const cel = document.querySelector('[data-data="' + data + '"]');
            if (cel) cel.classList.add('bc-selecionado');

            diaAberto = data;
            const ags = dadosCal[data] || [];

            // Título
            const partes = data.split('-');
            document.getElementById('tituloDia').textContent =
                partes[2] + '/' + partes[1] + '/' + partes[0] +
                ' — ' + ags.length + (ags.length === 1 ? ' agendamento' : ' agendamentos');

            // Link novo agendamento com data pré-preenchida
            document.getElementById('btnNovoDiaLink').href =
                BASE_URL + '/painel/agenda.php?vista=calendario&mes=' + MES_SEL + '&acao=novo&data=' + data;

            // Conteúdo
            const cont = document.getElementById('conteudoDia');
            if (ags.length === 0) {
                cont.innerHTML = '<p class="text-center text-secondary py-4 mb-0">Nenhum agendamento neste dia.</p>';
            } else {
                const ul = document.createElement('ul');
                ul.className = 'list-group list-group-flush';
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
                        botoes += '<button class="btn btn-sm btn-outline-danger" onclick="acaoAg(\'cancelar\',\'' + ag.id + '\')" title="Cancelar"><i class="bi bi-x-lg"></i></button>';
                    }
                    if (ag.status === 'confirmado') {
                        botoes += '<button class="btn btn-sm btn-outline-secondary" onclick="acaoAg(\'concluir\',\'' + ag.id + '\')" title="Concluído"><i class="bi bi-check2-all"></i></button>';
                    }

                    li.innerHTML =
                        '<div class="d-flex align-items-center gap-2 flex-wrap">' +
                        '<span class="fw-bold text-accent" style="min-width:40px;">' + escHtml(ag.hora) + '</span>' +
                        '<div class="flex-grow-1">' +
                        '<span class="fw-medium">' + escHtml(ag.nome) + '</span>' +
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
    </script>
<?php endif ?>

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

    // Abre modal e pré-preenche data quando ?acao=novo&data=YYYY-MM-DD
    (function() {
        const params = new URLSearchParams(location.search);
        if (params.get('acao') === 'novo') {
            const dataParam = params.get('data');
            if (dataParam) {
                const inp = document.getElementById('inputDataHora');
                if (inp) inp.value = dataParam + 'T09:00';
            }
            new bootstrap.Modal(document.getElementById('modalNovoAg')).show();
        }
    })();
</script>

<?php require_once __DIR__ . '/../geral/footer.php' ?>