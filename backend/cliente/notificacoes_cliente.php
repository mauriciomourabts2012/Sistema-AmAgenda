<?php
declare(strict_types=1);

/**
 * CENTRAL DE NOTIFICAÇÕES DO CLIENTE (Área do Cliente).
 * A autorização é exclusivamente pela sessão de cliente: empresa e cliente nunca vêm do navegador.
 * A lógica de persistência continua em backend/_servicos/notificacao.php.
 */

require_once __DIR__ . '/_sessao_cliente.php';
require_once __DIR__ . '/../_config/conexao.php';
require_once __DIR__ . '/../_servicos/notificacao.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$notificacaoClienteRota = (string)($rota ?? '');

try {
    $sessao = exigirSessaoCliente();
    $idEmpresaCliente = (int)$sessao['id_empresa'];
    $idClienteSessao = $sessao['id_cliente'];
    if (!is_int($idClienteSessao) || $idClienteSessao <= 0) {
        out([
            'ok' => false,
            'code' => 'CLIENT_NOTIFICATION_PROFILE_REQUIRED',
            'user_msg' => 'Conclua seu cadastro para ver suas notificações.',
        ], 403);
    }

    // Cliente ativo da própria empresa da sessão (mesma validação usada pelo Chat do cliente).
    $cliente = buscarClienteDaSessao($conexao, $sessao);
    if ($cliente === null || (int)($cliente['id_cliente'] ?? 0) !== $idClienteSessao) {
        out([
            'ok' => false,
            'code' => 'CLIENT_NOTIFICATION_ACCESS_DENIED',
            'user_msg' => 'Não foi possível validar seu acesso às notificações.',
        ], 403);
    }
} catch (Throwable $e) {
    error_log('[cliente/notificacoes] Falha ao validar contexto: ' . $e->getMessage());
    out([
        'ok' => false,
        'code' => 'CLIENT_NOTIFICATION_CONTEXT_ERROR',
        'user_msg' => 'Não foi possível validar seu acesso às notificações agora.',
    ], 500);
}

switch ($notificacaoClienteRota) {
    case 'cliente/notificacoes/listar': {
        // Projeção do Chat sob demanda. Uma falha aqui não impede a listagem das demais.
        try {
            notificacaoSincronizarChatCliente($conexao, $idEmpresaCliente, $idClienteSessao);
        } catch (Throwable $e) {
            error_log('[cliente/notificacoes/listar] Falha ao sincronizar notificações do chat: ' . $e->getMessage());
        }

        try {
            $pendentes = notificacaoListarPendentes($conexao, 'cliente', $idClienteSessao, $idEmpresaCliente, 100);
        } catch (Throwable $e) {
            error_log('[cliente/notificacoes/listar] ' . $e->getMessage());
            out([
                'ok' => false,
                'code' => 'CLIENT_NOTIFICATION_LIST_ERROR',
                'user_msg' => 'Não foi possível carregar as notificações agora.',
            ], 500);
        }

        $itens = array_map(static function (array $item): array {
            // O contexto é apenas referência de navegação (não é autorização): somente o
            // agendamento, que é o contrato de abertura do Chat do cliente.
            $contextoSeguro = null;
            $contexto = is_array($item['contexto'] ?? null) ? $item['contexto'] : [];
            if (in_array((string)$item['codigo'], ['chat.conversa_nao_lida', 'agendamento_atraso'], true)
                && (string)($item['acao_codigo'] ?? '') === 'cliente.chat.abrir_conversa'
                && is_int($contexto['id_agendamento'] ?? null)
                && $contexto['id_agendamento'] > 0) {
                $contextoSeguro = ['id_agendamento' => $contexto['id_agendamento']];
            }

            return [
                'id_notificacao' => (int)$item['id_notificacao'],
                'codigo' => (string)$item['codigo'],
                'categoria' => (string)$item['categoria'],
                'titulo' => (string)$item['titulo'],
                'mensagem' => (string)$item['mensagem'],
                'prioridade' => (string)$item['prioridade'],
                'acao_codigo' => $item['acao_codigo'] === null ? null : (string)$item['acao_codigo'],
                'contexto' => $contextoSeguro,
                // Data/hora funcional (no Chat: horário da última resposta), como na central interna.
                'prazo_em' => $item['prazo_em'] === null ? null : (string)$item['prazo_em'],
                'criada_em' => (string)$item['criado_em'],
                'lida_em' => $item['lida_em'] === null ? null : (string)$item['lida_em'],
            ];
        }, $pendentes);

        out([
            'ok' => true,
            'code' => 'CLIENT_NOTIFICATIONS_LISTED',
            'data' => [
                'quantidade' => count($itens),
                'nao_lidas' => count(array_filter($itens, static fn(array $item): bool => $item['lida_em'] === null)),
                'itens' => $itens,
            ],
        ]);
    }

    case 'cliente/notificacoes/marcar-lida': {
        require_once __DIR__ . '/../_auth/csrf.php';
        csrfValidarSessao();

        $idNotificacaoRaw = $_POST['id_notificacao'] ?? null;
        if (!is_scalar($idNotificacaoRaw) || preg_match('/^[1-9]\d*$/', trim((string)$idNotificacaoRaw)) !== 1) {
            out([
                'ok' => false,
                'code' => 'NOTIFICATION_ID_INVALID',
                'user_msg' => 'Notificação inválida.',
            ], 422);
        }
        $idNotificacao = (int)trim((string)$idNotificacaoRaw);

        try {
            // Revalida empresa + destinatario_tipo + destinatario_id dentro do UPDATE.
            $resultado = notificacaoMarcarComoLida($conexao, $idNotificacao, 'cliente', $idClienteSessao, $idEmpresaCliente);
        } catch (Throwable $e) {
            error_log('[cliente/notificacoes/marcar-lida] ' . $e->getMessage());
            out([
                'ok' => false,
                'code' => 'CLIENT_NOTIFICATION_READ_ERROR',
                'user_msg' => 'Não foi possível marcar a notificação como lida.',
            ], 500);
        }
        if (!($resultado['encontrada'] ?? false)) {
            out([
                'ok' => false,
                'code' => 'NOTIFICATION_NOT_FOUND',
                'user_msg' => 'Notificação não encontrada.',
            ], 404);
        }

        out([
            'ok' => true,
            'code' => 'CLIENT_NOTIFICATION_READ',
            'data' => [
                'id_notificacao' => $idNotificacao,
                'lida' => true,
                'alterada' => (bool)($resultado['alterada'] ?? false),
            ],
        ]);
    }

    default:
        out([
            'ok' => false,
            'code' => 'ROUTE_NOT_FOUND',
            'user_msg' => 'Rota não encontrada.',
        ], 404);
}
