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
    return ['id_assinatura'=>(int)$r['id_assinatura'],'plano'=>$r['plano'] === null ? null : (string)$r['plano'],
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
    $stmt->close(); out(['ok'=>true,'code'=>'COBRANCAS_LISTADAS','user_msg'=>'Cobranças listadas com sucesso.','data'=>['items'=>$items,'page'=>$page,'limit'=>$limit,'total'=>$total,'total_pages'=>$total===0?0:(int)ceil($total/$limit)]]);
}

function faturamentoPagamentos(mysqli $db, int $empresa): void {
    $page=max(1,(int)($_GET['page']??$_GET['pagina']??1)); $limit=min(100,max(1,(int)($_GET['limit']??$_GET['limite']??20))); $offset=($page-1)*$limit; $where=['c.id_empresa=?']; $vals=[$empresa]; $types='i';
    // O ID informado só é aceito junto ao vínculo da cobrança com a empresa autenticada.
    $idRaw=$_GET['id_cobranca']??null; if ($idRaw!==null && preg_match('/^[1-9]\d*$/',(string)$idRaw)) {$where[]='c.id_cobranca=?';$vals[]=(int)$idRaw;$types.='i';}
    $from=' FROM pagamento pg INNER JOIN cobranca c ON c.id_cobranca=pg.id_cobranca WHERE '.implode(' AND ',$where);
    $stmt=$db->prepare("SELECT COUNT(*) total {$from}"); if(!$stmt)throw new RuntimeException('Falha ao preparar total.'); faturamentoBind($stmt,$types,$vals);$stmt->execute();$total=(int)$stmt->get_result()->fetch_assoc()['total'];$stmt->close();
    $stmt=$db->prepare("SELECT pg.id_pagamento,pg.id_cobranca,pg.valor_pago,pg.forma_pagamento,pg.origem,pg.status,DATE_FORMAT(pg.data_pagamento,'%Y-%m-%d %H:%i:%s') data_pagamento {$from} ORDER BY pg.data_pagamento DESC,pg.id_pagamento DESC LIMIT ? OFFSET ?"); if(!$stmt)throw new RuntimeException('Falha ao preparar pagamentos.');$vals[]=$limit;$vals[]=$offset;faturamentoBind($stmt,$types.'ii',$vals);$stmt->execute();$res=$stmt->get_result();$items=[];while($r=$res->fetch_assoc())$items[]=['id_pagamento'=>(int)$r['id_pagamento'],'id_cobranca'=>(int)$r['id_cobranca'],'valor_pago'=>faturamentoDecimal($r['valor_pago']),'forma_pagamento'=>(string)$r['forma_pagamento'],'origem'=>(string)$r['origem'],'status'=>(string)$r['status'],'data_pagamento'=>(string)$r['data_pagamento']];$stmt->close();out(['ok'=>true,'code'=>'PAGAMENTOS_LISTADOS','user_msg'=>'Pagamentos listados com sucesso.','data'=>['items'=>$items,'page'=>$page,'limit'=>$limit,'total'=>$total,'total_pages'=>$total===0?0:(int)ceil($total/$limit)]]);
}

