<?php
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/../_gateways/mercado_pago.php';
require_once __DIR__ . '/../_servicos/auditoria.php';
require_once __DIR__ . '/../_servicos/assinatura.php';

const MP_WEBHOOK_PROVEDOR = 'mercado_pago';
const MP_WEBHOOK_CORPO_MAX_BYTES = 1048576;
const MP_WEBHOOK_LEASE_SEGUNDOS = 60;

/**
 * Origem registrada no contexto da auditoria financeira. O webhook usa o padrão;
 * a conciliação CLI (backend/processos/conciliar_pagamentos_mercado_pago.php)
 * informa a própria origem antes de reutilizar as mesmas regras.
 */
function mpWebhookOrigemAuditoria(?string $definir = null): string
{
    static $origem = 'webhook_mercado_pago';
    if ($definir !== null && in_array($definir, ['webhook_mercado_pago', 'conciliacao_mercado_pago'], true)) {
        $origem = $definir;
    }

    return $origem;
}

function mpWebhookResponder(int $httpStatus, string $codigo, bool $ok = false): never
{
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(
        ['ok' => $ok, 'code' => $codigo],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/** @return mysqli_result */
function mpWebhookConsulta(mysqli $conexao, string $sql, string $tipos = '', mixed ...$valores): mysqli_result
{
    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar consulta do webhook.');
    }
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$valores);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Falha ao executar consulta do webhook.');
    }
    $resultado = $stmt->get_result();
    $stmt->close();
    if (!$resultado) {
        throw new RuntimeException('Consulta do webhook sem resultado.');
    }

    return $resultado;
}

function mpWebhookExecutar(mysqli $conexao, string $sql, string $tipos = '', mixed ...$valores): int
{
    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar atualização do webhook.');
    }
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$valores);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Falha ao executar atualização do webhook.');
    }
    $afetadas = $stmt->affected_rows;
    $stmt->close();

    return $afetadas;
}

function mpWebhookRollback(mysqli $conexao): void
{
    try {
        $conexao->rollback();
    } catch (Throwable) {
    }
}

function mpWebhookCampoExterno(mixed $valor, int $maximo): ?string
{
    if (!is_string($valor) && !is_int($valor)) {
        return null;
    }
    $texto = (string) $valor;
    if ($texto === '' || strlen($texto) > $maximo
        || !preg_match('/^[A-Za-z0-9_.:-]+$/D', $texto)) {
        return null;
    }

    return $texto;
}

function mpWebhookQueryExata(string $nome): ?string
{
    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
    $valores = [];
    foreach (explode('&', $query) as $parte) {
        if ($parte === '') {
            continue;
        }
        [$chave, $valor] = array_pad(explode('=', $parte, 2), 2, '');
        if (urldecode($chave) === $nome) {
            $valores[] = urldecode($valor);
        }
    }

    return count($valores) === 1 ? $valores[0] : null;
}

/** @return array{ambiente:string,secret:string,gateway:MercadoPagoGateway} */
function mpWebhookConfiguracao(): array
{
    try {
        $gateway = MercadoPagoGateway::daConfiguracao();
        $ambiente = $gateway->ambiente();
        $secret = MercadoPagoGateway::segredoWebhook();
    } catch (RuntimeException $e) {
        throw new RuntimeException('Configuração do webhook indisponível.');
    }

    return ['ambiente' => $ambiente, 'secret' => $secret, 'gateway' => $gateway];
}

function mpWebhookValidarAssinatura(string $dataId, string $requestId, string $secret): string
{
    $assinatura = (string) ($_SERVER['HTTP_X_SIGNATURE'] ?? '');
    if ($assinatura === '' || strlen($assinatura) > 512) {
        mpWebhookResponder(401, 'INVALID_SIGNATURE');
    }
    $timestamp = null;
    $assinaturasV1 = [];
    foreach (explode(',', $assinatura) as $parte) {
        [$chave, $valor] = array_pad(explode('=', trim($parte), 2), 2, '');
        $chave = trim($chave);
        $valor = trim($valor);
        if ($chave === 'ts') {
            if ($timestamp !== null || !preg_match('/^[0-9]{10,16}$/D', $valor)) {
                mpWebhookResponder(401, 'INVALID_SIGNATURE');
            }
            $timestamp = $valor;
        } elseif ($chave === 'v1') {
            if (!preg_match('/^[0-9a-f]{64}$/D', $valor)) {
                mpWebhookResponder(401, 'INVALID_SIGNATURE');
            }
            $assinaturasV1[] = $valor;
        }
    }
    if ($timestamp === null || $assinaturasV1 === []) {
        mpWebhookResponder(401, 'INVALID_SIGNATURE');
    }

    $manifesto = 'id:' . strtolower($dataId)
        . ';request-id:' . $requestId
        . ';ts:' . $timestamp . ';';
    $esperada = hash_hmac('sha256', $manifesto, $secret);
    foreach ($assinaturasV1 as $recebida) {
        if (hash_equals($esperada, $recebida)) {
            return $timestamp;
        }
    }

    mpWebhookResponder(401, 'INVALID_SIGNATURE');
}

