<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['usuario_id'])) {
    if ($_SESSION['nivel_acesso'] === 'designer') {
        header('Location: ' . BASE . '/painel/index.php');
    } else {
        header('Location: ' . BASE . '/usuario/perfil.php');
    }
    exit;
}

require_once __DIR__ . '/config/conexao.php';

$paginaTitulo = 'Início';
$areaAtual    = 'publico';
require_once __DIR__ . '/geral/header.php';
?>

<!-- Hero -->
<section class="py-5 text-center">
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <img src="<?= BASE ?>/geral/img/NomeCompleto.png" alt="Belos Cílios"
                 class="mb-3" style="max-width:320px;width:100%;">
            <h1 class="display-5 fw-bold mb-3" style="color:var(--text-main);">
                Realce sua beleza com quem entende
            </h1>
            <p class="lead mb-4" style="color:var(--text-secondary);">
                Design de sobrancelhas e extensão de cílios feitos com cuidado,
                precisão e muito amor. Agende online em minutos.
            </p>
            <div class="d-flex flex-wrap gap-3 justify-content-center">
                <a href="<?= BASE ?>/usuario/cadastro.php" class="btn btn-accent btn-lg px-4">
                    <i class="bi bi-calendar-plus me-2"></i> Agendar agora
                </a>
                <a href="<?= BASE ?>/usuario/login.php" class="btn btn-outline-accent btn-lg px-4">
                    Já tenho cadastro
                </a>
            </div>
        </div>
    </div>
</section>

<!-- Serviços em destaque -->
<section class="py-4">
    <h2 class="text-center fw-semibold mb-4">Nossos serviços</h2>
    <?php
    try {
        $servicos = $pdo->query(
            'SELECT Nome, Descricao, Preco, DuracaoMinutos FROM Servicos WHERE Ativo = 1 ORDER BY Ordem LIMIT 6'
        )->fetchAll();
    } catch (PDOException) {
        $servicos = [];
    }
    ?>
    <div class="row g-3 justify-content-center">
        <?php foreach ($servicos as $s): ?>
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100 p-3">
                <img src="<?= BASE ?>/geral/img/mascara.png" alt="" class="mb-2" style="width:2.2rem;height:2.2rem;object-fit:contain;">
                <h5 class="fw-semibold mb-1"><?= h($s['Nome']) ?></h5>
                <p class="small text-secondary flex-grow-1 mb-2"><?= h($s['Descricao']) ?></p>
                <div class="d-flex align-items-center justify-content-between">
                    <span class="fw-bold text-accent"><?= formatarMoeda((float)$s['Preco']) ?></span>
                    <span class="small text-secondary">
                        <i class="bi bi-clock me-1"></i><?= (int)$s['DuracaoMinutos'] ?>min
                    </span>
                </div>
            </div>
        </div>
        <?php endforeach ?>
    </div>
    <div class="text-center mt-4">
        <a href="<?= BASE ?>/agendamento/index.php" class="btn btn-accent px-4">
            Ver todos e agendar <i class="bi bi-arrow-right ms-1"></i>
        </a>
    </div>
</section>

<!-- Por que nos escolher -->
<section class="py-5">
    <div class="row g-4 text-center">
        <?php
        $diferenciais = [
            ['bi-shield-check',   'var(--accent)',  'Materiais Premium',    'Produtos hipoalergênicos e certificados para sua segurança e conforto.'],
            ['bi-clock',          '#6B9E7A',        'Pontualidade',          'Respeitamos seu tempo. Agenda organizada sem esperas desnecessárias.'],
            ['bi-phone',          '#D4963A',        'Agendamento Online',    'Marque seu horário a qualquer hora pelo celular, sem precisar ligar.'],
            ['bi-heart',          '#C0604A',        'Atendimento Exclusivo', 'Cuidado personalizado para realçar o melhor de cada cliente.'],
        ];
        foreach ($diferenciais as [$icon, $color, $title, $desc]):
        ?>
        <div class="col-6 col-md-3">
            <div class="card p-4 h-100">
                <div class="mb-3">
                    <span style="font-size:2rem;color:<?= $color ?>">
                        <i class="bi <?= $icon ?>"></i>
                    </span>
                </div>
                <h6 class="fw-semibold mb-1"><?= $title ?></h6>
                <p class="small text-secondary mb-0"><?= $desc ?></p>
            </div>
        </div>
        <?php endforeach ?>
    </div>
</section>

<!-- CTA final -->
<section class="py-5 text-center">
    <div class="card p-5" style="background:var(--accent-light);border-color:var(--accent);">
        <h3 class="fw-bold mb-2">Pronta para se sentir incrível?</h3>
        <p class="text-secondary mb-4">Agende agora e garanta seu horário. É rápido, fácil e sem complicação.</p>
        <div>
            <a href="<?= BASE ?>/usuario/cadastro.php" class="btn btn-accent btn-lg px-5">
                <i class="bi bi-calendar-heart me-2"></i> Quero agendar!
            </a>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/geral/footer.php' ?>
