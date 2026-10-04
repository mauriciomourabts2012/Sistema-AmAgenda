<?php
declare(strict_types=1);

/**
 * Catálogo fechado da auditoria administrativa.
 * Códigos ausentes deste catálogo nunca podem ser persistidos.
 */
function auditoriaCatalogo(): array
{
    return [
        'agendamento.criado' => auditoriaDefinicaoEvento('agenda', 'agendamento', 'alta', 'Criou um agendamento.', [
            'cliente', 'profissional', 'servico', 'data_agendamento', 'hora_inicio', 'hora_fim',
            'status', 'duracao_min_aplicada', 'valor_aplicado', 'observacao',
            'repetir_semanalmente', 'recorrencia_data_fim', 'recorrencia', 'depois',
        ]),
        'agendamento.editado' => auditoriaDefinicaoEvento('agenda', 'agendamento', 'alta', 'Alterou um agendamento.', [
            'cliente', 'profissional', 'servico', 'data_agendamento', 'hora_inicio', 'hora_fim',
            'status', 'duracao_min_aplicada', 'valor_aplicado', 'observacao',
            'repetir_semanalmente', 'recorrencia_data_fim', 'recorrencia',
        ]),
        'agendamento.confirmado' => auditoriaDefinicaoEvento('agenda', 'agendamento', 'alta', 'Confirmou um agendamento.', ['status', 'recorrencia']),
        'agendamento.cancelado' => auditoriaDefinicaoEvento('agenda', 'agendamento', 'alta', 'Cancelou um agendamento.', ['status', 'recorrencia']),
        'agendamento.concluido' => auditoriaDefinicaoEvento('agenda', 'agendamento', 'alta', 'Concluiu um agendamento.', ['status', 'recorrencia']),
        'agendamento.excluido' => auditoriaDefinicaoEvento('agenda', 'agendamento', 'alta', 'Excluiu um agendamento.', [
            'cliente', 'profissional', 'servico', 'data_agendamento', 'hora_inicio', 'hora_fim',
            'status', 'recorrencia', 'antes',
        ]),

        'cliente.criado' => auditoriaDefinicaoEvento('clientes', 'cliente', 'media', 'Cadastrou um cliente.', ['nome', 'telefone', 'email', 'status', 'observacao', 'depois']),
        'cliente.editado' => auditoriaDefinicaoEvento('clientes', 'cliente', 'media', 'Alterou um cliente.', ['nome', 'telefone', 'email', 'status', 'observacao']),
        'cliente.status_alterado' => auditoriaDefinicaoEvento('clientes', 'cliente', 'alta', 'Alterou o status de um cliente.', ['status']),

        'usuario.criado' => auditoriaDefinicaoEvento('usuarios', 'usuario', 'alta', 'Cadastrou um usuário.', ['nome', 'email', 'telefone', 'perfil', 'status', 'status_vinculo', 'especialidade', 'depois']),
        'usuario.vinculado_empresa' => auditoriaDefinicaoEvento('usuarios', 'usuario', 'alta', 'Vinculou um usuário a uma empresa.', ['perfil', 'status_vinculo']),
        'usuario.editado' => auditoriaDefinicaoEvento('usuarios', 'usuario', 'alta', 'Alterou um usuário.', ['nome', 'email', 'telefone', 'perfil', 'status', 'status_vinculo', 'especialidade']),
        'usuario.status_alterado' => auditoriaDefinicaoEvento('usuarios', 'usuario', 'critica', 'Alterou o status de um usuário.', ['status', 'status_vinculo']),
        'usuario.reativado_plano' => auditoriaDefinicaoEvento('usuarios', 'usuario', 'critica', 'Reativou um usuário bloqueado pelo plano.', ['bloqueado_plano']),
        'usuario.senha_redefinida' => auditoriaDefinicaoEvento('usuarios', 'usuario', 'critica', 'Redefiniu a senha de um usuário.', ['senha_alterada']),
        'usuario.permissoes_alteradas' => auditoriaDefinicaoEvento('permissoes', 'usuario', 'critica', 'Alterou permissões de um usuário.', ['permissoes']),
        'usuario.permissoes_restauradas' => auditoriaDefinicaoEvento('permissoes', 'usuario', 'critica', 'Restaurou as permissões padrão de um usuário.', ['permissoes', 'antes']),

        'servico.criado' => auditoriaDefinicaoEvento('servicos', 'servico', 'media', 'Cadastrou um serviço.', ['nome', 'descricao', 'profissional', 'duracao_min', 'valor', 'status', 'origem', 'depois']),
        'servico.excluido' => auditoriaDefinicaoEvento('servicos', 'servico', 'alta', 'Excluiu um serviço.', ['nome', 'descricao', 'profissional', 'duracao_min', 'valor', 'status', 'origem', 'antes']),

        'empresa.configuracoes_alteradas' => auditoriaDefinicaoEvento('configuracoes', 'empresa', 'alta', 'Alterou configurações da empresa.', [
            'aba', 'intervalo_padrao', 'observacao_padrao', 'inicio_semana', 'horarios',
            'ddi_padrao', 'ddd_padrao', 'mensagem_whatsapp',
        ]),
        'empresa.identidade_visual_alterada' => auditoriaDefinicaoEvento('configuracoes', 'empresa', 'alta', 'Alterou a identidade visual da empresa.', [
            'nome_exibicao', 'logo', 'imagem_login', 'imagem_login_escala', 'imagem_login_pos_x', 'imagem_login_pos_y',
        ]),
        'empresa.identidade_visual_restaurada' => auditoriaDefinicaoEvento('configuracoes', 'empresa', 'alta', 'Restaurou a identidade visual padrão.', [
            'nome_exibicao', 'logo', 'imagem_login', 'imagem_login_escala', 'imagem_login_pos_x', 'imagem_login_pos_y', 'antes',
        ]),
        'agenda_profissional.configuracao_alterada' => auditoriaDefinicaoEvento('configuracoes', 'profissional', 'alta', 'Alterou a configuração de agenda de um profissional.', [
            'profissional', 'aba', 'intervalo_padrao', 'observacao_padrao', 'inicio_semana',
            'horarios', 'ddi_padrao', 'ddd_padrao', 'mensagem_whatsapp',
        ]),
        'agenda_profissional.configuracao_restaurada' => auditoriaDefinicaoEvento('configuracoes', 'profissional', 'alta', 'Restaurou a configuração padrão de um profissional.', ['profissional', 'antes']),

        'perfil.senha_alterada' => auditoriaDefinicaoEvento('perfil', 'usuario', 'critica', 'Alterou a própria senha.', ['senha_alterada']),

        'empresa.criada' => auditoriaDefinicaoEvento('empresas', 'empresa', 'alta', 'Criou uma empresa.', ['nome', 'cnpj', 'email', 'telefone', 'plano', 'status', 'endereco', 'observacao', 'depois']),
        'empresa.editada' => auditoriaDefinicaoEvento('empresas', 'empresa', 'alta', 'Alterou uma empresa.', ['nome', 'cnpj', 'email', 'telefone', 'plano', 'status', 'endereco', 'observacao']),
        'empresa.status_alterado' => auditoriaDefinicaoEvento('empresas', 'empresa', 'critica', 'Alterou o status de uma empresa.', ['status']),

        'assinatura.criada' => auditoriaDefinicaoEvento('financeiro', 'assinatura', 'alta', 'Criou uma assinatura.', [
            'id_empresa', 'id_plano', 'valor_contratado', 'periodicidade', 'dia_vencimento', 'data_inicio', 'status', 'depois',
        ]),
        'assinatura.encerrada' => auditoriaDefinicaoEvento('financeiro', 'assinatura', 'alta', 'Encerrou uma assinatura.', [
            'status', 'data_fim', 'antes', 'depois',
        ]),
        'assinatura.trocada' => auditoriaDefinicaoEvento('financeiro', 'assinatura', 'critica', 'Trocou a assinatura de uma empresa.', [
            'id_assinatura_anterior', 'id_plano', 'valor_contratado', 'periodicidade', 'antes', 'depois',
        ]),
        'assinatura.suspensa' => auditoriaDefinicaoEvento('financeiro', 'assinatura', 'critica', 'Suspendeu uma assinatura.', [
            'id_empresa', 'id_assinatura', 'id_cobranca', 'id_plano', 'status', 'modalidade', 'status_anterior', 'status_novo',
            'motivo_suspensao', 'suspensa_em', 'depois',
        ]),
        'assinatura.reativada' => auditoriaDefinicaoEvento('financeiro', 'assinatura', 'critica', 'Reativou uma assinatura.', [
            'id_empresa', 'id_assinatura', 'id_cobranca', 'id_plano', 'status', 'modalidade', 'status_anterior', 'status_novo',
            'motivo_suspensao', 'suspensa_em', 'depois',
        ]),
        'assinatura.cancelada' => auditoriaDefinicaoEvento('financeiro', 'assinatura', 'critica', 'Cancelou uma assinatura.', [
            'id_empresa', 'id_assinatura', 'id_plano', 'status', 'modalidade', 'status_anterior', 'status_novo', 'data_fim', 'depois',
        ]),
        'assinatura.teste_iniciado' => auditoriaDefinicaoEvento('financeiro', 'assinatura', 'alta', 'Iniciou o período de teste de uma assinatura.', [
            'id_empresa', 'id_assinatura', 'id_plano', 'status', 'modalidade', 'teste_iniciado_em', 'teste_expira_em', 'depois',
        ]),
        'assinatura.teste_expirado' => auditoriaDefinicaoEvento('financeiro', 'assinatura', 'critica', 'Registrou a expiração do período de teste de uma assinatura.', [
            'id_empresa', 'id_assinatura', 'id_plano', 'status', 'modalidade', 'teste_iniciado_em', 'teste_expira_em',
            'motivo_suspensao', 'suspensa_em', 'antes', 'depois',
        ]),
        'assinatura.convertida_paga' => auditoriaDefinicaoEvento('financeiro', 'assinatura', 'critica', 'Converteu o período de teste em assinatura paga após a quitação da cobrança de conversão.', [
            'id_empresa', 'id_assinatura', 'id_cobranca', 'id_plano', 'status', 'modalidade',
            'status_anterior', 'status_novo', 'modalidade_anterior', 'modalidade_nova',
            'teste_iniciado_em', 'teste_expira_em', 'valor', 'periodo_inicio', 'periodo_fim',
            'motivo_suspensao', 'suspensa_em', 'depois',
        ]),
        'cobranca.gerada' => auditoriaDefinicaoEvento('financeiro', 'cobranca', 'alta', 'Gerou uma cobrança.', [
            'id_empresa', 'id_assinatura', 'periodo_inicio', 'periodo_fim', 'data_vencimento', 'valor', 'status', 'finalidade', 'depois',
        ]),
        'cobranca.cancelada' => auditoriaDefinicaoEvento('financeiro', 'cobranca', 'alta', 'Cancelou uma cobrança.', [
            'id_empresa', 'id_assinatura', 'id_cobranca', 'valor', 'periodo_inicio', 'periodo_fim', 'data_vencimento',
            'status', 'motivo', 'antes', 'depois',
        ]),
        'pagamento.registrado' => auditoriaDefinicaoEvento('financeiro', 'pagamento', 'alta', 'Registrou um pagamento manual.', [
            'id_empresa', 'id_cobranca', 'id_pagamento', 'valor_pago', 'data_pagamento', 'forma_pagamento', 'origem', 'status',
            'total_pago_confirmado', 'saldo_restante', 'depois',
        ]),
        'pagamento.cancelado' => auditoriaDefinicaoEvento('financeiro', 'pagamento', 'alta', 'Cancelou um pagamento.', [
            'id_empresa', 'id_cobranca', 'id_pagamento', 'valor_pago', 'data_pagamento', 'forma_pagamento', 'origem', 'status',
            'total_pago_confirmado', 'saldo_restante', 'antes', 'depois',
        ]),
        'pagamento.estornado' => auditoriaDefinicaoEvento('financeiro', 'pagamento', 'critica', 'Estornou um pagamento.', [
            'id_empresa', 'id_cobranca', 'id_pagamento', 'valor_pago', 'data_pagamento', 'forma_pagamento', 'origem', 'status',
            'total_pago_confirmado', 'saldo_restante', 'antes', 'depois',
        ]),
        'pagamento.confirmado_gateway' => auditoriaDefinicaoEvento('financeiro', 'pagamento', 'alta', 'Confirmou um pagamento recebido pelo gateway.', [
            'id_empresa', 'id_cobranca', 'id_pagamento', 'valor_pago', 'data_pagamento', 'forma_pagamento', 'origem', 'status',
            'provedor', 'referencia_externa', 'total_pago_confirmado', 'saldo_restante', 'depois',
        ]),
        'pagamento.recusado_gateway' => auditoriaDefinicaoEvento('financeiro', 'transacao', 'alta', 'Registrou uma recusa informada pelo gateway.', [
            'id_empresa', 'id_cobranca', 'id_transacao', 'status', 'status_externo', 'detalhe_status_externo', 'depois',
        ]),
        'pagamento.estornado_gateway' => auditoriaDefinicaoEvento('financeiro', 'pagamento', 'critica', 'Registrou um estorno confirmado pelo gateway.', [
            'id_empresa', 'id_cobranca', 'id_pagamento', 'valor_pago', 'data_pagamento', 'forma_pagamento', 'origem', 'status',
            'provedor', 'referencia_externa', 'total_pago_confirmado', 'saldo_restante', 'antes', 'depois',
        ]),
        'pagamento.conciliacao_necessaria' => auditoriaDefinicaoEvento('financeiro', 'transacao', 'critica', 'Identificou uma divergência financeira que exige conciliação.', [
            'id_empresa', 'id_cobranca', 'id_transacao', 'status', 'status_externo', 'detalhe_status_externo', 'motivo', 'depois',
        ]),

        'plano.criado' => auditoriaDefinicaoEvento('planos', 'plano', 'alta', 'Criou um plano.', ['nome', 'ref', 'preco_mensal', 'cobranca', 'limite_usuarios', 'limite_profissionais', 'limite_servicos', 'limite_agendamentos', 'agenda_online', 'gera_cobranca', 'disponivel_cadastro_publico', 'destaque', 'status', 'descricao', 'observacao', 'depois']),
        'plano.editado' => auditoriaDefinicaoEvento('planos', 'plano', 'alta', 'Alterou um plano.', ['nome', 'ref', 'preco_mensal', 'cobranca', 'limite_usuarios', 'limite_profissionais', 'limite_servicos', 'limite_agendamentos', 'agenda_online', 'gera_cobranca', 'disponivel_cadastro_publico', 'destaque', 'status', 'descricao', 'observacao']),
        'plano.status_alterado' => auditoriaDefinicaoEvento('planos', 'plano', 'critica', 'Alterou o status de um plano.', ['status']),

        'super_admin.criado' => auditoriaDefinicaoEvento('usuarios', 'usuario', 'critica', 'Criou um Super Admin.', ['nome', 'email', 'telefone', 'status', 'depois']),
        'super_admin.editado' => auditoriaDefinicaoEvento('usuarios', 'usuario', 'critica', 'Alterou um Super Admin.', ['nome', 'email', 'telefone', 'status', 'senha_alterada']),
        'super_admin.status_alterado' => auditoriaDefinicaoEvento('usuarios', 'usuario', 'critica', 'Alterou o status de um Super Admin.', ['status']),

        'autenticacao.credenciais_invalidas' => auditoriaDefinicaoEvento('autenticacao', 'sessao', 'critica', 'Falha de autenticação por credenciais inválidas.', ['login_tentado']),
        'autenticacao.usuario_inativo' => auditoriaDefinicaoEvento('autenticacao', 'sessao', 'critica', 'Falha de autenticação por usuário indisponível.', []),
        'autenticacao.empresa_inativa' => auditoriaDefinicaoEvento('autenticacao', 'sessao', 'critica', 'Falha de autenticação por empresa indisponível.', []),
        'autenticacao.vinculo_inativo' => auditoriaDefinicaoEvento('autenticacao', 'sessao', 'critica', 'Falha de autenticação por vínculo indisponível.', []),
        'autenticacao.acesso_negado' => auditoriaDefinicaoEvento('autenticacao', 'sessao', 'critica', 'Acesso negado por regra de autenticação.', []),
        'cadastro_publico.tentativa' => auditoriaDefinicaoEvento('autenticacao', 'cadastro_publico', 'alta', 'Registrou uma tentativa de cadastro público.', []),
        'cadastro_publico.recusado' => auditoriaDefinicaoEvento('autenticacao', 'cadastro_publico', 'alta', 'Recusou uma tentativa de cadastro público.', []),
        'cadastro_publico.concluido' => auditoriaDefinicaoEvento('autenticacao', 'cadastro_publico', 'alta', 'Concluiu um cadastro público.', []),
        'cadastro_publico.falha_tecnica' => auditoriaDefinicaoEvento('autenticacao', 'cadastro_publico', 'critica', 'Registrou uma falha técnica no cadastro público.', []),
        'suporte.iniciado' => auditoriaDefinicaoEvento('autenticacao', 'sessao', 'alta', 'Iniciou o modo suporte.', []),
        'suporte.finalizado' => auditoriaDefinicaoEvento('autenticacao', 'sessao', 'alta', 'Finalizou o modo suporte.', []),

        'documentos_legais.termos_aceitos' => auditoriaDefinicaoEvento('documentos_legais', 'documento_legal_manifestacao', 'critica', 'Registrou o aceite dos termos legais.', []),
        'documentos_legais.politica_ciencia_registrada' => auditoriaDefinicaoEvento('documentos_legais', 'documento_legal_manifestacao', 'critica', 'Registrou ciência da Política de Privacidade.', []),
        'documentos_legais.manifestacao_registrada' => auditoriaDefinicaoEvento('documentos_legais', 'documento_legal_manifestacao', 'critica', 'Concluiu a manifestação dos documentos legais obrigatórios.', []),
        'documentos_legais.falha_integridade' => auditoriaDefinicaoEvento('documentos_legais', 'documento_legal_versao', 'critica', 'Detectou falha de integridade em documento legal.', []),
        'documentos_legais.preview_visualizado' => auditoriaDefinicaoEvento('documentos_legais', 'documento_legal_versao', 'alta', 'Visualizou o preview de um documento legal.', []),
        'documentos_legais.rascunho_criado' => auditoriaDefinicaoEvento('documentos_legais', 'documento_legal_versao', 'alta', 'Criou um rascunho de documento legal.', [
            'resumo_alteracoes', 'exige_nova_manifestacao', 'conteudo_alterado',
        ]),
        'documentos_legais.rascunho_editado' => auditoriaDefinicaoEvento('documentos_legais', 'documento_legal_versao', 'alta', 'Editou um rascunho de documento legal.', [
            'resumo_alteracoes', 'exige_nova_manifestacao', 'conteudo_alterado',
        ]),
        'documentos_legais.versao_publicada' => auditoriaDefinicaoEvento('documentos_legais', 'documento_legal_versao', 'critica', 'Publicou uma versão de documento legal.', [
            'status', 'vigencia_inicio', 'vigencia_fim', 'exige_nova_manifestacao', 'versao_anterior_afetada',
        ]),
        'documentos_legais.versao_arquivada' => auditoriaDefinicaoEvento('documentos_legais', 'documento_legal_versao', 'alta', 'Arquivou uma versão de documento legal.', [
            'status', 'vigencia_fim',
        ]),
    ];
}

function auditoriaDefinicaoEvento(string $modulo, string $entidade, string $prioridade, string $descricao, array $campos): array
{
    return [
        'modulo' => $modulo,
        'entidade' => $entidade,
        'prioridade' => $prioridade,
        'descricao_padrao' => $descricao,
        'campos_auditaveis' => array_values(array_unique($campos)),
    ];
}

function auditoriaObterEvento(string $codigo): array
{
    $evento = auditoriaCatalogo()[$codigo] ?? null;
    if (!is_array($evento)) {
        throw new InvalidArgumentException('Evento de auditoria desconhecido.');
    }

    return $evento;
}
