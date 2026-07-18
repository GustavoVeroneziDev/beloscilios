<?php
header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
require_once __DIR__ . '/config/conexao.php';
$b = defined('BASE') ? BASE : '';
?>
// Belos Cílios Service Worker — v3.0
const CACHE_NAME = 'beloscilios-v3';

// Assets estáticos que não mudam entre requisições
const STATIC_ASSETS = [
    '<?= $b ?>/geral/vendor/bs/css/bootstrap.min.css',
    '<?= $b ?>/geral/vendor/bi/font/bootstrap-icons.min.css',
    '<?= $b ?>/geral/vendor/bs/js/bootstrap.bundle.min.js',
    '<?= $b ?>/geral/img/LogoCírculo.png',
    '<?= $b ?>/geral/img/ico.ico',
];

// Pré-cache dos assets críticos na instalação
self.addEventListener('install', e => {
    e.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(STATIC_ASSETS).catch(() => {}))
            .then(() => self.skipWaiting())
    );
});

// Remove caches de versões antigas na ativação
self.addEventListener('activate', e => e.waitUntil(
    caches.keys()
        .then(keys => Promise.all(
            keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
        ))
        .then(() => clients.claim())
));

self.addEventListener('fetch', e => {
    const req = e.request;
    const url = new URL(req.url);

    // Só intercepta GET — POST/PUT/DELETE vão direto para o servidor
    if (req.method !== 'GET') return;

    // Navegações (páginas PHP dinâmicas) → sempre do servidor
    if (req.mode === 'navigate') return;

    // Assets estáticos (vendor, imagens, fontes, CSS, JS) → cache-first
    const isStatic = /\.(css|js|png|ico|jpg|jpeg|gif|svg|webp|woff2?|ttf|eot)(\?.*)?$/.test(url.pathname)
                  || url.pathname.includes('/vendor/')
                  || url.pathname.includes('/geral/img/');

    if (isStatic && url.origin === self.location.origin) {
        e.respondWith(
            caches.match(req).then(cached => {
                if (cached) return cached;
                return fetch(req).then(res => {
                    if (res.ok) {
                        const clone = res.clone();
                        caches.open(CACHE_NAME).then(c => c.put(req, clone));
                    }
                    return res;
                }).catch(() => cached || new Response('', { status: 503 }));
            })
        );
    }
    // Demais GET (APIs, etc.) → rede direta, sem interceptar
});

// Push: exibe notificação nativa
self.addEventListener('push', e => {
    let d = { title: 'Belos Cílios', body: 'Você tem uma novidade.', url: '<?= $b ?>/painel/index.php' };
    if (e.data) { try { d = Object.assign(d, e.data.json()); } catch (_) {} }
    e.waitUntil(
        self.registration.showNotification(d.title, {
            body:  d.body,
            icon:  '<?= $b ?>/geral/img/LogoCírculo.png',
            badge: '<?= $b ?>/geral/img/LogoCírculo.png',
            data:  { url: d.url },
            tag:   d.tag || undefined,
        })
    );
});

// Clique na notificação
self.addEventListener('notificationclick', e => {
    e.notification.close();
    const url = e.notification.data?.url || '<?= $b ?>/painel/index.php';
    e.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(lista => {
            for (const c of lista) {
                if ('focus' in c) { c.navigate(url); return c.focus(); }
            }
            if (clients.openWindow) return clients.openWindow(url);
        })
    );
});
