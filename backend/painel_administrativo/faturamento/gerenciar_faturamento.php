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

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
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
$rotaAtual = trim((string)($_GET['path'] ?? ''), "/ \t\n\r\0\x0B");

function faturamentoBind(mysqli_stmt $stmt, string $tipos, array &$valores): void {
    $args = [$tipos];
    foreach ($valores as &$valor) $args[] = &$valor;
    call_user_func_array([$stmt, 'bind_param'], $args);
}

function faturamentoDecimal(mixed $valor): float { return round((float)$valor, 2); }

function faturamentoAssinatura(mysqli $db, int $empresa): ?array {
    $stmt = $db->prepare("SELECT a.id_assinatura, a.id_plano, p.nome AS plano, a.valor_contratado,
        a.periodicidade, a.dia_vencimento, DATE_FORMAT(a.data_inicio,'%Y-%m-%d') AS data_inicio,
        DATE_FORMAT(a.data_fim,'%Y-%m-%d') AS data_fim, a.status
        FROM assinatura a LEFT JOIN plano p ON p.id_plano=a.id_plano
        WHERE a.id_empresa=? AND a.status='ativa' ORDER BY a.id_assinatura DESC");
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
        'data_fim'=>$r['data_fim'] === null ? null : (string)$r['data_fim'],'status'=>(string)$r['status']];
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
    $sql="SELECT c.id_cobranca,c.id_assinatura,p.nome plano,c.periodo_inicio,c.periodo_fim,c.data_vencimento,c.valor,c.status,COALESCE(pg.total_pago,0) total_pago,c.criado_em {$from} ORDER BY c.data_vencimento DESC,c.id_cobranca DESC LIMIT ? OFFSET ?";
    $stmt=$db->prepare($sql); if (!$stmt) throw new RuntimeException('Falha ao preparar cobranças.'); $vals[]=$limit; $vals[]=$offset; faturamentoBind($stmt,$types.'ii',$vals); $stmt->execute(); $res=$stmt->get_result(); $items=[]; $hoje=date('Y-m-d');
    while ($r=$res->fetch_assoc()) { $valor=faturamentoDecimal($r['valor']); $pago=faturamentoDecimal($r['total_pago']); // vencida é situação calculada, nunca persistida.
        $items[]=['id_cobranca'=>(int)$r['id_cobranca'],'id_assinatura'=>(int)$r['id_assinatura'],'plano'=>$r['plano']===null?null:(string)$r['plano'],'periodo_inicio'=>(string)$r['periodo_inicio'],'periodo_fim'=>(string)$r['periodo_fim'],'data_vencimento'=>(string)$r['data_vencimento'],'valor'=>$valor,'status'=>(string)$r['status'],'situacao'=>$r['status']==='pendente' && (string)$r['data_vencimento']<$hoje?'vencida':(string)$r['status'],'total_pago_confirmado'=>$pago,'saldo_restante'=>max(0,round($valor-$pago,2)),'criado_em'=>$r['criado_em']===null?null:(string)$r['criado_em']]; }
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

try {
    if ($rotaAtual === 'painel/faturamento/resumo') {
        $assinatura=faturamentoAssinatura($conexao,$idEmpresa); $receber=0.0;$vencido=0.0;$pago=0.0;$proximo=null;
        $stmt=$conexao->prepare("SELECT c.valor,c.status,c.data_vencimento,COALESCE(pg.total_pago,0) pago FROM cobranca c LEFT JOIN (SELECT id_cobranca,SUM(CASE WHEN status='confirmado' THEN valor_pago ELSE 0 END) total_pago FROM pagamento GROUP BY id_cobranca) pg ON pg.id_cobranca=c.id_cobranca WHERE c.id_empresa=? ORDER BY c.data_vencimento ASC,c.id_cobranca ASC"); if(!$stmt)throw new RuntimeException('Falha ao preparar resumo.');$stmt->bind_param('i',$idEmpresa);$stmt->execute();$res=$stmt->get_result();while($r=$res->fetch_assoc()){ $saldo=max(0,round((float)$r['valor']-(float)$r['pago'],2));$pago+=faturamentoDecimal($r['pago']);if($r['status']!=='cancelada')$receber+=$saldo;if($r['status']==='pendente'&&$r['data_vencimento']<date('Y-m-d'))$vencido+=$saldo;if($proximo===null&&$r['status']==='pendente'&&$r['data_vencimento']>=date('Y-m-d'))$proximo=(string)$r['data_vencimento'];}$stmt->close();out(['ok'=>true,'code'=>'FATURAMENTO_RESUMO','user_msg'=>'Resumo de faturamento consultado com sucesso.','data'=>['assinatura'=>$assinatura,'indicadores'=>['total_a_receber'=>round($receber,2),'total_vencido'=>round($vencido,2),'total_pago_confirmado'=>round($pago,2),'proximo_vencimento'=>$proximo]]]);
    } elseif ($rotaAtual === 'painel/faturamento/cobrancas') faturamentoCobrancas($conexao,$idEmpresa);
    elseif ($rotaAtual === 'painel/faturamento/pagamentos') faturamentoPagamentos($conexao,$idEmpresa);
    else out(['ok'=>false,'code'=>'ROUTE_NOT_FOUND','user_msg'=>'Rota de faturamento inválida.'],404);
} catch (Throwable $e) { error_log('FATURAMENTO_READ_EXCEPTION: '.$e->getMessage()); out(['ok'=>false,'code'=>'FATURAMENTO_READ_ERROR','user_msg'=>'Erro ao consultar faturamento.'],500); }
