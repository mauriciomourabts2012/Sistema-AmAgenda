<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/**
 * ==========================================================
 * ALTERAR STATUS PLANO (toggle ativo/inativo)
 * ----------------------------------------------------------
 * Recebe:
 *   - id_plano (POST)
 *
 * Regras:
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
$idPlano = filter_input(INPUT_POST, 'id_plano', FILTER_VALIDATE_INT);

if (!$idPlano || $idPlano <= 0) {
    out([
        'ok' => false,
        'code' => 'INVALID_ID',
        'user_msg' => 'ID do plano inválido.'
    ], 400);
}

try {
    /* ==========================================================
       BUSCAR STATUS ATUAL
    ========================================================== */
    $sql = "SELECT nome, status FROM plano WHERE id_plano = ? LIMIT 1";
    $stmt = $conexao->prepare($sql);

    if (!$stmt) {
        throw new Exception('Falha ao preparar SELECT do plano.');
    }

    $stmt->bind_param('i', $idPlano);
    $stmt->execute();

    $result = $stmt->get_result();
    $plano = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$plano) {
        out([
            'ok' => false,
            'code' => 'NOT_FOUND',
            'user_msg' => 'Plano não encontrado.'
        ], 404);
    }

    $statusAtual = (string)($plano['status'] ?? '');
    $nomePlano   = (string)($plano['nome'] ?? '');

    /* ==========================================================
       REGRA DE NEGÓCIO
    ========================================================== */
    if ($statusAtual === 'bloqueado') {
        out([
            'ok' => false,
            'code' => 'BLOCKED',
            'user_msg' => 'Plano bloqueado não pode ter status alterado.'
        ], 403);
    }

    if (!in_array($statusAtual, ['ativo', 'inativo'], true)) {
        out([
            'ok' => false,
            'code' => 'INVALID_CURRENT_STATUS',
            'user_msg' => 'Status atual do plano é inválido para alteração.'
        ], 422);
    }

    $novoStatus = ($statusAtual === 'ativo') ? 'inativo' : 'ativo';

    /* ==========================================================
       UPDATE
    ========================================================== */
    $sqlUpdate = "UPDATE plano SET status = ? WHERE id_plano = ?";
    $stmtUpdate = $conexao->prepare($sqlUpdate);

    if (!$stmtUpdate) {
        throw new Exception('Falha ao preparar UPDATE do plano.');
    }

    $stmtUpdate->bind_param('si', $novoStatus, $idPlano);
    $ok = $stmtUpdate->execute();

    if (!$ok) {
        $errno = (int)$stmtUpdate->errno;
        $error = (string)$stmtUpdate->error;
        $stmtUpdate->close();

        out([
            'ok' => false,
            'code' => 'UPDATE_ERROR',
            'user_msg' => 'Erro ao atualizar status do plano.',
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
        'user_msg' => "Status do plano '{$nomePlano}' alterado com sucesso.",
        'data' => [
            'id_plano' => $idPlano,
            'status_anterior' => $statusAtual,
            'novo_status' => $novoStatus,
        ],
    ], 200);

} catch (Throwable $e) {
    out([
        'ok' => false,
        'code' => 'SERVER_ERROR',
        'user_msg' => 'Erro interno ao alterar o status do plano.',
    ], 500);
}