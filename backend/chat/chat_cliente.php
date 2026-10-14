<?php
declare(strict_types=1);

/**
 * CHAT — handler contextual da Área do Cliente.
 * A autorização é exclusivamente pela sessão de cliente e pelo vínculo da
 * conversa/agendamento com empresa + cliente da própria sessão.
 */

require_once __DIR__ . '/../_servicos/chat.php';
require_once __DIR__ . '/../cliente/_sessao_cliente.php';
require_once __DIR__ . '/../_config/conexao.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$chatClienteRota = (string)($rota ?? '');
$chatClienteEscritas = [
    'cliente/chat/conversa/iniciar',
    'cliente/chat/mensagem/enviar',
    'cliente/chat/leitura/marcar',
];

try {
    $sessao = exigirSessaoCliente();
    $idClienteSessao = $sessao['id_cliente'];
    if (!is_int($idClienteSessao) || $idClienteSessao <= 0) {
        out([
            'ok' => false,
            'code' => 'CLIENT_CHAT_PROFILE_REQUIRED',
            'user_msg' => 'Conclua seu cadastro para usar o chat.',
        ], 403);
    }

    $cliente = buscarClienteDaSessao($conexao, $sessao);
    if ($cliente === null || (int)($cliente['id_cliente'] ?? 0) !== $idClienteSessao) {
        out([
            'ok' => false,
            'code' => 'CLIENT_CHAT_ACCESS_DENIED',
            'user_msg' => 'Não foi possível validar seu acesso ao chat.',
        ], 403);
    }

    $chatClienteCtx = [
        'id_empresa' => (int)$sessao['id_empresa'],
        'id_cliente' => $idClienteSessao,
        'nome_cliente' => (string)($cliente['nome_completo'] ?? ''),
    ];
} catch (Throwable $e) {
    error_log('[cliente/chat] Falha ao validar contexto: ' . $e->getMessage());
    out([
        'ok' => false,
        'code' => 'CLIENT_CHAT_CONTEXT_ERROR',
        'user_msg' => 'Não foi possível validar seu acesso ao chat agora.',
    ], 500);
}

if (in_array($chatClienteRota, $chatClienteEscritas, true)) {
    require_once __DIR__ . '/../_auth/csrf.php';
    csrfValidarSessao();
}

$chatClienteNaoEncontrada = static function (): void {
    out([
        'ok' => false,
        'code' => 'CHAT_NOT_FOUND',
        'user_msg' => 'Conversa não encontrada.',
    ], 404);
};

$chatClienteIdConversa = static function (array $origem): int {
    $id = chatInteiroPositivo($origem['id_conversa'] ?? null);
    if ($id === null) {
        out([
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'user_msg' => 'Conversa inválida.',
            'field_errors' => ['id_conversa' => 'Informe uma conversa válida.'],
        ], 422);
    }
    return $id;
};

$chatClienteConversaVisivel = static function (int $idConversa) use ($conexao, $chatClienteCtx, $chatClienteNaoEncontrada): array {
    $conversa = chatClienteBuscarConversaAutorizada($conexao, $chatClienteCtx, $idConversa);
    if ($conversa === null) $chatClienteNaoEncontrada();
    return $conversa;
};

