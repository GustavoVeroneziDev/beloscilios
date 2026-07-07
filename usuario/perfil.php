<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('cliente');

$uid = $_SESSION['usuario_id'];

try {
    $usuario = $pdo->prepare('SELECT * FROM Usuarios WHERE IDUsuario = :id LIMIT 1');
    $usuario->execute([':id' => $uid]);
    $usuario = $usuario->fetch();

    $proximosAg = $pdo->prepare(
        'SELECT a.*, s.Nome AS NomeServico, s.Preco,
                ss.Nome AS NomeSubServico
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
} catch (PDOException $e) {
    error_log('[Perfil] ' . $e->getMessage());
    $proximosAg = [];
}

$paginaTitulo = 'Meu Perfil';
$areaAtual    = 'cliente';
require_once __DIR__ . '/../geral/header.php';
?>

<div class="row g-4">
    <!-- Card boas-vindas -->
    <div class="col-12">
        <div class="card p-4 d-flex flex-row align-items-center gap-3"
             style="background:var(--accent-light);border-color:var(--accent);">
            <div style="font-size:2.5rem;">👋</div>
            <div>
                <h5 class="fw-bold mb-0">Olá, <?= h($usuario['Nome']) ?>!</h5>
                <p class="text-secondary small mb-0">Que bom ter você aqui. Confira seus próximos agendamentos.</p>
            </div>
            <div class="ms-auto">
                <a href="/beloscilios/agendamento/index.php" class="btn btn-accent">
                    <i class="bi bi-calendar-plus me-1"></i> Agendar
                </a>
            </div>
        </div>
    </div>

    <!-- Próximos agendamentos -->
    <div class="col-md-8">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between px-4 py-3">
                <span><i class="bi bi-calendar3 me-2 text-accent"></i>Próximos agendamentos</span>
                <a href="/beloscilios/usuario/historico.php" class="btn btn-sm btn-outline-accent">
                    Ver histórico
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($proximosAg)): ?>
                <div class="text-center py-5 text-secondary">
                    <i class="bi bi-calendar-x fs-1 mb-2 d-block opacity-25"></i>
                    <p class="mb-2">Você não tem agendamentos futuros.</p>
                    <a href="/beloscilios/agendamento/index.php" class="btn btn-accent btn-sm">
                        Agendar agora
                    </a>
                </div>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($proximosAg as $ag): ?>
                    <li class="list-group-item px-4 py-3">
                        <div class="d-flex align-items-start gap-3">
                            <div class="text-center" style="min-width:52px;">
                                <div class="fw-bold text-accent fs-5 lh-1">
                                    <?= date('d', strtotime($ag['DataHoraAgendamento'])) ?>
                                </div>
                                <div class="text-uppercase text-secondary" style="font-size:.7rem;">
                                    <?= strftime('%b', strtotime($ag['DataHoraAgendamento'])) ?>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <div class="fw-semibold">
                                    <?= h($ag['NomeSubServico'] ?? $ag['NomeServico']) ?>
                                </div>
                                <div class="small text-secondary">
                                    <i class="bi bi-clock me-1"></i>
                                    <?= date('H:i', strtotime($ag['DataHoraAgendamento'])) ?>
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
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header px-4 py-3">
                <i class="bi bi-person-circle me-2 text-accent"></i>Meus dados
            </div>
            <div class="card-body px-4">
                <dl class="mb-3">
                    <dt class="text-secondary small">Nome</dt>
                    <dd class="mb-3"><?= h($usuario['Nome']) ?></dd>
                    <dt class="text-secondary small">E-mail</dt>
                    <dd class="mb-3"><?= h($usuario['Email']) ?></dd>
                    <dt class="text-secondary small">WhatsApp</dt>
                    <dd class="mb-0"><?= $usuario['Telefone'] ? h($usuario['Telefone']) : '<span class="text-secondary">Não informado</span>' ?></dd>
                </dl>
                <a href="/beloscilios/usuario/editar_perfil.php" class="btn btn-outline-accent btn-sm w-100">
                    <i class="bi bi-pencil me-1"></i> Editar dados
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