/** @return array{acao:string,versao:?int,payload_hash:string} */
function mpWebhookValidarCorpo(string $corpo, string $dataId, string $ambiente, string $tipo = 'order'): array
{
    if ($corpo === '' || strlen($corpo) > MP_WEBHOOK_CORPO_MAX_BYTES) {
        mpWebhookResponder(400, 'INVALID_EVENT');
    }
    try {
        $dados = json_decode($corpo, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        mpWebhookResponder(400, 'INVALID_EVENT');
    }
    if (!is_array($dados) || array_is_list($dados) || ($dados['type'] ?? null) !== $tipo
        || !is_array($dados['data'] ?? null)) {
        mpWebhookResponder(400, 'INVALID_EVENT');
    }
    $idCorpo = mpWebhookCampoExterno($dados['data']['id'] ?? null, 150);
    $acao = mpWebhookCampoExterno($dados['action'] ?? null, 100);
    if ($idCorpo === null || !hash_equals(strtolower($dataId), strtolower($idCorpo))
        || $acao === null
        || !preg_match($tipo === 'order' ? '/^order\.[a-z_]+$/D' : '/^[a-z_]+(\.[a-z_]+)?$/D', $acao)
        || !is_bool($dados['live_mode'] ?? null)
        || ($ambiente === 'teste' && $dados['live_mode'] !== false)
        || ($ambiente === 'producao' && $dados['live_mode'] !== true)) {
        mpWebhookResponder(400, 'INVALID_EVENT');
    }

    $versao = $dados['data']['version'] ?? null;
    if ($versao !== null && (!is_int($versao) || $versao < 0 || $versao > 2147483647)) {
        mpWebhookResponder(400, 'INVALID_EVENT');
    }

    return [
        'acao' => $acao,
        'versao' => $versao,
        'payload_hash' => hash('sha256', $corpo),
    ];
}

/** @return array{id_evento:int,duplicado:bool} */
function mpWebhookReservarEvento(mysqli $conexao, array $evento): array
{
    $conexao->begin_transaction();
    try {
        mpWebhookExecutar(
            $conexao,
            "INSERT INTO evento_gateway_pagamento
                (id_transacao,id_assinatura_pagamento,provedor,ambiente,tipo_recurso,acao,
                 recurso_externo_id,versao_recurso,request_id_externo,chave_evento,payload_hash,
                 assinatura_timestamp,status_processamento,tentativas_processamento,erro_codigo,
                 recebido_em,processado_em,atualizado_em)
             VALUES (NULL,NULL,?,?,?,?,?,?,?,?,?,?,'recebido',0,NULL,NOW(),NULL,NOW())
             ON DUPLICATE KEY UPDATE id_evento_gateway=LAST_INSERT_ID(id_evento_gateway)",
            'sssssissss',
            MP_WEBHOOK_PROVEDOR,
            $evento['ambiente'],
            $evento['tipo_recurso'] ?? 'order',
            $evento['acao'],
            $evento['recurso_externo_id'],
            $evento['versao'],
            $evento['request_id'],
            $evento['chave_evento'],
            $evento['payload_hash'],
            $evento['assinatura_timestamp']
        );
        $idEvento = (int) $conexao->insert_id;
        if ($idEvento <= 0) {
            throw new RuntimeException('Evento sem identificador.');
        }
        $atual = mpWebhookConsulta(
            $conexao,
            'SELECT id_evento_gateway,status_processamento,
                    TIMESTAMPDIFF(SECOND,atualizado_em,NOW()) AS idade_processamento
               FROM evento_gateway_pagamento
              WHERE id_evento_gateway=?
              FOR UPDATE',
            'i',
            $idEvento
        )->fetch_assoc();
        if (!$atual) {
            throw new RuntimeException('Evento não localizado após reserva.');
        }
        $terminal = in_array($atual['status_processamento'], ['processado', 'ignorado'], true);
        $emProcessamento = $atual['status_processamento'] === 'processando'
            && (int) $atual['idade_processamento'] < MP_WEBHOOK_LEASE_SEGUNDOS;
        if ($terminal || $emProcessamento) {
            $conexao->commit();
            return ['id_evento' => $idEvento, 'duplicado' => true];
        }
        mpWebhookExecutar(
            $conexao,
            "UPDATE evento_gateway_pagamento
                SET status_processamento='processando',
                    tentativas_processamento=tentativas_processamento+1,
                    erro_codigo=NULL,
                    atualizado_em=NOW()
              WHERE id_evento_gateway=?",
            'i',
            $idEvento
        );
        $conexao->commit();

        return ['id_evento' => $idEvento, 'duplicado' => false];
    } catch (Throwable $erro) {
        mpWebhookRollback($conexao);
        throw $erro;
    }
}

function mpWebhookEventoErro(mysqli $conexao, int $idEvento, string $codigo): void
{
    $codigo = mpWebhookCampoExterno($codigo, 100) ?? 'falha_processamento';
    mpWebhookExecutar(
        $conexao,
        "UPDATE evento_gateway_pagamento
            SET status_processamento='erro',erro_codigo=?,atualizado_em=NOW()
          WHERE id_evento_gateway=?
            AND status_processamento='processando'",
        'si',
        $codigo,
        $idEvento
    );
}

function mpWebhookEventoIgnorado(mysqli $conexao, int $idEvento, string $codigo): void
{
    $codigo = mpWebhookCampoExterno($codigo, 100) ?? 'evento_ignorado';
    mpWebhookExecutar(
        $conexao,
        "UPDATE evento_gateway_pagamento
            SET status_processamento='ignorado',erro_codigo=?,processado_em=NOW(),atualizado_em=NOW()
          WHERE id_evento_gateway=?
            AND status_processamento='processando'",
        'si',
        $codigo,
        $idEvento
    );
}

/**
 * Diagnóstico de falha (somente registro no log de erros da API): etapa, código
 * e metadados estruturais. Nunca registra token, segredo, assinatura, payload,
 * dados do pagador nem valores financeiros.
 */
function mpWebhookRegistrarFalha(string $etapa, array $detalhes = []): void
{
    $partes = [];
    foreach ($detalhes as $chave => $valor) {
        $texto = is_scalar($valor) || $valor === null ? (string)$valor : (string)json_encode($valor);
        $partes[] = $chave . '=' . mb_substr(preg_replace('/[\x00-\x1F\x7F]/', ' ', $texto) ?? '', 0, 300);
    }
    error_log('[webhook_mercado_pago] ' . $etapa . ($partes === [] ? '' : ' ' . implode(' ', $partes)));
}

/** Estrutura da Order recebida (presença e tipo dos campos validados), sem valores. */
function mpWebhookEstruturaOrder(array $order): array
{
    $tipoCampo = static fn (mixed $valor): string => $valor === null ? 'ausente' : get_debug_type($valor);

    return [
        'chaves' => implode(',', array_slice(array_map('strval', array_keys($order)), 0, 40)),
        'id' => $tipoCampo($order['id'] ?? null),
        'external_reference' => $tipoCampo($order['external_reference'] ?? null),
        'status' => $tipoCampo($order['status'] ?? null),
        'currency' => $tipoCampo($order['currency'] ?? null),
        'currency_id' => $tipoCampo($order['currency_id'] ?? null),
        'type' => is_string($order['type'] ?? null) ? (string)$order['type'] : $tipoCampo($order['type'] ?? null),
        'total_amount' => $tipoCampo($order['total_amount'] ?? null),
        'payments' => $tipoCampo($order['transactions']['payments'] ?? null),
    ];
}

/** @return array<string,mixed>|null */
function mpWebhookNormalizarOrder(array $order, string $idEsperado): ?array
{
    $id = mpWebhookCampoExterno($order['id'] ?? null, 150);
    $referencia = mpWebhookCampoExterno($order['external_reference'] ?? null, 64);
    $status = mpWebhookCampoExterno($order['status'] ?? null, 50);
    $detalhe = mpWebhookCampoExterno($order['status_detail'] ?? null, 100);
    // API Orders V1: a moeda vem em "currency" (string ISO 4217). "currency_id"
    // pertence à API de Payments e não é aceito como substituto. Ausente, não
    // textual ou diferente de BRL: a Order é rejeitada (comparação estrita abaixo).
    $moeda = $order['currency'] ?? null;
    $tipo = $order['type'] ?? null;
    $total = $order['total_amount'] ?? null;
    $pagamentos = $order['transactions']['payments'] ?? null;
    if ($id === null || !hash_equals(strtolower($idEsperado), strtolower($id))
        || $referencia === null || $status === null || $moeda !== 'BRL'
        || $tipo !== 'online' || !is_string($total) || !is_array($pagamentos)) {
        return null;
    }
    try {
        $totalCentavos = pagamentoGatewayValorCentavos($total);
    } catch (InvalidArgumentException) {
        return null;
    }

    return [
        'id' => $id,
        'referencia' => $referencia,
        'status' => $status,
        'detalhe' => $detalhe,
        'total_centavos' => $totalCentavos,
        'pagamentos' => array_values($pagamentos),
        'payer' => is_array($order['payer'] ?? null) ? $order['payer'] : [],
    ];
}

function mpWebhookLocalizarTransacao(mysqli $conexao, array $order, string $ambiente): ?int
{
    $linhas = mpWebhookConsulta(
        $conexao,
        "SELECT id_transacao
           FROM transacao_pagamento
          WHERE provedor=?
            AND ambiente=?
            AND tipo_operacao <> 'assinatura_preapproval'
            AND (ordem_externa_id=? OR (ordem_externa_id IS NULL AND referencia_interna=?))
          ORDER BY (ordem_externa_id=?) DESC,id_transacao DESC
          LIMIT 2",
        'sssss',
        MP_WEBHOOK_PROVEDOR,
        $ambiente,
        $order['id'],
        $order['referencia'],
        $order['id']
    )->fetch_all(MYSQLI_ASSOC);

    return count($linhas) === 1 ? (int) $linhas[0]['id_transacao'] : null;
}

function mpWebhookAtorSistema(int $idEmpresa): array
{
    return [
        'ator_tipo' => 'sistema',
        'id_ator' => null,
        'ator_nome' => 'Mercado Pago',
        'ator_perfil' => 'sistema',
        'id_empresa' => $idEmpresa,
        'modo_suporte' => false,
        'origem' => 'empresa',
    ];
}

function mpWebhookAlteracoes(array $depois, array $antes = []): array
{
    $alteracoes = [];
    foreach ($depois as $campo => $valor) {
        $alteracoes[(string) $campo] = [
            'antes' => $antes[$campo] ?? null,
            'depois' => $valor,
        ];
    }

    return $alteracoes;
}

function mpWebhookFinalizarEvento(
    mysqli $conexao,
    ?int $idEvento,
    int $idTransacao,
    ?int $idAssinaturaPagamento
): void {
    // Sem evento (conciliação CLI): não há notificação a finalizar.
    if ($idEvento === null) {
        return;
    }
    mpWebhookExecutar(
        $conexao,
        "UPDATE evento_gateway_pagamento
            SET id_transacao=?,id_assinatura_pagamento=?,status_processamento='processado',
                erro_codigo=NULL,processado_em=NOW(),atualizado_em=NOW()
          WHERE id_evento_gateway=?
            AND status_processamento='processando'",
        'iii',
        $idTransacao,
        $idAssinaturaPagamento,
        $idEvento
    );
}

function mpWebhookTotalConfirmado(mysqli $conexao, int $idEmpresa, int $idCobranca): int
{
    $pagamentos = mpWebhookConsulta(
        $conexao,
        "SELECT valor_pago
           FROM pagamento
          WHERE id_empresa=? AND id_cobranca=? AND status='confirmado'
          FOR UPDATE",
        'ii',
        $idEmpresa,
        $idCobranca
    );
    $total = 0;
    while ($pagamento = $pagamentos->fetch_assoc()) {
        $total += pagamentoGatewayValorCentavos((string) $pagamento['valor_pago']);
    }
    $pagamentos->free();

    return $total;
}

function mpWebhookRecalcularCobranca(
    mysqli $conexao,
    array $cobranca,
    int $totalConfirmado
): array {
    $valorCobranca = pagamentoGatewayValorCentavos((string) $cobranca['valor']);
    if ($cobranca['status'] !== 'cancelada') {
        $novoStatus = $totalConfirmado >= $valorCobranca ? 'paga' : 'pendente';
        if ($novoStatus !== $cobranca['status']) {
            mpWebhookExecutar(
                $conexao,
                'UPDATE cobranca SET status=? WHERE id_cobranca=? AND id_empresa=?',
                'sii',
                $novoStatus,
                (int) $cobranca['id_cobranca'],
                (int) $cobranca['id_empresa']
            );
        }
    }

    return [
        'valor_cobranca' => $valorCobranca,
        'saldo' => max(0, $valorCobranca - $totalConfirmado),
        'sobrepagamento' => $totalConfirmado > $valorCobranca,
        'cancelada' => $cobranca['status'] === 'cancelada',
    ];
}

function mpWebhookAuditarConciliacao(
    mysqli $conexao,
    array $transacao,
    string $statusExterno,
    ?string $detalheExterno,
    string $motivo,
    ?string $statusLocal = null
): void {
    auditoriaRegistrar($conexao, 'pagamento.conciliacao_necessaria', [
        'ator' => mpWebhookAtorSistema((int) $transacao['id_empresa']),
        'entidade_id' => (int) $transacao['id_transacao'],
        'entidade_rotulo' => 'Transação de pagamento #' . (int) $transacao['id_transacao'],
        'alteracoes' => mpWebhookAlteracoes([
            'id_empresa' => (int) $transacao['id_empresa'],
            'id_cobranca' => (int) $transacao['id_cobranca'],
            'id_transacao' => (int) $transacao['id_transacao'],
            'status' => $statusLocal
                ?? ($transacao['status'] === 'confirmada' ? 'confirmada' : 'conciliacao'),
            'status_externo' => $statusExterno,
            'detalhe_status_externo' => $detalheExterno,
            'motivo' => $motivo,
        ]),
        'contexto' => ['origem' => mpWebhookOrigemAuditoria(), 'motivo' => $motivo],
    ]);
}

function mpWebhookMarcarConciliacao(
    mysqli $conexao,
    array $transacao,
    ?int $idEvento,
    string $statusExterno,
    ?string $detalheExterno,
    string $motivo
): void {
    $motivo = mb_substr($motivo, 0, 300, 'UTF-8');
    $status = $transacao['status'] === 'confirmada' ? 'confirmada' : 'conciliacao';
    mpWebhookExecutar(
        $conexao,
        'UPDATE transacao_pagamento
            SET status=?,status_externo=?,detalhe_status_externo=?,ultima_consulta_em=NOW(),
                requer_conciliacao=1,motivo_conciliacao=?,erro_codigo=?
          WHERE id_transacao=? AND id_empresa=?',
        'sssssii',
        $status,
        $statusExterno,
        $detalheExterno,
        $motivo,
        'conciliacao_webhook',
        (int) $transacao['id_transacao'],
        (int) $transacao['id_empresa']
    );
    if ((int) $transacao['requer_conciliacao'] !== 1) {
        mpWebhookAuditarConciliacao($conexao, $transacao, $statusExterno, $detalheExterno, $motivo);
    }
    mpWebhookFinalizarEvento(
        $conexao,
        $idEvento,
        (int) $transacao['id_transacao'],
        $transacao['id_assinatura_pagamento'] === null
            ? null
            : (int) $transacao['id_assinatura_pagamento']
    );
}

/** @return array<string,mixed>|null */
function mpWebhookPagamentoOrder(array $order): ?array
{
    if (count($order['pagamentos']) !== 1 || !is_array($order['pagamentos'][0])) {
        return null;
    }
    $pagamento = $order['pagamentos'][0];
    $id = mpWebhookCampoExterno($pagamento['id'] ?? null, 150);
    $status = mpWebhookCampoExterno($pagamento['status'] ?? null, 50);
    $detalhe = mpWebhookCampoExterno($pagamento['status_detail'] ?? null, 100);
    $metodo = is_array($pagamento['payment_method'] ?? null) ? $pagamento['payment_method'] : [];
    $tipoMetodo = mpWebhookCampoExterno($metodo['type'] ?? null, 50);
    $idMetodo = mpWebhookCampoExterno($metodo['id'] ?? null, 50);
    $pago = $pagamento['paid_amount'] ?? null;
    $valor = $pagamento['amount'] ?? null;
    try {
        $pagoCentavos = is_string($pago) ? pagamentoGatewayValorCentavos($pago) : null;
        $valorCentavos = is_string($valor) ? pagamentoGatewayValorCentavos($valor) : null;
    } catch (InvalidArgumentException) {
        return null;
    }

    return [
        'id' => $id,
        'status' => $status,
        'detalhe' => $detalhe,
        'tipo_metodo' => $tipoMetodo,
        'id_metodo' => $idMetodo,
        'pago_centavos' => $pagoCentavos,
        'valor_centavos' => $valorCentavos,
        'automatico' => is_array($pagamento['automatic_payments'] ?? null)
            ? $pagamento['automatic_payments']
            : [],
    ];
}

/**
 * Aplica o estado autoritativo de uma Order à transação local.
 * - $idEvento: notificação do webhook reservada como 'processando'.
 * - $idEvento null: conciliação CLI (consulta autoritativa feita pelo chamador);
 *   exige $ambienteConciliacao e não lê nem finaliza evento.
 * As demais regras, locks e transações são idênticos nos dois casos.
 */
function mpWebhookAplicarOrder(
    mysqli $conexao,
    ?int $idEvento,
    int $idTransacao,
    array $order,
    ?string $ambienteConciliacao = null
): void {
    if ($idEvento === null && !in_array($ambienteConciliacao, ['teste', 'producao'], true)) {
        throw new InvalidArgumentException('Ambiente da conciliação inválido.');
    }
    $pagamentoOrder = mpWebhookPagamentoOrder($order);
    $idPagamentoExterno = $pagamentoOrder['id'] ?? null;
    $referenciaTransacao = mpWebhookConsulta(
        $conexao,
        'SELECT id_transacao,id_empresa,id_cobranca,id_assinatura_pagamento,
                provedor,ambiente,metodo
           FROM transacao_pagamento
          WHERE id_transacao=?',
        'i',
        $idTransacao
    )->fetch_assoc();
    if (!$referenciaTransacao) {
        throw new RuntimeException('Transação não localizada durante a conciliação.');
    }

    $conexao->begin_transaction();
    try {
        // Mantém a mesma ordem de locks dos fluxos de cobrança: cobrança, vínculo, pagamentos e transação.
        $cobranca = mpWebhookConsulta(
            $conexao,
            'SELECT c.id_cobranca,c.id_empresa,c.valor,c.status
               FROM cobranca c
               JOIN empresa e ON e.id_empresa=c.id_empresa
              WHERE c.id_cobranca=? AND c.id_empresa=?
              FOR UPDATE',
            'ii',
            (int) $referenciaTransacao['id_cobranca'],
            (int) $referenciaTransacao['id_empresa']
        )->fetch_assoc();
        if (!$cobranca) {
            throw new RuntimeException('Cobrança não localizada durante a conciliação.');
        }
        $vinculo = null;
        if ($referenciaTransacao['metodo'] === 'cartao_recorrente') {
            $vinculo = mpWebhookConsulta(
                $conexao,
                'SELECT id_assinatura_pagamento,id_empresa,cliente_externo_id,perfil_externo_id
                   FROM assinatura_pagamento_recorrente
                  WHERE id_assinatura_pagamento=? AND id_empresa=?
                    AND provedor=? AND ambiente=?
                  FOR UPDATE',
                'iiss',
                (int) $referenciaTransacao['id_assinatura_pagamento'],
                (int) $referenciaTransacao['id_empresa'],
                MP_WEBHOOK_PROVEDOR,
                $referenciaTransacao['ambiente']
            )->fetch_assoc();
        }
        mpWebhookConsulta(
            $conexao,
            'SELECT id_pagamento
               FROM pagamento
              WHERE id_empresa=? AND id_cobranca=?
              FOR UPDATE',
            'ii',
            (int) $referenciaTransacao['id_empresa'],
            (int) $referenciaTransacao['id_cobranca']
        )->free();
        $transacao = mpWebhookConsulta(
            $conexao,
            'SELECT id_transacao,id_empresa,id_cobranca,id_pagamento,id_assinatura_pagamento,
                    provedor,ambiente,metodo,tipo_operacao,valor,moeda,status,referencia_interna,
                    ordem_externa_id,pagamento_externo_id,status_externo,detalhe_status_externo,
                    confirmado_em,requer_conciliacao
               FROM transacao_pagamento
              WHERE id_transacao=?
              FOR UPDATE',
            'i',
            $idTransacao
        )->fetch_assoc();
        if (!$transacao) {
            throw new RuntimeException('Transação não localizada durante a conciliação.');
        }
        if ((int) $transacao['id_empresa'] !== (int) $referenciaTransacao['id_empresa']
            || (int) $transacao['id_cobranca'] !== (int) $referenciaTransacao['id_cobranca']
            || $transacao['metodo'] !== $referenciaTransacao['metodo']
            || $transacao['ambiente'] !== $referenciaTransacao['ambiente']) {
            throw new RuntimeException('Contexto da transação alterado durante a conciliação.');
        }
        $outraTransacao = null;
        if ($idPagamentoExterno !== null) {
            $outraTransacao = mpWebhookConsulta(
                $conexao,
                'SELECT id_transacao
                   FROM transacao_pagamento
                  WHERE provedor=? AND ambiente=? AND pagamento_externo_id=?
                    AND id_transacao<>?',
                'sssi',
                MP_WEBHOOK_PROVEDOR,
                $transacao['ambiente'],
                $idPagamentoExterno,
                (int) $transacao['id_transacao']
            )->fetch_assoc();
        }
        if ($idEvento !== null) {
            $evento = mpWebhookConsulta(
                $conexao,
                'SELECT id_evento_gateway,ambiente,status_processamento
                   FROM evento_gateway_pagamento
                  WHERE id_evento_gateway=?
                  FOR UPDATE',
                'i',
                $idEvento
            )->fetch_assoc();
            if (!$evento || $evento['status_processamento'] !== 'processando') {
                $conexao->commit();
                return;
            }
            $ambienteEsperado = (string) $evento['ambiente'];
        } else {
            $ambienteEsperado = (string) $ambienteConciliacao;
        }

        $inconsistente = $transacao['provedor'] !== MP_WEBHOOK_PROVEDOR
            || $transacao['ambiente'] !== $ambienteEsperado
            || $transacao['referencia_interna'] !== $order['referencia']
            || $transacao['moeda'] !== 'BRL'
            || ($transacao['ordem_externa_id'] !== null
                && $transacao['ordem_externa_id'] !== $order['id'])
            || !in_array($transacao['metodo'], ['pix', 'cartao_recorrente'], true);
        if ($inconsistente) {
            mpWebhookMarcarConciliacao(
                $conexao,
                $transacao,
                $idEvento,
                $order['status'],
                $order['detalhe'],
                'Order divergente da transação financeira local.'
            );
            $conexao->commit();
            return;
        }

        if ($pagamentoOrder === null) {
            mpWebhookMarcarConciliacao(
                $conexao,
                $transacao,
                $idEvento,
                $order['status'],
                $order['detalhe'],
                'Quantidade ou formato de pagamentos da Order não reconhecido.'
            );
            $conexao->commit();
            return;
        }
        if ($transacao['pagamento_externo_id'] !== null && $idPagamentoExterno !== null
            && $transacao['pagamento_externo_id'] !== $idPagamentoExterno) {
            mpWebhookMarcarConciliacao(
                $conexao,
                $transacao,
                $idEvento,
                $order['status'],
                $order['detalhe'],
                'Payment ID divergente da transação financeira local.'
            );
            $conexao->commit();
            return;
        }
        if ($outraTransacao) {
            mpWebhookMarcarConciliacao(
                $conexao,
                $transacao,
                $idEvento,
                $order['status'],
                $order['detalhe'],
                'Payment ID já associado a outra transação local.'
            );
            $conexao->commit();
            return;
        }

        $metodoValido = $transacao['metodo'] === 'pix'
            ? $pagamentoOrder['id_metodo'] === 'pix' && $pagamentoOrder['tipo_metodo'] === 'bank_transfer'
            : $pagamentoOrder['tipo_metodo'] === 'credit_card';
        if (!$metodoValido) {
            mpWebhookMarcarConciliacao(
                $conexao,
                $transacao,
                $idEvento,
                $order['status'],
                $order['detalhe'],
                'Meio de pagamento da Order divergente da transação local.'
            );
            $conexao->commit();
            return;
        }

        if ($transacao['metodo'] === 'cartao_recorrente') {
            $clienteOrder = $order['payer']['customer_id'] ?? null;
            $perfilOrder = $pagamentoOrder['automatico']['payment_profile_id'] ?? null;
            if (!$vinculo || $clienteOrder !== $vinculo['cliente_externo_id']
                || $perfilOrder !== $vinculo['perfil_externo_id']) {
                mpWebhookMarcarConciliacao(
                    $conexao,
                    $transacao,
                    $idEvento,
                    $order['status'],
                    $order['detalhe'],
                    'Customer ou Payment Profile divergente do vínculo recorrente.'
                );
                $conexao->commit();
                return;
            }
        }

        $statusOrder = $order['status'];
        $detalheOrder = $order['detalhe'];
        $confirmada = $statusOrder === 'processed' && $detalheOrder === 'accredited'
            && $pagamentoOrder['status'] === 'processed'
            && $pagamentoOrder['detalhe'] === 'accredited';
        $estornada = $statusOrder === 'refunded' && $detalheOrder === 'refunded'
            && $pagamentoOrder['status'] === 'refunded'
            && $pagamentoOrder['detalhe'] === 'refunded';
        $parcialOuContestada = ($statusOrder === 'processed' && $detalheOrder === 'partially_refunded')
            || $statusOrder === 'charged_back';

        if ($parcialOuContestada) {
            mpWebhookMarcarConciliacao(
                $conexao,
                $transacao,
                $idEvento,
                $statusOrder,
                $detalheOrder,
                'Reembolso parcial ou contestação exige conciliação financeira.'
            );
            $conexao->commit();
            return;
        }

        if ($confirmada) {
            if ($idPagamentoExterno === null || !is_int($pagamentoOrder['pago_centavos'])
                || $pagamentoOrder['pago_centavos'] <= 0) {
                mpWebhookMarcarConciliacao(
                    $conexao,
                    $transacao,
                    $idEvento,
                    $statusOrder,
                    $detalheOrder,
                    'Pagamento creditado sem identificador ou valor confirmado utilizável.'
                );
                $conexao->commit();
                return;
            }
            $existente = mpWebhookConsulta(
                $conexao,
                'SELECT id_pagamento,id_empresa,id_cobranca,valor_pago,data_pagamento,
                        forma_pagamento,origem,status,provedor,referencia_externa
                   FROM pagamento
                  WHERE provedor=? AND referencia_externa=?
                  FOR UPDATE',
                'ss',
                MP_WEBHOOK_PROVEDOR,
                $idPagamentoExterno
            )->fetch_assoc();
            $forma = $transacao['metodo'] === 'pix' ? 'pix' : 'cartao_credito';
            $valorReal = pagamentoGatewayValorDecimal($pagamentoOrder['pago_centavos']);
            $criado = false;
            if ($existente) {
                $coerente = (int) $existente['id_empresa'] === (int) $transacao['id_empresa']
                    && (int) $existente['id_cobranca'] === (int) $transacao['id_cobranca']
                    && pagamentoGatewayValorCentavos((string) $existente['valor_pago']) === $pagamentoOrder['pago_centavos']
                    && $existente['forma_pagamento'] === $forma
                    && $existente['origem'] === 'gateway'
                    && $existente['status'] === 'confirmado';
                if (!$coerente) {
                    mpWebhookMarcarConciliacao(
                        $conexao,
                        $transacao,
                        $idEvento,
                        $statusOrder,
                        $detalheOrder,
                        'Payment ID já associado a pagamento local divergente.'
                    );
                    $conexao->commit();
                    return;
                }
                $idPagamento = (int) $existente['id_pagamento'];
            } else {
                // A Order não documenta um instante de aprovação próprio; registra-se o momento local da confirmação autoritativa.
                mpWebhookExecutar(
                    $conexao,
                    "INSERT INTO pagamento
                        (id_empresa,id_cobranca,valor_pago,data_pagamento,forma_pagamento,
                         origem,status,provedor,referencia_externa)
                     VALUES (?,?,?,NOW(),?,'gateway','confirmado',?,?)",
                    'iissss',
                    (int) $transacao['id_empresa'],
                    (int) $transacao['id_cobranca'],
                    $valorReal,
                    $forma,
                    MP_WEBHOOK_PROVEDOR,
                    $idPagamentoExterno
                );
                $idPagamento = (int) $conexao->insert_id;
                $criado = true;
                $existente = mpWebhookConsulta(
                    $conexao,
                    'SELECT id_pagamento,id_empresa,id_cobranca,valor_pago,data_pagamento,
                            forma_pagamento,origem,status,provedor,referencia_externa
                       FROM pagamento WHERE id_pagamento=? FOR UPDATE',
                    'i',
                    $idPagamento
                )->fetch_assoc();
            }

            $totalConfirmado = mpWebhookTotalConfirmado(
                $conexao,
                (int) $transacao['id_empresa'],
                (int) $transacao['id_cobranca']
            );
            $recalculo = mpWebhookRecalcularCobranca($conexao, $cobranca, $totalConfirmado);
            $valorEsperado = pagamentoGatewayValorCentavos((string) $transacao['valor']);
            $motivos = [];
            if ($valorEsperado !== $pagamentoOrder['pago_centavos']
                || $order['total_centavos'] !== $valorEsperado) {
                $motivos[] = 'Valor confirmado diverge do valor esperado pela transação.';
            }
            if ($recalculo['sobrepagamento']) {
                $motivos[] = 'Total confirmado excede o valor da cobrança.';
            }
            if ($recalculo['cancelada']) {
                $motivos[] = 'Pagamento confirmado para cobrança cancelada.';
            }
            if ($transacao['id_pagamento'] !== null
                && (int) $transacao['id_pagamento'] !== $idPagamento) {
                $motivos[] = 'Transação já vinculada a outro pagamento local.';
            }
            // A conversão do trial (ou a reativação da assinatura paga suspensa por inadimplência desta mesma
            // cobrança) só ocorre depois do recálculo interno e com saldo integralmente quitado; o serviço
            // exige finalidade 'conversao_trial' e é idempotente em reprocessamentos.
            if (!$recalculo['cancelada'] && $recalculo['saldo'] === 0) {
                $conversaoTrial = assinaturaServicoReavaliarConversaoTrial(
                    $conexao,
                    (int) $transacao['id_cobranca'],
                    mpWebhookAtorSistema((int) $transacao['id_empresa']),
                    ['origem' => mpWebhookOrigemAuditoria()]
                );
                if ($conversaoTrial['requer_atencao']) {
                    $motivos[] = 'Cobrança de conversão quitada, mas a assinatura não pôde ser convertida automaticamente.';
                }
            }
            $requerConciliacao = $motivos === [] ? 0 : 1;
            $motivo = $requerConciliacao === 1 ? implode(' ', $motivos) : null;
            mpWebhookExecutar(
                $conexao,
                "UPDATE transacao_pagamento
                    SET id_pagamento=COALESCE(id_pagamento,?),ordem_externa_id=?,pagamento_externo_id=?,status='confirmada',
                        status_externo=?,detalhe_status_externo=?,confirmado_em=COALESCE(confirmado_em,NOW()),
                        ultima_consulta_em=NOW(),requer_conciliacao=?,motivo_conciliacao=?,erro_codigo=NULL
                  WHERE id_transacao=? AND id_empresa=?",
                'issssisii',
                $idPagamento,
                $order['id'],
                $idPagamentoExterno,
                $statusOrder,
                $detalheOrder,
                $requerConciliacao,
                $motivo,
                (int) $transacao['id_transacao'],
                (int) $transacao['id_empresa']
            );
            if ($criado && $existente) {
                auditoriaRegistrar($conexao, 'pagamento.confirmado_gateway', [
                    'ator' => mpWebhookAtorSistema((int) $transacao['id_empresa']),
                    'entidade_id' => $idPagamento,
                    'entidade_rotulo' => 'Pagamento da cobrança #' . (int) $transacao['id_cobranca'],
                    'alteracoes' => mpWebhookAlteracoes([
                        'id_empresa' => (int) $transacao['id_empresa'],
                        'id_cobranca' => (int) $transacao['id_cobranca'],
                        'id_pagamento' => $idPagamento,
                        'valor_pago' => (string) $existente['valor_pago'],
                        'data_pagamento' => (string) $existente['data_pagamento'],
                        'forma_pagamento' => $forma,
                        'origem' => 'gateway',
                        'status' => 'confirmado',
                        'provedor' => MP_WEBHOOK_PROVEDOR,
                        'referencia_externa' => $idPagamentoExterno,
                        'total_pago_confirmado' => pagamentoGatewayValorDecimal($totalConfirmado),
                        'saldo_restante' => pagamentoGatewayValorDecimal($recalculo['saldo']),
                    ]),
                    'contexto' => ['origem' => mpWebhookOrigemAuditoria()],
                ]);
            }
            if ($requerConciliacao === 1 && (int) $transacao['requer_conciliacao'] !== 1) {
                mpWebhookAuditarConciliacao(
                    $conexao,
                    $transacao,
                    $statusOrder,
                    $detalheOrder,
                    (string) $motivo,
                    'confirmada'
                );
            }
            mpWebhookFinalizarEvento(
                $conexao,
                $idEvento,
                (int) $transacao['id_transacao'],
                $transacao['id_assinatura_pagamento'] === null
                    ? null
                    : (int) $transacao['id_assinatura_pagamento']
            );
            $conexao->commit();
            return;
        }

        if ($estornada) {
            if ($idPagamentoExterno === null) {
                mpWebhookMarcarConciliacao(
                    $conexao,
                    $transacao,
                    $idEvento,
                    $statusOrder,
                    $detalheOrder,
                    'Order estornada sem Payment ID utilizável.'
                );
                $conexao->commit();
                return;
            }
            $pagamento = mpWebhookConsulta(
                $conexao,
                'SELECT id_pagamento,id_empresa,id_cobranca,valor_pago,data_pagamento,
                        forma_pagamento,origem,status,provedor,referencia_externa
                   FROM pagamento
                  WHERE provedor=? AND referencia_externa=?
                  FOR UPDATE',
                'ss',
                MP_WEBHOOK_PROVEDOR,
                $idPagamentoExterno
            )->fetch_assoc();
            if (!$pagamento || (int) $pagamento['id_empresa'] !== (int) $transacao['id_empresa']
                || (int) $pagamento['id_cobranca'] !== (int) $transacao['id_cobranca']
                || $pagamento['origem'] !== 'gateway'
                || $pagamento['forma_pagamento'] !== ($transacao['metodo'] === 'pix' ? 'pix' : 'cartao_credito')
                || !in_array($pagamento['status'], ['confirmado', 'estornado'], true)) {
                mpWebhookMarcarConciliacao(
                    $conexao,
                    $transacao,
                    $idEvento,
                    $statusOrder,
                    $detalheOrder,
                    'Estorno sem pagamento local confirmado e coerente.'
                );
                $conexao->commit();
                return;
            }
            $alterou = $pagamento['status'] === 'confirmado';
            if ($alterou) {
                mpWebhookExecutar(
                    $conexao,
                    "UPDATE pagamento SET status='estornado' WHERE id_pagamento=? AND status='confirmado'",
                    'i',
                    (int) $pagamento['id_pagamento']
                );
            }
            $totalConfirmado = mpWebhookTotalConfirmado(
                $conexao,
                (int) $transacao['id_empresa'],
                (int) $transacao['id_cobranca']
            );
            $recalculo = mpWebhookRecalcularCobranca($conexao, $cobranca, $totalConfirmado);
            // Após o recálculo interno, uma cobrança de conversão que deixou de estar quitada suspende a
            // assinatura já convertida por inadimplência; reprocessar o mesmo estorno é idempotente.
            $contratoEstorno = $recalculo['cancelada'] ? null : assinaturaServicoReavaliarConversaoTrial(
                $conexao,
                (int) $transacao['id_cobranca'],
                mpWebhookAtorSistema((int) $transacao['id_empresa']),
                ['origem' => mpWebhookOrigemAuditoria()]
            );
            $vinculoPagamentoDivergente = $transacao['id_pagamento'] !== null
                && (int) $transacao['id_pagamento'] !== (int) $pagamento['id_pagamento'];
            $valorEstornoDivergente = is_int($pagamentoOrder['pago_centavos'])
                && pagamentoGatewayValorCentavos((string) $pagamento['valor_pago'])
                    !== $pagamentoOrder['pago_centavos'];
            mpWebhookExecutar(
                $conexao,
                "UPDATE transacao_pagamento
                    SET id_pagamento=COALESCE(id_pagamento,?),ordem_externa_id=?,pagamento_externo_id=?,
                        status_externo=?,detalhe_status_externo=?,ultima_consulta_em=NOW(),erro_codigo=NULL
                  WHERE id_transacao=? AND id_empresa=?",
                'issssii',
                (int) $pagamento['id_pagamento'],
                $order['id'],
                $idPagamentoExterno,
                $statusOrder,
                $detalheOrder,
                (int) $transacao['id_transacao'],
                (int) $transacao['id_empresa']
            );
            if ($alterou) {
                auditoriaRegistrar($conexao, 'pagamento.estornado_gateway', [
                    'ator' => mpWebhookAtorSistema((int) $transacao['id_empresa']),
                    'entidade_id' => (int) $pagamento['id_pagamento'],
                    'entidade_rotulo' => 'Pagamento da cobrança #' . (int) $transacao['id_cobranca'],
                    'alteracoes' => mpWebhookAlteracoes([
                        'id_empresa' => (int) $transacao['id_empresa'],
                        'id_cobranca' => (int) $transacao['id_cobranca'],
                        'id_pagamento' => (int) $pagamento['id_pagamento'],
                        'valor_pago' => (string) $pagamento['valor_pago'],
                        'data_pagamento' => (string) $pagamento['data_pagamento'],
                        'forma_pagamento' => (string) $pagamento['forma_pagamento'],
                        'origem' => 'gateway',
                        'status' => 'estornado',
                        'provedor' => MP_WEBHOOK_PROVEDOR,
                        'referencia_externa' => $idPagamentoExterno,
                        'total_pago_confirmado' => pagamentoGatewayValorDecimal($totalConfirmado),
                        'saldo_restante' => pagamentoGatewayValorDecimal($recalculo['saldo']),
                    ], ['status' => 'confirmado']),
                    'contexto' => ['origem' => mpWebhookOrigemAuditoria()],
                ]);
            }
            if ($vinculoPagamentoDivergente || $valorEstornoDivergente) {
                mpWebhookMarcarConciliacao(
                    $conexao,
                    $transacao,
                    $idEvento,
                    $statusOrder,
                    $detalheOrder,
                    'Estorno confirmado com divergência no pagamento local vinculado.'
                );
                $conexao->commit();
                return;
            }
            if ($contratoEstorno !== null && $contratoEstorno['requer_atencao']) {
                mpWebhookMarcarConciliacao(
                    $conexao,
                    $transacao,
                    $idEvento,
                    $statusOrder,
                    $detalheOrder,
                    'Cobrança de conversão deixou de estar quitada, mas a situação da assinatura exige revisão manual.'
                );
                $conexao->commit();
                return;
            }
            mpWebhookFinalizarEvento(
                $conexao,
                $idEvento,
                (int) $transacao['id_transacao'],
                $transacao['id_assinatura_pagamento'] === null
                    ? null
                    : (int) $transacao['id_assinatura_pagamento']
            );
            $conexao->commit();
            return;
        }

        $mapa = [
            'created' => 'aguardando',
            'action_required' => 'aguardando',
            'processing' => 'processando',
            'in_review' => 'processando',
            'failed' => 'recusada',
            'expired' => 'expirada',
            'canceled' => 'cancelada',
        ];
        $novoStatus = $mapa[$statusOrder] ?? null;
        if ($novoStatus === null || $transacao['status'] === 'confirmada') {
            mpWebhookMarcarConciliacao(
                $conexao,
                $transacao,
                $idEvento,
                $statusOrder,
                $detalheOrder,
                'Estado autoritativo da Order não pode ser aplicado automaticamente.'
            );
            $conexao->commit();
            return;
        }
        if ($transacao['status'] === 'processando' && $novoStatus === 'aguardando') {
            $novoStatus = 'processando';
        }
        $recusouAgora = $novoStatus === 'recusada' && $transacao['status'] !== 'recusada';
        mpWebhookExecutar(
            $conexao,
            'UPDATE transacao_pagamento
                SET ordem_externa_id=?,pagamento_externo_id=COALESCE(pagamento_externo_id,?),
                    status=?,status_externo=?,detalhe_status_externo=?,ultima_consulta_em=NOW(),
                    erro_codigo=?,requer_conciliacao=0,motivo_conciliacao=NULL
              WHERE id_transacao=? AND id_empresa=?',
            'ssssssii',
            $order['id'],
            $idPagamentoExterno,
            $novoStatus,
            $statusOrder,
            $detalheOrder,
            $novoStatus === 'recusada' ? ($detalheOrder ?? 'pagamento_recusado') : null,
            (int) $transacao['id_transacao'],
            (int) $transacao['id_empresa']
        );
        if ($recusouAgora) {
            auditoriaRegistrar($conexao, 'pagamento.recusado_gateway', [
                'ator' => mpWebhookAtorSistema((int) $transacao['id_empresa']),
                'entidade_id' => (int) $transacao['id_transacao'],
                'entidade_rotulo' => 'Transação de pagamento #' . (int) $transacao['id_transacao'],
                'alteracoes' => mpWebhookAlteracoes([
                    'id_empresa' => (int) $transacao['id_empresa'],
                    'id_cobranca' => (int) $transacao['id_cobranca'],
                    'id_transacao' => (int) $transacao['id_transacao'],
                    'status' => 'recusada',
                    'status_externo' => $statusOrder,
                    'detalhe_status_externo' => $detalheOrder,
                ], ['status' => $transacao['status']]),
                'contexto' => ['origem' => mpWebhookOrigemAuditoria()],
            ]);
        }
        mpWebhookFinalizarEvento(
            $conexao,
            $idEvento,
            (int) $transacao['id_transacao'],
            $transacao['id_assinatura_pagamento'] === null
                ? null
                : (int) $transacao['id_assinatura_pagamento']
        );
        $conexao->commit();
    } catch (Throwable $erro) {
        mpWebhookRollback($conexao);
        throw $erro;
    }
}

/**
 * Concilia agora as transações Orders (PIX) ainda abertas de UMA cobrança, pela mesma sequência da conciliação
 * periódica (conciliar_pagamentos_mercado_pago.php): GET da Order, normalização, localização inequívoca e
 * mpWebhookAplicarOrder() (transação própria). Um PIX expirado ou cancelado no provedor deixa de bloquear; um PIX pago
 * é registrado (e, se a cobrança já estava quitada, fica em conciliação como sobrepagamento). Sem resposta válida do
 * provedor, nada muda. Deve ser chamada fora de transação.
 *
 * @return array{consultadas: int, falhas: int}
 */
function mpWebhookConciliarOrdersCobranca(mysqli $conexao, object $gateway, int $idEmpresa, int $idCobranca): array
{
    $resumo = ['consultadas' => 0, 'falhas' => 0];
    $ambiente = $gateway->ambiente();
    $transacoes = mpWebhookConsulta(
        $conexao,
        "SELECT id_transacao, ordem_externa_id FROM transacao_pagamento
          WHERE id_empresa = ? AND id_cobranca = ? AND provedor = ? AND ambiente = ?
            AND tipo_operacao <> 'assinatura_preapproval' AND ordem_externa_id IS NOT NULL AND requer_conciliacao = 0
            AND status IN ('aguardando', 'processando')
          ORDER BY id_transacao",
        'iiss',
        $idEmpresa,
        $idCobranca,
        MP_WEBHOOK_PROVEDOR,
        $ambiente
    )->fetch_all(MYSQLI_ASSOC);
    foreach ($transacoes as $transacao) {
        $ordemId = (string)$transacao['ordem_externa_id'];
        $resposta = $gateway->requisitar('consultar_order', ['order_id' => $ordemId]);
        $resumo['consultadas']++;
        $order = ($resposta['sucesso'] ?? false) && is_array($resposta['dados'] ?? null)
            ? mpWebhookNormalizarOrder($resposta['dados'], $ordemId) : null;
        if ($order === null || mpWebhookLocalizarTransacao($conexao, $order, $ambiente) !== (int)$transacao['id_transacao']) {
            $resumo['falhas']++;
            continue;
        }
        try {
            mpWebhookAplicarOrder($conexao, null, (int)$transacao['id_transacao'], $order, $ambiente);
        } catch (Throwable $e) {
            mpWebhookRollback($conexao);
            mpWebhookRegistrarFalha('conciliacao_orders_cobranca', ['transacao' => (int)$transacao['id_transacao'], 'classe' => get_class($e)]);
            $resumo['falhas']++;
        }
    }

    return $resumo;
}

/*
 * ---------------------------------------------------------------------
 * Subscriptions (Mercado Pago Preapproval): notificações e conciliação.
 *
 * Eventos: subscription_preapproval, subscription_authorized_payment e payment.
 * Isolado do tratamento de Orders: as funções `mpSub*` nunca tocam transações de Orders/PIX,
 * e uma notificação `payment` só é tratada quando se prova, pela consulta autoritativa, que ela
 * pertence a uma Preapproval vinculada localmente; qualquer outra é ignorada.
 *
 * - A notificação é apenas um gatilho: todo estado vem de GET /preapproval/{id},
 *   /authorized_payments/{id} e /v1/payments/{id}. Replay, atraso e ordem trocada convergem
 *   para o estado autoritativo corrente.
 * - Sem `subscriptions.habilitado === true` o gateway não faz chamadas e a porta HTTP responde
 *   sem reservar evento nem tocar o banco.
 * - Nenhuma função daqui inicia cobrança no provedor (somente GET).
 * - Pagamento só quita cobrança quando aprovado e creditado; pendente, recusado, em retry,
 *   divergente ou de outro vínculo nunca quita. Divergências ficam em conciliação.
 * ---------------------------------------------------------------------
 */
const MP_SUB_TIPOS = ['subscription_preapproval', 'subscription_authorized_payment', 'payment'];
/**
 * Tópicos que o Mercado Pago pode enviar à mesma URL mas que o AmAgenda não usa (assinaturas sem plano; reclamações são
 * acompanhadas pelo status do pagamento e pela conciliação). Depois do HMAC válido recebem 200 sem tocar o banco, para o
 * provedor não repetir a notificação a cada 15 minutos.
 */
const MP_SUB_TIPOS_SEM_ACAO = ['subscription_preapproval_plan', 'topic_claims_integration_wh'];const MP_SUB_TOLERANCIA_COMPETENCIA_DIAS = 1;
const MP_SUB_BUSCA_LIMITE = 50;
const MP_SUB_BUSCA_PAGINAS = 4;

/** Escopo opcional da CLI. O marcador fica no WHERE externo, antes dos demais parâmetros. */
function mpSubConsultaEmpresa(mysqli $conexao, string $sql, ?int $idEmpresa, string $coluna = 'id_empresa', string $tipos = '', mixed ...$valores): array
{
    if (($idEmpresa !== null && $idEmpresa <= 0)
        || !in_array($coluna, ['id_empresa', 'a.id_empresa', 'o.id_empresa', 't.id_empresa', 'COALESCE(a.id_empresa,t.id_empresa)'], true)
        || substr_count($sql, '/*empresa*/') !== 1) {
        throw new InvalidArgumentException('Escopo de empresa inválido para Subscriptions.');
    }
    $filtro = $idEmpresa === null ? '' : $coluna . ' = ? AND';
    return assinaturaServicoConsultaFinanceira(
        $conexao,
        str_replace('/*empresa*/', $filtro, $sql),
        ($idEmpresa === null ? '' : 'i') . $tipos,
        ...($idEmpresa === null ? $valores : [$idEmpresa, ...$valores])
    );
}

/** Também valida resultados antes de delegar ações por ID; escopo inválido falha fechado. */
function mpSubConferirEmpresa(?int $idEmpresa, array $registro): void
{
    if ($idEmpresa !== null && ($idEmpresa <= 0 || (int)($registro['id_empresa'] ?? 0) !== $idEmpresa)) {
        throw new RuntimeException('Recurso fora do escopo de empresa de Subscriptions.');
    }
}

/** Revalida a operação e seu vínculo após liberar locks, antes de usar o gateway. */
function mpSubConferirOperacaoEmpresa(mysqli $conexao, ?int $idEmpresa, int $idOperacao, array $ref): void
{
    if ($idEmpresa === null) return;
    mpSubConferirEmpresa($idEmpresa, $ref);
    $atual = mpSubConsultaEmpresa($conexao,
        "SELECT o.id_empresa FROM operacao_assinatura_gateway o
         JOIN assinatura_pagamento_recorrente a ON a.id_assinatura_pagamento = o.id_assinatura_pagamento AND a.id_empresa = o.id_empresa
         WHERE /*empresa*/ o.id_operacao = ? AND a.id_assinatura_pagamento = ? AND a.assinatura_externa_id = ?",
        $idEmpresa, 'o.id_empresa', 'iis', $idOperacao,
        (int)$ref['id_assinatura_pagamento'], (string)$ref['assinatura_externa_id']
    )[0] ?? null;
    if ($atual === null) throw new RuntimeException('Operação fora do escopo de empresa.');
    mpSubConferirEmpresa($idEmpresa, $atual);
}

function mpSubResultado(string $resultado, ?string $codigo = null, array $extra = []): array
{
    return ['resultado' => $resultado, 'codigo' => $codigo] + $extra;
}

function mpSubCentavos(mixed $valor): ?int
{
    if (!(is_int($valor) || is_float($valor) || (is_string($valor) && is_numeric($valor)))) {
        return null;
    }
    $numero = (float)$valor;
    if (!is_finite($numero) || $numero < 0 || $numero > 99999999) {
        return null;
    }

    return (int)round($numero * 100);
}

/** @return array<string, mixed>|string dados normalizados da fatura ou código do problema */
function mpSubNormalizarFatura(array $d, string $idEsperado): array|string
{
    $id = mpWebhookCampoExterno($d['id'] ?? null, 150);
    if ($id === null || strtolower($id) !== strtolower($idEsperado)) {
        return 'FATURA_ID_DIVERGENTE';
    }
    $preapproval = mpWebhookCampoExterno($d['preapproval_id'] ?? null, 150);
    if ($preapproval === null) {
        return 'FATURA_SEM_PREAPPROVAL';
    }
    // Valores documentados: scheduled, processed, recycling, cancelled e "waiting for gateway" (com espaços).
    $status = is_string($d['status'] ?? null) && preg_match('/^[A-Za-z _-]{1,50}$/D', $d['status']) === 1
        ? str_replace('canceled', 'cancelled', strtolower(str_replace(' ', '_', $d['status'])))
        : null;
    if ($status === null) {
        return 'FATURA_STATUS_INVALIDO';
    }
    if (($d['currency_id'] ?? null) !== 'BRL') {
        return 'FATURA_MOEDA_DIVERGENTE';
    }
    $centavos = mpSubCentavos($d['transaction_amount'] ?? null);
    if ($centavos === null || $centavos <= 0) {
        return 'FATURA_VALOR_INVALIDO';
    }
    $pagamento = is_array($d['payment'] ?? null) ? $d['payment'] : [];

    return [
        'id' => $id,
        'preapproval_id' => $preapproval,
        'status' => $status,
        'valor_centavos' => $centavos,
        'debito' => assinaturaServicoPreapprovalDataExterna($d['debit_date'] ?? null),
        'tentativa' => is_int($d['retry_attempt'] ?? null) && $d['retry_attempt'] >= 0 ? $d['retry_attempt'] : 0,
        'referencia' => is_string($d['external_reference'] ?? null) ? $d['external_reference'] : null,
        'pagamento_id' => mpWebhookCampoExterno($pagamento['id'] ?? null, 150),
        'pagamento_status' => mpWebhookCampoExterno($pagamento['status'] ?? null, 50),
    ];
}

/** @return array<string, mixed>|string dados normalizados do pagamento ou código do problema */
function mpSubNormalizarPagamento(array $d, string $idEsperado, string $ambiente): array|string
{
    $id = mpWebhookCampoExterno($d['id'] ?? null, 150);
    if ($id === null || strtolower($id) !== strtolower($idEsperado)) {
        return 'PAGAMENTO_ID_DIVERGENTE';
    }
    if (array_key_exists('live_mode', $d) && is_bool($d['live_mode']) && $d['live_mode'] !== ($ambiente === 'producao')) {
        return 'PAGAMENTO_AMBIENTE_DIVERGENTE';
    }
    if (($d['currency_id'] ?? null) !== 'BRL') {
        return 'PAGAMENTO_MOEDA_DIVERGENTE';
    }
    $valor = mpSubCentavos($d['transaction_amount'] ?? null);
    $reembolsado = mpSubCentavos($d['transaction_amount_refunded'] ?? 0);
    $status = mpWebhookCampoExterno($d['status'] ?? null, 50);
    if ($valor === null || $valor <= 0 || $reembolsado === null || $status === null) {
        return 'PAGAMENTO_DADOS_INVALIDOS';
    }
    $metadata = is_array($d['metadata'] ?? null) ? $d['metadata'] : [];

    return [
        'id' => $id,
        'status' => $status,
        'detalhe' => mpWebhookCampoExterno($d['status_detail'] ?? null, 100),
        'valor_centavos' => $valor,
        'reembolsado_centavos' => $reembolsado,
        'referencia' => is_string($d['external_reference'] ?? null) ? $d['external_reference'] : null,
        'preapproval_id' => mpWebhookCampoExterno($metadata['preapproval_id'] ?? null, 150),
        'aprovado_em' => assinaturaServicoPreapprovalDataExterna($d['date_approved'] ?? null),
    ];
}

/**
 * Estado local derivado da fatura e do pagamento autoritativos.
 * classe: 'confirmada' | 'estorno_total' | 'conciliacao' | 'normal'.
 *
 * @param array<string, mixed>|string|null $pagamento
 * @return array{status: string, classe: string, motivo: ?string, detalhe: ?string}
 */
function mpSubClassificarFatura(array $fatura, array|string|null $pagamento): array
{
    $r = static fn (string $status, string $classe, ?string $motivo = null, ?string $detalhe = null): array
        => ['status' => $status, 'classe' => $classe, 'motivo' => $motivo, 'detalhe' => $detalhe];
    $statusFatura = (string)$fatura['status'];

    if (is_string($pagamento)) {
        return $r('conciliacao', 'conciliacao', 'Pagamento externo inválido ou de outro ambiente.');
    }
    if ($pagamento === null) {
        if ($fatura['pagamento_id'] !== null) {
            return $r('conciliacao', 'conciliacao', 'Pagamento da fatura não pôde ser verificado.');
        }

        return match ($statusFatura) {
            'scheduled' => $r('aguardando', 'normal'),
            'recycling' => $r('processando', 'normal', null, 'retry'),
            'waiting_for_gateway' => $r('processando', 'normal', null, 'aguardando_gateway'),
            'cancelled' => $r('cancelada', 'normal'),
            default => $r('conciliacao', 'conciliacao', 'Fatura em estado não reconhecido ou sem pagamento associado.'),
        };
    }

    $statusPagamento = (string)$pagamento['status'];
    $detalhe = $pagamento['detalhe'];
    $parcial = $pagamento['reembolsado_centavos'] > 0 && $pagamento['reembolsado_centavos'] < $pagamento['valor_centavos'];
    if ($statusPagamento === 'refunded') {
        return $pagamento['reembolsado_centavos'] >= $pagamento['valor_centavos']
            ? $r('confirmada', 'estorno_total', null, $detalhe)
            : $r('conciliacao', 'conciliacao', 'Reembolso parcial exige conciliação financeira.', $detalhe);
    }
    if ($statusPagamento === 'charged_back' || $statusPagamento === 'in_mediation' && $statusFatura === 'processed') {
        return $r('conciliacao', 'conciliacao', 'Contestação (chargeback) exige conciliação financeira.', $detalhe);
    }
    if ($statusPagamento === 'approved') {
        if ($parcial) {
            return $r('conciliacao', 'conciliacao', 'Reembolso parcial exige conciliação financeira.', $detalhe);
        }
        if ($detalhe !== 'accredited' || $statusFatura !== 'processed') {
            return $r('conciliacao', 'conciliacao', 'Pagamento aprovado sem crédito confirmado ou com fatura em estado incompatível.', $detalhe);
        }

        return $r('confirmada', 'confirmada', null, $detalhe);
    }
    if (in_array($statusPagamento, ['pending', 'in_process', 'authorized', 'in_mediation'], true)) {
        return $r('processando', 'normal', null, $detalhe);
    }
    if ($statusPagamento === 'rejected') {
        return $statusFatura === 'recycling'
            ? $r('processando', 'normal', null, 'retry')
            : $r('recusada', 'normal', null, $detalhe);
    }
    if ($statusPagamento === 'cancelled' || $statusPagamento === 'expired') {
        return $r('cancelada', 'normal', null, $detalhe);
    }

    return $r('conciliacao', 'conciliacao', 'Estado do pagamento externo não reconhecido.', $detalhe);
}

function mpSubVinculoPorPreapproval(mysqli $conexao, string $ambiente, string $preapprovalId, ?int $idEmpresa = null): ?array
{
    $vinculo = mpSubConsultaEmpresa(
        $conexao,
        "SELECT id_assinatura_pagamento, id_empresa, id_assinatura, ambiente, status, versao_externa,
                referencia_externa_assinatura, proxima_cobranca_em
           FROM assinatura_pagamento_recorrente
          WHERE /*empresa*/ provedor = ? AND ambiente = ? AND motor_recorrencia = 'preapproval' AND assinatura_externa_id = ?
          LIMIT 1",
        $idEmpresa,
        'id_empresa',
        'sss',
        MP_WEBHOOK_PROVEDOR,
        $ambiente,
        $preapprovalId
    )[0] ?? null;
    if ($vinculo !== null) mpSubConferirEmpresa($idEmpresa, $vinculo);
    return $vinculo;
}

/** Assinatura externa sem vínculo: se a referência é de uma tentativa local ainda sem vínculo, a notificação deve ser repetida. */
function mpSubNaoReconhecido(mysqli $conexao, string $ambiente, ?string $referencia): array
{
    if ($referencia !== null && preg_match('/^amg_[0-9a-f]{32}$/D', $referencia) === 1) {
        $pendente = assinaturaServicoConsultaFinanceira(
            $conexao,
            "SELECT 1 FROM assinatura_pagamento_recorrente
              WHERE provedor = ? AND ambiente = ? AND motor_recorrencia = 'preapproval'
                AND referencia_externa_assinatura = ? AND assinatura_externa_id IS NULL LIMIT 1",
            'sss',
            MP_WEBHOOK_PROVEDOR,
            $ambiente,
            $referencia
        ) !== [];
        if ($pendente) {
            return mpSubResultado('erro', 'vinculo_pendente');
        }
    }

    return mpSubResultado('ignorado', 'assinatura_externa_nao_reconhecida');
}

function mpSubEncerrarEvento(mysqli $conexao, ?int $idEvento, string $status, ?string $codigo, ?int $idTransacao = null, ?int $idAssinaturaPagamento = null): void
{
    if ($idEvento === null) {
        return;
    }
    $codigo = $codigo === null ? null : (mpWebhookCampoExterno($codigo, 100) ?? 'falha_processamento');
    assinaturaServicoPreapprovalExec(
        $conexao,
        "UPDATE evento_gateway_pagamento
            SET id_transacao = COALESCE(?, id_transacao),
                id_assinatura_pagamento = COALESCE(?, id_assinatura_pagamento),
                status_processamento = ?, erro_codigo = ?,
                processado_em = CASE WHEN ? IN ('processado', 'ignorado') THEN NOW(6) ELSE processado_em END,
                atualizado_em = NOW()
          WHERE id_evento_gateway = ? AND status_processamento = 'processando'",
        'iisssi',
        $idTransacao,
        $idAssinaturaPagamento,
        $status,
        $codigo,
        $status,
        $idEvento
    );
}

/** Marca o vínculo com o último problema detectado, sem texto do provedor. */
function mpSubSinalizarVinculo(mysqli $conexao, array $vinculo, string $codigo, string $motivo): void
{
    assinaturaServicoPreapprovalExec(
        $conexao,
        'UPDATE assinatura_pagamento_recorrente SET ultimo_erro_codigo = ?, ultimo_erro_motivo = ?
          WHERE id_assinatura_pagamento = ? AND id_empresa = ?',
        'ssii',
        mb_substr($codigo, 0, 100, 'UTF-8'),
        mb_substr($motivo, 0, 300, 'UTF-8'),
        (int)$vinculo['id_assinatura_pagamento'],
        (int)$vinculo['id_empresa']
    );
}

/**
 * Primeira ativação: a próxima cobrança autorizada não pode cair no período já pago. Deve rodar na transação da
 * sincronização, com a assinatura já bloqueada. Sem período pago vigente a regra não se aplica (nada a proteger).
 */
function mpSubCobrancaAntecipadaNaAtivacao(mysqli $conexao, array $vinculo, array $dados, array $assinatura): ?string
{
    if ((string)$assinatura['modalidade'] === 'teste') {
        // Teste grátis: nenhuma parcela antes do fim do teste.
        return assinaturaServicoPreapprovalConferirInicioExterno($dados, assinaturaServicoPreapprovalInicioTeste((string)$assinatura['teste_expira_em']));
    }
    $fimPago = assinaturaServicoFimPeriodoPago($conexao, (int)$vinculo['id_empresa'], (int)$vinculo['id_assinatura']);
    if ($fimPago === null) {
        return null;
    }

    return assinaturaServicoPreapprovalConferirInicioExterno($dados, assinaturaServicoPreapprovalInicioCobranca($fimPago));
}

/** Mapeia o status externo da Preapproval ao vínculo local; nunca reativa vínculo cancelado/encerrado. */
function mpSubSincronizarPreapproval(mysqli $conexao, object $gateway, string $ambiente, string $preapprovalId, ?int $idEvento, ?int $idEmpresa = null): array
{
    if ($idEmpresa !== null && mpSubVinculoPorPreapproval($conexao, $ambiente, $preapprovalId, $idEmpresa) === null) {
        return mpSubResultado('erro', 'vinculo_fora_do_escopo');
    }
    $resposta = $gateway->consultarPreapproval($preapprovalId);
    if (($resposta['sucesso'] ?? false) !== true || !is_array($resposta['dados'] ?? null)) {
        return mpSubResultado('erro', mpWebhookCampoExterno($resposta['codigo'] ?? null, 100) ?? 'consulta_preapproval_falhou');
    }
    $dados = $resposta['dados'];
    $dados['status'] = mpSubStatusPreapproval($dados);
    if ((string)($dados['id'] ?? '') !== $preapprovalId) {
        return mpSubResultado('ignorado', 'preapproval_id_divergente');
    }
    $vinculo = mpSubVinculoPorPreapproval($conexao, $ambiente, $preapprovalId, $idEmpresa);
    if ($vinculo === null) {
        if ($idEmpresa !== null) return mpSubResultado('erro', 'vinculo_fora_do_escopo');
        return mpSubNaoReconhecido($conexao, $ambiente, is_string($dados['external_reference'] ?? null) ? $dados['external_reference'] : null);
    }

    $contrato = assinaturaServicoConsultaFinanceira(
        $conexao,
        'SELECT valor_contratado, periodicidade FROM assinatura WHERE id_assinatura = ? AND id_empresa = ?',
        'ii',
        (int)$vinculo['id_assinatura'],
        (int)$vinculo['id_empresa']
    )[0] ?? null;
    $frequencia = $contrato === null ? null : assinaturaServicoPreapprovalFrequenciaMeses((string)$contrato['periodicidade']);
    $divergencia = $contrato === null || $frequencia === null ? 'CONTRATO_LOCAL_INDISPONIVEL' : assinaturaServicoPreapprovalValidarExterna($dados, [
        'referencia' => (string)$vinculo['referencia_externa_assinatura'],
        'ambiente' => $ambiente,
        'valor_centavos' => pagamentoGatewayValorCentavos((string)$contrato['valor_contratado']),
        'frequencia_meses' => $frequencia,
        'id' => $preapprovalId,
        'status_permitidos' => ['pending', 'authorized', 'paused', 'canceled'],
    ]);
    if ($divergencia === 'VALOR_EXTERNO_DIVERGENTE'
        && (($dados['status'] ?? null) === 'canceled' || in_array((string)$vinculo['status'], ['cancelamento_pendente', 'cancelada', 'encerrada'], true))) {
        // Assinatura cancelada ou em cancelamento: o valor deixa de importar e não pode impedir a confirmação do cancelamento.
        $divergencia = null;
    }
    if ($divergencia === 'VALOR_EXTERNO_DIVERGENTE') {
        // Troca de plano em andamento: o valor anterior e o novo da operação são transições esperadas, não divergência.
        foreach (assinaturaServicoPreapprovalValoresTransitorios($conexao, (int)$vinculo['id_assinatura_pagamento']) as $alternativo) {
            $ainda = assinaturaServicoPreapprovalValidarExterna($dados, [
                'referencia' => (string)$vinculo['referencia_externa_assinatura'],
                'ambiente' => $ambiente,
                'valor_centavos' => $alternativo,
                'frequencia_meses' => $frequencia,
                'id' => $preapprovalId,
                'status_permitidos' => ['pending', 'authorized', 'paused', 'canceled'],
            ]);
            if ($ainda === null) {
                $divergencia = null;
                break;
            }
        }
    }
    if ($divergencia !== null) {
        mpSubSinalizarVinculo($conexao, $vinculo, $divergencia, 'Assinatura externa divergente do contrato interno.');

        return mpSubResultado('ignorado', $divergencia, ['id_assinatura_pagamento' => (int)$vinculo['id_assinatura_pagamento']]);
    }

    $versaoRef = assinaturaServicoPreapprovalDataExterna($dados['last_modified'] ?? null)
        ?? assinaturaServicoPreapprovalDataExterna($dados['date_created'] ?? null);
    $versao = $versaoRef === null ? 1 : max(1, ((int)$versaoRef->format('U')) * 1000 + (int)$versaoRef->format('v'));
    $proximaData = assinaturaServicoPreapprovalDataExterna($dados['next_payment_date'] ?? null);
    $statusExterno = (string)$dados['status'];

    $conexao->begin_transaction();
    try {
        $assinatura = assinaturaServicoConsultaFinanceira(
            $conexao,
            "SELECT a.status, a.modalidade, a.motivo_suspensao, a.data_fim, p.gera_cobranca,
                    DATE_FORMAT(a.teste_expira_em, '%Y-%m-%d %H:%i:%s') AS teste_expira_em
               FROM assinatura a INNER JOIN plano p ON p.id_plano = a.id_plano
              WHERE a.id_assinatura = ? AND a.id_empresa = ? FOR UPDATE",
            'ii',
            (int)$vinculo['id_assinatura'],
            (int)$vinculo['id_empresa']
        )[0] ?? null;
        $atual = assinaturaServicoConsultaFinanceira(
            $conexao,
            "SELECT id_assinatura_pagamento, status, versao_externa, proxima_cobranca_em
               FROM assinatura_pagamento_recorrente
              WHERE id_assinatura_pagamento = ? AND id_empresa = ? AND ambiente = ?
                AND motor_recorrencia = 'preapproval' AND assinatura_externa_id = ? FOR UPDATE",
            'iiss',
            (int)$vinculo['id_assinatura_pagamento'],
            (int)$vinculo['id_empresa'],
            $ambiente,
            $preapprovalId
        )[0] ?? null;
        if ($assinatura === null || $atual === null) {
            $conexao->rollback();

            return mpSubResultado('ignorado', 'vinculo_alterado');
        }
        $idAspr = (int)$atual['id_assinatura_pagamento'];
        if ($atual['versao_externa'] !== null && $versao < (int)$atual['versao_externa']) {
            mpSubEncerrarEvento($conexao, $idEvento, 'processado', null, null, $idAspr);
            $conexao->commit();

            return mpSubResultado('processado', 'versao_antiga', ['id_assinatura_pagamento' => $idAspr]);
        }

        $statusLocal = (string)$atual['status'];
        // Contratação nova ainda não paga também é apta: a Preapproval autorizada vai cobrar a primeira mensalidade.
        // O vínculo ativo é só a recorrência autorizada; o contrato só é ativado depois do pagamento confirmado.
        // Teste grátis com cartão autorizado também é apto: a Preapproval cobra a primeira mensalidade no fim do teste. O
        // contrato segue em teste (sem cobrança nem conversão) até a fatura paga ser conciliada.
        $assinaturaApta = (int)$assinatura['gera_cobranca'] === 1 && $assinatura['data_fim'] === null
            && ((((string)$assinatura['status'] === 'ativa' || assinaturaServicoPreapprovalContratacaoPendente($assinatura))
                    && (string)$assinatura['modalidade'] === 'paga')
                || assinaturaServicoPreapprovalTesteApto($assinatura));
        $novoStatus = $statusLocal;
        $sinal = null;
        $encerrado = in_array($statusLocal, ['cancelada', 'encerrada'], true);
        // Intenção de cancelar em curso: só a confirmação de 'canceled' encerra o vínculo; o slot ativo é mantido.
        $cancelando = $statusLocal === 'cancelamento_pendente';
        $operacaoAberta = assinaturaServicoConsultaFinanceira(
            $conexao,
            "SELECT tipo_operacao FROM operacao_assinatura_gateway
              WHERE id_assinatura_pagamento = ? AND status_processamento IN ('pendente', 'processando', 'conciliacao')",
            'i',
            $idAspr
        )[0]['tipo_operacao'] ?? null;
        if ($statusExterno === 'authorized') {
            if ($encerrado) {
                $sinal = 'VINCULO_ENCERRADO_REAUTORIZADO';
            } elseif ($cancelando) {
                $novoStatus = $statusLocal;
            } elseif (in_array($operacaoAberta, ['cancelar', 'pausar'], true)) {
                $sinal = 'OPERACAO_EXTERNA_PENDENTE';
            } elseif (!$assinaturaApta) {
                $sinal = 'ASSINATURA_LOCAL_INCOMPATIVEL';
            } elseif ($statusLocal === 'pendente'
                && ($antecipada = mpSubCobrancaAntecipadaNaAtivacao($conexao, $vinculo, $dados, $assinatura)) !== null) {
                // Autorizada, mas a próxima cobrança cairia no período já pago: não ativa, sinaliza e mantém o PIX
                // bloqueado para a competência (a Preapproval autorizada pode cobrar). Requer análise manual.
                $sinal = $antecipada;
            } else {
                $novoStatus = $statusLocal === 'atualizacao_necessaria' ? $statusLocal : 'ativa';
            }
        } elseif ($statusExterno === 'paused') {
            if (!$encerrado && !$cancelando) {
                $novoStatus = 'suspensa';
                if (in_array($statusLocal, ['ativa', 'atualizacao_necessaria'], true) && $operacaoAberta !== 'pausar') {
                    $sinal = 'PAUSA_EXTERNA_SEM_SOLICITACAO';
                }
            }
        } elseif ($statusExterno === 'canceled') {
            if (!$encerrado) {
                $novoStatus = 'cancelada';
                if (in_array($statusLocal, ['ativa', 'atualizacao_necessaria'], true) && $operacaoAberta !== 'cancelar') {
                    // Cancelamento pelo provedor sem pedido do AmAgenda: o contrato interno não é alterado. O provedor cancela
                    // sozinho após três faturas recusadas; a última tentativa recusada distingue esse caso.
                    $ultimaTentativa = assinaturaServicoConsultaFinanceira(
                        $conexao,
                        "SELECT status FROM transacao_pagamento
                          WHERE id_assinatura_pagamento = ? AND tipo_operacao = 'assinatura_preapproval'
                          ORDER BY id_transacao DESC LIMIT 1",
                        'i',
                        $idAspr
                    )[0]['status'] ?? null;
                    $sinal = $ultimaTentativa === 'recusada'
                        ? 'CANCELAMENTO_EXTERNO_POR_FALTA_DE_PAGAMENTO'
                        : 'CANCELAMENTO_EXTERNO_SEM_SOLICITACAO';
                }
            }
        } elseif ($statusExterno === 'pending' && $statusLocal !== 'pendente' && !$encerrado && !$cancelando) {
            $sinal = 'STATUS_EXTERNO_REGREDIU';
        }

        $slot = in_array($novoStatus, ['ativa', 'atualizacao_necessaria', 'suspensa', 'cancelamento_pendente'], true) ? 1 : null;
        $proxima = $proximaData === null
            ? ($atual['proxima_cobranca_em'] === null ? null : (string)$atual['proxima_cobranca_em'])
            : $proximaData->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s.u');
        $cobrarAPartirDe = $slot === 1
            ? substr($proxima ?? (new DateTimeImmutable())->format('Y-m-d H:i:s.u'), 0, 19)
            : null;
        $parametros = [
            $novoStatus, $slot, $cobrarAPartirDe, $statusExterno, $versao, $proxima,
            $sinal, $sinal === null ? null : 'Estado externo incompatível com o vínculo local.',
            $novoStatus, $statusLocal, $novoStatus, $statusLocal, $novoStatus, $statusLocal,
            $idAspr, (int)$vinculo['id_empresa'], $versao,
        ];
        try {
            assinaturaServicoPreapprovalExec(
                $conexao,
                "UPDATE assinatura_pagamento_recorrente
                    SET status = ?, slot_ativo = ?, cobrar_a_partir_de = ?, status_externo = ?, versao_externa = ?,
                        proxima_cobranca_em = ?, ultima_sincronizacao_em = NOW(6),
                        ultimo_erro_codigo = ?, ultimo_erro_motivo = ?,
                        suspenso_em = CASE WHEN ? = 'suspensa' AND ? <> 'suspensa' THEN NOW() ELSE suspenso_em END,
                        reativado_em = CASE WHEN ? = 'ativa' AND ? = 'suspensa' THEN NOW() ELSE reativado_em END,
                        cancelado_em = CASE WHEN ? = 'cancelada' AND ? <> 'cancelada' THEN NOW() ELSE cancelado_em END
                  WHERE id_assinatura_pagamento = ? AND id_empresa = ?
                    AND (versao_externa IS NULL OR versao_externa <= ?)",
                'sississsssssssiii',
                ...$parametros
            );
        } catch (RuntimeException $e) {
            if (!assinaturaServicoPreapprovalChaveDuplicada($e)) {
                throw $e;
            }
            // Outro vínculo da empresa já ocupa o slot ativo: nada é ativado e o caso fica sinalizado.
            $conexao->rollback();
            $conexao->begin_transaction();
            mpSubSinalizarVinculo($conexao, $vinculo, 'CONFLITO_SLOT_ATIVO', 'Outro vínculo recorrente já está ativo para a empresa.');
            mpSubEncerrarEvento($conexao, $idEvento, 'processado', 'CONFLITO_SLOT_ATIVO', null, $idAspr);
            $conexao->commit();

            return mpSubResultado('processado', 'CONFLITO_SLOT_ATIVO', ['id_assinatura_pagamento' => $idAspr]);
        }
        mpSubEncerrarEvento($conexao, $idEvento, 'processado', null, null, $idAspr);
        $conexao->commit();

        // Intenção derivada do contrato (cancelar/pausar uma Preapproval ainda autorizada): vai ao outbox, sem rede.
        if (in_array($statusExterno, ['authorized', 'paused', 'pending'], true)) {
            try {
                assinaturaServicoPreapprovalGarantirOperacao($conexao, $idAspr, mpWebhookAtorSistema((int)$vinculo['id_empresa']), $idEmpresa);
            } catch (Throwable $e) {
                mpWebhookRollback($conexao);
                mpWebhookRegistrarFalha('garantir_operacao_falhou', ['vinculo' => $idAspr, 'classe' => get_class($e)]);
            }
        }

        return mpSubResultado('processado', $sinal, ['id_assinatura_pagamento' => $idAspr, 'status' => $novoStatus]);
    } catch (Throwable $e) {
        mpWebhookRollback($conexao);
        throw $e;
    }
}

/**
 * Competência da fatura: a cobrança regular cujo início coincide (±1 dia) com a data de débito, ou a
 * próxima cobrança da sequência (mesma regra do gerador automático), criada no mesmo formato.
 * Deve rodar na transação, com a assinatura bloqueada.
 *
 * @param array<string, mixed> $assinatura
 * @return array{cobranca: array<string, mixed>, criada: bool}|string
 */
function mpSubResolverCobranca(mysqli $conexao, array $assinatura, ?DateTimeImmutable $debito): array|string
{
    if ($debito === null) {
        return 'FATURA_SEM_DATA_DEBITO';
    }
    $fuso = new DateTimeZone(ASSINATURA_SERVICO_PREAPPROVAL_FUSO);
    $dia = $debito->setTimezone($fuso)->setTime(0, 0);
    $colunas = 'id_cobranca, id_empresa, id_assinatura, periodo_inicio, periodo_fim, data_vencimento, valor, status, finalidade, motor_cobranca';
    $todas = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT {$colunas} FROM cobranca WHERE id_assinatura = ? AND id_empresa = ? ORDER BY periodo_inicio, id_cobranca FOR UPDATE",
        'ii',
        (int)$assinatura['id_assinatura'],
        (int)$assinatura['id_empresa']
    );
    if ((string)$assinatura['modalidade'] === 'teste') {
        return mpSubResolverCobrancaConversaoTeste($conexao, $assinatura, $dia, $todas);
    }
    $aceitas = [];
    $ultimoFim = null;
    foreach ($todas as $cobranca) {
        if ($ultimoFim === null || (string)$cobranca['periodo_fim'] > $ultimoFim) {
            $ultimoFim = (string)$cobranca['periodo_fim'];
        }
        if ((string)$cobranca['finalidade'] !== 'regular' || (string)$cobranca['status'] === 'cancelada') {
            continue;
        }
        $inicio = new DateTimeImmutable((string)$cobranca['periodo_inicio'] . ' 00:00:00', $fuso);
        if (abs((int)$inicio->diff($dia)->format('%r%a')) <= MP_SUB_TOLERANCIA_COMPETENCIA_DIAS) {
            $aceitas[] = $cobranca;
        }
    }
    if (count($aceitas) > 1) {
        return 'COMPETENCIA_AMBIGUA';
    }
    if (count($aceitas) === 1) {
        return ['cobranca' => $aceitas[0], 'criada' => false];
    }
    if (assinaturaServicoPreapprovalContratacaoPendente($assinatura)) {
        // Primeira parcela de uma contratação ainda não ativada fora da data da oferta (ex.: checkout antigo autorizado
        // dias depois): o período e as renovações não conferem. O pagamento fica em conciliação e o contrato não é
        // ativado; nunca se cria competência para contrato não ativado.
        return 'PRIMEIRA_COBRANCA_FORA_DO_PERIODO';
    }

    $esperado = $ultimoFim === null
        ? new DateTimeImmutable((string)$assinatura['data_inicio'] . ' 00:00:00', $fuso)
        : (new DateTimeImmutable($ultimoFim . ' 00:00:00', $fuso))->modify('+1 day');
    if (abs((int)$esperado->diff($dia)->format('%r%a')) > MP_SUB_TOLERANCIA_COMPETENCIA_DIAS) {
        return 'COMPETENCIA_NAO_ALINHADA';
    }
    $fim = $esperado->modify('+' . assinaturaServicoMesesPeriodicidade((string)$assinatura['periodicidade']) . ' months')->modify('-1 day');
    if ($assinatura['data_fim'] !== null && $fim->format('Y-m-d') > (string)$assinatura['data_fim']) {
        return 'COMPETENCIA_APOS_ENCERRAMENTO';
    }
    $vencimento = assinaturaServicoCalcularVencimento($esperado, (int)$assinatura['dia_vencimento']);
    try {
        assinaturaServicoPreapprovalExec(
            $conexao,
            "INSERT INTO cobranca (id_empresa, id_assinatura, periodo_inicio, periodo_fim, data_vencimento, valor, status, finalidade, motor_cobranca)
             VALUES (?, ?, ?, ?, ?, ?, 'pendente', 'regular', 'preapproval')",
            'iissss',
            (int)$assinatura['id_empresa'],
            (int)$assinatura['id_assinatura'],
            $esperado->format('Y-m-d'),
            $fim->format('Y-m-d'),
            $vencimento->format('Y-m-d'),
            (string)$assinatura['valor_contratado']
        );
    } catch (RuntimeException $e) {
        if (assinaturaServicoPreapprovalChaveDuplicada($e)) {
            return 'COMPETENCIA_DUPLICADA';
        }
        throw $e;
    }
    $criada = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT {$colunas} FROM cobranca WHERE id_cobranca = ? AND id_empresa = ? FOR UPDATE",
        'ii',
        (int)$conexao->insert_id,
        (int)$assinatura['id_empresa']
    )[0] ?? null;

    return $criada === null ? 'COMPETENCIA_NAO_ALINHADA' : ['cobranca' => $criada, 'criada' => true];
}