switch ($chatClienteRota) {
    case 'cliente/chat/conversa/iniciar': {
        $idAgendamento = chatInteiroPositivo($_POST['id_agendamento'] ?? null);
        if ($idAgendamento === null) {
            out([
                'ok' => false,
                'code' => 'VALIDATION_ERROR',
                'user_msg' => 'Agendamento inválido.',
                'field_errors' => ['id_agendamento' => 'Informe um agendamento válido.'],
            ], 422);
        }

        try {
            $conexao->begin_transaction();
            $resultado = chatClienteIniciarConversaPorAgendamento($conexao, $chatClienteCtx, $idAgendamento);
            if ($resultado['resultado'] === 'nao_encontrado') {
                $conexao->rollback();
                out([
                    'ok' => false,
                    'code' => 'CHAT_APPOINTMENT_NOT_FOUND',
                    'user_msg' => 'Agendamento não encontrado.',
                ], 404);
            }
            if ($resultado['resultado'] === 'status_invalido') {
                $conexao->rollback();
                out([
                    'ok' => false,
                    'code' => 'CHAT_APPOINTMENT_STATUS_INVALID',
                    'user_msg' => 'Este agendamento não está disponível para conversa.',
                ], 409);
            }

            $conversa = chatClienteBuscarConversaAutorizada($conexao, $chatClienteCtx, (int)$resultado['id_conversa']);
            if ($conversa === null) throw new RuntimeException('Conversa criada sem escopo autorizado para o cliente.');
            if (!$conexao->commit()) throw new RuntimeException('Falha ao confirmar a criação da conversa.');
        } catch (Throwable $e) {
            try { $conexao->rollback(); } catch (Throwable) {}
            error_log('[cliente/chat/conversa/iniciar] ' . $e->getMessage());
            out([
                'ok' => false,
                'code' => 'INTERNAL_ERROR',
                'user_msg' => 'Não foi possível abrir a conversa agora.',
            ], 500);
        }

        $criada = $resultado['resultado'] === 'criada';
        out([
            'ok' => true,
            'code' => $criada ? 'CLIENT_CHAT_CONVERSATION_CREATED' : 'CLIENT_CHAT_CONVERSATION_EXISTS',
            'data' => [
                'criada' => $criada,
                'conversa' => chatClienteFormatarConversa($conversa),
            ],
        ], $criada ? 201 : 200);
    }

    case 'cliente/chat/conversa/abrir': {
        $idConversa = $chatClienteIdConversa($_GET);
        $conversa = $chatClienteConversaVisivel($idConversa);
        $mensagens = chatClienteListarMensagens(
            $conexao,
            $chatClienteCtx,
            $idConversa,
            null,
            null,
            chatLimite($_GET['limite'] ?? null)
        );
        out([
            'ok' => true,
            'code' => 'CLIENT_CHAT_CONVERSATION_OPENED',
            'data' => [
                'conversa' => chatClienteFormatarConversa($conversa),
                'mensagens' => $mensagens['itens'],
                'tem_mais_anteriores' => $mensagens['tem_mais'],
            ],
        ]);
    }

    case 'cliente/chat/mensagens/listar': {
        $idConversa = $chatClienteIdConversa($_GET);
        $afterRaw = $_GET['after_id'] ?? null;
        $beforeRaw = $_GET['before_id'] ?? null;
        $afterId = null;
        $beforeId = null;

        if ($afterRaw !== null && $afterRaw !== '') {
            $afterId = (is_string($afterRaw) && trim($afterRaw) === '0') ? 0 : chatInteiroPositivo($afterRaw);
            if ($afterId === null) {
                out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Parâmetro after_id inválido.'], 422);
            }
        }
        if ($beforeRaw !== null && $beforeRaw !== '') {
            $beforeId = chatInteiroPositivo($beforeRaw);
            if ($beforeId === null) {
                out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Parâmetro before_id inválido.'], 422);
            }
        }
        if ($afterId !== null && $beforeId !== null) {
            out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Use after_id ou before_id, não ambos.'], 422);
        }

        $conversa = $chatClienteConversaVisivel($idConversa);
        $mensagens = chatClienteListarMensagens(
            $conexao,
            $chatClienteCtx,
            $idConversa,
            $afterId,
            $beforeId,
            chatLimite($_GET['limite'] ?? null)
        );
        out([
            'ok' => true,
            'code' => 'CLIENT_CHAT_MESSAGES_LISTED',
            'data' => [
                'id_conversa' => $idConversa,
                'status' => (string)$conversa['status'],
                'mensagens' => $mensagens['itens'],
                'tem_mais' => $mensagens['tem_mais'],
            ],
        ]);
    }

    case 'cliente/chat/mensagem/enviar': {
        $idConversa = $chatClienteIdConversa($_POST);
        $conteudo = chatNormalizarConteudo($_POST['conteudo'] ?? null);
        if ($conteudo === null) {
            out([
                'ok' => false,
                'code' => 'VALIDATION_ERROR',
                'user_msg' => 'Escreva uma mensagem de até ' . CHAT_CONTEUDO_MAX . ' caracteres.',
                'field_errors' => ['conteudo' => 'Mensagem vazia, inválida ou acima do limite.'],
            ], 422);
        }

        $chaveRaw = $_POST['chave_idempotencia'] ?? null;
        $chave = null;
        if ($chaveRaw !== null && $chaveRaw !== '') {
            $chave = chatNormalizarChaveIdempotencia($chaveRaw);
            if ($chave === null) {
                out([
                    'ok' => false,
                    'code' => 'VALIDATION_ERROR',
                    'user_msg' => 'Identificador de envio inválido.',
                    'field_errors' => ['chave_idempotencia' => 'Use um UUID válido.'],
                ], 422);
            }
        }

        $resultado = chatClienteEnviarMensagem($conexao, $chatClienteCtx, $idConversa, $conteudo, $chave);
        switch ($resultado['resultado']) {
            case 'nao_encontrada':
                $chatClienteNaoEncontrada();
            case 'encerrada':
                out([
                    'ok' => false,
                    'code' => 'CHAT_CLOSED',
                    'user_msg' => 'Esta conversa foi encerrada e não aceita novas mensagens.',
                ], 409);
            case 'chave_em_uso':
                out([
                    'ok' => false,
                    'code' => 'CHAT_IDEMPOTENCY_CONFLICT',
                    'user_msg' => 'Identificador de envio já utilizado.',
                ], 409);
            case 'idempotente':
                out([
                    'ok' => true,
                    'code' => 'CLIENT_CHAT_MESSAGE_ALREADY_SENT',
                    'data' => ['idempotente' => true, 'mensagem' => $resultado['mensagem']],
                ]);
            default:
                out([
                    'ok' => true,
                    'code' => 'CLIENT_CHAT_MESSAGE_SENT',
                    'data' => ['idempotente' => false, 'mensagem' => $resultado['mensagem']],
                ], 201);
        }
    }

    case 'cliente/chat/leitura/marcar': {
        $idConversa = $chatClienteIdConversa($_POST);
        $idMensagem = chatInteiroPositivo($_POST['id_ultima_mensagem_lida'] ?? null);
        if ($idMensagem === null) {
            out([
                'ok' => false,
                'code' => 'VALIDATION_ERROR',
                'user_msg' => 'Mensagem inválida.',
                'field_errors' => ['id_ultima_mensagem_lida' => 'Informe uma mensagem válida.'],
            ], 422);
        }

        $chatClienteConversaVisivel($idConversa);
        $resultado = chatClienteMarcarLeitura($conexao, $chatClienteCtx, $idConversa, $idMensagem);
        if (!$resultado['ok']) {
            out([
                'ok' => false,
                'code' => 'CHAT_MESSAGE_NOT_FOUND',
                'user_msg' => 'Mensagem não encontrada nesta conversa.',
            ], 404);
        }

        out([
            'ok' => true,
            'code' => 'CLIENT_CHAT_READ_MARKED',
            'data' => [
                'id_conversa' => $idConversa,
                'id_ultima_mensagem_lida' => $resultado['id_ultima_mensagem_lida'],
            ],
        ]);
    }

    case 'cliente/chat/conversas/nao-lidas': {
        // Empresa e cliente vêm exclusivamente do contexto de sessão validado acima.
        out([
            'ok' => true,
            'code' => 'CLIENT_CHAT_UNREAD_COUNTED',
            'data' => chatClienteContarNaoLidas($conexao, $chatClienteCtx),
        ]);
    }

    default:
        out([
            'ok' => false,
            'code' => 'ROUTE_NOT_FOUND',
            'user_msg' => 'Rota não encontrada.',
        ], 404);
}
