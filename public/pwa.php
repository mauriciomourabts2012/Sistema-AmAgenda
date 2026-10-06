<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../backend/_regras/pwa_entry.php';

$destino = pwaDestinoSessao($_SESSION);
header('Location: ' . $destino, true, 302);
exit;
