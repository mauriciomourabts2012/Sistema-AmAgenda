<?php
declare(strict_types=1);

/* Retorna um agendamento da empresa da sessão para preencher o modal de edição. */
if (!function_exists('out')) { function out(array $p, int $c = 200): void { http_response_code($c); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; } }

try {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $auth = $_SESSION['auth'] ?? [];
    $idUsuario = (int)($auth['id_usuario'] ?? 0);
    $idEmpresa = (int)($auth['id_empresa'] ?? $_SESSION['empresa_id'] ?? $_SESSION['id_empresa'] ?? $_SESSION['empresa']['id_empresa'] ?? 0);
    $id = filter_input(INPUT_GET, 'id_agendamento', FILTER_VALIDATE_INT) ?: 0;
    if ($idUsuario <= 0) out(['ok'=>false,'code'=>'NOT_AUTHENTICATED','user_msg'=>'Sessão expirada. Faça login novamente.'], 401);
    if ($idEmpresa <= 0 || $id <= 0) out(['ok'=>false,'code'=>'INVALID_PARAMETERS','user_msg'=>'Agendamento inválido.'], 422);
    require __DIR__ . '/../_config/conexao.php';
    $conexao->set_charset('utf8mb4');
    $sql = "SELECT a.*,c.nome_completo AS cliente_nome,c.whatsapp_celular AS cliente_telefone FROM agendamento a INNER JOIN cliente c ON c.id_cliente=a.id_cliente AND c.id_empresa=a.id_empresa INNER JOIN empresa_usuario eu ON eu.id_empresa=a.id_empresa AND eu.id_usuario=? AND eu.status='ativo' LEFT JOIN perfil pf ON pf.id_perfil=eu.id_perfil LEFT JOIN profissional p ON p.id_usuario=eu.id_usuario WHERE a.id_agendamento=? AND a.id_empresa=? AND (LOWER(COALESCE(pf.nome,'')) NOT IN ('profissional','profissionais') OR p.id_profissional=a.id_profissional) LIMIT 1";
    $stmt = $conexao->prepare($sql); $stmt->bind_param('iii', $idUsuario, $id, $idEmpresa); $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$r) out(['ok'=>false,'code'=>'APPOINTMENT_NOT_FOUND','user_msg'=>'Agendamento não encontrado ou sem permissão para editar.'], 404);
    foreach (['hora_inicio','hora_fim'] as $campo) $r[$campo] = substr((string)$r[$campo], 0, 5);
    out(['ok'=>true,'data'=>$r]);
} catch (Throwable $e) { error_log('[detalhar_agendamento] '.$e->getMessage()); out(['ok'=>false,'code'=>'INTERNAL_ERROR','user_msg'=>'Não foi possível carregar o agendamento.'], 500); }
