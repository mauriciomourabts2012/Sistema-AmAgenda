<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/**
 * ==========================================================
 * ALTERAR STATUS EMPRESA (toggle ativo/inativo)
 * ----------------------------------------------------------
 * Recebe:
 *   - id_empresa (POST)
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
   Ajuste o caminho conforme a estrutura real do projeto
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
$idEmpresa = filter_input(INPUT_POST, 'id_empresa', FILTER_VALIDATE_INT);

if (!$idEmpresa || $idEmpresa <= 0) {
    out([
        'ok' => false,
        'code' => 'INVALID_ID',
        'user_msg' => 'ID da empresa inválido.'
    ], 400);
}

try {
    /* ==========================================================
       BUSCAR STATUS ATUAL
    ========================================================== */
    $sql = "SELECT nome, status FROM empresa WHERE id_empresa = ? LIMIT 1";
    $stmt = $conexao->prepare($sql);

    if (!$stmt) {
        throw new Exception('Falha ao preparar SELECT da empresa.');
    }

    $stmt->bind_param('i', $idEmpresa);
    $stmt->execute();

    $result = $stmt->get_result();
    $empresa = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$empresa) {
        out([
            'ok' => false,
            'code' => 'NOT_FOUND',
            'user_msg' => 'Empresa não encontrada.'
        ], 404);
    }

    $statusAtual = (string)($empresa['status'] ?? '');
    $nomeEmpresa = (string)($empresa['nome'] ?? '');

    /* ==========================================================
       REGRA DE NEGÓCIO
    ========================================================== */
    if ($statusAtual === 'bloqueado') {
        out([
            'ok' => false,
            'code' => 'BLOCKED',
            'user_msg' => 'Empresa bloqueada não pode ter status alterado.'
        ], 403);
    }

    if (!in_array($statusAtual, ['ativo', 'inativo'], true)) {
        out([
            'ok' => false,
            'code' => 'INVALID_CURRENT_STATUS',
            'user_msg' => 'Status atual da empresa é inválido para alteração.'
        ], 422);
    }

    $novoStatus = ($statusAtual === 'ativo') ? 'inativo' : 'ativo';

    /* ==========================================================
       UPDATE
    ========================================================== */
    $sqlUpdate = "UPDATE empresa SET status = ? WHERE id_empresa = ?";
    $stmtUpdate = $conexao->prepare($sqlUpdate);

    if (!$stmtUpdate) {
        throw new Exception('Falha ao preparar UPDATE da empresa.');
    }

    $stmtUpdate->bind_param('si', $novoStatus, $idEmpresa);
    $ok = $stmtUpdate->execute();

    if (!$ok) {
        $errno = (int)$stmtUpdate->errno;
        $error = (string)$stmtUpdate->error;
        $stmtUpdate->close();

        out([
            'ok' => false,
            'code' => 'UPDATE_ERROR',
            'user_msg' => 'Erro ao atualizar status da empresa.',
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
        'user_msg' => "Status da empresa '{$nomeEmpresa}' alterado com sucesso.",
        'data' => [
            'id_empresa' => $idEmpresa,
            'status_anterior' => $statusAtual,
            'novo_status' => $novoStatus,
        ],
    ], 200);

} catch (Throwable $e) {
    out([
        'ok' => false,
        'code' => 'SERVER_ERROR',
        'user_msg' => 'Erro interno ao alterar o status da empresa.',
    ], 500);
}