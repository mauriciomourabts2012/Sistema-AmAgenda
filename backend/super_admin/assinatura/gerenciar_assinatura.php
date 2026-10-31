<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
date_default_timezone_set('America/Sao_Paulo');

if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function assinaturaErroTransacional(mysqli $conexao, array $payload, int $code): void
{
    try {
        $conexao->rollback();
    } catch (Throwable) {
    }

    out($payload, $code);
}

function assinaturaIdEntrada(array $origem, string $campo): ?int
{
    $valor = $origem[$campo] ?? null;
    if (!is_scalar($valor) || preg_match('/^[1-9]\d*$/', trim((string)$valor)) !== 1) {
        return null;
    }

    return (int)$valor;
}

function assinaturaAlteracoes(array $dados): array
{
    $resultado = [];
    foreach ($dados as $campo => $valor) {
        $resultado[$campo] = ['antes' => null, 'depois' => $valor];
    }

    return $resultado;
}

function assinaturaListar(mysqli $conexao): void
{
    $idEmpresa = assinaturaIdEntrada($_GET, 'id_empresa');
    $sql = "SELECT
                a.id_assinatura,
                a.id_empresa,
                e.nome AS empresa_nome,
                a.id_plano,
                p.nome AS plano_nome,
                a.valor_contratado,
                a.periodicidade,
                a.dia_vencimento,
                DATE_FORMAT(a.data_inicio, '%Y-%m-%d') AS data_inicio,
                DATE_FORMAT(a.data_fim, '%Y-%m-%d') AS data_fim,
                a.status,
                a.modalidade,
                DATE_FORMAT(a.teste_iniciado_em, '%Y-%m-%d %H:%i:%s') AS teste_iniciado_em,
                DATE_FORMAT(a.teste_expira_em, '%Y-%m-%d %H:%i:%s') AS teste_expira_em,
                a.motivo_suspensao,
                DATE_FORMAT(a.suspensa_em, '%Y-%m-%d %H:%i:%s') AS suspensa_em
            FROM assinatura a
            INNER JOIN empresa e ON e.id_empresa = a.id_empresa
            LEFT JOIN plano p ON p.id_plano = a.id_plano";

    if ($idEmpresa !== null) {
        $sql .= ' WHERE a.id_empresa = ?';
    }

    $sql .= " ORDER BY e.nome ASC,
                       CASE a.status
                           WHEN 'ativa' THEN 1
                           WHEN 'suspensa' THEN 2
                           WHEN 'cancelada' THEN 3
                           WHEN 'encerrada' THEN 4
                           ELSE 5
                       END,
                       a.data_inicio DESC,
                       a.id_assinatura DESC";

    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar a listagem de assinaturas.');
    }

    if ($idEmpresa !== null) {
        $stmt->bind_param('i', $idEmpresa);
    }

    if (!$stmt->execute()) {
        $erro = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Falha ao listar assinaturas: ' . $erro);
    }

    $resultado = $stmt->get_result();
    $items = [];
    while ($linha = $resultado->fetch_assoc()) {
        $items[] = [
            'id_assinatura' => (int)$linha['id_assinatura'],
            'id_empresa' => (int)$linha['id_empresa'],
            'empresa_nome' => (string)$linha['empresa_nome'],
            'id_plano' => (int)$linha['id_plano'],
            'plano_nome' => $linha['plano_nome'] === null ? '' : (string)$linha['plano_nome'],
            'valor_contratado' => (float)$linha['valor_contratado'],
            'periodicidade' => (string)$linha['periodicidade'],
            'dia_vencimento' => (int)$linha['dia_vencimento'],
            'data_inicio' => (string)$linha['data_inicio'],
            'data_fim' => $linha['data_fim'] === null ? null : (string)$linha['data_fim'],
            'status' => (string)$linha['status'],
            'modalidade' => (string)$linha['modalidade'],
            'teste_iniciado_em' => $linha['teste_iniciado_em'] === null ? null : (string)$linha['teste_iniciado_em'],
            'teste_expira_em' => $linha['teste_expira_em'] === null ? null : (string)$linha['teste_expira_em'],
            'motivo_suspensao' => $linha['motivo_suspensao'] === null ? null : (string)$linha['motivo_suspensao'],
            'suspensa_em' => $linha['suspensa_em'] === null ? null : (string)$linha['suspensa_em'],
        ];
    }
    $stmt->close();

    // Situação da recorrência no cartão (só leitura) para a ação "Cancelar pagamento automático"; sem ela a ação não aparece.
    try {
        require_once __DIR__ . '/../../_servicos/cancelamento_recorrencia.php';
        $recorrencias = cancelamentoRecorrenciaResumoPorAssinaturas($conexao, array_column($items, 'id_assinatura'));
    } catch (Throwable $erro) {
        error_log('LISTA_ASSINATURA_RECORRENCIA: ' . get_class($erro));
        $recorrencias = [];
    }
    foreach ($items as &$item) {
        $item['recorrencia'] = $recorrencias[$item['id_assinatura']] ?? null;
    }
    unset($item);

    out([
        'ok' => true,
        'code' => 'ASSINATURAS_LISTADAS',
        'user_msg' => 'Assinaturas listadas com sucesso.',
        'data' => ['items' => $items],
    ]);
}

