<?php
header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
require_once __DIR__ . '/config/conexao.php';
$b = defined('BASE') ? BASE : '';
?>
// Belos Cílios Service Worker — v1.0
const CACHE_NAME = 'beloscilios-v1';

self.addEventListener('install',  () => self.skipWaiting());
self.addEventListener('activate', e  => e.waitUntil(clients.claim()));

// Fetch: navegações vão direto ao servidor; assets com fallback gracioso
self.addEventListener('fetch', e => {
    if (e.request.mode === 'navigate') return;
    e.respondWith(
        fetch(e.request).catch(() => new Response('', { status: 503, statusText: 'Offline' }))
    );
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
