<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/**
 * ==========================================================
 * ALTERAR STATUS CLIENTE (toggle ativo/inativo)
 * ----------------------------------------------------------
 * Recebe:
 *   - id_cliente (POST)
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
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$auth=$_SESSION['auth']??[];
$idEmpresa=(int)($auth['id_empresa']??$auth['empresa_id']??$_SESSION['empresa_id']??$_SESSION['id_empresa']??0);
if((int)($auth['id_usuario']??0)<=0)out(['ok'=>false,'code'=>'NOT_AUTHENTICATED','user_msg'=>'Sessão expirada. Faça login novamente.'],401);
if($idEmpresa<=0)out(['ok'=>false,'code'=>'COMPANY_ACCESS_DENIED','user_msg'=>'Contexto empresarial não autorizado.'],403);
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
$idCliente = filter_input(INPUT_POST, 'id_cliente', FILTER_VALIDATE_INT)
    ?: (is_numeric($_POST['id_cliente'] ?? null) ? (int)$_POST['id_cliente'] : 0);

if (!$idCliente || $idCliente <= 0) {
    out([
        'ok' => false,
        'code' => 'INVALID_ID',
        'user_msg' => 'ID do cliente inválido.'
    ], 400);
}

try {
    $conexao->begin_transaction();
    /* ==========================================================
       BUSCAR STATUS ATUAL
    ========================================================== */
    // Correção IDOR: o alvo é lido e bloqueado somente dentro da empresa autenticada no backend.
    $sql = "SELECT nome_completo, status FROM cliente WHERE id_cliente = ? AND id_empresa = ? LIMIT 1 FOR UPDATE";
    $stmt = $conexao->prepare($sql);

    if (!$stmt) {
        throw new Exception('Falha ao preparar SELECT do cliente.');
    }

    $stmt->bind_param('ii', $idCliente, $idEmpresa);
    $stmt->execute();

    $result = $stmt->get_result();
    $cliente = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$cliente) {
        out([
            'ok' => false,
            'code' => 'NOT_FOUND',
            'user_msg' => 'Cliente não encontrado.'
        ], 404);
    }

    $statusAtual = (string)($cliente['status'] ?? '');
    $nomeCliente = trim((string)($cliente['nome_completo'] ?? ''));

    /* ==========================================================
       REGRA DE NEGÓCIO
    ========================================================== */
    if ($statusAtual === 'bloqueado') {
        out([
            'ok' => false,
            'code' => 'BLOCKED',
            'user_msg' => 'Cliente bloqueado não pode ter status alterado.'
        ], 403);
    }

    if (!in_array($statusAtual, ['ativo', 'inativo'], true)) {
        out([
            'ok' => false,
            'code' => 'INVALID_CURRENT_STATUS',
            'user_msg' => 'Status atual do cliente é inválido para alteração.'
        ], 422);
    }

    $novoStatus = ($statusAtual === 'ativo') ? 'inativo' : 'ativo';

    /* ==========================================================
       UPDATE
    ========================================================== */
    $sqlUpdate = "UPDATE cliente SET status = ? WHERE id_cliente = ? AND id_empresa = ? LIMIT 1";
    $stmtUpdate = $conexao->prepare($sqlUpdate);

    if (!$stmtUpdate) {
        throw new Exception('Falha ao preparar UPDATE do cliente.');
    }

    $stmtUpdate->bind_param('sii', $novoStatus, $idCliente, $idEmpresa);
    $ok = $stmtUpdate->execute();

    if (!$ok) {
        $errno = (int)$stmtUpdate->errno;
        $error = (string)$stmtUpdate->error;
        $stmtUpdate->close();

        out([
            'ok' => false,
            'code' => 'UPDATE_ERROR',
            'user_msg' => 'Erro ao atualizar status do cliente.',
            'debug' => [
                'errno' => $errno,
                'error' => $error,
            ],
        ], 500);
    }

    $stmtUpdate->close();
    auditoriaRegistrar($conexao,'cliente.status_alterado',['entidade_id'=>$idCliente,'entidade_rotulo'=>$nomeCliente,'descricao'=>'Alterou o status do cliente '.$nomeCliente.'.','alteracoes'=>['status'=>['antes'=>$statusAtual,'depois'=>$novoStatus]],'contexto'=>['origem'=>'painel_administrativo']]);
    $conexao->commit();

    /* ==========================================================
       RESPOSTA
    ========================================================== */
    out([
        'ok' => true,
        'code' => 'STATUS_UPDATED',
        'user_msg' => "Status do cliente '{$nomeCliente}' alterado com sucesso.",
        'data' => [
            'id_cliente' => $idCliente,
            'status_anterior' => $statusAtual,
            'novo_status' => $novoStatus,
        ],
    ], 200);

} catch (Throwable $e) {
    try { $conexao->rollback(); } catch (Throwable $ignorado) {}
    error_log('[alterar_status_cliente] '.$e->getMessage());
    out([
        'ok' => false,
        'code' => 'SERVER_ERROR',
        'user_msg' => 'Erro interno ao alterar o status do cliente.',
    ], 500);
}
