<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/conexao.php';

$paginaTitulo  = $paginaTitulo  ?? 'Belos Cílios';
$areaAtual     = $areaAtual     ?? '';
$ehPainel      = $areaAtual === 'painel';
$usuario       = $_SESSION['usuario_nome'] ?? '';
$nivelAcesso   = $_SESSION['nivel_acesso'] ?? '';
$base          = '/beloscilios';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($paginaTitulo) ?> — Belos Cílios</title>
    <link rel="icon" href="<?= $base ?>/geral/img/ico.ico" type="image/x-icon">

    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        /* ── Paleta Belos Cílios ── */
        :root {
            --roxo-900:         #10002b;   /* mais escuro */
            --roxo-800:         #240046;
            --roxo-600:         #5a189a;   /* accent principal */
            --roxo-500:         #6739c7;
            --roxo-300:         #b38cff;
            --roxo-200:         #c886fa;
            --roxo-100:         #e0aaff;   /* mais claro */

            --bg-main:          #faf5ff;
            --bg-card:          #ffffff;
            --bg-hover:         #f3e8ff;
            --text-main:        #10002b;
            --text-secondary:   #6739c7;
            --accent:           #5a189a;
            --accent-hover:     #240046;
            --accent-light:     rgba(179,140,255,.15);
            --card-border-color:#e0aaff;
            --sidebar-bg:       #10002b;
            --sidebar-text:     #e0aaff;
            --sidebar-active:   rgba(90,24,154,.35);
            --sidebar-width:    240px;
        }

        /* ── Reset / Base ── */
        body {
            background-color: var(--bg-main);
            color: var(--text-main);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
        }
        a { color: var(--accent); text-decoration: none; }
        a:hover { color: var(--accent-hover); }

        /* ── Cards ── */
        .card {
            background: var(--bg-card);
            border: 1px solid var(--card-border-color);
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(16,0,43,.07);
        }
        .card-header {
            background: transparent;
            border-bottom: 1px solid var(--card-border-color);
            font-weight: 600;
        }

        /* ── Botão accent ── */
        .btn-accent {
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-weight: 500;
        }
        .btn-accent:hover { background: var(--accent-hover); color: #fff; }
        .btn-outline-accent {
            border: 1.5px solid var(--accent);
            color: var(--accent);
            border-radius: 8px;
            font-weight: 500;
        }
        .btn-outline-accent:hover {
            background: var(--accent);
            color: #fff;
        }

        /* ── Sidebar (painel da designer) ── */
        .sidebar {
            position: fixed;
            top: 0; left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--sidebar-bg);
            color: var(--sidebar-text);
            display: flex;
            flex-direction: column;
            z-index: 1040;
            overflow-y: auto;
        }
        .sidebar-brand {
            padding: 1.5rem 1.25rem 1rem;
            font-size: 1.1rem;
            font-weight: 700;
            letter-spacing: .03em;
            color: var(--roxo-300);
            border-bottom: 1px solid rgba(224,170,255,.1);
        }
        .sidebar-brand small {
            display: block;
            color: rgba(224,170,255,.45);
            font-size: .7rem;
            font-weight: 400;
            letter-spacing: .05em;
            text-transform: uppercase;
        }
        .sidebar-nav { list-style: none; padding: .75rem 0; margin: 0; flex: 1; }
        .sidebar-nav li a {
            display: flex;
            align-items: center;
            gap: .65rem;
            padding: .65rem 1.25rem;
            color: rgba(224,170,255,.75);
            font-size: .92rem;
            transition: background .15s, color .15s;
            border-radius: 0 8px 8px 0;
            margin-right: .75rem;
        }
        .sidebar-nav li a:hover,
        .sidebar-nav li a.ativo {
            background: var(--sidebar-active);
            color: var(--roxo-200);
        }
        .sidebar-nav li a i { font-size: 1.1rem; width: 1.3rem; }
        .sidebar-footer {
            padding: 1rem 1.25rem;
            border-top: 1px solid rgba(224,170,255,.1);
            font-size: .82rem;
            color: rgba(224,170,255,.45);
        }
        .sidebar-footer a { color: rgba(224,170,255,.6); }
        .sidebar-footer a:hover { color: var(--roxo-300); }

        /* ── Layout com sidebar ── */
        .painel-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            padding: 1.75rem;
            background: var(--bg-main);
        }

        /* ── Topnav (área cliente) ── */
        .topnav {
            background: var(--roxo-900);
            border-bottom: 1px solid rgba(224,170,255,.15);
            box-shadow: 0 2px 12px rgba(16,0,43,.2);
        }
        .topnav .navbar-brand {
            font-weight: 700;
            color: var(--roxo-300) !important;
            font-size: 1.15rem;
        }
        .topnav .btn-accent {
            background: var(--accent);
        }
        .topnav .btn-outline-secondary {
            border-color: rgba(224,170,255,.4);
            color: var(--roxo-200);
        }
        .topnav .dropdown-menu {
            border-color: var(--card-border-color);
        }

        /* ── Stat cards no dashboard ── */
        .stat-card {
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .stat-icon {
            width: 48px; height: 48px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem;
        }

        /* ── Badges de status ── */
        .badge { font-size: .78rem; font-weight: 500; padding: .35em .65em; border-radius: 6px; }

        /* ── Responsivo: ocultar sidebar no mobile ── */
        @media (max-width: 767.98px) {
            .sidebar { transform: translateX(-100%); transition: transform .25s ease; }
            .sidebar.aberta { transform: translateX(0); }
            .painel-content { margin-left: 0; padding: 1rem; }
            .sidebar-overlay {
                display: none;
                position: fixed; inset: 0;
                background: rgba(0,0,0,.45);
                z-index: 1039;
            }
            .sidebar-overlay.ativo { display: block; }
        }

        /* ── Utilitários ── */
        .text-accent { color: var(--accent) !important; }
        .bg-accent   { background: var(--accent) !important; }
        .border-accent { border-color: var(--card-border-color) !important; }
        .rounded-xl { border-radius: 14px !important; }
    </style>
</head>
<body>

<?php if ($ehPainel): ?>
<!-- ════════ SIDEBAR — painel da designer ════════ -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="fecharSidebar()"></div>
<nav class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <img src="<?= BASE ?>/geral/img/LogoTransparente.png" alt="BC"
             style="height:28px;width:auto;filter:brightness(0) invert(1);opacity:.9;vertical-align:middle;margin-right:6px;">
        Belos Cílios
        <small>Painel da Designer</small>
    </div>
    <?php
    $uri = $_SERVER['REQUEST_URI'];
    $menuItens = [
        ['href' => BASE . '/painel/index.php',        'icon' => 'bi-house-door',   'label' => 'Dashboard'],
        ['href' => BASE . '/painel/agenda.php',        'icon' => 'bi-calendar3',    'label' => 'Agenda'],
        ['href' => BASE . '/painel/clientes.php',      'icon' => 'bi-people',       'label' => 'Clientes'],
        ['href' => BASE . '/painel/servicos.php',      'icon' => 'bi-scissors',     'label' => 'Serviços'],
        ['href' => BASE . '/painel/relatorio.php',     'icon' => 'bi-bar-chart',    'label' => 'Financeiro'],
        ['href' => BASE . '/painel/configuracoes.php', 'icon' => 'bi-gear',         'label' => 'Configurações'],
    ];
    ?>
    <ul class="sidebar-nav">
        <?php foreach ($menuItens as $item): ?>
        <li>
            <a href="<?= $item['href'] ?>"
               class="<?= str_contains($uri, $item['href']) ? 'ativo' : '' ?>">
                <i class="bi <?= $item['icon'] ?>"></i>
                <?= $item['label'] ?>
            </a>
        </li>
        <?php endforeach ?>
    </ul>
    <div class="sidebar-footer">
        <div class="mb-1"><i class="bi bi-person-circle me-1"></i> <?= h($usuario) ?></div>
        <a href="<?= BASE ?>/usuario/logout.php"><i class="bi bi-box-arrow-right me-1"></i> Sair</a>
    </div>
</nav>

<div class="painel-content">
    <!-- Topbar mobile -->
    <div class="d-flex d-md-none align-items-center mb-3 gap-2">
        <button class="btn btn-sm btn-outline-secondary" onclick="abrirSidebar()">
            <i class="bi bi-list fs-5"></i>
        </button>
        <img src="<?= BASE ?>/geral/img/LogoTransparente.png" alt="Belos Cílios"
             style="height:24px;width:auto;"> <span class="fw-semibold text-accent">Belos Cílios</span>
    </div>

    <?php flashMsg() ?>

<?php else: ?>
<!-- ════════ TOPNAV — área cliente / pública ════════ -->
<nav class="navbar topnav sticky-top">
    <div class="container-lg">
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= BASE ?>/index.php">
            <img src="<?= BASE ?>/geral/img/LogoTransparente.png" alt="BC"
                 style="height:30px;width:auto;filter:brightness(0) invert(1);opacity:.9;">
            Belos Cílios
        </a>

        <?php if (estaLogado()): ?>
        <div class="d-flex align-items-center gap-2">
            <a href="<?= BASE ?>/agendamento/index.php" class="btn btn-accent btn-sm d-none d-sm-inline-flex">
                <i class="bi bi-calendar-plus me-1"></i> Agendar
            </a>
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="bi bi-person-circle me-1"></i> <?= h($usuario) ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="<?= BASE ?>/usuario/perfil.php">
                        <i class="bi bi-person me-2"></i>Meu Perfil</a></li>
                    <li><a class="dropdown-item" href="<?= BASE ?>/usuario/historico.php">
                        <i class="bi bi-clock-history me-2"></i>Histórico</a></li>
                    <li><a class="dropdown-item" href="<?= BASE ?>/agendamento/index.php">
                        <i class="bi bi-calendar-plus me-2"></i>Agendar</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="<?= BASE ?>/usuario/logout.php">
                        <i class="bi bi-box-arrow-right me-2"></i>Sair</a></li>
                </ul>
            </div>
        </div>
        <?php else: ?>
        <div class="d-flex gap-2">
            <a href="<?= BASE ?>/usuario/login.php" class="btn btn-sm btn-outline-accent">Entrar</a>
            <a href="<?= BASE ?>/usuario/cadastro.php" class="btn btn-sm btn-accent">Cadastrar</a>
        </div>
        <?php endif ?>
    </div>
</nav>

<main class="container-lg py-4">
    <?php flashMsg() ?>

    <?php
    // Banner de verificação de e-mail — carrega status uma vez por sessão
    if (estaLogado() && !($ehPainel ?? false)) {
        if (!isset($_SESSION['email_verificado'])) {
            try {
                $evStmt = $pdo->prepare('SELECT EmailVerificado FROM Usuarios WHERE IDUsuario = :id LIMIT 1');
                $evStmt->execute([':id' => $_SESSION['usuario_id']]);
                $_SESSION['email_verificado'] = (bool) $evStmt->fetchColumn();
            } catch (PDOException) {
                $_SESSION['email_verificado'] = true; // falha silenciosa
            }
        }
        if (!$_SESSION['email_verificado']) {
            echo '<div class="alert alert-warning alert-dismissible d-flex align-items-center gap-2 mb-3" role="alert">'
               . '<i class="bi bi-envelope-exclamation-fill flex-shrink-0"></i>'
               . '<div>Confirme seu e-mail para receber lembretes de agendamento. '
               . '<a href="' . BASE . '/usuario/reenviar_verificacao.php" class="alert-link">Reenviar link</a>'
               . '</div>'
               . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>'
               . '</div>';
        }
    }
    ?>
<?php endif ?>