/**
 * Competência da primeira mensalidade de um teste grátis com cartão autorizado: a cobrança 'conversao_trial' que começa
 * no dia em que o teste termina (assinaturaServicoPreapprovalInicioTeste). Débito antes desse dia, ou mais de
 * MP_SUB_TOLERANCIA_COMPETENCIA_DIAS depois, não é competência válida (vai para conciliação); nunca se cria cobrança
 * regular para o período do teste. Na transação de mpSubAplicarFatura, com a assinatura bloqueada.
 *
 * @param array<string, mixed> $assinatura
 * @param list<array<string, mixed>> $todas cobranças do contrato, bloqueadas
 * @return array{cobranca: array<string, mixed>, criada: bool}|string
 */
function mpSubResolverCobrancaConversaoTeste(mysqli $conexao, array $assinatura, DateTimeImmutable $dia, array $todas): array|string
{
    if (in_array((string)$assinatura['status'], ['cancelada', 'encerrada'], true) || $assinatura['data_fim'] !== null) {
        return 'COMPETENCIA_APOS_ENCERRAMENTO';
    }
    if (!assinaturaServicoPreapprovalTesteApto($assinatura)) {
        return 'ASSINATURA_TRIAL_INCONSISTENTE';
    }
    $inicio = assinaturaServicoPreapprovalInicioTeste((string)$assinatura['teste_expira_em'])->setTime(0, 0);
    $atraso = (int)$inicio->diff($dia)->format('%r%a');
    if ($atraso < 0) {
        return 'COBRANCA_EXTERNA_ANTECIPADA'; // Débito dentro do teste grátis: nada é quitado nem convertido.
    }
    if ($atraso > MP_SUB_TOLERANCIA_COMPETENCIA_DIAS) {
        return 'COMPETENCIA_NAO_ALINHADA';
    }

    $periodoInicio = $inicio->format('Y-m-d');
    $conversoes = [];
    foreach ($todas as $cobranca) {
        if ((string)$cobranca['status'] === 'cancelada') {
            continue;
        }
        if ((string)$cobranca['finalidade'] !== 'conversao_trial') {
            return 'ASSINATURA_TRIAL_INCONSISTENTE'; // Contrato em teste não tem cobrança regular.
        }
        $conversoes[] = $cobranca;
    }
    if (count($conversoes) > 1) {
        return 'COMPETENCIA_AMBIGUA';
    }
    if (count($conversoes) === 1) {
        // A cobrança de conversão de outro período (ex.: regularização por PIX) não pode receber a parcela do cartão.
        return (string)$conversoes[0]['periodo_inicio'] === $periodoInicio
            ? ['cobranca' => $conversoes[0], 'criada' => false]
            : 'COMPETENCIA_CONVERSAO_DIVERGENTE';
    }

    $fim = $inicio->modify('+' . assinaturaServicoMesesPeriodicidade((string)$assinatura['periodicidade']) . ' months')->modify('-1 day');
    $vencimento = assinaturaServicoCalcularVencimento($inicio, (int)$assinatura['dia_vencimento']);
    try {
        assinaturaServicoPreapprovalExec(
            $conexao,
            "INSERT INTO cobranca (id_empresa, id_assinatura, periodo_inicio, periodo_fim, data_vencimento, valor, status, finalidade)
             VALUES (?, ?, ?, ?, ?, ?, 'pendente', 'conversao_trial')",
            'iissss',
            (int)$assinatura['id_empresa'],
            (int)$assinatura['id_assinatura'],
            $periodoInicio,
            $fim->format('Y-m-d'),
            $vencimento->format('Y-m-d'),
            (string)$assinatura['valor_contratado']
        );
    } catch (RuntimeException $e) {
        if (assinaturaServicoPreapprovalChaveDuplicada($e)) {
            return 'COMPETENCIA_DUPLICADA';
        }
        throw $e;
    }
    $idCobranca = (int)$conexao->insert_id;
    $criada = assinaturaServicoConsultaFinanceira(
        $conexao,
        'SELECT id_cobranca, id_empresa, id_assinatura, periodo_inicio, periodo_fim, data_vencimento, valor, status, finalidade, motor_cobranca
           FROM cobranca WHERE id_cobranca = ? AND id_empresa = ? FOR UPDATE',
        'ii',
        $idCobranca,
        (int)$assinatura['id_empresa']
    )[0] ?? null;
    if ($criada === null) {
        return 'COMPETENCIA_NAO_ALINHADA';
    }
    auditoriaRegistrar($conexao, 'cobranca.gerada', [
        'ator' => mpWebhookAtorSistema((int)$assinatura['id_empresa']),
        'entidade_id' => $idCobranca,
        'entidade_rotulo' => 'Cobrança de conversão da assinatura #' . (int)$assinatura['id_assinatura'],
        'descricao' => 'Gerou a cobrança da primeira mensalidade após o teste grátis, cobrada no cartão autorizado.',
        'alteracoes' => mpWebhookAlteracoes([
            'id_empresa' => (int)$assinatura['id_empresa'],
            'id_assinatura' => (int)$assinatura['id_assinatura'],
            'periodo_inicio' => $periodoInicio,
            'periodo_fim' => $fim->format('Y-m-d'),
            'data_vencimento' => $vencimento->format('Y-m-d'),
            'valor' => (string)$assinatura['valor_contratado'],
            'status' => 'pendente',
            'finalidade' => 'conversao_trial',
        ]),
        'contexto' => ['origem' => mpWebhookOrigemAuditoria(), 'origem_geracao' => 'preapproval_teste', 'motor' => 'preapproval'],
    ]);

    return ['cobranca' => $criada, 'criada' => true];
}

