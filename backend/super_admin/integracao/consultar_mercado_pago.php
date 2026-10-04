<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    out(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED', 'user_msg' => 'Método não permitido.'], 405);
}

require __DIR__ . '/../../_auth/bloquear.php';
require_once __DIR__ . '/../../_gateways/mercado_pago.php';

// O painel recebe somente estados e a URL pública calculada; nenhum segredo ou derivação dele é exposto.
try {
    $diagnostico = MercadoPagoGateway::diagnosticoConfiguracaoSeguro();
} catch (Throwable) {
    $diagnostico = [
        'ambiente' => null,
        'ambiente_configurado' => false,
        'public_key_configurada' => false,
        'access_token_configurado' => false,
        'webhook_secret_configurado' => false,
        'url_publica_configurada' => false,
        'webhook_url' => null,
        'pronto' => false,
        'status_geral' => 'configuracao_incompleta',
    ];
}

out([
    'ok' => true,
    'code' => 'MERCADO_PAGO_CONFIGURATION_STATUS',
    'data' => $diagnostico,
]);
