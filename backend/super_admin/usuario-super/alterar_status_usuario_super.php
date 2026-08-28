<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/**
 * ==========================================================
 * ALTERAR STATUS USUÁRIO SUPER / USUÁRIO
 * ----------------------------------------------------------
 * Recebe:
 *   - id_usuario (POST)
 *
 * Regras:
 *   - altera o status diretamente na tabela usuario
 *   - ativo <-> inativo
 *   - bloqueado NÃO altera
 *
 * Retorno:
 *   JSON padrão
 * ==========================================================
 */

if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

/* ==========================================================
   MÉTODO
========================================================== */
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    out([
        'ok' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'user_msg' => 'Método não permitido.',
    ], 405);
}

require __DIR__ . '/../../_auth/bloquear.php';

/* ==========================================================
   CONEXÃO
========================================================== */
require_once __DIR__ . '/../../_config/conexao.php';
require_once __DIR__ . '/../../_servicos/auditoria.php';

if (!isset($conexao) || !($conexao instanceof mysqli)) {
    out([
        'ok' => false,
        'code' => 'DB_CONN_MISSING',
        'user_msg' => 'Conexão com banco não encontrada.',
    ], 500);
}

if ($conexao->connect_errno) {
    out([
        'ok' => false,
        'code' => 'DB_CONN_ERROR',
        'user_msg' => 'Falha ao conectar no banco.',
    ], 500);
}

$conexao->set_charset('utf8mb4');

/* ==========================================================
   VALIDAÇÃO DE ENTRADA
========================================================== */
$idUsuario = filter_input(INPUT_POST, 'id_usuario', FILTER_VALIDATE_INT);

if (!$idUsuario || $idUsuario <= 0) {
    out([
        'ok' => false,
        'code' => 'INVALID_ID',
        'user_msg' => 'ID do usuário inválido.'
    ], 400);
}

try {
    /* ==========================================================
       BUSCAR USUÁRIO
    ========================================================== */
    $sql = "
        SELECT
            id_usuario,
            nome,
            email,
            status,
            tipo_usuario
        FROM usuario
        WHERE id_usuario = ?
        LIMIT 1
    ";

    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new Exception('Falha ao preparar SELECT do usuário.');
    }

    $stmt->bind_param('i', $idUsuario);
    $stmt->execute();

    $result = $stmt->get_result();
    $usuario = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$usuario) {
        out([
            'ok' => false,
            'code' => 'NOT_FOUND',
            'user_msg' => 'Usuário não encontrado.'
        ], 404);
    }

    $idUsuarioDb = (int)($usuario['id_usuario'] ?? 0);
    $nomeUsuario = (string)($usuario['nome'] ?? '');
    $emailUsuario = (string)($usuario['email'] ?? '');
    $tipoUsuario = (string)($usuario['tipo_usuario'] ?? '');
    $statusAtual = trim(mb_strtolower((string)($usuario['status'] ?? ''), 'UTF-8'));

    if ($idUsuarioDb <= 0) {
        out([
            'ok' => false,
            'code' => 'INVALID_USER',
            'user_msg' => 'Usuário inválido.'
        ], 422);
    }

    if ($tipoUsuario !== 'super_admin') {
        out(['ok'=>false,'code'=>'INVALID_USER_TYPE','user_msg'=>'O usuário informado não é um Super Admin.'], 422);
    }

    /* ==========================================================
       REGRA DE NEGÓCIO
    ========================================================== */
    if ($statusAtual === 'bloqueado') {
        out([
            'ok' => false,
            'code' => 'BLOCKED',
            'user_msg' => 'Usuário bloqueado não pode ter status alterado.'
        ], 403);
    }

    if (!in_array($statusAtual, ['ativo', 'inativo'], true)) {
        out([
            'ok' => false,
            'code' => 'INVALID_CURRENT_STATUS',
            'user_msg' => 'Status atual do usuário é inválido para alteração.'
        ], 422);
    }

    $novoStatus = ($statusAtual === 'ativo') ? 'inativo' : 'ativo';

    /* ==========================================================
       UPDATE
    ========================================================== */
    $conexao->begin_transaction();
    $sqlUpdate = "
        UPDATE usuario
        SET status = ?
        WHERE id_usuario = ?
        LIMIT 1
    ";

    $stmtUpdate = $conexao->prepare($sqlUpdate);

    if (!$stmtUpdate) {
        throw new Exception('Falha ao preparar UPDATE do usuário.');
    }

    $stmtUpdate->bind_param('si', $novoStatus, $idUsuarioDb);
    $ok = $stmtUpdate->execute();

    if (!$ok) {
        $errno = (int)$stmtUpdate->errno;
        $error = (string)$stmtUpdate->error;
        $stmtUpdate->close();

        out([
            'ok' => false,
            'code' => 'UPDATE_ERROR',
            'user_msg' => 'Erro ao atualizar status do usuário.',
            'debug' => [
                'errno' => $errno,
                'error' => $error,
            ],
        ], 500);
    }

    $stmtUpdate->close();

    auditoriaRegistrar($conexao, 'super_admin.status_alterado', [
        'ator'=>auditoriaResolverAtorSuperAdmin($conexao),
        'entidade_id'=>$idUsuarioDb,'entidade_rotulo'=>$nomeUsuario,
        'descricao'=>'Alterou o status do Super Admin ' . $nomeUsuario . '.',
        'alteracoes'=>['status'=>['antes'=>$statusAtual,'depois'=>$novoStatus]],
        'contexto'=>['origem'=>'painel_super_admin'],
    ]);
    $conexao->commit();

    /* ==========================================================
       RESPOSTA
    ========================================================== */
    out([
        'ok' => true,
        'code' => 'STATUS_UPDATED',
        'user_msg' => "Status do usuário '{$nomeUsuario}' alterado com sucesso.",
        'data' => [
            'id_usuario' => $idUsuarioDb,
            'nome' => $nomeUsuario,
            'email' => $emailUsuario,
            'tipo_usuario' => $tipoUsuario,
            'status_anterior' => $statusAtual,
            'novo_status' => $novoStatus,
        ],
    ], 200);

} catch (Throwable $e) {
    if (isset($conexao) && $conexao instanceof mysqli) { try { $conexao->rollback(); } catch (Throwable) {} }
    out([
        'ok' => false,
        'code' => 'SERVER_ERROR',
        'user_msg' => 'Erro interno ao alterar o status do usuário.',
    ], 500);
}
