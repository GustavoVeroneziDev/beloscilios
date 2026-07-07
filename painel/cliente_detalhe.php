<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

$id = trim($_GET['id'] ?? '');
if (!$id) {
    redirecionarComMensagem(BASE . '/painel/clientes.php', 'Cliente não encontrada.', 'warning');
}

try {
    $clienteStmt = $pdo->prepare(
        'SELECT * FROM Usuarios WHERE IDUsuario = :id AND NivelAcesso = \'cliente\' LIMIT 1'
    );
    $clienteStmt->execute([':id' => $id]);
    $cliente = $clienteStmt->fetch();
    if (!$cliente) {
        redirecionarComMensagem(BASE . '/painel/clientes.php', 'Cliente não encontrada.', 'warning');
    }

    $historico = $pdo->prepare(
        'SELECT a.*, s.Nome AS NomeServico, ss.Nome AS NomeSubServico
         FROM Agendamentos a
         JOIN Servicos s ON s.IDServico = a.FKServico
         LEFT JOIN SubServicos ss ON ss.IDSubServico = a.FKSubServico
         WHERE a.FKCliente = :id
         ORDER BY a.DataHoraAgendamento DESC
         LIMIT 50'
    );
    $historico->execute([':id' => $id]);
    $historico = $historico->fetchAll();

    $totalGasto = array_sum(array_column(
        array_filter($historico, fn($a) => $a['StatusPagamento'] === 'pago'),
        'ValorCobrado'
    ));
} catch (PDOException $e) {
    error_log('[ClienteDetalhe] ' . $e->getMessage());
    $historico  = [];
    $totalGasto = 0;
}

$paginaTitulo = h($cliente['Nome']);
$areaAtual    = 'painel';
require_once __DIR__ . '/../geral/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-4">
    <a href="<?= BASE ?>/painel/clientes.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h4 class="fw-bold mb-0"><?= h($cliente['Nome']) ?></h4>
</div>

<div class="row g-4">
    <!-- Dados pessoais -->
    <div class="col-md-4">
        <div class="card p-4">
            <div class="text-center mb-3">
                <div style="font-size:3rem;">👤</div>
                <h5 class="fw-bold mb-0"><?= h($cliente['Nome']) ?></h5>
                <p class="small text-secondary mb-0">Cliente desde <?= formatarData($cliente['MomentoRegistro']) ?></p>
            </div>
            <dl class="mb-3">
                <dt class="small text-secondary">E-mail</dt>
                <dd><?= h($cliente['Email']) ?></dd>
                <dt class="small text-secondary">WhatsApp</dt>
                <dd>
                    <?php if ($cliente['Telefone']): ?>
                    <a href="https://wa.me/<?= h($cliente['Telefone']) ?>" target="_blank"
                       class="btn btn-sm btn-outline-success">
                        <i class="bi bi-whatsapp me-1"></i><?= h($cliente['Telefone']) ?>
                    </a>
                    <?php else: ?>
                    <span class="text-secondary">Não informado</span>
                    <?php endif ?>
                </dd>
                <dt class="small text-secondary">Total gasto (pago)</dt>
                <dd class="fw-bold text-accent fs-5"><?= formatarMoeda($totalGasto) ?></dd>
                <dt class="small text-secondary">Total de procedimentos</dt>
                <dd><?= count($historico) ?></dd>
            </dl>

            <?php if ($cliente['Telefone']): ?>
            <a href="https://wa.me/<?= h($cliente['Telefone']) ?>" target="_blank"
               class="btn btn-outline-success w-100 mb-2">
                <i class="bi bi-whatsapp me-1"></i> Abrir conversa
            </a>
            <?php endif ?>
            <a href="<?= BASE ?>/painel/agenda.php?acao=novo" class="btn btn-accent w-100">
                <i class="bi bi-calendar-plus me-1"></i> Novo agendamento
            </a>
        </div>
    </div>

    <!-- Histórico -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header px-4 py-3">
                <i class="bi bi-clock-history me-2 text-accent"></i>Histórico de procedimentos
            </div>
            <div class="card-body p-0">
                <?php if (empty($historico)): ?>
                <div class="text-center py-5 text-secondary">
                    <i class="bi bi-calendar-x fs-1 d-block mb-2 opacity-25"></i>
                    <p>Nenhum procedimento encontrado.</p>
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
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historico as $h_ag): ?>
                            <tr>
                                <td class="px-4">
                                    <div class="fw-medium"><?= date('d/m/Y', strtotime($h_ag['DataHoraAgendamento'])) ?></div>
                                    <div class="small text-secondary"><?= date('H:i', strtotime($h_ag['DataHoraAgendamento'])) ?></div>
                                </td>
                                <td class="small"><?= h($h_ag['NomeSubServico'] ?? $h_ag['NomeServico']) ?></td>
                                <td><?= $h_ag['ValorCobrado'] ? formatarMoeda((float)$h_ag['ValorCobrado']) : '—' ?></td>
                                <td><?= labelStatus($h_ag['StatusAgendamento']) ?></td>
                                <td><?= labelStatusPag($h_ag['StatusPagamento']) ?></td>
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

<?php require_once __DIR__ . '/../geral/footer.php' ?>