function assinaturaPreviaCancelamento(mysqli $conexao): void
{
    $idAssinatura = assinaturaIdEntrada($_GET, 'id_assinatura');
    $idEmpresa = assinaturaIdEntrada($_GET, 'id_empresa');
    if ($idAssinatura === null || $idEmpresa === null) {
        out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Dados da assinatura inválidos.'], 422);
    }

    $previa = assinaturaServicoPreviaCancelamento($conexao, $idEmpresa, $idAssinatura);
    out([
        'ok' => true,
        'code' => 'CANCELAMENTO_PREVIA_CONSULTADA',
        'user_msg' => 'Prévia de cancelamento consultada.',
        'data' => [
            'id_assinatura' => $idAssinatura,
            'id_empresa' => $idEmpresa,
            ...$previa,
        ],
    ]);
}

/**
 * Validação sem efeitos do cancelamento, antes de tocar a recorrência externa: se o cancelamento comercial já é inviável,
 * a Preapproval não é cancelada. A transação principal repete as validações sob lock.
 *
 * @return array{0: string, 1: string, 2: int}|null erro (código, mensagem, HTTP) ou null quando válido
 */
function assinaturaPreValidarCancelamento(mysqli $conexao, int $idEmpresa, int $idAssinatura, string $modalidade): ?array
{
    $a = assinaturaServicoConsultaFinanceira($conexao,
        'SELECT id_empresa, status, modalidade, data_fim FROM assinatura WHERE id_assinatura = ? LIMIT 1', 'i', $idAssinatura)[0] ?? null;
    if ($a === null || (int)$a['id_empresa'] !== $idEmpresa) {
        return ['ASSINATURA_NAO_ENCONTRADA', 'Assinatura não encontrada para esta empresa.', 404];
    }
    if ($a['data_fim'] !== null) {
        return ['CANCELAMENTO_JA_REGISTRADO', 'Esta assinatura já tem uma data de encerramento. Atualize a lista.', 409];
    }
    if (!in_array((string)$a['status'], ['ativa', 'suspensa'], true)) {
        return ['TRANSICAO_INVALIDA', 'A transição de status da assinatura não é permitida.', 409];
    }
    if ($modalidade === 'fim_periodo_pago') {
        if ((string)$a['status'] !== 'ativa') {
            return ['CANCELAMENTO_PROGRAMADO_INDISPONIVEL', 'O cancelamento ao fim do período pago exige assinatura ativa.', 409];
        }
        if ((string)$a['modalidade'] !== 'paga' || assinaturaServicoFimPeriodoPago($conexao, $idEmpresa, $idAssinatura) === null) {
            return ['SEM_PERIODO_PAGO', 'Não há período integralmente pago que inclua a data atual.', 409];
        }
    }

    return null;
}

/**
 * "Cancelar pagamento automático" no painel do Super Admin: só a Preapproval; o contrato não muda. Acesso já restrito ao
 * Super Admin autenticado (bloquear.php) e fora do modo suporte (ator auditável); CSRF validado por quem chama.
 */
function assinaturaCancelarRecorrencia(mysqli $conexao, int $idEmpresa, int $idAssinatura): void
{
    require_once __DIR__ . '/../../_servicos/cancelamento_recorrencia.php';
    // A assinatura precisa pertencer à empresa informada; nada é inferido do vínculo.
    $dono = assinaturaServicoConsultaFinanceira($conexao, 'SELECT id_empresa FROM assinatura WHERE id_assinatura = ? LIMIT 1', 'i', $idAssinatura)[0] ?? null;
    if ($dono === null || (int)$dono['id_empresa'] !== $idEmpresa) {
        out(['ok' => false, 'code' => 'ASSINATURA_NAO_ENCONTRADA', 'user_msg' => 'Assinatura não encontrada para esta empresa.'], 404);
    }
    // Mesma trava por empresa da troca de cartão, do botão do proprietário e do cancelamento comercial.
    if (!cancelamentoRecorrenciaObterTrava($conexao, $idEmpresa)) {
        out(['ok' => false, 'code' => 'OPERACAO_CARTAO_EM_ANDAMENTO', 'user_msg' => 'Já existe uma operação no pagamento automático em andamento. Aguarde a conclusão.'], 409);
    }
    register_shutdown_function(static fn() => cancelamentoRecorrenciaLiberarTrava($conexao, $idEmpresa));
    try {
        $ator = auditoriaResolverAtorSuperAdmin($conexao, $idEmpresa);
        $gateway = cancelamentoRecorrenciaCarregarGateway();
        // Mesmo serviço do proprietário (inclui vínculo 'pendente' autorizado e a restauração do estado após recusa); o
        // administrador também pode encerrar um checkout ainda `pending` no provedor, para não travar o cancelamento comercial.
        $r = cancelamentoRecorrenciaCancelarPreapproval($conexao, $gateway, $idEmpresa, $idAssinatura, $ator, true);
    } catch (Throwable $erro) {
        error_log('GESTAO_ASSINATURA_CANCELAR_RECORRENCIA: ' . get_class($erro));
        out(['ok' => false, 'code' => 'CANCELAMENTO_AUTOMATICO_ERRO', 'user_msg' => CANCELAMENTO_RECORRENCIA_MSG_FALHA_ADMIN], 503);
    }
    out(['ok' => in_array($r['desfecho'], ['cancelado', 'pendente'], true), 'code' => $r['code'], 'user_msg' => $r['msg'],
        'data' => ['id_assinatura' => $idAssinatura, 'id_empresa' => $idEmpresa, 'desfecho' => $r['desfecho']]], $r['http']);
}

