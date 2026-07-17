<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

// Cadastro rápido via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cadastrar') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
        redirecionarComMensagem(BASE . '/painel/clientes.php', 'Token inválido.', 'danger');
    }
    $nome  = trim($_POST['nome']  ?? '');
    $email = trim($_POST['email'] ?? '');
    $tel   = trim($_POST['tel']   ?? '');
    $senha = bin2hex(random_bytes(8)); // senha aleatória (cliente pode resetar)

    if ($nome && $email) {
        try {
            $chk = $pdo->prepare('SELECT IDUsuario FROM Usuarios WHERE Email = :e LIMIT 1');
            $chk->execute([':e' => $email]);
            if ($chk->fetch()) {
                redirecionarComMensagem(BASE . '/painel/clientes.php', 'E-mail já cadastrado.', 'warning');
            }
            $stmt = $pdo->prepare(
                'INSERT INTO Usuarios (IDUsuario, Nome, Email, Telefone, Senha, NivelAcesso)
                 VALUES (:id,:nome,:email,:tel,:senha,\'cliente\')'
            );
            $stmt->execute([
                ':id'    => gerarUuid(),
                ':nome'  => $nome,
                ':email' => $email,
                ':tel'   => sanitizarTelefone($tel),
                ':senha' => password_hash($senha, PASSWORD_DEFAULT),
            ]);
            redirecionarComMensagem(BASE . '/painel/clientes.php', 'Cliente cadastrada com sucesso!', 'success');
        } catch (PDOException $e) {
            error_log('[CadastroCliente] ' . $e->getMessage());
            redirecionarComMensagem(BASE . '/painel/clientes.php', 'Erro ao cadastrar.', 'danger');
        }
    }
}

$busca = trim($_GET['q'] ?? '');
$pag   = max(1, (int)($_GET['pag'] ?? 1));
$por   = 20;
$off   = ($pag - 1) * $por;

try {
    $where = "WHERE NivelAcesso = 'cliente' AND Ativo = 1";
    $params = [];
    if ($busca !== '') {
        $where .= ' AND (Nome LIKE :q OR Email LIKE :q OR Telefone LIKE :q)';
        $params[':q'] = '%' . $busca . '%';
    }

    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM Usuarios {$where}");
    $cntStmt->execute($params);
    $total = (int) $cntStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT u.IDUsuario, u.Nome, u.Email, u.Telefone, u.MomentoRegistro, u.EmailVerificado,
                COUNT(a.IDAgendamento) AS TotalAg
         FROM Usuarios u
         LEFT JOIN Agendamentos a ON a.FKCliente = u.IDUsuario
         {$where}
         GROUP BY u.IDUsuario
         ORDER BY u.Nome ASC
         LIMIT :lim OFFSET :off"
    );
    $params[':lim'] = $por;
    $params[':off'] = $off;
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, in_array($k, [':lim', ':off']) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $clientes = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('[Clientes] ' . $e->getMessage());
    $clientes = [];
    $total    = 0;
}

$totalPag = max(1, (int) ceil($total / $por));

$paginaTitulo = 'Clientes';
$areaAtual    = 'painel';
require_once __DIR__ . '/../geral/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
    <h4 class="fw-bold mb-0">Clientes <span class="text-secondary small">(<?= number_format($total) ?>)</span></h4>
    <button class="btn btn-accent btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovaCliente">
        <i class="bi bi-person-plus me-1"></i> Nova cliente
    </button>
</div>

<!-- Busca -->
<form class="mb-4" method="GET">
    <div class="input-group">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" name="q" class="form-control" placeholder="Buscar por nome, e-mail ou telefone..."
            value="<?= h($busca) ?>">
        <button class="btn btn-accent" type="submit">Buscar</button>
        <?php if ($busca): ?>
            <a href="<?= BASE ?>/painel/clientes.php" class="btn btn-outline-secondary">Limpar</a>
        <?php endif ?>
    </div>
