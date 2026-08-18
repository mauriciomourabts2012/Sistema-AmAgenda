<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| EXCLUIR AGENDAMENTO
|--------------------------------------------------------------------------
| Exclui somente o agendamento selecionado ou, quando ele pertence a uma
| recorrência, o trecho autorizado pelo usuário. Empresa, vínculo e perfil
| profissional são sempre confirmados pela sessão no servidor.
|--------------------------------------------------------------------------
*/
if (!function_exists('out')) {
    function out(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$conexao = null;

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        out(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED', 'user_msg' => 'Método não permitido.'], 405);
    }

    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $auth = $_SESSION['auth'] ?? [];
    $idUsuario = (int)($auth['id_usuario'] ?? 0);
    $idEmpresa = (int)($auth['id_empresa'] ?? $_SESSION['empresa_id'] ?? $_SESSION['id_empresa'] ?? $_SESSION['empresa']['id_empresa'] ?? 0);
    $idAgendamento = filter_input(INPUT_POST, 'id_agendamento', FILTER_VALIDATE_INT) ?: 0;
    $escopo = strtolower(trim((string)($_POST['escopo'] ?? 'somente_este')));

    if ($idUsuario <= 0) out(['ok' => false, 'code' => 'NOT_AUTHENTICATED', 'user_msg' => 'Sessão expirada. Faça login novamente.'], 401);
    if ($idEmpresa <= 0) out(['ok' => false, 'code' => 'SESSION_WITHOUT_COMPANY', 'user_msg' => 'Não foi possível identificar a empresa da sessão.'], 403);
    if ($idAgendamento <= 0 || !in_array($escopo, ['somente_este', 'este_e_proximos', 'toda_recorrencia'], true)) {
        out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Informe um agendamento e uma opção de exclusão válidos.'], 422);
    }

    require __DIR__ . '/../_config/conexao.php';
    $conexao->set_charset('utf8mb4');

    $stmt = $conexao->prepare("SELECT pf.nome,p.id_profissional FROM empresa_usuario eu INNER JOIN empresa e ON e.id_empresa=eu.id_empresa INNER JOIN perfil pf ON pf.id_perfil=eu.id_perfil LEFT JOIN profissional p ON p.id_usuario=eu.id_usuario WHERE eu.id_empresa=? AND eu.id_usuario=? AND eu.status='ativo' AND e.status='ativo' LIMIT 1");
    $stmt->bind_param('ii', $idEmpresa, $idUsuario);
    $stmt->execute();
    $stmt->bind_result($perfil, $idProfissionalSessao);
    $vinculo = $stmt->fetch();
    $stmt->close();
    if (!$vinculo) out(['ok' => false, 'code' => 'COMPANY_ACCESS_DENIED', 'user_msg' => 'Acesso à empresa não autorizado.'], 403);

    $conexao->begin_transaction();
    $stmt = $conexao->prepare("SELECT id_profissional,data_agendamento,grupo_recorrencia FROM agendamento WHERE id_agendamento=? AND id_empresa=? LIMIT 1 FOR UPDATE");
    $stmt->bind_param('ii', $idAgendamento, $idEmpresa);
    $stmt->execute();
    $stmt->bind_result($idProfissionalAgendamento, $dataSelecionada, $grupoRecorrencia);
    $existe = $stmt->fetch();
    $stmt->close();
    if (!$existe) {
        $conexao->rollback();
        out(['ok' => false, 'code' => 'APPOINTMENT_NOT_FOUND', 'user_msg' => 'Agendamento não encontrado.'], 404);
    }

    $perfilNormalizado = mb_strtolower(trim((string)$perfil));
    if (in_array($perfilNormalizado, ['profissional', 'profissionais'], true) && (int)$idProfissionalSessao !== (int)$idProfissionalAgendamento) {
        $conexao->rollback();
        out(['ok' => false, 'code' => 'PROFESSIONAL_ACCESS_DENIED', 'user_msg' => 'O profissional só pode excluir os próprios agendamentos.'], 403);
    }

    $temRecorrencia = trim((string)$grupoRecorrencia) !== '';
    if (!$temRecorrencia && $escopo !== 'somente_este') {
        $conexao->rollback();
        out(['ok' => false, 'code' => 'APPOINTMENT_NOT_RECURRENT', 'user_msg' => 'Este agendamento não pertence a uma recorrência.'], 422);
    }

    if ($escopo === 'somente_este') {
        $stmt = $conexao->prepare("DELETE FROM agendamento WHERE id_agendamento=? AND id_empresa=? LIMIT 1");
        $stmt->bind_param('ii', $idAgendamento, $idEmpresa);
    } elseif ($escopo === 'este_e_proximos') {
        $stmt = $conexao->prepare("DELETE FROM agendamento WHERE id_empresa=? AND grupo_recorrencia=? AND data_agendamento>=?");
        $stmt->bind_param('iss', $idEmpresa, $grupoRecorrencia, $dataSelecionada);
    } else {
        $stmt = $conexao->prepare("DELETE FROM agendamento WHERE id_empresa=? AND grupo_recorrencia=?");
        $stmt->bind_param('is', $idEmpresa, $grupoRecorrencia);
    }

    $stmt->execute();
    $quantidade = $stmt->affected_rows;
    $stmt->close();
    if ($quantidade < 1) {
        $conexao->rollback();
        out(['ok' => false, 'code' => 'NOTHING_DELETED', 'user_msg' => 'Nenhum agendamento foi excluído.'], 409);
    }

    $conexao->commit();
    out([
        'ok' => true,
        'code' => 'APPOINTMENT_DELETED',
        'user_msg' => $quantidade === 1 ? 'Agendamento excluído com sucesso.' : $quantidade . ' agendamentos foram excluídos.',
        'data' => ['id_agendamento' => $idAgendamento, 'escopo' => $escopo, 'quantidade' => $quantidade],
    ]);
} catch (Throwable $e) {
    if ($conexao instanceof mysqli) {
        try { $conexao->rollback(); } catch (Throwable $ignorado) {}
    }
    error_log('[excluir_agendamento] ' . $e->getMessage());
    out(['ok' => false, 'code' => 'INTERNAL_ERROR', 'user_msg' => 'Não foi possível excluir o agendamento.'], 500);
}