/**
 * Resolução administrativa de um cadastro de cartão com criação incerta (Super Admin autenticado, fora do modo suporte;
 * CSRF validado por quem chama). Fase 1 (sem `confirmo`): devolve a evidência externa somente leitura. A liberação exige
 * sempre a conferência independente do Super Admin: `fonte_evidencia`, `conferido_em` (AAAA-MM-DD HH:MM, horário de
 * Brasília) e `atestado` (identificação da evidência consultada). Fase 2: `confirmo=1` e `confirmar_empresa` igual ao id
 * da empresa. Trava por empresa; nada é criado nem cancelado no Mercado Pago; contrato e teste grátis não mudam.
 */
function assinaturaResolverCriacaoCartao(mysqli $conexao, int $idEmpresa, int $idAssinatura): void
{
    $dono = assinaturaServicoConsultaFinanceira($conexao, 'SELECT id_empresa FROM assinatura WHERE id_assinatura = ? LIMIT 1', 'i', $idAssinatura)[0] ?? null;
    if ($dono === null || (int)$dono['id_empresa'] !== $idEmpresa) {
        out(['ok' => false, 'code' => 'ASSINATURA_NAO_ENCONTRADA', 'user_msg' => 'Assinatura não encontrada para esta empresa.'], 404);
    }
    $confirmo = ($_POST['confirmo'] ?? '') === '1';
    if ($confirmo && assinaturaIdEntrada($_POST, 'confirmar_empresa') !== $idEmpresa) {
        out(['ok' => false, 'code' => 'CONFIRMACAO_EMPRESA_INVALIDA', 'user_msg' => 'Informe o número da empresa para confirmar.'], 422);
    }
    $atestado = is_scalar($_POST['atestado'] ?? null) ? trim((string)$_POST['atestado']) : '';
    if (strlen($atestado) > 300) {
        out(['ok' => false, 'code' => 'ATESTADO_INVALIDO', 'user_msg' => 'O registro da evidência deve ter até 300 caracteres.'], 422);
    }
    $fonte = is_scalar($_POST['fonte_evidencia'] ?? null) ? trim((string)$_POST['fonte_evidencia']) : '';
    // Aceita o formato do campo datetime-local (AAAA-MM-DDTHH:MM); o serviço valida a data contra a tentativa e o agora.
    $conferidoEm = is_scalar($_POST['conferido_em'] ?? null) ? str_replace('T', ' ', trim((string)$_POST['conferido_em'])) : '';
    $conferencia = ($atestado === '' && $fonte === '' && $conferidoEm === '')
        ? null : ['fonte' => $fonte, 'conferido_em' => $conferidoEm, 'descricao' => $atestado];
    // Evidência coletada pelo servidor no diagnóstico, guardada na sessão deste Super Admin; o navegador só conhece o token.
    // O serviço só a aceita para a mesma empresa, assinatura, operação e referência, e por tempo limitado.
    $token = is_scalar($_POST['evidencia_token'] ?? null) ? (string)$_POST['evidencia_token'] : '';
    $guardadas = is_array($_SESSION['resolver_cartao_evidencia'] ?? null) ? $_SESSION['resolver_cartao_evidencia'] : [];
    $evidenciaAnterior = preg_match('/^[0-9a-f]{32}$/D', $token) === 1 && is_array($guardadas[$token] ?? null) ? $guardadas[$token] : null;
    require_once __DIR__ . '/../../_servicos/cancelamento_recorrencia.php';
    // Mesma trava por empresa de troca de cartão, cancelamento e demais operações do pagamento automático.
    if (!cancelamentoRecorrenciaObterTrava($conexao, $idEmpresa)) {
        out(['ok' => false, 'code' => 'OPERACAO_CARTAO_EM_ANDAMENTO', 'user_msg' => 'Já existe uma operação no pagamento automático em andamento. Aguarde a conclusão.'], 409);
    }
    register_shutdown_function(static fn() => cancelamentoRecorrenciaLiberarTrava($conexao, $idEmpresa));
    try {
        $ator = auditoriaResolverAtorSuperAdmin($conexao, $idEmpresa);
        $gateway = cancelamentoRecorrenciaCarregarGateway();
        $r = assinaturaServicoPreapprovalResolverCriacaoIncerta($conexao, $gateway, $idEmpresa, $idAssinatura, $ator, $confirmo, $conferencia, $evidenciaAnterior);
    } catch (Throwable $erro) {
        // Causa técnica no log (classe, código e mensagem curta, sem dados do provedor) para o suporte; o usuário vê a neutra.
        error_log('GESTAO_ASSINATURA_RESOLVER_CRIACAO_CARTAO: ' . get_class($erro) . ' codigo=' . (int)$erro->getCode()
            . ' ' . mb_substr(preg_replace('/\s+/', ' ', $erro->getMessage()) ?? '', 0, 200));
        out(['ok' => false, 'code' => 'RESOLVER_CRIACAO_ERRO', 'user_msg' => 'Não foi possível concluir a verificação agora. Nada foi alterado.'], 503);
    }
    // Guarda só a evidência conclusiva mais recente desta empresa; concluída a resolução, ela é descartada.
    foreach ($guardadas as $chave => $item) {
        if (!is_array($item) || (int)($item['id_empresa'] ?? 0) === $idEmpresa
            || time() - (int)($item['coletada_em'] ?? 0) > ASSINATURA_SERVICO_PREAPPROVAL_EVIDENCIA_REUSO_SEGUNDOS) {
            unset($guardadas[$chave]);
        }
    }
    $tokenNovo = null;
    if ($r['ok'] !== true && is_array($r['evidencia_registro'] ?? null)) {
        $tokenNovo = $evidenciaAnterior !== null && $r['evidencia_registro'] === $evidenciaAnterior ? $token : bin2hex(random_bytes(16));
        $guardadas[$tokenNovo] = $r['evidencia_registro'];
    }
    $_SESSION['resolver_cartao_evidencia'] = $guardadas;
    $dados = ['id_assinatura' => $idAssinatura, 'id_empresa' => $idEmpresa, 'evidencia' => $r['evidencia'] ?? null, 'contexto' => $r['contexto'] ?? null,
        'evidencia_token' => $tokenNovo, 'referencia_nova' => $r['referencia_nova'] ?? null,
        'atestado_necessario' => ($r['atestado_necessario'] ?? false) === true, 'atestado_motivo' => $r['atestado_motivo'] ?? null];
    out(['ok' => $r['ok'] === true, 'code' => (string)$r['codigo'], 'user_msg' => (string)$r['mensagem'], 'data' => $dados], $r['ok'] === true ? 200 : (int)($r['http_status'] ?? 409));
}

