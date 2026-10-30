<?php
declare(strict_types=1);

if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

require_once __DIR__ . '/../../_auth/require_auth.php';
require_once __DIR__ . '/../../_regras/permissoes_usuario.php';
$rotaAtual = trim((string)($_GET['path'] ?? ''), "/ \t\n\r\0\x0B");
$cancelarContrato = $rotaAtual === 'painel/faturamento/cancelar'
    && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$novaContratacao = $rotaAtual === 'painel/faturamento/contratacao'
    && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$trocaPlano = $rotaAtual === 'painel/faturamento/troca-plano'
    && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

if (!$cancelarContrato && !$novaContratacao && !$trocaPlano && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    out(['ok'=>false,'code'=>'METHOD_NOT_ALLOWED','user_msg'=>'Método não permitido.'], 405);
}

if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
    out(['ok'=>false,'code'=>'DB_CONNECTION_ERROR','user_msg'=>'Erro de conexão com banco de dados.'], 500);
}

$contexto = permissoesContexto($conexao);
if (!($contexto['valido'] ?? false)) {
    out(['ok'=>false,'code'=>'SESSION_WITHOUT_COMPANY','user_msg'=>'Não foi possível identificar a empresa da sessão.'], 403);
}
exigirPermissao($conexao, 'faturamento.visualizar', $contexto);
$idEmpresa = (int)$contexto['id_empresa'];

function faturamentoBind(mysqli_stmt $stmt, string $tipos, array &$valores): void {
    $args = [$tipos];
    foreach ($valores as &$valor) $args[] = &$valor;
    call_user_func_array([$stmt, 'bind_param'], $args);
}

function faturamentoDecimal(mixed $valor): float { return round((float)$valor, 2); }

