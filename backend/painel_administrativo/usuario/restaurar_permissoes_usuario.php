<?php
declare(strict_types=1);
require __DIR__.'/../../_config/conexao.php'; require_once __DIR__.'/../../_regras/permissoes_usuario.php';
$ctx=permissoesContexto($conexao); exigirPermissao($conexao,'usuarios.gerenciar_permissoes');
$alvo=filter_input(INPUT_POST,'id_usuario',FILTER_VALIDATE_INT) ?: 0;
if($alvo<=0||$alvo===(int)$ctx['id_usuario']) out(['ok'=>false,'code'=>'SELF_PERMISSION_CHANGE_DENIED','user_msg'=>'Você não pode alterar as próprias permissões.'],403);
$alvoCtx=permissoesContexto($conexao,$alvo,(int)$ctx['id_empresa']); if(!($alvoCtx['valido']??false)) out(['ok'=>false,'code'=>'USER_NOT_FOUND','user_msg'=>'Usuário não encontrado nesta empresa.'],404);
$empresa=(int)$ctx['id_empresa'];
$conexao->begin_transaction();try{$stmt=$conexao->prepare('DELETE FROM usuario_permissao WHERE id_empresa=? AND id_usuario=?');$stmt->bind_param('ii',$empresa,$alvo);$stmt->execute();$stmt->close();$conexao->commit();}catch(Throwable $e){$conexao->rollback();throw $e;}
out(['ok'=>true,'code'=>'PERMISSIONS_RESTORED','user_msg'=>'Padrão do perfil restaurado com sucesso.']);