/**
 * Aplica a fatura autoritativa (e o pagamento autoritativo, quando houver) numa única transação.
 * Locks: assinatura, cobrança, vínculo, pagamentos e transação, nessa ordem; ambos os caminhos
 * (webhook e conciliação) serializam por assinatura, então a mesma fatura nunca é aplicada em paralelo.
 *
 * @param array<string, mixed> $vinculo
 * @param array<string, mixed> $fatura
 * @param array<string, mixed>|string|null $pagamento
 */
function mpSubAplicarFatura(mysqli $conexao, string $ambiente, array $vinculo, array $fatura, array|string|null $pagamento, ?int $idEvento, ?string $pagamentoAnteriorRecusado = null): array
{
    $idEmpresa = (int)$vinculo['id_empresa'];
    $idAspr = (int)$vinculo['id_assinatura_pagamento'];
    $classe = mpSubClassificarFatura($fatura, $pagamento);
    $colunasTransacao = 'id_transacao, id_empresa, id_cobranca, id_pagamento, id_assinatura_pagamento, provedor, ambiente, metodo,
                         tipo_operacao, valor, moeda, status, referencia_interna, fatura_externa_id, pagamento_externo_id,
                         status_externo, detalhe_status_externo, confirmado_em, requer_conciliacao';

    $conexao->begin_transaction();
    try {
        $assinatura = assinaturaServicoConsultaFinanceira(
            $conexao,
            "SELECT id_assinatura, id_empresa, valor_contratado, periodicidade, dia_vencimento, data_inicio, data_fim,
                    status, modalidade, motivo_suspensao, DATE_FORMAT(teste_expira_em, '%Y-%m-%d %H:%i:%s') AS teste_expira_em
               FROM assinatura WHERE id_assinatura = ? AND id_empresa = ? FOR UPDATE",
            'ii',
            (int)$vinculo['id_assinatura'],
            $idEmpresa
        )[0] ?? null;
        $vinculoTravado = assinaturaServicoConsultaFinanceira(
            $conexao,
            "SELECT id_assinatura_pagamento, status, cancelado_em FROM assinatura_pagamento_recorrente
              WHERE id_assinatura_pagamento = ? AND id_empresa = ? AND provedor = ? AND ambiente = ?
                AND motor_recorrencia = 'preapproval' AND assinatura_externa_id = ? FOR UPDATE",
            'iisss',
            $idAspr,
            $idEmpresa,
            MP_WEBHOOK_PROVEDOR,
            $ambiente,
            (string)$fatura['preapproval_id']
        )[0] ?? null;
        if ($assinatura === null || $vinculoTravado === null) {
            $conexao->rollback();

            return mpSubResultado('ignorado', 'vinculo_alterado');
        }

        $existente = assinaturaServicoConsultaFinanceira(
            $conexao,
            'SELECT id_transacao, id_empresa, id_cobranca FROM transacao_pagamento
              WHERE provedor = ? AND ambiente = ? AND fatura_externa_id = ? LIMIT 1',
            'sss',
            MP_WEBHOOK_PROVEDOR,
            $ambiente,
            (string)$fatura['id']
        )[0] ?? null;
        if ($existente !== null && (int)$existente['id_empresa'] !== $idEmpresa) {
            $conexao->rollback();

            return mpSubResultado('ignorado', 'FATURA_DE_OUTRA_EMPRESA');
        }

        // Cobrança devida antes do cancelamento (notificação atrasada) segue o fluxo normal; só débito posterior ao
        // cancelamento confirmado é anomalia do provedor.
        $debitoLocal = $fatura['debito'] === null ? null : $fatura['debito']->setTimezone(new DateTimeZone(ASSINATURA_SERVICO_PREAPPROVAL_FUSO))->format('Y-m-d');
        $canceladoEm = $vinculoTravado['cancelado_em'] === null ? null : substr((string)$vinculoTravado['cancelado_em'], 0, 10);
        $debitoAposCancelamento = $debitoLocal === null || $canceladoEm === null || $debitoLocal > $canceladoEm;
        if ($existente === null && $debitoAposCancelamento && in_array((string)$vinculoTravado['status'], ['cancelada', 'encerrada'], true)) {
            // Uma Preapproval cancelada não deve cobrar. Se há pagamento, ele é preservado em conciliação; nada é quitado.
            $motivoCancelada = 'Fatura recebida de assinatura recorrente já cancelada.';
            mpSubSinalizarVinculo($conexao, $vinculo, 'FATURA_APOS_CANCELAMENTO', $motivoCancelada);
            $guardada = $fatura['pagamento_id'] !== null
                && mpSubRegistrarFaturaOrfa($conexao, $ambiente, $vinculo, $assinatura, $fatura, $pagamento, $idEvento, $motivoCancelada);
            if (!$guardada) {
                mpSubEncerrarEvento($conexao, $idEvento, 'ignorado', 'FATURA_APOS_CANCELAMENTO', null, $idAspr);
            }
            $conexao->commit();

            return mpSubResultado($guardada ? 'processado' : 'ignorado', 'FATURA_APOS_CANCELAMENTO', ['id_assinatura_pagamento' => $idAspr]);
        }

        if ($existente !== null) {
            $cobranca = assinaturaServicoConsultaFinanceira(
                $conexao,
                'SELECT id_cobranca, id_empresa, id_assinatura, valor, status, finalidade, motor_cobranca
                   FROM cobranca WHERE id_cobranca = ? AND id_empresa = ? FOR UPDATE',
                'ii',
                (int)$existente['id_cobranca'],
                $idEmpresa
            )[0] ?? null;
            $criada = false;
            $erroCompetencia = $cobranca === null ? 'COBRANCA_DA_FATURA_INEXISTENTE' : null;
        } else {
            $resolvida = mpSubResolverCobranca($conexao, $assinatura, $fatura['debito']);
            $erroCompetencia = is_string($resolvida) ? $resolvida : null;
            $cobranca = is_array($resolvida) ? $resolvida['cobranca'] : null;
            $criada = is_array($resolvida) && $resolvida['criada'];
        }
        if ($cobranca === null) {
            // Sem competência não há onde registrar a transação: o caso é sinalizado no vínculo.
            mpSubSinalizarVinculo($conexao, $vinculo, (string)$erroCompetencia, 'Fatura externa sem competência interna correspondente.');
            // Com pagamento envolvido, o registro fica numa transação em conciliação (cobrança mais recente do contrato).
            $guardada = $fatura['pagamento_id'] !== null
                && mpSubRegistrarFaturaOrfa($conexao, $ambiente, $vinculo, $assinatura, $fatura, $pagamento, $idEvento, 'Fatura com pagamento sem competência interna: ' . $erroCompetencia . '.');
            if (!$guardada) {
                mpSubEncerrarEvento($conexao, $idEvento, 'ignorado', (string)$erroCompetencia, null, $idAspr);
            }
            $conexao->commit();

            return mpSubResultado($guardada ? 'processado' : 'ignorado', $erroCompetencia, ['id_assinatura_pagamento' => $idAspr]);
        }
        if (!$criada && (string)$cobranca['motor_cobranca'] === 'local') {
            assinaturaServicoPreapprovalExec(
                $conexao,
                "UPDATE cobranca SET motor_cobranca = 'preapproval'
                  WHERE id_cobranca = ? AND id_empresa = ? AND finalidade = 'regular' AND motor_cobranca = 'local'",
                'ii',
                (int)$cobranca['id_cobranca'],
                $idEmpresa
            );
        }

        assinaturaServicoConsultaFinanceira(
            $conexao,
            'SELECT id_pagamento FROM pagamento WHERE id_empresa = ? AND id_cobranca = ? FOR UPDATE',
            'ii',
            $idEmpresa,
            (int)$cobranca['id_cobranca']
        );

        $transacao = $existente === null ? null : (assinaturaServicoConsultaFinanceira(
            $conexao,
            "SELECT {$colunasTransacao} FROM transacao_pagamento WHERE id_transacao = ? AND id_empresa = ? FOR UPDATE",
            'ii',
            (int)$existente['id_transacao'],
            $idEmpresa
        )[0] ?? null);
        if ($transacao === null) {
            assinaturaServicoPreapprovalExec(
                $conexao,
                "INSERT INTO transacao_pagamento
                    (id_empresa, id_cobranca, id_assinatura_pagamento, provedor, ambiente, metodo, tipo_operacao, valor, moeda,
                     status, chave_idempotencia, referencia_interna, fatura_externa_id, numero_tentativa, ultima_consulta_em)
                 VALUES (?, ?, ?, ?, ?, 'cartao_recorrente', 'assinatura_preapproval', ?, 'BRL', 'criada', ?, ?, ?, ?, NOW())",
                'iiissssssi',
                $idEmpresa,
                (int)$cobranca['id_cobranca'],
                $idAspr,
                MP_WEBHOOK_PROVEDOR,
                $ambiente,
                pagamentoGatewayValorDecimal($fatura['valor_centavos']),
                pagamentoGatewayChaveIdempotencia(),
                pagamentoGatewayReferenciaInterna(),
                (string)$fatura['id'],
                max(1, min(65000, $fatura['tentativa'] + 1))
            );
            $transacao = assinaturaServicoConsultaFinanceira(
                $conexao,
                "SELECT {$colunasTransacao} FROM transacao_pagamento WHERE id_transacao = ? AND id_empresa = ? FOR UPDATE",
                'ii',
                (int)$conexao->insert_id,
                $idEmpresa
            )[0];
        }
        $idTransacao = (int)$transacao['id_transacao'];

        $statusExterno = (string)$fatura['status'];
        $detalheExterno = $classe['detalhe'];
        $valorCobranca = pagamentoGatewayValorCentavos((string)$cobranca['valor']);
        $pagamentoOk = is_array($pagamento) ? $pagamento : null;
        $motivo = null;
        $retentativa = false;
        if ((int)$transacao['id_cobranca'] !== (int)$cobranca['id_cobranca']
            || (int)$transacao['id_assinatura_pagamento'] !== $idAspr
            || $transacao['tipo_operacao'] !== 'assinatura_preapproval'
            || $transacao['metodo'] !== 'cartao_recorrente'
            || $transacao['moeda'] !== 'BRL'
            || pagamentoGatewayValorCentavos((string)$transacao['valor']) !== $fatura['valor_centavos']) {
            $motivo = 'Fatura divergente da transação financeira local.';
        } elseif ($fatura['valor_centavos'] !== $valorCobranca) {
            $motivo = 'Valor da fatura diverge do valor da cobrança.';
        } elseif ($pagamentoOk !== null && $pagamentoOk['valor_centavos'] !== $fatura['valor_centavos']) {
            $motivo = 'Valor do pagamento diverge do valor da fatura.';
        } elseif ($pagamentoOk !== null && $pagamentoOk['referencia'] !== null
            && $pagamentoOk['referencia'] !== (string)$vinculo['referencia_externa_assinatura']
            && ($pagamentoOk['preapproval_id'] ?? null) !== (string)$fatura['preapproval_id']) {
            $motivo = 'Pagamento sem vínculo com a assinatura recorrente.';
        } elseif ($pagamentoOk !== null && $transacao['pagamento_externo_id'] !== null
            && $transacao['pagamento_externo_id'] !== $pagamentoOk['id']
            // Retentativa do provedor (novo pagamento na mesma fatura) só com recusa do pagamento anterior comprovada no
            // provedor (mpSubPagamentoAnteriorRecusado) e transação sem pagamento local nem confirmação.
            && !($retentativa = $pagamentoAnteriorRecusado !== null && $transacao['pagamento_externo_id'] === $pagamentoAnteriorRecusado
                && $transacao['id_pagamento'] === null && in_array($transacao['status'], ['recusada', 'processando', 'aguardando'], true))) {
            $motivo = 'Payment ID divergente da transação financeira local.';
        } elseif ($classe['classe'] === 'conciliacao') {
            $motivo = (string)$classe['motivo'];
        } elseif ($transacao['status'] === 'confirmada' && $classe['classe'] === 'normal') {
            $motivo = 'Estado externo regrediu após a confirmação do pagamento.';
        } elseif ($pagamentoOk !== null) {
            $outra = assinaturaServicoConsultaFinanceira(
                $conexao,
                'SELECT 1 FROM transacao_pagamento WHERE provedor = ? AND ambiente = ? AND pagamento_externo_id = ? AND id_transacao <> ? LIMIT 1',
                'sssi',
                MP_WEBHOOK_PROVEDOR,
                $ambiente,
                $pagamentoOk['id'],
                $idTransacao
            );
            if ($outra !== []) {
                $motivo = 'Payment ID já associado a outra transação local.';
            }
        }
        if ($motivo !== null) {
            mpWebhookMarcarConciliacao($conexao, $transacao, $idEvento, $statusExterno, $detalheExterno, $motivo);
            mpSubGuardarPagamentoExterno($conexao, $ambiente, $transacao, $pagamentoOk);
            mpSubEncerrarEvento($conexao, $idEvento, 'processado', null, $idTransacao, $idAspr);
            $conexao->commit();

            return mpSubResultado('processado', 'CONCILIACAO', ['id_transacao' => $idTransacao, 'id_assinatura_pagamento' => $idAspr]);
        }

        if ($retentativa) {
            // A tentativa anterior (recusada no provedor) fica registrada na auditoria; a transação passa a acompanhar a nova.
            assinaturaServicoPreapprovalExec(
                $conexao,
                'UPDATE transacao_pagamento SET pagamento_externo_id = ? WHERE id_transacao = ? AND id_empresa = ? AND pagamento_externo_id = ? AND id_pagamento IS NULL',
                'siis',
                (string)$pagamentoOk['id'],
                $idTransacao,
                $idEmpresa,
                (string)$pagamentoAnteriorRecusado
            );
            auditoriaRegistrar($conexao, 'pagamento.recusado_gateway', [
                'ator' => mpWebhookAtorSistema($idEmpresa),
                'entidade_id' => $idTransacao,
                'entidade_rotulo' => 'Transação de pagamento #' . $idTransacao,
                'alteracoes' => mpWebhookAlteracoes([
                    'id_empresa' => $idEmpresa,
                    'id_cobranca' => (int)$cobranca['id_cobranca'],
                    'id_transacao' => $idTransacao,
                    'status' => 'recusada',
                    'status_externo' => $transacao['status_externo'],
                    'detalhe_status_externo' => $transacao['detalhe_status_externo'],
                ], ['status' => $transacao['status']]),
                'contexto' => ['origem' => mpWebhookOrigemAuditoria(), 'motivo' => 'Retentativa do provedor: pagamento ' . $pagamentoAnteriorRecusado . ' recusado substituído por ' . $pagamentoOk['id'] . '.'],
            ]);
            $transacao['pagamento_externo_id'] = (string)$pagamentoOk['id'];
        }
        $idPagamentoExterno = $pagamentoOk['id'] ?? null;
        $ator = mpWebhookAtorSistema($idEmpresa);
        $concluirContratacao = false;
        if ($classe['classe'] === 'confirmada' && $pagamentoOk !== null) {
            $existentePag = assinaturaServicoConsultaFinanceira(
                $conexao,
                'SELECT id_pagamento, id_empresa, id_cobranca, valor_pago, data_pagamento, forma_pagamento, origem, status
                   FROM pagamento WHERE provedor = ? AND referencia_externa = ? FOR UPDATE',
                'ss',
                MP_WEBHOOK_PROVEDOR,
                $idPagamentoExterno
            )[0] ?? null;
            $valorReal = pagamentoGatewayValorDecimal($pagamentoOk['valor_centavos']);
            $criouPagamento = false;
            if ($existentePag !== null) {
                $coerente = (int)$existentePag['id_empresa'] === $idEmpresa
                    && (int)$existentePag['id_cobranca'] === (int)$cobranca['id_cobranca']
                    && pagamentoGatewayValorCentavos((string)$existentePag['valor_pago']) === $pagamentoOk['valor_centavos']
                    && $existentePag['forma_pagamento'] === 'cartao_credito'
                    && $existentePag['origem'] === 'gateway'
                    && $existentePag['status'] === 'confirmado';
                if (!$coerente) {
                    mpWebhookMarcarConciliacao($conexao, $transacao, $idEvento, $statusExterno, $detalheExterno, 'Payment ID já associado a pagamento local divergente.');
                    mpSubEncerrarEvento($conexao, $idEvento, 'processado', null, $idTransacao, $idAspr);
                    $conexao->commit();

                    return mpSubResultado('processado', 'CONCILIACAO', ['id_transacao' => $idTransacao, 'id_assinatura_pagamento' => $idAspr]);
                }
                $idPagamento = (int)$existentePag['id_pagamento'];
            } else {
                $aprovadoEm = $pagamentoOk['aprovado_em'] === null
                    ? null
                    : $pagamentoOk['aprovado_em']->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
                assinaturaServicoPreapprovalExec(
                    $conexao,
                    "INSERT INTO pagamento (id_empresa, id_cobranca, valor_pago, data_pagamento, forma_pagamento, origem, status, provedor, referencia_externa)
                     VALUES (?, ?, ?, COALESCE(?, NOW()), 'cartao_credito', 'gateway', 'confirmado', ?, ?)",
                    'iissss',
                    $idEmpresa,
                    (int)$cobranca['id_cobranca'],
                    $valorReal,
                    $aprovadoEm,
                    MP_WEBHOOK_PROVEDOR,
                    $idPagamentoExterno
                );
                $idPagamento = (int)$conexao->insert_id;
                $criouPagamento = true;
            }

            $total = mpWebhookTotalConfirmado($conexao, $idEmpresa, (int)$cobranca['id_cobranca']);
            $recalculo = mpWebhookRecalcularCobranca($conexao, $cobranca, $total);
            $motivos = [];
            if ($recalculo['sobrepagamento']) {
                $motivos[] = 'Total confirmado excede o valor da cobrança.';
            }
            if ($recalculo['cancelada']) {
                $motivos[] = 'Pagamento confirmado para cobrança cancelada.';
            }
            if ($transacao['id_pagamento'] !== null && (int)$transacao['id_pagamento'] !== $idPagamento) {
                $motivos[] = 'Transação já vinculada a outro pagamento local.';
            }
            $requer = $motivos === [] ? 0 : 1;
            if ($requer === 0 && !$recalculo['cancelada'] && $recalculo['saldo'] === 0
                && (string)$cobranca['finalidade'] === 'conversao_trial') {
                // Primeira mensalidade após o teste grátis: a mesma conversão do PIX de regularização (idempotente; exige a
                // cobrança de conversão integralmente quitada por pagamento confirmado).
                $conversaoTrial = assinaturaServicoReavaliarConversaoTrial($conexao, (int)$cobranca['id_cobranca'], $ator,
                    ['origem' => mpWebhookOrigemAuditoria(), 'motor' => 'preapproval']);
                if ($conversaoTrial['requer_atencao']) {
                    $motivos[] = 'Cobrança de conversão quitada, mas a assinatura não pôde ser convertida automaticamente.';
                    $requer = 1;
                }
            }
            // Primeira mensalidade quitada pela Preapproval: a contratação é ativada depois do commit (pagamento já persistido).
            $concluirContratacao = $requer === 0 && $recalculo['saldo'] === 0 && assinaturaServicoPreapprovalContratacaoPendente($assinatura);
            assinaturaServicoPreapprovalExec(
                $conexao,
                "UPDATE transacao_pagamento
                    SET id_pagamento = COALESCE(id_pagamento, ?), pagamento_externo_id = ?, status = 'confirmada',
                        status_externo = ?, detalhe_status_externo = ?, confirmado_em = COALESCE(confirmado_em, NOW()),
                        ultima_consulta_em = NOW(), requer_conciliacao = ?, motivo_conciliacao = ?, erro_codigo = NULL
                  WHERE id_transacao = ? AND id_empresa = ?",
                'isssisii',
                $idPagamento,
                $idPagamentoExterno,
                $statusExterno,
                $detalheExterno,
                $requer,
                $requer === 1 ? implode(' ', $motivos) : null,
                $idTransacao,
                $idEmpresa
            );
            if ($criouPagamento) {
                auditoriaRegistrar($conexao, 'pagamento.confirmado_gateway', [
                    'ator' => $ator,
                    'entidade_id' => $idPagamento,
                    'entidade_rotulo' => 'Pagamento da cobrança #' . (int)$cobranca['id_cobranca'],
                    'alteracoes' => mpWebhookAlteracoes([
                        'id_empresa' => $idEmpresa,
                        'id_cobranca' => (int)$cobranca['id_cobranca'],
                        'id_pagamento' => $idPagamento,
                        'valor_pago' => $valorReal,
                        'forma_pagamento' => 'cartao_credito',
                        'origem' => 'gateway',
                        'status' => 'confirmado',
                        'provedor' => MP_WEBHOOK_PROVEDOR,
                        'referencia_externa' => $idPagamentoExterno,
                        'total_pago_confirmado' => pagamentoGatewayValorDecimal($total),
                        'saldo_restante' => pagamentoGatewayValorDecimal($recalculo['saldo']),
                    ]),
                    'contexto' => ['origem' => mpWebhookOrigemAuditoria(), 'motor' => 'preapproval'],
                ]);
            }
        } elseif ($classe['classe'] === 'estorno_total' && $pagamentoOk !== null) {
            $pagamentoLocal = assinaturaServicoConsultaFinanceira(
                $conexao,
                'SELECT id_pagamento, id_empresa, id_cobranca, valor_pago, forma_pagamento, origem, status
                   FROM pagamento WHERE provedor = ? AND referencia_externa = ? FOR UPDATE',
                'ss',
                MP_WEBHOOK_PROVEDOR,
                $idPagamentoExterno
            )[0] ?? null;
            if ($pagamentoLocal === null || (int)$pagamentoLocal['id_empresa'] !== $idEmpresa
                || (int)$pagamentoLocal['id_cobranca'] !== (int)$cobranca['id_cobranca']
                || $pagamentoLocal['origem'] !== 'gateway' || $pagamentoLocal['forma_pagamento'] !== 'cartao_credito'
                || !in_array($pagamentoLocal['status'], ['confirmado', 'estornado'], true)
                || pagamentoGatewayValorCentavos((string)$pagamentoLocal['valor_pago']) !== $pagamentoOk['valor_centavos']) {
                mpWebhookMarcarConciliacao($conexao, $transacao, $idEvento, $statusExterno, $detalheExterno, 'Estorno sem pagamento local confirmado e coerente.');
                mpSubEncerrarEvento($conexao, $idEvento, 'processado', null, $idTransacao, $idAspr);
                $conexao->commit();

                return mpSubResultado('processado', 'CONCILIACAO', ['id_transacao' => $idTransacao, 'id_assinatura_pagamento' => $idAspr]);
            }
            $alterou = $pagamentoLocal['status'] === 'confirmado';
            if ($alterou) {
                assinaturaServicoPreapprovalExec(
                    $conexao,
                    "UPDATE pagamento SET status = 'estornado' WHERE id_pagamento = ? AND status = 'confirmado'",
                    'i',
                    (int)$pagamentoLocal['id_pagamento']
                );
            }
            $total = mpWebhookTotalConfirmado($conexao, $idEmpresa, (int)$cobranca['id_cobranca']);
            $recalculo = mpWebhookRecalcularCobranca($conexao, $cobranca, $total);
            if (!$recalculo['cancelada'] && (string)$cobranca['finalidade'] === 'conversao_trial') {
                // Estorno da primeira mensalidade após o teste: mesma reavaliação do PIX (suspende o contrato convertido).
                assinaturaServicoReavaliarConversaoTrial($conexao, (int)$cobranca['id_cobranca'], $ator,
                    ['origem' => mpWebhookOrigemAuditoria(), 'motor' => 'preapproval']);
            }
            assinaturaServicoPreapprovalExec(
                $conexao,
                'UPDATE transacao_pagamento
                    SET id_pagamento = COALESCE(id_pagamento, ?), pagamento_externo_id = ?, status_externo = ?,
                        detalhe_status_externo = ?, ultima_consulta_em = NOW(), erro_codigo = NULL
                  WHERE id_transacao = ? AND id_empresa = ?',
                'isssii',
                (int)$pagamentoLocal['id_pagamento'],
                $idPagamentoExterno,
                $statusExterno,
                $detalheExterno,
                $idTransacao,
                $idEmpresa
            );
            if ($alterou) {
                auditoriaRegistrar($conexao, 'pagamento.estornado_gateway', [
                    'ator' => $ator,
                    'entidade_id' => (int)$pagamentoLocal['id_pagamento'],
                    'entidade_rotulo' => 'Pagamento da cobrança #' . (int)$cobranca['id_cobranca'],
                    'alteracoes' => mpWebhookAlteracoes([
                        'id_empresa' => $idEmpresa,
                        'id_cobranca' => (int)$cobranca['id_cobranca'],
                        'id_pagamento' => (int)$pagamentoLocal['id_pagamento'],
                        'status' => 'estornado',
                        'provedor' => MP_WEBHOOK_PROVEDOR,
                        'referencia_externa' => $idPagamentoExterno,
                        'total_pago_confirmado' => pagamentoGatewayValorDecimal($total),
                        'saldo_restante' => pagamentoGatewayValorDecimal($recalculo['saldo']),
                    ], ['status' => 'confirmado']),
                    'contexto' => ['origem' => mpWebhookOrigemAuditoria(), 'motor' => 'preapproval'],
                ]);
            }
        } else {
            // aguardando / processando / recusada / cancelada: registra o estado, sem quitar nada.
            assinaturaServicoPreapprovalExec(
                $conexao,
                "UPDATE transacao_pagamento
                    SET status = ?, status_externo = ?, detalhe_status_externo = ?,
                        pagamento_externo_id = COALESCE(pagamento_externo_id, ?),
                        numero_tentativa = GREATEST(numero_tentativa, ?), ultima_consulta_em = NOW(),
                        erro_codigo = ?
                  WHERE id_transacao = ? AND id_empresa = ? AND status <> 'confirmada'",
                'ssssisii',
                $classe['status'],
                $statusExterno,
                $detalheExterno,
                $idPagamentoExterno,
                max(1, min(65000, $fatura['tentativa'] + 1)),
                $classe['status'] === 'recusada' ? ($detalheExterno ?? 'pagamento_recusado') : null,
                $idTransacao,
                $idEmpresa
            );
        }

        mpSubEncerrarEvento($conexao, $idEvento, 'processado', null, $idTransacao, $idAspr);
        $conexao->commit();

        return mpSubResultado('processado', null, [
            'id_transacao' => $idTransacao,
            'id_assinatura_pagamento' => $idAspr,
            'status' => $classe['status'],
            'cobranca_criada' => $criada,
            'contratacao' => $concluirContratacao ? mpSubConcluirContratacaoPaga($conexao, $idEmpresa) : null,
        ]);
    } catch (Throwable $e) {
        mpWebhookRollback($conexao);
        throw $e;
    }
}

