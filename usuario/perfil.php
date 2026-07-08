<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('cliente');

$uid = $_SESSION['usuario_id'];

try {
    $stmtU = $pdo->prepare('SELECT * FROM Usuarios WHERE IDUsuario = :id LIMIT 1');
    $stmtU->execute([':id' => $uid]);
    $usuario = $stmtU->fetch();

    $proximosAg = $pdo->prepare(
        'SELECT a.DataHoraAgendamento, a.StatusAgendamento,
                s.Nome AS NomeServico,
                ss.Nome AS NomeSubServico,
                s.Preco
         FROM Agendamentos a
         JOIN Servicos s ON s.IDServico = a.FKServico
         LEFT JOIN SubServicos ss ON ss.IDSubServico = a.FKSubServico
         WHERE a.FKCliente = :id
           AND a.StatusAgendamento IN (\'pendente\',\'confirmado\')
           AND a.DataHoraAgendamento >= NOW()
         ORDER BY a.DataHoraAgendamento ASC
         LIMIT 5'
    );
    $proximosAg->execute([':id' => $uid]);
    $proximosAg = $proximosAg->fetchAll();

    $totalAg = $pdo->prepare(
        'SELECT COUNT(*) FROM Agendamentos WHERE FKCliente = :id AND StatusAgendamento = \'confirmado\''
    );
    $totalAg->execute([':id' => $uid]);
    $totalAg = (int) $totalAg->fetchColumn();
} catch (PDOException $e) {
    error_log('[Perfil] ' . $e->getMessage());
    $proximosAg = [];
    $totalAg    = 0;
}

$mesesPt = ['jan','fev','mar','abr','mai','jun','jul','ago','set','out','nov','dez'];

$paginaTitulo = 'Meu Perfil';
$areaAtual    = 'cliente';
require_once __DIR__ . '/../geral/header.php';
?>

<div class="row g-4">

    <!-- Boas-vindas -->
    <div class="col-12">
        <div class="card p-4 d-flex flex-row align-items-center gap-3"
             style="background:var(--accent-light);border-color:var(--accent);">
            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:56px;height:56px;background:var(--accent);color:#fff;font-size:1.5rem;font-weight:700;">
                <?= mb_strtoupper(mb_substr($usuario['Nome'], 0, 1)) ?>
            </div>
            <div class="flex-grow-1 min-w-0">
                <h5 class="fw-bold mb-0">Olá, <?= h(explode(' ', $usuario['Nome'])[0]) ?>!</h5>
                <p class="text-secondary small mb-0">
                    <?= $totalAg > 0
                        ? $totalAg . ' ' . ($totalAg === 1 ? 'atendimento realizado' : 'atendimentos realizados')
                        : 'Que bom ter você aqui!' ?>
                </p>
            </div>
            <a href="<?= BASE ?>/agendamento/index.php" class="btn btn-accent flex-shrink-0">
                <i class="bi bi-calendar-plus me-1"></i> Agendar
            </a>
        </div>
    </div>

    <!-- Próximos agendamentos -->
    <div class="col-md-7">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between px-4 py-3">
                <span><i class="bi bi-calendar3 me-2 text-accent"></i>Próximos agendamentos</span>
                <a href="<?= BASE ?>/usuario/historico.php" class="btn btn-sm btn-outline-accent">Histórico</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($proximosAg)): ?>
                <div class="text-center py-5 text-secondary">
                    <i class="bi bi-calendar-x fs-1 mb-2 d-block opacity-25"></i>
                    <p class="mb-2">Nenhum agendamento futuro.</p>
                    <a href="<?= BASE ?>/agendamento/index.php" class="btn btn-accent btn-sm">Agendar agora</a>
                </div>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($proximosAg as $ag):
                        $ts  = strtotime($ag['DataHoraAgendamento']);
                        $dia = date('d', $ts);
                        $mes = $mesesPt[(int) date('n', $ts) - 1];
                    ?>
                    <li class="list-group-item px-4 py-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="text-center flex-shrink-0"
                                 style="min-width:48px;border-right:1px solid var(--card-border-color);padding-right:12px;">
                                <div class="fw-bold text-accent fs-5 lh-1"><?= $dia ?></div>
                                <div class="text-uppercase text-secondary" style="font-size:.7rem;"><?= $mes ?></div>
                            </div>
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold text-truncate">
                                    <?= h($ag['NomeSubServico'] ?? $ag['NomeServico']) ?>
                                </div>
                                <div class="small text-secondary">
                                    <i class="bi bi-clock me-1"></i><?= date('H:i', $ts) ?>
                                    &nbsp;·&nbsp; <?= formatarMoeda((float)$ag['Preco']) ?>
                                </div>
                            </div>
                            <?= labelStatus($ag['StatusAgendamento']) ?>
                        </div>
                    </li>
                    <?php endforeach ?>
                </ul>
                <?php endif ?>
            </div>
        </div>
    </div>

    <!-- Dados pessoais -->
    <div class="col-md-5">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between px-4 py-3">
                <span><i class="bi bi-person-circle me-2 text-accent"></i>Meus dados</span>
                <a href="<?= BASE ?>/usuario/editar_perfil.php" class="btn btn-sm btn-outline-accent">
                    <i class="bi bi-pencil me-1"></i> Editar
                </a>
            </div>
            <div class="card-body px-4 py-3">
                <dl class="mb-0 row g-0">
                    <dt class="col-12 text-secondary small mb-1">Nome</dt>
                    <dd class="col-12 fw-semibold mb-3"><?= h($usuario['Nome']) ?></dd>

                    <dt class="col-12 text-secondary small mb-1">E-mail</dt>
                    <dd class="col-12 mb-3 text-truncate"><?= h($usuario['Email']) ?>
                        <?php if ($usuario['EmailVerificado']): ?>
                            <i class="bi bi-patch-check-fill text-success ms-1" title="E-mail verificado" style="font-size:.85rem;"></i>
                        <?php else: ?>
                            <a href="<?= BASE ?>/usuario/reenviar_verificacao.php" class="small text-warning ms-1">
                                <i class="bi bi-exclamation-circle"></i> Verificar
                            </a>
                        <?php endif ?>
                    </dd>

                    <dt class="col-12 text-secondary small mb-1">WhatsApp</dt>
                    <dd class="col-12 mb-3">
                        <?php if (!empty($usuario['Telefone'])): ?>
                            <i class="bi bi-whatsapp text-success me-1"></i><?= h($usuario['Telefone']) ?>
                        <?php else: ?>
                            <a href="<?= BASE ?>/usuario/editar_perfil.php" class="text-secondary small">
                                <i class="bi bi-plus-circle me-1"></i>Adicionar número
                            </a>
                        <?php endif ?>
                    </dd>

                    <dt class="col-12 text-secondary small mb-1">Login com Google</dt>
                    <dd class="col-12 mb-0">
                        <?php if (!empty($usuario['GoogleId'])): ?>
                            <i class="bi bi-check-circle-fill text-success me-1"></i>Vinculado
                        <?php else: ?>
                            <span class="text-secondary small">Não vinculado</span>
                        <?php endif ?>
                    </dd>
                </dl>
            </div>

            <?php if (empty($usuario['Telefone'])): ?>
            <div class="card-footer px-4 py-2 border-top-0">
                <div class="alert alert-warning py-2 px-3 mb-0 small d-flex align-items-center gap-2">
                    <i class="bi bi-whatsapp"></i>
                    Adicione seu WhatsApp para receber lembretes de agendamento.
                </div>
            </div>
            <?php endif ?>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
