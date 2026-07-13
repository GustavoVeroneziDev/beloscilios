<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('cliente');

$uid = $_SESSION['usuario_id'];

// Cancela um reagendamento em curso
if (!empty($_GET['cancelar_reagendamento'])) {
    unset($_SESSION['reagendar_id']);
}

$pag  = max(1, (int)($_GET['pag'] ?? 1));
$porPag = 10;
$offset = ($pag - 1) * $porPag;

try {
    $total = $pdo->prepare(
        'SELECT COUNT(*) FROM Agendamentos WHERE FKCliente = :id'
    );
    $total->execute([':id' => $uid]);
    $totalReg = (int) $total->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT a.*, s.Nome AS NomeServico, ss.Nome AS NomeSubServico
         FROM Agendamentos a
         JOIN Servicos s ON s.IDServico = a.FKServico
         LEFT JOIN SubServicos ss ON ss.IDSubServico = a.FKSubServico
         WHERE a.FKCliente = :id
         ORDER BY a.DataHoraAgendamento DESC
         LIMIT :lim OFFSET :off'
    );
    $stmt->bindValue(':id',  $uid);
    $stmt->bindValue(':lim', $porPag, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $agendamentos = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('[Historico] ' . $e->getMessage());
    $agendamentos = [];
    $totalReg = 0;
}

$totalPag = max(1, (int) ceil($totalReg / $porPag));

$paginaTitulo = 'Histórico';
$areaAtual    = 'cliente';
require_once __DIR__ . '/../geral/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-4">
    <a href="<?= BASE ?>/usuario/perfil.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h4 class="fw-bold mb-0">Histórico de procedimentos</h4>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($agendamentos)): ?>
        <div class="text-center py-5 text-secondary">
            <i class="bi bi-calendar-x fs-1 d-block mb-2 opacity-25"></i>
            <p>Nenhum procedimento encontrado.</p>
            <a href="<?= BASE ?>/agendamento/index.php" class="btn btn-accent btn-sm">Fazer primeiro agendamento</a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead style="background:var(--bg-hover);">
                    <tr>
                        <th class="px-4 py-3">Data</th>
                        <th>Serviço</th>
                        <th>Valor</th>
                        <th>Status</th>
                        <th>Pagamento</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($agendamentos as $ag):
                        $futuro     = strtotime($ag['DataHoraAgendamento']) > time();
                        $reagendavel= $futuro && in_array($ag['StatusAgendamento'], ['pendente','confirmado']);
                    ?>
                    <tr>
                        <td class="px-4">
                            <div class="fw-medium"><?= date('d/m/Y', strtotime($ag['DataHoraAgendamento'])) ?></div>
                            <div class="small text-secondary"><?= date('H:i', strtotime($ag['DataHoraAgendamento'])) ?></div>
                        </td>
                        <td><?= h($ag['NomeSubServico'] ?? $ag['NomeServico']) ?></td>
                        <td><?= $ag['ValorCobrado'] ? formatarMoeda((float)$ag['ValorCobrado']) : '<span class="text-secondary">—</span>' ?></td>
                        <td><?= labelStatus($ag['StatusAgendamento']) ?></td>
                        <td><?= labelStatusPag($ag['StatusPagamento']) ?></td>
                        <td class="pe-3">
                            <?php if ($reagendavel): ?>
                            <a href="<?= BASE ?>/agendamento/reagendar.php?id=<?= h($ag['IDAgendamento']) ?>"
                               class="btn btn-sm btn-outline-accent">
                                <i class="bi bi-arrow-repeat me-1"></i>Reagendar
                            </a>
                            <?php endif ?>
                        </td>
                    </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPag > 1): ?>
        <div class="d-flex justify-content-center py-3">
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($p = 1; $p <= $totalPag; $p++): ?>
                    <li class="page-item <?= $p === $pag ? 'active' : '' ?>">
                        <a class="page-link" href="?pag=<?= $p ?>"><?= $p ?></a>
                    </li>
                    <?php endfor ?>
                </ul>
            </nav>
        </div>
        <?php endif ?>
        <?php endif ?>
    </div>
</div>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
