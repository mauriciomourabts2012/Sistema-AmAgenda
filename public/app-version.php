<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$arquivoVersao = __DIR__ . '/../backend/_config/app-version.json';
$conteudo = @file_get_contents($arquivoVersao);
$configuracao = is_string($conteudo) ? json_decode($conteudo, true) : null;
$versao = is_array($configuracao) ? ($configuracao['version'] ?? null) : null;

if (!is_string($versao) || preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/D', $versao) !== 1) {
    error_log('[app-version] Fonte central de versao ausente ou invalida.');
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'code' => 'APP_VERSION_UNAVAILABLE',
        'user_msg' => 'Não foi possível consultar a versão atual do AmAgenda.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode([
    'ok' => true,
    'version' => $versao,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
