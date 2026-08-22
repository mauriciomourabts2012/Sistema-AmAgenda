<?php
declare(strict_types=1);

/** Catálogo único: códigos ausentes são negados por segurança. */
function permissoesCatalogo(): array
{
    return [
        'painel.acessar' => ['grupo'=>'painel','rotulo'=>'Entrar no Painel Administrativo','proprietario'=>true,'profissional'=>false,'recepcionista'=>false],
        'painel.visualizar_resumo' => ['grupo'=>'painel','rotulo'=>'Visualizar a aba Resumo do dia','proprietario'=>true,'profissional'=>false,'recepcionista'=>false],
        'agenda.visualizar' => ['grupo'=>'agenda','rotulo'=>'Visualizar agenda','proprietario'=>true,'profissional'=>true,'recepcionista'=>true],
        'agenda.visualizar_todos_profissionais' => ['grupo'=>'agenda','rotulo'=>'Visualizar agenda de todos os profissionais','proprietario'=>true,'profissional'=>false,'recepcionista'=>true],
        'agenda.criar_agendamento' => ['grupo'=>'agenda','rotulo'=>'Criar agendamento','proprietario'=>true,'profissional'=>true,'recepcionista'=>true],
        'agenda.criar_agendamento_outro_profissional' => ['grupo'=>'agenda','rotulo'=>'Criar agendamento para outro profissional','proprietario'=>true,'profissional'=>false,'recepcionista'=>true],
        'agenda.editar_agendamento' => ['grupo'=>'agenda','rotulo'=>'Editar agendamento','proprietario'=>true,'profissional'=>true,'recepcionista'=>true],
        'agenda.cancelar_agendamento' => ['grupo'=>'agenda','rotulo'=>'Cancelar agendamento','proprietario'=>true,'profissional'=>true,'recepcionista'=>true],
        'agenda.excluir_agendamento' => ['grupo'=>'agenda','rotulo'=>'Excluir agendamento','proprietario'=>true,'profissional'=>true,'recepcionista'=>true],
        'clientes.visualizar' => ['grupo'=>'clientes','rotulo'=>'Visualizar clientes','proprietario'=>true,'profissional'=>false,'recepcionista'=>false],
        'clientes.cadastrar' => ['grupo'=>'clientes','rotulo'=>'Cadastrar cliente','proprietario'=>true,'profissional'=>false,'recepcionista'=>false],
        'clientes.editar' => ['grupo'=>'clientes','rotulo'=>'Editar cliente','proprietario'=>true,'profissional'=>false,'recepcionista'=>false],
        'clientes.alterar_status' => ['grupo'=>'clientes','rotulo'=>'Alterar status do cliente','proprietario'=>true,'profissional'=>false,'recepcionista'=>false],
        'servicos.visualizar' => ['grupo'=>'servicos','rotulo'=>'Visualizar serviços','proprietario'=>true,'profissional'=>true,'recepcionista'=>true],
        'servicos.cadastrar' => ['grupo'=>'servicos','rotulo'=>'Cadastrar serviço','proprietario'=>true,'profissional'=>true,'recepcionista'=>false],
        'servicos.excluir' => ['grupo'=>'servicos','rotulo'=>'Excluir serviço','proprietario'=>true,'profissional'=>true,'recepcionista'=>false],
        'usuarios.visualizar' => ['grupo'=>'usuarios','rotulo'=>'Visualizar usuários','proprietario'=>true,'profissional'=>false,'recepcionista'=>false],
        'usuarios.cadastrar' => ['grupo'=>'usuarios','rotulo'=>'Cadastrar usuário','proprietario'=>true,'profissional'=>false,'recepcionista'=>false],
        'usuarios.editar' => ['grupo'=>'usuarios','rotulo'=>'Editar usuário','proprietario'=>true,'profissional'=>false,'recepcionista'=>false],
        'usuarios.alterar_status' => ['grupo'=>'usuarios','rotulo'=>'Alterar status do usuário','proprietario'=>true,'profissional'=>false,'recepcionista'=>false],
        'usuarios.gerenciar_permissoes' => ['grupo'=>'usuarios','rotulo'=>'Gerenciar permissões','proprietario'=>true,'profissional'=>false,'recepcionista'=>false,'critica'=>'proprietario'],
        'empresa.visualizar_configuracoes' => ['grupo'=>'configuracoes','rotulo'=>'Visualizar configurações da empresa','proprietario'=>true,'profissional'=>false,'recepcionista'=>false],
        'empresa.editar_configuracoes' => ['grupo'=>'configuracoes','rotulo'=>'Editar configurações da empresa','proprietario'=>true,'profissional'=>false,'recepcionista'=>false],
        'empresa.editar_identidade_visual' => ['grupo'=>'configuracoes','rotulo'=>'Editar identidade visual','proprietario'=>true,'profissional'=>false,'recepcionista'=>false],
        'agenda_configuracao.visualizar' => ['grupo'=>'configuracoes','rotulo'=>'Visualizar configurações da agenda','proprietario'=>true,'profissional'=>true,'recepcionista'=>false],
        'agenda_configuracao.editar_propria' => ['grupo'=>'configuracoes','rotulo'=>'Configurar a própria agenda','proprietario'=>true,'profissional'=>true,'recepcionista'=>false],
        'agenda_configuracao.editar_todos_profissionais' => ['grupo'=>'configuracoes','rotulo'=>'Configurar agenda de todos os profissionais','proprietario'=>true,'profissional'=>false,'recepcionista'=>false],
    ];
}

