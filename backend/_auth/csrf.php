<?php
declare(strict_types=1);

/** Token CSRF único da sessão, reutilizado pelas APIs autenticadas. */
function csrfTokenSessao(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $token = (string)($_SESSION['csrf_token'] ?? '');
    if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
    }

    return $token;
}

function csrfValidarSessao(): void
{
    $recebido = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $esperado = (string)($_SESSION['csrf_token'] ?? '');

    if ($esperado === '' || $recebido === '' || !hash_equals($esperado, $recebido)) {
        out([
            'ok' => false,
            'code' => 'CSRF_INVALID',
            'user_msg' => 'Sua sessão expirou. Atualize a página e tente novamente.',
        ], 403);
    }
}
