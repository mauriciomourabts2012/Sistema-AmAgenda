<?php
declare(strict_types=1);

/**
 * CHAT — handler interno único (Proprietário, Profissional e Recepção).
 * Incluído por public/api/api_central.php; a operação é escolhida pela rota ($rota).
 * Ordem obrigatória: barreira do Super Admin (somente sessão) → contexto/permissões →
 * CSRF nas escritas → validação de entrada → consulta já com escopo da sessão.
 */

require_once __DIR__ . '/../_servicos/chat.php';

if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// 1) Segunda barreira contra Super Admin: antes de qualquer consulta de conversa/mensagem.
if (chatSessaoEhSuperAdmin()) {
    out(['ok' => false, 'code' => 'CHAT_ACCESS_DENIED', 'user_msg' => 'Acesso ao chat não permitido.'], 403);
}
if ((int)($_SESSION['auth']['id_usuario'] ?? 0) <= 0) {
    out(['ok' => false, 'code' => 'NOT_AUTHENTICATED', 'user_msg' => 'Sessão expirada. Faça login novamente.'], 401);
}

require_once __DIR__ . '/../_config/conexao.php';

// 2) Contexto validado (empresa, perfil, profissional e permissões) obtido somente da sessão.
$chatCtx = chatContextoInterno($conexao);
if ($chatCtx === null || !$chatCtx['pode_visualizar']) {
    out(['ok' => false, 'code' => 'CHAT_ACCESS_DENIED', 'user_msg' => 'Você não possui acesso ao chat.'], 403);
}

$chatRota = (string)($rota ?? '');
$chatEscritas = ['chat/conversa/iniciar', 'chat/mensagem/enviar', 'chat/leitura/marcar', 'chat/conversa/encerrar'];

// 3) Escritas reutilizam o CSRF da sessão.
if (in_array($chatRota, $chatEscritas, true)) {
    require_once __DIR__ . '/../_auth/csrf.php';
    csrfValidarSessao();
}

$chatNaoEncontrada = static function (): void {
    out(['ok' => false, 'code' => 'CHAT_NOT_FOUND', 'user_msg' => 'Conversa não encontrada.'], 404);
};
$chatSemPermissao = static function (string $codigo): void {
    out(['ok' => false, 'code' => 'PERMISSION_DENIED', 'user_msg' => 'Você não possui permissão para realizar esta ação.', 'data' => ['permissao' => $codigo]], 403);
};
$chatIdConversa = static function (array $origem) use ($chatNaoEncontrada): int {
    $id = chatInteiroPositivo($origem['id_conversa'] ?? null);
    if ($id === null) {
        out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Conversa inválida.', 'field_errors' => ['id_conversa' => 'Informe uma conversa válida.']], 422);
    }
    return $id;
};
// Conversa autorizada para leitura: escopo + histórico quando encerrada (mesma resposta se não autorizada).
$chatConversaVisivel = static function (int $idConversa) use ($conexao, $chatCtx, $chatNaoEncontrada, $chatSemPermissao): array {
    $conversa = chatBuscarConversaAutorizada($conexao, $chatCtx, $idConversa);
    if ($conversa === null) $chatNaoEncontrada();
    if ((string)$conversa['status'] === 'encerrada' && !$chatCtx['pode_historico']) $chatSemPermissao('chat.visualizar_historico');
    return $conversa;
};

