<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/versao.php';

$paginaTitulo  = $paginaTitulo  ?? 'Belos Cílios';
$areaAtual     = $areaAtual     ?? '';
$ehPainel      = $areaAtual === 'painel';

// Meta description e Open Graph — páginas podem definir $metaDescricao e $ogImage antes do header
$_metaDesc  = $metaDescricao ?? 'Extensão de cílios e design de sobrancelhas com atendimento personalizado. Agende online com facilidade.';
$_ogTitle   = h($paginaTitulo) . ' — Belos Cílios';
$_ogImage   = $ogImage ?? 'https://beloscilios.com/geral/img/LogoCírculo.png';
$_ogUrl     = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'beloscilios.com') . ($_SERVER['REQUEST_URI'] ?? '/');

// Auto-login por cookie lembrar-me em páginas públicas (protegidas já tratam em exigirLogin)
if (!estaLogado() && !empty($_COOKIE['bc_lembrar']) && isset($pdo)) {
    tentarLoginLembrado($pdo);
}

// Sair do modo preview (qualquer página que receba ?sair_preview=1)
if (!empty($_GET['sair_preview']) && !empty($_SESSION['designer_preview'])) {
    unset($_SESSION['designer_preview']);
    header('Location: ' . BASE . '/painel/agenda.php');
    exit;
}

$_nomeSession  = $_SESSION['usuario_nome'] ?? '';
$nivelAcesso   = $_SESSION['nivel_acesso'] ?? '';
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

    <meta name="description" content="<?= h($_metaDesc) ?>">
    <?php if ($ehPainel): ?>
    <meta name="robots" content="noindex, nofollow">
    <?php endif ?>

    <!-- Open Graph / WhatsApp / redes sociais -->
    <meta property="og:type"        content="website">
    <meta property="og:site_name"   content="Belos Cílios">
    <meta property="og:title"       content="<?= $_ogTitle ?>">
    <meta property="og:description" content="<?= h($_metaDesc) ?>">
    <meta property="og:url"         content="<?= h($_ogUrl) ?>">
    <meta property="og:image"       content="<?= h($_ogImage) ?>">
    <meta property="og:image:alt"   content="Belos Cílios — Studio de Cílios e Sobrancelhas">
    <meta name="twitter:card"       content="summary">
    <meta name="twitter:title"      content="<?= $_ogTitle ?>">
    <meta name="twitter:description" content="<?= h($_metaDesc) ?>">
    <meta name="twitter:image"      content="<?= h($_ogImage) ?>">

    <meta name="theme-color" content="#5a189a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Belos Cílios">
    <meta name="format-detection" content="telephone=no">

    <link rel="stylesheet" href="<?= BASE ?>/geral/vendor/bs/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE ?>/geral/vendor/bi/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE ?>/geral/css/estilo.css?v=<?= APP_VERSAO ?>">
    <script>var BASE = '<?= BASE ?>';</script>
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
                ['href' => BASE . '/painel/whatsapp.php',      'icon' => 'bi-whatsapp',     'label' => 'WhatsApp'],
                ['separador' => true],
                ['href' => BASE . '/agendamento/index.php?designer_preview=1', 'icon' => 'bi-eye', 'label' => 'Ver como Cliente'],
            ];
            ?>
            <ul class="sidebar-nav">
                <?php foreach ($menuItens as $item): ?>
                    <?php if (!empty($item['separador'])): ?>
                        <li><hr class="sidebar-hr"></li>
                    <?php else: ?>
                    <li>
                        <a href="<?= $item['href'] ?>"
                            class="<?= str_contains($uri, $item['href'] ?? '') ? 'ativo' : '' ?>">
                            <i class="bi <?= $item['icon'] ?>"></i>
                            <?= $item['label'] ?>
                        </a>
                    </li>
                    <?php endif ?>
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
                                    <li><a class="dropdown-item" href="<?= BASE ?>/usuario/ficha_anamnese.php">
                                            <i class="bi bi-clipboard2-pulse me-2"></i>Ficha de saúde</a></li>
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
                            <a href="<?= BASE ?>/usuario/login.php" class="btn btn-sm btn-outline-accent"><i class="bi bi-box-arrow-in-right me-1"></i>Entrar</a>
                            <a href="<?= BASE ?>/usuario/cadastro.php" class="btn btn-sm btn-accent"><i class="bi bi-person-plus me-1"></i>Cadastrar</a>
                        </div>
                    <?php endif ?>
                </div>
            </nav>

            <main class="container-lg py-4">
                <?php flashMsg() ?>

                <?php if (!empty($_SESSION['designer_preview'])): ?>
                <div class="d-flex align-items-center gap-2 px-3 py-2 mb-3 rounded-3 small fw-medium"
                     style="background:#fff8e1;border:1px solid #f59e0b;color:#78350f;">
                    <i class="bi bi-eye-fill flex-shrink-0" style="color:#f59e0b;"></i>
                    <span class="flex-grow-1">
                        Modo visualização ativo — agendamentos feitos aqui <strong>não serão salvos</strong>.
                    </span>
                    <a href="<?= BASE ?>/painel/agenda.php?sair_preview=1"
                       class="btn btn-sm fw-semibold flex-shrink-0"
                       style="background:#f59e0b;color:#fff;border:none;white-space:nowrap;">
                        <i class="bi bi-x me-1"></i>Sair
                    </a>
                </div>
                <?php endif ?>

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