<?php
declare(strict_types=1);

require_once __DIR__ . '/_comum.php';
require_once __DIR__ . '/../_regras/limites_plano.php';
require_once __DIR__ . '/../_servicos/chat.php';

$nomeLock = null;
$transacao = false;

try {
    clienteAgendamentoMetodo('POST');
    $contexto = clienteAgendamentoContexto($conexao, true);
    clienteAgendamentoValidarCsrf();

    $idEmpresa = (int)$contexto['id_empresa'];
    $idCliente = (int)$contexto['cliente']['id_cliente'];
    $idProfissional = clienteAgendamentoId($_POST['id_profissional'] ?? null);
    $idServico = clienteAgendamentoId($_POST['id_servico'] ?? null);
    $dataTexto = trim((string)($_POST['data'] ?? ''));
    $horaTexto = trim((string)($_POST['hora'] ?? ''));
    $observacao = trim((string)($_POST['observacao'] ?? ''));
    $data = clienteAgendamentoData($dataTexto);
    $campos = [];

    if ($idProfissional <= 0) $campos['id_profissional'] = 'Selecione um profissional válido.';
    if ($idServico <= 0) $campos['id_servico'] = 'Selecione um serviço válido.';
    if ($data === null) $campos['data'] = 'Selecione uma data válida.';
    if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $horaTexto) !== 1) $campos['hora'] = 'Selecione um horário válido.';
    if (mb_strlen($observacao) > 220) $campos['observacao'] = 'A observação deve ter no máximo 220 caracteres.';
    if ($campos) {
        out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Revise os dados do agendamento.', 'fields' => $campos], 422);
    }

    $inicioSolicitado = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $dataTexto . ' ' . $horaTexto);
    if (!$inicioSolicitado || $inicioSolicitado <= new DateTimeImmutable('now')) {
        out(['ok' => false, 'code' => 'PAST_START_TIME', 'user_msg' => 'Selecione um horário que ainda não tenha começado.'], 422);
    }

    $nomeLock = sprintf('amagenda_cliente_%d_%d_%s', $idEmpresa, $idProfissional, $dataTexto);
    $stmtLock = $conexao->prepare('SELECT GET_LOCK(?, 5)');
    if (!$stmtLock) {
        throw new RuntimeException('Falha ao preparar a proteção concorrente.');
    }
    $stmtLock->bind_param('s', $nomeLock);
    $stmtLock->execute();
    $stmtLock->bind_result($lockObtido);
    $stmtLock->fetch();
    $stmtLock->close();
    if ((int)$lockObtido !== 1) {
        $nomeLock = null;
        out(['ok' => false, 'code' => 'SCHEDULE_BUSY', 'user_msg' => 'Este horário está sendo confirmado por outra pessoa. Tente novamente.'], 409);
    }

    $conexao->begin_transaction();
    $transacao = true;

    $resultadoPlano = limitesPlanoBloquearEmpresa($conexao, $idEmpresa);
    if (($resultadoPlano['ok'] ?? false) !== true) {
        $conexao->rollback();
        $transacao = false;
        clienteAgendamentoLiberarLock($conexao, $nomeLock);
        $nomeLock = null;
        $status = (int)($resultadoPlano['http_status'] ?? 409);
        unset($resultadoPlano['http_status']);
        out($resultadoPlano, $status);
    }

    $stmtCliente = $conexao->prepare("SELECT id_cliente FROM cliente WHERE id_cliente = ? AND id_empresa = ? AND status = 'ativo' AND cadastro_completo = 1 LIMIT 1 FOR UPDATE");
    if (!$stmtCliente) throw new RuntimeException('Falha ao revalidar o cliente.');
    $stmtCliente->bind_param('ii', $idCliente, $idEmpresa);
    $stmtCliente->execute();
    $stmtCliente->store_result();
    $clienteValido = $stmtCliente->num_rows === 1;
    $stmtCliente->close();
    if (!$clienteValido) {
        $conexao->rollback(); $transacao = false;
        clienteAgendamentoLiberarLock($conexao, $nomeLock); $nomeLock = null;
        out(['ok' => false, 'code' => 'CLIENT_NOT_AVAILABLE', 'user_msg' => 'Seu cadastro não está disponível para agendamento.'], 403);
    }

    $servico = clienteAgendamentoServico($conexao, $idEmpresa, $idProfissional, $idServico, true);
    if ($servico === null) {
        $conexao->rollback(); $transacao = false;
        clienteAgendamentoLiberarLock($conexao, $nomeLock); $nomeLock = null;
        out(['ok' => false, 'code' => 'SERVICE_NOT_FOUND', 'user_msg' => 'O serviço ou profissional não está mais disponível.'], 409);
    }

    $resultadoLimite = limitesPlanoVerificarAgendamentosPorMes($conexao, $resultadoPlano['plano'], $idEmpresa, [$dataTexto]);
    if (($resultadoLimite['ok'] ?? false) !== true) {
        $conexao->rollback(); $transacao = false;
        clienteAgendamentoLiberarLock($conexao, $nomeLock); $nomeLock = null;
        $status = (int)($resultadoLimite['http_status'] ?? 409);
        unset($resultadoLimite['http_status']);
        out($resultadoLimite, $status);
    }

    $horarios = clienteAgendamentoHorarios($conexao, $idEmpresa, $servico, $data, true);
    $horarioValido = null;
    foreach ($horarios as $horario) {
        if ($horario['hora_inicio'] === $horaTexto) {
            $horarioValido = $horario;
            break;
        }
    }
    if ($horarioValido === null) {
        $conexao->rollback(); $transacao = false;
        clienteAgendamentoLiberarLock($conexao, $nomeLock); $nomeLock = null;
        out(['ok' => false, 'code' => 'SCHEDULE_CONFLICT', 'user_msg' => 'Este horário acabou de ser ocupado ou não está mais disponível.'], 409);
    }

    $horaInicio = $horarioValido['hora_inicio'] . ':00';
    $horaFim = $horarioValido['hora_fim'] . ':00';
    $duracao = (int)$servico['duracao_min'];
    $valor = (float)$servico['valor'];
    $observacaoDb = $observacao !== '' ? $observacao : null;
    $status = 'pendente';
    $stmt = $conexao->prepare(
        'INSERT INTO agendamento
         (id_empresa, id_cliente, id_profissional, id_servico, data_agendamento,
          hora_inicio, hora_fim, duracao_min_aplicada, valor_aplicado, status,
          observacao, repetir_semanalmente, recorrencia_data_fim, grupo_recorrencia, criado_por)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, NULL, NULL)'
    );
    if (!$stmt) throw new RuntimeException('Falha ao preparar o agendamento.');
    $stmt->bind_param('iiiisssidss', $idEmpresa, $idCliente, $idProfissional, $idServico, $dataTexto, $horaInicio, $horaFim, $duracao, $valor, $status, $observacaoDb);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Falha ao gravar o agendamento.');
    }
    $idAgendamento = (int)$conexao->insert_id;
    $stmt->close();

    $stmt = $conexao->prepare('UPDATE cliente SET ultimo_agendamento_em = CURRENT_TIMESTAMP, ultima_movimentacao_em = CURRENT_TIMESTAMP, total_agendamentos = total_agendamentos + 1 WHERE id_cliente = ? AND id_empresa = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Falha ao preparar a atualização do cliente.');
    $stmt->bind_param('ii', $idCliente, $idEmpresa);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Falha ao atualizar os dados do cliente.');
    }
    $stmt->close();

    $conexao->commit();
    $transacao = false;
    clienteAgendamentoLiberarLock($conexao, $nomeLock);
    $nomeLock = null;

    // Chat 5B-3: mensagem automática "agendamento_solicitado" SOMENTE após o commit e a liberação
    // do lock. Transação própria e curta; qualquer falha é isolada aqui (log sem dados pessoais):
    // o agendamento já está gravado e a resposta continua sendo sucesso.
    $chatEmTransacao = false;
    try {
        $conexao->begin_transaction();
        $chatEmTransacao = true;
        // Relê e trava o agendamento (empresa + id); não confia nos valores em memória.
        $agendamentoChat = chatBuscarAgendamentoParaAtualizar($conexao, $idEmpresa, $idAgendamento);
        $profissionalChat = null;
        if ($agendamentoChat !== null
            && (int)$agendamentoChat['id_cliente'] === $idCliente
            && (string)$agendamentoChat['status'] === 'pendente'
            && (int)$agendamentoChat['id_profissional'] > 0) {
            $profissionalChat = chatConsultar(
                $conexao,
                'SELECT u.nome
                   FROM profissional p
                  INNER JOIN usuario u ON u.id_usuario = p.id_usuario
                  INNER JOIN empresa_usuario eu ON eu.id_usuario = p.id_usuario AND eu.id_empresa = ?
                  WHERE p.id_profissional = ?
                  LIMIT 1',
                'ii',
                [$idEmpresa, (int)$agendamentoChat['id_profissional']]
            )[0] ?? null;
        }
        $nomeProfissionalChat = trim((string)($profissionalChat['nome'] ?? ''));
        $dataChat = $agendamentoChat !== null ? DateTimeImmutable::createFromFormat('!Y-m-d', (string)$agendamentoChat['data_agendamento']) : false;
        $horaChat = $agendamentoChat !== null ? substr((string)$agendamentoChat['hora_inicio'], 0, 5) : '';

        if ($nomeProfissionalChat === '' || $dataChat === false || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $horaChat) !== 1) {
            // Não está mais pendente, cliente/profissional divergente ou dados inválidos: nada a gravar.
            $conexao->rollback();
            $chatEmTransacao = false;
        } else {
            $conversaChat = chatObterOuCriarConversaAgendamento($conexao, $idEmpresa, $agendamentoChat, ['tipo' => 'cliente']);
            if (in_array($conversaChat['resultado'], ['criada', 'existente'], true) && !empty($conversaChat['id_conversa'])) {
                if ($conversaChat['resultado'] === 'criada') {
                    chatAuditarConversaCriada(
                        $conexao,
                        (int)$conversaChat['id_conversa'],
                        $idAgendamento,
                        'agendamento_solicitado',
                        chatAtorAuditoriaCliente([
                            'id_empresa' => $idEmpresa,
                            'id_cliente' => $idCliente,
                            'nome_cliente' => (string)($contexto['cliente']['nome_completo'] ?? ''),
                        ])
                    );
                }
                chatRegistrarMensagemSistema(
                    $conexao,
                    $idEmpresa,
                    (int)$conversaChat['id_conversa'],
                    'Recebemos sua solicitação de agendamento para ' . $dataChat->format('d/m/Y') . ' às ' . $horaChat
                        . ' com ' . $nomeProfissionalChat . '. Você receberá a confirmação por aqui assim que o profissional confirmar.',
                    chatNomeExibicaoSistema($conexao, $idEmpresa),
                    chatChaveIdempotenciaSistema('agendamento_solicitado', $idEmpresa, $idAgendamento, $dataChat->format('Y-m-d'), $horaChat),
                    true // evento originado pelo próprio cliente: nasce lida (sem pular respostas anteriores não lidas)
                );
                $conexao->commit();
            } else {
                $conexao->rollback();
            }
            $chatEmTransacao = false;
        }
    } catch (Throwable $erroChat) {
        if ($chatEmTransacao) {
            try { $conexao->rollback(); } catch (Throwable $ignorado) {}
        }
        error_log('[cliente_agendamento_confirmar] chat evento=agendamento_solicitado empresa=' . $idEmpresa
            . ' agendamento=' . $idAgendamento . ' erro=' . get_class($erroChat) . ': ' . $erroChat->getMessage());
    }

    out(['ok' => true, 'code' => 'CLIENT_APPOINTMENT_CREATED', 'user_msg' => 'Solicitação enviada com sucesso. O agendamento está pendente de confirmação do profissional.', 'data' => [
        'id_agendamento' => $idAgendamento,
        'status' => $status,
        'data' => $dataTexto,
        'hora_inicio' => substr($horaInicio, 0, 5),
        'hora_fim' => substr($horaFim, 0, 5),
    ]], 201);
} catch (Throwable $e) {
    if ($transacao) {
        try { $conexao->rollback(); } catch (Throwable $ignorado) {}
    }
    clienteAgendamentoLiberarLock($conexao, $nomeLock);
    error_log('[cliente_agendamento_confirmar] ' . $e->getMessage());
    out(['ok' => false, 'code' => 'CLIENT_APPOINTMENT_CREATE_ERROR', 'user_msg' => 'Não foi possível concluir o agendamento agora.'], 500);
}
