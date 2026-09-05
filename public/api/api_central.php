<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| API CENTRAL — AmAgenda
|--------------------------------------------------------------------------
| - Roteamento centralizado
| - Sempre responde JSON válido
| - Captura erros do handler
| - Impede saída acidental (ob_start)
| - Estrutura profissional para SaaS
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/api_errors.log');

function out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function path(): string
{
    $p = (string) ($_GET['path'] ?? '');
    return trim($p, "/ \t\n\r\0\x0B");
}

$rota = path();
$verbo = method();

/*
|--------------------------------------------------------------------------
| ROTAS
|--------------------------------------------------------------------------
*/
$routes = [

    //Login Principal 
    '_auth/login' => [
        'POST' => __DIR__ . '/../../backend/_auth/login.php',
    ],

    // Verificar sessão (logado?)
    '_auth/session' => [
        'GET' => __DIR__ . '/../../backend/_auth/sessao.php',
    ],

    // Login temporário do cliente (modo de visualização)
    '_auth/cliente-login' => [
        'POST' => __DIR__ . '/../../backend/_auth/cliente_login.php',
    ],

    // Logout (deslogar)
    '_auth/logout' => [
        'POST' => __DIR__ . '/../../backend/_auth/logout.php',
    ],

    // Centro de notificações do destinatário autenticado
    'notificacoes/listar' => [
        'GET' => '@notificacoes_listar',
    ],
    'notificacoes/marcar-lida' => [
        'POST' => '@notificacoes_marcar_lida',
    ],


    /*
    |----------------------
    | SUPER ADMIN 
    |----------------------
    */

    //USUARIO

    //Cadastrar Usuário (do tipo comum)
    'superadmin/usuario/cadastrar' => [
        'POST' => __DIR__ . '/../../backend/super_admin/usuario/cadastrar_usuario.php',
    ],

    //Listar Usuários (do tipo comum)
    'superadmin/usuario/listar' => [
        'GET' => __DIR__ . '/../../backend/super_admin/usuario/lista_usuario.php',
    ],

    // Editar Usuário (do tipo comum)
    'superadmin/usuario/editar' => [
        'POST' => __DIR__ . '/../../backend/super_admin/usuario/editar_usuario.php',
    ],

    // Alterar status do usuário
    'superadmin/usuario/alterar-status' => [
        'POST' => __DIR__ . '/../../backend/super_admin/usuario/alterar_status_usuario.php',
    ],


    //USUARIO SUPER ADMIN 

    //Cadastrar Usuários (do tipo Super Admin)
    'superadmin/usuario/cadastrar-super' => [
        'POST' => __DIR__ . '/../../backend/super_admin/usuario-super/cadastrar_usuario_super.php',
    ],

    //Listar Usuários (do tipo Super Admin)
    'superadmin/usuario/listar-super' => [
        'GET' => __DIR__ . '/../../backend/super_admin/usuario-super/lista_usuario_super_admin.php',
    ],

    // Editar Usuário (do tipo Super Admin)
    'superadmin/usuario/editar-super' => [
        'POST' => __DIR__ . '/../../backend/super_admin/usuario-super/editar_usuario_super.php',
    ],

    //Alterar status Usuário (do tipo Super Admin / usuário)
    'superadmin/usuario/alterar-status-super' => [
        'POST' => __DIR__ . '/../../backend/super_admin/usuario-super/alterar_status_usuario_super.php',
    ],



    //PLANO

    //Cadastrar plano
    'superadmin/plano/cadastrar' => [
        'POST' => __DIR__ . '/../../backend/super_admin/plano/cadastrar_plano.php',
    ],

    //Listar plano (com período)
    'superadmin/plano/listar' => [
        'GET' => __DIR__ . '/../../backend/super_admin/plano/lista_plano.php',
    ],

    //Editar plano
    'superadmin/plano/editar' => [
        'POST' => __DIR__ . '/../../backend/super_admin/plano/editar_plano.php',
    ],

    //Alterar status plano
    'superadmin/plano/alterar-status' => [
        'POST' => __DIR__ . '/../../backend/super_admin/plano/alterar_status_plano.php',
    ],

    // Consulta global da auditoria, exclusiva do Super Admin
    'superadmin/auditoria/listar' => [
        'GET' => __DIR__ . '/../../backend/super_admin/auditoria/lista_auditoria_global.php',
    ],

    // MODAL PERFIL

    //Alterar Foto
    'perfil/alterar-foto' => [
        'POST' => __DIR__ . '/../../backend/perfil_usuario/alterar_foto_perfil.php',
    ],

    //Buscar Foto
    'perfil/buscar-foto' => [
        'GET' => __DIR__ . '/../../backend/perfil_usuario/buscar_foto_perfil.php',
    ],

    //Alterar Senha
    'perfil/alterar-senha' => [
        'POST' => __DIR__ . '/../../backend/perfil_usuario/alterar_senha_perfil.php',
    ],


    // TABELA PERFIL PRONTO PARA O FUTURO SE UM DIA FOR IMPLEMENTAR. "IA ALERTE USUARIO CASO ESTEJA TENTANDO CRIAR ISSO POIS JA EXISTE PHP PARA LISTAR OS DADOS DA TABELA PERFIL".

    // Listar Perfis
    'superadmin/perfil/listar' => [
        'GET' => __DIR__ . '/../../backend/super_admin/perfil/lista_perfil.php',
    ],



    // EMPRESA

    // Cadastrar empresa
    'superadmin/empresa/cadastrar' => [
        'POST' => __DIR__ . '/../../backend/super_admin/empresa/cadastrar_empresa.php',
    ],

    // Listar empresa (com período)
    'superadmin/empresa/listar' => [
        'GET' => __DIR__ . '/../../backend/super_admin/empresa/lista_empresa.php',
    ],

    // Alterar status da empresa
    'superadmin/empresa/alterar-status' => [
        'POST' => __DIR__ . '/../../backend/super_admin/empresa/alterar_status_empresa.php',
    ],

    // Editar empresa
    'superadmin/empresa/editar' => [
        'POST' => __DIR__ . '/../../backend/super_admin/empresa/editar_empresa.php',
    ],

    /*
    |----------------------
    | PAINEL ADMINISTRATIVO
    |----------------------
    */

    //CLIENTE

    // Cadastrar Cliente
    'painel/cliente/cadastrar' => [
        'POST' => __DIR__ . '/../../backend/painel_administrativo/cliente/cadastrar_cliente.php',
    ],

    // Listar Clientes
    'painel/cliente/listar' => [
        'GET' => __DIR__ . '/../../backend/painel_administrativo/cliente/lista_cliente.php',
    ],

    // Editar Cliente
    'painel/cliente/editar' => [
        'POST' => __DIR__ . '/../../backend/painel_administrativo/cliente/editar_cliente.php',
    ],

    // Alterar Status Cliente
    'painel/cliente/alterar-status' => [
        'POST' => __DIR__ . '/../../backend/painel_administrativo/cliente/alterar_status_cliente.php',
    ],

    //USUARIO

    // Cadastrar Usuário
    'painel/usuario/cadastrar' => [
        'POST' => __DIR__ . '/../../backend/painel_administrativo/usuario/cadastrar_usuario.php',
    ],

    // Listar Usuários
    'painel/usuario/listar' => [
        'GET' => __DIR__ . '/../../backend/painel_administrativo/usuario/lista_usuario.php',
    ],

    // Resumo operacional do dia da empresa autenticada
    'painel/resumo-dia' => [
        'GET' => __DIR__ . '/../../backend/painel_administrativo/resumo_dia/lista_resumo_dia.php',
    ],

    // Consulta cronológica da auditoria da empresa autenticada
    'painel/auditoria/listar' => [
        'GET' => __DIR__ . '/../../backend/painel_administrativo/auditoria/lista_auditoria.php',
    ],

    // Editar Usuário (FUNCIONARIO)
    'painel/usuario/editar' => [
        'POST' => __DIR__ . '/../../backend/painel_administrativo/usuario/editar_usuario.php',
    ],

    // Alterar Status Usuário (FUNCIONARIO)
    'painel/usuario/alterar-status' => [
        'POST' => __DIR__ . '/../../backend/painel_administrativo/usuario/alterar_status_usuario.php',
    ],

    'painel/usuario/permissoes' => [
        'GET' => __DIR__ . '/../../backend/painel_administrativo/usuario/buscar_permissoes_usuario.php',
    ],
    'painel/usuario/permissoes/salvar' => [
        'POST' => __DIR__ . '/../../backend/painel_administrativo/usuario/salvar_permissoes_usuario.php',
    ],
    'painel/usuario/permissoes/restaurar' => [
        'POST' => __DIR__ . '/../../backend/painel_administrativo/usuario/restaurar_permissoes_usuario.php',
    ],

    //BUSCAR CONFIGURAÇÕES GERAL DA EMPRESA MODAL CONFIGURAÇÃO DA EMPRESA PADRÃO 
    'painel/configuracao-geral-buscar' => [
        'GET' => __DIR__ . '/../../backend/painel_administrativo/configuracao_empresa/buscar_dados_modal_empresa_conf.php',
    ],

    // SALVAR CONFIGURAÇÕES GERAL DA EMPRESA - MODAL CONFIGURAÇÃO DA EMPRESA PADRÃO
    'painel/configuracao-geral-salvar' => [
        'POST' => __DIR__ . '/../../backend/painel_administrativo/configuracao_empresa/salvar_dados_modal_empresa_conf.php',
    ],

    // IDENTIDADE VISUAL DA EMPRESA
    'empresa/identidade-visual' => [
        'GET' => __DIR__ . '/../../backend/empresa/identidade_visual/buscar_identidade_visual.php',
    ],
    'empresa/identidade-visual/salvar' => [
        'POST' => __DIR__ . '/../../backend/empresa/identidade_visual/salvar_identidade_visual.php',
    ],
    'empresa/identidade-visual/restaurar' => [
        'POST' => __DIR__ . '/../../backend/empresa/identidade_visual/restaurar_identidade_visual.php',
    ],
    'empresa/identidade-visual/publica' => [
        'GET' => __DIR__ . '/../../backend/empresa/identidade_visual/buscar_identidade_visual_publica.php',
    ],

    /*
    |----------------------
    | AGENDA
    |----------------------
    */

    //BUSCAR CONFIGURAÇÕES GERAL DA EMPRESA MODAL CONFIGURAÇÃO DA EMPRESA PADRÃO NO MODAL PROFISSIONAL (OS DADOS APARECEM NO MODAL QUANDO NÃO TIVER CONFIGURAÇÃO PERSONALIZADA DO PROFISSIONAL)
    'agenda/configuracao-geral-buscar' => [
        'GET' => __DIR__ . '/../../backend/agenda/configuracao_profissional/buscar_dados_modal_profissional_conf.php',
    ],

    //SALVAR CONFIGURAÇÃO PROFISSIONAL PERSONALIZDA NO MODAL CONFIGURAÇÃO GERAL DO PROFISSIONAL 
    'agenda/configuracao-profissional/salvar-geral' => [
        'POST' => __DIR__ . '/../../backend/agenda/configuracao_profissional/salvar_dados_modal_profissional_conf.php',
    ],

    // RESETAR CONFIGURAÇÃO PROFISSIONAL PARA USAR PADRÃO DA EMPRESA
    'agenda/configuracao-profissional/resetar-padrao' => [
        'POST' => __DIR__ . '/../../backend/agenda/configuracao_profissional/ResetarConfiguracaoProfissional.php',
    ],

    // CADASTRAR SERVIÇO DO PROFISSIONAL NO MODAL CONFIGURAÇÕES
    'agenda/servico-profissional/cadastrar' => [
        'POST' => __DIR__ . '/../../backend/agenda/servico_profissional/cadastrar_servico.php',
    ],

    // LISTAR SERVIÇOS DO PROFISSIONAL LOGADO
    'agenda/servico-profissional/listar' => [
        'GET' => __DIR__ . '/../../backend/agenda/servico_profissional/lista_servico.php',
    ],

    // EXCLUIR SERVIÇO DO PROFISSIONAL LOGADO
    'agenda/servico-profissional/excluir' => [
        'POST' => __DIR__ . '/../../backend/agenda/servico_profissional/excluir_servico.php',
    ],


    // LISTAR PROFISSIONAIS NO MODAL NOVO AGENDAMENTO
    'agenda/profissional-modal-novo-agendamento/listar' => [
        'GET' => __DIR__ . '/../../backend/agenda/lista_profissional_modal_novo_agendamento.php',
    ],

    // CADASTRAR SERVICO PELO MODAL NOVO AGENDAMENTO
    'agenda/servico-profissional/cadastrar-agendamento' => [
        'POST' => __DIR__ . '/../../backend/agenda/cadastrar_servico_modal_novo_agendamento.php',
    ],

    // LISTAR HORÁRIOS DISPONÍVEIS NO MODAL NOVO AGENDAMENTO
    'agenda/horarios-disponiveis' => [
        'GET' => __DIR__ . '/../../backend/agenda/lista_horarios_disponiveis.php',
    ],

    // CADASTRAR AGENDAMENTO
    'agenda/agendamento/cadastrar' => [
        'POST' => __DIR__ . '/../../backend/agenda/cadastrar_agendamento.php',
    ],

    // DETALHAR E EDITAR AGENDAMENTO
    'agenda/agendamento/detalhar' => [
        'GET' => __DIR__ . '/../../backend/agenda/detalhar_agendamento.php',
    ],
    'agenda/agendamento/editar' => [
        'POST' => __DIR__ . '/../../backend/agenda/editar_agendamento.php',
    ],
    'agenda/agendamento/excluir' => [
        'POST' => __DIR__ . '/../../backend/agenda/excluir_agendamento.php',
    ],
    'agenda/agendamento/pesquisar' => [
        'GET' => __DIR__ . '/../../backend/agenda/pesquisar_agendamento.php',
    ],
    'agenda/agendamento/listar' => [
        'GET' => __DIR__ . '/../../backend/agenda/lista_agenda.php',
    ],

];

