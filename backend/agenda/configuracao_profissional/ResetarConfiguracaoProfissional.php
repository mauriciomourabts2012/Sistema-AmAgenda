<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

date_default_timezone_set('America/Sao_Paulo');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php_errors.log');

require_once __DIR__ . '/../../_auth/bloquear.php';
require_once __DIR__ . '/../../_config/conexao.php';

/*
  IMPORTANTE:
  Não declarar function out() aqui.
  Ela já existe no api_central.php.
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out([
        'ok' => false,
        'mensagem' => 'Método não permitido.'
    ], 405);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$auth = $_SESSION['auth'] ?? [];

$idUsuarioSessao = (int)($auth['id_usuario'] ?? 0);
$statusSessao    = (string)($auth['status'] ?? '');

if ($idUsuarioSessao <= 0) {
    out([
        'ok' => false,
        'mensagem' => 'Sessão expirada. Faça login novamente.'
    ], 401);
}

if ($statusSessao !== '' && $statusSessao !== 'ativo') {
    out([
        'ok' => false,
        'mensagem' => 'Seu usuário não está ativo. Faça login novamente.'
    ], 403);
}

/* ==========================================================
   EMPRESA DA SESSÃO
========================================================== */
$idEmpresa = 0;

if (isset($auth['id_empresa'])) {
    $idEmpresa = (int)$auth['id_empresa'];
} elseif (isset($_SESSION['empresa_id'])) {
    $idEmpresa = (int)$_SESSION['empresa_id'];
} elseif (isset($_SESSION['id_empresa'])) {
    $idEmpresa = (int)$_SESSION['id_empresa'];
} elseif (isset($_SESSION['empresa']['id_empresa'])) {
    $idEmpresa = (int)$_SESSION['empresa']['id_empresa'];
} elseif (isset($_SESSION['empresa']['id'])) {
    $idEmpresa = (int)$_SESSION['empresa']['id'];
}

if ($idEmpresa <= 0) {
    out([
        'ok' => false,
        'mensagem' => 'Empresa não identificada.'
    ], 401);
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    if (
        !isset($conexao) ||
        !($conexao instanceof mysqli) ||
        $conexao->connect_errno
    ) {
        out([
            'ok' => false,
            'mensagem' => 'Erro de conexão com banco de dados.'
        ], 500);
    }

    $conexao->set_charset('utf8mb4');

    /* ==========================================================
       BUSCA O PROFISSIONAL PELO USUÁRIO DA SESSÃO

       Sua tabela profissional tem:
       - id_profissional
       - id_usuario

       Então NÃO pode usar id_usuario direto como id_profissional.
    ========================================================== */
    $idProfissional = 0;

    $stmt = $conexao->prepare("
        SELECT id_profissional
        FROM profissional
        WHERE id_usuario = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $idUsuarioSessao);
    $stmt->execute();
    $stmt->bind_result($idProfissionalDb);

    if ($stmt->fetch()) {
        $idProfissional = (int)$idProfissionalDb;
    }

    $stmt->close();

    if ($idProfissional <= 0) {
        out([
            'ok' => false,
            'mensagem' => 'Este usuário não possui profissional vinculado.'
        ], 404);
    }

    /* ==========================================================
       APAGA DADOS PERSONALIZADOS DO PROFISSIONAL
    ========================================================== */
    $conexao->begin_transaction();

    $apagados = [
        'configuracao_geral_profissional' => 0,
        'horario_profissional' => 0,
        'configuracao_whatsapp_profissional' => 0
    ];

    $stmt = $conexao->prepare("
        DELETE FROM configuracao_geral_profissional
        WHERE id_empresa = ?
          AND id_profissional = ?
    ");
    $stmt->bind_param('ii', $idEmpresa, $idProfissional);
    $stmt->execute();
    $apagados['configuracao_geral_profissional'] = max(0, $stmt->affected_rows);
    $stmt->close();

    $stmt = $conexao->prepare("
        DELETE FROM horario_profissional
        WHERE id_empresa = ?
          AND id_profissional = ?
    ");
    $stmt->bind_param('ii', $idEmpresa, $idProfissional);
    $stmt->execute();
    $apagados['horario_profissional'] = max(0, $stmt->affected_rows);
    $stmt->close();

    $stmt = $conexao->prepare("
        DELETE FROM configuracao_whatsapp_profissional
        WHERE id_empresa = ?
          AND id_profissional = ?
    ");
    $stmt->bind_param('ii', $idEmpresa, $idProfissional);
    $stmt->execute();
    $apagados['configuracao_whatsapp_profissional'] = max(0, $stmt->affected_rows);
    $stmt->close();

    $conexao->commit();

    out([
        'ok' => true,
        'mensagem' => 'Padrão da empresa restaurado com sucesso.',
        'data' => [
            'id_empresa' => $idEmpresa,
            'id_usuario' => $idUsuarioSessao,
            'id_profissional' => $idProfissional,
            'registros_apagados' => $apagados
        ]
    ]);

} catch (Throwable $e) {
    if (isset($conexao) && $conexao instanceof mysqli) {
        try {
            $conexao->rollback();
        } catch (Throwable $rollbackError) {
            error_log('[ResetConfigAgendaEmpresa][rollback] ' . $rollbackError->getMessage());
        }
    }

    error_log('[ResetConfigAgendaEmpresa] ' . $e->getMessage());

    out([
        'ok' => false,
        'mensagem' => 'Erro interno ao restaurar configurações.',
        'debug' => [
            'message' => $e->getMessage()
        ]
    ], 500);
}