/**
 * Ativa a contratação cuja primeira mensalidade acabou de ser confirmada pela Preapproval, pelas mesmas regras (e locks)
 * do CRON e do painel. Transação própria; falha não desfaz o pagamento já gravado: o CRON de contratações pagas e o
 * botão "Concluir contratação" refazem a tentativa.
 */
function mpSubConcluirContratacaoPaga(mysqli $conexao, int $idEmpresa): string
{
    try {
        $conexao->begin_transaction();
        $r = assinaturaServicoConcluirNovaContratacao($conexao, $idEmpresa, mpWebhookAtorSistema($idEmpresa), ['origem' => mpWebhookOrigemAuditoria(), 'motor' => 'preapproval']);
        if (($r['ok'] ?? false) !== true) {
            $conexao->rollback();

            return (string)($r['codigo'] ?? 'CONTRATACAO_NAO_CONCLUIDA');
        }
        $conexao->commit();

        return 'ativada';
    } catch (Throwable $e) {
        mpWebhookRollback($conexao);
        mpWebhookRegistrarFalha('contratacao_conclusao_falhou', ['empresa' => $idEmpresa, 'classe' => get_class($e)]);

        return 'falha';
    }
}

/** Fatura autorizada: consulta a fatura e o pagamento no provedor e aplica o estado autoritativo. */
function mpSubProcessarFatura(mysqli $conexao, object $gateway, string $ambiente, string $faturaId, ?int $idEvento, ?int $idEmpresa = null, ?string $preapprovalEsperada = null): array
{
    if ($idEmpresa !== null && ($preapprovalEsperada === null
        || mpSubVinculoPorPreapproval($conexao, $ambiente, $preapprovalEsperada, $idEmpresa) === null)) {
        return mpSubResultado('erro', 'vinculo_fora_do_escopo');
    }
    $resposta = $gateway->consultarFaturaAutorizada($faturaId);
    if (($resposta['sucesso'] ?? false) !== true || !is_array($resposta['dados'] ?? null)) {
        return mpSubResultado('erro', mpWebhookCampoExterno($resposta['codigo'] ?? null, 100) ?? 'consulta_fatura_falhou');
    }
    $fatura = mpSubNormalizarFatura($resposta['dados'], $faturaId);
    if (is_string($fatura)) {
        return mpSubResultado('ignorado', $fatura);
    }
    if ($preapprovalEsperada !== null && (string)$fatura['preapproval_id'] !== $preapprovalEsperada) {
        return mpSubResultado('erro', 'fatura_fora_do_escopo');
    }
    $vinculo = mpSubVinculoPorPreapproval($conexao, $ambiente, (string)$fatura['preapproval_id'], $idEmpresa);
    if ($vinculo === null) {
        if ($idEmpresa !== null) return mpSubResultado('erro', 'vinculo_fora_do_escopo');
        return mpSubNaoReconhecido($conexao, $ambiente, $fatura['referencia']);
    }
    if ($fatura['referencia'] !== null && $fatura['referencia'] !== (string)$vinculo['referencia_externa_assinatura']) {
        mpSubSinalizarVinculo($conexao, $vinculo, 'REFERENCIA_FATURA_DIVERGENTE', 'Fatura com referência externa diferente do vínculo.');

        return mpSubResultado('ignorado', 'REFERENCIA_FATURA_DIVERGENTE', ['id_assinatura_pagamento' => (int)$vinculo['id_assinatura_pagamento']]);
    }

    $pagamento = null;
    if ($fatura['pagamento_id'] !== null) {
        $consulta = $gateway->consultarPagamento($fatura['pagamento_id']);
        if (($consulta['sucesso'] ?? false) !== true || !is_array($consulta['dados'] ?? null)) {
            return mpSubResultado('erro', mpWebhookCampoExterno($consulta['codigo'] ?? null, 100) ?? 'consulta_pagamento_falhou');
        }
        $pagamento = mpSubNormalizarPagamento($consulta['dados'], $fatura['pagamento_id'], $ambiente);
        if ($pagamento === 'PAGAMENTO_AMBIENTE_DIVERGENTE') {
            return mpSubResultado('ignorado', $pagamento);
        }
    }

    return mpSubAplicarFatura($conexao, $ambiente, $vinculo, $fatura, $pagamento, $idEvento,
        mpSubPagamentoAnteriorRecusado($conexao, $gateway, $ambiente, $fatura, $pagamento));
}