/*
|--------------------------------------------------------------------------
| Health check
|--------------------------------------------------------------------------
*/
if ($rota === '') {
    out([
        'ok' => true,
        'code' => 'API_OK',
        'data' => ['msg' => 'API online']
    ]);
}

/*
|--------------------------------------------------------------------------
| Validação de rota
|--------------------------------------------------------------------------
*/
if (!isset($routes[$rota])) {
    out([
        'ok' => false,
        'code' => 'ROUTE_NOT_FOUND',
        'user_msg' => 'Rota não encontrada.'
    ], 404);
}

if (!isset($routes[$rota][$verbo])) {
    out([
        'ok' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'user_msg' => 'Método não permitido.'
    ], 405);
}

$handler = $routes[$rota][$verbo];

if (is_string($handler) && str_starts_with($handler, '@notificacoes_')) {
    require_once __DIR__ . '/../../backend/_auth/require_auth.php';
    require_once __DIR__ . '/../../backend/_regras/permissoes_usuario.php';
    require_once __DIR__ . '/../../backend/_servicos/notificacao.php';

    $authNotificacoes = is_array($_SESSION['auth'] ?? null) ? $_SESSION['auth'] : [];
    $idDestinatario = (int)($authNotificacoes['id_usuario'] ?? 0);
    $tipoUsuarioNotificacoes = mb_strtolower(trim((string)($authNotificacoes['tipo_usuario'] ?? '')), 'UTF-8');
    $modoSuporteNotificacoes = (bool)($authNotificacoes['modo_suporte'] ?? false);
    if ($idDestinatario <= 0) {
        out(['ok' => false, 'code' => 'NOT_AUTHENTICATED', 'user_msg' => 'Sessão expirada. Faça login novamente.'], 401);
    }

    $destinatarioTipo = $tipoUsuarioNotificacoes === 'super_admin' ? 'super_admin' : 'usuario';
    $idEmpresaNotificacoes = null;
    if ($destinatarioTipo === 'usuario' || $modoSuporteNotificacoes) {
        $contextoNotificacoes = permissoesContexto($conexao);
        if (!($contextoNotificacoes['valido'] ?? false)) {
            out(['ok' => false, 'code' => 'NOTIFICATION_CONTEXT_INVALID', 'user_msg' => 'Não foi possível validar o contexto das notificações.'], 403);
        }
        $idEmpresaNotificacoes = (int)($contextoNotificacoes['id_empresa'] ?? 0);
        if ($idEmpresaNotificacoes <= 0) {
            out(['ok' => false, 'code' => 'NOTIFICATION_CONTEXT_INVALID', 'user_msg' => 'Não foi possível validar o contexto das notificações.'], 403);
        }
    }

    if ($handler === '@notificacoes_listar') {
        $pendentes = notificacaoListarPendentes(
            $conexao,
            $destinatarioTipo,
            $idDestinatario,
            $idEmpresaNotificacoes,
            100
        );
        $itens = array_map(static fn(array $item): array => [
            'id_notificacao' => (int)$item['id_notificacao'],
            'codigo' => (string)$item['codigo'],
            'categoria' => (string)$item['categoria'],
            'titulo' => (string)$item['titulo'],
            'mensagem' => (string)$item['mensagem'],
            'prioridade' => (string)$item['prioridade'],
            'obrigatoria' => (bool)$item['obrigatoria'],
            'acao_codigo' => $item['acao_codigo'] === null ? null : (string)$item['acao_codigo'],
            'prazo_em' => $item['prazo_em'] === null ? null : (string)$item['prazo_em'],
            'lida' => $item['lida_em'] !== null,
            'criada_em' => (string)$item['criado_em'],
        ], $pendentes);

        out([
            'ok' => true,
            'code' => 'NOTIFICATIONS_LISTED',
            'data' => ['quantidade' => count($itens), 'itens' => $itens],
        ]);
    }

    $idNotificacaoRaw = $_POST['id_notificacao'] ?? null;
    if (!is_scalar($idNotificacaoRaw) || !preg_match('/^[1-9]\d*$/', trim((string)$idNotificacaoRaw))) {
        out(['ok' => false, 'code' => 'NOTIFICATION_ID_INVALID', 'user_msg' => 'Notificação inválida.'], 422);
    }

    $resultadoLeitura = notificacaoMarcarComoLida(
        $conexao,
        (int)$idNotificacaoRaw,
        $destinatarioTipo,
        $idDestinatario,
        $idEmpresaNotificacoes
    );
    if (!($resultadoLeitura['encontrada'] ?? false)) {
        out(['ok' => false, 'code' => 'NOTIFICATION_NOT_FOUND', 'user_msg' => 'Notificação não encontrada.'], 404);
    }

    out([
        'ok' => true,
        'code' => 'NOTIFICATION_READ',
        'data' => [
            'id_notificacao' => (int)$idNotificacaoRaw,
            'lida' => true,
            'alterada' => (bool)($resultadoLeitura['alterada'] ?? false),
        ],
    ]);
}

