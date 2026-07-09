<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/conexao.php';

if (!empty($_SESSION['usuario_id']) && ($_SESSION['nivel_acesso'] ?? '') === 'designer') {
    header('Location: ' . BASE . '/painel/index.php');
    exit;
}

try {
    $telefoneWa  = getConfig($pdo, 'telefone_estudio', '');
    $fotosGaleria = $pdo->query(
        'SELECT NomeArquivo, TituloExibicao
         FROM Imagens
         WHERE TituloExibicao IN (\'Wispy\',\'Perfil\',\'Perfil 2\',\'Ambiente\',\'Fox Marrom\')
         ORDER BY FIELD(TituloExibicao,\'Wispy\',\'Perfil\',\'Perfil 2\',\'Ambiente\',\'Fox Marrom\')'
    )->fetchAll();
} catch (PDOException) {
    $telefoneWa   = '';
    $fotosGaleria = [];
}

$waLink = $telefoneWa
    ? 'https://wa.me/' . preg_replace('/\D/', '', $telefoneWa) . '?text=' . urlencode('Olá! Gostaria de saber mais sobre os serviços.')
    : '#';

$paginaTitulo = 'Belos Cílios — Extensão de Cílios e Design de Sobrancelhas';
$areaAtual    = 'publico';
require_once __DIR__ . '/geral/header.php';
?>
<style>
/* ═══════════════════════════════════════════════
   Landing — estilos inline
   ═══════════════════════════════════════════════ */

/* Breakout de container-lg para seções full-bleed */
.lp-full {
    position: relative;
    left: 50%;
    right: 50%;
    margin-left: -50vw;
    margin-right: -50vw;
    width: 100vw;
}

/* Scroll-reveal */
[data-r] {
    opacity: 0;
    transform: translateY(40px);
    transition: opacity .8s cubic-bezier(.22,1,.36,1), transform .8s cubic-bezier(.22,1,.36,1);
}
[data-r="L"] { transform: translateX(-48px); }
[data-r="R"] { transform: translateX( 48px); }
[data-r="s"] { transform: scale(.88); }
[data-r].on  { opacity: 1; transform: none; }

/* Stagger de filhos */
[data-stagger] > * {
    opacity: 0;
    transform: translateY(32px);
    transition: opacity .65s cubic-bezier(.22,1,.36,1), transform .65s cubic-bezier(.22,1,.36,1);
}
[data-stagger].on > *:nth-child(1) { opacity:1; transform:none; transition-delay:.05s; }
[data-stagger].on > *:nth-child(2) { opacity:1; transform:none; transition-delay:.15s; }
[data-stagger].on > *:nth-child(3) { opacity:1; transform:none; transition-delay:.25s; }
[data-stagger].on > *:nth-child(4) { opacity:1; transform:none; transition-delay:.35s; }
[data-stagger].on > *:nth-child(5) { opacity:1; transform:none; transition-delay:.45s; }
[data-stagger].on > *:nth-child(6) { opacity:1; transform:none; transition-delay:.55s; }

/* ── HERO ─────────────────────────────────────── */
.lp-hero {
    min-height: 100vh;
    background: #0d0020;
    display: flex;
    align-items: stretch;
    justify-content: center;
    overflow: hidden;
    margin-top: -1.5rem;
    position: relative;
    clip-path: polygon(0 0, 100% 0, 100% 93%, 50% 100%, 0 93%);
}

/* Orbs bokeh */
.lp-orb {
    position: absolute;
    border-radius: 50%;
    filter: blur(80px);
    pointer-events: none;
    animation: orbDrift 12s ease-in-out infinite;
}
.lp-orb-a {
    width: 520px; height: 520px;
    background: radial-gradient(circle at 40%, rgba(90,24,154,.65), transparent 65%);
    top: -140px; right: -80px;
    animation-duration: 14s;
}
.lp-orb-b {
    width: 380px; height: 380px;
    background: radial-gradient(circle at 55%, rgba(179,140,255,.28), transparent 65%);
    bottom: -100px; left: -60px;
    animation-duration: 10s; animation-delay: -5s;
}
.lp-orb-c {
    width: 220px; height: 220px;
    background: radial-gradient(circle at 50%, rgba(200,134,250,.35), transparent 65%);
    top: 35%; left: 20%;
    animation-duration: 18s; animation-delay: -9s;
}
.lp-orb-d {
    width: 160px; height: 160px;
    background: radial-gradient(circle at 50%, rgba(103,57,199,.4), transparent 65%);
    top: 20%; right: 28%;
    animation-duration: 11s; animation-delay: -3s;
}
@keyframes orbDrift {
    0%,100% { transform: translate(0,0) scale(1); }
    33%     { transform: translate(28px,-22px) scale(1.07); }
    66%     { transform: translate(-20px,14px) scale(.93); }
}

/* Partículas de brilho */
.lp-sparkle {
    position: absolute;
    inset: 0;
    pointer-events: none;
    overflow: hidden;
}
.lp-sparkle span {
    position: absolute;
    display: block;
    width: 2px; height: 2px;
    background: #e0aaff;
    border-radius: 50%;
    opacity: 0;
    animation: sparkle 6s ease-in-out infinite;
}
.lp-sparkle span:nth-child(1)  { top:18%; left:12%; animation-delay:0s;    animation-duration:7s;  }
.lp-sparkle span:nth-child(2)  { top:32%; left:75%; animation-delay:1.5s;  animation-duration:5s;  }
.lp-sparkle span:nth-child(3)  { top:55%; left:22%; animation-delay:3s;    animation-duration:8s;  }
.lp-sparkle span:nth-child(4)  { top:70%; left:60%; animation-delay:.8s;   animation-duration:6s;  }
.lp-sparkle span:nth-child(5)  { top:14%; left:50%; animation-delay:2.2s;  animation-duration:9s;  }
.lp-sparkle span:nth-child(6)  { top:80%; left:38%; animation-delay:4s;    animation-duration:5s;  }
.lp-sparkle span:nth-child(7)  { top:42%; left:88%; animation-delay:1s;    animation-duration:7s;  }
.lp-sparkle span:nth-child(8)  { top:62%; left:6%;  animation-delay:3.5s;  animation-duration:6s;  }
@keyframes sparkle {
    0%,100% { opacity:0; transform:scale(1); }
    50%     { opacity:.7; transform:scale(2.5); }
}

/* Wrapper principal do hero */
.lp-hero-wrap {
    position: relative; z-index: 1;
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    align-items: center;
    width: 100%;
    max-width: 1240px;
    padding: 4.5rem 2rem 0;
    min-height: 100vh;
    box-sizing: border-box;
}

/* Topo: logo + eyebrow — centralizado, full‑width */
.lp-hero-top {
    text-align: center;
    width: 100%;
    padding-bottom: 1.5rem;
}

/* Parte inferior: duas colunas */
.lp-hero-bottom {
    display: grid;
    grid-template-columns: 1fr;
    width: 100%;
    flex: 1;
    gap: 0;
}
@media (min-width: 768px) {
    .lp-hero-bottom {
        grid-template-columns: 1fr 1fr;
        align-items: end;
    }
}

/* Coluna de texto */
.lp-hero-text {
    text-align: center;
    padding: 0 0 3rem;
}
@media (min-width: 768px) {
    .lp-hero-text {
        text-align: left;
        padding-bottom: 8%;
        align-self: end;
    }
    .lp-hero-ctas { justify-content: flex-start; }
}

/* Coluna da foto */
.lp-hero-foto {
    display: none;
    align-items: flex-end;
    justify-content: center;
}
@media (min-width: 768px) {
    .lp-hero-foto { display: flex; align-self: end; }
}

.lp-hero-principal {
    display: block;
    width: 100%;
    max-width: 520px;
    height: auto;
    object-fit: contain;
    object-position: bottom center;
    align-self: flex-end;
    max-height: 92vh;
    animation: fadeSlideRight .9s cubic-bezier(.22,1,.36,1) forwards;
}
@keyframes fadeSlideRight {
    from { opacity:0; transform: translateX(40px); }
    to   { opacity:1; transform: none; }
}

.lp-hero-logo {
    height: clamp(140px, 18vw, 200px); width: auto;
    margin-bottom: 1.25rem;
    display: inline-block;
    animation: fadeSlideDown .9s cubic-bezier(.22,1,.36,1) both;
}
@keyframes fadeSlideDown {
    from { opacity:0; transform:translateY(-24px); }
    to   { opacity:.92; transform:none; }
}

.lp-hero-eyebrow-wrap {
    text-align: center;
    padding: 2rem 1rem 0;
}
.lp-hero-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: .6rem;
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .18em;
    text-transform: uppercase;
    color: var(--accent);
}
.lp-hero-eyebrow::before,
.lp-hero-eyebrow::after {
    content: '';
    display: block;
    width: 28px; height: 1px;
    background: var(--accent);
    opacity: .45;
}

.lp-hero-h1 {
    font-size: clamp(2.6rem, 7vw, 4.8rem);
    font-weight: 900;
    line-height: 1.05;
    letter-spacing: -.03em;
    color: #fff;
    margin-bottom: .75rem;
    text-wrap: balance;
    animation: fadeSlideDown .9s .3s cubic-bezier(.22,1,.36,1) both;
}
.lp-hero-h1 em {
    font-style: normal;
    color: #c886fa;
}

.lp-hero-p {
    font-size: 1.1rem;
    color: rgba(179,140,255,.72);
    margin-bottom: 2.75rem;
    animation: fadeSlideDown .9s .45s cubic-bezier(.22,1,.36,1) both;
}

.lp-hero-ctas {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    justify-content: center;
    animation: fadeSlideDown .9s .6s cubic-bezier(.22,1,.36,1) both;
}

.lp-btn-primary {
    background: #5a189a;
    color: #fff !important;
    border: none;
    padding: .9rem 2.4rem;
    border-radius: 60px;
    font-weight: 700;
    font-size: 1rem;
    letter-spacing: .01em;
    display: inline-flex; align-items: center; gap: .55rem;
    box-shadow: 0 4px 28px rgba(90,24,154,.55);
    transition: background .2s, transform .18s, box-shadow .2s;
    text-decoration: none !important;
    min-height: 52px;
}
.lp-btn-primary:hover {
    background: #240046;
    transform: translateY(-3px);
    box-shadow: 0 10px 40px rgba(90,24,154,.7);
}

.lp-btn-ghost {
    color: #e0aaff !important;
    border: 1.5px solid rgba(224,170,255,.38);
    padding: .9rem 2.4rem;
    border-radius: 60px;
    font-weight: 500;
    font-size: 1rem;
    background: transparent;
    display: inline-flex; align-items: center; gap: .55rem;
    transition: border-color .2s, color .2s, background .2s;
    text-decoration: none !important;
    min-height: 52px;
}
.lp-btn-ghost:hover {
    border-color: #e0aaff;
    color: #fff !important;
    background: rgba(255,255,255,.05);
}

.lp-scroll-cue {
    position: absolute;
    bottom: 3.5%;
    left: 50%; transform: translateX(-50%);
    color: rgba(179,140,255,.4);
    display: flex; flex-direction: column; align-items: center; gap: .4rem;
    font-size: .65rem; letter-spacing: .12em; text-transform: uppercase;
    animation: cueFloat 2.2s ease-in-out infinite;
}
.lp-scroll-cue i { font-size: 1.1rem; }
@keyframes cueFloat {
    0%,100% { transform: translateX(-50%) translateY(0); opacity:.4; }
    50%     { transform: translateX(-50%) translateY(9px); opacity:.8; }
}

/* ── SEÇÕES GERAIS ────────────────────────────── */
.lp-section {
    padding: 5rem 0;
}
.lp-label {
    font-size: .7rem;
    font-weight: 800;
    letter-spacing: .2em;
    text-transform: uppercase;
    color: var(--accent);
    margin-bottom: .75rem;
}
.lp-h2 {
    font-size: clamp(1.9rem, 4.5vw, 3rem);
    font-weight: 900;
    letter-spacing: -.025em;
    line-height: 1.08;
    color: var(--text-main);
    text-wrap: balance;
}
.lp-h2 em { font-style: normal; color: var(--accent); }

/* ── GALERIA ──────────────────────────────────── */
.lp-gallery-wrap {
    margin-top: 2rem;
}
.lp-gallery {
    display: grid;
    gap: .75rem;
    grid-template-columns: repeat(3, 1fr);
    grid-auto-rows: 160px;
}
@media (min-width: 640px) {
    .lp-gallery { grid-auto-rows: 210px; }
}
@media (min-width: 992px) {
    .lp-gallery {
        grid-template-columns: 2.2fr 1fr 1fr;
        grid-auto-rows: 230px;
    }
    .lp-gallery .lp-ph:first-child { grid-row: span 2; }
}

.lp-ph {
    border-radius: 14px;
    overflow: hidden;
    position: relative;
    background: #1a0040;
}
.lp-ph img {
    width: 100%; height: 100%;
    object-fit: cover;
    object-position: top center;
    display: block;
}

.lp-ph-tag {
    position: absolute;
    bottom: .75rem; left: .75rem;
    background: rgba(16,0,43,.65);
    backdrop-filter: blur(6px);
    color: #e0aaff;
    font-size: .68rem;
    font-weight: 600;
    letter-spacing: .08em;
    text-transform: uppercase;
    padding: .25rem .6rem;
    border-radius: 6px;
}

.lp-ig-cta {
    margin-top: 1.25rem;
    text-align: center;
    font-size: .85rem;
    color: var(--text-secondary);
}
.lp-ig-cta a {
    color: var(--accent);
    font-weight: 600;
}

/* ── STRIP DIFERENCIAIS ───────────────────────── */
.lp-strip {
    background: var(--accent);
    padding: 4.5rem 1.5rem;
    margin-top: 0;
}
.lp-strip-inner {
    max-width: 1140px;
    margin: 0 auto;
}
.lp-strip-head {
    text-align: center;
    color: #fff;
    margin-bottom: 3rem;
}
.lp-strip-head .lp-label { color: rgba(224,170,255,.65); }
.lp-strip-head .lp-h2   { color: #fff; }

.lp-dif-grid {
    display: grid;
    gap: 2.5rem;
    grid-template-columns: repeat(2, 1fr);
}
@media (min-width: 768px) {
    .lp-dif-grid { grid-template-columns: repeat(4, 1fr); }
}
.lp-dif {
    text-align: center;
    color: #fff;
}
.lp-dif-ico {
    width: 52px; height: 52px;
    border-radius: 14px;
    background: rgba(255,255,255,.12);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 1rem;
    font-size: 1.5rem;
    backdrop-filter: blur(4px);
}
.lp-dif-title {
    font-size: .95rem;
    font-weight: 700;
    margin-bottom: .4rem;
}
.lp-dif-desc {
    font-size: .82rem;
    opacity: .72;
    line-height: 1.5;
}

/* ── CTA FINAL ────────────────────────────────── */
.lp-cta {
    background: linear-gradient(145deg, #0d0020 0%, #3d0070 50%, #5a189a 100%);
    padding: 7rem 1.5rem;
    text-align: center;
    position: relative;
    overflow: hidden;
    margin-top: 5rem;
    clip-path: polygon(0 7%, 50% 0, 100% 7%, 100% 100%, 0 100%);
    padding-top: 9rem;
}
/* Dot pattern overlay */
.lp-cta::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image: radial-gradient(rgba(224,170,255,.08) 1px, transparent 1px);
    background-size: 28px 28px;
}
.lp-cta-orb {
    position: absolute;
    border-radius: 50%;
    filter: blur(60px);
    pointer-events: none;
}
.lp-cta-orb-1 {
    width: 400px; height: 400px;
    background: rgba(103,57,199,.4);
    top: -100px; right: -100px;
}
.lp-cta-orb-2 {
    width: 300px; height: 300px;
    background: rgba(179,140,255,.2);
    bottom: -60px; left: -60px;
}
.lp-cta-inner {
    position: relative; z-index: 1;
    max-width: 640px; margin: 0 auto;
}
.lp-cta h3 {
    font-size: clamp(2.2rem, 5.5vw, 3.4rem);
    font-weight: 900;
    letter-spacing: -.03em;
    color: #fff;
    text-wrap: balance;
    margin-bottom: 1rem;
}
.lp-cta h3 em { font-style: normal; color: #c886fa; }
.lp-cta p {
    font-size: 1.1rem;
    color: rgba(224,170,255,.75);
    margin-bottom: 2.75rem;
}
.lp-cta-btns {
    display: flex; flex-wrap: wrap; gap: 1rem; justify-content: center;
}
.lp-cta-btn-main {
    background: #fff;
    color: #5a189a !important;
    border: none;
    padding: 1rem 2.6rem;
    border-radius: 60px;
    font-weight: 800;
    font-size: 1.05rem;
    display: inline-flex; align-items: center; gap: .6rem;
    box-shadow: 0 6px 36px rgba(0,0,0,.35);
    transition: transform .2s, box-shadow .2s;
    text-decoration: none !important;
    min-height: 56px;
}
.lp-cta-btn-main:hover {
    transform: translateY(-4px);
    box-shadow: 0 14px 48px rgba(0,0,0,.45);
}
.lp-cta-btn-wa {
    color: #fff !important;
    border: 1.5px solid rgba(255,255,255,.35);
    padding: 1rem 2.2rem;
    border-radius: 60px;
    font-weight: 600;
    font-size: 1rem;
    background: rgba(255,255,255,.07);
    backdrop-filter: blur(4px);
    display: inline-flex; align-items: center; gap: .6rem;
    transition: border-color .2s, background .2s;
    text-decoration: none !important;
    min-height: 56px;
}
.lp-cta-btn-wa:hover {
    border-color: #fff;
    background: rgba(255,255,255,.14);
}
</style>

<!-- ═══════════════════════════════════════════════
     HERO
══════════════════════════════════════════════════ -->
<div class="lp-hero lp-full">
    <!-- Orbs -->
    <div class="lp-orb lp-orb-a"></div>
    <div class="lp-orb lp-orb-b"></div>
    <div class="lp-orb lp-orb-c"></div>
    <div class="lp-orb lp-orb-d"></div>
    <!-- Sparkles -->
    <div class="lp-sparkle" aria-hidden="true">
        <span></span><span></span><span></span><span></span>
        <span></span><span></span><span></span><span></span>
    </div>
    <!-- Grid -->
    <div class="lp-hero-wrap">
        <!-- Topo: só o logo centralizado -->
        <div class="lp-hero-top">
            <img src="<?= BASE ?>/geral/img/NomeCompleto.png"
                 alt="Belos Cílios" class="lp-hero-logo">
        </div>

        <!-- Colunas: texto esquerda + foto direita -->
        <div class="lp-hero-bottom">
            <div class="lp-hero-text">
                <h1 class="lp-hero-h1">
                    Beleza que<br>
                    <em>fala por você.</em>
                </h1>

                <p class="lp-hero-p">
                    Arte, técnica e cuidado para realçar o melhor em cada olhar.
                </p>

                <div class="lp-hero-ctas">
                    <a href="<?= BASE ?>/agendamento/index.php" class="lp-btn-primary">
                        <i class="bi bi-calendar-heart"></i> Agendar agora
                    </a>
                </div>
            </div>

            <!-- Foto da designer -->
            <div class="lp-hero-foto" aria-hidden="true">
                <img src="<?= BASE ?>/geral/img/Principal.png"
                     alt="" class="lp-hero-principal">
            </div>
        </div>
    </div>

    <div class="lp-scroll-cue" aria-hidden="true">
        <i class="bi bi-chevron-down"></i>
        <span>explorar</span>
    </div>
</div>

<div class="lp-hero-eyebrow-wrap">
    <div class="lp-hero-eyebrow">
        Extensão de Cílios &nbsp;·&nbsp; Design de Sobrancelhas
    </div>
</div>

<!-- ═══════════════════════════════════════════════
     GALERIA DE TRABALHOS
══════════════════════════════════════════════════ -->
<section class="lp-section" style="padding-top:0;">
    <div class="d-flex align-items-end justify-content-between mb-0 flex-wrap gap-2" data-r>
        <div>
            <div class="lp-label">Galeria</div>
            <h2 class="lp-h2">O trabalho<br><em>por si mesmo.</em></h2>
        </div>
        <p class="text-secondary mb-0" style="max-width:280px;font-size:.9rem;">
            Cada procedimento é único. Veja alguns resultados.
        </p>
    </div>

    <div class="lp-gallery-wrap">
        <div class="lp-gallery">
            <?php foreach ($fotosGaleria as $foto): ?>
            <div class="lp-ph">
                <img src="<?= BASE ?>/geral/img/galeria/<?= h($foto['NomeArquivo']) ?>"
                     alt="<?= h($foto['TituloExibicao'] ?? 'Resultado') ?>"
                     loading="lazy">
                <?php if (!empty($foto['TituloExibicao'])): ?>
                <div class="lp-ph-tag"><?= h($foto['TituloExibicao']) ?></div>
                <?php endif ?>
            </div>
            <?php endforeach ?>
        </div>
        <p class="lp-ig-cta">
            Mais resultados no Instagram →
            <a href="#" target="_blank" rel="noopener">@beloscilios</a>
        </p>
    </div>
</section>

<!-- ═══════════════════════════════════════════════
     DIFERENCIAIS (full-bleed)
══════════════════════════════════════════════════ -->
<div class="lp-strip lp-full">
    <div class="lp-strip-inner">
        <div class="lp-strip-head" data-r>
            <div class="lp-label">Por que nos escolher</div>
            <h2 class="lp-h2">Profissionalismo<br><em>em cada etapa.</em></h2>
        </div>
        <div class="lp-dif-grid" data-stagger>
            <div class="lp-dif">
                <div class="lp-dif-ico"><i class="bi bi-shield-check"></i></div>
                <div class="lp-dif-title">Materiais Premium</div>
                <div class="lp-dif-desc">Produtos hipoalergênicos, certificados e de marcas reconhecidas.</div>
            </div>
            <div class="lp-dif">
                <div class="lp-dif-ico"><i class="bi bi-clock-history"></i></div>
                <div class="lp-dif-title">Pontualidade</div>
                <div class="lp-dif-desc">Agenda organizada. Seu tempo é tão valioso quanto o nosso.</div>
            </div>
            <div class="lp-dif">
                <div class="lp-dif-ico"><i class="bi bi-phone"></i></div>
                <div class="lp-dif-title">Agendamento Online</div>
                <div class="lp-dif-desc">Reserve seu horário a qualquer momento, direto pelo celular.</div>
            </div>
            <div class="lp-dif">
                <div class="lp-dif-ico"><i class="bi bi-heart"></i></div>
                <div class="lp-dif-title">Atendimento Exclusivo</div>
                <div class="lp-dif-desc">Cuidado personalizado para realçar o que há de mais bonito em você.</div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════
     CTA FINAL (full-bleed)
══════════════════════════════════════════════════ -->
<div class="lp-cta lp-full">
    <div class="lp-cta-orb lp-cta-orb-1"></div>
    <div class="lp-cta-orb lp-cta-orb-2"></div>
    <div class="lp-cta-inner">
        <h3 data-r>Pronta para um<br><em>novo olhar?</em></h3>
        <p data-r>Agende online em minutos. Sem filas, sem ligações, sem complicação.</p>
        <div class="lp-cta-btns" data-r>
            <a href="<?= BASE ?>/usuario/cadastro.php" class="lp-cta-btn-main">
                <i class="bi bi-calendar-heart"></i> Quero agendar
            </a>
            <?php if ($telefoneWa): ?>
            <a href="<?= h($waLink) ?>" target="_blank" rel="noopener" class="lp-cta-btn-wa">
                <i class="bi bi-whatsapp"></i> Falar no WhatsApp
            </a>
            <?php endif ?>
        </div>
    </div>
</div>

<script>
(function () {
    // Scroll-reveal
    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
            if (e.isIntersecting) {
                e.target.classList.add('on');
                io.unobserve(e.target);
            }
        });
    }, { threshold: 0.12 });

    document.querySelectorAll('[data-r], [data-stagger]').forEach(function (el) {
        io.observe(el);
    });

    // Scroll suave nos links âncora
    document.querySelectorAll('a[href^="#"]').forEach(function (a) {
        a.addEventListener('click', function (e) {
            var target = document.querySelector(this.getAttribute('href'));
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });
})();
</script>

<?php require_once __DIR__ . '/geral/footer.php' ?>
