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

ini_set('display_errors', '1');
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

    // Logout (deslogar)
    '_auth/logout' => [
        'POST' => __DIR__ . '/../../backend/_auth/logout.php',
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

    // Editar Usuário (FUNCIONARIO)
    'painel/usuario/editar' => [
        'POST' => __DIR__ . '/../../backend/painel_administrativo/usuario/editar_usuario.php',
    ],

    // Alterar Status Usuário (FUNCIONARIO)
    'painel/usuario/alterar-status' => [
        'POST' => __DIR__ . '/../../backend/painel_administrativo/usuario/alterar_status_usuario.php',
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

if (!is_file($handler)) {
    out([
        'ok' => false,
        'code' => 'HANDLER_NOT_FOUND',
        'user_msg' => 'Handler não encontrado.'
    ], 500);
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
            'user_msg' => 'Handler retornou saída inválida.',
            'raw' => $output
        ], 500);
    }

} catch (Throwable $e) {

    error_log("API EXCEPTION: " . $e->getMessage());

    out([
        'ok' => false,
        'code' => 'EXCEPTION',
        'user_msg' => 'Erro interno na API.',
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
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
