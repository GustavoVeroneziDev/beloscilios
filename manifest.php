<?php
header('Content-Type: application/manifest+json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
require_once __DIR__ . '/config/conexao.php';
$b = defined('BASE') ? BASE : '';

echo json_encode([
    'name'             => 'Belos Cílios',
    'short_name'       => 'Belos Cílios',
    'description'      => 'Agendamento de serviços de cílios e sobrancelhas',
    'start_url'        => $b . '/painel/index.php',
    'scope'            => $b . '/',
    'display'          => 'standalone',
    'orientation'      => 'portrait-primary',
    'background_color' => '#10002b',
    'theme_color'      => '#5a189a',
    'lang'             => 'pt-BR',
    'icons'            => [
        // 'any maskable' combinados numa entrada é inválido — entradas separadas
        [
            'src'     => $b . '/geral/img/LogoCírculo.png',
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => $b . '/geral/img/LogoCírculo.png',
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => $b . '/geral/img/LogoCírculo.png',
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'maskable',
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
