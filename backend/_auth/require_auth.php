<?php
//php para sessao do alterar perfil do modal perfil
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$auth = $_SESSION['auth'] ?? null;

$idUsuario = (int)($auth['id_usuario'] ?? 0);
$statusUsuario = (string)($auth['status'] ?? '');

if ($idUsuario <= 0) {
    out([
        'ok' => false,
        'code' => 'NOT_AUTHENTICATED',
        'user_msg' => 'Sessão expirada. Faça login novamente.'
    ], 401);
}

if ($statusUsuario !== '' && $statusUsuario !== 'ativo') {
    out([
        'ok' => false,
        'code' => 'SESSION_USER_INACTIVE',
        'user_msg' => 'Seu usuário não está ativo. Faça login novamente.'
    ], 403);
}