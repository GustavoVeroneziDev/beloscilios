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

    $total = (int) $pdo->prepare("SELECT COUNT(*) FROM Usuarios {$where}")
                       ->execute($params) ? $pdo->query("SELECT COUNT(*) FROM Usuarios {$where}")->fetchColumn() : 0;

    // Reexecuta corretamente
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM Usuarios {$where}");
    $cntStmt->execute($params);
    $total = (int) $cntStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT u.IDUsuario, u.Nome, u.Email, u.Telefone, u.MomentoRegistro,
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
        $stmt->bindValue($k, $v, in_array($k, [':lim',':off']) ? PDO::PARAM_INT : PDO::PARAM_STR);
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
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead style="background:var(--bg-hover);">
                    <tr>
                        <th class="px-4 py-3">Nome</th>
                        <th>E-mail</th>
                        <th>WhatsApp</th>
                        <th class="text-center">Procedimentos</th>
                        <th>Cadastro</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clientes as $c): ?>
                    <tr>
                        <td class="px-4 fw-medium"><?= h($c['Nome']) ?></td>
                        <td class="text-secondary small"><?= h($c['Email']) ?></td>
                        <td>
                            <?php if ($c['Telefone']): ?>
                            <a href="https://wa.me/<?= h($c['Telefone']) ?>" target="_blank"
                               class="btn btn-sm btn-outline-success">
                                <i class="bi bi-whatsapp"></i>
                            </a>
                            <?php else: ?>
                            <span class="text-secondary">—</span>
                            <?php endif ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-secondary"><?= (int)$c['TotalAg'] ?></span>
                        </td>
                        <td class="small text-secondary"><?= formatarData($c['MomentoRegistro']) ?></td>
                        <td>
                            <a href="<?= BASE ?>/painel/cliente_detalhe.php?id=<?= h($c['IDUsuario']) ?>"
                               class="btn btn-sm btn-outline-accent">
                                <i class="bi bi-eye me-1"></i>Ver
                            </a>
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
