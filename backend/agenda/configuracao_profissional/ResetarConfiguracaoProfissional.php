<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

date_default_timezone_set('America/Sao_Paulo');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php_errors.log');

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

    $normalizar = static fn(mixed $valor): string => mb_strtolower(trim((string)$valor), 'UTF-8');
    $tipoUsuario = $normalizar($auth['tipo_usuario'] ?? '');
    $modoSuporte = ($auth['modo_suporte'] ?? false) === true || (int)($auth['modo_suporte'] ?? 0) === 1;
    $idProfissionalProprio = 0;
    $restringirAoProfissionalProprio = false;
    if ($tipoUsuario === 'super_admin') {
        if (!$modoSuporte) out(['ok' => false, 'code' => 'SUPPORT_COMPANY_REQUIRED', 'user_msg' => 'Acesse uma empresa em modo suporte antes de administrar estas configurações.'], 403);
    } else {
        $stmt = $conexao->prepare("SELECT pf.nome, p.id_profissional FROM empresa_usuario eu INNER JOIN perfil pf ON pf.id_perfil=eu.id_perfil INNER JOIN empresa e ON e.id_empresa=eu.id_empresa LEFT JOIN profissional p ON p.id_usuario=eu.id_usuario WHERE eu.id_empresa=? AND eu.id_usuario=? AND eu.status='ativo' AND pf.status='ativo' AND e.status='ativo' LIMIT 1");
        $stmt->bind_param('ii', $idEmpresa, $idUsuarioSessao); $stmt->execute(); $stmt->bind_result($perfilSessao, $idProfissionalProprio); $vinculoOk=$stmt->fetch(); $stmt->close();
        $perfilNormalizado = $normalizar($perfilSessao ?? '');
        $restringirAoProfissionalProprio = in_array($perfilNormalizado, ['profissional','profissionais'], true);
        if (!$vinculoOk || !in_array($perfilNormalizado, ['proprietário','proprietario','profissional','profissionais'], true)) out(['ok'=>false,'code'=>'ACCESS_DENIED','user_msg'=>'Você não possui permissão para administrar estas configurações.'],403);
    }

    $entrada = json_decode((string)file_get_contents('php://input'), true);
    $idProfissional = (int)($entrada['id_profissional'] ?? $_POST['id_profissional'] ?? 0);
    if ($idProfissional <= 0) out(['ok'=>false,'code'=>'PROFESSIONAL_REQUIRED','user_msg'=>'Selecione um profissional para continuar.'],422);
    if ($restringirAoProfissionalProprio && ((int)$idProfissionalProprio <= 0 || (int)$idProfissionalProprio !== $idProfissional)) out(['ok'=>false,'code'=>'PROFESSIONAL_ACCESS_DENIED','user_msg'=>'O profissional só pode restaurar as configurações da própria agenda.'],403);

    // Confirma novamente que o alvo ativo pertence à empresa da sessão.
    $stmt = $conexao->prepare("SELECT p.id_profissional FROM profissional p INNER JOIN usuario u ON u.id_usuario=p.id_usuario INNER JOIN empresa_usuario eu ON eu.id_usuario=p.id_usuario WHERE p.id_profissional=? AND eu.id_empresa=? AND u.status='ativo' AND eu.status='ativo' LIMIT 1");
    $stmt->bind_param('ii', $idProfissional, $idEmpresa); $stmt->execute(); $stmt->store_result(); $profissionalOk=$stmt->num_rows===1; $stmt->close();
    if (!$profissionalOk) out(['ok'=>false,'code'=>'PROFESSIONAL_ACCESS_DENIED','user_msg'=>'O profissional selecionado não está ativo ou não pertence à empresa acessada.'],403);

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
