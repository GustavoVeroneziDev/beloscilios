<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

$_bcPwaModal = true; // exibe modal de instalação PWA no dashboard

// Estatísticas do dia
try {
    $hoje = date('Y-m-d');

    $agHoje = $pdo->prepare(
        'SELECT COUNT(*) FROM Agendamentos
         WHERE DATE(DataHoraAgendamento) = :hoje
           AND StatusAgendamento NOT IN (\'cancelado\')'
    );
    $agHoje->execute([':hoje' => $hoje]);
    $totalHoje = (int) $agHoje->fetchColumn();

    $agSemana = $pdo->prepare(
        'SELECT COUNT(*) FROM Agendamentos
         WHERE DataHoraAgendamento BETWEEN :ini AND :fim
           AND StatusAgendamento NOT IN (\'cancelado\')'
    );
    $agSemana->execute([
        ':ini' => date('Y-m-d', strtotime('monday this week')),
        ':fim' => date('Y-m-d', strtotime('sunday this week')) . ' 23:59:59',
    ]);
    $totalSemana = (int) $agSemana->fetchColumn();

    $receitaMes = $pdo->prepare(
        'SELECT COALESCE(SUM(ValorCobrado),0) FROM Agendamentos
         WHERE YEAR(DataHoraAgendamento) = YEAR(CURDATE())
           AND MONTH(DataHoraAgendamento) = MONTH(CURDATE())
           AND StatusPagamento = \'pago\''
    );
    $receitaMes->execute();
    $totalReceitaMes = (float) $receitaMes->fetchColumn();

    $totalClientes = (int) $pdo->query(
        'SELECT COUNT(*) FROM Usuarios WHERE NivelAcesso = \'cliente\' AND Ativo = 1'
    )->fetchColumn();

    // Agendamentos de hoje com dados completos
    $agHojeDetalhe = $pdo->prepare(
        'SELECT a.*, u.Nome AS NomeCliente, u.Telefone,
                s.Nome AS NomeServico,
                ss.Nome AS NomeSubServico
         FROM Agendamentos a
         JOIN Usuarios u ON u.IDUsuario = a.FKCliente
         JOIN Servicos s ON s.IDServico = a.FKServico
         LEFT JOIN SubServicos ss ON ss.IDSubServico = a.FKSubServico
         WHERE DATE(a.DataHoraAgendamento) = :hoje
           AND a.StatusAgendamento NOT IN (\'cancelado\')
         ORDER BY a.DataHoraAgendamento ASC'
    );
    $agHojeDetalhe->execute([':hoje' => $hoje]);
    $agHojeDetalhe = $agHojeDetalhe->fetchAll();

    // Próximos 5 agendamentos (excluindo hoje)
    $proximos = $pdo->prepare(
        'SELECT a.DataHoraAgendamento, u.Nome AS NomeCliente,
                s.Nome AS NomeServico, a.StatusAgendamento
         FROM Agendamentos a
         JOIN Usuarios u ON u.IDUsuario = a.FKCliente
         JOIN Servicos s ON s.IDServico = a.FKServico
         WHERE a.DataHoraAgendamento > :amanha
           AND a.StatusAgendamento NOT IN (\'cancelado\')
         ORDER BY a.DataHoraAgendamento ASC
         LIMIT 5'
    );
    $proximos->execute([':amanha' => $hoje . ' 23:59:59']);
    $proximos = $proximos->fetchAll();
} catch (PDOException $e) {
    error_log('[PainelDash] ' . $e->getMessage());
    $totalHoje = $totalSemana = $totalClientes = 0;
    $totalReceitaMes = 0.0;
    $agHojeDetalhe = $proximos = [];
}

$paginaTitulo = 'Dashboard';
$areaAtual    = 'painel';
require_once __DIR__ . '/../geral/header.php';
?>

<h4 class="fw-bold mb-1">Dashboard</h4>
<p class="text-secondary small mb-4"><?= date('l, d \d\e F \d\e Y') ?></p>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <?php
    $stats = [
        ['bi-calendar-check', '#B07D62', 'rgba(176,125,98,.15)',  'Hoje',            $totalHoje,                      'agendamentos'],
        ['bi-calendar-week',  '#6B9E7A', 'rgba(107,158,122,.15)', 'Esta semana',      $totalSemana,                   'agendamentos'],
        ['bi-people',         '#5B8FD4', 'rgba(91,143,212,.15)',  'Clientes ativos',  $totalClientes,                 'cadastradas'],
        ['bi-cash-stack',     '#D4963A', 'rgba(212,150,58,.15)',  'Receita do mês',   formatarMoeda($totalReceitaMes), ''],
    ];
    foreach ($stats as [$icon, $color, $bg, $label, $valor, $sub]):
    ?>
        <div class="col-6 col-xl-3">
            <div class="card stat-card">
                <div class="stat-icon" style="background:<?= $bg ?>;color:<?= $color ?>">
                    <i class="bi <?= $icon ?>"></i>
                </div>
                <div>
                    <div class="fw-bold fs-4 lh-1"><?= is_numeric($valor) ? number_format((float)$valor) : $valor ?></div>
                    <div class="text-secondary small"><?= $label ?></div>
                    <?php if ($sub): ?><div class="text-secondary" style="font-size:.72rem;"><?= $sub ?></div><?php endif ?>
                </div>
            </div>
        </div>
    <?php endforeach ?>
</div>

<div class="row g-4">
    <!-- Agenda de hoje -->
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between px-4 py-3">
                <span><i class="bi bi-calendar-day me-2 text-accent"></i>Agenda de hoje
                    <span class="badge ms-2" style="background:var(--accent);"><?= $totalHoje ?></span>
                </span>
                <a href="<?= BASE ?>/painel/agenda.php" class="btn btn-sm btn-outline-accent">
                    Ver agenda completa
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($agHojeDetalhe)): ?>
                    <div class="text-center py-5 text-secondary">
                        <i class="bi bi-sun fs-1 d-block mb-2 opacity-25"></i>
                        <p class="mb-0">Nenhum agendamento para hoje.</p>
                    </div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($agHojeDetalhe as $ag): ?>
                            <li class="list-group-item px-4 py-3">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="text-accent fw-bold" style="min-width:42px;font-size:1rem;">
                                        <?= date('H:i', strtotime($ag['DataHoraAgendamento'])) ?>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold"><?= h($ag['NomeCliente']) ?></div>
                                        <div class="small text-secondary">
                                            <?= h($ag['NomeSubServico'] ?? $ag['NomeServico']) ?>
                                            <?php if ($ag['ValorCobrado']): ?>
                                                &nbsp;·&nbsp; <?= formatarMoeda((float)$ag['ValorCobrado']) ?>
                                            <?php endif ?>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <?= labelStatus($ag['StatusAgendamento']) ?>
                                        <?php if ($ag['Telefone']): ?>
                                            <a href="https://wa.me/<?= h($ag['Telefone']) ?>" target="_blank"
                                                class="btn btn-sm btn-outline-success" title="WhatsApp">
                                                <i class="bi bi-whatsapp"></i>
                                            </a>
                                        <?php endif ?>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach ?>
                    </ul>
                <?php endif ?>
            </div>
        </div>
    </div>

    <!-- Próximos + Ações rápidas -->
    <div class="col-lg-4 d-flex flex-column gap-4">
        <!-- Ações rápidas -->
        <div class="card p-4">
            <h6 class="fw-semibold mb-3"><i class="bi bi-lightning me-2 text-accent"></i>Ações rápidas</h6>
            <div class="d-grid gap-2">
                <a href="<?= BASE ?>/painel/agenda.php?acao=novo" class="btn btn-accent">
                    <i class="bi bi-calendar-plus me-2"></i>Novo agendamento
                </a>
                <a href="<?= BASE ?>/painel/clientes.php?acao=novo" class="btn btn-outline-accent">
                    <i class="bi bi-person-plus me-2"></i>Cadastrar cliente
                </a>
                <a href="<?= BASE ?>/painel/relatorio.php" class="btn btn-outline-secondary">
                    <i class="bi bi-bar-chart me-2"></i>Ver financeiro
                </a>
            </div>
        </div>

        <!-- Próximos agendamentos -->
        <?php if (!empty($proximos)): ?>
            <div class="card flex-grow-1">
                <div class="card-header px-4 py-3">
                    <i class="bi bi-calendar3 me-2 text-accent"></i>Próximos
                </div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($proximos as $p): ?>
                        <li class="list-group-item px-4 py-2">
                            <div class="small fw-medium"><?= h($p['NomeCliente']) ?></div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="small text-secondary">
                                    <?= date('d/m H:i', strtotime($p['DataHoraAgendamento'])) ?>
                                </span>
                                <?= labelStatus($p['StatusAgendamento']) ?>
                            </div>
                        </li>
                    <?php endforeach ?>
                </ul>
            </div>
        <?php endif ?>
    </div>
</div>

<?php require_once __DIR__ . '/../geral/footer.php' ?>