function faturamentoCancelarContrato(mysqli $db, array $contexto): void {
    require_once __DIR__ . '/../../_auth/csrf.php';
    require_once __DIR__ . '/../../_servicos/assinatura.php';
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
    try {
        if (!$db->begin_transaction()) throw new RuntimeException('Falha ao iniciar cancelamento.');
        $empresas = assinaturaServicoConsultaFinanceira($db,
            "SELECT id_empresa, nome FROM empresa WHERE id_empresa = ? AND status = 'ativo' FOR UPDATE", 'i', $empresa);
        $assinaturas = assinaturaServicoConsultaFinanceira($db,
            "SELECT id_assinatura, id_plano, status, modalidade, data_fim FROM assinatura
              WHERE id_empresa = ? AND status IN ('ativa','suspensa') ORDER BY id_assinatura DESC FOR UPDATE", 'i', $empresa);
        if ($empresas === [] || count($assinaturas) !== 1 || $assinaturas[0]['data_fim'] !== null) {
            $db->rollback();
            out(['ok'=>false,'code'=>'CONTRATO_INDISPONIVEL','user_msg'=>'A assinatura foi alterada. Atualize o faturamento.'],409);
        }
        $assinatura = $assinaturas[0];
        $idAssinatura = (int)$assinatura['id_assinatura'];
        $statusAnterior = (string)$assinatura['status'];
        if ($modalidade === 'fim_periodo_pago' && ($statusAnterior !== 'ativa' || (string)$assinatura['modalidade'] !== 'paga')) {
            $db->rollback();
            out(['ok'=>false,'code'=>'SEM_PERIODO_PAGO','user_msg'=>'O cancelamento ao fim do período pago exige assinatura paga e ativa.'],409);
        }
        $dataFim = $modalidade === 'fim_periodo_pago'
            ? assinaturaServicoFimPeriodoPago($db, $empresa, $idAssinatura) : date('Y-m-d');
        if ($dataFim === null) {
            $db->rollback();
            out(['ok'=>false,'code'=>'SEM_PERIODO_PAGO','user_msg'=>'Não há período integralmente pago que inclua a data atual.'],409);
        }
        $statusNovo = $modalidade === 'fim_periodo_pago' ? 'ativa' : 'cancelada';
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
            WHERE id_empresa = ? AND id_assinatura = ?
              AND status IN ('pendente','ativa','suspensa','atualizacao_necessaria')");
        if (!$stmt) throw new RuntimeException('Falha ao preparar bloqueio da recorrência.');
        $stmt->bind_param('ii', $empresa, $idAssinatura);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Falha ao bloquear recorrência.');
        }
        $stmt->close();
        auditoriaRegistrar($db, $modalidade === 'fim_periodo_pago' ? 'assinatura.cancelamento_programado' : 'assinatura.cancelada', [
            'entidade_id' => $idAssinatura,
            'entidade_rotulo' => 'Assinatura da empresa ' . (string)$empresas[0]['nome'],
            'descricao' => $modalidade === 'fim_periodo_pago' ? 'Proprietário programou o cancelamento da assinatura.' : 'Proprietário cancelou imediatamente a assinatura.',
            'alteracoes' => [
                'status' => ['antes'=>$statusAnterior,'depois'=>$statusNovo],
                'data_fim' => ['antes'=>null,'depois'=>$dataFim],
            ],
            'contexto' => ['origem'=>'painel_administrativo','id_empresa'=>$empresa,
                'id_assinatura'=>$idAssinatura,'id_plano'=>(int)$assinatura['id_plano'],
                'status_anterior'=>$statusAnterior,'status_novo'=>$statusNovo,'data_fim'=>$dataFim,
                'modalidade_cancelamento'=>$modalidade,'motivo'=>$motivo],
        ]);
        if (!$db->commit()) throw new RuntimeException('Falha ao confirmar cancelamento.');
        out(['ok'=>true,'code'=>'CANCELAMENTO_REGISTRADO',
            'user_msg'=>$modalidade === 'fim_periodo_pago' ? 'Cancelamento programado.' : 'Assinatura cancelada.',
            'data'=>['id_assinatura'=>$idAssinatura,'status'=>$statusNovo,'data_fim'=>$dataFim,
                'modalidade_cancelamento'=>$modalidade]]);
    } catch (Throwable $erro) {
        try { $db->rollback(); } catch (Throwable) {}
        error_log('FATURAMENTO_CANCELAMENTO_EXCEPTION: ' . $erro->getMessage());
        $concorrencia = in_array((int)$erro->getCode(), [1205,1213], true);
        out(['ok'=>false,'code'=>$concorrencia ? 'FINANCEIRO_EM_ATUALIZACAO' : 'CANCELAMENTO_ERROR',
            'user_msg'=>$concorrencia ? 'O financeiro está em atualização. Tente novamente.' : 'Não foi possível cancelar a assinatura.'],
            $concorrencia ? 409 : 500);
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
        $dados = array_intersect_key($resultado, array_flip(['resultado','id_assinatura','id_cobranca','valor','data_vencimento']));
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
        $stmt=$conexao->prepare("SELECT c.valor,c.status,c.data_vencimento,COALESCE(pg.total_pago,0) pago FROM cobranca c LEFT JOIN (SELECT id_cobranca,SUM(CASE WHEN status='confirmado' THEN valor_pago ELSE 0 END) total_pago FROM pagamento GROUP BY id_cobranca) pg ON pg.id_cobranca=c.id_cobranca WHERE c.id_empresa=? ORDER BY c.data_vencimento ASC,c.id_cobranca ASC"); if(!$stmt)throw new RuntimeException('Falha ao preparar resumo.');$stmt->bind_param('i',$idEmpresa);$stmt->execute();$res=$stmt->get_result();while($r=$res->fetch_assoc()){ $saldo=max(0,round((float)$r['valor']-(float)$r['pago'],2));$pago+=faturamentoDecimal($r['pago']);if($r['status']!=='cancelada')$receber+=$saldo;if($r['status']==='pendente'&&$r['data_vencimento']<date('Y-m-d'))$vencido+=$saldo;if($proximo===null&&$r['status']==='pendente'&&$r['data_vencimento']>=date('Y-m-d'))$proximo=(string)$r['data_vencimento'];}$stmt->close();out(['ok'=>true,'code'=>'FATURAMENTO_RESUMO','user_msg'=>'Resumo de faturamento consultado com sucesso.','data'=>['assinatura'=>$assinatura,'contratacao'=>faturamentoSituacaoContratacao($conexao,$idEmpresa),'troca'=>faturamentoSituacaoTroca($conexao,$idEmpresa),'indicadores'=>['total_a_receber'=>round($receber,2),'total_vencido'=>round($vencido,2),'total_pago_confirmado'=>round($pago,2),'proximo_vencimento'=>$proximo]]]);
    } elseif ($rotaAtual === 'painel/faturamento/cobrancas') faturamentoCobrancas($conexao,$idEmpresa);
    elseif ($rotaAtual === 'painel/faturamento/pagamentos') faturamentoPagamentos($conexao,$idEmpresa);
    else out(['ok'=>false,'code'=>'ROUTE_NOT_FOUND','user_msg'=>'Rota de faturamento inválida.'],404);
} catch (Throwable $e) { error_log('FATURAMENTO_READ_EXCEPTION: '.$e->getMessage()); out(['ok'=>false,'code'=>'FATURAMENTO_READ_ERROR','user_msg'=>'Erro ao consultar faturamento.'],500); }
