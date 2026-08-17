<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/**
 * ==========================================================
 * ALTERAR STATUS USUÁRIO (toggle ativo/inativo)
 * ----------------------------------------------------------
 * Recebe:
 *   - id_usuario (POST)
 *   - id_empresa (POST) [opcional por enquanto]
 *
 * Regras:
 *   - altera o status do vínculo na tabela empresa_usuario
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

/* ==========================================================
   CONEXÃO
========================================================== */
require_once __DIR__ . '/../../_config/conexao.php';

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
$idEmpresa = filter_input(INPUT_POST, 'id_empresa', FILTER_VALIDATE_INT);

if (!$idUsuario || $idUsuario <= 0) {
    out([
        'ok' => false,
        'code' => 'INVALID_ID',
        'user_msg' => 'ID do usuário inválido.'
    ], 400);
}

try {
    /* ==========================================================
       BUSCAR USUÁRIO + VÍNCULO
       - se vier id_empresa, busca vínculo exato
       - se não vier, tenta buscar o primeiro vínculo do usuário
    ========================================================== */
    if ($idEmpresa && $idEmpresa > 0) {
        $sql = "
            SELECT
                u.nome,
                eu.id_empresa_usuario,
                eu.id_empresa,
                eu.status
            FROM empresa_usuario eu
            INNER JOIN usuario u
                ON u.id_usuario = eu.id_usuario
            WHERE eu.id_usuario = ?
              AND eu.id_empresa = ?
            LIMIT 1
        ";

        $stmt = $conexao->prepare($sql);
        if (!$stmt) {
            throw new Exception('Falha ao preparar SELECT do vínculo do usuário.');
        }

        $stmt->bind_param('ii', $idUsuario, $idEmpresa);
    } else {
        $sql = "
            SELECT
                u.nome,
                eu.id_empresa_usuario,
                eu.id_empresa,
                eu.status
            FROM empresa_usuario eu
            INNER JOIN usuario u
                ON u.id_usuario = eu.id_usuario
            WHERE eu.id_usuario = ?
            ORDER BY eu.id_empresa_usuario ASC
            LIMIT 1
        ";

        $stmt = $conexao->prepare($sql);
        if (!$stmt) {
            throw new Exception('Falha ao preparar SELECT do vínculo do usuário.');
        }

        $stmt->bind_param('i', $idUsuario);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $vinculo = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$vinculo) {
        out([
            'ok' => false,
            'code' => 'NOT_FOUND',
            'user_msg' => 'Vínculo do usuário com a empresa não encontrado.'
        ], 404);
    }

    $idEmpresaUsuario = (int)($vinculo['id_empresa_usuario'] ?? 0);
    $idEmpresaVinculo = (int)($vinculo['id_empresa'] ?? 0);
    $statusAtual = trim(mb_strtolower((string)($vinculo['status'] ?? ''), 'UTF-8'));
    $nomeUsuario = (string)($vinculo['nome'] ?? '');

    if ($idEmpresaUsuario <= 0) {
        out([
            'ok' => false,
            'code' => 'INVALID_LINK',
            'user_msg' => 'Vínculo do usuário inválido.'
        ], 422);
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
    $sqlUpdate = "
        UPDATE empresa_usuario
        SET status = ?
        WHERE id_empresa_usuario = ?
        LIMIT 1
    ";

    $stmtUpdate = $conexao->prepare($sqlUpdate);

    if (!$stmtUpdate) {
        throw new Exception('Falha ao preparar UPDATE do vínculo do usuário.');
    }

    $stmtUpdate->bind_param('si', $novoStatus, $idEmpresaUsuario);
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

    /* ==========================================================
       RESPOSTA
    ========================================================== */
    out([
        'ok' => true,
        'code' => 'STATUS_UPDATED',
        'user_msg' => "Status do usuário '{$nomeUsuario}' alterado com sucesso.",
        'data' => [
            'id_usuario' => $idUsuario,
            'id_empresa' => $idEmpresaVinculo,
            'id_empresa_usuario' => $idEmpresaUsuario,
            'status_anterior' => $statusAtual,
            'novo_status' => $novoStatus,
        ],
    ], 200);

} catch (Throwable $e) {
    out([
        'ok' => false,
        'code' => 'SERVER_ERROR',
        'user_msg' => 'Erro interno ao alterar o status do usuário.',
    ], 500);
}