/**
 * Retentativa do provedor (novo pagamento na mesma fatura): devolve o Payment ID anterior somente quando a consulta
 * autoritativa ao provedor prova que ele foi recusado ou cancelado. Consulta indisponível ou qualquer outro estado
 * (em processamento, aprovado, incerto) devolve null, e a divergência segue para conciliação. Sem lock: a aplicação
 * confere de novo, sob lock, que a transação ainda aponta para esse pagamento.
 */
function mpSubPagamentoAnteriorRecusado(mysqli $conexao, object $gateway, string $ambiente, array $fatura, array|string|null $pagamento): ?string
{
    if (!is_array($pagamento)) {
        return null;
    }
    $anterior = assinaturaServicoConsultaFinanceira(
        $conexao,
        'SELECT pagamento_externo_id FROM transacao_pagamento WHERE provedor = ? AND ambiente = ? AND fatura_externa_id = ? AND id_pagamento IS NULL LIMIT 1',
        'sss',
        MP_WEBHOOK_PROVEDOR,
        $ambiente,
        (string)$fatura['id']
    )[0]['pagamento_externo_id'] ?? null;
    if ($anterior === null || (string)$anterior === $pagamento['id']) {
        return null;
    }
    $consulta = $gateway->consultarPagamento((string)$anterior);
    if (($consulta['sucesso'] ?? false) !== true || !is_array($consulta['dados'] ?? null)) {
        return null;
    }
    $dados = mpSubNormalizarPagamento($consulta['dados'], (string)$anterior, $ambiente);

    return is_array($dados) && in_array($dados['status'], ['rejected', 'cancelled'], true) ? (string)$anterior : null;
}

/**
 * Notificação `payment`: só é tratada se o pagamento pertencer, de forma autoritativa, a uma fatura de
 * Preapproval vinculada. Pagamentos de Orders/PIX ou sem vínculo são ignorados sem efeito.
 */
function mpSubProcessarPagamento(mysqli $conexao, object $gateway, string $ambiente, string $pagamentoId, ?int $idEvento): array
{
    $local = assinaturaServicoConsultaFinanceira(
        $conexao,
        'SELECT tipo_operacao, fatura_externa_id FROM transacao_pagamento
          WHERE provedor = ? AND ambiente = ? AND pagamento_externo_id = ? LIMIT 1',
        'sss',
        MP_WEBHOOK_PROVEDOR,
        $ambiente,
        $pagamentoId
    )[0] ?? null;
    if ($local !== null) {
        return $local['tipo_operacao'] === 'assinatura_preapproval' && $local['fatura_externa_id'] !== null
            ? mpSubProcessarFatura($conexao, $gateway, $ambiente, (string)$local['fatura_externa_id'], $idEvento)
            : mpSubResultado('ignorado', 'payment_de_outro_fluxo');
    }

    $consulta = $gateway->consultarPagamento($pagamentoId);
    if (($consulta['sucesso'] ?? false) !== true || !is_array($consulta['dados'] ?? null)) {
        return mpSubResultado('erro', mpWebhookCampoExterno($consulta['codigo'] ?? null, 100) ?? 'consulta_pagamento_falhou');
    }
    $pagamento = mpSubNormalizarPagamento($consulta['dados'], $pagamentoId, $ambiente);
    if (is_string($pagamento)) {
        return mpSubResultado('ignorado', $pagamento);
    }

    $vinculo = null;
    if ($pagamento['preapproval_id'] !== null) {
        $vinculo = mpSubVinculoPorPreapproval($conexao, $ambiente, $pagamento['preapproval_id']);
    }
    if ($vinculo === null && $pagamento['referencia'] !== null) {
        $vinculo = assinaturaServicoConsultaFinanceira(
            $conexao,
            "SELECT id_assinatura_pagamento, id_empresa, id_assinatura, ambiente, status, versao_externa,
                    referencia_externa_assinatura, proxima_cobranca_em, assinatura_externa_id
               FROM assinatura_pagamento_recorrente
              WHERE provedor = ? AND ambiente = ? AND motor_recorrencia = 'preapproval'
                AND referencia_externa_assinatura = ? AND assinatura_externa_id IS NOT NULL LIMIT 1",
            'sss',
            MP_WEBHOOK_PROVEDOR,
            $ambiente,
            $pagamento['referencia']
        )[0] ?? null;
    }
    if ($vinculo === null) {
        return mpSubResultado('ignorado', 'payment_sem_vinculo_subscriptions');
    }

    $preapprovalId = (string)($vinculo['assinatura_externa_id'] ?? $pagamento['preapproval_id']);
    $faturaId = null;
    $offset = 0;
    for ($pagina = 0; $pagina < MP_SUB_BUSCA_PAGINAS && $faturaId === null; $pagina++) {
        $busca = $gateway->buscarFaturasAutorizadas([
            'preapproval_id' => $preapprovalId,
            'limit' => MP_SUB_BUSCA_LIMITE,
            'offset' => $offset,
        ]);
        if (($busca['sucesso'] ?? false) !== true || !is_array($busca['dados']['results'] ?? null)) {
            return mpSubResultado('erro', 'busca_faturas_falhou');
        }
        foreach ($busca['dados']['results'] as $item) {
            $idPagamentoItem = is_array($item) && is_array($item['payment'] ?? null)
                ? mpWebhookCampoExterno($item['payment']['id'] ?? null, 150)
                : null;
            if (is_array($item) && $idPagamentoItem !== null && $idPagamentoItem === $pagamento['id']
                && (string)($item['preapproval_id'] ?? '') === $preapprovalId) {
                $faturaId = (string)$item['id'];
                break;
            }
        }
        $total = $busca['dados']['paging']['total'] ?? null;
        $offset += MP_SUB_BUSCA_LIMITE;
        if (count($busca['dados']['results']) < MP_SUB_BUSCA_LIMITE || (is_int($total) && $offset >= $total)) {
            break;
        }
    }
    if ($faturaId === null) {
        // Pagamento aprovado antes da fatura aparecer: o evento fica em erro e a notificação será repetida.
        return mpSubResultado('erro', 'fatura_nao_localizada');
    }

    return mpSubProcessarFatura($conexao, $gateway, $ambiente, $faturaId, $idEvento);
}

/** Despacha a notificação reservada e garante o encerramento do evento. */
function mpSubProcessarEvento(mysqli $conexao, object $gateway, string $ambiente, int $idEvento, string $tipo, string $dataId): array
{
    $resultado = match ($tipo) {
        'subscription_preapproval' => mpSubSincronizarPreapproval($conexao, $gateway, $ambiente, $dataId, $idEvento),
        'subscription_authorized_payment' => mpSubProcessarFatura($conexao, $gateway, $ambiente, $dataId, $idEvento),
        'payment' => mpSubProcessarPagamento($conexao, $gateway, $ambiente, $dataId, $idEvento),
        default => mpSubResultado('ignorado', 'tipo_nao_suportado'),
    };
    if ($resultado['resultado'] === 'erro' && ($resultado['codigo'] ?? '') === 'fatura_nao_localizada') {
        // Cobranças de verificação do provedor não têm fatura: depois de algumas tentativas o evento deixa de ser reenviado.
        $anteriores = (int)(assinaturaServicoConsultaFinanceira(
            $conexao,
            "SELECT COUNT(*) AS n FROM evento_gateway_pagamento
              WHERE provedor = ? AND ambiente = ? AND tipo_recurso = 'payment' AND recurso_externo_id = ?
                AND erro_codigo = 'fatura_nao_localizada' AND id_evento_gateway <> ?",
            'sssi',
            MP_WEBHOOK_PROVEDOR,
            $ambiente,
            $dataId,
            $idEvento
        )[0]['n'] ?? 0);
        if ($anteriores >= 4) {
            $resultado = mpSubResultado('ignorado', 'payment_sem_fatura');
        }
    }
    $status = ['processado' => 'processado', 'ignorado' => 'ignorado'][$resultado['resultado']] ?? 'erro';
    mpSubEncerrarEvento(
        $conexao,
        $idEvento,
        $status,
        $status === 'processado' ? null : ($resultado['codigo'] ?? 'falha_processamento'),
        null,
        isset($resultado['id_assinatura_pagamento']) ? (int)$resultado['id_assinatura_pagamento'] : null
    );

    return $resultado;
}

/**
 * Conciliação independente de um vínculo: sincroniza a Preapproval e reaplica cada fatura vista no provedor
 * ou ainda em aberto localmente. Somente leitura no provedor; nunca inicia cobrança. Sem CRON configurado.
 *
 * @return array<string, mixed>
 */
function mpSubConciliarVinculo(mysqli $conexao, object $gateway, int $idAssinaturaPagamento, ?int $idEmpresa = null): array
{
    if (!$gateway::subscriptionsHabilitado()) {
        return mpSubResultado('erro', 'subscriptions_desabilitado');
    }
    $vinculo = mpSubConsultaEmpresa(
        $conexao,
        "SELECT id_assinatura_pagamento, id_empresa, ambiente, assinatura_externa_id
           FROM assinatura_pagamento_recorrente
          WHERE /*empresa*/ id_assinatura_pagamento = ? AND provedor = ? AND motor_recorrencia = 'preapproval'
            AND assinatura_externa_id IS NOT NULL",
        $idEmpresa,
        'id_empresa',
        'is',
        $idAssinaturaPagamento,
        MP_WEBHOOK_PROVEDOR
    )[0] ?? null;
    if ($vinculo === null) {
        return mpSubResultado('ignorado', 'vinculo_inexistente');
    }
    mpSubConferirEmpresa($idEmpresa, $vinculo);
    $ambiente = (string)$vinculo['ambiente'];
    if ($gateway->ambiente() !== $ambiente) {
        return mpSubResultado('erro', 'ambiente_incompativel');
    }
    $preapprovalId = (string)$vinculo['assinatura_externa_id'];

    $sincronia = mpSubSincronizarPreapproval($conexao, $gateway, $ambiente, $preapprovalId, null, $idEmpresa);
    if ($idEmpresa !== null && $sincronia['resultado'] === 'erro') return $sincronia;
    $faturas = [];
    $offset = 0;
    for ($pagina = 0; $pagina < MP_SUB_BUSCA_PAGINAS; $pagina++) {
        $busca = $gateway->buscarFaturasAutorizadas(['preapproval_id' => $preapprovalId, 'limit' => MP_SUB_BUSCA_LIMITE, 'offset' => $offset]);
        if (($busca['sucesso'] ?? false) !== true || !is_array($busca['dados']['results'] ?? null)) {
            return mpSubResultado('erro', 'busca_faturas_falhou', ['sincronizacao' => $sincronia['codigo'] ?? $sincronia['resultado']]);
        }
        foreach ($busca['dados']['results'] as $item) {
            if (is_array($item) && (string)($item['preapproval_id'] ?? '') === $preapprovalId && isset($item['id'])) {
                $faturas[(string)$item['id']] = true;
            }
        }
        $total = $busca['dados']['paging']['total'] ?? null;
        $offset += MP_SUB_BUSCA_LIMITE;
        if (count($busca['dados']['results']) < MP_SUB_BUSCA_LIMITE || (is_int($total) && $offset >= $total)) {
            break;
        }
    }
    foreach (assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT fatura_externa_id FROM transacao_pagamento
          WHERE id_empresa = ? AND id_assinatura_pagamento = ? AND tipo_operacao = 'assinatura_preapproval'
            AND fatura_externa_id IS NOT NULL
            AND (status IN ('criada', 'aguardando', 'processando', 'conciliacao') OR requer_conciliacao = 1)",
        'ii',
        (int)$vinculo['id_empresa'],
        $idAssinaturaPagamento
    ) as $aberta) {
        $faturas[(string)$aberta['fatura_externa_id']] = true;
    }

    $contagem = ['processado' => 0, 'ignorado' => 0, 'erro' => 0];
    foreach (array_keys($faturas) as $faturaId) {
        try {
            $r = mpSubProcessarFatura($conexao, $gateway, $ambiente, (string)$faturaId, null, $idEmpresa, $preapprovalId);
        } catch (Throwable $e) {
            mpWebhookRollback($conexao);
            $r = mpSubResultado('erro', 'falha_processamento');
        }
        $contagem[$r['resultado']]++;
    }

    return mpSubResultado('processado', null, [
        'id_assinatura_pagamento' => $idAssinaturaPagamento,
        'sincronizacao' => $sincronia['resultado'],
        'faturas' => count($faturas),
    ] + $contagem);
}

/*
 * ---------------------------------------------------------------------
 * Outbox de operações externas da Preapproval: pausar, reativar e cancelar.
 *
 * O ÚNICO ponto que escreve (PUT /preapproval/{id}) é mpSubOperacaoProcessar(), sempre sob estas regras:
 * - Uma operação aberta por vínculo (uq_oag_operacao_aberta); a mesma intenção (mesma linha e chave) é
 *   reutilizada em todos os retries.
 * - Antes de enviar, consulta autoritativa: se o provedor já está no destino, nada é enviado.
 * - O envio é marcado ('ENVIO_INICIADO') antes do PUT. Sem a marca, uma operação interrompida prova
 *   que nada saiu; com ela, o desfecho é incerto e só se segue pela conciliação.
 * - Resultado incerto: a operação fica em 'conciliacao'; novo PUT só ocorre depois de um GET que mostre
 *   que o destino ainda não foi alcançado (o PUT define estado absoluto, e as tentativas são limitadas).
 * - A conclusão exige GET autoritativo: o vínculo local só muda de estado (cancelada/suspensa/ativa) depois
 *   que o provedor confirma, via mpSubSincronizarPreapproval(); o slot ativo é mantido até lá.
 * Nenhum CRON é configurado: mpSubOperacoesProcessarPendentes() é a rotina a ser agendada em fase própria.
 * ---------------------------------------------------------------------
 */
const MP_SUB_OP_MAX_TENTATIVAS = 8;
const MP_SUB_OP_INTERROMPIDA_SEGUNDOS = 180;
const MP_SUB_OP_DESTINO_EXTERNO = ['pausar' => 'paused', 'reativar' => 'authorized', 'cancelar' => 'canceled'];
const MP_SUB_OP_STATUS_LOCAL_FINAL = ['pausar' => 'suspensa', 'reativar' => 'ativa', 'cancelar' => 'cancelada'];

function mpSubOpBackoffSegundos(int $tentativas): int
{
    return [60, 300, 900, 3600, 14400][min(max($tentativas, 1), 5) - 1];
}

/** Atualiza a operação que esta execução detém. */
function mpSubOpAtualizar(mysqli $conexao, int $idOperacao, string $status, ?string $codigo, ?string $motivo, ?int $backoffSegundos = null): bool
{
    return assinaturaServicoPreapprovalExec(
        $conexao,
        "UPDATE operacao_assinatura_gateway
            SET status_processamento = ?, erro_codigo = ?, erro_motivo = ?,
                concluida_em = CASE WHEN ? = 'concluida' THEN NOW(6) ELSE concluida_em END,
                proxima_tentativa_em = CASE WHEN ? IS NULL THEN NULL ELSE DATE_ADD(NOW(6), INTERVAL ? SECOND) END
          WHERE id_operacao = ? AND status_processamento IN ('processando', 'conciliacao')",
        'sssssii',
        $status,
        $codigo,
        $motivo === null ? null : mb_substr($motivo, 0, 300, 'UTF-8'),
        $status,
        $backoffSegundos === null ? null : 'x',
        $backoffSegundos ?? 0,
        $idOperacao
    ) === 1;
}

function mpSubOpAuditar(mysqli $conexao, string $evento, int $idOperacao, string $tipo, array $extra): void
{
    try {
        $v = assinaturaServicoConsultaFinanceira(
            $conexao,
            'SELECT a.id_assinatura_pagamento, a.id_empresa, a.id_assinatura, a.status
               FROM operacao_assinatura_gateway o
               JOIN assinatura_pagamento_recorrente a ON a.id_assinatura_pagamento = o.id_assinatura_pagamento AND a.id_empresa = o.id_empresa
              WHERE o.id_operacao = ?',
            'i',
            $idOperacao
        )[0] ?? null;
        if ($v !== null) {
            assinaturaServicoPreapprovalAuditarOperacao($conexao, $evento, $v, $tipo, $idOperacao, $extra, mpWebhookAtorSistema((int)$v['id_empresa']));
        }
    } catch (Throwable $e) {
        mpWebhookRegistrarFalha('auditoria_operacao_falhou', ['operacao' => $idOperacao, 'classe' => get_class($e)]);
    }
}

/**
 * Processa uma operação do outbox. Retorna resultado: concluida | pendente | conciliacao | erro | cancelada |
 * nao_devido | desabilitado | ignorado.
 */
