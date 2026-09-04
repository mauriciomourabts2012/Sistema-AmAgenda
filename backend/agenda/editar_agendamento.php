<?php
declare(strict_types=1);

// Mantém a validação da data igual à data exibida no navegador da agenda.
date_default_timezone_set('America/Sao_Paulo');

/*
|--------------------------------------------------------------------------
| EDITAR AGENDAMENTO
|--------------------------------------------------------------------------
| Atualiza somente a ocorrência identificada. Em uma recorrência semanal, a
| ocorrência pode mudar de dia dentro da própria semana sem alterar as demais.
| Duração, valor, jornada e conflitos são novamente validados.
|--------------------------------------------------------------------------
*/
if (!function_exists('out')) { function out(array $p, int $c = 200): void { http_response_code($c); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; } }
function ed_data(string $v): ?DateTimeImmutable { $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v); return $d && $d->format('Y-m-d')===$v ? $d : null; }
$conexao = null;

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(['ok'=>false,'code'=>'METHOD_NOT_ALLOWED','user_msg'=>'Método não permitido.'],405);
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $auth=$_SESSION['auth'] ?? []; $idUsuario=(int)($auth['id_usuario'] ?? 0);
    $idEmpresa=(int)($auth['id_empresa'] ?? $_SESSION['empresa_id'] ?? $_SESSION['id_empresa'] ?? $_SESSION['empresa']['id_empresa'] ?? 0);
    if ($idUsuario<=0) out(['ok'=>false,'code'=>'NOT_AUTHENTICATED','user_msg'=>'Sessão expirada. Faça login novamente.'],401);
    if ($idEmpresa<=0) out(['ok'=>false,'code'=>'SESSION_WITHOUT_COMPANY','user_msg'=>'Não foi possível identificar a empresa da sessão.'],403);

    $id=filter_input(INPUT_POST,'id_agendamento',FILTER_VALIDATE_INT) ?: 0;
    $idCliente=filter_input(INPUT_POST,'id_cliente',FILTER_VALIDATE_INT) ?: 0;
    $idProfissional=filter_input(INPUT_POST,'id_profissional',FILTER_VALIDATE_INT) ?: 0;
    $idServico=filter_input(INPUT_POST,'id_servico',FILTER_VALIDATE_INT) ?: 0;
    $duracaoSolicitada=filter_input(INPUT_POST,'duracao',FILTER_VALIDATE_INT) ?: 0;
    $dataTexto=trim((string)($_POST['data_agendamento'] ?? '')); $data=ed_data($dataTexto);
    $horaTexto=trim((string)($_POST['hora_inicio'] ?? '')); $status=strtolower(trim((string)($_POST['status'] ?? 'pendente')));
    $obs=trim((string)($_POST['observacao'] ?? '')); $repetir=(int)($_POST['repetir_semanalmente'] ?? 0)===1;
    $fimRecTexto=trim((string)($_POST['recorrencia_data_fim'] ?? '')); $fimRec=$fimRecTexto!=='' ? ed_data($fimRecTexto) : null;
    $erros=[];
    if ($id<=0) $erros['id_agendamento']='Agendamento inválido.';
    if ($idCliente<=0) $erros['id_cliente']='Selecione o cliente.';
    if ($idProfissional<=0) $erros['id_profissional']='Selecione o profissional.';
    if ($idServico<=0) $erros['id_servico']='Selecione o serviço.';
    $duracoesPermitidas=[15,30,45,60,75,90,105,120,150,180,210,240];
    if (!in_array($duracaoSolicitada,$duracoesPermitidas,true)) $erros['duracao']='Selecione uma duração válida.';
    if (!$data || $data < new DateTimeImmutable('today')) $erros['data_agendamento']='Não é permitido agendar em uma data passada.';
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$horaTexto)) $erros['hora_inicio']='Selecione um horário válido.';
    if (!in_array($status,['pendente','confirmado','concluido','cancelado','faltou'],true)) $erros['status']='Status inválido.';
    if (mb_strlen($obs)>220) $erros['observacao']='A observação deve ter no máximo 220 caracteres.';
    if ($erros) out(['ok'=>false,'code'=>'VALIDATION_ERROR','user_msg'=>'Revise os dados do agendamento.','fields'=>$erros],422);

    require __DIR__ . '/../_config/conexao.php';
    require_once __DIR__ . '/../_regras/permissoes_usuario.php';
    require_once __DIR__ . '/../_regras/limites_plano.php';
    require_once __DIR__ . '/../_servicos/auditoria.php';
    $conexao->set_charset('utf8mb4');
    $contexto=permissoesContexto($conexao);
    if (!($contexto['valido'] ?? false)) out(['ok'=>false,'code'=>'COMPANY_ACCESS_DENIED','user_msg'=>'Acesso à empresa não autorizado.'],403);
    if (!($contexto['super_admin_suporte'] ?? false) && ($contexto['perfil'] ?? '') === 'profissional' && (int)($contexto['id_profissional'] ?? 0)!==$idProfissional) out(['ok'=>false,'code'=>'PROFESSIONAL_ACCESS_DENIED','user_msg'=>'O profissional só pode editar os próprios agendamentos.'],403);

    // Snapshot mínimo com rótulos históricos, sempre limitado à empresa da sessão.
    $stmt=$conexao->prepare("SELECT a.id_agendamento,a.id_cliente,c.nome_completo,a.id_profissional,up.nome,a.id_servico,s.nome,a.data_agendamento,a.hora_inicio,a.hora_fim,a.duracao_min_aplicada,a.valor_aplicado,a.status,a.repetir_semanalmente,a.recorrencia_data_fim,a.grupo_recorrencia FROM agendamento a INNER JOIN cliente c ON c.id_cliente=a.id_cliente AND c.id_empresa=a.id_empresa INNER JOIN profissional p ON p.id_profissional=a.id_profissional INNER JOIN usuario up ON up.id_usuario=p.id_usuario INNER JOIN servico s ON s.id_servico=a.id_servico AND s.id_empresa=a.id_empresa WHERE a.id_agendamento=? AND a.id_empresa=? LIMIT 1");
    $stmt->bind_param('ii',$id,$idEmpresa); $stmt->execute();
    $stmt->bind_result($idDb,$clienteAnteriorId,$clienteAnteriorNome,$profAnteriorId,$profAnteriorNome,$servicoAnteriorId,$servicoAnteriorNome,$dataOriginalTexto,$horaOriginalTexto,$horaFimAnterior,$duracaoAnterior,$valorAnterior,$statusAnterior,$repetirDb,$fimRecDbOriginal,$grupoDb); $existe=$stmt->fetch(); $stmt->close();
    if (!$existe) out(['ok'=>false,'code'=>'APPOINTMENT_NOT_FOUND','user_msg'=>'Agendamento não encontrado.'],404);

    $ocorrenciaRecorrente=trim((string)$grupoDb)!=='';
    if ($ocorrenciaRecorrente && $data && $data->format('o-W')!==(new DateTimeImmutable((string)$dataOriginalTexto))->format('o-W')) {
        out(['ok'=>false,'code'=>'RECURRENCE_WEEK_CHANGE_NOT_ALLOWED','user_msg'=>'Esta ocorrência recorrente só pode ser reagendada dentro da própria semana. As demais semanas não serão alteradas.'],422);
    }
    if (!$ocorrenciaRecorrente && $repetir && (!$fimRec || ($data && $fimRec<$data))) {
        out(['ok'=>false,'code'=>'VALIDATION_ERROR','user_msg'=>'Informe uma data final válida para a recorrência.','fields'=>['recorrencia_data_fim'=>'Informe uma data final válida.']],422);
    }

    $stmt=$conexao->prepare("SELECT id_cliente,nome_completo FROM cliente WHERE id_cliente=? AND id_empresa=? AND status='ativo' LIMIT 1");
    $stmt->bind_param('ii',$idCliente,$idEmpresa); $stmt->execute(); $stmt->bind_result($clienteNovoId,$clienteNovoNome); $ok=$stmt->fetch(); $stmt->close();
    if (!$ok) out(['ok'=>false,'code'=>'CLIENT_NOT_FOUND','user_msg'=>'Cliente não encontrado ou inativo.'],404);

    $stmt=$conexao->prepare("SELECT s.duracao_min,s.valor,s.nome,u.nome FROM servico s INNER JOIN profissional p ON p.id_profissional=s.id_profissional INNER JOIN usuario u ON u.id_usuario=p.id_usuario INNER JOIN empresa_usuario eu ON eu.id_usuario=p.id_usuario AND eu.id_empresa=s.id_empresa WHERE s.id_servico=? AND s.id_profissional=? AND s.id_empresa=? AND s.status='ativo' AND eu.status='ativo' LIMIT 1");
    $stmt->bind_param('iii',$idServico,$idProfissional,$idEmpresa); $stmt->execute(); $stmt->bind_result($duracaoDb,$valorDb,$servicoNovoNome,$profNovoNome); $servico=$stmt->fetch(); $stmt->close();
    if (!$servico || (int)$duracaoDb<=0) out(['ok'=>false,'code'=>'SERVICE_NOT_FOUND','user_msg'=>'Serviço não encontrado para o profissional selecionado.'],404);
    $duracao=$duracaoSolicitada;
    $inicio=DateTimeImmutable::createFromFormat('!H:i',$horaTexto); $fim=$inicio?->modify('+'.$duracao.' minutes');
    if (!$inicio || !$fim || $fim->format('Y-m-d')!=='1970-01-01') out(['ok'=>false,'code'=>'INVALID_END_TIME','user_msg'=>'A duração do serviço ultrapassa o fim do dia.'],422);
    $horaInicio=$inicio->format('H:i:s'); $horaFim=$fim->format('H:i:s'); $valor=(float)$valorDb;
    $dataHoraInicio=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$dataTexto.' '.$horaTexto);
    $mantemHorarioOriginal=$dataTexto===(string)$dataOriginalTexto && $horaTexto===substr((string)$horaOriginalTexto,0,5);
    if (in_array($status,['pendente','confirmado'],true) && !$mantemHorarioOriginal && (!$dataHoraInicio || $dataHoraInicio<=new DateTimeImmutable('now'))) out(['ok'=>false,'code'=>'PAST_START_TIME','user_msg'=>'Selecione um horário que ainda não tenha começado.'],422);
    $obsDb=$obs!==''?$obs:null;
    // Preserva os metadados da série: mover esta ocorrência nunca reprograma
    // nem desvincula silenciosamente as ocorrências das outras semanas.
    if ($ocorrenciaRecorrente) {
        $rep=(int)$repetirDb;
        $fimRecDb=$fimRecDbOriginal;
        $grupo=$grupoDb;
    } else {
        $rep=$repetir?1:0;
        $fimRecDb=$repetir?$fimRecTexto:null;
        $grupo=null;
    }

    // Cancelar/concluir/falta não ocupa a grade; reagendamento ativo recalcula e valida tudo.
    $statusBloqueiaHorario=in_array($status,['pendente','confirmado'],true);
    if ($statusBloqueiaHorario) {
        require_once __DIR__.'/validar_horario_agendamento.php';
        $erroHorario=validarHorarioAgendamento($conexao,$idEmpresa,$idProfissional,$data,$horaInicio,$duracao);
        if ($erroHorario!==null) out(['ok'=>false,'code'=>'INVALID_SCHEDULE','user_msg'=>$erroHorario],422);
    }

    $conexao->begin_transaction();
    $avisosPlano=[];
    if (substr((string)$dataOriginalTexto,0,7)!==$data->format('Y-m')) {
        $resultadoPlano=limitesPlanoBloquearEmpresa($conexao,$idEmpresa);
        limitesPlanoAbortarSeNegado($conexao,$resultadoPlano);
        $resultadoAgendamentos=limitesPlanoVerificarAgendamentosPorMes($conexao,$resultadoPlano['plano'],$idEmpresa,[$dataTexto]);
        limitesPlanoAbortarSeNegado($conexao,$resultadoAgendamentos);
        $avisosPlano=$resultadoAgendamentos['avisos'] ?? [];
    }
    if ($statusBloqueiaHorario) {
        $stmt=$conexao->prepare("SELECT id_agendamento FROM agendamento WHERE id_empresa=? AND id_profissional=? AND data_agendamento=? AND id_agendamento<>? AND status IN ('pendente','confirmado') AND hora_inicio<? AND hora_fim>? LIMIT 1 FOR UPDATE");
        $stmt->bind_param('iisiss',$idEmpresa,$idProfissional,$dataTexto,$id,$horaFim,$horaInicio); $stmt->execute(); $stmt->store_result();
        if ($stmt->num_rows>0) { $stmt->close(); $conexao->rollback(); out(['ok'=>false,'code'=>'SCHEDULE_CONFLICT','user_msg'=>'O horário selecionado já está ocupado.'],409); } $stmt->close();
    }
    // Inclusive quando somente o status muda, o WHERE por ID garante que
    // nenhuma outra ocorrência do mesmo grupo de recorrência seja alterada.
    $stmt=$conexao->prepare("UPDATE agendamento SET id_cliente=?,id_profissional=?,id_servico=?,data_agendamento=?,hora_inicio=?,hora_fim=?,duracao_min_aplicada=?,valor_aplicado=?,status=?,observacao=?,repetir_semanalmente=?,recorrencia_data_fim=?,grupo_recorrencia=? WHERE id_agendamento=? AND id_empresa=? LIMIT 1");
    $stmt->bind_param('iiisssidssissii',$idCliente,$idProfissional,$idServico,$dataTexto,$horaInicio,$horaFim,$duracao,$valor,$status,$obsDb,$rep,$fimRecDb,$grupo,$id,$idEmpresa);
    $stmt->execute(); $stmt->close();
    $campos=['cliente'=>[['id'=>(int)$clienteAnteriorId,'rotulo'=>$clienteAnteriorNome],['id'=>$idCliente,'rotulo'=>$clienteNovoNome]],'profissional'=>[['id'=>(int)$profAnteriorId,'rotulo'=>$profAnteriorNome],['id'=>$idProfissional,'rotulo'=>$profNovoNome]],'servico'=>[['id'=>(int)$servicoAnteriorId,'rotulo'=>$servicoAnteriorNome],['id'=>$idServico,'rotulo'=>$servicoNovoNome]],'data_agendamento'=>[$dataOriginalTexto,$dataTexto],'hora_inicio'=>[$horaOriginalTexto,$horaInicio],'hora_fim'=>[$horaFimAnterior,$horaFim],'duracao_min_aplicada'=>[(int)$duracaoAnterior,$duracao],'valor_aplicado'=>[(float)$valorAnterior,(float)$valor],'status'=>[$statusAnterior,$status],'recorrencia'=>[['grupo'=>$grupoDb,'data_fim'=>$fimRecDbOriginal],['grupo'=>$grupo,'data_fim'=>$fimRecDb]]];
    $diferencas=[];foreach($campos as $campo=>[$antes,$depois])if(!auditoriaValoresIguais($antes,$depois))$diferencas[$campo]=['antes'=>$antes,'depois'=>$depois];
    if($diferencas!==[]){$somenteStatus=array_keys($diferencas)===['status'];$eventoStatus=['confirmado'=>'agendamento.confirmado','cancelado'=>'agendamento.cancelado','concluido'=>'agendamento.concluido'];$evento=$somenteStatus&&isset($eventoStatus[$status])?$eventoStatus[$status]:'agendamento.editado';auditoriaRegistrar($conexao,$evento,['entidade_id'=>$id,'entidade_rotulo'=>(string)$clienteNovoNome,'descricao'=>($evento==='agendamento.confirmado'?'Confirmou':($evento==='agendamento.cancelado'?'Cancelou':($evento==='agendamento.concluido'?'Concluiu':'Alterou'))).' o agendamento de '.$clienteNovoNome.'.','alteracoes'=>$diferencas,'contexto'=>['origem'=>'agenda','escopo'=>'ocorrencia_unica','grupo_recorrencia'=>$grupo]]);}
    $conexao->commit();
    out(['ok'=>true,'code'=>'APPOINTMENT_UPDATED','user_msg'=>$ocorrenciaRecorrente?'Ocorrência reagendada sem alterar as demais semanas.':'Agendamento atualizado com sucesso.','data'=>['id_agendamento'=>$id,'ocorrencia_recorrente'=>$ocorrenciaRecorrente,'avisos_plano'=>$avisosPlano]],200);
} catch (Throwable $e) { if ($conexao instanceof mysqli) { try{$conexao->rollback();}catch(Throwable $x){} } error_log('[editar_agendamento] '.$e->getMessage()); out(['ok'=>false,'code'=>'INTERNAL_ERROR','user_msg'=>'Não foi possível atualizar o agendamento.'],500); }
