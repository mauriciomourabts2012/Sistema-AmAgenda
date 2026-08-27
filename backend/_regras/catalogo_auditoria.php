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
        'usuario.editado' => auditoriaDefinicaoEvento('usuarios', 'usuario', 'alta', 'Alterou um usuário.', ['nome', 'email', 'telefone', 'perfil', 'status', 'status_vinculo', 'especialidade']),
        'usuario.status_alterado' => auditoriaDefinicaoEvento('usuarios', 'usuario', 'critica', 'Alterou o status de um usuário.', ['status', 'status_vinculo']),
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