switch ($chatRota) {
    case 'chat/conversas/listar': {
        $status = (string)($_GET['status'] ?? 'aberta');
        if (!in_array($status, ['aberta', 'encerrada'], true)) {
            out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Situação inválida.', 'field_errors' => ['status' => 'Use aberta ou encerrada.']], 422);
        }
        if ($status === 'encerrada' && !$chatCtx['pode_historico']) $chatSemPermissao('chat.visualizar_historico');

        $filtroProfissional = null;
        $profissionalRaw = $_GET['id_profissional'] ?? '';
        if ($profissionalRaw !== '' && $profissionalRaw !== null) {
            $filtroProfissional = chatInteiroPositivo($profissionalRaw);
            if ($filtroProfissional === null) {
                out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Profissional inválido.', 'field_errors' => ['id_profissional' => 'Informe um profissional válido.']], 422);
            }
        }
        $pagina = chatInteiroPositivo($_GET['pagina'] ?? '1') ?? 1;
        $pagina = min($pagina, CHAT_PAGINA_MAX);
        $somenteNaoLidas = in_array((string)($_GET['nao_lidas'] ?? ''), ['1', 'true'], true);

        // Para Profissional o filtro é ignorado: o escopo já é o próprio id_profissional.
        $dados = chatListarConversas($conexao, $chatCtx, $status, $filtroProfissional, $somenteNaoLidas, $pagina, chatLimite($_GET['limite'] ?? null));
        out(['ok' => true, 'code' => 'CHAT_CONVERSATIONS_LISTED', 'data' => $dados]);
    }

    case 'chat/conversas/nao-lidas': {
        out(['ok' => true, 'code' => 'CHAT_UNREAD_COUNTED', 'data' => chatContarNaoLidas($conexao, $chatCtx)]);
    }

    case 'chat/conversa/abrir': {
        $idConversa = $chatIdConversa($_GET);
        $conversa = $chatConversaVisivel($idConversa);
        $mensagens = chatListarMensagens($conexao, $chatCtx, $idConversa, null, null, chatLimite($_GET['limite'] ?? null));
        out(['ok' => true, 'code' => 'CHAT_CONVERSATION_OPENED', 'data' => [
            'conversa' => chatFormatarConversa($conversa, $chatCtx),
            'mensagens' => $mensagens['itens'],
            'tem_mais_anteriores' => $mensagens['tem_mais'],
        ]]);
    }

    case 'chat/mensagens/listar': {
        $idConversa = $chatIdConversa($_GET);
        $afterRaw = $_GET['after_id'] ?? null;
        $beforeRaw = $_GET['before_id'] ?? null;
        $afterId = null;
        $beforeId = null;
        if ($afterRaw !== null && $afterRaw !== '') {
            $afterId = (is_string($afterRaw) && trim($afterRaw) === '0') ? 0 : chatInteiroPositivo($afterRaw);
            if ($afterId === null) out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Parâmetro after_id inválido.'], 422);
        }
        if ($beforeRaw !== null && $beforeRaw !== '') {
            $beforeId = chatInteiroPositivo($beforeRaw);
            if ($beforeId === null) out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Parâmetro before_id inválido.'], 422);
        }
        if ($afterId !== null && $beforeId !== null) {
            out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Use after_id ou before_id, não ambos.'], 422);
        }
        $conversa = $chatConversaVisivel($idConversa);
        $mensagens = chatListarMensagens($conexao, $chatCtx, $idConversa, $afterId, $beforeId, chatLimite($_GET['limite'] ?? null));
        out(['ok' => true, 'code' => 'CHAT_MESSAGES_LISTED', 'data' => [
            'id_conversa' => $idConversa,
            'status' => (string)$conversa['status'],
            'mensagens' => $mensagens['itens'],
            'tem_mais' => $mensagens['tem_mais'],
        ]]);
    }

    case 'chat/conversa/iniciar': {
        if (!$chatCtx['pode_responder']) $chatSemPermissao('chat.responder');
        $idAgendamento = chatInteiroPositivo($_POST['id_agendamento'] ?? null);
        if ($idAgendamento === null) {
            out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Agendamento inválido.', 'field_errors' => ['id_agendamento' => 'Informe um agendamento válido.']], 422);
        }
        try {
            $conexao->begin_transaction();
            $resultado = chatIniciarConversaPorAgendamento($conexao, $chatCtx, $idAgendamento);
            if ($resultado['resultado'] === 'nao_encontrado') {
                $conexao->rollback();
                out(['ok' => false, 'code' => 'CHAT_APPOINTMENT_NOT_FOUND', 'user_msg' => 'Agendamento não encontrado.'], 404);
            }
            if ($resultado['resultado'] === 'status_invalido') {
                $conexao->rollback();
                out(['ok' => false, 'code' => 'CHAT_APPOINTMENT_STATUS_INVALID', 'user_msg' => 'Só é possível iniciar conversa em agendamento pendente ou confirmado.'], 409);
            }
            $conversa = chatBuscarConversaAutorizada($conexao, $chatCtx, (int)$resultado['id_conversa']);
            if ($conversa === null) throw new RuntimeException('Conversa criada sem escopo autorizado.');
            if ((string)$conversa['status'] === 'encerrada' && !$chatCtx['pode_historico']) {
                $conexao->rollback();
                $chatSemPermissao('chat.visualizar_historico');
            }
            if (!$conexao->commit()) throw new RuntimeException('Falha ao confirmar a criação da conversa.');
        } catch (Throwable $e) {
            try { $conexao->rollback(); } catch (Throwable) {}
            error_log('[chat/conversa/iniciar] ' . $e->getMessage());
            out(['ok' => false, 'code' => 'INTERNAL_ERROR', 'user_msg' => 'Não foi possível iniciar a conversa.'], 500);
        }
        $criada = $resultado['resultado'] === 'criada';
        out(['ok' => true, 'code' => $criada ? 'CHAT_CONVERSATION_CREATED' : 'CHAT_CONVERSATION_EXISTS', 'data' => [
            'criada' => $criada,
            'conversa' => chatFormatarConversa($conversa, $chatCtx),
        ]], $criada ? 201 : 200);
    }

    case 'chat/mensagem/enviar': {
        if (!$chatCtx['pode_responder']) $chatSemPermissao('chat.responder');
        $idConversa = $chatIdConversa($_POST);
        $conteudo = chatNormalizarConteudo($_POST['conteudo'] ?? null);
        if ($conteudo === null) {
            out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Escreva uma mensagem de até ' . CHAT_CONTEUDO_MAX . ' caracteres.', 'field_errors' => ['conteudo' => 'Mensagem vazia, inválida ou acima do limite.']], 422);
        }
        $chaveRaw = $_POST['chave_idempotencia'] ?? null;
        $chave = null;
        if ($chaveRaw !== null && $chaveRaw !== '') {
            $chave = chatNormalizarChaveIdempotencia($chaveRaw);
            if ($chave === null) {
                out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Identificador de envio inválido.', 'field_errors' => ['chave_idempotencia' => 'Use um UUID válido.']], 422);
            }
        }

        $resultado = chatEnviarMensagemInterna($conexao, $chatCtx, $idConversa, $conteudo, $chave);
        switch ($resultado['resultado']) {
            case 'nao_encontrada':
                $chatNaoEncontrada();
            case 'encerrada':
                out(['ok' => false, 'code' => 'CHAT_CLOSED', 'user_msg' => 'Esta conversa foi encerrada e não aceita novas mensagens.'], 409);
            case 'chave_em_uso':
                out(['ok' => false, 'code' => 'CHAT_IDEMPOTENCY_CONFLICT', 'user_msg' => 'Identificador de envio já utilizado.'], 409);
            case 'idempotente':
                out(['ok' => true, 'code' => 'CHAT_MESSAGE_ALREADY_SENT', 'data' => ['idempotente' => true, 'mensagem' => $resultado['mensagem']]]);
            default:
                out(['ok' => true, 'code' => 'CHAT_MESSAGE_SENT', 'data' => ['idempotente' => false, 'mensagem' => $resultado['mensagem']]], 201);
        }
    }

    case 'chat/leitura/marcar': {
        $idConversa = $chatIdConversa($_POST);
        $idMensagem = chatInteiroPositivo($_POST['id_ultima_mensagem_lida'] ?? null);
        if ($idMensagem === null) {
            out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Mensagem inválida.', 'field_errors' => ['id_ultima_mensagem_lida' => 'Informe uma mensagem válida.']], 422);
        }
        $chatConversaVisivel($idConversa);
        $resultado = chatMarcarLeitura($conexao, $chatCtx, $idConversa, $idMensagem);
        if (!$resultado['ok']) {
            out(['ok' => false, 'code' => 'CHAT_MESSAGE_NOT_FOUND', 'user_msg' => 'Mensagem não encontrada nesta conversa.'], 404);
        }
        out(['ok' => true, 'code' => 'CHAT_READ_MARKED', 'data' => [
            'id_conversa' => $idConversa,
            'id_ultima_mensagem_lida' => $resultado['id_ultima_mensagem_lida'],
        ]]);
    }

    case 'chat/conversa/encerrar': {
        if (!$chatCtx['pode_encerrar']) $chatSemPermissao('chat.encerrar');
        $idConversa = $chatIdConversa($_POST);
        // Auditoria do encerramento pendente: auditoria.chk_auditoria_modulo ainda não aceita 'chat'.
        $resultado = chatEncerrarConversa($conexao, $chatCtx, $idConversa);
        if ($resultado === 'nao_encontrada') $chatNaoEncontrada();
        if ($resultado === 'ja_encerrada') {
            out(['ok' => false, 'code' => 'CHAT_ALREADY_CLOSED', 'user_msg' => 'Esta conversa já está encerrada.'], 409);
        }
        out(['ok' => true, 'code' => 'CHAT_CONVERSATION_CLOSED', 'data' => ['id_conversa' => $idConversa, 'status' => 'encerrada']]);
    }

    default:
        out(['ok' => false, 'code' => 'ROUTE_NOT_FOUND', 'user_msg' => 'Rota não encontrada.'], 404);
}
