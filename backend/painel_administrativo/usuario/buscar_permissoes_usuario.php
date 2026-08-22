<?php
declare(strict_types=1);
require __DIR__.'/../../_config/conexao.php';
require_once __DIR__.'/../../_regras/permissoes_usuario.php';
$ctx=permissoesContexto($conexao); exigirPermissao($conexao,'usuarios.gerenciar_permissoes');
$alvo=filter_input(INPUT_GET,'id_usuario',FILTER_VALIDATE_INT) ?: 0;
if ($alvo<=0) out(['ok'=>false,'code'=>'INVALID_USER','user_msg'=>'Usuário inválido.'],422);
$alvoCtx=permissoesContexto($conexao,$alvo,(int)$ctx['id_empresa']);
if (!($alvoCtx['valido']??false)) out(['ok'=>false,'code'=>'USER_NOT_FOUND','user_msg'=>'Usuário não encontrado nesta empresa.'],404);
$stmt=$conexao->prepare('SELECT codigo_permissao,estado FROM usuario_permissao WHERE id_empresa=? AND id_usuario=?');
$empresa=(int)$ctx['id_empresa'];
$stmt->bind_param('ii',$empresa,$alvo); $stmt->execute(); $res=$stmt->get_result(); $ex=[]; while($r=$res->fetch_assoc())$ex[$r['codigo_permissao']]=$r['estado']; $stmt->close();
$itens=[]; foreach(permissoesCatalogo() as $codigo=>$regra){$padrao=(bool)($regra[$alvoCtx['perfil']]??false);$estado=$ex[$codigo]??'padrao';$itens[]=['codigo'=>$codigo,'grupo'=>$regra['grupo'],'rotulo'=>$regra['rotulo'],'padrao_permitido'=>$padrao,'estado'=>$estado,'efetivo'=>$estado==='permitido'||($estado==='padrao'&&$padrao)];}
out(['ok'=>true,'data'=>['id_usuario'=>$alvo,'perfil'=>$alvoCtx['perfil'],'pode_editar'=>$alvo!==(int)$ctx['id_usuario'],'permissoes'=>$itens]]);
