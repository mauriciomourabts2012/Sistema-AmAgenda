<?php
declare(strict_types=1);

session_start();

function go(string $url): void {
    header("Location: {$url}");
    exit;
}

/*
|--------------------------------------------------------------------------
| Logout Super Admin
|--------------------------------------------------------------------------
| - Remove sessão de empresa
| - Remove sessão de autenticação
| - Destrói sessão
*/

unset($_SESSION['empresa_id']);
unset($_SESSION['auth']);

session_destroy();

go('/public/views/login-super-admin.html');