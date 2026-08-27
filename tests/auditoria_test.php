<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/_servicos/auditoria.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (in_array('--api-child', $argv, true)) {
    $indice = array_search('--api-child', $argv, true);
    $papel = (string)($argv[$indice + 1] ?? 'proprietario');
    $excecao = (string)($argv[$indice + 2] ?? '');
    $cenario = (string)($argv[$indice + 3] ?? 'lista');
    require __DIR__ . '/../backend/_config/conexao.php';
    $conexao->begin_transaction();
    register_shutdown_function(static function () use ($conexao): void {
        try { $conexao->rollback(); } catch (Throwable) {}
    });

    $empresas = [];
    $res = $conexao->query("SELECT id_empresa FROM empresa WHERE status='ativo' ORDER BY id_empresa LIMIT 2");
    while ($linha = $res->fetch_assoc()) $empresas[] = (int)$linha['id_empresa'];
    if (count($empresas) < 2) throw new RuntimeException('Os testes da API exigem duas empresas ativas.');
    [$empresaA, $empresaB] = $empresas;

    if ($papel === 'super_admin') {
        $usuario = $conexao->query("SELECT id_usuario,nome FROM usuario WHERE tipo_usuario='super_admin' AND status='ativo' ORDER BY id_usuario LIMIT 1")?->fetch_assoc() ?: [];
        $idUsuario = (int)($usuario['id_usuario'] ?? 0); $nome = (string)($usuario['nome'] ?? ''); $perfil = 'super_admin';
    } else {
        $nomes = ['proprietario'=>'proprietário','profissional'=>'profissional','recepcionista'=>'recepção'];
        $perfilBanco = $nomes[$papel] ?? '';
        $stmt = $conexao->prepare("SELECT u.id_usuario,u.nome FROM empresa_usuario eu INNER JOIN usuario u ON u.id_usuario=eu.id_usuario AND u.status='ativo' INNER JOIN perfil p ON p.id_perfil=eu.id_perfil AND p.status='ativo' WHERE eu.id_empresa=? AND eu.status='ativo' AND LOWER(p.nome)=? ORDER BY u.id_usuario LIMIT 1");
        $stmt->bind_param('is', $empresaA, $perfilBanco); $stmt->execute(); $usuario=$stmt->get_result()->fetch_assoc() ?: []; $stmt->close();
        $idUsuario=(int)($usuario['id_usuario']??0); $nome=(string)($usuario['nome']??''); $perfil=$papel;
    }
    if ($idUsuario <= 0) throw new RuntimeException('Usuário de teste não encontrado para o perfil ' . $papel);

    if ($excecao !== '') {
        $stmt=$conexao->prepare("INSERT INTO usuario_permissao(id_empresa,id_usuario,codigo_permissao,estado,alterado_por) VALUES(?,?,'auditoria.visualizar',?,?) ON DUPLICATE KEY UPDATE estado=VALUES(estado),alterado_por=VALUES(alterado_por)");
        $stmt->bind_param('iisi',$empresaA,$idUsuario,$excecao,$idUsuario); $stmt->execute(); $stmt->close();
    }

    $stmt=$conexao->prepare("INSERT INTO auditoria(id_empresa,ator_tipo,id_ator,ator_nome,ator_perfil,modo_suporte,evento_codigo,modulo,entidade_tipo,entidade_id,entidade_rotulo,descricao,alteracoes,contexto,request_id,ocorrido_em) VALUES(?,?,?,?,?,?,'cliente.editado','clientes','cliente',?,?,?,JSON_OBJECT('nome',JSON_OBJECT('antes','A','depois','B')),JSON_OBJECT('origem','teste'),'00000000-0000-4000-8000-000000000001',?)");
    $quantidade=$cenario==='paginacao'?27:2; $ids=[];
    for($i=0;$i<$quantidade;$i++){
        $tipo=$papel==='super_admin'?'super_admin':'usuario'; $entidade=9000+$i;
        $rotulo=$i===0?'<script>alert(1)</script>':'Cliente Teste '.$i; $descricao='Descrição teste '.$i;
        $ocorrido=$cenario==='paginacao'?'2026-08-27 12:00:00.123456':'2026-08-27 12:'.str_pad((string)$i,2,'0',STR_PAD_LEFT).':00.123456';
        $modoSuporteEvento=$papel==='super_admin'?1:0;
        $stmt->bind_param('isissiisss',$empresaA,$tipo,$idUsuario,$nome,$perfil,$modoSuporteEvento,$entidade,$rotulo,$descricao,$ocorrido);
        if(!$stmt->execute()) throw new RuntimeException('Falha ao inserir evento temporário: '.$stmt->error);
        $ids[]=(int)$conexao->insert_id;
    }
    $stmt->close();
    $stmt=$conexao->prepare("INSERT INTO auditoria(id_empresa,ator_tipo,id_ator,ator_nome,ator_perfil,modo_suporte,evento_codigo,modulo,entidade_tipo,entidade_id,entidade_rotulo,descricao,request_id,ocorrido_em) VALUES(?,'sistema',NULL,'Sistema','sistema',0,'empresa.configuracoes_alteradas','configuracoes','empresa',?,'Empresa B','SEGREDO_EMPRESA_B','00000000-0000-4000-8000-000000000002','2026-08-27 12:30:00.000000')");
    $stmt->bind_param('ii',$empresaB,$empresaB); if(!$stmt->execute())throw new RuntimeException('Falha ao inserir evento isolado: '.$stmt->error); $idOutraEmpresa=(int)$conexao->insert_id; $stmt->close();

    $_SESSION['auth']=['id_usuario'=>$idUsuario,'empresa_id'=>$cenario==='sem_empresa'?0:$empresaA,'tipo_usuario'=>$papel==='super_admin'?'super_admin':'usuario','modo_suporte'=>$papel==='super_admin'&&$cenario!=='super_fora','status'=>'ativo'];
    $_SERVER['REQUEST_METHOD']='GET';
    // Isola os eventos temporários dos registros reais que possam existir no ambiente.
    $_GET=['path'=>'painel/auditoria/listar','inicio'=>'2026-08-01','fim'=>'2026-08-31','q'=>'Teste'];
    if($cenario==='idor')$_GET['id_auditoria']=(string)$idOutraEmpresa;
    if($cenario==='empresa_maliciosa')$_GET['id_empresa']=(string)$empresaB;
    if($cenario==='data_invalida')$_GET['inicio']='2026-99-99';
    if($cenario==='periodo_grande'){$_GET['inicio']='2026-01-01';$_GET['fim']='2026-08-31';}
    if($cenario==='modulo_invalido')$_GET['modulo']='agenda;DROP TABLE auditoria';
    if($cenario==='evento_invalido')$_GET['evento']='evento.inexistente';
    if($cenario==='entidade_invalida')$_GET['entidade']='cliente;DELETE';
    if($cenario==='ator_invalido')$_GET['ator_id']='1 OR 1=1';
    if($cenario==='limite_invalido')$_GET['limite']='100000';
    if($cenario==='cursor_invalido')$_GET['cursor']='%%%';
    if($cenario==='busca_longa')$_GET['q']=str_repeat('x',101);
    if($cenario==='injection')$_GET['q']="%' OR 1=1 --";
    if($cenario==='filtros'){$_GET+=['modulo'=>'clientes','evento'=>'cliente.editado','entidade'=>'cliente','ator_id'=>(string)$idUsuario];$_GET['q']='Cliente Teste';}
    if($cenario==='paginacao')$_GET['limite']='25';
    if($cenario==='cursor_valido')$_GET['cursor']=rtrim(strtr(base64_encode(json_encode(['ocorrido_em'=>'2026-08-27 12:01:00.123456','id'=>$ids[1]])),'+/','-_'),'=');
    require __DIR__ . '/../public/api/api_central.php';
}

