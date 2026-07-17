<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

$anoAtual = date('Y');
$mesAtual = date('n');

$anoSel = (int)($_GET['ano'] ?? $anoAtual);
$mesSel = (int)($_GET['mes'] ?? $mesAtual);
if ($mesSel < 1 || $mesSel > 12) $mesSel = $mesAtual;

$iniMes = sprintf('%04d-%02d-01', $anoSel, $mesSel);
$fimMes = date('Y-m-t', strtotime($iniMes));

try {
    // Resumo do mês
    $resumo = $pdo->prepare(
        'SELECT
           COUNT(*) AS Total,
           SUM(CASE WHEN StatusAgendamento = \'concluido\' THEN 1 ELSE 0 END) AS Concluidos,
           SUM(CASE WHEN StatusAgendamento = \'cancelado\' THEN 1 ELSE 0 END) AS Cancelados,
           SUM(CASE WHEN StatusPagamento = \'pago\' THEN COALESCE(ValorCobrado,0) ELSE 0 END) AS Recebido,
           SUM(CASE WHEN StatusPagamento = \'pendente\'
                     AND StatusAgendamento NOT IN (\'cancelado\') THEN COALESCE(ValorCobrado,0) ELSE 0 END) AS APagar
         FROM Agendamentos
         WHERE DataHoraAgendamento BETWEEN :ini AND :fim'
    );
    $resumo->execute([':ini' => $iniMes . ' 00:00:00', ':fim' => $fimMes . ' 23:59:59']);
    $resumo = $resumo->fetch();

    // Por serviço
    $porServico = $pdo->prepare(
        'SELECT s.Nome, COUNT(*) AS Qtd,
                SUM(CASE WHEN a.StatusPagamento=\'pago\' THEN COALESCE(a.ValorCobrado,0) ELSE 0 END) AS Receita
         FROM Agendamentos a
         JOIN Servicos s ON s.IDServico = a.FKServico
         WHERE a.DataHoraAgendamento BETWEEN :ini AND :fim
           AND a.StatusAgendamento != \'cancelado\'
         GROUP BY s.IDServico, s.Nome
         ORDER BY Receita DESC'
    );
    $porServico->execute([':ini' => $iniMes . ' 00:00:00', ':fim' => $fimMes . ' 23:59:59']);
    $porServico = $porServico->fetchAll();

    // Agendamentos por dia (para mini gráfico)
    $porDia = $pdo->prepare(
        'SELECT DAY(DataHoraAgendamento) AS Dia, COUNT(*) AS Qtd
         FROM Agendamentos
         WHERE DataHoraAgendamento BETWEEN :ini AND :fim
           AND StatusAgendamento != \'cancelado\'
         GROUP BY Dia ORDER BY Dia'
    );
    $porDia->execute([':ini' => $iniMes . ' 00:00:00', ':fim' => $fimMes . ' 23:59:59']);
    $porDia = $porDia->fetchAll(PDO::FETCH_KEY_PAIR);

    // Histórico do mês: concluídos ou com pagamento pago (para permitir reversão)
    $historico = $pdo->prepare(
        'SELECT a.IDAgendamento, a.DataHoraAgendamento, a.StatusAgendamento,
                a.StatusPagamento, a.ValorCobrado,
                u.Nome AS NomeCliente, s.Nome AS NomeServico
         FROM Agendamentos a
         JOIN Usuarios u ON u.IDUsuario = a.FKCliente
         JOIN Servicos s ON s.IDServico = a.FKServico
         WHERE a.DataHoraAgendamento BETWEEN :ini AND :fim
           AND a.StatusAgendamento != \'cancelado\'
           AND (a.StatusAgendamento = \'concluido\' OR a.StatusPagamento = \'pago\')
         ORDER BY a.DataHoraAgendamento DESC
         LIMIT 50'
    );
    $historico->execute([':ini' => $iniMes . ' 00:00:00', ':fim' => $fimMes . ' 23:59:59']);
    $historico = $historico->fetchAll();

    // Últimos agendamentos do mês com pagamento pendente
    $pendentes = $pdo->prepare(
        'SELECT a.DataHoraAgendamento, u.Nome AS NomeCliente, u.Telefone,
                s.Nome AS NomeServico, a.ValorCobrado, a.IDAgendamento
         FROM Agendamentos a
         JOIN Usuarios u ON u.IDUsuario = a.FKCliente
         JOIN Servicos s ON s.IDServico = a.FKServico
         WHERE a.DataHoraAgendamento BETWEEN :ini AND :fim
           AND a.StatusPagamento = \'pendente\'
           AND a.StatusAgendamento IN (\'confirmado\',\'concluido\')
         ORDER BY a.DataHoraAgendamento DESC
         LIMIT 20'
    );
    $pendentes->execute([':ini' => $iniMes . ' 00:00:00', ':fim' => $fimMes . ' 23:59:59']);
    $pendentes = $pendentes->fetchAll();
} catch (PDOException $e) {
    error_log('[Relatorio] ' . $e->getMessage());
    $resumo = $porServico = $pendentes = $historico = [];
    $porDia = [];
}

$meses = [
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

$paginaTitulo = 'Relatório Financeiro';
$areaAtual    = 'painel';
require_once __DIR__ . '/../geral/header.php';
?>

<!-- Cabeçalho + filtros -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
        <h4 class="fw-bold mb-0">Financeiro</h4>
        <p class="text-secondary small mb-0"><?= $meses[$mesSel] ?>/<?= $anoSel ?></p>
    </div>
    <form method="GET" class="d-flex gap-2 align-items-center">
        <select name="mes" class="form-select form-select-sm" style="width:130px;">
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $m === $mesSel ? 'selected' : '' ?>><?= $meses[$m] ?></option>
            <?php endfor ?>
        </select>
        <select name="ano" class="form-select form-select-sm" style="width:90px;">
            <?php for ($a = $anoAtual - 2; $a <= $anoAtual + 1; $a++): ?>
                <option value="<?= $a ?>" <?= $a === $anoSel ? 'selected' : '' ?>><?= $a ?></option>
            <?php endfor ?>
        </select>
        <button class="btn btn-accent btn-sm px-3">
            <i class="bi bi-funnel me-1"></i>Filtrar
        </button>
    </form>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['bi-cash-stack',    '#B07D62', 'rgba(176,125,98,.15)',  'Total recebido',  formatarMoeda((float)($resumo['Recebido']   ?? 0)), 'no mês'],
        ['bi-hourglass',     '#D4963A', 'rgba(212,150,58,.15)',  'A receber',        formatarMoeda((float)($resumo['APagar']    ?? 0)), 'pendente'],
        ['bi-check2-circle', '#6B9E7A', 'rgba(107,158,122,.15)', 'Concluídos',      (int)($resumo['Concluidos']  ?? 0),                'agendamentos'],
        ['bi-x-circle',      '#C0604A', 'rgba(192,96,74,.15)',   'Cancelamentos',   (int)($resumo['Cancelados']  ?? 0),                'agendamentos'],
    ];
    foreach ($cards as [$icon, $color, $bg, $label, $valor, $sub]):
    ?>
        <div class="col-6 col-xl-3">
            <div class="card stat-card h-100">
                <div class="stat-card-top">
                    <span class="stat-card-label"><?= $label ?></span>
                    <div class="stat-icon" style="background:<?= $bg ?>;color:<?= $color ?>">
                        <i class="bi <?= $icon ?>"></i>
                    </div>
                </div>
                <div>
                    <div class="stat-card-valor"><?= is_numeric($valor) ? number_format((float)$valor) : $valor ?></div>
                    <div class="stat-card-sub"><?= $sub ?></div>
                </div>
            </div>
        </div>
    <?php endforeach ?>
</div>

<div class="row g-4">
    <!-- Por serviço -->
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header px-4 py-3">
                <i class="bi bi-bar-chart me-2 text-accent"></i>Receita por serviço
            </div>
            <div class="card-body p-0">
                <?php if (empty($porServico)): ?>
                    <div class="text-center py-5 text-secondary">Sem dados neste período.</div>
                <?php else: ?>
                    <?php
                    $totalReceita = array_sum(array_column($porServico, 'Receita'));
                    ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($porServico as $ps): ?>
                            <?php $pct = $totalReceita > 0 ? round($ps['Receita'] / $totalReceita * 100) : 0; ?>
                            <li class="list-group-item px-4 py-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span class="fw-medium small"><?= h($ps['Nome']) ?></span>
                                    <span class="text-accent fw-bold small"><?= formatarMoeda((float)$ps['Receita']) ?></span>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height:6px;">
                                        <div class="progress-bar" style="width:<?= $pct ?>%;background:var(--accent)"></div>
                                    </div>
                                    <span class="text-secondary" style="font-size:.75rem;"><?= $ps['Qtd'] ?>x</span>
                                </div>
                            </li>
                        <?php endforeach ?>
                    </ul>
                <?php endif ?>
            </div>
        </div>
    </div>

    <!-- Pagamentos pendentes -->
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header px-4 py-3">
                <i class="bi bi-clock me-2 text-warning"></i>Pagamentos pendentes
            </div>
            <div class="card-body p-0">
                <?php if (empty($pendentes)): ?>
                    <div class="text-center py-5 text-secondary">
                        <i class="bi bi-check2-circle fs-1 d-block mb-2 text-success opacity-50"></i>
                        <p>Nenhum pagamento pendente!</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead style="background:var(--bg-hover);">
                                <tr>
                                    <th class="px-4 py-2">Data</th>
                                    <th>Cliente</th>
                                    <th>Serviço</th>
                                    <th>Valor</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendentes as $p): ?>
                                    <tr>
                                        <td class="px-4 small"><?= date('d/m', strtotime($p['DataHoraAgendamento'])) ?></td>
                                        <td class="small"><?= h($p['NomeCliente']) ?></td>
                                        <td class="small"><?= h($p['NomeServico']) ?></td>
                                        <td class="fw-medium small text-accent">
                                            <?= formatarMoeda((float)($p['ValorCobrado'] ?? 0)) ?>
                                        </td>
                                        <td>
                                            <form method="POST" action="<?= BASE ?>/painel/marcar_pago.php">
                                                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                                                <input type="hidden" name="id" value="<?= h($p['IDAgendamento']) ?>">
                                                <input type="hidden" name="redirect" value="<?= BASE ?>/painel/relatorio.php">
                                                <button class="btn btn-sm btn-outline-success" title="Marcar como pago">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($historico)): ?>
<!-- Histórico do mês (reversões) -->
<div class="mt-4">
    <div class="card">
        <div class="card-header px-4 py-3 d-flex align-items-center justify-content-between">
            <span>
                <i class="bi bi-clock-history me-2 text-accent"></i>Histórico do mês
                <span class="text-secondary small ms-2 fw-normal">— clique para desfazer ações acidentais</span>
            </span>
            <button class="btn btn-sm btn-outline-secondary" type="button"
                    data-bs-toggle="collapse" data-bs-target="#collapseHistorico" aria-expanded="false">
                <i class="bi bi-chevron-down"></i>
            </button>
        </div>
        <div class="collapse" id="collapseHistorico">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead style="background:var(--bg-hover);">
                        <tr>
                            <th class="px-4 py-2">Data</th>
                            <th>Cliente</th>
                            <th>Serviço</th>
                            <th>Valor</th>
                            <th>Status</th>
                            <th>Pagamento</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historico as $h): ?>
                            <tr>
                                <td class="px-4 small"><?= date('d/m', strtotime($h['DataHoraAgendamento'])) ?></td>
                                <td class="small"><?= h($h['NomeCliente']) ?></td>
                                <td class="small"><?= h($h['NomeServico']) ?></td>
                                <td class="small fw-medium text-accent">
                                    <?= formatarMoeda((float)($h['ValorCobrado'] ?? 0)) ?>
                                </td>
                                <td><?= labelStatus($h['StatusAgendamento']) ?></td>
                                <td>
                                    <?php if ($h['StatusPagamento'] === 'pago'): ?>
                                        <span class="badge bg-success">Pago</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Pendente</span>
                                    <?php endif ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <?php if ($h['StatusAgendamento'] === 'concluido'): ?>
                                            <form method="POST" action="<?= BASE ?>/painel/reverter_status.php"
                                                  data-confirm="Reabrir este atendimento como confirmado?"
                                                  data-confirm-label="Reabrir">
                                                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                                                <input type="hidden" name="acao" value="reabrir">
                                                <input type="hidden" name="id" value="<?= h($h['IDAgendamento']) ?>">
                                                <input type="hidden" name="redirect"
                                                       value="<?= BASE ?>/painel/relatorio.php?mes=<?= $mesSel ?>&amp;ano=<?= $anoSel ?>">
                                                <button class="btn btn-sm btn-outline-primary" title="Reabrir como confirmado">
                                                    <i class="bi bi-arrow-counterclockwise"></i>
                                                </button>
                                            </form>
                                        <?php endif ?>
                                        <?php if ($h['StatusPagamento'] === 'pago'): ?>
                                            <form method="POST" action="<?= BASE ?>/painel/reverter_status.php"
                                                  data-confirm="Estornar este pagamento para pendente?"
                                                  data-confirm-label="Estornar">
                                                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                                                <input type="hidden" name="acao" value="estornar_pagamento">
                                                <input type="hidden" name="id" value="<?= h($h['IDAgendamento']) ?>">
                                                <input type="hidden" name="redirect"
                                                       value="<?= BASE ?>/painel/relatorio.php?mes=<?= $mesSel ?>&amp;ano=<?= $anoSel ?>">
                                                <button class="btn btn-sm btn-outline-warning" title="Estornar pagamento">
                                                    <i class="bi bi-arrow-counterclockwise"></i> Estornar
                                                </button>
                                            </form>
                                        <?php endif ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif ?>

<?php require_once __DIR__ . '/../geral/footer.php' ?>