function assinaturaAlterarStatus(mysqli $conexao): void
{
    require_once __DIR__ . '/../../_auth/csrf.php';
    csrfValidarSessao();

    $idAssinatura = assinaturaIdEntrada($_POST, 'id_assinatura');
    $idEmpresaInformada = assinaturaIdEntrada($_POST, 'id_empresa');
    $acao = is_scalar($_POST['acao'] ?? null) ? strtolower(trim((string)$_POST['acao'])) : '';
    $motivo = is_scalar($_POST['motivo'] ?? null) ? trim((string)$_POST['motivo']) : '';
    $modalidadeCancelamento = is_scalar($_POST['modalidade_cancelamento'] ?? null)
        ? trim((string)$_POST['modalidade_cancelamento']) : '';

    if ($idAssinatura === null || $idEmpresaInformada === null || !in_array($acao, ['suspender', 'reativar', 'cancelar', 'cancelar_recorrencia', 'resolver_criacao_cartao'], true)) {
        out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Dados da assinatura inválidos.'], 422);
    }
    if ($acao === 'cancelar_recorrencia') {
        assinaturaCancelarRecorrencia($conexao, $idEmpresaInformada, $idAssinatura);
        return;
    }
    if ($acao === 'resolver_criacao_cartao') {
        assinaturaResolverCriacaoCartao($conexao, $idEmpresaInformada, $idAssinatura);
        return;
    }

    if ($acao === 'cancelar' && ($motivo === '' || strlen($motivo) > 500)) {
        out(['ok' => false, 'code' => 'MOTIVO_OBRIGATORIO', 'user_msg' => 'Informe um motivo válido para cancelar a assinatura.'], 422);
    }
    if ($acao === 'cancelar' && !in_array($modalidadeCancelamento, ['imediato', 'fim_periodo_pago'], true)) {
        out(['ok' => false, 'code' => 'MODALIDADE_CANCELAMENTO_INVALIDA', 'user_msg' => 'Escolha a modalidade do cancelamento.'], 422);
    }

    $recorrenciaCancelada = false;
    if ($acao === 'cancelar') {
        require_once __DIR__ . '/../../_servicos/cancelamento_recorrencia.php';
        // Mesma trava por empresa da troca de cartão e do cancelamento pelo proprietário; liberada ao fim da requisição.
        if (!cancelamentoRecorrenciaObterTrava($conexao, $idEmpresaInformada)) {
            out(['ok' => false, 'code' => 'OPERACAO_CARTAO_EM_ANDAMENTO', 'user_msg' => 'Já existe uma operação no pagamento automático em andamento. Aguarde a conclusão.'], 409);
        }
        register_shutdown_function(static fn() => cancelamentoRecorrenciaLiberarTrava($conexao, $idEmpresaInformada));
        try {
            $previa = assinaturaPreValidarCancelamento($conexao, $idEmpresaInformada, $idAssinatura, $modalidadeCancelamento);
            if ($previa !== null) {
                out(['ok' => false, 'code' => $previa[0], 'user_msg' => $previa[1]], $previa[2]);
            }
            // Preapproval ativa, pendente de cancelamento ou incerta bloqueia o cancelamento comercial. Nada é enviado ao
            // Mercado Pago aqui: a recorrência é encerrada antes, pelo fluxo próprio do pagamento automático.
            $externo = cancelamentoRecorrenciaVerificarBloqueio(cancelamentoRecorrenciaPortasReais($conexao, $idEmpresaInformada, $idAssinatura));
        } catch (Throwable $erro) {
            // Sem conseguir verificar, o contrato não é cancelado (falha fechada).
            error_log('GESTAO_ASSINATURA_VERIFICAR_RECORRENCIA: ' . get_class($erro));
            $externo = ['ok' => false, 'recorrencia' => true, 'code' => 'CANCELAMENTO_RECORRENCIA_INDISPONIVEL',
                'msg' => 'Não foi possível verificar o pagamento automático agora. Tente novamente em instantes.', 'http' => 503];
        }
        if (!$externo['ok']) {
            // Textos do Super Admin; o código segue o mesmo do proprietário.
            $mensagemAdmin = match ($externo['code']) {
                'CANCELAR_PAGAMENTO_AUTOMATICO_PRIMEIRO' => 'Para cancelar esta assinatura, primeiro cancele o pagamento automático.',
                'CANCELAMENTO_RECORRENCIA_AGUARDANDO' => 'Aguarde a confirmação do cancelamento do pagamento automático para continuar.',
                default => $externo['msg'],
            };
            out(['ok' => false, 'code' => $externo['code'], 'user_msg' => $mensagemAdmin, 'data' => ['bloqueio_recorrencia' => true]], $externo['http']);
        }
        $recorrenciaCancelada = $externo['recorrencia'];
    }

    try {
        $conexao->begin_transaction();

        // Empresa e assinatura são bloqueadas antes da transição para impedir decisões sobre estado desatualizado.
        $stmtEmpresa = $conexao->prepare(
            'SELECT id_empresa, nome, plano_id, status
               FROM empresa
              WHERE id_empresa = ?
              LIMIT 1
              FOR UPDATE'
        );
        if (!$stmtEmpresa) {
            throw new RuntimeException('Falha ao preparar o bloqueio da empresa.');
        }
        $stmtEmpresa->bind_param('i', $idEmpresaInformada);
        if (!$stmtEmpresa->execute()) {
            $erro = $stmtEmpresa->error;
            $stmtEmpresa->close();
            throw new RuntimeException('Falha ao bloquear empresa: ' . $erro);
        }
        $empresa = $stmtEmpresa->get_result()->fetch_assoc();
        $stmtEmpresa->close();

        if (!$empresa) {
            assinaturaErroTransacional($conexao, ['ok' => false, 'code' => 'EMPRESA_NAO_ENCONTRADA', 'user_msg' => 'Empresa não encontrada.'], 404);
        }

        $stmtAssinatura = $conexao->prepare(
            "SELECT id_assinatura, id_empresa, id_plano, status, modalidade,
                    motivo_suspensao, suspensa_em, data_fim,
                    CASE WHEN teste_iniciado_em IS NOT NULL
                          AND teste_expira_em IS NOT NULL
                          AND teste_expira_em > teste_iniciado_em
                         THEN 1 ELSE 0 END AS teste_configurado,
                    CASE WHEN teste_expira_em IS NOT NULL
                          AND CURRENT_TIMESTAMP >= teste_expira_em
                         THEN 1 ELSE 0 END AS teste_expirado
               FROM assinatura
              WHERE id_assinatura = ?
              LIMIT 1
              FOR UPDATE"
        );
        if (!$stmtAssinatura) {
            throw new RuntimeException('Falha ao preparar o bloqueio da assinatura.');
        }
        $stmtAssinatura->bind_param('i', $idAssinatura);
        if (!$stmtAssinatura->execute()) {
            $erro = $stmtAssinatura->error;
            $stmtAssinatura->close();
            throw new RuntimeException('Falha ao bloquear assinatura: ' . $erro);
        }
        $assinatura = $stmtAssinatura->get_result()->fetch_assoc();
        $stmtAssinatura->close();

        if (!$assinatura || (int)$assinatura['id_empresa'] !== $idEmpresaInformada) {
            assinaturaErroTransacional($conexao, ['ok' => false, 'code' => 'ASSINATURA_NAO_ENCONTRADA', 'user_msg' => 'Assinatura não encontrada para esta empresa.'], 404);
        }

        $statusAnterior = (string)$assinatura['status'];
        if ($acao === 'cancelar' && $assinatura['data_fim'] !== null) {
            assinaturaErroTransacional($conexao, ['ok' => false, 'code' => 'CANCELAMENTO_JA_REGISTRADO', 'user_msg' => 'Esta assinatura já tem uma data de encerramento. Atualize a lista.'], 409);
        }
        if ($acao === 'reativar' && $assinatura['data_fim'] !== null) {
            assinaturaErroTransacional($conexao, ['ok' => false, 'code' => 'CANCELAMENTO_PROGRAMADO', 'user_msg' => 'Uma assinatura com encerramento programado não pode ser reativada.'], 409);
        }
        $statusNovo = match ($acao) {
            'suspender' => 'suspensa',
            'reativar' => 'ativa',
            'cancelar' => $modalidadeCancelamento === 'fim_periodo_pago' ? 'ativa' : 'cancelada',
        };
        $transicaoPermitida = ($acao === 'suspender' && $statusAnterior === 'ativa')
            || ($acao === 'reativar' && $statusAnterior === 'suspensa')
            || ($acao === 'cancelar' && in_array($statusAnterior, ['ativa', 'suspensa'], true));

        if (!$transicaoPermitida) {
            assinaturaErroTransacional($conexao, ['ok' => false, 'code' => 'TRANSICAO_INVALIDA', 'user_msg' => 'A transição de status da assinatura não é permitida.'], 409);
        }
        if ($acao === 'cancelar' && $modalidadeCancelamento === 'fim_periodo_pago' && $statusAnterior !== 'ativa') {
            assinaturaErroTransacional($conexao, ['ok' => false, 'code' => 'CANCELAMENTO_PROGRAMADO_INDISPONIVEL', 'user_msg' => 'O cancelamento ao fim do período pago exige assinatura ativa.'], 409);
        }

        if ($acao === 'reativar') {
            // A reativação não pode criar uma segunda assinatura ativa nem restaurar plano divergente.
            $stmtAtivas = $conexao->prepare(
                "SELECT id_assinatura
                   FROM assinatura
                  WHERE id_empresa = ?
                    AND status = 'ativa'
                    AND id_assinatura <> ?
                  FOR UPDATE"
            );
            if (!$stmtAtivas) {
                throw new RuntimeException('Falha ao preparar a validação de assinaturas ativas.');
            }
            $stmtAtivas->bind_param('ii', $idEmpresaInformada, $idAssinatura);
            if (!$stmtAtivas->execute()) {
                $erro = $stmtAtivas->error;
                $stmtAtivas->close();
                throw new RuntimeException('Falha ao validar assinaturas ativas: ' . $erro);
            }
            $quantidadeAtivas = $stmtAtivas->get_result()->num_rows;
            $stmtAtivas->close();

            if ($quantidadeAtivas > 0) {
                assinaturaErroTransacional($conexao, ['ok' => false, 'code' => 'ASSINATURA_ATIVA_EXISTENTE', 'user_msg' => 'A empresa já possui outra assinatura ativa.'], 409);
            }

            if ((int)$assinatura['id_plano'] !== (int)$empresa['plano_id']) {
                assinaturaErroTransacional($conexao, ['ok' => false, 'code' => 'PLANO_ASSINATURA_DIVERGENTE', 'user_msg' => 'A assinatura não corresponde ao plano operacional atual.'], 409);
            }

            // Segurança financeira: origem da suspensão, teste grátis, dívida e pagamento incerto.
            $bloqueioReativacao = assinaturaServicoBloqueioReativacao($conexao, $empresa, $assinatura);
            if ($bloqueioReativacao !== null) {
                assinaturaErroTransacional($conexao, ['ok' => false, 'code' => $bloqueioReativacao['code'], 'user_msg' => $bloqueioReativacao['user_msg']], 409);
            }
        }

        $operacoesEmAndamento = 0;
        if ($acao === 'cancelar') {
            $stmtOperacoes = $conexao->prepare(
                "SELECT COUNT(*) AS total FROM transacao_pagamento t
                   INNER JOIN cobranca c ON c.id_cobranca = t.id_cobranca AND c.id_empresa = t.id_empresa
                  WHERE c.id_empresa = ? AND c.id_assinatura = ?
                    AND (t.status IN ('criada','enviando','aguardando','processando','conciliacao')
                         OR t.requer_conciliacao = 1)"
            );
            if (!$stmtOperacoes) throw new RuntimeException('Falha ao consultar transações em andamento.');
            $stmtOperacoes->bind_param('ii', $idEmpresaInformada, $idAssinatura);
            if (!$stmtOperacoes->execute()) throw new RuntimeException('Falha ao consultar transações em andamento.');
            $operacoesEmAndamento = (int)$stmtOperacoes->get_result()->fetch_assoc()['total'];
            $stmtOperacoes->close();
        }

        $dataFim = null;
        $motivoSuspensao = $assinatura['motivo_suspensao'];
        $suspensaEm = $assinatura['suspensa_em'];
        if ($acao === 'cancelar') {
            if ($modalidadeCancelamento === 'fim_periodo_pago') {
                if ((string)$assinatura['modalidade'] !== 'paga') {
                    assinaturaErroTransacional($conexao, ['ok' => false, 'code' => 'SEM_PERIODO_PAGO', 'user_msg' => 'Esta assinatura não possui período pago válido. Use o cancelamento imediato.'], 409);
                }
                $dataFim = assinaturaServicoFimPeriodoPago($conexao, $idEmpresaInformada, $idAssinatura);
                if ($dataFim === null) {
                    assinaturaErroTransacional($conexao, ['ok' => false, 'code' => 'SEM_PERIODO_PAGO', 'user_msg' => 'Não há período integralmente pago que inclua a data atual.'], 409);
                }
            } else {
                $dataFim = date('Y-m-d');
            }
            $stmtUpdate = $conexao->prepare(
                "UPDATE assinatura
                    SET status = ?, data_fim = ?
                  WHERE id_assinatura = ?
                    AND status = ?"
            );
            if (!$stmtUpdate) {
                throw new RuntimeException('Falha ao preparar cancelamento da assinatura.');
            }
            $stmtUpdate->bind_param('ssis', $statusNovo, $dataFim, $idAssinatura, $statusAnterior);
        } elseif ($acao === 'suspender') {
            $motivoSuspensao = 'manual';
            $suspensaEm = date('Y-m-d H:i:s');
            $stmtUpdate = $conexao->prepare(
                "UPDATE assinatura
                    SET status = ?, motivo_suspensao = ?, suspensa_em = ?
                  WHERE id_assinatura = ?
                    AND status = ?"
            );
            if (!$stmtUpdate) {
                throw new RuntimeException('Falha ao preparar suspensão da assinatura.');
            }
            $stmtUpdate->bind_param('sssis', $statusNovo, $motivoSuspensao, $suspensaEm, $idAssinatura, $statusAnterior);
        } else {
            $motivoSuspensao = null;
            $suspensaEm = null;
            $stmtUpdate = $conexao->prepare(
                "UPDATE assinatura
                    SET status = ?, motivo_suspensao = NULL, suspensa_em = NULL
                  WHERE id_assinatura = ?
                    AND status = ?"
            );
            if (!$stmtUpdate) {
                throw new RuntimeException('Falha ao preparar alteração da assinatura.');
            }
            $stmtUpdate->bind_param('sis', $statusNovo, $idAssinatura, $statusAnterior);
        }

        if (!$stmtUpdate->execute()) {
            $erro = $stmtUpdate->error;
            $stmtUpdate->close();
            throw new RuntimeException('Falha ao atualizar assinatura: ' . $erro);
        }
        $alterados = $stmtUpdate->affected_rows;
        $stmtUpdate->close();

        if ($alterados !== 1) {
            assinaturaErroTransacional($conexao, ['ok' => false, 'code' => 'ASSINATURA_ALTERADA', 'user_msg' => 'A assinatura foi alterada por outra operação. Atualize a lista.'], 409);
        }

        if ($acao === 'cancelar') {
            // O perfil externo continua sujeito à conciliação; este bloqueio é somente da cobrança local.
            $stmtRecorrencia = $conexao->prepare(
                "UPDATE assinatura_pagamento_recorrente
                    SET status = 'cancelamento_pendente', slot_ativo = NULL, cobrar_a_partir_de = NULL
                  WHERE id_empresa = ? AND id_assinatura = ? AND motor_recorrencia = 'orders'
                    AND status IN ('pendente','ativa','suspensa','atualizacao_necessaria')"
            );
            if (!$stmtRecorrencia) throw new RuntimeException('Falha ao preparar bloqueio da recorrência.');
            $stmtRecorrencia->bind_param('ii', $idEmpresaInformada, $idAssinatura);
            if (!$stmtRecorrencia->execute()) throw new RuntimeException('Falha ao bloquear recorrência.');
            $stmtRecorrencia->close();
            // Preapproval já encerrada ou inexistente (verificado antes); nada é solicitado ao provedor aqui.
            if (!cancelamentoRecorrenciaFinalizarLocal($conexao, $idEmpresaInformada, $idAssinatura)) {
                // Uma recorrência viva surgiu entre as etapas: nada é concluído.
                assinaturaErroTransacional($conexao, ['ok' => false, 'code' => 'CANCELAR_PAGAMENTO_AUTOMATICO_PRIMEIRO', 'user_msg' => 'Para cancelar esta assinatura, primeiro cancele o pagamento automático.', 'data' => ['bloqueio_recorrencia' => true]], 409);
            }
        } elseif ($acao === 'suspender') {
            // Somente suspensão administrativa pausa a Preapproval; inadimplência nunca passa por aqui.
            assinaturaServicoPreapprovalSolicitarPausa($conexao, $idEmpresaInformada, $idAssinatura);
        } elseif ($acao === 'reativar') {
            assinaturaServicoPreapprovalSolicitarReativacao($conexao, $idEmpresaInformada, $idAssinatura);
        }

        $evento = match ($acao) {
            'suspender' => 'assinatura.suspensa',
            'reativar' => 'assinatura.reativada',
            'cancelar' => $modalidadeCancelamento === 'fim_periodo_pago' ? 'assinatura.cancelamento_programado' : 'assinatura.cancelada',
        };
        $contexto = [
            'origem' => 'painel_super_admin',
            'id_empresa' => $idEmpresaInformada,
            'id_assinatura' => $idAssinatura,
            'id_plano' => (int)$assinatura['id_plano'],
            'modalidade' => (string)$assinatura['modalidade'],
            'status_anterior' => $statusAnterior,
            'status_novo' => $statusNovo,
        ];
        if ($dataFim !== null) {
            $contexto['data_fim'] = $dataFim;
            $contexto['motivo'] = $motivo;
            $contexto['modalidade_cancelamento'] = $modalidadeCancelamento;
        }
        if (in_array($acao, ['suspender', 'reativar'], true)) {
            $contexto['motivo_suspensao'] = $motivoSuspensao;
            $contexto['suspensa_em'] = $suspensaEm;
        }

        $alteracoesDados = [
            'id_empresa' => $idEmpresaInformada,
            'id_assinatura' => $idAssinatura,
            'id_plano' => (int)$assinatura['id_plano'],
            'modalidade' => (string)$assinatura['modalidade'],
            'status' => $statusNovo,
            'status_anterior' => $statusAnterior,
            'status_novo' => $statusNovo,
        ];
        if ($dataFim !== null) {
            $alteracoesDados['data_fim'] = $dataFim;
        }

        $alteracoes = assinaturaAlteracoes($alteracoesDados);
        $alteracoes['status'] = ['antes' => $statusAnterior, 'depois' => $statusNovo];
        if (in_array($acao, ['suspender', 'reativar'], true)) {
            $alteracoes['motivo_suspensao'] = [
                'antes' => $assinatura['motivo_suspensao'],
                'depois' => $motivoSuspensao,
            ];
            $alteracoes['suspensa_em'] = [
                'antes' => $assinatura['suspensa_em'],
                'depois' => $suspensaEm,
            ];
        }

        auditoriaRegistrar($conexao, $evento, [
            'ator' => auditoriaResolverAtorSuperAdmin($conexao, $idEmpresaInformada),
            'entidade_id' => $idAssinatura,
            'entidade_rotulo' => 'Assinatura da empresa ' . (string)$empresa['nome'],
            'descricao' => match ($acao) {
                'suspender' => 'Suspendeu a assinatura da empresa ' . (string)$empresa['nome'] . '.',
                'reativar' => 'Reativou a assinatura da empresa ' . (string)$empresa['nome'] . '.',
                'cancelar' => $modalidadeCancelamento === 'fim_periodo_pago'
                    ? 'Programou o cancelamento da assinatura da empresa ' . (string)$empresa['nome'] . ' para ' . $dataFim . '.'
                    : 'Cancelou imediatamente a assinatura da empresa ' . (string)$empresa['nome'] . '.',
            },
            'alteracoes' => $alteracoes,
            'contexto' => $contexto,
        ]);

        $conexao->commit();
        out([
            'ok' => true,
            'code' => 'ASSINATURA_STATUS_ATUALIZADO',
            'user_msg' => 'Status da assinatura atualizado com sucesso.',
            'data' => [
                'id_assinatura' => $idAssinatura,
                'id_empresa' => $idEmpresaInformada,
                'status_anterior' => $statusAnterior,
                'status_novo' => $statusNovo,
                'data_fim' => $dataFim,
                'modalidade_cancelamento' => $modalidadeCancelamento,
                'operacoes_em_andamento' => $operacoesEmAndamento,
                'recorrencia_cancelada' => $recorrenciaCancelada,
                'modalidade' => (string)$assinatura['modalidade'],
                'motivo_suspensao' => $motivoSuspensao,
                'suspensa_em' => $suspensaEm,
            ],
        ]);
    } catch (Throwable $erro) {
        try {
            $conexao->rollback();
        } catch (Throwable) {
        }
        error_log('GESTAO_ASSINATURA_EXCEPTION: ' . $erro->getMessage());
        if (in_array($erro->getCode(), [1205, 1213], true)) {
            // Deadlock ou timeout de lock com operação financeira concorrente: nada foi alterado; basta repetir.
            out(['ok' => false, 'code' => 'FINANCEIRO_EM_ATUALIZACAO', 'user_msg' => 'O financeiro da empresa está sendo atualizado por outra operação. Tente novamente em instantes.'], 409);
        }
        out(['ok' => false, 'code' => 'ASSINATURA_STATUS_ERROR', 'user_msg' => 'Não foi possível atualizar a assinatura.'], 500);
    }
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    require __DIR__ . '/../../_auth/bloquear.php';
    require_once __DIR__ . '/../../_config/conexao.php';
    require_once __DIR__ . '/../../_servicos/auditoria.php';
    require_once __DIR__ . '/../../_servicos/assinatura.php';
    if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
        out(['ok' => false, 'code' => 'DB_CONN_ERROR', 'user_msg' => 'Falha ao conectar no banco.'], 500);
    }
    $conexao->set_charset('utf8mb4');
    try {
        if ((string)($_GET['previa_cancelamento'] ?? '') === '1') {
            assinaturaPreviaCancelamento($conexao);
        }
        assinaturaListar($conexao);
    } catch (Throwable $erro) {
        error_log('LISTA_ASSINATURA_EXCEPTION: ' . $erro->getMessage());
        out(['ok' => false, 'code' => 'ASSINATURA_LIST_ERROR', 'user_msg' => 'Não foi possível listar as assinaturas.'], 500);
    }
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    out(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED', 'user_msg' => 'Método não permitido.'], 405);
}

require __DIR__ . '/../../_auth/bloquear.php';
require_once __DIR__ . '/../../_config/conexao.php';
require_once __DIR__ . '/../../_servicos/auditoria.php';
require_once __DIR__ . '/../../_servicos/assinatura.php';

if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
    out(['ok' => false, 'code' => 'DB_CONN_ERROR', 'user_msg' => 'Falha ao conectar no banco.'], 500);
}

$conexao->set_charset('utf8mb4');
assinaturaAlterarStatus($conexao);