$total = 0;
$falhas = [];

function teste(string $nome, callable $fn): void
{
    global $total, $falhas;
    $total++;
    try {
        $fn();
        echo "OK  {$nome}\n";
    } catch (Throwable $e) {
        $falhas[] = $nome . ': ' . $e->getMessage();
        echo "FALHA  {$nome}: {$e->getMessage()}\n";
    }
}

function afirmar(bool $condicao, string $mensagem = 'Afirmação falhou.'): void
{
    if (!$condicao) throw new RuntimeException($mensagem);
}

function esperarExcecao(callable $fn): void
{
    try {
        $fn();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException('Era esperada uma exceção.');
}

teste('evento válido', fn() => afirmar(auditoriaObterEvento('cliente.editado')['modulo'] === 'clientes'));
teste('evento inexistente', fn() => esperarExcecao(fn() => auditoriaObterEvento('evento.inexistente')));
teste('campo permitido e desconhecido removido', function (): void {
    $r = auditoriaSanitizarAlteracoes('cliente.editado', [
        'nome' => ['antes' => 'Ana', 'depois' => 'Ana Maria'],
        'interno' => ['antes' => 1, 'depois' => 2],
    ]);
    afirmar(isset($r['nome']) && !isset($r['interno']));
});
foreach (['senha', 'access_token', 'cookie_sessao'] as $campo) {
    teste("campo proibido {$campo}", fn() => esperarExcecao(fn() => auditoriaSanitizarAlteracoes('usuario.editado', [
        $campo => ['antes' => 'a', 'depois' => 'b'],
    ])));
}
teste('diferença vazia removida', fn() => afirmar(auditoriaSanitizarAlteracoes('cliente.editado', ['nome' => ['antes' => 'Ana', 'depois' => 'Ana']]) === []));
teste('relação normalizada', function (): void {
    $r = auditoriaSanitizarAlteracoes('agendamento.editado', [
        'profissional' => ['antes' => ['id' => 8, 'rotulo' => 'João'], 'depois' => ['id' => 13, 'rotulo' => 'Carlos']],
    ]);
    afirmar($r['profissional']['depois']['id'] === 13);
});
teste('texto grande limitado', fn() => afirmar(mb_strlen((string)auditoriaNormalizarValor(str_repeat('á', 1500)), 'UTF-8') === AUDITORIA_TEXTO_VALOR_MAX));
teste('caracteres especiais e JSON válido', function (): void {
    $r = auditoriaSanitizarAlteracoes('cliente.editado', ['nome' => ['antes' => 'João', 'depois' => 'João & "Filhos" <teste>']]);
    afirmar(is_string(auditoriaJson($r)) && json_decode((string)auditoriaJson($r), true, 512, JSON_THROW_ON_ERROR)['nome']['depois'] !== '');
});
teste('IPv4', fn() => afirmar(auditoriaNormalizarIp('127.0.0.1') === '127.0.0.1'));
teste('IPv6', fn() => afirmar(auditoriaNormalizarIp('2001:db8::1') === '2001:db8::1'));
teste('IP inválido', fn() => afirmar(auditoriaNormalizarIp('999.1.1.1') === null));
teste('request ID UUID v4', fn() => afirmar((bool)preg_match('/^[0-9a-f-]{36}$/', auditoriaRequestId())));
teste('ator empresarial válido', fn() => auditoriaValidarAtor(['ator_tipo'=>'usuario','id_ator'=>1,'ator_nome'=>'Teste','ator_perfil'=>'proprietario','id_empresa'=>1,'modo_suporte'=>false]));
teste('Super Admin em suporte válido', fn() => auditoriaValidarAtor(['ator_tipo'=>'super_admin','id_ator'=>1,'ator_nome'=>'Suporte','ator_perfil'=>'super_admin','id_empresa'=>1,'modo_suporte'=>true]));
teste('Super Admin fora do suporte', fn() => esperarExcecao(fn() => auditoriaValidarAtor(['ator_tipo'=>'super_admin','id_ator'=>1,'ator_nome'=>'Suporte','ator_perfil'=>'super_admin','id_empresa'=>1,'modo_suporte'=>false])));
teste('empresa ausente', fn() => esperarExcecao(fn() => auditoriaValidarAtor(['ator_tipo'=>'usuario','id_ator'=>1,'ator_nome'=>'Teste','ator_perfil'=>'proprietario','id_empresa'=>0,'modo_suporte'=>false])));

teste('permissões em lote preservam apenas diferenças', function (): void {
    $r=auditoriaSanitizarAlteracoes('usuario.permissoes_alteradas',['permissoes'=>['antes'=>['agenda.editar'=>'padrao'],'depois'=>['agenda.editar'=>'permitido','clientes.excluir'=>'bloqueado']]]);
    afirmar(isset($r['permissoes']) && count($r)===1);
});
teste('restauração registra exceções removidas', fn() => afirmar(isset(auditoriaSanitizarAlteracoes('usuario.permissoes_restauradas',['permissoes'=>['antes'=>['agenda.editar'=>'bloqueado'],'depois'=>['agenda.editar'=>'padrao']]])['permissoes'])));
teste('cadastro de usuário aceita somente campos administrativos', fn() => afirmar(count(auditoriaSanitizarAlteracoes('usuario.criado',['nome'=>['antes'=>null,'depois'=>'Teste'],'email'=>['antes'=>null,'depois'=>'teste@example.com'],'status_vinculo'=>['antes'=>null,'depois'=>'ativo']]))===3));
teste('edição sem diferença não gera alterações', fn() => afirmar(auditoriaSanitizarAlteracoes('usuario.editado',['nome'=>['antes'=>'Teste','depois'=>'Teste']])===[]));
teste('status distingue vínculo empresarial', fn() => afirmar(isset(auditoriaSanitizarAlteracoes('usuario.status_alterado',['status_vinculo'=>['antes'=>'ativo','depois'=>'inativo']])['status_vinculo'])));
teste('senha registra somente o fato', function (): void {
    $r=auditoriaSanitizarAlteracoes('perfil.senha_alterada',['senha_alterada'=>['antes'=>false,'depois'=>true]]); afirmar(array_keys($r)===['senha_alterada']);
    esperarExcecao(fn() => auditoriaSanitizarAlteracoes('perfil.senha_alterada',['senha'=>['antes'=>'a','depois'=>'b']]));
});
teste('configuração empresarial sanitizada', fn() => afirmar(count(auditoriaSanitizarAlteracoes('empresa.configuracoes_alteradas',['intervalo_padrao'=>['antes'=>30,'depois'=>45],'ddd_padrao'=>['antes'=>'11','depois'=>'61']]))===2));
teste('identidade visual não aceita conteúdo de arquivo', function (): void {
    $r=auditoriaSanitizarAlteracoes('empresa.identidade_visual_alterada',['logo'=>['antes'=>'padrao','depois'=>'substituida']]); afirmar(isset($r['logo']));
    esperarExcecao(fn() => auditoriaSanitizarAlteracoes('empresa.identidade_visual_alterada',['token_upload'=>['antes'=>null,'depois'=>'segredo']]));
});
teste('configuração profissional e restauração sanitizadas', function (): void {
    afirmar(isset(auditoriaSanitizarAlteracoes('agenda_profissional.configuracao_alterada',['horarios'=>['antes'=>[],'depois'=>['segunda'=>['disponivel'=>1]]]])['horarios']));
    afirmar(isset(auditoriaSanitizarAlteracoes('agenda_profissional.configuracao_restaurada',['antes'=>['antes'=>['horarios'=>7],'depois'=>[]]])['antes']));
});
teste('agendamento criado aceita relações com rótulos', fn() => afirmar(count(auditoriaSanitizarAlteracoes('agendamento.criado',['cliente'=>['antes'=>null,'depois'=>['id'=>1,'rotulo'=>'Cliente']],'profissional'=>['antes'=>null,'depois'=>['id'=>2,'rotulo'=>'Profissional']],'servico'=>['antes'=>null,'depois'=>['id'=>3,'rotulo'=>'Serviço']]]))===3));
teste('recorrência criada aceita ação agrupada', fn() => afirmar(isset(auditoriaSanitizarAlteracoes('agendamento.criado',['recorrencia'=>['antes'=>null,'depois'=>['grupo'=>'grupo-teste','quantidade'=>15,'data_fim'=>'2026-12-01']]])['recorrencia'])));
teste('agendamento sem diferença não gera alteração', fn() => afirmar(auditoriaSanitizarAlteracoes('agendamento.editado',['status'=>['antes'=>'pendente','depois'=>'pendente']])===[]));
teste('eventos semânticos de status aceitam somente mudança', function (): void {foreach(['agendamento.confirmado','agendamento.cancelado','agendamento.concluido'] as $evento)afirmar(isset(auditoriaSanitizarAlteracoes($evento,['status'=>['antes'=>'pendente','depois'=>explode('.',$evento)[1]]])['status']));});
teste('exclusão de agendamento preserva snapshot', fn() => afirmar(isset(auditoriaSanitizarAlteracoes('agendamento.excluido',['cliente'=>['antes'=>['id'=>1,'rotulo'=>'Cliente'],'depois'=>null],'recorrencia'=>['antes'=>['grupo'=>'g'],'depois'=>null]])['cliente'])));
teste('clientes operacionais usam campos mínimos', function (): void {afirmar(count(auditoriaSanitizarAlteracoes('cliente.criado',['nome'=>['antes'=>null,'depois'=>'Cliente'],'telefone'=>['antes'=>null,'depois'=>'61999999999'],'email'=>['antes'=>null,'depois'=>'c@example.com']]))===3);afirmar(isset(auditoriaSanitizarAlteracoes('cliente.status_alterado',['status'=>['antes'=>'ativo','depois'=>'inativo']])['status']));});
teste('serviço normal e rápido compartilham evento', fn() => afirmar(count(auditoriaSanitizarAlteracoes('servico.criado',['nome'=>['antes'=>null,'depois'=>'Corte'],'origem'=>['antes'=>null,'depois'=>'novo_agendamento']]))===2));
teste('serviço excluído preserva profissional', fn() => afirmar(isset(auditoriaSanitizarAlteracoes('servico.excluido',['profissional'=>['antes'=>['id'=>2,'rotulo'=>'Profissional'],'depois'=>null]])['profissional'])));
teste('fonte do status de cliente exige empresa em leitura e escrita', function (): void {$fonte=file_get_contents(__DIR__.'/../backend/painel_administrativo/cliente/alterar_status_cliente.php');afirmar(substr_count((string)$fonte,'id_empresa = ?')>=2,'SELECT e UPDATE precisam limitar id_empresa.');});

$comBanco = in_array('--com-banco', $argv, true);
if ($comBanco) {
    require __DIR__ . '/../backend/_config/conexao.php';
    teste('contexto empresarial resolvido pelo backend', function () use ($conexao): void {
        $sql = "SELECT u.id_usuario,eu.id_empresa FROM empresa_usuario eu INNER JOIN usuario u ON u.id_usuario=eu.id_usuario AND u.status='ativo' INNER JOIN empresa e ON e.id_empresa=eu.id_empresa AND e.status='ativo' INNER JOIN perfil p ON p.id_perfil=eu.id_perfil AND p.status='ativo' WHERE eu.status='ativo' AND u.tipo_usuario<>'super_admin' ORDER BY eu.id_empresa,eu.id_usuario LIMIT 1";
        $registro = $conexao->query($sql)?->fetch_assoc() ?: [];
        afirmar((int)($registro['id_usuario'] ?? 0) > 0, 'Não há usuário empresarial ativo para o teste.');
        $anterior = $_SESSION['auth'] ?? null;
        try {
            $_SESSION['auth'] = [
                'id_usuario' => (int)$registro['id_usuario'],
                'empresa_id' => (int)$registro['id_empresa'],
                'tipo_usuario' => 'usuario',
                'modo_suporte' => false,
            ];
            $ator = auditoriaResolverAtorSessao($conexao);
            afirmar($ator['ator_tipo'] === 'usuario' && $ator['id_empresa'] === (int)$registro['id_empresa']);
        } finally {
            if ($anterior === null) unset($_SESSION['auth']); else $_SESSION['auth'] = $anterior;
        }
    });
    teste('contexto real de Super Admin em suporte', function () use ($conexao): void {
        $super = $conexao->query("SELECT id_usuario FROM usuario WHERE tipo_usuario='super_admin' AND status='ativo' ORDER BY id_usuario LIMIT 1")?->fetch_assoc() ?: [];
        $empresa = $conexao->query("SELECT id_empresa FROM empresa WHERE status='ativo' ORDER BY id_empresa LIMIT 1")?->fetch_assoc() ?: [];
        afirmar((int)($super['id_usuario'] ?? 0) > 0 && (int)($empresa['id_empresa'] ?? 0) > 0, 'Falta Super Admin ou empresa ativa para o teste.');
        $anterior = $_SESSION['auth'] ?? null;
        try {
            $_SESSION['auth'] = [
                'id_usuario' => (int)$super['id_usuario'],
                'empresa_id' => (int)$empresa['id_empresa'],
                'tipo_usuario' => 'super_admin',
                'modo_suporte' => true,
            ];
            $ator = auditoriaResolverAtorSessao($conexao);
            afirmar($ator['ator_tipo'] === 'super_admin' && $ator['modo_suporte'] === true && $ator['ator_perfil'] === 'super_admin');
            $_SESSION['auth']['modo_suporte'] = false;
            esperarExcecao(fn() => auditoriaResolverAtorSessao($conexao));
            unset($_SESSION['auth']['empresa_id']);
            esperarExcecao(fn() => auditoriaResolverAtorSessao($conexao));
        } finally {
            if ($anterior === null) unset($_SESSION['auth']); else $_SESSION['auth'] = $anterior;
        }
    });
    teste('persistência participa de rollback', function () use ($conexao): void {
        $res = $conexao->query("SELECT id_empresa FROM empresa WHERE status='ativo' ORDER BY id_empresa LIMIT 1");
        $empresa = (int)($res?->fetch_assoc()['id_empresa'] ?? 0);
        afirmar($empresa > 0, 'Não há empresa ativa para o teste.');
        $ator = auditoriaResolverAtorSistema($conexao, $empresa, 'Teste isolado');
        $requestId = auditoriaGerarUuidV4();
        $_SERVER['HTTP_X_REQUEST_ID'] = $requestId;

        $conexao->begin_transaction();
        try {
            $id = auditoriaRegistrar($conexao, 'empresa.configuracoes_alteradas', [
                'ator' => $ator,
                'entidade_id' => $empresa,
                'entidade_rotulo' => 'Teste isolado',
                'descricao' => 'Evento temporário de teste com rollback.',
                'alteracoes' => ['intervalo_padrao' => ['antes' => 30, 'depois' => 45]],
                'ip' => '::1',
                'user_agent' => 'AmAgenda-Teste',
            ]);
            afirmar($id > 0);
            $stmt = $conexao->prepare('SELECT COUNT(*) FROM auditoria WHERE id_auditoria=?');
            $stmt->bind_param('i', $id); $stmt->execute(); $stmt->bind_result($durante); $stmt->fetch(); $stmt->close();
            afirmar((int)$durante === 1, 'Registro não ficou visível na transação.');
            $conexao->rollback();
            $stmt = $conexao->prepare('SELECT COUNT(*) FROM auditoria WHERE id_auditoria=?');
            $stmt->bind_param('i', $id); $stmt->execute(); $stmt->bind_result($depois); $stmt->fetch(); $stmt->close();
            afirmar((int)$depois === 0, 'Rollback não removeu o evento temporário.');
        } catch (Throwable $e) {
            try { $conexao->rollback(); } catch (Throwable) {}
            throw $e;
        }
    });
    teste('eventos críticos persistem com o contrato e são removidos no rollback', function () use ($conexao): void {
        $res=$conexao->query("SELECT id_empresa FROM empresa WHERE status='ativo' ORDER BY id_empresa LIMIT 1");$empresa=(int)($res?->fetch_assoc()['id_empresa']??0);afirmar($empresa>0);
        $ator=auditoriaResolverAtorSistema($conexao,$empresa,'Teste Fase 6');
        $casos=[
            ['usuario.permissoes_alteradas',['permissoes'=>['antes'=>['agenda.editar'=>'padrao'],'depois'=>['agenda.editar'=>'permitido']]]],
            ['usuario.criado',['nome'=>['antes'=>null,'depois'=>'Usuário temporário']]],
            ['usuario.editado',['perfil'=>['antes'=>'Profissional','depois'=>'Recepção']]],
            ['usuario.status_alterado',['status_vinculo'=>['antes'=>'ativo','depois'=>'inativo']]],
            ['usuario.senha_redefinida',['senha_alterada'=>['antes'=>false,'depois'=>true]]],
            ['empresa.configuracoes_alteradas',['intervalo_padrao'=>['antes'=>30,'depois'=>45]]],
            ['empresa.identidade_visual_alterada',['logo'=>['antes'=>'padrao','depois'=>'substituida']]],
            ['agenda_profissional.configuracao_alterada',['ddd_padrao'=>['antes'=>'11','depois'=>'61']]],
            ['perfil.senha_alterada',['senha_alterada'=>['antes'=>false,'depois'=>true]]],
        ];
        $ids=[];$conexao->begin_transaction();try{foreach($casos as [$evento,$alteracoes])$ids[]=auditoriaRegistrar($conexao,$evento,['ator'=>$ator,'entidade_id'=>$empresa,'entidade_rotulo'=>'Temporário Fase 6','alteracoes'=>$alteracoes,'contexto'=>['origem'=>'teste_fase_6']]);afirmar(count($ids)===count($casos));$conexao->rollback();$lista=implode(',',array_map('intval',$ids));$res=$conexao->query("SELECT COUNT(*) total FROM auditoria WHERE id_auditoria IN ({$lista})");afirmar((int)($res?->fetch_assoc()['total']??-1)===0,'Resíduos críticos permaneceram.');}catch(Throwable $e){try{$conexao->rollback();}catch(Throwable){}throw $e;}
    });
    teste('IDOR cliente Empresa A x Empresa B não encontra nem altera', function () use ($conexao): void {
        $empresas=[];$res=$conexao->query("SELECT id_empresa FROM empresa WHERE status='ativo' ORDER BY id_empresa LIMIT 2");while($row=$res->fetch_assoc())$empresas[]=(int)$row['id_empresa'];afirmar(count($empresas)===2);
        [$empresaA,$empresaB]=$empresas;$stmt=$conexao->prepare('SELECT id_cliente,status FROM cliente WHERE id_empresa=? ORDER BY id_cliente LIMIT 1');$stmt->bind_param('i',$empresaA);$stmt->execute();$cliente=$stmt->get_result()->fetch_assoc()?:[];$stmt->close();afirmar((int)($cliente['id_cliente']??0)>0,'Empresa A não possui cliente para o teste.');$id=(int)$cliente['id_cliente'];$status=(string)$cliente['status'];
        $stmt=$conexao->prepare('SELECT id_cliente FROM cliente WHERE id_cliente=? AND id_empresa=? LIMIT 1');$stmt->bind_param('ii',$id,$empresaB);$stmt->execute();$stmt->store_result();afirmar($stmt->num_rows===0,'Cliente da Empresa A vazou para a Empresa B.');$stmt->close();
        $novo=$status==='ativo'?'inativo':'ativo';$stmt=$conexao->prepare('UPDATE cliente SET status=? WHERE id_cliente=? AND id_empresa=? LIMIT 1');$stmt->bind_param('sii',$novo,$id,$empresaB);$stmt->execute();afirmar($stmt->affected_rows===0,'Empresa B alterou cliente da Empresa A.');$stmt->close();
        $stmt=$conexao->prepare('SELECT status FROM cliente WHERE id_cliente=? AND id_empresa=?');$stmt->bind_param('ii',$id,$empresaA);$stmt->execute();$stmt->bind_result($depois);$stmt->fetch();$stmt->close();afirmar($depois===$status,'Cliente da Empresa A foi modificado.');
        $stmt=$conexao->prepare("SELECT COUNT(*) FROM auditoria WHERE id_empresa=? AND evento_codigo='cliente.status_alterado' AND entidade_id=?");$stmt->bind_param('ii',$empresaB,$id);$stmt->execute();$stmt->bind_result($eventosB);$stmt->fetch();$stmt->close();afirmar((int)$eventosB===0,'Operação negada gerou evento na Empresa B.');
    });
}

if (in_array('--api', $argv, true)) {
    function executarApi(string $papel, string $excecao, string $cenario): array
    {
        $comando=[PHP_BINARY,__FILE__,'--api-child',$papel,$excecao,$cenario];
        $descritores=[1=>['pipe','w'],2=>['pipe','w']];
        $processo=proc_open($comando,$descritores,$pipes,__DIR__.'/..');
        if(!is_resource($processo))throw new RuntimeException('Não foi possível iniciar o teste da API.');
        $saida=stream_get_contents($pipes[1]);$erro=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$codigo=proc_close($processo);
        if($codigo!==0)throw new RuntimeException(trim($erro?:$saida));
        $json=json_decode($saida,true,512,JSON_THROW_ON_ERROR);
        return is_array($json)?$json:[];
    }

    $casos=[
        ['Proprietário permitido','proprietario','','lista',fn($j)=>($j['ok']??false)&&count($j['data']['items']??[])===2],
        ['Profissional bloqueado','profissional','','lista',fn($j)=>($j['code']??'')==='PERMISSION_DENIED'],
        ['Recepção bloqueada','recepcionista','','lista',fn($j)=>($j['code']??'')==='PERMISSION_DENIED'],
        ['Exceção Permitir','profissional','permitido','lista',fn($j)=>($j['ok']??false)===true],
        ['Exceção Bloquear','proprietario','bloqueado','lista',fn($j)=>($j['code']??'')==='PERMISSION_DENIED'],
        ['Super Admin fora do suporte','super_admin','','super_fora',fn($j)=>($j['code']??'')==='PERMISSION_DENIED'],
        ['Super Admin em suporte','super_admin','','lista',fn($j)=>($j['ok']??false)===true],
        ['IDOR entre empresas','proprietario','','idor',fn($j)=>($j['ok']??false)&&count($j['data']['items']??[])===0],
        ['Empresa do frontend ignorada','proprietario','','empresa_maliciosa',fn($j)=>($j['ok']??false)&&!str_contains(json_encode($j),'SEGREDO_EMPRESA_B')],
        ['Empresa ausente','proprietario','','sem_empresa',fn($j)=>!($j['ok']??false)],
        ['Data inválida','proprietario','','data_invalida',fn($j)=>($j['code']??'')==='INVALID_DATE_FILTER'],
        ['Período máximo','proprietario','','periodo_grande',fn($j)=>($j['code']??'')==='DATE_RANGE_TOO_LARGE'],
        ['Módulo inválido','proprietario','','modulo_invalido',fn($j)=>($j['code']??'')==='INVALID_MODULE'],
        ['Evento inválido','proprietario','','evento_invalido',fn($j)=>($j['code']??'')==='INVALID_EVENT'],
        ['Entidade inválida','proprietario','','entidade_invalida',fn($j)=>($j['code']??'')==='INVALID_ENTITY'],
        ['Ator inválido','proprietario','','ator_invalido',fn($j)=>($j['code']??'')==='INVALID_ACTOR'],
        ['Limite inválido','proprietario','','limite_invalido',fn($j)=>($j['code']??'')==='INVALID_LIMIT'],
        ['Cursor inválido','proprietario','','cursor_invalido',fn($j)=>($j['code']??'')==='INVALID_CURSOR'],
        ['Busca longa','proprietario','','busca_longa',fn($j)=>($j['code']??'')==='SEARCH_TOO_LONG'],
        ['SQL injection como dado','proprietario','','injection',fn($j)=>($j['ok']??false)&&count($j['data']['items']??[])===0],
        ['Filtros combinados','proprietario','','filtros',fn($j)=>($j['ok']??false)&&count($j['data']['items']??[])>=1],
        ['XSS permanece dado textual','proprietario','','lista',fn($j)=>($j['data']['items'][1]['entidade']['rotulo']??'')==='<script>alert(1)</script>'],
        ['Paginação determinística','proprietario','','paginacao',fn($j)=>($j['ok']??false)&&count($j['data']['items']??[])===25&&($j['meta']['tem_mais']??false)&&!empty($j['meta']['proximo_cursor'])],
        ['Cursor válido e fim da listagem','proprietario','','cursor_valido',fn($j)=>($j['ok']??false)&&count($j['data']['items']??[])===1&&!($j['meta']['tem_mais']??true)&&empty($j['meta']['proximo_cursor'])],
    ];
    foreach($casos as [$nome,$papel,$excecao,$cenario,$validar]){
        teste('API: '.$nome,fn()=>afirmar($validar(executarApi($papel,$excecao,$cenario))));
    }
}

echo "\n{$total} teste(s), " . count($falhas) . " falha(s).\n";
if ($falhas) exit(1);