function permissoesNormalizarPerfil(?string $perfil): string
{
    $v = mb_strtolower(trim((string)$perfil), 'UTF-8');
    return match ($v) {
        'proprietário', 'proprietario' => 'proprietario',
        'profissionais', 'profissional' => 'profissional',
        'recepção', 'recepcao', 'recepcionista' => 'recepcionista',
        default => $v,
    };
}

function permissoesContexto(mysqli $conexao, ?int $idUsuario = null, ?int $idEmpresa = null): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $auth = is_array($_SESSION['auth'] ?? null) ? $_SESSION['auth'] : [];
    $usuario = $idUsuario ?? (int)($auth['id_usuario'] ?? 0);
    $empresa = $idEmpresa ?? (int)($auth['id_empresa'] ?? $auth['empresa_id'] ?? $_SESSION['empresa_id'] ?? 0);
    $superSuporte = mb_strtolower((string)($auth['tipo_usuario'] ?? ''), 'UTF-8') === 'super_admin'
        && (bool)($auth['modo_suporte'] ?? false) && $empresa > 0;
    if ($usuario <= 0 || $empresa <= 0) return ['valido'=>false,'super_admin_suporte'=>$superSuporte];
    if ($superSuporte && $idUsuario === null) return ['valido'=>true,'id_usuario'=>$usuario,'id_empresa'=>$empresa,'perfil'=>'super_admin','super_admin_suporte'=>true,'id_profissional'=>0];

    $stmt = $conexao->prepare("SELECT pf.nome,p.id_profissional FROM empresa_usuario eu INNER JOIN empresa e ON e.id_empresa=eu.id_empresa AND e.status='ativo' INNER JOIN usuario u ON u.id_usuario=eu.id_usuario AND u.status='ativo' INNER JOIN perfil pf ON pf.id_perfil=eu.id_perfil AND pf.status='ativo' LEFT JOIN profissional p ON p.id_usuario=eu.id_usuario WHERE eu.id_empresa=? AND eu.id_usuario=? AND eu.status='ativo' LIMIT 1");
    if (!$stmt) throw new RuntimeException('Falha ao preparar contexto de autorização.');
    $stmt->bind_param('ii',$empresa,$usuario); $stmt->execute(); $stmt->bind_result($perfil,$idProfissional); $ok=$stmt->fetch(); $stmt->close();
    return $ok ? ['valido'=>true,'id_usuario'=>$usuario,'id_empresa'=>$empresa,'perfil'=>permissoesNormalizarPerfil($perfil),'super_admin_suporte'=>false,'id_profissional'=>(int)$idProfissional] : ['valido'=>false,'super_admin_suporte'=>false];
}

function usuarioTemPermissao(mysqli $conexao, string $codigo, array $contexto = []): bool
{
    $catalogo = permissoesCatalogo();
    if (!isset($catalogo[$codigo])) return false;
    $ctx = permissoesContexto($conexao, $contexto['id_usuario'] ?? null, $contexto['id_empresa'] ?? null);
    if (!($ctx['valido'] ?? false)) return false;
    if ($ctx['super_admin_suporte'] ?? false) return true;
    $regra = $catalogo[$codigo];
    if (($regra['critica'] ?? '') === 'proprietario' && $ctx['perfil'] !== 'proprietario') return false;

    $stmt=$conexao->prepare('SELECT estado FROM usuario_permissao WHERE id_empresa=? AND id_usuario=? AND codigo_permissao=? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Falha ao consultar permissão personalizada.');
    $empresa = (int)$ctx['id_empresa'];
    $usuario = (int)$ctx['id_usuario'];
    $stmt->bind_param('iis',$empresa,$usuario,$codigo); $stmt->execute(); $stmt->bind_result($estado); $tem=$stmt->fetch(); $stmt->close();
    if ($tem) return $estado === 'permitido';
    return (bool)($regra[$ctx['perfil']] ?? false);
}

function exigirPermissao(mysqli $conexao, string $codigo, array $contexto = []): void
{
    if (usuarioTemPermissao($conexao,$codigo,$contexto)) return;
    out(['ok'=>false,'code'=>'PERMISSION_DENIED','user_msg'=>'Você não possui permissão para realizar esta ação.','data'=>['permissao'=>$codigo]],403);
}

function obterPermissoesEfetivas(mysqli $conexao, ?int $idUsuario = null, ?int $idEmpresa = null): array
{
    $resultado=[];
    foreach (permissoesCatalogo() as $codigo=>$regra) $resultado[$codigo]=usuarioTemPermissao($conexao,$codigo,['id_usuario'=>$idUsuario,'id_empresa'=>$idEmpresa]);
    return $resultado;
}
