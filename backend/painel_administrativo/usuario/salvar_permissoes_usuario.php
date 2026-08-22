<?php
declare(strict_types=1);
require __DIR__.'/../../_config/conexao.php'; require_once __DIR__.'/../../_regras/permissoes_usuario.php';
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
$conexao->begin_transaction(); try{$del=$conexao->prepare('DELETE FROM usuario_permissao WHERE id_empresa=? AND id_usuario=? AND codigo_permissao=?');$up=$conexao->prepare("INSERT INTO usuario_permissao(id_empresa,id_usuario,codigo_permissao,estado,alterado_por) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE estado=VALUES(estado),alterado_por=VALUES(alterado_por),atualizado_em=CURRENT_TIMESTAMP");foreach($dados as $codigo=>$estado){if($estado==='padrao'){$del->bind_param('iis',$empresa,$alvo,$codigo);$del->execute();}else{$up->bind_param('iissi',$empresa,$alvo,$codigo,$estado,$ator);$up->execute();}}$del->close();$up->close();$conexao->commit();}catch(Throwable $e){$conexao->rollback();throw $e;}
out(['ok'=>true,'code'=>'PERMISSIONS_SAVED','user_msg'=>'Permissões atualizadas com sucesso.']);
