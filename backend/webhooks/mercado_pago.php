<?php
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/../_gateways/mercado_pago.php';
require_once __DIR__ . '/../_servicos/auditoria.php';
require_once __DIR__ . '/../_servicos/assinatura.php';

const MP_WEBHOOK_PROVEDOR = 'mercado_pago';
const MP_WEBHOOK_CORPO_MAX_BYTES = 1048576;
const MP_WEBHOOK_LEASE_SEGUNDOS = 60;

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
function mpWebhookValidarCorpo(string $corpo, string $dataId, string $ambiente): array
{
    if ($corpo === '' || strlen($corpo) > MP_WEBHOOK_CORPO_MAX_BYTES) {
        mpWebhookResponder(400, 'INVALID_EVENT');
    }
    try {
        $dados = json_decode($corpo, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        mpWebhookResponder(400, 'INVALID_EVENT');
    }
    if (!is_array($dados) || array_is_list($dados) || ($dados['type'] ?? null) !== 'order'
        || !is_array($dados['data'] ?? null)) {
        mpWebhookResponder(400, 'INVALID_EVENT');
    }
    $idCorpo = mpWebhookCampoExterno($dados['data']['id'] ?? null, 150);
    $acao = mpWebhookCampoExterno($dados['action'] ?? null, 100);
    if ($idCorpo === null || !hash_equals(strtolower($dataId), strtolower($idCorpo))
        || $acao === null || !preg_match('/^order\.[a-z_]+$/D', $acao)
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
             VALUES (NULL,NULL,?,?,'order',?,?,?,?,?,?,?,'recebido',0,NULL,NOW(),NULL,NOW())
             ON DUPLICATE KEY UPDATE id_evento_gateway=LAST_INSERT_ID(id_evento_gateway)",
            'ssssissss',
            MP_WEBHOOK_PROVEDOR,
            $evento['ambiente'],
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

/** @return array<string,mixed>|null */
function mpWebhookNormalizarOrder(array $order, string $idEsperado): ?array
{
    $id = mpWebhookCampoExterno($order['id'] ?? null, 150);
    $referencia = mpWebhookCampoExterno($order['external_reference'] ?? null, 64);
    $status = mpWebhookCampoExterno($order['status'] ?? null, 50);
    $detalhe = mpWebhookCampoExterno($order['status_detail'] ?? null, 100);
    $moeda = $order['currency_id'] ?? null;
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
    int $idEvento,
    int $idTransacao,
    ?int $idAssinaturaPagamento
): void {
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
        'contexto' => ['origem' => 'webhook_mercado_pago', 'motivo' => $motivo],
    ]);
}

function mpWebhookMarcarConciliacao(
    mysqli $conexao,
    array $transacao,
    int $idEvento,
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

function mpWebhookAplicarOrder(
    mysqli $conexao,
    int $idEvento,
    int $idTransacao,
    array $order
): void {
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

        $inconsistente = $transacao['provedor'] !== MP_WEBHOOK_PROVEDOR
            || $transacao['ambiente'] !== $evento['ambiente']
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
                    ['origem' => 'webhook_mercado_pago']
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
                    'contexto' => ['origem' => 'webhook_mercado_pago'],
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
                ['origem' => 'webhook_mercado_pago']
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
                    'contexto' => ['origem' => 'webhook_mercado_pago'],
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
                'contexto' => ['origem' => 'webhook_mercado_pago'],
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

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    mpWebhookResponder(405, 'METHOD_NOT_ALLOWED');
}

$dataId = mpWebhookQueryExata('data.id');
$tipoQuery = mpWebhookQueryExata('type');
$requestId = mpWebhookCampoExterno($_SERVER['HTTP_X_REQUEST_ID'] ?? null, 150);
if ($dataId === null || $tipoQuery !== 'order' || $requestId === null
    || !preg_match('/^[A-Za-z0-9_-]{1,150}$/D', $dataId)) {
    mpWebhookResponder(400, 'INVALID_EVENT');
}

try {
    $configuracao = mpWebhookConfiguracao();
} catch (Throwable) {
    mpWebhookResponder(503, 'TEMPORARY_FAILURE');
}

$timestampAssinatura = mpWebhookValidarAssinatura(
    $dataId,
    $requestId,
    $configuracao['secret']
);
$corpo = file_get_contents('php://input');
if (!is_string($corpo)) {
    mpWebhookResponder(400, 'INVALID_EVENT');
}
$corpoValidado = mpWebhookValidarCorpo($corpo, $dataId, $configuracao['ambiente']);
$chaveEvento = hash('sha256', implode('|', [
    MP_WEBHOOK_PROVEDOR,
    $configuracao['ambiente'],
    'order',
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

    $resposta = $configuracao['gateway']->requisitar(
        'consultar_order',
        ['order_id' => $dataId]
    );
    if (!($resposta['sucesso'] ?? false) || !is_array($resposta['dados'] ?? null)) {
        mpWebhookEventoErro(
            $conexao,
            $idEvento,
            mpWebhookCampoExterno($resposta['codigo'] ?? null, 100) ?? 'falha_consulta_order'
        );
        mpWebhookResponder(503, 'TEMPORARY_FAILURE');
    }
    $order = mpWebhookNormalizarOrder($resposta['dados'], $dataId);
    if ($order === null) {
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
} catch (Throwable) {
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
