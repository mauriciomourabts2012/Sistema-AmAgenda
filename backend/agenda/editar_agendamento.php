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
function acaoRapidaAgendamentoDefinicao(string $acao): ?array {
    $definicoes = [
        'confirmar' => ['origens'=>['pendente'],'destino'=>'confirmado','exige_inicio'=>false],
        'concluir' => ['origens'=>['confirmado'],'destino'=>'concluido','exige_inicio'=>true],
        'faltou' => ['origens'=>['confirmado'],'destino'=>'faltou','exige_inicio'=>true],
        'cancelar' => ['origens'=>['pendente','confirmado'],'destino'=>'cancelado','exige_inicio'=>false],
    ];
    return $definicoes[$acao] ?? null;
}
/** Política isolada das ações rápidas; o fluxo normal do modal não passa por ela. */
function acaoRapidaAgendamentoValidar(string $acao, string $statusAtual, ?DateTimeImmutable $inicio, DateTimeImmutable $agora): array {
    $definicao=acaoRapidaAgendamentoDefinicao($acao);
    if ($definicao===null) return ['ok'=>false,'code'=>'INVALID_QUICK_ACTION','user_msg'=>'Ação rápida inválida.'];
    if (!in_array($statusAtual,$definicao['origens'],true)) {
        $mensagem=$acao==='confirmar'
            ? 'Este agendamento não está mais pendente. Atualize a agenda.'
            : 'O status deste agendamento foi alterado. Atualize a agenda.';
        return ['ok'=>false,'code'=>'APPOINTMENT_STATUS_CHANGED','user_msg'=>$mensagem];
    }
    if ($definicao['exige_inicio'] && ($inicio===null || $inicio>$agora)) {
        return ['ok'=>false,'code'=>'APPOINTMENT_NOT_STARTED','user_msg'=>'Este atendimento ainda não começou.'];
    }
    return ['ok'=>true]+$definicao;
}
/**
 * Ciclo de vida do modal de edição completa (as ações rápidas usam a política acima).
 * Status finalizados não voltam, Pendente não pula para resultado, Concluído e Faltou
 * exigem o início do atendimento e um resultado nunca é gravado junto com outras alterações.
 */
