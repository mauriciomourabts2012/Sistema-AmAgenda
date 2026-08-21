<?php
declare(strict_types=1);

// Proteção autoritativa das rotas exclusivas do Super Admin.
// A interface pode ocultar abas, mas a autorização sempre é decidida pela sessão.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$authSuperAdmin = $_SESSION['auth'] ?? null;
$idUsuarioSuperAdmin = (int)($authSuperAdmin['id_usuario'] ?? 0);
$tipoUsuarioSuperAdmin = mb_strtolower(trim((string)($authSuperAdmin['tipo_usuario'] ?? '')), 'UTF-8');
$statusUsuarioSuperAdmin = mb_strtolower(trim((string)($authSuperAdmin['status'] ?? '')), 'UTF-8');

if ($idUsuarioSuperAdmin <= 0) {
    out(['ok' => false, 'code' => 'NOT_AUTHENTICATED', 'user_msg' => 'Sessão expirada. Faça login novamente.'], 401);
}

if ($tipoUsuarioSuperAdmin !== 'super_admin') {
    out(['ok' => false, 'code' => 'ACCESS_DENIED', 'user_msg' => 'Você não possui permissão para acessar este recurso.'], 403);
}

if ($statusUsuarioSuperAdmin !== '' && $statusUsuarioSuperAdmin !== 'ativo') {
    out(['ok' => false, 'code' => 'SESSION_USER_INACTIVE', 'user_msg' => 'Seu usuário não está ativo. Faça login novamente.'], 403);
}