function faturamentoAssinatura(mysqli $db, int $empresa): ?array {
    $stmt = $db->prepare("SELECT a.id_assinatura, a.id_plano, p.nome AS plano, a.valor_contratado,
        a.periodicidade, a.dia_vencimento, DATE_FORMAT(a.data_inicio,'%Y-%m-%d') AS data_inicio,
        DATE_FORMAT(a.data_fim,'%Y-%m-%d') AS data_fim, a.status, a.modalidade, a.motivo_suspensao
        FROM assinatura a LEFT JOIN plano p ON p.id_plano=a.id_plano
        WHERE a.id_empresa=? AND (a.status='ativa'
            OR (a.status='suspensa' AND a.modalidade='teste' AND a.motivo_suspensao='teste_expirado')
            OR (a.status='suspensa' AND a.modalidade='paga' AND a.motivo_suspensao='inadimplencia'
                AND NOT EXISTS (SELECT 1 FROM assinatura ax WHERE ax.id_empresa=a.id_empresa AND ax.status='ativa'))
            OR (a.status='suspensa' AND a.modalidade='paga' AND a.motivo_suspensao='contratacao_pendente')
            OR (a.status='cancelada' AND NOT EXISTS
                (SELECT 1 FROM assinatura ax WHERE ax.id_empresa=a.id_empresa AND ax.status IN ('ativa','suspensa'))
                AND a.id_assinatura = (SELECT MAX(ay.id_assinatura) FROM assinatura ay WHERE ay.id_empresa=a.id_empresa)))
        ORDER BY a.id_assinatura DESC");
    if (!$stmt) throw new RuntimeException('Falha ao preparar assinatura.');
    $stmt->bind_param('i', $empresa);
    if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Falha ao consultar assinatura.'); }
    $res = $stmt->get_result(); $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    if (count($rows) > 1) out(['ok'=>false,'code'=>'CONTRACT_INCONSISTENT','user_msg'=>'Não foi possível determinar a assinatura vigente.'], 409);
    if (!$rows) return null;
    $r = $rows[0];
    // Duração da competência diária (1 ou 2 dias) pelo ciclo já persistido; só exibição. Outras periodicidades: null.
    $diasCiclo = null;
    if ((string)$r['periodicidade'] === 'diaria') {
        try {
            require_once __DIR__ . '/../../_servicos/assinatura.php';
            $diasCiclo = assinaturaServicoDiasCicloContrato($db, $empresa, (int)$r['id_assinatura'], 'diaria');
        } catch (Throwable $erro) {
            error_log('FATURAMENTO_DIAS_CICLO: ' . get_class($erro));
        }
    }
    return ['dias_ciclo'=>$diasCiclo,'id_assinatura'=>(int)$r['id_assinatura'],'plano'=>$r['plano'] === null ? null : (string)$r['plano'],
        'valor_contratado'=>faturamentoDecimal($r['valor_contratado']),'periodicidade'=>(string)$r['periodicidade'],
        'dia_vencimento'=>(int)$r['dia_vencimento'],'data_inicio'=>(string)$r['data_inicio'],
        'data_fim'=>$r['data_fim'] === null ? null : (string)$r['data_fim'],'status'=>(string)$r['status'],
        'modalidade'=>(string)$r['modalidade'],'motivo_suspensao'=>$r['motivo_suspensao'] === null ? null : (string)$r['motivo_suspensao'],
        'em_regularizacao'=>(string)$r['status']==='suspensa' && (string)$r['motivo_suspensao']==='teste_expirado'];
}

function faturamentoCobrancas(mysqli $db, int $empresa): void {
    // Paginação defensiva e ordenação por vencimento/ID tornam a leitura estável.
    $page = max(1, (int)($_GET['page'] ?? $_GET['pagina'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? $_GET['limite'] ?? 20)));
    $offset = ($page - 1) * $limit;
    // A empresa vem do contexto autenticado; nenhum parâmetro HTTP define o escopo.
    $where = ['c.id_empresa=?']; $vals = [$empresa]; $types = 'i';
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') { $where[] = '(p.nome LIKE ? OR c.status LIKE ?)'; $needle="%{$q}%"; $vals[]=$needle; $vals[]=$needle; $types.='ss'; }
    $situacao = strtolower(trim((string)($_GET['situacao'] ?? '')));
    if (in_array($situacao, ['pendente','vencida','paga','cancelada'], true)) {
        $where[] = $situacao === 'vencida' ? "c.status='pendente' AND c.data_vencimento < CURDATE()" : ($situacao === 'pendente' ? "c.status='pendente' AND c.data_vencimento >= CURDATE()" : 'c.status=?');
        if (in_array($situacao, ['paga','cancelada'], true)) { $vals[]=$situacao; $types.='s'; }
    }
    foreach ([['vencimento_de','>='],['vencimento_ate','<=']] as [$key,$op]) if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET[$key] ?? ''))) { $where[]="c.data_vencimento {$op} ?"; $vals[]=(string)$_GET[$key]; $types.='s'; }
    $from = " FROM cobranca c INNER JOIN assinatura a ON a.id_assinatura=c.id_assinatura LEFT JOIN plano p ON p.id_plano=a.id_plano LEFT JOIN (SELECT id_cobranca,SUM(CASE WHEN status='confirmado' THEN valor_pago ELSE 0 END) total_pago FROM pagamento GROUP BY id_cobranca) pg ON pg.id_cobranca=c.id_cobranca WHERE ".implode(' AND ',$where);
    $stmt=$db->prepare("SELECT COUNT(*) total {$from}"); if (!$stmt) throw new RuntimeException('Falha ao preparar total.'); faturamentoBind($stmt,$types,$vals); $stmt->execute(); $total=(int)$stmt->get_result()->fetch_assoc()['total']; $stmt->close();
    $sql="SELECT c.id_cobranca,c.id_assinatura,p.nome plano,c.periodo_inicio,c.periodo_fim,c.data_vencimento,c.valor,c.status,c.finalidade,COALESCE(pg.total_pago,0) total_pago,c.criado_em {$from} ORDER BY c.data_vencimento DESC,c.id_cobranca DESC LIMIT ? OFFSET ?";
    $stmt=$db->prepare($sql); if (!$stmt) throw new RuntimeException('Falha ao preparar cobranças.'); $vals[]=$limit; $vals[]=$offset; faturamentoBind($stmt,$types.'ii',$vals); $stmt->execute(); $res=$stmt->get_result(); $items=[]; $hoje=date('Y-m-d');
    while ($r=$res->fetch_assoc()) { $valor=faturamentoDecimal($r['valor']); $pago=faturamentoDecimal($r['total_pago']); // vencida é situação calculada, nunca persistida.
        $items[]=['id_cobranca'=>(int)$r['id_cobranca'],'id_assinatura'=>(int)$r['id_assinatura'],'plano'=>$r['plano']===null?null:(string)$r['plano'],'periodo_inicio'=>(string)$r['periodo_inicio'],'periodo_fim'=>(string)$r['periodo_fim'],'data_vencimento'=>(string)$r['data_vencimento'],'valor'=>$valor,'status'=>(string)$r['status'],'finalidade'=>(string)$r['finalidade'],'situacao'=>$r['status']==='pendente' && (string)$r['data_vencimento']<$hoje?'vencida':(string)$r['status'],'total_pago_confirmado'=>$pago,'saldo_restante'=>max(0,round($valor-$pago,2)),'criado_em'=>$r['criado_em']===null?null:(string)$r['criado_em']]; }
    $stmt->close();
    // Situação real do pagamento automático no cartão (recusa, nova tentativa, conferência) e o mesmo bloqueio de PIX do backend.
    require_once __DIR__ . '/../../_servicos/assinatura.php';
    $situacoes = assinaturaServicoPreapprovalSituacoesCobrancas($db, array_map(fn($i) => ['id_cobranca'=>$i['id_cobranca'],'id_empresa'=>$empresa,'id_assinatura'=>$i['id_assinatura'],'status'=>$i['status']], $items));
    foreach ($items as &$item) $item['pagamento_automatico'] = $situacoes[$item['id_cobranca']] ?? null;
    unset($item);
    out(['ok'=>true,'code'=>'COBRANCAS_LISTADAS','user_msg'=>'Cobranças listadas com sucesso.','data'=>['items'=>$items,'page'=>$page,'limit'=>$limit,'total'=>$total,'total_pages'=>$total===0?0:(int)ceil($total/$limit)]]);
}

