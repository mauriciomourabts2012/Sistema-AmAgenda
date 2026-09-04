<?php
declare(strict_types=1);

// Mantém a validação da data igual à data exibida no navegador da agenda.
date_default_timezone_set('America/Sao_Paulo');

/*
|--------------------------------------------------------------------------
| CADASTRAR AGENDAMENTO
|--------------------------------------------------------------------------
| Grava um agendamento simples ou uma recorrencia semanal. Identificadores,
| duracao, valor e empresa sao sempre confirmados no servidor; o navegador
| nunca e considerado fonte confiavel para esses dados.
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function data_valida(string $valor): ?DateTimeImmutable
{
    $data = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    return $data && $data->format('Y-m-d') === $valor ? $data : null;
}

function uuid_v4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

$conexao = null;

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        out(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED', 'user_msg' => 'Método não permitido.'], 405);
    }

    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $auth = $_SESSION['auth'] ?? [];
    $idUsuario = (int)($auth['id_usuario'] ?? 0);
    $idEmpresa = (int)($auth['id_empresa'] ?? $_SESSION['empresa_id'] ?? $_SESSION['id_empresa'] ?? $_SESSION['empresa']['id_empresa'] ?? $_SESSION['empresa']['id'] ?? 0);
    $superAdminSuporte = mb_strtolower(trim((string)($auth['tipo_usuario'] ?? '')), 'UTF-8') === 'super_admin'
        && (bool)($auth['modo_suporte'] ?? false);

    if ($idUsuario <= 0) out(['ok' => false, 'code' => 'NOT_AUTHENTICATED', 'user_msg' => 'Sessão expirada. Faça login novamente.'], 401);
    if (($auth['status'] ?? 'ativo') !== 'ativo') out(['ok' => false, 'code' => 'USER_INACTIVE', 'user_msg' => 'Seu usuário está inativo.'], 403);
    if ($idEmpresa <= 0) out(['ok' => false, 'code' => 'SESSION_WITHOUT_COMPANY', 'user_msg' => 'Não foi possível identificar a empresa da sessão.'], 403);

    $idCliente = filter_input(INPUT_POST, 'id_cliente', FILTER_VALIDATE_INT) ?: 0;
    $idProfissional = filter_input(INPUT_POST, 'id_profissional', FILTER_VALIDATE_INT) ?: 0;
    $idServico = filter_input(INPUT_POST, 'id_servico', FILTER_VALIDATE_INT) ?: 0;
    $duracaoSolicitada = filter_input(INPUT_POST, 'duracao', FILTER_VALIDATE_INT) ?: 0;
    $dataTexto = trim((string)($_POST['data_agendamento'] ?? ''));
    $horaTexto = trim((string)($_POST['hora_inicio'] ?? ''));
    $status = strtolower(trim((string)($_POST['status'] ?? 'pendente')));
    $observacao = trim((string)($_POST['observacao'] ?? ''));
    $repetir = (int)($_POST['repetir_semanalmente'] ?? 0) === 1;
    $fimRecorrenciaTexto = trim((string)($_POST['recorrencia_data_fim'] ?? ''));
    $data = data_valida($dataTexto);
    $fimRecorrencia = $fimRecorrenciaTexto !== '' ? data_valida($fimRecorrenciaTexto) : null;

    $fields = [];
    if ($idCliente <= 0) $fields['id_cliente'] = 'Selecione um cliente.';
    if ($idProfissional <= 0) $fields['id_profissional'] = 'Selecione um profissional.';
    if ($idServico <= 0) $fields['id_servico'] = 'Selecione um serviço.';
    $duracoesPermitidas = [15, 30, 45, 60, 75, 90, 105, 120, 150, 180, 210, 240];
    if (!in_array($duracaoSolicitada, $duracoesPermitidas, true)) $fields['duracao'] = 'Selecione uma duração válida.';
    if (!$data || $data < new DateTimeImmutable('today')) $fields['data_agendamento'] = 'Não é permitido agendar em uma data passada.';
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $horaTexto)) $fields['hora_inicio'] = 'Selecione um horário válido.';
    if (!in_array($status, ['pendente', 'confirmado'], true)) $fields['status'] = 'Status inválido para um novo agendamento.';
    if (mb_strlen($observacao) > 220) $fields['observacao'] = 'A observação deve ter no máximo 220 caracteres.';
    if ($repetir && (!$fimRecorrencia || ($data && $fimRecorrencia < $data))) $fields['recorrencia_data_fim'] = 'Informe uma data final igual ou posterior ao primeiro agendamento.';
    if ($repetir && $data && $fimRecorrencia && $fimRecorrencia > $data->modify('+1 year')) $fields['recorrencia_data_fim'] = 'A repetição semanal pode abranger no máximo um ano.';
    if ($fields) out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Revise os dados do agendamento.', 'fields' => $fields], 422);

    require __DIR__ . '/../_config/conexao.php';
    require_once __DIR__ . '/../_regras/limites_plano.php';
    require_once __DIR__ . '/../_servicos/auditoria.php';
    if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) throw new RuntimeException('Conexão indisponível.');
    $conexao->set_charset('utf8mb4');

    // Modo suporte autoriza o Super Admin sem criar vínculo fictício na empresa.
    if ($superAdminSuporte) {
        $stmt = $conexao->prepare("SELECT status FROM empresa WHERE id_empresa=? LIMIT 1");
        $stmt->bind_param('i', $idEmpresa);
        $stmt->execute();
        $stmt->bind_result($empresaStatus);
        $empresaEncontrada = $stmt->fetch();
        $stmt->close();
        if (!$empresaEncontrada || $empresaStatus !== 'ativo') out(['ok' => false, 'code' => 'COMPANY_ACCESS_DENIED', 'user_msg' => 'Acesso à empresa não autorizado.'], 403);
    } else {
        // Confirma empresa, vínculo, perfil e restringe o perfil Profissional a si mesmo.
        $stmt = $conexao->prepare("SELECT e.status, eu.status, pf.nome, p.id_profissional FROM empresa e INNER JOIN empresa_usuario eu ON eu.id_empresa=e.id_empresa INNER JOIN perfil pf ON pf.id_perfil=eu.id_perfil LEFT JOIN profissional p ON p.id_usuario=eu.id_usuario WHERE e.id_empresa=? AND eu.id_usuario=? LIMIT 1");
        $stmt->bind_param('ii', $idEmpresa, $idUsuario);
        $stmt->execute();
        $stmt->bind_result($empresaStatus, $vinculoStatus, $perfilNome, $profissionalSessao);
        $temVinculo = $stmt->fetch();
        $stmt->close();
        if (!$temVinculo || $empresaStatus !== 'ativo' || $vinculoStatus !== 'ativo') out(['ok' => false, 'code' => 'COMPANY_ACCESS_DENIED', 'user_msg' => 'Acesso à empresa não autorizado.'], 403);
        if (in_array(mb_strtolower((string)$perfilNome), ['profissional', 'profissionais'], true) && (int)$profissionalSessao !== $idProfissional) out(['ok' => false, 'code' => 'PROFESSIONAL_ACCESS_DENIED', 'user_msg' => 'O profissional só pode criar agendamentos para si mesmo.'], 403);
    }

    $stmt = $conexao->prepare("SELECT id_cliente,nome_completo FROM cliente WHERE id_cliente=? AND id_empresa=? AND status='ativo' LIMIT 1");
    $stmt->bind_param('ii', $idCliente, $idEmpresa);
    $stmt->execute(); $stmt->bind_result($clienteIdDb,$clienteNome); $clienteOk=$stmt->fetch(); $stmt->close();
    if (!$clienteOk) out(['ok' => false, 'code' => 'CLIENT_NOT_FOUND', 'user_msg' => 'Cliente não encontrado ou inativo.'], 404);

    $stmt = $conexao->prepare("SELECT s.duracao_min,s.valor,s.nome,u.nome FROM servico s INNER JOIN profissional p ON p.id_profissional=s.id_profissional INNER JOIN usuario u ON u.id_usuario=p.id_usuario INNER JOIN empresa_usuario eu ON eu.id_usuario=p.id_usuario AND eu.id_empresa=s.id_empresa WHERE s.id_servico=? AND s.id_profissional=? AND s.id_empresa=? AND s.status='ativo' AND eu.status='ativo' AND eu.bloqueado_plano=0 LIMIT 1");
    $stmt->bind_param('iii', $idServico, $idProfissional, $idEmpresa);
    $stmt->execute(); $stmt->bind_result($duracaoDb,$valorDb,$servicoNome,$profissionalNome); $servicoOk = $stmt->fetch(); $stmt->close();
    if (!$servicoOk || (int)$duracaoDb <= 0) out(['ok' => false, 'code' => 'SERVICE_NOT_FOUND', 'user_msg' => 'Serviço não encontrado para o profissional selecionado.'], 404);
    $duracao = $duracaoSolicitada;

    $inicio = DateTimeImmutable::createFromFormat('!H:i', $horaTexto);
    $fim = $inicio?->modify('+' . $duracao . ' minutes');
    if (!$inicio || !$fim || $fim->format('Y-m-d') !== '1970-01-01') out(['ok' => false, 'code' => 'INVALID_END_TIME', 'user_msg' => 'A duração do serviço ultrapassa o fim do dia.'], 422);
    $horaInicio = $inicio->format('H:i:s');
    $horaFim = $fim->format('H:i:s');
    $dataHoraInicio = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $dataTexto . ' ' . $horaTexto);
    if (!$dataHoraInicio || $dataHoraInicio <= new DateTimeImmutable('now')) {
        out(['ok' => false, 'code' => 'PAST_START_TIME', 'user_msg' => 'Selecione um horário que ainda não tenha começado.'], 422);
    }

    require_once __DIR__ . '/validar_horario_agendamento.php';
    $erroHorario = validarHorarioAgendamento($conexao, $idEmpresa, $idProfissional, $data, $horaInicio, $duracao);
    if ($erroHorario !== null) out(['ok' => false, 'code' => 'INVALID_SCHEDULE', 'user_msg' => $erroHorario], 422);

    $datas = [];
    $limite = $repetir ? $fimRecorrencia : $data;
    for ($atual = $data; $atual <= $limite; $atual = $atual->modify('+7 days')) $datas[] = $atual->format('Y-m-d');
    $grupo = $repetir ? uuid_v4() : null;

    $conexao->begin_transaction();
    $resultadoPlano = limitesPlanoBloquearEmpresa($conexao, $idEmpresa);
    limitesPlanoAbortarSeNegado($conexao, $resultadoPlano);

    // Revalida o vínculo escolhido sob bloqueio transacional. Assim, nem um
    // POST manipulado nem um downgrade concorrente permitem novo agendamento.
    $stmtVinculoProfissional = $conexao->prepare("SELECT eu.status, eu.bloqueado_plano FROM empresa_usuario eu INNER JOIN profissional p ON p.id_usuario=eu.id_usuario WHERE p.id_profissional=? AND eu.id_empresa=? LIMIT 1 FOR UPDATE");
    $stmtVinculoProfissional->bind_param('ii', $idProfissional, $idEmpresa);
    $stmtVinculoProfissional->execute();
    $stmtVinculoProfissional->bind_result($statusVinculoProfissional, $bloqueadoPlanoProfissional);
    $vinculoProfissionalValido = $stmtVinculoProfissional->fetch();
    $stmtVinculoProfissional->close();

    if (!$vinculoProfissionalValido || $statusVinculoProfissional !== 'ativo' || (int)$bloqueadoPlanoProfissional === 1) {
        $conexao->rollback();
        out(['ok' => false, 'code' => 'PROFESSIONAL_UNAVAILABLE', 'user_msg' => 'O profissional selecionado não está disponível para novos agendamentos.'], 409);
    }

    $resultadoAgendamentos = limitesPlanoVerificarAgendamentosPorMes(
        $conexao,
        $resultadoPlano['plano'],
        $idEmpresa,
        $datas
    );
    limitesPlanoAbortarSeNegado($conexao, $resultadoAgendamentos);

    $stmtConflito = $conexao->prepare("SELECT id_agendamento FROM agendamento WHERE id_empresa=? AND id_profissional=? AND data_agendamento=? AND status IN ('pendente','confirmado') AND hora_inicio < ? AND hora_fim > ? LIMIT 1 FOR UPDATE");
    $stmtInserir = $conexao->prepare("INSERT INTO agendamento (id_empresa,id_cliente,id_profissional,id_servico,data_agendamento,hora_inicio,hora_fim,duracao_min_aplicada,valor_aplicado,status,observacao,repetir_semanalmente,recorrencia_data_fim,grupo_recorrencia,criado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $ids = [];
    foreach ($datas as $dataOcorrencia) {
        $dataOcorrenciaObj = new DateTimeImmutable($dataOcorrencia);
        $erroHorario = validarHorarioAgendamento($conexao, $idEmpresa, $idProfissional, $dataOcorrenciaObj, $horaInicio, $duracao);
        if ($erroHorario !== null) { $stmtConflito->close(); $stmtInserir->close(); $conexao->rollback(); out(['ok'=>false,'code'=>'INVALID_RECURRING_SCHEDULE','user_msg'=>$erroHorario.' Data: '.date('d/m/Y',strtotime($dataOcorrencia)).'.'],422); }
        $stmtConflito->bind_param('iisss', $idEmpresa, $idProfissional, $dataOcorrencia, $horaFim, $horaInicio);
        $stmtConflito->execute(); $stmtConflito->store_result();
        if ($stmtConflito->num_rows > 0) { $stmtConflito->close(); $stmtInserir->close(); $conexao->rollback(); out(['ok' => false, 'code' => 'SCHEDULE_CONFLICT', 'user_msg' => 'O horário já está ocupado em ' . date('d/m/Y', strtotime($dataOcorrencia)) . '.'], 409); }
        $obsDb = $observacao !== '' ? $observacao : null;
        $fimRecDb = $repetir ? $fimRecorrenciaTexto : null;
        $repInt = $repetir ? 1 : 0;
        $valor = (float)$valorDb;
        $stmtInserir->bind_param('iiiisssidssissi', $idEmpresa, $idCliente, $idProfissional, $idServico, $dataOcorrencia, $horaInicio, $horaFim, $duracao, $valor, $status, $obsDb, $repInt, $fimRecDb, $grupo, $idUsuario);
        $stmtInserir->execute();
        $ids[] = $conexao->insert_id;
    }
    $stmtConflito->close(); $stmtInserir->close();
    // Uma única ação recorrente produz um único evento com quantidade e grupo da série.
    auditoriaRegistrar($conexao,'agendamento.criado',['entidade_id'=>(int)($ids[0]??0),'entidade_rotulo'=>(string)$clienteNome,'descricao'=>'Criou o agendamento de '.$clienteNome.'.','alteracoes'=>[
        'cliente'=>['antes'=>null,'depois'=>['id'=>$idCliente,'rotulo'=>$clienteNome]],'profissional'=>['antes'=>null,'depois'=>['id'=>$idProfissional,'rotulo'=>$profissionalNome]],'servico'=>['antes'=>null,'depois'=>['id'=>$idServico,'rotulo'=>$servicoNome]],'data_agendamento'=>['antes'=>null,'depois'=>$dataTexto],'hora_inicio'=>['antes'=>null,'depois'=>$horaInicio],'hora_fim'=>['antes'=>null,'depois'=>$horaFim],'status'=>['antes'=>null,'depois'=>$status],'duracao_min_aplicada'=>['antes'=>null,'depois'=>$duracao],'valor_aplicado'=>['antes'=>null,'depois'=>$valorDb],'recorrencia'=>['antes'=>null,'depois'=>['grupo'=>$grupo,'quantidade'=>count($ids),'data_fim'=>$fimRecDb]]
    ],'contexto'=>['origem'=>'agenda','quantidade_afetada'=>count($ids),'escopo'=>$repetir?'toda_recorrencia':'ocorrencia_unica','grupo_recorrencia'=>$grupo,'data_referencia'=>$dataTexto]]);
    $conexao->commit();

    out(['ok' => true, 'code' => 'APPOINTMENT_CREATED', 'user_msg' => count($ids) > 1 ? 'Agendamentos criados com sucesso.' : 'Agendamento criado com sucesso.', 'data' => ['id_agendamento' => $ids[0] ?? null, 'ids' => $ids, 'quantidade' => count($ids), 'grupo_recorrencia' => $grupo, 'avisos_plano' => $resultadoAgendamentos['avisos'] ?? []]], 201);
} catch (Throwable $e) {
    if ($conexao instanceof mysqli) { try { $conexao->rollback(); } catch (Throwable $ignorado) {} }
    error_log('[cadastrar_agendamento] ' . $e->getMessage());
    out(['ok' => false, 'code' => 'INTERNAL_ERROR', 'user_msg' => 'Não foi possível salvar o agendamento.'], 500);
}
