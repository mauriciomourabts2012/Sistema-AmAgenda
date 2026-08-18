<?php
declare(strict_types=1);

/* Lista os agendamentos reais da empresa, agrupados pelo dia da semana. */
if (!function_exists('out')) { function out(array $p,int $c=200):void { http_response_code($c); echo json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; } }
try {
    if (session_status()!==PHP_SESSION_ACTIVE) session_start();
    $auth=$_SESSION['auth']??[]; $idUsuario=(int)($auth['id_usuario']??0);
    $idEmpresa=(int)($auth['id_empresa']??$_SESSION['empresa_id']??$_SESSION['id_empresa']??$_SESSION['empresa']['id_empresa']??0);
    if ($idUsuario<=0) out(['ok'=>false,'code'=>'NOT_AUTHENTICATED','user_msg'=>'Sessão expirada. Faça login novamente.'],401);
    if ($idEmpresa<=0) out(['ok'=>false,'code'=>'SESSION_WITHOUT_COMPANY','user_msg'=>'Empresa da sessão não identificada.'],403);
    require __DIR__.'/../_config/conexao.php'; $conexao->set_charset('utf8mb4');
    $stmt=$conexao->prepare("SELECT pf.nome,p.id_profissional FROM empresa_usuario eu INNER JOIN perfil pf ON pf.id_perfil=eu.id_perfil LEFT JOIN profissional p ON p.id_usuario=eu.id_usuario WHERE eu.id_empresa=? AND eu.id_usuario=? AND eu.status='ativo' LIMIT 1");
    $stmt->bind_param('ii',$idEmpresa,$idUsuario); $stmt->execute(); $stmt->bind_result($perfil,$profSessao); $vinculo=$stmt->fetch(); $stmt->close();
    if (!$vinculo) out(['ok'=>false,'code'=>'COMPANY_ACCESS_DENIED','user_msg'=>'Acesso à empresa não autorizado.'],403);
    $somentePro=in_array(mb_strtolower((string)$perfil),['profissional','profissionais'],true);
    $sql="SELECT a.id_agendamento AS id,a.id_cliente,a.id_profissional,a.id_servico,a.data_agendamento AS data,DATE_FORMAT(a.hora_inicio,'%H:%i') AS hora,c.nome_completo AS cliente,c.whatsapp_celular AS telefone,u.nome AS profissional,s.nome AS servico,CONCAT(a.duracao_min_aplicada,'min') AS duracao,a.status,a.observacao AS obs,0 AS pagamento_confirmado FROM agendamento a INNER JOIN cliente c ON c.id_cliente=a.id_cliente INNER JOIN profissional p ON p.id_profissional=a.id_profissional INNER JOIN usuario u ON u.id_usuario=p.id_usuario INNER JOIN servico s ON s.id_servico=a.id_servico WHERE a.id_empresa=?";
    if ($somentePro) $sql.=" AND a.id_profissional=?";
    $sql.=" ORDER BY a.data_agendamento ASC,a.hora_inicio ASC LIMIT 1000";
    $stmt=$conexao->prepare($sql);
    if ($somentePro) { $pro=(int)$profSessao; $stmt->bind_param('ii',$idEmpresa,$pro); } else $stmt->bind_param('i',$idEmpresa);
    $stmt->execute(); $res=$stmt->get_result();
    $dias=['segunda'=>[],'terca'=>[],'quarta'=>[],'quinta'=>[],'sexta'=>[],'sabado'=>[],'domingo'=>[]];
    $map=[1=>'segunda',2=>'terca',3=>'quarta',4=>'quinta',5=>'sexta',6=>'sabado',7=>'domingo'];
    while($r=$res->fetch_assoc()) { $r['pagamento_confirmado']=(bool)$r['pagamento_confirmado']; $dias[$map[(int)(new DateTimeImmutable($r['data']))->format('N')]][]=$r; }
    $stmt->close(); out(['ok'=>true,'data'=>$dias]);
} catch(Throwable $e) { error_log('[lista_agendamento] '.$e->getMessage()); out(['ok'=>false,'code'=>'INTERNAL_ERROR','user_msg'=>'Não foi possível listar os agendamentos.'],500); }