function faturamentoPagamentos(mysqli $db, int $empresa): void {
    $page=max(1,(int)($_GET['page']??$_GET['pagina']??1)); $limit=min(100,max(1,(int)($_GET['limit']??$_GET['limite']??20))); $offset=($page-1)*$limit; $where=['c.id_empresa=?']; $vals=[$empresa]; $types='i';
    // O ID informado só é aceito junto ao vínculo da cobrança com a empresa autenticada.
    $idRaw=$_GET['id_cobranca']??null; if ($idRaw!==null && preg_match('/^[1-9]\d*$/',(string)$idRaw)) {$where[]='c.id_cobranca=?';$vals[]=(int)$idRaw;$types.='i';}
    $from=' FROM pagamento pg INNER JOIN cobranca c ON c.id_cobranca=pg.id_cobranca WHERE '.implode(' AND ',$where);
    $stmt=$db->prepare("SELECT COUNT(*) total {$from}"); if(!$stmt)throw new RuntimeException('Falha ao preparar total.'); faturamentoBind($stmt,$types,$vals);$stmt->execute();$total=(int)$stmt->get_result()->fetch_assoc()['total'];$stmt->close();
    $stmt=$db->prepare("SELECT pg.id_pagamento,pg.id_cobranca,pg.valor_pago,pg.forma_pagamento,pg.origem,pg.status,DATE_FORMAT(pg.data_pagamento,'%Y-%m-%d %H:%i:%s') data_pagamento {$from} ORDER BY pg.data_pagamento DESC,pg.id_pagamento DESC LIMIT ? OFFSET ?"); if(!$stmt)throw new RuntimeException('Falha ao preparar pagamentos.');$vals[]=$limit;$vals[]=$offset;faturamentoBind($stmt,$types.'ii',$vals);$stmt->execute();$res=$stmt->get_result();$items=[];while($r=$res->fetch_assoc())$items[]=['id_pagamento'=>(int)$r['id_pagamento'],'id_cobranca'=>(int)$r['id_cobranca'],'valor_pago'=>faturamentoDecimal($r['valor_pago']),'forma_pagamento'=>(string)$r['forma_pagamento'],'origem'=>(string)$r['origem'],'status'=>(string)$r['status'],'data_pagamento'=>(string)$r['data_pagamento']];$stmt->close();out(['ok'=>true,'code'=>'PAGAMENTOS_LISTADOS','user_msg'=>'Pagamentos listados com sucesso.','data'=>['items'=>$items,'page'=>$page,'limit'=>$limit,'total'=>$total,'total_pages'=>$total===0?0:(int)ceil($total/$limit)]]);
}

/**
 * Valida o contrato para o cancelamento. Com $bloquear, deve rodar na transação do chamador (FOR UPDATE).
 *
 * @return array{erro: array{0: string, 1: string, 2: int}}|array{empresa: array<string, mixed>, assinatura: array<string, mixed>, dataFim: string, statusNovo: string}
 */