function edicaoAgendamentoTransicaoValidar(string $origem, string $destino, bool $somenteStatus, ?DateTimeImmutable $inicio, DateTimeImmutable $agora): array {
    $finais=['concluido','cancelado','faltou'];
    if (in_array($origem,$finais,true)) {
        return ['ok'=>false,'http'=>409,'code'=>'APPOINTMENT_FINALIZED','user_msg'=>'Este agendamento já foi finalizado e não pode ser alterado.'];
    }
    $permitidos=['pendente'=>['pendente','confirmado','cancelado'],'confirmado'=>['confirmado','concluido','faltou','cancelado']];
    if (!in_array($destino,$permitidos[$origem] ?? [],true)) {
        return ['ok'=>false,'http'=>422,'code'=>'INVALID_STATUS_TRANSITION','user_msg'=>'Esta alteração de status não é permitida para a situação atual do agendamento.'];
    }
    if (in_array($destino,$finais,true) && !$somenteStatus) {
        return ['ok'=>false,'http'=>422,'code'=>'FINAL_STATUS_WITH_CHANGES','user_msg'=>'Para registrar o resultado, altere somente o status. Salve antes as demais alterações.'];
    }
    if (in_array($destino,['concluido','faltou'],true) && ($inicio===null || $inicio>$agora)) {
        return ['ok'=>false,'http'=>422,'code'=>'APPOINTMENT_NOT_STARTED','user_msg'=>'Este atendimento ainda não começou.'];
    }
    return ['ok'=>true];
}
// Alteração somente de status: compara o pedido com o snapshot do banco. Em ocorrência
// recorrente a série é sempre preservada pelo backend; fora dela, mudar repetição/data final
// continua sendo uma edição real.
function edicaoAgendamentoAlteraSomenteStatus(array $banco, array $pedido, bool $ocorrenciaRecorrente): bool {
    $iguais=(int)$banco['id_cliente']===(int)$pedido['id_cliente'] && (int)$banco['id_profissional']===(int)$pedido['id_profissional']
        && (int)$banco['id_servico']===(int)$pedido['id_servico'] && (string)$banco['data_agendamento']===(string)$pedido['data_agendamento']
        && substr((string)$banco['hora_inicio'],0,5)===substr((string)$pedido['hora_inicio'],0,5) && (int)$banco['duracao']===(int)$pedido['duracao']
        && trim((string)$banco['observacao'])===trim((string)$pedido['observacao']);
    if (!$iguais || $ocorrenciaRecorrente) return $iguais;
    $repetirBanco=(int)$banco['repetir_semanalmente']===1; $repetirPedido=(int)$pedido['repetir_semanalmente']===1;
    if ($repetirBanco!==$repetirPedido) return false;
    return !$repetirBanco || (string)$banco['recorrencia_data_fim']===(string)$pedido['recorrencia_data_fim'];
}
$conexao = null;

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(['ok'=>false,'code'=>'METHOD_NOT_ALLOWED','user_msg'=>'Método não permitido.'],405);
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $auth=$_SESSION['auth'] ?? []; $idUsuario=(int)($auth['id_usuario'] ?? 0);
    $idEmpresa=(int)($auth['id_empresa'] ?? $_SESSION['empresa_id'] ?? $_SESSION['id_empresa'] ?? $_SESSION['empresa']['id_empresa'] ?? 0);
    if ($idUsuario<=0) out(['ok'=>false,'code'=>'NOT_AUTHENTICATED','user_msg'=>'Sessão expirada. Faça login novamente.'],401);
    if ($idEmpresa<=0) out(['ok'=>false,'code'=>'SESSION_WITHOUT_COMPANY','user_msg'=>'Não foi possível identificar a empresa da sessão.'],403);
    // Escrita autenticada: exige o token CSRF da sessão (mecanismo existente).
    require_once __DIR__ . '/../_auth/csrf.php';
    csrfValidarSessao();

    $id=filter_input(INPUT_POST,'id_agendamento',FILTER_VALIDATE_INT) ?: 0;
    $idCliente=filter_input(INPUT_POST,'id_cliente',FILTER_VALIDATE_INT) ?: 0;
    $idProfissional=filter_input(INPUT_POST,'id_profissional',FILTER_VALIDATE_INT) ?: 0;
    $idServico=filter_input(INPUT_POST,'id_servico',FILTER_VALIDATE_INT) ?: 0;
    $duracaoSolicitada=filter_input(INPUT_POST,'duracao',FILTER_VALIDATE_INT) ?: 0;
    $dataTexto=trim((string)($_POST['data_agendamento'] ?? '')); $data=ed_data($dataTexto);
    $horaTexto=trim((string)($_POST['hora_inicio'] ?? '')); $status=strtolower(trim((string)($_POST['status'] ?? 'pendente')));
    $obs=trim((string)($_POST['observacao'] ?? '')); $repetir=(int)($_POST['repetir_semanalmente'] ?? 0)===1;
    $fimRecTexto=trim((string)($_POST['recorrencia_data_fim'] ?? '')); $fimRec=$fimRecTexto!=='' ? ed_data($fimRecTexto) : null;
    // Ações rápidas usam o mesmo caso de uso, mas possuem transições fechadas e nunca
    // confiam no status de destino enviado pelo navegador.
    $acaoRapida=trim((string)($_POST['acao_rapida'] ?? ''));
    $definicaoAcaoRapida=$acaoRapida!==''?acaoRapidaAgendamentoDefinicao($acaoRapida):null;
    $acaoRapidaAtiva=$definicaoAcaoRapida!==null;
    $erros=[];
    if ($id<=0) $erros['id_agendamento']='Agendamento inválido.';
    if ($idCliente<=0) $erros['id_cliente']='Selecione o cliente.';
    if ($idProfissional<=0) $erros['id_profissional']='Selecione o profissional.';
    if ($idServico<=0) $erros['id_servico']='Selecione o serviço.';
    $duracoesPermitidas=[15,30,45,60,75,90,105,120,150,180,210,240];
    // Na ação rápida a duração gravada é reenviada como está (conferida contra o banco abaixo).
    if (!in_array($duracaoSolicitada,$duracoesPermitidas,true) && !($acaoRapidaAtiva && $duracaoSolicitada>0)) $erros['duracao']='Selecione uma duração válida.';
    if ($acaoRapida!=='' && !$acaoRapidaAtiva) $erros['acao_rapida']='Ação rápida inválida.';
    // O destino da ação rápida é definido somente pelo backend; o status enviado é ignorado.
    if ($acaoRapidaAtiva) $status=(string)$definicaoAcaoRapida['destino'];
    // Data passada é decidida após o snapshot do banco (exceção de alteração somente de status).
    if (!$data) $erros['data_agendamento']='Selecione uma data válida.';
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$horaTexto)) $erros['hora_inicio']='Selecione um horário válido.';
    if (!in_array($status,['pendente','confirmado','concluido','cancelado','faltou'],true)) $erros['status']='Status inválido.';
    if (mb_strlen($obs)>220) $erros['observacao']='A observação deve ter no máximo 220 caracteres.';
    if ($erros) out(['ok'=>false,'code'=>'VALIDATION_ERROR','user_msg'=>'Revise os dados do agendamento.','fields'=>$erros],422);

    require __DIR__ . '/../_config/conexao.php';
    require_once __DIR__ . '/../_regras/permissoes_usuario.php';
    require_once __DIR__ . '/../_regras/limites_plano.php';
    require_once __DIR__ . '/../_servicos/auditoria.php';
    require_once __DIR__ . '/../_servicos/chat.php';
    require_once __DIR__ . '/../_servicos/notificacao.php';
    $conexao->set_charset('utf8mb4');
    $contexto=permissoesContexto($conexao);
    if (!($contexto['valido'] ?? false)) out(['ok'=>false,'code'=>'COMPANY_ACCESS_DENIED','user_msg'=>'Acesso à empresa não autorizado.'],403);
    // A rota já exige agenda.editar_agendamento. Cancelar pela ação rápida exige também
    // a permissão específica, sem alterar as transições disponíveis no modal completo.
    if ($acaoRapida==='cancelar') exigirPermissao($conexao,'agenda.cancelar_agendamento');
    if (!($contexto['super_admin_suporte'] ?? false) && ($contexto['perfil'] ?? '') === 'profissional' && (int)($contexto['id_profissional'] ?? 0)!==$idProfissional) out(['ok'=>false,'code'=>'PROFESSIONAL_ACCESS_DENIED','user_msg'=>'O profissional só pode editar os próprios agendamentos.'],403);

    // Snapshot mínimo com rótulos históricos, sempre limitado à empresa da sessão.
    $stmt=$conexao->prepare("SELECT a.id_agendamento,a.id_cliente,c.nome_completo,a.id_profissional,up.nome,a.id_servico,s.nome,a.data_agendamento,a.hora_inicio,a.hora_fim,a.duracao_min_aplicada,a.valor_aplicado,a.status,a.observacao,a.repetir_semanalmente,a.recorrencia_data_fim,a.grupo_recorrencia FROM agendamento a INNER JOIN cliente c ON c.id_cliente=a.id_cliente AND c.id_empresa=a.id_empresa INNER JOIN profissional p ON p.id_profissional=a.id_profissional INNER JOIN usuario up ON up.id_usuario=p.id_usuario INNER JOIN servico s ON s.id_servico=a.id_servico AND s.id_empresa=a.id_empresa WHERE a.id_agendamento=? AND a.id_empresa=? LIMIT 1");
    $stmt->bind_param('ii',$id,$idEmpresa); $stmt->execute();
    $stmt->bind_result($idDb,$clienteAnteriorId,$clienteAnteriorNome,$profAnteriorId,$profAnteriorNome,$servicoAnteriorId,$servicoAnteriorNome,$dataOriginalTexto,$horaOriginalTexto,$horaFimAnterior,$duracaoAnterior,$valorAnterior,$statusAnterior,$obsAnterior,$repetirDb,$fimRecDbOriginal,$grupoDb); $existe=$stmt->fetch(); $stmt->close();
    if (!$existe) out(['ok'=>false,'code'=>'APPOINTMENT_NOT_FOUND','user_msg'=>'Agendamento não encontrado.'],404);
    // O profissional também precisa ser o responsável atual pelo registro.
    if (!($contexto['super_admin_suporte'] ?? false) && ($contexto['perfil'] ?? '') === 'profissional' && (int)($contexto['id_profissional'] ?? 0)!==(int)$profAnteriorId) out(['ok'=>false,'code'=>'PROFESSIONAL_ACCESS_DENIED','user_msg'=>'O profissional só pode editar os próprios agendamentos.'],403);

    // Alteração somente de status preserva todos os dados históricos do registro.
    // A comparação é feita contra o snapshot do banco, nunca apenas pelo frontend.
    $somenteStatus=edicaoAgendamentoAlteraSomenteStatus(
        [
            'id_cliente'=>(int)$clienteAnteriorId,'id_profissional'=>(int)$profAnteriorId,'id_servico'=>(int)$servicoAnteriorId,
            'data_agendamento'=>(string)$dataOriginalTexto,'hora_inicio'=>(string)$horaOriginalTexto,'duracao'=>(int)$duracaoAnterior,
            'observacao'=>(string)$obsAnterior,'repetir_semanalmente'=>(int)$repetirDb,'recorrencia_data_fim'=>(string)$fimRecDbOriginal,
        ],
        [
            'id_cliente'=>$idCliente,'id_profissional'=>$idProfissional,'id_servico'=>$idServico,
            'data_agendamento'=>$dataTexto,'hora_inicio'=>$horaTexto,'duracao'=>$duracaoSolicitada,
            'observacao'=>$obs,'repetir_semanalmente'=>$repetir?1:0,'recorrencia_data_fim'=>$fimRecTexto,
        ],
        trim((string)$grupoDb)!==''
    );
    $inicioOriginal=DateTimeImmutable::createFromFormat('!Y-m-d H:i',(string)$dataOriginalTexto.' '.substr((string)$horaOriginalTexto,0,5));
    // Ação rápida: valida a transição e o horário sobre o snapshot autorizado. A mesma
    // regra será aplicada novamente depois do FOR UPDATE para cobrir concorrência.
    if ($acaoRapidaAtiva) {
        $validacaoAcaoRapida=acaoRapidaAgendamentoValidar(
            $acaoRapida,
            (string)$statusAnterior,
            $inicioOriginal===false?null:$inicioOriginal,
            new DateTimeImmutable('now')
        );
        if (!($validacaoAcaoRapida['ok'] ?? false)) out(['ok'=>false,'code'=>$validacaoAcaoRapida['code'],'user_msg'=>$validacaoAcaoRapida['user_msg']],409);
        if (!$somenteStatus) out(['ok'=>false,'code'=>'APPOINTMENT_CHANGED','user_msg'=>'Este agendamento foi alterado por outra pessoa. Atualize a agenda e tente novamente.'],409);
    }
    // Edição completa: aplica o ciclo de vida sobre o snapshot; a mesma regra é conferida
    // novamente sobre o status relido depois do FOR UPDATE.
    if (!$acaoRapidaAtiva) {
        $validacaoTransicao=edicaoAgendamentoTransicaoValidar((string)$statusAnterior,$status,$somenteStatus,$inicioOriginal===false?null:$inicioOriginal,new DateTimeImmutable('now'));
        if (!($validacaoTransicao['ok'] ?? false)) out(['ok'=>false,'code'=>$validacaoTransicao['code'],'user_msg'=>$validacaoTransicao['user_msg']],(int)$validacaoTransicao['http']);
        if ($status==='cancelado') exigirPermissao($conexao,'agenda.cancelar_agendamento');
    }
    // Agendamento que já começou continua aceitando somente essa alteração segura.
    $somenteStatusPassado=$inicioOriginal!==false && $inicioOriginal<=new DateTimeImmutable('now')
        && $somenteStatus;
    if ($data < new DateTimeImmutable('today') && !$somenteStatusPassado) out(['ok'=>false,'code'=>'VALIDATION_ERROR','user_msg'=>'Revise os dados do agendamento.','fields'=>['data_agendamento'=>'Não é permitido agendar em uma data passada.']],422);

    $ocorrenciaRecorrente=trim((string)$grupoDb)!=='';
    if ($ocorrenciaRecorrente && $data && $data->format('o-W')!==(new DateTimeImmutable((string)$dataOriginalTexto))->format('o-W')) {
        out(['ok'=>false,'code'=>'RECURRENCE_WEEK_CHANGE_NOT_ALLOWED','user_msg'=>'Esta ocorrência recorrente só pode ser reagendada dentro da própria semana. As demais semanas não serão alteradas.'],422);
    }
    if (!$ocorrenciaRecorrente && !$somenteStatusPassado && $repetir && (!$fimRec || ($data && $fimRec<$data))) {
        out(['ok'=>false,'code'=>'VALIDATION_ERROR','user_msg'=>'Informe uma data final válida para a recorrência.','fields'=>['recorrencia_data_fim'=>'Informe uma data final válida.']],422);
    }

    if ($somenteStatus) {
        // Alterar apenas o status mantém o cliente original, inclusive se estiver inativo hoje.
        $clienteNovoNome=$clienteAnteriorNome;
    } else {
        $stmt=$conexao->prepare("SELECT id_cliente,nome_completo FROM cliente WHERE id_cliente=? AND id_empresa=? AND status='ativo' LIMIT 1");
        $stmt->bind_param('ii',$idCliente,$idEmpresa); $stmt->execute(); $stmt->bind_result($clienteNovoId,$clienteNovoNome); $ok=$stmt->fetch(); $stmt->close();
        if (!$ok) out(['ok'=>false,'code'=>'CLIENT_NOT_FOUND','user_msg'=>'Cliente não encontrado ou inativo.'],404);
    }

    if ($somenteStatus) {
        // Serviço, profissional, duração e valor permanecem exatamente como foram gravados.
        $duracaoDb=(int)$duracaoAnterior; $valorDb=$valorAnterior; $servicoNovoNome=$servicoAnteriorNome; $profNovoNome=$profAnteriorNome;
    } else {
        $stmt=$conexao->prepare("SELECT s.duracao_min,s.valor,s.nome,u.nome FROM servico s INNER JOIN profissional p ON p.id_profissional=s.id_profissional INNER JOIN usuario u ON u.id_usuario=p.id_usuario INNER JOIN empresa_usuario eu ON eu.id_usuario=p.id_usuario AND eu.id_empresa=s.id_empresa WHERE s.id_servico=? AND s.id_profissional=? AND s.id_empresa=? AND s.status='ativo' AND eu.status='ativo' LIMIT 1");
        $stmt->bind_param('iii',$idServico,$idProfissional,$idEmpresa); $stmt->execute(); $stmt->bind_result($duracaoDb,$valorDb,$servicoNovoNome,$profNovoNome); $servico=$stmt->fetch(); $stmt->close();
        if (!$servico || (int)$duracaoDb<=0) out(['ok'=>false,'code'=>'SERVICE_NOT_FOUND','user_msg'=>'Serviço não encontrado para o profissional selecionado.'],404);
    }
    $duracao=$duracaoSolicitada;
    $inicio=DateTimeImmutable::createFromFormat('!H:i',$horaTexto); $fim=$inicio?->modify('+'.$duracao.' minutes');
    if (!$inicio || !$fim || $fim->format('Y-m-d')!=='1970-01-01') out(['ok'=>false,'code'=>'INVALID_END_TIME','user_msg'=>'A duração do serviço ultrapassa o fim do dia.'],422);
    $horaInicio=$inicio->format('H:i:s'); $horaFim=$fim->format('H:i:s'); $valor=$somenteStatus?(float)$valorAnterior:(float)$valorDb;
    $dataHoraInicio=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$dataTexto.' '.$horaTexto);
    $mantemHorarioOriginal=$dataTexto===(string)$dataOriginalTexto && $horaTexto===substr((string)$horaOriginalTexto,0,5);
    // Nenhum status permite mover o agendamento para outra data/hora já passada.
    if (!$mantemHorarioOriginal && (!$dataHoraInicio || $dataHoraInicio<=new DateTimeImmutable('now'))) out(['ok'=>false,'code'=>'PAST_START_TIME','user_msg'=>'Selecione um horário que ainda não tenha começado.'],422);
    $obsDb=$obs!==''?$obs:null;
    // Preserva os metadados da série: mover esta ocorrência nunca reprograma
    // nem desvincula silenciosamente as ocorrências das outras semanas.
    if ($ocorrenciaRecorrente || $somenteStatus) {
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
    // Slot mantido (mesma data, hora, profissional e duração do banco): o horário já gravado é
    // preservado e não é reavaliado contra grade, expediente, almoço ou exceções — ele pode ter
    // sido deslocado por um atraso real (Fase 5D). Qualquer mudança de slot valida tudo.
    // Conflito com outros agendamentos continua sendo conferido na transação abaixo.
    $slotMantido=$mantemHorarioOriginal && $idProfissional===(int)$profAnteriorId && $duracao===(int)$duracaoAnterior;
    // Somente status em agendamento já iniciado: a grade/jornada atual não reavalia o horário original.
    if ($statusBloqueiaHorario && !$somenteStatusPassado && !$slotMantido) {
        require_once __DIR__.'/validar_horario_agendamento.php';
        $erroHorario=validarHorarioAgendamento($conexao,$idEmpresa,$idProfissional,$data,$horaInicio,$duracao);
        if ($erroHorario!==null) out(['ok'=>false,'code'=>'INVALID_SCHEDULE','user_msg'=>$erroHorario],422);
    }

    $conexao->begin_transaction();
    // Mesma ordem de bloqueio da exclusão: primeiro o agendamento, depois a conversa.
    // Isso impede que edição e exclusão concorrentes invertam locks entre Agenda e Chat.
    $stmt=$conexao->prepare("SELECT id_agendamento FROM agendamento WHERE id_agendamento=? AND id_empresa=? LIMIT 1 FOR UPDATE");
    $stmt->bind_param('ii',$id,$idEmpresa); $stmt->execute(); $stmt->store_result();
    if ($stmt->num_rows!==1) { $stmt->close(); $conexao->rollback(); out(['ok'=>false,'code'=>'APPOINTMENT_NOT_FOUND','user_msg'=>'Agendamento não encontrado.'],404); }
    $stmt->close();
    if ($acaoRapidaAtiva) {
        // Concorrência: com o registro travado, relê o estado atual. Se a transição deixou de
        // ser válida ou qualquer campo mudou desde o snapshot, não grava nada.
        $stmt=$conexao->prepare("SELECT status,id_cliente,id_profissional,id_servico,data_agendamento,hora_inicio,hora_fim,duracao_min_aplicada,valor_aplicado,observacao,repetir_semanalmente,recorrencia_data_fim,grupo_recorrencia FROM agendamento WHERE id_agendamento=? AND id_empresa=? LIMIT 1");
        $stmt->bind_param('ii',$id,$idEmpresa); $stmt->execute(); $atual=$stmt->get_result()->fetch_assoc(); $stmt->close();
        $inicioAtual=$atual===null?false:DateTimeImmutable::createFromFormat('!Y-m-d H:i',(string)$atual['data_agendamento'].' '.substr((string)$atual['hora_inicio'],0,5));
        $validacaoAtual=acaoRapidaAgendamentoValidar(
            $acaoRapida,
            (string)($atual['status'] ?? ''),
            $inicioAtual===false?null:$inicioAtual,
            new DateTimeImmutable('now')
        );
        if (!($validacaoAtual['ok'] ?? false)) { $conexao->rollback(); out(['ok'=>false,'code'=>$validacaoAtual['code'],'user_msg'=>$validacaoAtual['user_msg']],409); }
        $inalterado=$atual!==null
            && (int)$atual['id_cliente']===(int)$clienteAnteriorId && (int)$atual['id_profissional']===(int)$profAnteriorId
            && (int)$atual['id_servico']===(int)$servicoAnteriorId && (string)$atual['data_agendamento']===(string)$dataOriginalTexto
            && (string)$atual['hora_inicio']===(string)$horaOriginalTexto && (string)$atual['hora_fim']===(string)$horaFimAnterior
            && (int)$atual['duracao_min_aplicada']===(int)$duracaoAnterior && abs((float)$atual['valor_aplicado']-(float)$valorAnterior)<0.005
            && (string)($atual['observacao'] ?? '')===(string)($obsAnterior ?? '') && (int)$atual['repetir_semanalmente']===(int)$repetirDb
            && (string)($atual['recorrencia_data_fim'] ?? '')===(string)($fimRecDbOriginal ?? '') && (string)($atual['grupo_recorrencia'] ?? '')===(string)($grupoDb ?? '');
        if (!$inalterado) { $conexao->rollback(); out(['ok'=>false,'code'=>'APPOINTMENT_CHANGED','user_msg'=>'Este agendamento foi alterado por outra pessoa. Atualize a agenda e tente novamente.'],409); }
    }
    if (!$acaoRapidaAtiva) {
        // Concorrência da edição completa: com o registro travado, status ou dados alterados por
        // outro fluxo (ação rápida, atraso, outra edição) desde o snapshot impedem a gravação.
        $stmt=$conexao->prepare("SELECT status,id_cliente,id_profissional,id_servico,data_agendamento,hora_inicio,hora_fim,duracao_min_aplicada,observacao FROM agendamento WHERE id_agendamento=? AND id_empresa=? LIMIT 1");
        $stmt->bind_param('ii',$id,$idEmpresa); $stmt->execute(); $atualEdicao=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($atualEdicao===null || (string)$atualEdicao['status']!==(string)$statusAnterior) { $conexao->rollback(); out(['ok'=>false,'code'=>'APPOINTMENT_STATUS_CHANGED','user_msg'=>'O status deste agendamento foi alterado por outra pessoa. Atualize a agenda.'],409); }
        $inalteradoEdicao=(int)$atualEdicao['id_cliente']===(int)$clienteAnteriorId && (int)$atualEdicao['id_profissional']===(int)$profAnteriorId
            && (int)$atualEdicao['id_servico']===(int)$servicoAnteriorId && (string)$atualEdicao['data_agendamento']===(string)$dataOriginalTexto
            && (string)$atualEdicao['hora_inicio']===(string)$horaOriginalTexto && (string)$atualEdicao['hora_fim']===(string)$horaFimAnterior
            && (int)$atualEdicao['duracao_min_aplicada']===(int)$duracaoAnterior && (string)($atualEdicao['observacao'] ?? '')===(string)($obsAnterior ?? '');
        if (!$inalteradoEdicao) { $conexao->rollback(); out(['ok'=>false,'code'=>'APPOINTMENT_CHANGED','user_msg'=>'Este agendamento foi alterado por outra pessoa. Atualize a agenda e tente novamente.'],409); }
        $validacaoTransicaoAtual=edicaoAgendamentoTransicaoValidar((string)$atualEdicao['status'],$status,$somenteStatus,$inicioOriginal===false?null:$inicioOriginal,new DateTimeImmutable('now'));
        if (!($validacaoTransicaoAtual['ok'] ?? false)) { $conexao->rollback(); out(['ok'=>false,'code'=>$validacaoTransicaoAtual['code'],'user_msg'=>$validacaoTransicaoAtual['user_msg']],(int)$validacaoTransicaoAtual['http']); }
    }
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
    // Compartilha esta transação: preserva a conversa histórica ou sincroniza o snapshot
    // antes de gravar a edição do agendamento, sem conceder acesso funcional ao Chat.
    chatPrepararConversaParaEdicaoAgendamento(
        $conexao,
        $idEmpresa,
        $id,
        $idCliente,
        $idProfissional,
        $dataTexto,
        $horaInicio,
        $idUsuario
    );
    // Inclusive quando somente o status muda, o WHERE por ID garante que
    // nenhuma outra ocorrência do mesmo grupo de recorrência seja alterada.
    // Resultado pelo modal (Concluído, Faltou ou Cancelado) também grava somente o status.
    $destinoFinalEdicao=!$acaoRapidaAtiva && in_array($status,['concluido','cancelado','faltou'],true);
    if ($acaoRapidaAtiva || $destinoFinalEdicao) {
        // Ação rápida grava SOMENTE o status; os demais dados atuais (inclusive hora_fim) ficam intactos.
        $horaFim=(string)$horaFimAnterior;
        $statusOrigem=(string)$statusAnterior;
        $stmt=$conexao->prepare("UPDATE agendamento SET status=? WHERE id_agendamento=? AND id_empresa=? AND status=? LIMIT 1");
        $stmt->bind_param('siis',$status,$id,$idEmpresa,$statusOrigem); $stmt->execute(); $alterouStatus=$stmt->affected_rows===1; $stmt->close();
        if (!$alterouStatus) { $conexao->rollback(); out(['ok'=>false,'code'=>'APPOINTMENT_STATUS_CHANGED','user_msg'=>'O status deste agendamento foi alterado. Atualize a agenda.'],409); }
    } else {
        $stmt=$conexao->prepare("UPDATE agendamento SET id_cliente=?,id_profissional=?,id_servico=?,data_agendamento=?,hora_inicio=?,hora_fim=?,duracao_min_aplicada=?,valor_aplicado=?,status=?,observacao=?,repetir_semanalmente=?,recorrencia_data_fim=?,grupo_recorrencia=? WHERE id_agendamento=? AND id_empresa=? LIMIT 1");
        $stmt->bind_param('iiisssidssissii',$idCliente,$idProfissional,$idServico,$dataTexto,$horaInicio,$horaFim,$duracao,$valor,$status,$obsDb,$rep,$fimRecDb,$grupo,$id,$idEmpresa);
        $stmt->execute(); $stmt->close();
    }
    $campos=['cliente'=>[['id'=>(int)$clienteAnteriorId,'rotulo'=>$clienteAnteriorNome],['id'=>$idCliente,'rotulo'=>$clienteNovoNome]],'profissional'=>[['id'=>(int)$profAnteriorId,'rotulo'=>$profAnteriorNome],['id'=>$idProfissional,'rotulo'=>$profNovoNome]],'servico'=>[['id'=>(int)$servicoAnteriorId,'rotulo'=>$servicoAnteriorNome],['id'=>$idServico,'rotulo'=>$servicoNovoNome]],'data_agendamento'=>[$dataOriginalTexto,$dataTexto],'hora_inicio'=>[$horaOriginalTexto,$horaInicio],'hora_fim'=>[$horaFimAnterior,$horaFim],'duracao_min_aplicada'=>[(int)$duracaoAnterior,$duracao],'valor_aplicado'=>[(float)$valorAnterior,(float)$valor],'status'=>[$statusAnterior,$status],'recorrencia'=>[['grupo'=>$grupoDb,'data_fim'=>$fimRecDbOriginal],['grupo'=>$grupo,'data_fim'=>$fimRecDb]]];
    $diferencas=[];foreach($campos as $campo=>[$antes,$depois])if(!auditoriaValoresIguais($antes,$depois))$diferencas[$campo]=['antes'=>$antes,'depois'=>$depois];
    if($diferencas!==[]){$alterouSomenteStatus=array_keys($diferencas)===['status'];$eventoStatus=['confirmado'=>'agendamento.confirmado','cancelado'=>'agendamento.cancelado','concluido'=>'agendamento.concluido','faltou'=>'agendamento.faltou'];$evento=$alterouSomenteStatus&&isset($eventoStatus[$status])?$eventoStatus[$status]:'agendamento.editado';auditoriaRegistrar($conexao,$evento,['entidade_id'=>$id,'entidade_rotulo'=>(string)$clienteNovoNome,'descricao'=>($evento==='agendamento.faltou'?'Registrou falta no agendamento de ':($evento==='agendamento.confirmado'?'Confirmou':($evento==='agendamento.cancelado'?'Cancelou':($evento==='agendamento.concluido'?'Concluiu':'Alterou'))).' o agendamento de ').$clienteNovoNome.'.','alteracoes'=>$diferencas,'contexto'=>['origem'=>'agenda','escopo'=>'ocorrencia_unica','grupo_recorrencia'=>$grupo]]);}
    // A central reflete o status já gravado nesta transação (pendente mantém/reativa/atualiza;
    // outro status conclui; troca de profissional cancela a do anterior). Falha desfaz a edição.
    notificacaoReconciliarAgendamentoPendente($conexao,$idEmpresa,$id);
    notificacaoReconciliarAgendamentoFinalizacao($conexao,$idEmpresa,$id);
    $conexao->commit();
    // Chat 5B-4: mensagem automática SOMENTE na transição para "confirmado" e SOMENTE após o commit.
    // A função relê o estado atual, tem transação própria e nunca lança; a edição já está
    // gravada e a resposta continua sendo sucesso mesmo se o Chat falhar.
    if ($statusAnterior!=='confirmado' && $status==='confirmado') {
        try { chatRegistrarEventoAgendamentoConfirmado($conexao,$idEmpresa,$id,$idUsuario); }
        catch (Throwable $e) { error_log('[editar_agendamento] chat evento=agendamento_confirmado empresa='.$idEmpresa.' agendamento='.$id.' erro='.get_class($e)); }
    }
    out(['ok'=>true,'code'=>'APPOINTMENT_UPDATED','user_msg'=>$ocorrenciaRecorrente?'Ocorrência reagendada sem alterar as demais semanas.':'Agendamento atualizado com sucesso.','data'=>['id_agendamento'=>$id,'ocorrencia_recorrente'=>$ocorrenciaRecorrente,'avisos_plano'=>$avisosPlano]],200);
} catch (Throwable $e) { if ($conexao instanceof mysqli) { try{$conexao->rollback();}catch(Throwable $x){} } error_log('[editar_agendamento] '.$e->getMessage()); out(['ok'=>false,'code'=>'INTERNAL_ERROR','user_msg'=>'Não foi possível atualizar o agendamento.'],500); }