</form>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($clientes)): ?>
            <div class="text-center py-5 text-secondary">
                <i class="bi bi-people fs-1 d-block mb-2 opacity-25"></i>
                <p>Nenhuma cliente encontrada.</p>
            </div>
        <?php else: ?>
            <style>
            /* E-mail truncado com ellipsis */
            .email-cell {
                max-width: 200px;
            }
            .email-cell span {
                display: block;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            /* Linha de detalhe mobile */
            .row-detail { display: none; }
            .row-detail.aberta { display: table-row; }
            .row-detail td {
                background: var(--bg-hover);
                border-top: none !important;
                padding: .75rem 1rem !important;
            }
            .detalhe-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: .5rem .75rem;
                font-size: .82rem;
            }
            .detalhe-item strong {
                display: block;
                font-size: .7rem;
                text-transform: uppercase;
                letter-spacing: .04em;
                color: var(--text-secondary, #888);
                margin-bottom: 2px;
            }
            /* Chevron animado */
            .btn-expand { transition: transform .2s; }
            .btn-expand.rotated { transform: rotate(180deg); }
            </style>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="tabelaClientes">
                    <thead style="background:var(--bg-hover);">
                        <tr>
                            <th class="px-4 py-3">Nome</th>
                            <th class="d-none d-md-table-cell email-cell">E-mail</th>
                            <th class="d-none d-md-table-cell">WhatsApp</th>
                            <th class="d-none d-md-table-cell text-center">Atend.</th>
                            <th class="d-none d-md-table-cell">Cadastro</th>
                            <th></th>
                            <th class="d-md-none"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clientes as $i => $c):
                            $verif    = (bool)$c['EmailVerificado'];
                            $verifTip = $verif ? 'E-mail verificado' : 'E-mail não verificado';
                            $verifIcon = $verif
                                ? '<i class="bi bi-patch-check-fill text-success" title="' . $verifTip . '"></i>'
                                : '<i class="bi bi-clock text-warning" title="' . $verifTip . '"></i>';
                            $rid = 'det-' . $i;
                        ?>
                            <tr class="linha-cliente" data-detail="<?= $rid ?>">
                                <td class="px-4 fw-medium">
                                    <?= h($c['Nome']) ?>
                                    <!-- status verificação visível só no mobile, abaixo do nome -->
                                    <span class="d-md-none ms-1" style="font-size:.8rem;"><?= $verifIcon ?></span>
                                </td>
                                <td class="d-none d-md-table-cell text-secondary small email-cell">
                                    <span title="<?= h($c['Email']) ?>"><?= h($c['Email']) ?></span>
                                    <span class="ms-1"><?= $verifIcon ?></span>
                                </td>
                                <td class="d-none d-md-table-cell">
                                    <?php if ($c['Telefone']): ?>
                                        <?= waBotoesDropdown($c['Telefone'], $c['Nome'], split: false, clienteId: $c['IDUsuario']) ?>
                                    <?php else: ?>
                                        <span class="text-secondary">—</span>
                                    <?php endif ?>
                                </td>
                                <td class="d-none d-md-table-cell text-center">
                                    <span class="badge bg-secondary"><?= (int)$c['TotalAg'] ?></span>
                                </td>
                                <td class="d-none d-md-table-cell small text-secondary"><?= formatarData($c['MomentoRegistro']) ?></td>
                                <td>
                                    <a href="<?= BASE ?>/painel/cliente_detalhe.php?id=<?= h($c['IDUsuario']) ?>"
                                        class="btn btn-sm btn-outline-accent">
                                        <i class="bi bi-eye"></i><span class="d-none d-md-inline ms-1">Ver</span>
                                    </a>
                                </td>
                                <!-- Chevron só no mobile -->
                                <td class="d-md-none">
                                    <button class="btn btn-sm btn-link text-secondary p-0 btn-expand"
                                            data-target="<?= $rid ?>" aria-label="Detalhes">
                                        <i class="bi bi-chevron-down"></i>
                                    </button>
                                </td>
                            </tr>
                            <!-- Linha de detalhe (mobile) -->
                            <tr class="row-detail" id="<?= $rid ?>">
                                <td colspan="3">
                                    <div class="detalhe-grid">
                                        <div class="detalhe-item" style="grid-column:1/-1;">
                                            <strong>E-mail</strong>
                                            <span style="word-break:break-all;"><?= h($c['Email']) ?></span>
                                            <?= $verifIcon ?>
                                        </div>
                                        <?php if ($c['Telefone']): ?>
                                        <div class="detalhe-item">
                                            <strong>WhatsApp</strong>
                                            <?= waBotoesDropdown($c['Telefone'], $c['Nome'], split: false, clienteId: $c['IDUsuario']) ?>
                                        </div>
                                        <?php endif ?>
                                        <div class="detalhe-item">
                                            <strong>Atendimentos</strong>
                                            <span class="badge bg-secondary"><?= (int)$c['TotalAg'] ?></span>
                                        </div>
                                        <div class="detalhe-item">
                                            <strong>Cadastro</strong>
                                            <?= formatarData($c['MomentoRegistro']) ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>

            <script>
            document.querySelectorAll('.btn-expand').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var rid  = btn.dataset.target;
                    var det  = document.getElementById(rid);
                    var open = det.classList.toggle('aberta');
                    btn.classList.toggle('rotated', open);
                });
            });
            </script>

            <?php if ($totalPag > 1): ?>
                <div class="d-flex justify-content-center py-3">
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php for ($p = 1; $p <= $totalPag; $p++): ?>
                                <li class="page-item <?= $p === $pag ? 'active' : '' ?>">
                                    <a class="page-link" href="?pag=<?= $p ?>&q=<?= urlencode($busca) ?>"><?= $p ?></a>
                                </li>
                            <?php endfor ?>
                        </ul>
                    </nav>
                </div>
            <?php endif ?>
        <?php endif ?>
    </div>
</div>

<!-- Modal: Nova cliente -->
<div class="modal fade" id="modalNovaCliente" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                <input type="hidden" name="acao" value="cadastrar">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold">Cadastrar cliente</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nome completo *</label>
                        <input type="text" name="nome" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">E-mail *</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">WhatsApp</label>
                        <input type="tel" name="tel" class="form-control" placeholder="(11) 99999-9999">
                    </div>
                    <p class="small text-secondary mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Uma senha temporária será gerada automaticamente.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-accent">
                        <i class="bi bi-person-plus me-1"></i> Cadastrar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
if (($_GET['acao'] ?? '') === 'novo') {
    echo '<script>new bootstrap.Modal(document.getElementById("modalNovaCliente")).show();</script>';
}
?>

<?php require_once __DIR__ . '/../geral/footer.php' ?>