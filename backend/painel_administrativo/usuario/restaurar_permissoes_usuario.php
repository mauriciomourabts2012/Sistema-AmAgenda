<?php
declare(strict_types=1);
require __DIR__.'/../../_config/conexao.php'; require_once __DIR__.'/../../_regras/permissoes_usuario.php'; require_once __DIR__.'/../../_servicos/auditoria.php';
$ctx=permissoesContexto($conexao); exigirPermissao($conexao,'usuarios.gerenciar_permissoes');
$alvo=filter_input(INPUT_POST,'id_usuario',FILTER_VALIDATE_INT) ?: 0;
if($alvo<=0||$alvo===(int)$ctx['id_usuario']) out(['ok'=>false,'code'=>'SELF_PERMISSION_CHANGE_DENIED','user_msg'=>'Você não pode alterar as próprias permissões.'],403);
$alvoCtx=permissoesContexto($conexao,$alvo,(int)$ctx['id_empresa']); if(!($alvoCtx['valido']??false)) out(['ok'=>false,'code'=>'USER_NOT_FOUND','user_msg'=>'Usuário não encontrado nesta empresa.'],404);
$empresa=(int)$ctx['id_empresa'];
$conexao->begin_transaction();try{
  // Snapshot das exceções que serão removidas; o perfil padrão completo não é copiado.
  $anteriores=[];$stmt=$conexao->prepare('SELECT codigo_permissao,estado FROM usuario_permissao WHERE id_empresa=? AND id_usuario=? FOR UPDATE');$stmt->bind_param('ii',$empresa,$alvo);$stmt->execute();$res=$stmt->get_result();while($row=$res->fetch_assoc())$anteriores[(string)$row['codigo_permissao']]=(string)$row['estado'];$stmt->close();
  $stmt=$conexao->prepare('DELETE FROM usuario_permissao WHERE id_empresa=? AND id_usuario=?');$stmt->bind_param('ii',$empresa,$alvo);$stmt->execute();$stmt->close();
  if($anteriores!==[]){$depois=array_fill_keys(array_keys($anteriores),'padrao');$nomeAlvo='Usuário #'.$alvo;$stmt=$conexao->prepare('SELECT nome FROM usuario WHERE id_usuario=? LIMIT 1');$stmt->bind_param('i',$alvo);$stmt->execute();$stmt->bind_result($nomeBanco);if($stmt->fetch())$nomeAlvo=(string)$nomeBanco;$stmt->close();auditoriaRegistrar($conexao,'usuario.permissoes_restauradas',['entidade_id'=>$alvo,'entidade_rotulo'=>$nomeAlvo,'descricao'=>'Restaurou as permissões padrão de '.$nomeAlvo.'.','alteracoes'=>['permissoes'=>['antes'=>$anteriores,'depois'=>$depois]],'contexto'=>['quantidade_afetada'=>count($anteriores),'origem'=>'painel_administrativo']]);}
  $conexao->commit();
}catch(Throwable $e){$conexao->rollback();error_log('[restaurar_permissoes_usuario] '.$e->getMessage());throw $e;}
out(['ok'=>true,'code'=>'PERMISSIONS_RESTORED','user_msg'=>'Padrão do perfil restaurado com sucesso.']);
