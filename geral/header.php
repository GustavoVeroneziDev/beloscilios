<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/versao.php';

$paginaTitulo  = $paginaTitulo  ?? 'Belos Cílios';
$areaAtual     = $areaAtual     ?? '';
$ehPainel      = $areaAtual === 'painel';

// Auto-login por cookie lembrar-me em páginas públicas (protegidas já tratam em exigirLogin)
if (!estaLogado() && !empty($_COOKIE['bc_lembrar']) && isset($pdo)) {
    tentarLoginLembrado($pdo);
}
$_nomeSession  = $_SESSION['usuario_nome'] ?? '';
$nivelAcesso   = $_SESSION['nivel_acesso'] ?? '';
$base          = '/beloscilios';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-bs-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($paginaTitulo) ?> — Belos Cílios</title>
    <link rel="icon" href="<?= BASE ?>/geral/img/ico.ico" type="image/x-icon">
    <link rel="apple-touch-icon" href="<?= BASE ?>/geral/img/LogoCírculo.png">
    <link rel="manifest" href="<?= BASE ?>/manifest.php">

    <meta name="theme-color" content="#5a189a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Belos Cílios">
    <meta name="format-detection" content="telephone=no">

    <link rel="stylesheet" href="<?= BASE ?>/geral/vendor/bs/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE ?>/geral/vendor/bi/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE ?>/geral/css/estilo.css?v=<?= APP_VERSAO ?>">
</head>

<body<?= isset($_bcPwaModal) && $_bcPwaModal ? ' data-pwa-modal="1"' : '' ?>>


    <?php if ($ehPainel): ?>
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="fecharSidebar()"></div>
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-brand">
                <a href="<?= BASE ?>/index.php" class="d-flex align-items-center mb-1 text-decoration-none">
                    <img src="<?= BASE ?>/geral/img/LogoTransparente.png" alt="Ícone BC"
                        style="height:40px;width:auto;filter:brightness(0) invert(1);opacity:.9;margin-right:6px;">
                    <img src="<?= BASE ?>/geral/img/NomeCompleto.png" alt="Belos Cílios"
                        style="height:40px;max-width:150px;width:auto;filter:brightness(0) invert(1);object-fit:contain;">
                </a>
                <small>Painel da Designer</small>
            </div>
            <?php
            $uri = $_SERVER['REQUEST_URI'];
            $menuItens = [
                ['href' => BASE . '/painel/index.php',        'icon' => 'bi-house-door',   'label' => 'Dashboard'],
                ['href' => BASE . '/painel/agenda.php',        'icon' => 'bi-calendar3',    'label' => 'Agenda'],
                ['href' => BASE . '/painel/clientes.php',      'icon' => 'bi-people',       'label' => 'Clientes'],
                ['href' => BASE . '/painel/servicos.php',      'icon' => 'bi-brush',        'label' => 'Serviços'],
                ['href' => BASE . '/painel/galeria.php',       'icon' => 'bi-images',       'label' => 'Galeria'],
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
                <div class="mb-1"><i class="bi bi-person-circle me-1"></i> <?= h($_nomeSession) ?></div>
                <button id="btnPwaSidebar" onclick="bcPwaInstalar()"
                    class="btn btn-sm btn-outline-accent w-100 mb-2" style="display:none;">
                    <i class="bi bi-download me-1"></i> Instalar app
                </button>
                <a href="<?= BASE ?>/usuario/logout.php"><i class="bi bi-box-arrow-right me-1"></i> Sair</a>
            </div>
        </nav>

        <div class="painel-content">
            <div class="d-flex d-md-none align-items-center mb-3 gap-2">
                <button class="btn btn-sm btn-outline-secondary" onclick="abrirSidebar()">
                    <i class="bi bi-list fs-5"></i>
                </button>
                <a href="<?= BASE ?>/index.php" class="d-flex align-items-center gap-2 text-decoration-none">
                    <img src="<?= BASE ?>/geral/img/LogoTransparente.png" alt="Ícone" style="height:35px;width:auto;">
                    <img src="<?= BASE ?>/geral/img/NomeCompleto.png" alt="Belos Cílios" style="height:35px;max-width:150px;width:auto;object-fit:contain;">
                </a>
            </div>

            <?php flashMsg() ?>

        <?php else: ?>
            <nav class="navbar topnav sticky-top">
                <div class="container-lg">
                    <a class="navbar-brand d-flex align-items-center gap-2" href="<?= BASE ?>/index.php">
                        <img src="<?= BASE ?>/geral/img/LogoTransparente.png" alt="Ícone BC"
                            style="height:40px;width:auto;filter:brightness(0) invert(1);opacity:.9;">
                        <img src="<?= BASE ?>/geral/img/NomeCompleto.png" alt="Belos Cílios"
                            style="height:40px;max-width:150px;width:auto;filter:brightness(0) invert(1);object-fit:contain;">
                    </a>

                    <?php if (estaLogado()): ?>
                        <div class="d-flex align-items-center gap-2">
                            <a href="<?= BASE ?>/agendamento/index.php" class="btn btn-accent btn-sm d-none d-sm-inline-flex">
                                <i class="bi bi-calendar-plus me-1"></i> Agendar
                            </a>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                    <i class="bi bi-person-circle me-1"></i> <?= h($_nomeSession) ?>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="<?= BASE ?>/usuario/perfil.php">
                                            <i class="bi bi-person me-2"></i>Meu Perfil</a></li>
                                    <li><a class="dropdown-item" href="<?= BASE ?>/usuario/historico.php">
                                            <i class="bi bi-clock-history me-2"></i>Histórico</a></li>
                                    <li><a class="dropdown-item" href="<?= BASE ?>/agendamento/index.php">
                                            <i class="bi bi-calendar-plus me-2"></i>Agendar</a></li>
                                    <li>
                                        <hr class="dropdown-divider">
                                    </li>
                                    <li>
                                        <button class="dropdown-item" id="btnPwaNav" onclick="bcPwaInstalar()" style="display:none;">
                                            <i class="bi bi-download me-2"></i>Instalar app
                                        </button>
                                    </li>
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