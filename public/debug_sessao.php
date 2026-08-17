<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DEBUG DE SESSÃO - AMAGENDA
|--------------------------------------------------------------------------
| Este arquivo serve apenas para teste temporário.
|
| Ele mostra os dados atuais da sessão PHP do navegador logado, incluindo:
| - dados salvos em $_SESSION['auth'];
| - id da empresa encontrado na sessão;
| - todas as chaves disponíveis em $_SESSION.
|
| O objetivo é verificar se o usuário logado está realmente vinculado à
| empresa correta. No caso do teste dos serviços da Roseane, a sessão precisa
| resolver id_empresa = 2, porque os serviços cadastrados estão nessa empresa.
|
| IMPORTANTE:
| Este arquivo expõe informações internas da sessão e não deve ficar no
| sistema em produção. Use apenas para teste e apague depois.
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$auth = $_SESSION['auth'] ?? null;

$idEmpresaSessao = 0;

if (isset($auth['id_empresa'])) {
    $idEmpresaSessao = (int)$auth['id_empresa'];
} elseif (isset($_SESSION['empresa_id'])) {
    $idEmpresaSessao = (int)$_SESSION['empresa_id'];
} elseif (isset($_SESSION['id_empresa'])) {
    $idEmpresaSessao = (int)$_SESSION['id_empresa'];
} elseif (isset($_SESSION['empresa']['id_empresa'])) {
    $idEmpresaSessao = (int)$_SESSION['empresa']['id_empresa'];
} elseif (isset($_SESSION['empresa']['id'])) {
    $idEmpresaSessao = (int)$_SESSION['empresa']['id'];
}

echo json_encode([
    'ok' => true,
    'id_empresa_resolvido' => $idEmpresaSessao,
    'auth' => $auth,
    'session' => $_SESSION,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);