function faturamentoCancelamentoValidar(mysqli $db, int $empresa, string $modalidade, bool $bloquear): array {
    $trava = $bloquear ? ' FOR UPDATE' : '';
    $empresas = assinaturaServicoConsultaFinanceira($db,
        "SELECT id_empresa, nome FROM empresa WHERE id_empresa = ? AND status = 'ativo'{$trava}", 'i', $empresa);
    $assinaturas = assinaturaServicoConsultaFinanceira($db,
        "SELECT id_assinatura, id_plano, status, modalidade, data_fim FROM assinatura
          WHERE id_empresa = ? AND status IN ('ativa','suspensa') ORDER BY id_assinatura DESC{$trava}", 'i', $empresa);
    if ($empresas === [] || count($assinaturas) !== 1 || $assinaturas[0]['data_fim'] !== null) {
        return ['erro' => ['CONTRATO_INDISPONIVEL', 'A assinatura foi alterada. Atualize o faturamento.', 409]];
    }
    $assinatura = $assinaturas[0];
    if ($modalidade === 'fim_periodo_pago' && ((string)$assinatura['status'] !== 'ativa' || (string)$assinatura['modalidade'] !== 'paga')) {
        return ['erro' => ['SEM_PERIODO_PAGO', 'O cancelamento ao fim do período pago exige assinatura paga e ativa.', 409]];
    }
    $dataFim = $modalidade === 'fim_periodo_pago'
        ? assinaturaServicoFimPeriodoPago($db, $empresa, (int)$assinatura['id_assinatura']) : date('Y-m-d');
    if ($dataFim === null) {
        return ['erro' => ['SEM_PERIODO_PAGO', 'Não há período integralmente pago que inclua a data atual.', 409]];
    }

    return ['empresa' => $empresas[0], 'assinatura' => $assinatura, 'dataFim' => $dataFim,
        'statusNovo' => $modalidade === 'fim_periodo_pago' ? 'ativa' : 'cancelada'];
}

function faturamentoCancelarContrato(mysqli $db, array $contexto): void {
    require_once __DIR__ . '/../../_auth/csrf.php';
    require_once __DIR__ . '/../../_servicos/assinatura.php';
    require_once __DIR__ . '/../../_servicos/cancelamento_recorrencia.php';
    csrfValidarSessao();
    if (($contexto['perfil'] ?? '') !== 'proprietario' || ($contexto['super_admin_suporte'] ?? false)) {
        out(['ok'=>false,'code'=>'ACCESS_DENIED','user_msg'=>'Somente o proprietário pode cancelar a assinatura.'],403);
    }
    exigirPermissao($db, 'faturamento.cancelar', $contexto);
    $modalidade = is_scalar($_POST['modalidade_cancelamento'] ?? null) ? trim((string)$_POST['modalidade_cancelamento']) : '';
    $motivo = is_scalar($_POST['motivo'] ?? null) ? trim((string)$_POST['motivo']) : '';
    if (!in_array($modalidade, ['imediato','fim_periodo_pago'], true) || $motivo === '' || strlen($motivo) > 500) {
        out(['ok'=>false,'code'=>'VALIDATION_ERROR','user_msg'=>'Escolha a modalidade e informe um motivo de até 500 caracteres.'],422);
    }
    $empresa = (int)$contexto['id_empresa'];
    // Mesma trava por empresa da troca de cartão e do cancelamento do pagamento automático: nunca correm juntas.
    if (!cancelamentoRecorrenciaObterTrava($db, $empresa)) {
        out(['ok'=>false,'code'=>'OPERACAO_CARTAO_EM_ANDAMENTO','user_msg'=>'Já existe uma operação no pagamento automático em andamento. Aguarde a conclusão.'],409);
    }
    try {
        [$payload, $http] = faturamentoCancelarContratoExecutar($db, $empresa, $modalidade, $motivo);
    } finally {
        cancelamentoRecorrenciaLiberarTrava($db, $empresa);
    }
    out($payload, $http);
}

/** @return array{0: array<string, mixed>, 1: int} corpo e status HTTP */
function faturamentoCancelarContratoExecutar(mysqli $db, int $empresa, string $modalidade, string $motivo): array {
    try {
        // 1) Validação sem efeitos: a recorrência externa não é tocada se o cancelamento comercial já é inviável.
        $previa = faturamentoCancelamentoValidar($db, $empresa, $modalidade, false);
        if (isset($previa['erro'])) {
            return [['ok'=>false,'code'=>$previa['erro'][0],'user_msg'=>$previa['erro'][1]], $previa['erro'][2]];
        }
        $idAssinatura = (int)$previa['assinatura']['id_assinatura'];

        // 2) Recorrência externa viva, pendente de cancelamento ou incerta bloqueia o contrato. Nada é enviado ao
        //    Mercado Pago aqui: o cancelamento da Preapproval é iniciado só pelo botão "Cancelar pagamento automático".
        $externo = cancelamentoRecorrenciaVerificarBloqueio(cancelamentoRecorrenciaPortasReais($db, $empresa, $idAssinatura));
        if (!$externo['ok']) {
            return [['ok'=>false,'code'=>$externo['code'],'user_msg'=>$externo['msg'],'data'=>['bloqueio_recorrencia'=>true]], $externo['http']];
        }

        // 3) Cancelamento comercial.
        if (!$db->begin_transaction()) throw new RuntimeException('Falha ao iniciar cancelamento.');
        $valida = faturamentoCancelamentoValidar($db, $empresa, $modalidade, true);
        if (isset($valida['erro'])) {
            $db->rollback();
            return [['ok'=>false,'code'=>$valida['erro'][0],'user_msg'=>$valida['erro'][1]], $valida['erro'][2]];
        }
        $assinatura = $valida['assinatura'];
        $idAssinatura = (int)$assinatura['id_assinatura'];
        $statusAnterior = (string)$assinatura['status'];
        $statusNovo = $valida['statusNovo'];
        $dataFim = $valida['dataFim'];
        $stmt = $db->prepare('UPDATE assinatura SET status = ?, data_fim = ? WHERE id_empresa = ? AND id_assinatura = ? AND status = ? AND data_fim IS NULL');
        if (!$stmt) throw new RuntimeException('Falha ao preparar cancelamento.');
        $stmt->bind_param('ssiis', $statusNovo, $dataFim, $empresa, $idAssinatura, $statusAnterior);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('Falha ao atualizar contrato.');
        }
        $stmt->close();
        $stmt = $db->prepare("UPDATE assinatura_pagamento_recorrente
            SET status = 'cancelamento_pendente', slot_ativo = NULL, cobrar_a_partir_de = NULL
            WHERE id_empresa = ? AND id_assinatura = ? AND motor_recorrencia = 'orders'
              AND status IN ('pendente','ativa','suspensa','atualizacao_necessaria')");
        if (!$stmt) throw new RuntimeException('Falha ao preparar bloqueio da recorrência.');
        $stmt->bind_param('ii', $empresa, $idAssinatura);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Falha ao bloquear recorrência.');
        }
        $stmt->close();
        // Preapproval: já encerrada (ou inexistente). Se uma recorrência viva surgiu entre as etapas, nada é concluído.
        if (!cancelamentoRecorrenciaFinalizarLocal($db, $empresa, $idAssinatura)) {
            $db->rollback();
            return [['ok'=>false,'code'=>'CANCELAR_PAGAMENTO_AUTOMATICO_PRIMEIRO','user_msg'=>CANCELAMENTO_RECORRENCIA_MSG_PRIMEIRO,'data'=>['bloqueio_recorrencia'=>true]], 409];
        }
        auditoriaRegistrar($db, $modalidade === 'fim_periodo_pago' ? 'assinatura.cancelamento_programado' : 'assinatura.cancelada', [
            'entidade_id' => $idAssinatura,
            'entidade_rotulo' => 'Assinatura da empresa ' . (string)$valida['empresa']['nome'],
            'descricao' => $modalidade === 'fim_periodo_pago' ? 'Proprietário programou o cancelamento da assinatura.' : 'Proprietário cancelou imediatamente a assinatura.',
            'alteracoes' => [
                'status' => ['antes'=>$statusAnterior,'depois'=>$statusNovo],
                'data_fim' => ['antes'=>null,'depois'=>$dataFim],
            ],
            'contexto' => ['origem'=>'painel_administrativo','id_empresa'=>$empresa,
                'id_assinatura'=>$idAssinatura,'id_plano'=>(int)$assinatura['id_plano'],
                'status_anterior'=>$statusAnterior,'status_novo'=>$statusNovo,'data_fim'=>$dataFim,
                'modalidade_cancelamento'=>$modalidade,'motivo'=>$motivo,
                'recorrencia_externa_cancelada'=>$externo['recorrencia']],
        ]);
        if (!$db->commit()) throw new RuntimeException('Falha ao confirmar cancelamento.');
        $sufixo = $externo['recorrencia'] ? ' Não haverá novas cobranças automáticas.' : '';

        return [['ok'=>true,'code'=>'CANCELAMENTO_REGISTRADO',
            'user_msg'=>($modalidade === 'fim_periodo_pago' ? 'Cancelamento programado.' : 'Sua assinatura foi cancelada com sucesso.') . $sufixo,
            'data'=>['id_assinatura'=>$idAssinatura,'status'=>$statusNovo,'data_fim'=>$dataFim,
                'modalidade_cancelamento'=>$modalidade,'recorrencia_cancelada'=>$externo['recorrencia']]], 200];
    } catch (Throwable $erro) {
        try { $db->rollback(); } catch (Throwable) {}
        error_log('FATURAMENTO_CANCELAMENTO_EXCEPTION: ' . $erro->getMessage());
        $concorrencia = in_array((int)$erro->getCode(), [1205,1213], true);
        return [['ok'=>false,'code'=>$concorrencia ? 'FINANCEIRO_EM_ATUALIZACAO' : 'CANCELAMENTO_ERROR',
            'user_msg'=>$concorrencia ? 'O financeiro está em atualização. Tente novamente.' : 'Não foi possível cancelar a assinatura.'],
            $concorrencia ? 409 : 500];
    }
}

