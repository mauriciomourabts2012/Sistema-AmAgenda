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
ini_set('error_log', __DIR__ . '/../../storage/api_errors.log');

// A7: cookie de sessao seguro, aplicado antes de qualquer session_start() deste roteador.
require_once __DIR__ . '/../../backend/_config/sessao.php';
amagendaConfigurarSessaoSegura();

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

    // Selecao da empresa apos login com mais de uma empresa (exige pre-sessao valida)
    '_auth/selecionar-empresa' => [
        'POST' => __DIR__ . '/../../backend/_auth/selecionar_empresa.php',
    ],

    // Cadastro público de empresa (onboarding): rota pública, sem sessão. Subfase 6.1: validação e preparação.
    'cadastro-publico/empresa' => [
        'POST' => __DIR__ . '/../../backend/cadastro_publico/cadastrar_empresa.php',
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

    // Webhook público autenticado criptograficamente pelo Mercado Pago
    'webhooks/mercado-pago' => [
        'POST' => __DIR__ . '/../../backend/webhooks/webhook_mercado_pago.php',
    ],

    // Documentos legais e manifestações obrigatórias
    'documentos-legais/publico' => [
        'GET' => __DIR__ . '/../../backend/documentos_legais/publico.php',
    ],
    'documentos-legais/preview' => [
        'GET' => __DIR__ . '/../../backend/documentos_legais/preview.php',
    ],
    'documentos-legais/admin/listar' => [
        'GET' => __DIR__ . '/../../backend/documentos_legais/listar_admin.php',
    ],
    'documentos-legais/admin/versoes' => [
        'GET' => __DIR__ . '/../../backend/documentos_legais/listar_versoes.php',
    ],
    'documentos-legais/admin/versao' => [
        'GET' => __DIR__ . '/../../backend/documentos_legais/visualizar_versao.php',
    ],
    'documentos-legais/admin/rascunho' => [
        'POST' => __DIR__ . '/../../backend/documentos_legais/salvar_rascunho.php',
    ],
    'documentos-legais/admin/publicar' => [
        'POST' => __DIR__ . '/../../backend/documentos_legais/publicar_versao.php',
    ],
    'documentos-legais/admin/arquivar' => [
        'POST' => __DIR__ . '/../../backend/documentos_legais/arquivar_versao.php',
    ],
    'documentos-legais/pendencias' => [
        'GET' => __DIR__ . '/../../backend/documentos_legais/pendencias.php',
    ],
    'documentos-legais/conteudo' => [
        'GET' => __DIR__ . '/../../backend/documentos_legais/conteudo.php',
    ],
    'documentos-legais/manifestar' => [
        'POST' => __DIR__ . '/../../backend/documentos_legais/manifestar.php',
    ],

    // Planos comerciais ativos exibidos no site público
    'planos/listar' => [
        'GET' => __DIR__ . '/../../backend/super_admin/plano/lista_plano.php',
    ],

    // Centro de notificações do destinatário autenticado
    'notificacoes/listar' => [
        'GET' => '@notificacoes_listar',
    ],
    'notificacoes/marcar-lida' => [
        'POST' => '@notificacoes_marcar_lida',
    ],

    // Chat interno (Proprietário, Profissional e Recepção). Autorização, escopo e bloqueio do
    // Super Admin são aplicados no handler, antes de qualquer consulta de conversa.
    'chat/conversas/listar' => [
        'GET' => __DIR__ . '/../../backend/chat/chat_interno.php',
    ],
    'chat/conversas/nao-lidas' => [
        'GET' => __DIR__ . '/../../backend/chat/chat_interno.php',
    ],
    'chat/conversa/abrir' => [
        'GET' => __DIR__ . '/../../backend/chat/chat_interno.php',
    ],
    'chat/mensagens/listar' => [
        'GET' => __DIR__ . '/../../backend/chat/chat_interno.php',
    ],
    'chat/conversa/iniciar' => [
        'POST' => __DIR__ . '/../../backend/chat/chat_interno.php',
    ],
    'chat/mensagem/enviar' => [
        'POST' => __DIR__ . '/../../backend/chat/chat_interno.php',
    ],
    'chat/leitura/marcar' => [
        'POST' => __DIR__ . '/../../backend/chat/chat_interno.php',
    ],
    'chat/conversa/encerrar' => [
        'POST' => __DIR__ . '/../../backend/chat/chat_interno.php',
    ],

    // Chat contextual do cliente. O handler valida sessão, empresa, cliente e
    // vínculo do agendamento/conversa sem reutilizar permissões internas chat.*.
    'cliente/chat/conversa/iniciar' => [
        'POST' => __DIR__ . '/../../backend/chat/chat_cliente.php',
    ],
    'cliente/chat/conversa/abrir' => [
        'GET' => __DIR__ . '/../../backend/chat/chat_cliente.php',
    ],
    'cliente/chat/mensagens/listar' => [
        'GET' => __DIR__ . '/../../backend/chat/chat_cliente.php',
    ],
    'cliente/chat/mensagem/enviar' => [
        'POST' => __DIR__ . '/../../backend/chat/chat_cliente.php',
    ],
    'cliente/chat/conversas/nao-lidas' => [
        'GET' => __DIR__ . '/../../backend/chat/chat_cliente.php',
    ],
    'cliente/chat/leitura/marcar' => [
        'POST' => __DIR__ . '/../../backend/chat/chat_cliente.php',
    ],

    // Central de notificações do cliente. Empresa e cliente vêm somente da sessão do cliente;
    // não reutiliza as rotas internas (notificacoes/*), que dependem da sessão de usuário.
    'cliente/notificacoes/listar' => [
        'GET' => __DIR__ . '/../../backend/cliente/notificacoes_cliente.php',
    ],
    'cliente/notificacoes/marcar-lida' => [
        'POST' => __DIR__ . '/../../backend/cliente/notificacoes_cliente.php',
    ],

    // Portal do cliente autenticado
    'cliente/perfil' => [
        'GET' => __DIR__ . '/../../backend/cliente/buscar_dados_cadastrais.php',
    ],
    'cliente/perfil/salvar' => [
        'POST' => __DIR__ . '/../../backend/cliente/salvar_dados_cadastrais.php',
    ],
    'cliente/agendamentos/listar' => [
        'GET' => __DIR__ . '/../../backend/cliente/listar_agendamentos.php',
    ],
    'cliente/agendamento/profissionais' => [
        'GET' => __DIR__ . '/../../backend/cliente_agendamento/lista-profissionais.php',
    ],
    'cliente/agendamento/servicos' => [
        'GET' => __DIR__ . '/../../backend/cliente_agendamento/Llista-servicos.php',
    ],
    'cliente/agendamento/disponibilidade' => [
        'GET' => __DIR__ . '/../../backend/cliente_agendamento/selecionar-horario.php',
    ],
    'cliente/agendamento/confirmar' => [
        'POST' => __DIR__ . '/../../backend/cliente_agendamento/confirmar-agendamento.php',
    ],

    // MODAL PERFIL DO CLIENTE

    //Buscar Foto
    'cliente/perfil/buscar-foto' => [
        'GET' => __DIR__ . '/../../backend/cliente_perfil/buscar_foto_perfil.php',
    ],

    //Alterar Foto
    'cliente/perfil/alterar-foto' => [
        'POST' => __DIR__ . '/../../backend/cliente_perfil/alterar_foto_perfil.php',
    ],

    //Alterar Senha
    'cliente/perfil/alterar-senha' => [
        'POST' => __DIR__ . '/../../backend/cliente_perfil/alterar_senha_perfil.php',
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

    // Contexto temporário de empresa para atendimento pelo Super Admin
    'superadmin/suporte/entrar' => [
        'POST' => __DIR__ . '/../../backend/super_admin/empresa/lista_empresa.php',
    ],
    'superadmin/suporte/sair' => [
        'POST' => __DIR__ . '/../../backend/super_admin/empresa/lista_empresa.php',
    ],

    // Alterar status da empresa
    'superadmin/empresa/alterar-status' => [
        'POST' => __DIR__ . '/../../backend/super_admin/empresa/alterar_status_empresa.php',
    ],

    // Editar empresa
    'superadmin/empresa/editar' => [
        'POST' => __DIR__ . '/../../backend/super_admin/empresa/editar_empresa.php',
    ],

    // Diagnóstico local e somente leitura da integração Mercado Pago
    'superadmin/integracoes/mercado-pago' => [
        'GET' => __DIR__ . '/../../backend/super_admin/integracao/consultar_mercado_pago.php',
    ],

    // Cobranças manuais do Super Admin
    'superadmin/cobranca/listar' => [
        'GET' => __DIR__ . '/../../backend/super_admin/cobranca/gerenciar_cobranca.php',
    ],
    'superadmin/cobranca/gerar' => [
        'POST' => __DIR__ . '/../../backend/super_admin/cobranca/gerenciar_cobranca.php',
    ],
    'superadmin/cobranca/pagamentos' => [
        'GET' => __DIR__ . '/../../backend/super_admin/cobranca/gerenciar_cobranca.php',
    ],
    'superadmin/cobranca/pagamento/registrar' => [
        'POST' => __DIR__ . '/../../backend/super_admin/cobranca/gerenciar_cobranca.php',
    ],
    'superadmin/cobranca/pagamento/status' => [
        'POST' => __DIR__ . '/../../backend/super_admin/cobranca/gerenciar_cobranca.php',
    ],
    'superadmin/cobranca/cancelar' => [
        'POST' => __DIR__ . '/../../backend/super_admin/cobranca/gerenciar_cobranca.php',
    ],

    // Gestão contratual das assinaturas do Super Admin
    'superadmin/assinatura/listar' => [
        'GET' => __DIR__ . '/../../backend/super_admin/assinatura/gerenciar_assinatura.php',
    ],
    'superadmin/assinatura/status' => [
        'POST' => __DIR__ . '/../../backend/super_admin/assinatura/gerenciar_assinatura.php',
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
    'painel/faturamento/resumo' => [
        'GET' => __DIR__ . '/../../backend/painel_administrativo/faturamento/gerenciar_faturamento.php',
    ],
    'painel/faturamento/cancelar' => [
        'POST' => __DIR__ . '/../../backend/painel_administrativo/faturamento/gerenciar_faturamento.php',
    ],
    'painel/faturamento/contratacao' => [
        'POST' => __DIR__ . '/../../backend/painel_administrativo/faturamento/gerenciar_faturamento.php',
    ],
    'painel/faturamento/troca-plano' => [
        'POST' => __DIR__ . '/../../backend/painel_administrativo/faturamento/gerenciar_faturamento.php',
    ],
    'painel/faturamento/cobrancas' => [
        'GET' => __DIR__ . '/../../backend/painel_administrativo/faturamento/gerenciar_faturamento.php',
    ],
    'painel/faturamento/pagamentos' => [
        'GET' => __DIR__ . '/../../backend/painel_administrativo/faturamento/gerenciar_faturamento.php',
    ],
    'painel/faturamento/regularizacao/cobranca' => [
        'POST' => __DIR__ . '/../../backend/painel_administrativo/faturamento/gerenciar_pagamento_online.php',
    ],
    'painel/faturamento/pagamento/pix/iniciar' => [
        'POST' => __DIR__ . '/../../backend/painel_administrativo/faturamento/gerenciar_pagamento_online.php',
    ],
    'painel/faturamento/pagamento/transacao' => [
        'GET' => __DIR__ . '/../../backend/painel_administrativo/faturamento/gerenciar_pagamento_online.php',
    ],
    'painel/faturamento/pagamento/cartao/configuracao' => [
        'GET' => __DIR__ . '/../../backend/painel_administrativo/faturamento/gerenciar_pagamento_online.php',
    ],
    'painel/faturamento/pagamento/cartao/autorizar' => [
        'POST' => __DIR__ . '/../../backend/painel_administrativo/faturamento/gerenciar_pagamento_online.php',
    ],
    'painel/faturamento/pagamento/cartao/usar-pix' => [
        'POST' => __DIR__ . '/../../backend/painel_administrativo/faturamento/gerenciar_pagamento_online.php',
    ],
    'painel/faturamento/pagamento/cartao/trocar' => [
        'POST' => __DIR__ . '/../../backend/painel_administrativo/faturamento/gerenciar_pagamento_online.php',
    ],
    'painel/faturamento/pagamento/cartao/status' => [
        'GET' => __DIR__ . '/../../backend/painel_administrativo/faturamento/gerenciar_pagamento_online.php',
        'POST' => __DIR__ . '/../../backend/painel_administrativo/faturamento/gerenciar_pagamento_online.php',
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
    // PRÉVIA DE ATRASO (somente leitura; a aplicação será o POST da Fase 5D.3)
    'agenda/agendamento/atraso/previa' => [
        'GET' => __DIR__ . '/../../backend/agenda/atraso_agendamento.php',
    ],
    // APLICAR ATRASO (transacional; CSRF validado no handler, como no editar)
    'agenda/agendamento/atraso/aplicar' => [
        'POST' => __DIR__ . '/../../backend/agenda/atraso_agendamento.php',
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

/* O bloqueio jurídico fica na fronteira central, antes inclusive dos handlers
   especiais, para não poder ser contornado por chamada direta à API. */
$rotasPublicasSemBloqueioJuridico = [
    '_auth/login',
    '_auth/selecionar-empresa',
    '_auth/cliente-login',
    'cadastro-publico/empresa',
    'webhooks/mercado-pago',
    'documentos-legais/publico',
    'empresa/identidade-visual/publica',
];
$rotasResolucaoPendenciaJuridica = [
    '_auth/session',
    '_auth/logout',
    'superadmin/suporte/sair',
    'documentos-legais/pendencias',
    'documentos-legais/conteudo',
    'documentos-legais/manifestar',
];
// O handler aplica o guard exclusivo de Super Admin usando somente a sessão.
$rotasSemConsultaBancoNaFronteira = [
    'superadmin/integracoes/mercado-pago',
];

if (!in_array($rota, $rotasPublicasSemBloqueioJuridico, true)
    && !in_array($rota, $rotasResolucaoPendenciaJuridica, true)
    && !in_array($rota, $rotasSemConsultaBancoNaFronteira, true)) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $temIdentidadeNaSessao = (int)($_SESSION['auth']['id_usuario'] ?? 0) > 0
        || (int)($_SESSION['cliente_auth']['id_cliente'] ?? 0) > 0;
    if ($temIdentidadeNaSessao) {
        try {
            require_once __DIR__ . '/../../backend/_config/conexao.php';
            require_once __DIR__ . '/../../backend/documentos_legais/_comum.php';
            if (documentosLegaisTemPendencias($conexao)) {
                out([
                    'ok' => false,
                    'code' => 'LEGAL_MANIFESTATION_REQUIRED',
                    'user_msg' => 'Revise e manifeste os documentos legais para continuar.',
                    'data' => ['pendencias_url' => '?path=documentos-legais/pendencias'],
                ], 428);
            }
        } catch (UnexpectedValueException) {
            out([
                'ok' => false,
                'code' => 'DOCUMENT_INTEGRITY_ERROR',
                'user_msg' => 'Não foi possível validar os documentos legais.',
            ], 503);
        } catch (Throwable $e) {
            error_log('[documentos_legais] Falha ao verificar pendências: ' . $e->getMessage());
            out([
                'ok' => false,
                'code' => 'LEGAL_PENDING_CHECK_ERROR',
                'user_msg' => 'Não foi possível validar as pendências legais.',
            ], 500);
        }
    }
}

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
        // A sincronização aplica agenda.visualizar (notificacaoUsuarioAcessaAgenda): sem acesso,
        // nada é projetado e pendências ativas anteriores são canceladas.
        if ($destinatarioTipo === 'usuario'
            && ($contextoNotificacoes['perfil'] ?? '') === 'profissional') {
            notificacaoSincronizarAgendamentosPendentesProfissional($conexao, $contextoNotificacoes);
            try {
                notificacaoSincronizarAgendamentosFinalizacaoProfissional($conexao, $contextoNotificacoes);
            } catch (Throwable $e) {
                // A nova projeção é autocorretiva na próxima listagem e não pode
                // indisponibilizar notificações já validadas de outros módulos.
                error_log('[notificacoes/listar] Falha ao sincronizar finalizações: ' . $e->getMessage());
            }
        }

        // Chat: projeta conversas não lidas no escopo real do usuário (chatContextoInterno aplica
        // o bloqueio de Super Admin/suporte, chat.* e o escopo do Profissional). Uma falha aqui
        // não impede a listagem das demais notificações.
        if ($destinatarioTipo === 'usuario' && !$modoSuporteNotificacoes) {
            try {
                require_once __DIR__ . '/../../backend/_servicos/chat.php';
                notificacaoSincronizarChatUsuario(
                    $conexao,
                    chatContextoInterno($conexao),
                    $idEmpresaNotificacoes,
                    $idDestinatario
                );
            } catch (Throwable $e) {
                error_log('[notificacoes/listar] Falha ao sincronizar notificações do chat: ' . $e->getMessage());
            }
        }

        $pendentes = notificacaoListarPendentes(
            $conexao,
            $destinatarioTipo,
            $idDestinatario,
            $idEmpresaNotificacoes,
            100
        );
        $itens = array_map(static function (array $item): array {
            $contextoSeguro = null;
            if (in_array((string)$item['codigo'], ['agenda.agendamento_pendente', 'agenda.agendamento_finalizacao_pendente'], true)
                && is_array($item['contexto'] ?? null)
                && is_int($item['contexto']['id_agendamento'] ?? null)
                && $item['contexto']['id_agendamento'] > 0) {
                $contextoSeguro = ['id_agendamento' => $item['contexto']['id_agendamento']];
            }
            // Chat: somente a referência da conversa; a autorização é refeita ao abrir.
            if ((string)$item['codigo'] === 'chat.conversa_nao_lida'
                && (string)($item['acao_codigo'] ?? '') === 'chat.abrir_conversa'
                && is_array($item['contexto'] ?? null)
                && is_int($item['contexto']['id_conversa'] ?? null)
                && $item['contexto']['id_conversa'] > 0) {
                $contextoSeguro = ['id_conversa' => $item['contexto']['id_conversa']];
            }

            return [
                'id_notificacao' => (int)$item['id_notificacao'],
                'codigo' => (string)$item['codigo'],
                'categoria' => (string)$item['categoria'],
                'titulo' => (string)$item['titulo'],
                'mensagem' => (string)$item['mensagem'],
                'prioridade' => (string)$item['prioridade'],
                'obrigatoria' => (bool)$item['obrigatoria'],
                'acao_codigo' => $item['acao_codigo'] === null ? null : (string)$item['acao_codigo'],
                'contexto' => $contextoSeguro,
                'prazo_em' => $item['prazo_em'] === null ? null : (string)$item['prazo_em'],
                'lida' => $item['lida_em'] !== null,
                'criada_em' => (string)$item['criado_em'],
            ];
        }, $pendentes);

        out([
            'ok' => true,
            'code' => 'NOTIFICATIONS_LISTED',
            'data' => ['quantidade' => count($itens), 'itens' => $itens],
        ]);
    }

    // Escrita autenticada: exige o token CSRF da sessão (mecanismo existente).
    require_once __DIR__ . '/../../backend/_auth/csrf.php';
    csrfValidarSessao();

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
    notificacaoConcluirInformativaLida($conexao, (int)$idNotificacaoRaw, $destinatarioTipo, $idDestinatario, $idEmpresaNotificacoes);

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
    '_auth/selecionar-empresa',
    '_auth/cliente-login',
    'cadastro-publico/empresa',
    'webhooks/mercado-pago',
    '_auth/session',
    '_auth/logout',
    'perfil/alterar-senha',
    'planos/listar',
    'documentos-legais/pendencias',
    'documentos-legais/conteudo',
    'documentos-legais/manifestar',
];

if ($rota === 'perfil/alterar-senha') {
    define('AUTH_PERMITIR_SENHA_TEMPORARIA_VENCIDA', true);
}

if (!in_array($rota, $rotasPermitidasComSenhaTemporariaVencida, true)
    && !in_array($rota, $rotasSemConsultaBancoNaFronteira, true)) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if ((int)($_SESSION['auth']['id_usuario'] ?? 0) > 0) {
        require_once __DIR__ . '/../../backend/_auth/require_auth.php';
    }
}

/* Autenticação antes da autorização: rotas internas (funcionários e Super Admin)
   sem identidade interna na sessão respondem 401 NOT_AUTHENTICATED, nunca
   403 PERMISSION_DENIED. Sessão presente segue a revalidação acima (401
   SESSION_REAUTH_REQUIRED/403 conforme require_auth.php) e depois as permissões.
   Rotas públicas, do cliente (cliente/*), de login/logout e documentos legais
   do ator autenticado não pertencem a esta lista e mantêm o próprio tratamento. */
$prefixosRotasInternas = [
    'painel/',
    'agenda/',
    'chat/',
    'notificacoes/',
    'perfil/',
    'superadmin/',
    'documentos-legais/admin/',
];
$rotasInternasExatas = [
    'empresa/identidade-visual',
    'empresa/identidade-visual/salvar',
    'empresa/identidade-visual/restaurar',
];
$rotaInternaProtegida = in_array($rota, $rotasInternasExatas, true);
foreach ($prefixosRotasInternas as $prefixoRotaInterna) {
    if (str_starts_with($rota, $prefixoRotaInterna)) {
        $rotaInternaProtegida = true;
        break;
    }
}

if ($rotaInternaProtegida) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if ((int)($_SESSION['auth']['id_usuario'] ?? 0) <= 0) {
        out([
            'ok' => false,
            'code' => 'NOT_AUTHENTICATED',
            'user_msg' => 'Sessão expirada. Faça login novamente.'
        ], 401);
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
    'painel/faturamento/resumo' => 'faturamento.visualizar',
    'painel/faturamento/cancelar' => 'faturamento.cancelar',
    'painel/faturamento/contratacao' => 'faturamento.contratar',
    'painel/faturamento/troca-plano' => 'faturamento.trocar_plano',
    'painel/faturamento/cobrancas' => 'faturamento.visualizar',
    'painel/faturamento/pagamentos' => 'faturamento.visualizar',
    // A regularização valida Proprietário, modo de regularização e CSRF no handler específico.
    'painel/faturamento/pagamento/pix/iniciar' => 'faturamento.pagar',
    'painel/faturamento/pagamento/transacao' => 'faturamento.visualizar',
    'painel/faturamento/pagamento/cartao/configuracao' => 'faturamento.pagar',
    'painel/faturamento/pagamento/cartao/autorizar' => 'faturamento.pagar',
    'painel/faturamento/pagamento/cartao/status' => 'faturamento.pagar',
    'painel/faturamento/pagamento/cartao/usar-pix' => 'faturamento.pagar',
    'painel/faturamento/pagamento/cartao/trocar' => 'faturamento.pagar',
    // As três rotas de configuração de serviços validam permissão e propriedade no handler,
    // pois a decisão depende do id_profissional alvo. O cadastro pelo agendamento não depende disso.
    'agenda/servico-profissional/cadastrar-agendamento' => 'servicos.cadastrar',
    'agenda/agendamento/listar' => 'agenda.visualizar',
    'agenda/agendamento/pesquisar' => 'agenda.visualizar',
    'agenda/agendamento/detalhar' => 'agenda.visualizar',
    'agenda/agendamento/cadastrar' => 'agenda.criar_agendamento',
    'agenda/agendamento/editar' => 'agenda.editar_agendamento',
    'agenda/agendamento/atraso/previa' => 'agenda.editar_agendamento',
    'agenda/agendamento/atraso/aplicar' => 'agenda.editar_agendamento',
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
| CSRF central (defesa preventiva com negação por padrão)
|--------------------------------------------------------------------------
| Toda operação de escrita com identidade autenticada na sessão exige o token
| CSRF da sessão (mecanismo existente em backend/_auth/csrf.php), inclusive em
| rotas criadas no futuro. Os handlers mantêm as próprias validações; a nova
| checagem usa o mesmo token e o mesmo resultado.
| Sem identidade na sessão, a decisão continua com o handler (preserva 401).
| Roda após o bloqueio jurídico, a revalidação da sessão e as permissões.
| O handler especial @notificacoes_* encerra antes deste ponto e valida o
| CSRF da sua escrita no próprio bloco.
|
| Exceções exatas e justificadas:
| - _auth/login, _auth/cliente-login e cadastro-publico/empresa: entrada pública;
| - webhooks/mercado-pago: servidor a servidor, autenticado por assinatura HMAC;
| - cliente/agendamento/confirmar: token próprio (cliente_agendamento_csrf).
*/
$rotasExcecaoCsrfCentral = [
    '_auth/login',
    '_auth/cliente-login',
    'cadastro-publico/empresa',
    'webhooks/mercado-pago',
    'cliente/agendamento/confirmar',
];

if (in_array($verbo, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
    && !in_array($rota, $rotasExcecaoCsrfCentral, true)) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $authCsrfCentral = is_array($_SESSION['auth'] ?? null) ? $_SESSION['auth'] : [];
    $clienteCsrfCentral = is_array($_SESSION['cliente_auth'] ?? null) ? $_SESSION['cliente_auth'] : [];
    $identidadeCsrfCentral = (int)($authCsrfCentral['id_usuario'] ?? 0) > 0
        || $clienteCsrfCentral !== []
        || (int)($_SESSION['superadmin_id'] ?? 0) > 0;

    if ($identidadeCsrfCentral) {
        require_once __DIR__ . '/../../backend/_auth/csrf.php';
        csrfValidarSessao();
    }
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
