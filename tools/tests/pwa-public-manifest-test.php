<?php
declare(strict_types=1);

$endpoint = __DIR__ . '/../../public/manifest-agenda-online.php';
if (!is_file($endpoint)) {
    fwrite(STDERR, "FAIL: manifest contextual da Agenda Online ainda não existe.\n");
    exit(1);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION = [
    'empresa_id' => 7,
    'empresa_nome' => 'Studio Exemplo',
    'empresa_slug' => 'studio-exemplo',
];
ob_start();
include $endpoint;
$saida = (string)ob_get_clean();
$manifesto = json_decode($saida, true, 512, JSON_THROW_ON_ERROR);

if (($manifesto['start_url'] ?? null) !== '/agendar/studio-exemplo') {
    fwrite(STDERR, "FAIL: start_url não preservou o slug validado.\n");
    exit(1);
}
if (($manifesto['id'] ?? null) !== '/agendar/studio-exemplo') {
    fwrite(STDERR, "FAIL: id não representa a Agenda Online instalada.\n");
    exit(1);
}
if (($manifesto['scope'] ?? null) !== '/') {
    fwrite(STDERR, "FAIL: scope público inválido.\n");
    exit(1);
}
if (($manifesto['icons'][0]['src'] ?? null) !== '/public/imagens/PWA/app-icon-192.png') {
    fwrite(STDERR, "FAIL: ícones públicos não usam URL absoluta segura.\n");
    exit(1);
}

fwrite(STDOUT, "PASS: manifest contextual preserva /agendar/{slug}.\n");
