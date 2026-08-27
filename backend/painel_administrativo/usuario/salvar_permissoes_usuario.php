<?php
declare(strict_types=1);
require __DIR__.'/../../_config/conexao.php'; require_once __DIR__.'/../../_regras/permissoes_usuario.php'; require_once __DIR__.'/../../_servicos/auditoria.php';
$ctx=permissoesContexto($conexao); exigirPermissao($conexao,'usuarios.gerenciar_permissoes');
$alvo=filter_input(INPUT_POST,'id_usuario',FILTER_VALIDATE_INT) ?: 0;
if($alvo<=0||$alvo===(int)$ctx['id_usuario']) out(['ok'=>false,'code'=>'SELF_PERMISSION_CHANGE_DENIED','user_msg'=>'Você não pode alterar as próprias permissões.'],403);
$alvoCtx=permissoesContexto($conexao,$alvo,(int)$ctx['id_empresa']); if(!($alvoCtx['valido']??false)) out(['ok'=>false,'code'=>'USER_NOT_FOUND','user_msg'=>'Usuário não encontrado nesta empresa.'],404);
$empresa=(int)$ctx['id_empresa']; $ator=(int)$ctx['id_usuario'];
$raw=$_POST['permissoes']??''; $dados=is_array($raw)?$raw:json_decode((string)$raw,true); if(!is_array($dados)) out(['ok'=>false,'code'=>'INVALID_PERMISSIONS','user_msg'=>'Permissões inválidas.'],422);
$catalogo=permissoesCatalogo(); foreach($dados as $codigo=>$estado){if(!isset($catalogo[$codigo])||!in_array($estado,['padrao','permitido','bloqueado'],true))out(['ok'=>false,'code'=>'UNKNOWN_PERMISSION','user_msg'=>'Foi enviada uma permissão inválida.'],422);}
if($alvoCtx['perfil']==='proprietario'){
  $stmt=$conexao->prepare("SELECT COUNT(*) FROM empresa_usuario eu INNER JOIN perfil pf ON pf.id_perfil=eu.id_perfil INNER JOIN usuario u ON u.id_usuario=eu.id_usuario WHERE eu.id_empresa=? AND eu.status='ativo' AND u.status='ativo' AND LOWER(pf.nome) IN ('proprietario','proprietário')");$stmt->bind_param('i',$empresa);$stmt->execute();$stmt->bind_result($qtd);$stmt->fetch();$stmt->close();
  if((int)$qtd<=1&&(($dados['painel.acessar']??'padrao')==='bloqueado'||($dados['usuarios.gerenciar_permissoes']??'padrao')==='bloqueado'))out(['ok'=>false,'code'=>'LAST_OWNER_PROTECTION','user_msg'=>'Não é permitido bloquear o acesso administrativo do último proprietário ativo.'],409);
}
$conexao->begin_transaction(); try{
  // Captura apenas as exceções das permissões enviadas e mantém o isolamento pela empresa ativa.
  $anteriores=[];$sel=$conexao->prepare('SELECT codigo_permissao,estado FROM usuario_permissao WHERE id_empresa=? AND id_usuario=?');$sel->bind_param('ii',$empresa,$alvo);$sel->execute();$res=$sel->get_result();while($row=$res->fetch_assoc())$anteriores[(string)$row['codigo_permissao']]=(string)$row['estado'];$sel->close();
  $del=$conexao->prepare('DELETE FROM usuario_permissao WHERE id_empresa=? AND id_usuario=? AND codigo_permissao=?');$up=$conexao->prepare("INSERT INTO usuario_permissao(id_empresa,id_usuario,codigo_permissao,estado,alterado_por) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE estado=VALUES(estado),alterado_por=VALUES(alterado_por),atualizado_em=CURRENT_TIMESTAMP");foreach($dados as $codigo=>$estado){if($estado==='padrao'){$del->bind_param('iis',$empresa,$alvo,$codigo);$del->execute();}else{$up->bind_param('iissi',$empresa,$alvo,$codigo,$estado,$ator);$up->execute();}}$del->close();$up->close();
  // Um único evento representa o clique em Salvar e contém somente permissões efetivamente alteradas.
  $diferencas=[];foreach($dados as $codigo=>$estado){$antes=$anteriores[$codigo]??'padrao';if($antes!==$estado)$diferencas[$codigo]=['antes'=>$antes,'depois'=>$estado];}
  if($diferencas!==[]){$nomeAlvo='Usuário #'.$alvo;$stmt=$conexao->prepare('SELECT nome FROM usuario WHERE id_usuario=? LIMIT 1');$stmt->bind_param('i',$alvo);$stmt->execute();$stmt->bind_result($nomeBanco);if($stmt->fetch())$nomeAlvo=(string)$nomeBanco;$stmt->close();auditoriaRegistrar($conexao,'usuario.permissoes_alteradas',['entidade_id'=>$alvo,'entidade_rotulo'=>$nomeAlvo,'descricao'=>'Alterou as permissões de '.$nomeAlvo.'.','alteracoes'=>['permissoes'=>['antes'=>array_intersect_key($anteriores,$diferencas),'depois'=>array_intersect_key($dados,$diferencas)]],'contexto'=>['quantidade_afetada'=>count($diferencas),'origem'=>'painel_administrativo']]);}
  // A alteração e sua trilha são confirmadas juntas; falha da auditoria cai no rollback abaixo.
  $conexao->commit();
}catch(Throwable $e){$conexao->rollback();error_log('[salvar_permissoes_usuario] '.$e->getMessage());throw $e;}
out(['ok'=>true,'code'=>'PERMISSIONS_SAVED','user_msg'=>'Permissões atualizadas com sucesso.']);