function mpSubOperacaoProcessar(mysqli $conexao, object $gateway, int $idOperacao, ?int $idEmpresaEscopo = null): array
{
    if (!$gateway::subscriptionsHabilitado()) {
        return mpSubResultado('desabilitado', 'subscriptions_desabilitado');
    }
    $ref = mpSubConsultaEmpresa(
        $conexao,
        "SELECT o.id_empresa, o.id_assinatura_pagamento, o.tipo_operacao, o.valor_desejado, o.id_troca, a.id_assinatura, a.ambiente, a.assinatura_externa_id
           FROM operacao_assinatura_gateway o
           JOIN assinatura_pagamento_recorrente a ON a.id_assinatura_pagamento = o.id_assinatura_pagamento AND a.id_empresa = o.id_empresa
          WHERE /*empresa*/ o.id_operacao = ? AND o.tipo_operacao IN ('pausar', 'reativar', 'cancelar', 'atualizar_valor')",
        $idEmpresaEscopo,
        'o.id_empresa',
        'i',
        $idOperacao
    )[0] ?? null;
    if ($ref === null) {
        return mpSubResultado('ignorado', 'operacao_inexistente');
    }
    mpSubConferirEmpresa($idEmpresaEscopo, $ref);
    if ($gateway->ambiente() !== (string)$ref['ambiente']) {
        return mpSubResultado('erro', 'ambiente_incompativel');
    }
    $tipo = (string)$ref['tipo_operacao'];
    $ambiente = (string)$ref['ambiente'];
    $idEmpresa = (int)$ref['id_empresa'];

    // Etapa 1 (transação): decide se a operação ainda é devida e assume a execução.
    $conexao->begin_transaction();
    try {
        $contrato = assinaturaServicoConsultaFinanceira(
            $conexao,
            'SELECT status, data_fim, motivo_suspensao, valor_contratado FROM assinatura WHERE id_assinatura = ? AND id_empresa = ? FOR UPDATE',
            'ii',
            (int)$ref['id_assinatura'],
            $idEmpresa
        )[0] ?? null;
        $vinculo = assinaturaServicoPreapprovalVinculoDoContrato($conexao, $idEmpresa, (int)$ref['id_assinatura'], (int)$ref['id_assinatura_pagamento']);
        $op = assinaturaServicoConsultaFinanceira(
            $conexao,
            "SELECT status_processamento, erro_codigo, tentativas_processamento,
                    (proxima_tentativa_em IS NULL OR proxima_tentativa_em <= NOW(6)) AS vencida,
                    TIMESTAMPDIFF(SECOND, COALESCE(ultima_tentativa_em, criado_em), NOW(6)) AS idade
               FROM operacao_assinatura_gateway WHERE id_operacao = ? AND id_empresa = ? FOR UPDATE",
            'ii',
            $idOperacao,
            $idEmpresa
        )[0] ?? null;
        if ($contrato === null || $vinculo === null || $op === null) {
            $conexao->rollback();

            return mpSubResultado('ignorado', 'contexto_inexistente');
        }
        $statusOp = (string)$op['status_processamento'];
        $interrompida = $statusOp === 'processando' && (int)$op['idade'] > MP_SUB_OP_INTERROMPIDA_SEGUNDOS;
        $devida = in_array($statusOp, ['pendente', 'conciliacao'], true) ? (int)$op['vencida'] === 1 : $interrompida;
        if (!$devida) {
            $conexao->rollback();

            return mpSubResultado('nao_devido', $statusOp);
        }
        $modo = ($statusOp === 'pendente' || ($statusOp === 'processando' && $op['erro_codigo'] === null)) ? 'enviar' : 'conciliar';

        $statusVinculo = (string)$vinculo['status'];
        $valorOperacao = $tipo === 'atualizar_valor' ? pagamentoGatewayValorCentavos((string)$ref['valor_desejado']) : null;
        $desejada = match ($tipo) {
            'atualizar_valor' => assinaturaServicoPreapprovalValorEsperadoOperacao($conexao, $vinculo, $contrato, ['id_troca' => $ref['id_troca']]) === $valorOperacao,
            'reativar' => (string)$contrato['status'] === 'ativa' && $contrato['data_fim'] === null && $statusVinculo === 'suspensa',
            default => assinaturaServicoPreapprovalOperacaoDesejada($vinculo, $contrato) === $tipo,
        };
        if (!$desejada && $tipo === 'atualizar_valor' && in_array($op['erro_codigo'], ['ENVIO_INICIADO', 'CONCILIANDO', 'RESULTADO_INCERTO'], true)
            && in_array((string)$contrato['status'], ['ativa', 'suspensa'], true) && assinaturaServicoPreapprovalVinculoAceitaValor($vinculo)) {
            // A troca deixou de valer, mas o provedor pode já ter recebido o novo valor: a operação passa a reverter ao valor do contrato.
            $valorOperacao = pagamentoGatewayValorCentavos((string)$contrato['valor_contratado']);
            $ref['valor_desejado'] = pagamentoGatewayValorDecimal($valorOperacao);
            $ref['id_troca'] = null;
            assinaturaServicoPreapprovalExec($conexao, 'UPDATE operacao_assinatura_gateway SET valor_desejado = ?, id_troca = NULL WHERE id_operacao = ?', 'si', $ref['valor_desejado'], $idOperacao);
            $desejada = true;
        }
        $abortar = null;
        if (!$desejada) {
            $abortar = ['cancelada', 'NAO_MAIS_DESEJADA', 'A intenção deixou de valer pelo estado atual do contrato.'];
        } elseif ($vinculo['assinatura_externa_id'] === null || (string)$vinculo['ambiente'] !== $ambiente) {
            $abortar = ['erro', 'SEM_ASSINATURA_EXTERNA', 'Vínculo sem assinatura externa utilizável.'];
        } elseif ((int)$op['tentativas_processamento'] >= MP_SUB_OP_MAX_TENTATIVAS) {
            $abortar = ['erro', 'TENTATIVAS_ESGOTADAS', 'Limite de tentativas atingido; requer intervenção.'];
        }
        if ($abortar !== null) {
            assinaturaServicoPreapprovalExec(
                $conexao,
                "UPDATE operacao_assinatura_gateway SET status_processamento = ?, erro_codigo = ?, erro_motivo = ?, proxima_tentativa_em = NULL
                  WHERE id_operacao = ? AND status_processamento IN ('pendente', 'processando', 'conciliacao')",
                'sssi',
                $abortar[0],
                $abortar[1],
                $abortar[2],
                $idOperacao
            );
            if ($abortar[0] === 'erro') {
                mpSubSinalizarVinculo($conexao, $vinculo, $abortar[1], $abortar[2]);
            }
            $conexao->commit();
            if ($abortar[0] === 'erro') {
                mpSubOpAuditar($conexao, 'assinatura.recorrencia_operacao_falhou', $idOperacao, $tipo, ['situacao' => $abortar[0], 'codigo' => $abortar[1]]);
            }

            return mpSubResultado($abortar[0], $abortar[1]);
        }

        $afetadas = assinaturaServicoPreapprovalExec(
            $conexao,
            "UPDATE operacao_assinatura_gateway
                SET status_processamento = 'processando', tentativas_processamento = tentativas_processamento + 1,
                    ultima_tentativa_em = NOW(6), proxima_tentativa_em = NULL,
                    erro_codigo = CASE WHEN ? = 'conciliar' THEN 'CONCILIANDO' ELSE NULL END
              WHERE id_operacao = ? AND status_processamento IN ('pendente', 'processando', 'conciliacao')",
            'si',
            $modo,
            $idOperacao
        );
        if ($afetadas !== 1) {
            $conexao->rollback();

            return mpSubResultado('nao_devido', 'perdeu_a_vez');
        }
        $tentativas = (int)$op['tentativas_processamento'] + 1;
        $conexao->commit();
    } catch (Throwable $e) {
        mpWebhookRollback($conexao);
        throw $e;
    }

    // Etapa 2 (sem transação nem lock): provedor.
    $preapprovalId = (string)$ref['assinatura_externa_id'];
    $destino = MP_SUB_OP_DESTINO_EXTERNO[$tipo] ?? '';
    $ehValor = $tipo === 'atualizar_valor';
    $alvoAlcancado = static fn (array $d): bool => $ehValor
        ? mpSubCentavos($d['auto_recurring']['transaction_amount'] ?? null) === $valorOperacao
        : mpSubStatusPreapproval($d) === $destino;
    $voltar = static function (string $status, string $codigo, string $motivo) use ($conexao, $idOperacao, $tentativas, $tipo): array {
        mpSubOpAtualizar($conexao, $idOperacao, $status, $codigo, $motivo, mpSubOpBackoffSegundos($tentativas));
        mpSubOpAuditar($conexao, 'assinatura.recorrencia_operacao_falhou', $idOperacao, $tipo, ['situacao' => $status, 'codigo' => $codigo]);

        return mpSubResultado($status, $codigo);
    };

    mpSubConferirOperacaoEmpresa($conexao, $idEmpresaEscopo, $idOperacao, $ref);
    $consulta = $gateway->consultarPreapproval($preapprovalId);
    if (($consulta['sucesso'] ?? false) !== true || !is_array($consulta['dados'] ?? null)) {
        return $voltar($modo === 'enviar' ? 'pendente' : 'conciliacao', 'CONSULTA_INDISPONIVEL', 'Não foi possível consultar o provedor.');
    }
    $atual = mpSubStatusPreapproval($consulta['dados']);

    if (!$alvoAlcancado($consulta['dados'])) {
        $incompativel = ($atual === 'canceled')
            || ($tipo === 'pausar' && $atual !== 'authorized')
            || ($tipo === 'reativar' && $atual !== 'paused');
        if ($incompativel) {
            mpSubSincronizarPreapproval($conexao, $gateway, $ambiente, $preapprovalId, null, $idEmpresaEscopo);
            mpSubOpAtualizar($conexao, $idOperacao, 'erro', 'DESTINO_INCOMPATIVEL', 'Estado externo incompatível com a operação.');
            mpSubOpAuditar($conexao, 'assinatura.recorrencia_operacao_falhou', $idOperacao, $tipo, ['situacao' => 'erro', 'codigo' => 'DESTINO_INCOMPATIVEL']);

            return mpSubResultado('erro', 'DESTINO_INCOMPATIVEL');
        }

        // O envio só é marcado se esta execução ainda detém a operação.
        $marcada = assinaturaServicoPreapprovalExec(
            $conexao,
            "UPDATE operacao_assinatura_gateway SET erro_codigo = 'ENVIO_INICIADO'
              WHERE id_operacao = ? AND status_processamento = 'processando' AND (erro_codigo IS NULL OR erro_codigo = 'CONCILIANDO')",
            'i',
            $idOperacao
        );
        if ($marcada !== 1) {
            return mpSubResultado('nao_devido', 'perdeu_a_vez');
        }
        mpSubConferirOperacaoEmpresa($conexao, $idEmpresaEscopo, $idOperacao, $ref);
        try {
            $resposta = $gateway->atualizarPreapproval($preapprovalId, $ehValor
                ? ['auto_recurring' => ['transaction_amount' => (float)$ref['valor_desejado'], 'currency_id' => 'BRL']]
                : ['status' => $destino]);
        } catch (Throwable $e) {
            $resposta = pagamentoGatewayResultado(false, 'resultado_incerto', 'x');
        }
        $codigo = (string)($resposta['codigo'] ?? 'resultado_incerto');
        $aceita = ($resposta['sucesso'] ?? false) === true && is_array($resposta['dados'] ?? null)
            && $alvoAlcancado($resposta['dados']);

        if (!$aceita) {
            if ($codigo === 'provedor_rejeitou') {
                mpSubOpAtualizar($conexao, $idOperacao, 'erro', 'PROVEDOR_REJEITOU', 'O provedor rejeitou a operação.');
                mpSubSinalizarVinculo($conexao, ['id_assinatura_pagamento' => $ref['id_assinatura_pagamento'], 'id_empresa' => $idEmpresa], $ehValor ? 'SINCRONIZACAO_VALOR_FALHOU' : 'OPERACAO_REJEITADA', $ehValor ? 'O provedor rejeitou a atualização do valor recorrente.' : 'O provedor rejeitou a operação externa.');
                mpSubOpAuditar($conexao, 'assinatura.recorrencia_operacao_falhou', $idOperacao, $tipo, ['situacao' => 'erro', 'codigo' => 'PROVEDOR_REJEITOU']);

                return mpSubResultado('erro', 'PROVEDOR_REJEITOU');
            }
            if ($codigo !== 'resultado_incerto' && ($resposta['sucesso'] ?? false) !== true) {
                // Comprovadamente não enviado (chave desligada, transporte, 429): volta ao início com espera.
                mpSubOpAtualizar($conexao, $idOperacao, 'pendente', null, 'Envio não realizado; nova tentativa agendada.', mpSubOpBackoffSegundos($tentativas));

                return mpSubResultado('pendente', strtoupper($codigo));
            }
            // Incerto (ou resposta aceita com status inesperado): conciliar, com uma consulta imediata.
            mpSubOpAtualizar($conexao, $idOperacao, 'conciliacao', 'RESULTADO_INCERTO', 'Não foi possível confirmar a operação no provedor.', mpSubOpBackoffSegundos($tentativas));
            mpSubOpAuditar($conexao, 'assinatura.recorrencia_operacao_falhou', $idOperacao, $tipo, ['situacao' => 'conciliacao', 'codigo' => 'RESULTADO_INCERTO']);
            mpSubConferirOperacaoEmpresa($conexao, $idEmpresaEscopo, $idOperacao, $ref);
            $imediata = $gateway->consultarPreapproval($preapprovalId);
            if (($imediata['sucesso'] ?? false) !== true || !is_array($imediata['dados'] ?? null) || !$alvoAlcancado($imediata['dados'])) {
                return mpSubResultado('conciliacao', 'RESULTADO_INCERTO');
            }
        }
    }

    return mpSubOpConcluir($conexao, $gateway, $ambiente, $idOperacao, $tipo, $ref, $tentativas, $idEmpresaEscopo);
}

/** Conclusão: consulta autoritativa + aplicação local pelo mesmo caminho dos webhooks, e só então a operação se fecha. */
function mpSubOpConcluir(mysqli $conexao, object $gateway, string $ambiente, int $idOperacao, string $tipo, array $ref, int $tentativas, ?int $idEmpresaEscopo = null): array
{
    mpSubConferirEmpresa($idEmpresaEscopo, $ref);
    $idEmpresa = (int)$ref['id_empresa'];
    $valorConfirmado = true;
    if ($tipo === 'atualizar_valor') {
        // O novo valor só é dado como sincronizado depois de lido de volta no provedor.
        mpSubConferirOperacaoEmpresa($conexao, $idEmpresaEscopo, $idOperacao, $ref);
        $leitura = $gateway->consultarPreapproval((string)$ref['assinatura_externa_id']);
        $valorConfirmado = ($leitura['sucesso'] ?? false) === true && is_array($leitura['dados'] ?? null)
            && mpSubCentavos($leitura['dados']['auto_recurring']['transaction_amount'] ?? null) === pagamentoGatewayValorCentavos((string)$ref['valor_desejado']);
    }
    $sincronia = mpSubSincronizarPreapproval($conexao, $gateway, $ambiente, (string)$ref['assinatura_externa_id'], null, $idEmpresaEscopo);
    $local = assinaturaServicoConsultaFinanceira(
        $conexao,
        'SELECT status, status_externo FROM assinatura_pagamento_recorrente WHERE id_assinatura_pagamento = ? AND id_empresa = ?',
        'ii',
        (int)$ref['id_assinatura_pagamento'],
        $idEmpresa
    )[0] ?? null;
    $convergiu = $sincronia['resultado'] === 'processado' && $local !== null
        && ($tipo === 'atualizar_valor'
            ? $valorConfirmado && !in_array((string)$local['status'], ['cancelada', 'encerrada'], true)
            : (string)$local['status'] === MP_SUB_OP_STATUS_LOCAL_FINAL[$tipo]
                && (string)$local['status_externo'] === MP_SUB_OP_DESTINO_EXTERNO[$tipo]);
    if (!$convergiu) {
        $codigo = $sincronia['resultado'] === 'processado' ? 'ESTADO_LOCAL_NAO_CONVERGIU' : (string)($sincronia['codigo'] ?? 'SINCRONIA_FALHOU');
        mpSubOpAtualizar($conexao, $idOperacao, 'conciliacao', $codigo, 'A confirmação externa não pôde ser aplicada ao vínculo local.', mpSubOpBackoffSegundos($tentativas));
        mpSubOpAuditar($conexao, 'assinatura.recorrencia_operacao_falhou', $idOperacao, $tipo, ['situacao' => 'conciliacao', 'codigo' => $codigo]);

        return mpSubResultado('conciliacao', $codigo);
    }

    mpSubOpAtualizar($conexao, $idOperacao, 'concluida', null, null);
    mpSubOpAuditar($conexao, 'assinatura.recorrencia_operacao_confirmada', $idOperacao, $tipo, ['status_externo' => (string)$local['status_externo']]);
    if ($tipo === 'atualizar_valor') {
        // Outra troca pode ter entrado na fila enquanto esta executava.
        assinaturaServicoPreapprovalGarantirValor($conexao, (int)$ref['id_assinatura_pagamento'], mpWebhookAtorSistema($idEmpresa), $idEmpresaEscopo);
    }
    // A intenção derivada do estado (por exemplo, cancelamento pedido durante a pausa) segue no mesmo outbox.
    assinaturaServicoPreapprovalGarantirOperacao($conexao, (int)$ref['id_assinatura_pagamento'], mpWebhookAtorSistema($idEmpresa), $idEmpresaEscopo);

    return mpSubResultado('concluida', null, ['id_operacao' => $idOperacao]);
}

/**
 * Rotina de processamento do outbox (sem CRON configurado): garante as intenções derivadas do estado e processa as
 * operações vencidas, uma a uma. Nunca cria Preapproval nem cobra.
 *
 * @return array<string, int>
 */
function mpSubOperacoesProcessarPendentes(mysqli $conexao, object $gateway, int $limite = 20, ?int $idEmpresa = null): array
{
    if ($idEmpresa !== null && $idEmpresa <= 0) throw new InvalidArgumentException('Empresa inválida.');
    $total = ['garantidas' => 0, 'processadas' => 0, 'concluidas' => 0, 'erros' => 0];
    if (!$gateway::subscriptionsHabilitado()) return $total;
    $limite = max(1, min(100, $limite));

    $candidatos = mpSubConsultaEmpresa(
        $conexao,
        "SELECT a.id_assinatura_pagamento, a.id_empresa
           FROM assinatura_pagamento_recorrente a
           JOIN assinatura s ON s.id_assinatura = a.id_assinatura AND s.id_empresa = a.id_empresa
          WHERE /*empresa*/ a.motor_recorrencia = 'preapproval' AND a.assinatura_externa_id IS NOT NULL
            AND a.status NOT IN ('cancelada', 'encerrada', 'erro')
            AND (a.status = 'cancelamento_pendente' OR s.status IN ('cancelada', 'encerrada') OR s.data_fim IS NOT NULL
                 OR (s.status = 'suspensa' AND s.motivo_suspensao = 'manual' AND a.status IN ('ativa', 'atualizacao_necessaria')))
            AND NOT EXISTS (SELECT 1 FROM operacao_assinatura_gateway o
                             WHERE o.id_assinatura_pagamento = a.id_assinatura_pagamento
                               AND o.status_processamento IN ('pendente', 'processando', 'conciliacao'))
          ORDER BY a.id_assinatura_pagamento LIMIT {$limite}",
        $idEmpresa,
        'a.id_empresa'
    );
    foreach ($candidatos as $c) {
        mpSubConferirEmpresa($idEmpresa, $c);
        try {
            if (assinaturaServicoPreapprovalGarantirOperacao($conexao, (int)$c['id_assinatura_pagamento'], mpWebhookAtorSistema((int)$c['id_empresa']), $idEmpresa) !== null) {
                $total['garantidas']++;
            }
        } catch (Throwable $e) {
            mpWebhookRollback($conexao);
            $total['erros']++;
        }
    }

    $comTroca = mpSubConsultaEmpresa(
        $conexao,
        "SELECT a.id_assinatura_pagamento, a.id_empresa
           FROM assinatura_pagamento_recorrente a
          WHERE /*empresa*/ a.motor_recorrencia = 'preapproval' AND a.assinatura_externa_id IS NOT NULL
            AND a.status IN ('pendente', 'ativa', 'atualizacao_necessaria', 'suspensa')
            AND (EXISTS (SELECT 1 FROM assinatura_troca_plano t
                          WHERE t.id_assinatura = a.id_assinatura AND t.id_empresa = a.id_empresa
                            AND t.status IN ('ativada', 'efetivada', 'programada') AND t.criado_em >= a.criado_em
                            AND NOT EXISTS (SELECT 1 FROM operacao_assinatura_gateway ot
                                             WHERE ot.id_troca = t.id_troca AND ot.tipo_operacao = 'atualizar_valor'
                                               AND ot.status_processamento <> 'cancelada'))
                 OR EXISTS (SELECT 1 FROM operacao_assinatura_gateway v
                              JOIN assinatura s ON s.id_assinatura = a.id_assinatura AND s.id_empresa = a.id_empresa
                             WHERE v.id_assinatura_pagamento = a.id_assinatura_pagamento
                               AND v.tipo_operacao = 'atualizar_valor' AND v.status_processamento = 'concluida'
                               AND v.valor_desejado <> s.valor_contratado
                               AND v.id_operacao = (SELECT MAX(w.id_operacao) FROM operacao_assinatura_gateway w
                                                     WHERE w.id_assinatura_pagamento = a.id_assinatura_pagamento
                                                       AND w.tipo_operacao = 'atualizar_valor')))
            AND NOT EXISTS (SELECT 1 FROM operacao_assinatura_gateway o
                             WHERE o.id_assinatura_pagamento = a.id_assinatura_pagamento
                               AND o.status_processamento IN ('pendente', 'processando', 'conciliacao'))
          ORDER BY a.id_assinatura_pagamento LIMIT {$limite}",
        $idEmpresa,
        'a.id_empresa'
    );
    foreach ($comTroca as $c) {
        mpSubConferirEmpresa($idEmpresa, $c);
        try {
            $r = assinaturaServicoPreapprovalGarantirValor($conexao, (int)$c['id_assinatura_pagamento'], mpWebhookAtorSistema((int)$c['id_empresa']), $idEmpresa);
            if ($r !== null && in_array($r['situacao'], ['criada', 'reaberta'], true)) {
                $total['garantidas']++;
            }
        } catch (Throwable $e) {
            mpWebhookRollback($conexao);
            $total['erros']++;
        }
    }

    $operacoes = mpSubConsultaEmpresa(
        $conexao,
        "SELECT id_operacao, id_empresa FROM operacao_assinatura_gateway
          WHERE /*empresa*/ tipo_operacao IN ('pausar', 'reativar', 'cancelar', 'atualizar_valor')
            AND ((status_processamento IN ('pendente', 'conciliacao') AND (proxima_tentativa_em IS NULL OR proxima_tentativa_em <= NOW(6)))
                 OR (status_processamento = 'processando' AND ultima_tentativa_em < DATE_SUB(NOW(6), INTERVAL " . MP_SUB_OP_INTERROMPIDA_SEGUNDOS . " SECOND)))
          ORDER BY id_operacao LIMIT {$limite}",
        $idEmpresa
    );
    foreach ($operacoes as $o) {
        mpSubConferirEmpresa($idEmpresa, $o);
        try {
            $r = mpSubOperacaoProcessar($conexao, $gateway, (int)$o['id_operacao'], $idEmpresa);
            $total['processadas']++;
            if ($r['resultado'] === 'concluida') {
                $total['concluidas']++;
            }
            if ($r['resultado'] === 'erro') {
                $total['erros']++;
            }
        } catch (Throwable $e) {
            mpWebhookRollback($conexao);
            $total['erros']++;
        }
    }

    return $total;
}

/*
 * ---------------------------------------------------------------------
 * Endurecimento (Fase 13): recuperação, reabertura controlada e diagnóstico.
 * ---------------------------------------------------------------------
 */

/** Forma canônica do AmAgenda: "canceled", valor oficial da API para cancelar. "cancelled" só é aceito na leitura de respostas (legado/divergência). */
function mpSubStatusPreapproval(array $dados): string
{
    $status = (string)($dados['status'] ?? '');

    return $status === 'cancelled' ? 'canceled' : $status;
}

/**
 * Guarda o Payment ID numa transação em conciliação, para que um pagamento confirmado nunca dependa só da memória do
 * provedor. Não sobrescreve nem duplica: o índice único do pagamento externo é respeitado.
 *
 * @param array<string, mixed> $transacao
 * @param array<string, mixed>|null $pagamento
 */
function mpSubGuardarPagamentoExterno(mysqli $conexao, string $ambiente, array $transacao, ?array $pagamento): void
{
    if ($pagamento === null || $transacao['pagamento_externo_id'] !== null) {
        return;
    }
    $usado = assinaturaServicoConsultaFinanceira(
        $conexao,
        'SELECT 1 FROM transacao_pagamento WHERE provedor = ? AND ambiente = ? AND pagamento_externo_id = ? AND id_transacao <> ? LIMIT 1',
        'sssi',
        MP_WEBHOOK_PROVEDOR,
        $ambiente,
        (string)$pagamento['id'],
        (int)$transacao['id_transacao']
    );
    if ($usado === []) {
        assinaturaServicoPreapprovalExec(
            $conexao,
            'UPDATE transacao_pagamento SET pagamento_externo_id = ? WHERE id_transacao = ? AND id_empresa = ? AND pagamento_externo_id IS NULL',
            'sii',
            (string)$pagamento['id'],
            (int)$transacao['id_transacao'],
            (int)$transacao['id_empresa']
        );
    }
}

/**
 * Fatura com pagamento que não encontra competência (ou chega depois do cancelamento): o registro fica numa transação
 * em conciliação ligada à cobrança mais recente do contrato. Nada é quitado, nada é descartado, e a pendência
 * financeira impede reativações e novas contratações até a análise. Participa da transação do chamador.
 *
 * @param array<string, mixed> $vinculo
 * @param array<string, mixed> $assinatura
 * @param array<string, mixed> $fatura
 * @param array<string, mixed>|string|null $pagamento
 */