/** Carrega o gateway e a biblioteca do webhook (sem executar o receptor público). */
function faturamentoCarregarGateway(): MercadoPagoGateway {
    require_once __DIR__ . '/../../_gateways/mercado_pago.php';
    if (!defined('MP_WEBHOOK_BIBLIOTECA')) define('MP_WEBHOOK_BIBLIOTECA', true);
    if (MP_WEBHOOK_BIBLIOTECA !== true) throw new RuntimeException('Processamento indisponível.');
    require_once __DIR__ . '/../../webhooks/webhook_mercado_pago.php';
    return MercadoPagoGateway::daConfiguracao();
}

/**
 * Antes de concluir a contratação: atualiza pelo provedor os PIX ainda abertos da empresa (mesma rotina da conciliação
 * periódica). Um PIX expirado no Mercado Pago deixa de bloquear a ativação; um PIX pago é registrado. Fora de transação;
 * falha nunca libera nada (a conclusão refaz todas as verificações sob lock).
 */
function faturamentoConciliarPixAbertos(mysqli $db, int $empresa): void {
    $stmt = $db->prepare("SELECT DISTINCT id_cobranca FROM transacao_pagamento
        WHERE id_empresa = ? AND tipo_operacao <> 'assinatura_preapproval' AND ordem_externa_id IS NOT NULL
          AND requer_conciliacao = 0 AND status IN ('aguardando','processando')");
    if (!$stmt) return;
    $stmt->bind_param('i', $empresa);
    $stmt->execute();
    $cobrancas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if ($cobrancas === []) return;
    try {
        $gateway = faturamentoCarregarGateway();
        foreach ($cobrancas as $cobranca) {
            mpWebhookConciliarOrdersCobranca($db, $gateway, $empresa, (int)$cobranca['id_cobranca']);
        }
    } catch (Throwable $erro) {
        if (function_exists('mpWebhookRollback')) mpWebhookRollback($db);
        error_log('FATURAMENTO_CONCILIACAO_PIX: ' . get_class($erro));
    }
}

/**
 * Antes de recontratar: processa agora, pelo mesmo outbox da rotina periódica, o cancelamento ainda pendente da
 * Preapproval de contratos anteriores (backoff, limite de tentativas, lock e conclusão só por GET autoritativo).
 * Fora de transação; provedor indisponível ou falha nunca libera nada: a contratação refaz o bloqueio sob lock.
 */
function faturamentoConciliarCancelamentosAnteriores(mysqli $db, int $empresa): void {
    try {
        $stmt = $db->prepare("SELECT v.id_assinatura_pagamento, v.id_empresa, v.ambiente, v.assinatura_externa_id,
                (SELECT MIN(o.id_operacao) FROM operacao_assinatura_gateway o
                  WHERE o.id_assinatura_pagamento = v.id_assinatura_pagamento AND o.id_empresa = v.id_empresa
                    AND o.tipo_operacao IN ('pausar','reativar','cancelar','atualizar_valor')
                    AND o.status_processamento IN ('pendente','processando','conciliacao')) AS id_operacao
            FROM assinatura_pagamento_recorrente v
            JOIN assinatura a ON a.id_assinatura = v.id_assinatura AND a.id_empresa = v.id_empresa
            WHERE v.id_empresa = ? AND v.motor_recorrencia = 'preapproval' AND v.assinatura_externa_id IS NOT NULL
              AND v.status IN ('pendente','ativa','atualizacao_necessaria','suspensa','cancelamento_pendente')
              AND a.status IN ('cancelada','encerrada')
            ORDER BY v.id_assinatura_pagamento LIMIT 3");
        if (!$stmt) return;
        $stmt->bind_param('i', $empresa);
        $stmt->execute();
        $vinculos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if ($vinculos === []) return;
        $gateway = faturamentoCarregarGateway();
        if (!MercadoPagoGateway::subscriptionsHabilitado()) return;
        foreach ($vinculos as $vinculo) {
            // Sem operação aberta, registra a intenção derivada do estado (nada é registrado após falha definitiva recente).
            $idOperacao = $vinculo['id_operacao'] ?? (assinaturaServicoPreapprovalGarantirOperacao(
                $db, (int)$vinculo['id_assinatura_pagamento'], null, $empresa)['id_operacao'] ?? null);
            if ($idOperacao !== null) mpSubOperacaoProcessar($db, $gateway, (int)$idOperacao, $empresa);
            // Intenção bloqueada por rejeição: sem novo PUT; só um GET que já mostre 'canceled' encerra o vínculo.
            else mpSubConfirmarCancelamentoExterno($db, $gateway, $vinculo, $empresa);
        }
    } catch (Throwable $erro) {
        if (function_exists('mpWebhookRollback')) mpWebhookRollback($db);
        error_log('FATURAMENTO_CANCELAMENTO_ANTERIOR: ' . get_class($erro));
    }
}

/**
 * Nova contratação pelo Proprietário após cancelamento. A decisão contratual e financeira é do serviço de
 * assinatura, sob lock na mesma transação; o super admin em modo suporte não contrata pela empresa.
 */
function faturamentoNovaContratacao(mysqli $db, array $contexto): void {
    require_once __DIR__ . '/../../_auth/csrf.php';
    require_once __DIR__ . '/../../_servicos/assinatura.php';
    csrfValidarSessao();
    if (($contexto['perfil'] ?? '') !== 'proprietario' || ($contexto['super_admin_suporte'] ?? false)) {
        out(['ok'=>false,'code'=>'ACCESS_DENIED','user_msg'=>'Somente o proprietário pode contratar a assinatura.'],403);
    }
    exigirPermissao($db, 'faturamento.contratar', $contexto);
    $acao = is_scalar($_POST['acao'] ?? null) ? trim((string)$_POST['acao']) : '';
    $planoBruto = is_scalar($_POST['id_plano'] ?? null) ? trim((string)$_POST['id_plano']) : '';
    if (!in_array($acao, ['contratar','concluir'], true)
        || ($acao === 'contratar' && preg_match('/^[1-9]\d{0,9}$/', $planoBruto) !== 1)) {
        out(['ok'=>false,'code'=>'VALIDATION_ERROR','user_msg'=>'Escolha um plano válido para contratar.'],422);
    }
    $empresa = (int)$contexto['id_empresa'];
    if ($acao === 'concluir') {
        // Um PIX gerado e abandonado (ou já pago) só deixa de ser "pagamento aguardando confirmação" pelo provedor.
        faturamentoConciliarPixAbertos($db, $empresa);
    } else {
        // O cancelamento da Preapproval anterior não depende só do webhook nem da rotina periódica.
        faturamentoConciliarCancelamentosAnteriores($db, $empresa);
    }
    try {
        if (!$db->begin_transaction()) throw new RuntimeException('Falha ao iniciar a contratação.');
        $resultado = $acao === 'concluir'
            ? assinaturaServicoConcluirNovaContratacao($db, $empresa, null, ['origem'=>'painel_administrativo'])
            : assinaturaServicoSolicitarNovaContratacao($db, $empresa, (int)$planoBruto);
        if (!($resultado['ok'] ?? false)) {
            $db->rollback();
            out(['ok'=>false,'code'=>(string)$resultado['codigo'],'user_msg'=>(string)$resultado['mensagem']],409);
        }
        if (!$db->commit()) throw new RuntimeException('Falha ao confirmar a contratação.');
        $dados = array_intersect_key($resultado, array_flip(['resultado','id_assinatura','id_cobranca','valor','data_vencimento','pix_residual']));
        out(['ok'=>true,'code'=>($resultado['resultado'] ?? '') === 'ativada' ? 'CONTRATACAO_ATIVADA' : 'CONTRATACAO_AGUARDANDO_PAGAMENTO',
            'user_msg'=>(string)$resultado['mensagem'],'data'=>$dados]);
    } catch (Throwable $erro) {
        try { $db->rollback(); } catch (Throwable) {}
        error_log('FATURAMENTO_CONTRATACAO_EXCEPTION: ' . $erro->getMessage());
        $concorrencia = in_array((int)$erro->getCode(), [1205,1213], true);
        out(['ok'=>false,'code'=>$concorrencia ? 'FINANCEIRO_EM_ATUALIZACAO' : 'CONTRATACAO_ERROR',
            'user_msg'=>$concorrencia ? 'O financeiro está em atualização. Tente novamente.' : 'Não foi possível concluir a contratação.'],
            $concorrencia ? 409 : 500);
    }
}

/**
 * Troca de plano pelo Proprietário: solicitar (upgrade proporcional ou downgrade programado), concluir o upgrade
 * pago ou cancelar a troca pendente. Decisão e valores vêm do serviço de assinatura, sob lock.
 */
function faturamentoTrocaPlano(mysqli $db, array $contexto): void {
    require_once __DIR__ . '/../../_auth/csrf.php';
    require_once __DIR__ . '/../../_servicos/assinatura.php';
    csrfValidarSessao();
    if (($contexto['perfil'] ?? '') !== 'proprietario' || ($contexto['super_admin_suporte'] ?? false)) {
        out(['ok'=>false,'code'=>'ACCESS_DENIED','user_msg'=>'Somente o proprietário pode trocar o plano.'],403);
    }
    exigirPermissao($db, 'faturamento.trocar_plano', $contexto);
    $acao = is_scalar($_POST['acao'] ?? null) ? trim((string)$_POST['acao']) : '';
    $planoBruto = is_scalar($_POST['id_plano'] ?? null) ? trim((string)$_POST['id_plano']) : '';
    $valorBruto = is_scalar($_POST['valor_confirmado'] ?? null) ? trim((string)$_POST['valor_confirmado']) : '';
    if (!in_array($acao, ['solicitar','concluir','cancelar'], true)
        || ($acao === 'solicitar' && (preg_match('/^[1-9]\d{0,9}$/', $planoBruto) !== 1 || preg_match('/^(0|[1-9]\d{0,11})$/', $valorBruto) !== 1))) {
        out(['ok'=>false,'code'=>'VALIDATION_ERROR','user_msg'=>'Revise o plano e o valor apresentado.'],422);
    }
    $empresa = (int)$contexto['id_empresa'];
    try {
        if (!$db->begin_transaction()) throw new RuntimeException('Falha ao iniciar a troca de plano.');
        $resultado = match ($acao) {
            'solicitar' => assinaturaServicoSolicitarTrocaPlano($db, $empresa, (int)$planoBruto, (int)$valorBruto),
            'concluir' => assinaturaServicoConcluirUpgrade($db, $empresa, null, ['origem'=>'painel_administrativo']),
            'cancelar' => assinaturaServicoCancelarTrocaPlano($db, $empresa),
        };
        if (!($resultado['ok'] ?? false)) {
            $db->rollback();
            $erro = ['ok'=>false,'code'=>(string)$resultado['codigo'],'user_msg'=>(string)$resultado['mensagem']];
            if (isset($resultado['previa'])) $erro['data'] = ['previa'=>$resultado['previa']];
            out($erro,409);
        }
        if (!$db->commit()) throw new RuntimeException('Falha ao confirmar a troca de plano.');
        $dados = array_intersect_key($resultado, array_flip(['resultado','id_troca','id_cobranca','valor','data_efetivacao']));
        out(['ok'=>true,'code'=>'TROCA_PLANO_' . strtoupper((string)($resultado['resultado'] ?? 'ok')),'user_msg'=>(string)$resultado['mensagem'],'data'=>$dados]);
    } catch (Throwable $erro) {
        try { $db->rollback(); } catch (Throwable) {}
        error_log('FATURAMENTO_TROCA_PLANO_EXCEPTION: ' . $erro->getMessage());
        $concorrencia = in_array((int)$erro->getCode(), [1205,1213], true);
        out(['ok'=>false,'code'=>$concorrencia ? 'FINANCEIRO_EM_ATUALIZACAO' : 'TROCA_PLANO_ERROR',
            'user_msg'=>$concorrencia ? 'O financeiro está em atualização. Tente novamente.' : 'Não foi possível concluir a troca de plano.'],
            $concorrencia ? 409 : 500);
    }
}

/** Situação da troca de plano no resumo; falha nunca impede o restante do faturamento. */
function faturamentoSituacaoTroca(mysqli $db, int $empresa): ?array {
    try {
        require_once __DIR__ . '/../../_servicos/assinatura.php';
        return assinaturaServicoSituacaoTrocaPlano($db, $empresa);
    } catch (Throwable $erro) {
        error_log('FATURAMENTO_SITUACAO_TROCA_EXCEPTION: ' . $erro->getMessage());
        return ['disponivel'=>false,'codigo'=>'TROCA_INDISPONIVEL','mensagem'=>'Não foi possível consultar a troca de plano agora.','opcoes'=>[],'pendente'=>null];
    }
}

/** Prévia somente leitura do cancelamento ao fim do período pago; falha nunca impede o restante do faturamento. */
function faturamentoPreviaCancelamento(mysqli $db, int $empresa, ?array $assinatura): ?array {
    if ($assinatura === null || ($assinatura['data_fim'] ?? null) !== null || ($assinatura['status'] ?? '') !== 'ativa') return null;
    try {
        require_once __DIR__ . '/../../_servicos/assinatura.php';
        return assinaturaServicoPreviaCancelamento($db, $empresa, (int)$assinatura['id_assinatura']);
    } catch (Throwable $erro) {
        error_log('FATURAMENTO_PREVIA_CANCELAMENTO_EXCEPTION: ' . $erro->getMessage());
        return null;
    }
}

/** Situação da nova contratação no resumo; falha nunca impede o restante do faturamento. */
function faturamentoSituacaoContratacao(mysqli $db, int $empresa): ?array {
    try {
        require_once __DIR__ . '/../../_servicos/assinatura.php';
        return assinaturaServicoSituacaoNovaContratacao($db, $empresa);
    } catch (Throwable $erro) {
        error_log('FATURAMENTO_SITUACAO_CONTRATACAO_EXCEPTION: ' . $erro->getMessage());
        return ['disponivel'=>false,'pode_concluir'=>false,'codigo'=>'CONTRATACAO_INDISPONIVEL',
            'mensagem'=>'Não foi possível verificar a contratação agora. Tente novamente em instantes.',
            'planos'=>[],'pendente'=>null,'cartao_sincronizacao_pendente'=>false];
    }
}

try {
    if ($cancelarContrato) faturamentoCancelarContrato($conexao, $contexto);
    if ($novaContratacao) faturamentoNovaContratacao($conexao, $contexto);
    if ($trocaPlano) faturamentoTrocaPlano($conexao, $contexto);
    if ($rotaAtual === 'painel/faturamento/resumo') {
        $assinatura=faturamentoAssinatura($conexao,$idEmpresa); $receber=0.0;$vencido=0.0;$pago=0.0;$proximo=null;
        $stmt=$conexao->prepare("SELECT c.valor,c.status,c.data_vencimento,COALESCE(pg.total_pago,0) pago FROM cobranca c LEFT JOIN (SELECT id_cobranca,SUM(CASE WHEN status='confirmado' THEN valor_pago ELSE 0 END) total_pago FROM pagamento GROUP BY id_cobranca) pg ON pg.id_cobranca=c.id_cobranca WHERE c.id_empresa=? ORDER BY c.data_vencimento ASC,c.id_cobranca ASC"); if(!$stmt)throw new RuntimeException('Falha ao preparar resumo.');$stmt->bind_param('i',$idEmpresa);$stmt->execute();$res=$stmt->get_result();while($r=$res->fetch_assoc()){ $saldo=max(0,round((float)$r['valor']-(float)$r['pago'],2));$pago+=faturamentoDecimal($r['pago']);if($r['status']!=='cancelada')$receber+=$saldo;if($r['status']==='pendente'&&$r['data_vencimento']<date('Y-m-d'))$vencido+=$saldo;if($proximo===null&&$r['status']==='pendente'&&$r['data_vencimento']>=date('Y-m-d'))$proximo=(string)$r['data_vencimento'];}$stmt->close();out(['ok'=>true,'code'=>'FATURAMENTO_RESUMO','user_msg'=>'Resumo de faturamento consultado com sucesso.','data'=>['assinatura'=>$assinatura,'contratacao'=>faturamentoSituacaoContratacao($conexao,$idEmpresa),'cancelamento'=>faturamentoPreviaCancelamento($conexao,$idEmpresa,$assinatura),'troca'=>faturamentoSituacaoTroca($conexao,$idEmpresa),'indicadores'=>['total_a_receber'=>round($receber,2),'total_vencido'=>round($vencido,2),'total_pago_confirmado'=>round($pago,2),'proximo_vencimento'=>$proximo]]]);
    } elseif ($rotaAtual === 'painel/faturamento/cobrancas') faturamentoCobrancas($conexao,$idEmpresa);
    elseif ($rotaAtual === 'painel/faturamento/pagamentos') faturamentoPagamentos($conexao,$idEmpresa);
    else out(['ok'=>false,'code'=>'ROUTE_NOT_FOUND','user_msg'=>'Rota de faturamento inválida.'],404);
} catch (Throwable $e) { error_log('FATURAMENTO_READ_EXCEPTION: '.$e->getMessage()); out(['ok'=>false,'code'=>'FATURAMENTO_READ_ERROR','user_msg'=>'Erro ao consultar faturamento.'],500); }