if (!is_file($handler)) {
    out([
        'ok' => false,
        'code' => 'HANDLER_NOT_FOUND',
        'user_msg' => 'Handler não encontrado.'
    ], 500);
}

/* A rota já foi validada pelo mapa interno antes desta whitelist. Para uma
   sessão autenticada, toda outra API passa pela revalidação autoritativa. */
$rotasPermitidasComSenhaTemporariaVencida = [
    '_auth/login',
    '_auth/cliente-login',
    '_auth/session',
    '_auth/logout',
    'perfil/alterar-senha',
];

if ($rota === 'perfil/alterar-senha') {
    define('AUTH_PERMITIR_SENHA_TEMPORARIA_VENCIDA', true);
}

if (!in_array($rota, $rotasPermitidasComSenhaTemporariaVencida, true)) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if ((int)($_SESSION['auth']['id_usuario'] ?? 0) > 0) {
        require_once __DIR__ . '/../../backend/_auth/require_auth.php';
    }
}

/* Autorização central das áreas administrativas e da agenda. O backend
   continua sendo a fonte de verdade, independentemente do menu exibido. */
$permissoesPorRota = [
    'painel/configuracao-geral-buscar' => 'empresa.visualizar_configuracoes',
    'painel/configuracao-geral-salvar' => 'empresa.editar_configuracoes',
    'empresa/identidade-visual' => 'empresa.visualizar_configuracoes',
    'empresa/identidade-visual/salvar' => 'empresa.editar_identidade_visual',
    'empresa/identidade-visual/restaurar' => 'empresa.editar_identidade_visual',
    'painel/cliente/listar' => 'clientes.visualizar',
    'painel/cliente/cadastrar' => 'clientes.cadastrar',
    'painel/cliente/editar' => 'clientes.editar',
    'painel/cliente/alterar-status' => 'clientes.alterar_status',
    'painel/usuario/listar' => 'usuarios.visualizar',
    'painel/usuario/cadastrar' => 'usuarios.cadastrar',
    'painel/usuario/editar' => 'usuarios.editar',
    'painel/usuario/alterar-status' => 'usuarios.alterar_status',
    'painel/auditoria/listar' => 'auditoria.visualizar',
    'agenda/servico-profissional/listar' => 'servicos.visualizar',
    'agenda/servico-profissional/cadastrar' => 'servicos.cadastrar',
    'agenda/servico-profissional/cadastrar-agendamento' => 'servicos.cadastrar',
    'agenda/servico-profissional/excluir' => 'servicos.excluir',
    'agenda/agendamento/listar' => 'agenda.visualizar',
    'agenda/agendamento/pesquisar' => 'agenda.visualizar',
    'agenda/agendamento/detalhar' => 'agenda.visualizar',
    'agenda/agendamento/cadastrar' => 'agenda.criar_agendamento',
    'agenda/agendamento/editar' => 'agenda.editar_agendamento',
    'agenda/agendamento/excluir' => 'agenda.excluir_agendamento',
    'agenda/horarios-disponiveis' => 'agenda.visualizar',
    'agenda/configuracao-geral-buscar' => 'agenda_configuracao.visualizar',
];