function mpSubRegistrarFaturaOrfa(mysqli $conexao, string $ambiente, array $vinculo, array $assinatura, array $fatura, array|string|null $pagamento, ?int $idEvento, string $motivo): bool
{
    $idEmpresa = (int)$vinculo['id_empresa'];
    $cobranca = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT id_cobranca FROM cobranca
          WHERE id_assinatura = ? AND id_empresa = ? AND status <> 'cancelada'
          ORDER BY periodo_fim DESC, id_cobranca DESC LIMIT 1 FOR UPDATE",
        'ii',
        (int)$assinatura['id_assinatura'],
        $idEmpresa
    )[0] ?? null;
    if ($cobranca === null) {
        return false;
    }
    $colunas = 'id_transacao, id_empresa, id_cobranca, id_pagamento, id_assinatura_pagamento, provedor, ambiente, metodo,
                tipo_operacao, valor, moeda, status, referencia_interna, fatura_externa_id, pagamento_externo_id,
                status_externo, detalhe_status_externo, confirmado_em, requer_conciliacao';
    $transacao = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT {$colunas} FROM transacao_pagamento WHERE provedor = ? AND ambiente = ? AND fatura_externa_id = ? FOR UPDATE",
        'sss',
        MP_WEBHOOK_PROVEDOR,
        $ambiente,
        (string)$fatura['id']
    )[0] ?? null;
    if ($transacao === null) {
        assinaturaServicoPreapprovalExec(
            $conexao,
            "INSERT INTO transacao_pagamento
                (id_empresa, id_cobranca, id_assinatura_pagamento, provedor, ambiente, metodo, tipo_operacao, valor, moeda,
                 status, chave_idempotencia, referencia_interna, fatura_externa_id, numero_tentativa, ultima_consulta_em)
             VALUES (?, ?, ?, ?, ?, 'cartao_recorrente', 'assinatura_preapproval', ?, 'BRL', 'criada', ?, ?, ?, ?, NOW())",
            'iiissssssi',
            $idEmpresa,
            (int)$cobranca['id_cobranca'],
            (int)$vinculo['id_assinatura_pagamento'],
            MP_WEBHOOK_PROVEDOR,
            $ambiente,
            pagamentoGatewayValorDecimal($fatura['valor_centavos']),
            pagamentoGatewayChaveIdempotencia(),
            pagamentoGatewayReferenciaInterna(),
            (string)$fatura['id'],
            max(1, min(65000, $fatura['tentativa'] + 1))
        );
        $transacao = assinaturaServicoConsultaFinanceira(
            $conexao,
            "SELECT {$colunas} FROM transacao_pagamento WHERE id_transacao = ? AND id_empresa = ? FOR UPDATE",
            'ii',
            (int)$conexao->insert_id,
            $idEmpresa
        )[0];
    }
    mpWebhookMarcarConciliacao($conexao, $transacao, $idEvento, (string)$fatura['status'], null, $motivo);
    mpSubGuardarPagamentoExterno($conexao, $ambiente, $transacao, is_array($pagamento) ? $pagamento : null);
    mpSubEncerrarEvento($conexao, $idEvento, 'processado', null, (int)$transacao['id_transacao'], (int)$vinculo['id_assinatura_pagamento']);

    return true;
}

/**
 * Reabertura controlada de uma operação em 'erro'. Só ocorre com motivo registrado, sem outra operação aberta, com
 * consulta autoritativa prévia ao provedor e, para rejeição definitiva ou destino incompatível, com a confirmação
 * explícita de que a causa foi corrigida. A mesma linha é reaproveitada (tentativas zeradas), então a chave e o
 * índice único por troca continuam impedindo duplicidade; a operação reaberta ainda passa por toda a validação
 * do processador (intenção vigente, estado externo, marca de envio).
 *
 * @param array<string, mixed> $ator
 */
function mpSubOperacaoReabrir(mysqli $conexao, object $gateway, int $idOperacao, array $ator, string $motivo, bool $causaCorrigida = false, ?int $idEmpresaEscopo = null): array
{
    if (preg_match('/^[A-Za-z0-9 _.,;:()\-]{5,200}$/D', $motivo) !== 1) {
        return mpSubResultado('recusada', 'MOTIVO_INVALIDO');
    }
    if (!$gateway::subscriptionsHabilitado()) {
        return mpSubResultado('recusada', 'subscriptions_desabilitado');
    }
    $ref = mpSubConsultaEmpresa(
        $conexao,
        "SELECT o.id_empresa, o.id_assinatura_pagamento, o.tipo_operacao, o.status_processamento, o.erro_codigo,
                a.id_assinatura, a.ambiente, a.assinatura_externa_id
           FROM operacao_assinatura_gateway o
           JOIN assinatura_pagamento_recorrente a ON a.id_assinatura_pagamento = o.id_assinatura_pagamento AND a.id_empresa = o.id_empresa
          WHERE /*empresa*/ o.id_operacao = ? AND o.tipo_operacao IN ('pausar', 'reativar', 'cancelar', 'atualizar_valor')",
        $idEmpresaEscopo,
        'o.id_empresa',
        'i',
        $idOperacao
    )[0] ?? null;
    if ($ref === null || (string)$ref['status_processamento'] !== 'erro') {
        return mpSubResultado('recusada', 'OPERACAO_NAO_REABRIVEL');
    }
    mpSubConferirEmpresa($idEmpresaEscopo, $ref);
    if ($ref['assinatura_externa_id'] === null || $gateway->ambiente() !== (string)$ref['ambiente']) {
        return mpSubResultado('recusada', 'VINCULO_OU_AMBIENTE_INVALIDO');
    }
    if (in_array((string)$ref['erro_codigo'], ['PROVEDOR_REJEITOU', 'DESTINO_INCOMPATIVEL'], true) && !$causaCorrigida) {
        return mpSubResultado('recusada', 'REQUER_CONFIRMACAO_DA_CAUSA');
    }

    // Validação autoritativa antes de qualquer escrita local.
    mpSubConferirOperacaoEmpresa($conexao, $idEmpresaEscopo, $idOperacao, $ref);
    $consulta = $gateway->consultarPreapproval((string)$ref['assinatura_externa_id']);
    if (($consulta['sucesso'] ?? false) !== true || !is_array($consulta['dados'] ?? null)) {
        return mpSubResultado('recusada', 'CONSULTA_INDISPONIVEL');
    }
    $statusExterno = mpSubStatusPreapproval($consulta['dados']);
    $tipo = (string)$ref['tipo_operacao'];
    if ($statusExterno === 'canceled' && $tipo !== 'cancelar') {
        mpSubSincronizarPreapproval($conexao, $gateway, (string)$ref['ambiente'], (string)$ref['assinatura_externa_id'], null, $idEmpresaEscopo);

        return mpSubResultado('recusada', 'ESTADO_EXTERNO_INCOMPATIVEL');
    }

    $idEmpresa = (int)$ref['id_empresa'];
    $conexao->begin_transaction();
    try {
        assinaturaServicoConsultaFinanceira($conexao, 'SELECT id_assinatura FROM assinatura WHERE id_assinatura = ? AND id_empresa = ? FOR UPDATE', 'ii', (int)$ref['id_assinatura'], $idEmpresa);
        $vinculo = assinaturaServicoPreapprovalVinculoDoContrato($conexao, $idEmpresa, (int)$ref['id_assinatura'], (int)$ref['id_assinatura_pagamento']);
        $aberta = assinaturaServicoConsultaFinanceira(
            $conexao,
            "SELECT 1 FROM operacao_assinatura_gateway
              WHERE id_assinatura_pagamento = ? AND status_processamento IN ('pendente', 'processando', 'conciliacao') FOR UPDATE",
            'i',
            (int)$ref['id_assinatura_pagamento']
        );
        if ($vinculo === null || $aberta !== []) {
            $conexao->rollback();

            return mpSubResultado('recusada', 'HA_OPERACAO_ABERTA');
        }
        $afetadas = assinaturaServicoPreapprovalExec(
            $conexao,
            "UPDATE operacao_assinatura_gateway
                SET status_processamento = 'pendente', tentativas_processamento = 0, erro_codigo = NULL, erro_motivo = NULL,
                    concluida_em = NULL, proxima_tentativa_em = NOW(6)
              WHERE id_operacao = ? AND id_empresa = ? AND status_processamento = 'erro'",
            'ii',
            $idOperacao,
            $idEmpresa
        );
        if ($afetadas !== 1) {
            $conexao->rollback();

            return mpSubResultado('recusada', 'OPERACAO_JA_ALTERADA');
        }
        assinaturaServicoPreapprovalExec(
            $conexao,
            'UPDATE assinatura_pagamento_recorrente SET ultimo_erro_codigo = NULL, ultimo_erro_motivo = NULL WHERE id_assinatura_pagamento = ? AND id_empresa = ?',
            'ii',
            (int)$ref['id_assinatura_pagamento'],
            $idEmpresa
        );
        assinaturaServicoPreapprovalAuditarOperacao(
            $conexao,
            'assinatura.recorrencia_operacao_reaberta',
            $vinculo,
            $tipo,
            $idOperacao,
            ['situacao' => 'reaberta', 'motivo' => $motivo],
            $ator
        );
        $conexao->commit();
    } catch (Throwable $e) {
        mpWebhookRollback($conexao);
        throw $e;
    }

    return mpSubResultado('reaberta', null, ['id_operacao' => $idOperacao]);
}

/**
 * Diagnóstico somente de leitura (sem rede) para operação e alertas: apenas ids, códigos e contagens.
 *
 * @return array<string, mixed>
 */
function mpSubDiagnostico(mysqli $conexao, int $sincroniaMaxMinutos = 60, ?int $idEmpresa = null): array
{
    $listar = static fn (string $sql, string $tipos = '', mixed ...$v): array
        => mpSubConsultaEmpresa($conexao, $sql, $idEmpresa, 'id_empresa', $tipos, ...$v);

    return [
        'operacoes_em_erro' => $listar("SELECT id_operacao, tipo_operacao, erro_codigo, tentativas_processamento, atualizado_em
            FROM operacao_assinatura_gateway WHERE /*empresa*/ status_processamento = 'erro' AND tipo_operacao <> 'conciliar' ORDER BY id_operacao DESC LIMIT 50"),
        'operacoes_em_conciliacao_antigas' => $listar("SELECT id_operacao, tipo_operacao, erro_codigo, tentativas_processamento, atualizado_em
            FROM operacao_assinatura_gateway WHERE /*empresa*/ status_processamento = 'conciliacao' AND atualizado_em < DATE_SUB(NOW(6), INTERVAL 1 HOUR) ORDER BY id_operacao LIMIT 50"),
        'operacoes_travadas' => $listar("SELECT id_operacao, tipo_operacao, status_processamento, ultima_tentativa_em
            FROM operacao_assinatura_gateway WHERE /*empresa*/ status_processamento = 'processando' AND ultima_tentativa_em < DATE_SUB(NOW(6), INTERVAL 10 MINUTE) LIMIT 50"),
        'vinculos_sinalizados' => $listar("SELECT id_assinatura_pagamento, id_empresa, status, ultimo_erro_codigo
            FROM assinatura_pagamento_recorrente WHERE /*empresa*/ motor_recorrencia = 'preapproval' AND ultimo_erro_codigo IS NOT NULL ORDER BY atualizado_em DESC LIMIT 50"),
        'vinculos_sem_sincronia' => $listar("SELECT id_assinatura_pagamento, id_empresa, status, ultima_sincronizacao_em
            FROM assinatura_pagamento_recorrente
           WHERE /*empresa*/ motor_recorrencia = 'preapproval' AND assinatura_externa_id IS NOT NULL
             AND status IN ('pendente', 'ativa', 'atualizacao_necessaria', 'suspensa', 'cancelamento_pendente')
             AND (ultima_sincronizacao_em IS NULL OR ultima_sincronizacao_em < DATE_SUB(NOW(6), INTERVAL ? MINUTE)) ORDER BY ultima_sincronizacao_em LIMIT 50", 'i', $sincroniaMaxMinutos),
        'cancelamentos_sem_operacao' => mpSubConsultaEmpresa($conexao, "SELECT a.id_assinatura_pagamento, a.id_empresa FROM assinatura_pagamento_recorrente a
           WHERE /*empresa*/ a.motor_recorrencia = 'preapproval' AND a.status = 'cancelamento_pendente'
             AND NOT EXISTS (SELECT 1 FROM operacao_assinatura_gateway o WHERE o.id_assinatura_pagamento = a.id_assinatura_pagamento
                              AND o.status_processamento IN ('pendente', 'processando', 'conciliacao')) LIMIT 50", $idEmpresa, 'a.id_empresa'),
        'transacoes_em_conciliacao' => $listar("SELECT id_transacao, id_empresa, id_cobranca, status, motivo_conciliacao
            FROM transacao_pagamento WHERE /*empresa*/ tipo_operacao = 'assinatura_preapproval' AND (requer_conciliacao = 1 OR status = 'conciliacao') ORDER BY id_transacao DESC LIMIT 50"),
        // Evento sem vínculo/transação não prova empresa: omitido no diagnóstico restrito.
        'eventos_em_erro' => mpSubConsultaEmpresa($conexao, "SELECT e.id_evento_gateway, e.tipo_recurso, e.erro_codigo, e.tentativas_processamento, e.recebido_em
            FROM evento_gateway_pagamento e
            LEFT JOIN assinatura_pagamento_recorrente a ON a.id_assinatura_pagamento = e.id_assinatura_pagamento
            LEFT JOIN transacao_pagamento t ON t.id_transacao = e.id_transacao
            WHERE /*empresa*/ e.status_processamento = 'erro' AND e.tipo_recurso IN ('subscription_preapproval', 'subscription_authorized_payment', 'payment')
              AND e.recebido_em > DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY e.id_evento_gateway DESC LIMIT 50", $idEmpresa, 'COALESCE(a.id_empresa,t.id_empresa)'),
        'downgrades_em_risco' => mpSubConsultaEmpresa($conexao, "SELECT t.id_troca, t.id_empresa, t.data_efetivacao, o.status_processamento
            FROM assinatura_troca_plano t
            JOIN assinatura_pagamento_recorrente v ON v.id_assinatura = t.id_assinatura AND v.id_empresa = t.id_empresa
              AND v.motor_recorrencia = 'preapproval' AND v.assinatura_externa_id IS NOT NULL
              AND v.status IN ('pendente', 'ativa', 'atualizacao_necessaria', 'suspensa')
            LEFT JOIN operacao_assinatura_gateway o ON o.id_troca = t.id_troca AND o.tipo_operacao = 'atualizar_valor'
           WHERE /*empresa*/ t.status = 'programada' AND t.data_efetivacao <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
             AND (o.id_operacao IS NULL OR o.status_processamento <> 'concluida') LIMIT 50", $idEmpresa, 't.id_empresa'),
    ];
}

/**
 * Rotina operacional da integração (sem CRON configurado): processa o outbox, sincroniza vínculos sem notícia
 * recente (recupera webhooks perdidos, inclusive faturas e pagamentos) e conclui criações incertas. Somente leitura
 * no provedor, com exceção dos PUT do outbox já autorizados pelo contrato.
 *
 * @param array<string, int|null> $opcoes limite, sincronia-min, pausa-ms, empresa (null = global)
 * @return array<string, mixed>
 */
function mpSubRotina(mysqli $conexao, object $gateway, array $opcoes = []): array
{
    $idEmpresa = $opcoes['empresa'] ?? null;
    if ($idEmpresa !== null && (!is_int($idEmpresa) || $idEmpresa <= 0)) {
        throw new InvalidArgumentException('Empresa inválida para Subscriptions.');
    }
    if (!$gateway::subscriptionsHabilitado()) {
        return ['desabilitado' => true];
    }
    $limite = max(1, min(100, (int)($opcoes['limite'] ?? 20)));
    $sincroniaMin = max(1, min(1440, (int)($opcoes['sincronia-min'] ?? 30)));
    $pausa = max(0, min(5000, (int)($opcoes['pausa-ms'] ?? 0)));
    $resumo = ['outbox' => mpSubOperacoesProcessarPendentes($conexao, $gateway, $limite, $idEmpresa), 'vinculos' => 0, 'criacoes' => 0, 'erros' => 0];

    $vinculos = mpSubConsultaEmpresa(
        $conexao,
        "SELECT id_assinatura_pagamento, id_empresa FROM assinatura_pagamento_recorrente
          WHERE /*empresa*/ motor_recorrencia = 'preapproval' AND assinatura_externa_id IS NOT NULL
            AND status IN ('pendente', 'ativa', 'atualizacao_necessaria', 'suspensa', 'cancelamento_pendente')
            AND (ultima_sincronizacao_em IS NULL OR ultima_sincronizacao_em < DATE_SUB(NOW(6), INTERVAL ? MINUTE))
          ORDER BY ultima_sincronizacao_em IS NOT NULL, ultima_sincronizacao_em, id_assinatura_pagamento LIMIT {$limite}",
        $idEmpresa,
        'id_empresa',
        'i',
        $sincroniaMin
    );
    foreach ($vinculos as $i => $v) {
        mpSubConferirEmpresa($idEmpresa, $v);
        try {
            if ($i > 0 && $pausa > 0) {
                usleep($pausa * 1000);
            }
            $r = mpSubConciliarVinculo($conexao, $gateway, (int)$v['id_assinatura_pagamento'], $idEmpresa);
            $resumo['vinculos']++;
            if (($r['resultado'] ?? '') === 'erro') {
                $resumo['erros']++;
            }
        } catch (Throwable $e) {
            mpWebhookRollback($conexao);
            $resumo['erros']++;
        }
    }

    $empresas = mpSubConsultaEmpresa(
        $conexao,
        "SELECT DISTINCT o.id_empresa FROM operacao_assinatura_gateway o
          WHERE /*empresa*/ o.tipo_operacao = 'conciliar' AND o.status_processamento IN ('conciliacao', 'processando')
            AND (o.proxima_tentativa_em IS NULL OR o.proxima_tentativa_em <= NOW(6)
                 OR o.ultima_tentativa_em < DATE_SUB(NOW(6), INTERVAL " . ASSINATURA_SERVICO_PREAPPROVAL_OPERACAO_INTERROMPIDA_SEGUNDOS . " SECOND))
          LIMIT {$limite}",
        $idEmpresa,
        'o.id_empresa'
    );
    foreach ($empresas as $e) {
        mpSubConferirEmpresa($idEmpresa, $e);
        try {
            assinaturaServicoPreapprovalConciliar($conexao, $gateway, (int)$e['id_empresa'], null);
            $resumo['criacoes']++;
        } catch (Throwable $erro) {
            mpWebhookRollback($conexao);
            $resumo['erros']++;
        }
    }

    return $resumo;
}

// Definida exclusivamente pelo chamador PHP (CLI ou API autenticada), nunca pela requisição.
// Reutiliza a conciliação sem executar o receptor público de notificações.
if (defined('MP_WEBHOOK_BIBLIOTECA') && MP_WEBHOOK_BIBLIOTECA === true) {
    return;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    mpWebhookResponder(405, 'METHOD_NOT_ALLOWED');
}

$dataId = mpWebhookQueryExata('data.id');
$tipoQuery = mpWebhookQueryExata('type');
$requestId = mpWebhookCampoExterno($_SERVER['HTTP_X_REQUEST_ID'] ?? null, 150);
if ($dataId === null || ($tipoQuery !== 'order' && !in_array($tipoQuery, MP_SUB_TIPOS, true) && !in_array($tipoQuery, MP_SUB_TIPOS_SEM_ACAO, true)) || $requestId === null
    || !preg_match('/^[A-Za-z0-9_-]{1,150}$/D', $dataId)) {
    mpWebhookResponder(400, 'INVALID_EVENT');
}

try {
    $configuracao = mpWebhookConfiguracao();
} catch (Throwable) {
    mpWebhookRegistrarFalha('configuracao_indisponivel');
    mpWebhookResponder(503, 'TEMPORARY_FAILURE');
}

$timestampAssinatura = mpWebhookValidarAssinatura(
    $dataId,
    $requestId,
    $configuracao['secret']
);
if (in_array($tipoQuery, MP_SUB_TIPOS_SEM_ACAO, true)) {
    mpWebhookResponder(200, 'IGNORED_TOPIC', true);
}
// Subscriptions desligado: a notificação autêntica é reconhecida sem reservar evento nem tocar o banco.
if ($tipoQuery !== 'order' && !MercadoPagoGateway::subscriptionsHabilitado()) {
    mpWebhookResponder(200, 'SUBSCRIPTIONS_DISABLED', true);
}
$corpo = file_get_contents('php://input');
if (!is_string($corpo)) {
    mpWebhookResponder(400, 'INVALID_EVENT');
}
$corpoValidado = mpWebhookValidarCorpo($corpo, $dataId, $configuracao['ambiente'], $tipoQuery);
$chaveEvento = hash('sha256', implode('|', [
    MP_WEBHOOK_PROVEDOR,
    $configuracao['ambiente'],
    $tipoQuery,
    strtolower($dataId),
    $corpoValidado['versao'] === null ? '-' : (string) $corpoValidado['versao'],
    strtolower($corpoValidado['acao']),
    $requestId,
]));

$idEvento = 0;
try {
    require_once __DIR__ . '/../_config/conexao.php';
    if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
        throw new RuntimeException('Conexão indisponível.');
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conexao->set_charset('utf8mb4');

    $reserva = mpWebhookReservarEvento($conexao, [
        'ambiente' => $configuracao['ambiente'],
        'tipo_recurso' => $tipoQuery,
        'acao' => $corpoValidado['acao'],
        'recurso_externo_id' => $dataId,
        'versao' => $corpoValidado['versao'],
        'request_id' => $requestId,
        'chave_evento' => $chaveEvento,
        'payload_hash' => $corpoValidado['payload_hash'],
        'assinatura_timestamp' => $timestampAssinatura,
    ]);
    $idEvento = $reserva['id_evento'];
    if ($reserva['duplicado']) {
        mpWebhookResponder(200, 'DUPLICATE', true);
    }

    if ($tipoQuery !== 'order') {
        $resultadoSub = mpSubProcessarEvento($conexao, $configuracao['gateway'], $configuracao['ambiente'], $idEvento, $tipoQuery, $dataId);
        if ($resultadoSub['resultado'] === 'erro') {
            mpWebhookRegistrarFalha('subscriptions_falhou', ['evento' => $idEvento, 'codigo' => (string)($resultadoSub['codigo'] ?? '')]);
            mpWebhookResponder(503, 'TEMPORARY_FAILURE');
        }
        mpWebhookResponder(200, $resultadoSub['resultado'] === 'ignorado' ? 'IGNORED' : 'PROCESSED', true);
    }

    $resposta = $configuracao['gateway']->requisitar(
        'consultar_order',
        ['order_id' => $dataId]
    );
    if (!($resposta['sucesso'] ?? false) || !is_array($resposta['dados'] ?? null)) {
        mpWebhookRegistrarFalha('consulta_order_falhou', [
            'evento' => $idEvento,
            'codigo' => (string)($resposta['codigo'] ?? ''),
            'http' => (int)($resposta['http_status'] ?? 0),
        ]);
        mpWebhookEventoErro(
            $conexao,
            $idEvento,
            mpWebhookCampoExterno($resposta['codigo'] ?? null, 100) ?? 'falha_consulta_order'
        );
        mpWebhookResponder(503, 'TEMPORARY_FAILURE');
    }
    $order = mpWebhookNormalizarOrder($resposta['dados'], $dataId);
    if ($order === null) {
        mpWebhookRegistrarFalha('resposta_order_invalida', ['evento' => $idEvento] + mpWebhookEstruturaOrder($resposta['dados']));
        mpWebhookEventoErro($conexao, $idEvento, 'resposta_order_invalida');
        mpWebhookResponder(503, 'TEMPORARY_FAILURE');
    }
    $idTransacao = mpWebhookLocalizarTransacao($conexao, $order, $configuracao['ambiente']);
    if ($idTransacao === null) {
        mpWebhookEventoIgnorado($conexao, $idEvento, 'transacao_nao_identificada');
        mpWebhookResponder(200, 'IGNORED', true);
    }

    mpWebhookAplicarOrder($conexao, $idEvento, $idTransacao, $order);
    mpWebhookResponder(200, 'PROCESSED', true);
} catch (Throwable $erroProcessamento) {
    mpWebhookRegistrarFalha('falha_processamento', [
        'evento' => $idEvento,
        'classe' => get_class($erroProcessamento),
        'mensagem' => $erroProcessamento->getMessage(),
    ]);
    if (isset($conexao) && $conexao instanceof mysqli) {
        mpWebhookRollback($conexao);
        if ($idEvento > 0) {
            try {
                mpWebhookEventoErro($conexao, $idEvento, 'falha_processamento');
            } catch (Throwable) {
            }
        }
    }
    mpWebhookResponder(503, 'TEMPORARY_FAILURE');
}
