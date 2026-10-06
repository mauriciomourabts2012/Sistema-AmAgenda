<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/manifest+json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$slug = trim((string)($_SESSION['empresa_slug'] ?? ''));

if ($empresaId <= 0 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
    http_response_code(404);
    echo json_encode(['error' => 'Contexto da Agenda Online indisponível.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

$entradaPublica = '/agendar/' . rawurlencode($slug);
$manifesto = [
    'id' => $entradaPublica,
    'name' => 'AmAgenda',
    'short_name' => 'AmAgenda',
    'description' => 'Agenda Online para agendamentos e acompanhamento de horários.',
    'lang' => 'pt-BR',
    'dir' => 'ltr',
    'start_url' => $entradaPublica,
    'scope' => '/',
    'display' => 'standalone',
    'theme_color' => '#111827',
    'background_color' => '#FFFFFF',
    'orientation' => 'portrait-primary',
    'icons' => [
        [
            'src' => '/public/imagens/PWA/app-icon-192.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => '/public/imagens/PWA/app-icon-512.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => '/public/imagens/PWA/app-icon-maskable-192.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'maskable',
        ],
        [
            'src' => '/public/imagens/PWA/app-icon-maskable-512.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'maskable',
        ],
    ],
];

echo json_encode($manifesto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
