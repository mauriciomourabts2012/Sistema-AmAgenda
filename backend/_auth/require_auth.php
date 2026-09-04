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

$tipoUsuario = mb_strtolower(trim((string)($auth['tipo_usuario'] ?? '')), 'UTF-8');
$idEmpresa = (int)($auth['empresa_id'] ?? $auth['id_empresa'] ?? $_SESSION['empresa_id'] ?? 0);

require_once __DIR__ . '/../_config/conexao.php';

if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
    out([
        'ok' => false,
        'code' => 'SESSION_USER_CHECK_ERROR',
        'user_msg' => 'Não foi possível validar sua sessão.'
    ], 500);
}

$stmt = $conexao->prepare(
    "SELECT status, deve_alterar_senha,
            (deve_alterar_senha = 1
             AND data_senha_temporaria IS NOT NULL
             AND CURRENT_TIMESTAMP >= DATE_ADD(data_senha_temporaria, INTERVAL 24 HOUR)) AS senha_temporaria_vencida
       FROM usuario
      WHERE id_usuario = ?
      LIMIT 1"
);
if (!$stmt) {
    out([
        'ok' => false,
        'code' => 'SESSION_USER_CHECK_ERROR',
        'user_msg' => 'Não foi possível validar sua sessão.'
    ], 500);
}

$stmt->bind_param('i', $idUsuario);
if (!$stmt->execute()) {
    $stmt->close();
    out([
        'ok' => false,
        'code' => 'SESSION_USER_CHECK_ERROR',
        'user_msg' => 'Não foi possível validar sua sessão.'
    ], 500);
}
$resultadoUsuario = $stmt->get_result();
$usuarioAtual = $resultadoUsuario ? ($resultadoUsuario->fetch_assoc() ?: null) : null;
$stmt->close();

if (!$usuarioAtual || mb_strtolower(trim((string)($usuarioAtual['status'] ?? '')), 'UTF-8') !== 'ativo') {
    out([
        'ok' => false,
        'code' => 'SESSION_USER_INACTIVE',
        'user_msg' => 'Seu usuário não está ativo. Faça login novamente.'
    ], 403);
}

$deveAlterarSenha = (int)($usuarioAtual['deve_alterar_senha'] ?? 0) === 1;
$senhaTemporariaVencida = (int)($usuarioAtual['senha_temporaria_vencida'] ?? 0) === 1;
$_SESSION['auth']['deve_alterar_senha'] = $deveAlterarSenha;
$_SESSION['auth']['senha_temporaria_vencida'] = $senhaTemporariaVencida;

if ($senhaTemporariaVencida && !defined('AUTH_PERMITIR_SENHA_TEMPORARIA_VENCIDA')) {
    out([
        'ok' => false,
        'code' => 'SENHA_TEMPORARIA_EXPIRADA',
        'user_msg' => 'Altere sua senha temporária para continuar utilizando o AmAgenda.'
    ], 403);
}

if ($tipoUsuario !== 'super_admin' && $idEmpresa > 0) {
    $stmt = $conexao->prepare('SELECT bloqueado_plano FROM empresa_usuario WHERE id_usuario = ? AND id_empresa = ? LIMIT 1');
    if (!$stmt) {
        out([
            'ok' => false,
            'code' => 'SESSION_PLAN_CHECK_ERROR',
            'user_msg' => 'Não foi possível validar sua sessão.'
        ], 500);
    }

    $stmt->bind_param('ii', $idUsuario, $idEmpresa);
    if (!$stmt->execute()) {
        $stmt->close();
        out([
            'ok' => false,
            'code' => 'SESSION_PLAN_CHECK_ERROR',
            'user_msg' => 'Não foi possível validar sua sessão.'
        ], 500);
    }
    $stmt->bind_result($bloqueadoPlano);
    $vinculoEncontrado = $stmt->fetch();
    $stmt->close();

    // O bloqueio do plano prevalece enquanto o vínculo autenticado estiver bloqueado.
    if ($vinculoEncontrado && (int)$bloqueadoPlano === 1) {
        out([
            'ok' => false,
            'code' => 'SESSION_ACCESS_DENIED',
            'user_msg' => 'Acesso indisponível para o plano atual.'
        ], 403);
    }
}