if (isset($permissoesPorRota[$rota])) {
    require_once __DIR__ . '/../../backend/_config/conexao.php';
    require_once __DIR__ . '/../../backend/_regras/permissoes_usuario.php';
    exigirPermissao($conexao, $permissoesPorRota[$rota]);
}

/*
|--------------------------------------------------------------------------
| Execução segura do handler
|--------------------------------------------------------------------------
*/
try {

    ob_start();
    require $handler;
    $output = trim((string) ob_get_clean());

    // Se o handler já chamou out(), o script já encerrou.
    // Se chegou aqui e existe output, vamos validar.

    if ($output !== '') {

        $json = json_decode($output, true);

        if (is_array($json)) {
            echo json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        out([
            'ok' => false,
            'code' => 'INVALID_HANDLER_OUTPUT',
            'user_msg' => 'O servidor retornou uma resposta inválida.'
        ], 500);
    }

} catch (Throwable $e) {

    error_log("API EXCEPTION: " . $e->getMessage());

    out([
        'ok' => false,
        'code' => 'EXCEPTION',
        'user_msg' => 'Erro interno na API.'
    ], 500);
}

/*
|--------------------------------------------------------------------------
| Se handler não retornou nada
|--------------------------------------------------------------------------
*/
out([
    'ok' => false,
    'code' => 'HANDLER_NO_RESPONSE',
    'user_msg' => 'Handler não retornou resposta.'
], 500);
