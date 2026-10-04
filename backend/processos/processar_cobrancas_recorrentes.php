<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Execução permitida somente em CLI.\n");
    exit(1);
}

if (($argc ?? 0) > 1) {
    fwrite(STDERR, "Esta rotina não aceita argumentos.\n");
    exit(1);
}

date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/../_config/conexao.php';
require_once __DIR__ . '/../_gateways/mercado_pago.php';
require_once __DIR__ . '/../_servicos/assinatura.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const COBRANCAS_RECORRENTES_LIMITE = 100;
const COBRANCAS_RECORRENTES_RETRIES_MP = 3;
const COBRANCAS_RECORRENTES_LEASE_MINUTOS = 5;

/** @return mysqli_result */
function cobrancasRecorrentesConsulta(
    mysqli $conexao,
    string $sql,
    string $tipos = '',
    mixed ...$valores
): mysqli_result {
    $stmt = $conexao->prepare($sql);
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$valores);
    }
    $stmt->execute();
    $resultado = $stmt->get_result();
    $stmt->close();

    if (!$resultado) {
        throw new RuntimeException('Consulta financeira sem resultado.');
    }

    return $resultado;
}

function cobrancasRecorrentesExecutar(
    mysqli $conexao,
    string $sql,
    string $tipos,
    mixed ...$valores
): int {
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param($tipos, ...$valores);
    $stmt->execute();
    $afetadas = $stmt->affected_rows;
    $stmt->close();

    return $afetadas;
}

function cobrancasRecorrentesRollback(mysqli $conexao): void
{
    try {
        $conexao->rollback();
    } catch (Throwable) {
    }
}

function cobrancasRecorrentesCampoExterno(mixed $valor, int $maximo): ?string
{
    if (!is_string($valor) && !is_int($valor)) {
        return null;
    }

    $texto = (string) $valor;
    if ($texto === '' || strlen($texto) > $maximo || !preg_match('/^[A-Za-z0-9_.-]+$/D', $texto)) {
        return null;
    }

    return $texto;
}

function cobrancasRecorrentesStatusLocal(string $statusExterno): string
{
    return match ($statusExterno) {
        'created', 'action_required' => 'aguardando',
        'processing', 'in_review' => 'processando',
        'processed' => 'confirmada',
        'failed' => 'recusada',
        'expired' => 'expirada',
        'canceled' => 'cancelada',
        default => 'conciliacao',
    };
}

function cobrancasRecorrentesTotalPagoCentavos(mysqli $conexao, int $idEmpresa, int $idCobranca): int
{
    $pagamentos = cobrancasRecorrentesConsulta(
        $conexao,
        "SELECT valor_pago
           FROM pagamento
          WHERE id_empresa = ?
            AND id_cobranca = ?
            AND status = 'confirmado'
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

/** @return array<string, mixed>|null */
function cobrancasRecorrentesValidarOrder(array $transacao, array $order): ?array
{
    $orderId = cobrancasRecorrentesCampoExterno($order['id'] ?? null, 150);
    $statusOrder = cobrancasRecorrentesCampoExterno($order['status'] ?? null, 50);
    $detalheOrder = cobrancasRecorrentesCampoExterno($order['status_detail'] ?? null, 100);
    $referencia = $order['external_reference'] ?? null;
    $valorOrder = $order['total_amount'] ?? null;
    $tipo = $order['type'] ?? null;
    $modo = $order['processing_mode'] ?? null;
    $pagador = is_array($order['payer'] ?? null) ? $order['payer'] : [];
    $pagamentos = is_array($order['transactions']['payments'] ?? null)
        ? $order['transactions']['payments']
        : [];
    $pagamento = is_array($pagamentos[0] ?? null) ? $pagamentos[0] : [];
    $pagamentoId = cobrancasRecorrentesCampoExterno($pagamento['id'] ?? null, 150);
    $statusPagamento = cobrancasRecorrentesCampoExterno($pagamento['status'] ?? null, 50);
    $detalhePagamento = cobrancasRecorrentesCampoExterno($pagamento['status_detail'] ?? null, 100);
    $valorPagamento = $pagamento['amount'] ?? null;
    $automatico = is_array($pagamento['automatic_payments'] ?? null)
        ? $pagamento['automatic_payments']
        : [];

    try {
        $valorEsperado = pagamentoGatewayValorCentavos((string) $transacao['valor']);
        $valorOrderValido = is_string($valorOrder)
            && pagamentoGatewayValorCentavos($valorOrder) === $valorEsperado;
        $valorPagamentoValido = is_string($valorPagamento)
            && pagamentoGatewayValorCentavos($valorPagamento) === $valorEsperado;
    } catch (InvalidArgumentException) {
        return null;
    }

    if ($orderId === null || $statusOrder === null
        || $referencia !== $transacao['referencia_interna']
        || !$valorOrderValido || !$valorPagamentoValido
        || $tipo !== 'online' || $modo !== 'automatic_async'
        || ($pagador['customer_id'] ?? null) !== $transacao['cliente_externo_id']
        || ($automatico['payment_profile_id'] ?? null) !== $transacao['perfil_externo_id']) {
        return null;
    }

    $statusLocal = cobrancasRecorrentesStatusLocal($statusOrder);
    if ($statusLocal === 'confirmada'
        && ($detalheOrder !== 'accredited' || $pagamentoId === null
            || $statusPagamento !== 'processed' || $detalhePagamento !== 'accredited')) {
        $statusLocal = 'conciliacao';
    }

    return [
        'ordem_externa_id' => $orderId,
        'pagamento_externo_id' => $pagamentoId,
        'status_externo' => $statusOrder,
        'detalhe_status_externo' => $detalheOrder,
        'status' => $statusLocal,
    ];
}

function cobrancasRecorrentesMarcarIncerta(
    mysqli $conexao,
    array $transacao,
    string $codigo
): void {
    $codigo = cobrancasRecorrentesCampoExterno($codigo, 100) ?? 'resultado_externo_incerto';
    cobrancasRecorrentesExecutar(
        $conexao,
        "UPDATE transacao_pagamento
            SET status = 'enviando',
                requer_conciliacao = 1,
                motivo_conciliacao = 'Resultado externo incerto; reutilizar a mesma idempotência.',
                erro_codigo = ?
          WHERE id_transacao = ?
            AND id_empresa = ?
            AND ordem_externa_id IS NULL",
        'sii',
        $codigo,
        (int) $transacao['id_transacao'],
        (int) $transacao['id_empresa']
    );
}

function cobrancasRecorrentesMarcarFalhaTerminal(
    mysqli $conexao,
    array $transacao,
    string $status,
    ?string $statusExterno,
    ?string $detalheExterno,
    string $codigo
): void {
    $codigo = cobrancasRecorrentesCampoExterno($codigo, 100) ?? 'falha_gateway';
    cobrancasRecorrentesExecutar(
        $conexao,
        'UPDATE transacao_pagamento
            SET status = ?,
                status_externo = ?,
                detalhe_status_externo = ?,
                ultima_consulta_em = NOW(),
                requer_conciliacao = 0,
                motivo_conciliacao = NULL,
                erro_codigo = ?
          WHERE id_transacao = ?
            AND id_empresa = ?
            AND ordem_externa_id IS NULL',
        'ssssii',
        $status,
        $statusExterno,
        $detalheExterno,
        $codigo,
        (int) $transacao['id_transacao'],
        (int) $transacao['id_empresa']
    );
}

function cobrancasRecorrentesAplicarOrder(
    mysqli $conexao,
    array $transacao,
    array $order
): string {
    $dados = cobrancasRecorrentesValidarOrder($transacao, $order);
    if ($dados === null) {
        cobrancasRecorrentesMarcarIncerta($conexao, $transacao, 'resposta_order_divergente');
        return 'conciliacao';
    }

    $conexao->begin_transaction();
    try {
        $atual = cobrancasRecorrentesConsulta(
            $conexao,
            'SELECT id_transacao,id_empresa,status,ordem_externa_id,pagamento_externo_id
               FROM transacao_pagamento
              WHERE id_transacao = ?
                AND id_empresa = ?
              FOR UPDATE',
            'ii',
            (int) $transacao['id_transacao'],
            (int) $transacao['id_empresa']
        )->fetch_assoc();

        if (!$atual) {
            throw new RuntimeException('Transação local não encontrada.');
        }

        $ordemConflitante = $atual['ordem_externa_id'] !== null
            && $atual['ordem_externa_id'] !== $dados['ordem_externa_id'];
        $pagamentoConflitante = $atual['pagamento_externo_id'] !== null
            && $dados['pagamento_externo_id'] !== null
            && $atual['pagamento_externo_id'] !== $dados['pagamento_externo_id'];

        if ($ordemConflitante || $pagamentoConflitante) {
            cobrancasRecorrentesExecutar(
                $conexao,
                "UPDATE transacao_pagamento
                    SET status = 'conciliacao',
                        requer_conciliacao = 1,
                        motivo_conciliacao = 'Identificador externo divergente da transação local.',
                        erro_codigo = 'identificador_externo_divergente'
                  WHERE id_transacao = ?
                    AND id_empresa = ?",
                'ii',
                (int) $transacao['id_transacao'],
                (int) $transacao['id_empresa']
            );
            $conexao->commit();
            return 'conciliacao';
        }

        $status = (string) $dados['status'];
        if ($atual['status'] === 'confirmada' && $status !== 'confirmada') {
            $status = 'confirmada';
        } elseif ($atual['status'] === 'processando' && $status === 'aguardando') {
            $status = 'processando';
        }

        $requerConciliacao = $status === 'conciliacao' ? 1 : 0;
        $motivo = $requerConciliacao === 1
            ? 'Status externo ainda não reconhecido pelo fluxo financeiro.'
            : null;
        $erro = $status === 'recusada'
            ? ($dados['detalhe_status_externo'] ?? 'pagamento_recusado')
            : ($requerConciliacao === 1 ? 'status_externo_desconhecido' : null);
        $pagamentoId = $dados['pagamento_externo_id'] ?? $atual['pagamento_externo_id'];

        cobrancasRecorrentesExecutar(
            $conexao,
            'UPDATE transacao_pagamento
                SET ordem_externa_id = ?,
                    pagamento_externo_id = ?,
                    status = ?,
                    status_externo = ?,
                    detalhe_status_externo = ?,
                    ultima_consulta_em = NOW(),
                    requer_conciliacao = ?,
                    motivo_conciliacao = ?,
                    erro_codigo = ?
              WHERE id_transacao = ?
                AND id_empresa = ?',
            'sssssissii',
            $dados['ordem_externa_id'],
            $pagamentoId,
            $status,
            $dados['status_externo'],
            $dados['detalhe_status_externo'],
            $requerConciliacao,
            $motivo,
            $erro,
            (int) $transacao['id_transacao'],
            (int) $transacao['id_empresa']
        );

        $conexao->commit();
        return $status;
    } catch (Throwable $erro) {
        cobrancasRecorrentesRollback($conexao);
        throw $erro;
    }
}

function cobrancasRecorrentesCodigoExterno(array $resposta): ?string
{
    $dados = is_array($resposta['dados'] ?? null) ? $resposta['dados'] : [];
    $candidatos = [
        $dados['code'] ?? null,
        $dados['error'] ?? null,
        $dados['errors'][0]['code'] ?? null,
    ];

    foreach ($candidatos as $candidato) {
        $codigo = cobrancasRecorrentesCampoExterno($candidato, 100);
        if ($codigo !== null) {
            return $codigo;
        }
    }

    return null;
}

function cobrancasRecorrentesTratarFalhaGateway(
    mysqli $conexao,
    array $transacao,
    array $resposta
): string {
    $dados = is_array($resposta['dados'] ?? null) ? $resposta['dados'] : [];
    if (isset($dados['id'], $dados['external_reference'], $dados['transactions'])) {
        $validada = cobrancasRecorrentesValidarOrder($transacao, $dados);
        if ($validada !== null) {
            return cobrancasRecorrentesAplicarOrder($conexao, $transacao, $dados);
        }
    }

    $httpStatus = (int) ($resposta['http_status'] ?? 0);
    $codigoExterno = cobrancasRecorrentesCodigoExterno($resposta);
    $codigo = $codigoExterno
        ?? cobrancasRecorrentesCampoExterno($resposta['codigo'] ?? null, 100)
        ?? 'falha_gateway';
    $incertos = ['idempotency_key_already_used', 'resource_locked', 'too_many_requests',
        'usage_quota_exceeded', 'idempotency_validation_failed', 'internal_error'];

    if ($httpStatus === 0 || $httpStatus >= 500 || in_array($httpStatus, [409, 423, 429], true)
        || in_array($codigo, $incertos, true)) {
        cobrancasRecorrentesMarcarIncerta($conexao, $transacao, $codigo);
        return 'conciliacao';
    }

    if ($httpStatus === 402) {
        cobrancasRecorrentesMarcarFalhaTerminal(
            $conexao,
            $transacao,
            'recusada',
            'failed',
            $codigoExterno,
            $codigo
        );
        return 'recusada';
    }

    cobrancasRecorrentesMarcarFalhaTerminal(
        $conexao,
        $transacao,
        'erro',
        null,
        $codigoExterno,
        $codigo
    );
    return 'erro';
}

/** @return array{resultado:string,elegivel:bool,criada:bool,transacao?:array<string,mixed>} */
function cobrancasRecorrentesReservar(
    mysqli $conexao,
    int $idCobranca,
    string $ambiente
): array {
    $conexao->begin_transaction();
    try {
        $vinculo = cobrancasRecorrentesConsulta(
            $conexao,
            "SELECT c.id_cobranca,c.id_empresa,c.id_assinatura,c.valor,c.status AS cobranca_status,
                    DATE_FORMAT(c.data_vencimento, '%Y-%m-%d') AS data_vencimento,
                    a.status AS assinatura_status,a.modalidade AS assinatura_modalidade,
                    apr.id_assinatura_pagamento,apr.ambiente,apr.status AS vinculo_status,
                    apr.status_externo,apr.slot_ativo,apr.cobrar_a_partir_de,
                    CASE WHEN apr.cobrar_a_partir_de <= NOW() THEN 1 ELSE 0 END AS pode_cobrar,
                    apr.cliente_externo_id,apr.perfil_externo_id,apr.meio_pagamento_externo_id
               FROM cobranca c
               JOIN empresa e
                 ON e.id_empresa = c.id_empresa
                AND e.status = 'ativo'
                JOIN assinatura a
                  ON a.id_assinatura = c.id_assinatura
                 AND a.id_empresa = c.id_empresa
                JOIN plano p
                  ON p.id_plano = a.id_plano
                 AND p.gera_cobranca = 1
                JOIN assinatura_pagamento_recorrente apr
                 ON apr.id_assinatura = a.id_assinatura
                AND apr.id_empresa = a.id_empresa
                AND apr.provedor = 'mercado_pago'
                AND apr.ambiente = ?
              WHERE c.id_cobranca = ?
              LIMIT 1
              FOR UPDATE",
            'si',
            $ambiente,
            $idCobranca
        )->fetch_assoc();

        $elegivel = $vinculo
            && $vinculo['cobranca_status'] === 'pendente'
            && $vinculo['assinatura_status'] === 'ativa'
            && $vinculo['assinatura_modalidade'] === 'paga'
            && $vinculo['vinculo_status'] === 'ativa'
            && strtoupper((string) $vinculo['status_externo']) === 'READY'
            && (int) $vinculo['slot_ativo'] === 1
            && $vinculo['cobrar_a_partir_de'] !== null
            && (int) $vinculo['pode_cobrar'] === 1
            && (string) $vinculo['data_vencimento'] <= date('Y-m-d')
            && cobrancasRecorrentesCampoExterno($vinculo['cliente_externo_id'] ?? null, 150) !== null
            && cobrancasRecorrentesCampoExterno($vinculo['perfil_externo_id'] ?? null, 150) !== null
            && cobrancasRecorrentesCampoExterno($vinculo['meio_pagamento_externo_id'] ?? null, 150) !== null;

        if (!$elegivel) {
            cobrancasRecorrentesRollback($conexao);
            return ['resultado' => 'ignorada', 'elegivel' => false, 'criada' => false];
        }

        $valorCobranca = pagamentoGatewayValorCentavos((string) $vinculo['valor']);
        $totalPago = cobrancasRecorrentesTotalPagoCentavos(
            $conexao,
            (int) $vinculo['id_empresa'],
            (int) $vinculo['id_cobranca']
        );
        $saldo = $valorCobranca - $totalPago;
        if ($saldo <= 0) {
            cobrancasRecorrentesRollback($conexao);
            return ['resultado' => 'ignorada', 'elegivel' => true, 'criada' => false];
        }

        $transacoes = cobrancasRecorrentesConsulta(
            $conexao,
            'SELECT id_transacao,id_empresa,id_cobranca,id_assinatura_pagamento,id_transacao_anterior,
                    ambiente,metodo,tipo_operacao,valor,status,chave_idempotencia,referencia_interna,
                    ordem_externa_id,pagamento_externo_id,numero_tentativa,requer_conciliacao,atualizado_em
               FROM transacao_pagamento
              WHERE id_empresa = ?
                AND id_cobranca = ?
              ORDER BY id_transacao DESC
              FOR UPDATE',
            'ii',
            (int) $vinculo['id_empresa'],
            (int) $vinculo['id_cobranca']
        )->fetch_all(MYSQLI_ASSOC);

        $estadosAtivos = ['criada', 'enviando', 'aguardando', 'processando', 'confirmada', 'conciliacao'];
        $ativas = array_values(array_filter(
            $transacoes,
            static fn(array $item): bool => in_array((string) $item['status'], $estadosAtivos, true)
        ));

        if (count($ativas) > 1) {
            cobrancasRecorrentesRollback($conexao);
            return ['resultado' => 'conciliacao', 'elegivel' => true, 'criada' => false];
        }

        $valor = pagamentoGatewayValorDecimal($saldo);
        $transacao = $ativas[0] ?? null;
        $criada = false;

        if ($transacao !== null) {
            $reutilizavel = $transacao['metodo'] === 'cartao_recorrente'
                && $transacao['tipo_operacao'] === 'recorrente_cartao'
                && $transacao['ambiente'] === $ambiente
                && (int) $transacao['id_assinatura_pagamento'] === (int) $vinculo['id_assinatura_pagamento']
                && in_array($transacao['status'], ['criada', 'enviando'], true)
                && $transacao['ordem_externa_id'] === null
                && $transacao['pagamento_externo_id'] === null
                && pagamentoGatewayValorCentavos((string) $transacao['valor']) === $saldo;

            if (!$reutilizavel) {
                cobrancasRecorrentesRollback($conexao);
                return ['resultado' => 'ignorada', 'elegivel' => true, 'criada' => false];
            }

            if ($transacao['status'] === 'criada') {
                $afetadas = cobrancasRecorrentesExecutar(
                    $conexao,
                    "UPDATE transacao_pagamento
                        SET status = 'enviando',
                            atualizado_em = NOW()
                      WHERE id_transacao = ?
                        AND status = 'criada'",
                    'i',
                    (int) $transacao['id_transacao']
                );
            } else {
                $afetadas = cobrancasRecorrentesExecutar(
                    $conexao,
                    "UPDATE transacao_pagamento
                        SET atualizado_em = NOW()
                      WHERE id_transacao = ?
                        AND status = 'enviando'
                        AND atualizado_em <= DATE_SUB(NOW(), INTERVAL " . COBRANCAS_RECORRENTES_LEASE_MINUTOS . " MINUTE)",
                    'i',
                    (int) $transacao['id_transacao']
                );
            }

            if ($afetadas !== 1) {
                cobrancasRecorrentesRollback($conexao);
                return ['resultado' => 'ignorada', 'elegivel' => true, 'criada' => false];
            }
        } else {
            $anterioresCartao = array_values(array_filter(
                $transacoes,
                static fn(array $item): bool => $item['metodo'] === 'cartao_recorrente'
            ));

            // Retentativas da mesma Order ficam no Mercado Pago; resultado terminal não gera outra Order automaticamente.
            if ($anterioresCartao !== []) {
                cobrancasRecorrentesRollback($conexao);
                return ['resultado' => 'ignorada', 'elegivel' => true, 'criada' => false];
            }

            $chave = pagamentoGatewayChaveIdempotencia();
            $referencia = pagamentoGatewayReferenciaInterna();
            cobrancasRecorrentesExecutar(
                $conexao,
                "INSERT INTO transacao_pagamento
                    (id_empresa,id_cobranca,id_assinatura_pagamento,id_transacao_anterior,
                     provedor,ambiente,metodo,tipo_operacao,valor,moeda,status,
                     chave_idempotencia,referencia_interna,numero_tentativa)
                 VALUES (?,?,?,NULL,'mercado_pago',?,'cartao_recorrente','recorrente_cartao',
                         ?,'BRL','criada',?,?,1)",
                'iiissss',
                (int) $vinculo['id_empresa'],
                (int) $vinculo['id_cobranca'],
                (int) $vinculo['id_assinatura_pagamento'],
                $ambiente,
                $valor,
                $chave,
                $referencia
            );
            $idTransacao = (int) $conexao->insert_id;
            cobrancasRecorrentesExecutar(
                $conexao,
                "UPDATE transacao_pagamento
                    SET status = 'enviando',
                        atualizado_em = NOW()
                  WHERE id_transacao = ?
                    AND status = 'criada'",
                'i',
                $idTransacao
            );
            $transacao = [
                'id_transacao' => $idTransacao,
                'id_empresa' => (int) $vinculo['id_empresa'],
                'id_cobranca' => (int) $vinculo['id_cobranca'],
                'id_assinatura_pagamento' => (int) $vinculo['id_assinatura_pagamento'],
                'ambiente' => $ambiente,
                'metodo' => 'cartao_recorrente',
                'tipo_operacao' => 'recorrente_cartao',
                'valor' => $valor,
                'status' => 'enviando',
                'chave_idempotencia' => $chave,
                'referencia_interna' => $referencia,
                'ordem_externa_id' => null,
                'pagamento_externo_id' => null,
                'numero_tentativa' => 1,
            ];
            $criada = true;
        }

        $anterior = cobrancasRecorrentesConsulta(
            $conexao,
            "SELECT pagamento_externo_id
               FROM transacao_pagamento
              WHERE id_empresa = ?
                AND id_assinatura_pagamento = ?
                AND metodo = 'cartao_recorrente'
                AND id_transacao <> ?
                AND pagamento_externo_id IS NOT NULL
              ORDER BY id_transacao DESC
              LIMIT 1",
            'iii',
            (int) $vinculo['id_empresa'],
            (int) $vinculo['id_assinatura_pagamento'],
            (int) $transacao['id_transacao']
        )->fetch_assoc();
        $referenciaAnterior = cobrancasRecorrentesCampoExterno($anterior['pagamento_externo_id'] ?? null, 150);

        $transacao['valor'] = $valor;
        $transacao['id_assinatura'] = (int) $vinculo['id_assinatura'];
        $transacao['cliente_externo_id'] = (string) $vinculo['cliente_externo_id'];
        $transacao['perfil_externo_id'] = (string) $vinculo['perfil_externo_id'];
        $transacao['referencia_transacao_anterior'] = $referenciaAnterior;
        $transacao['status'] = 'enviando';

        $conexao->commit();
        return [
            'resultado' => 'enviar',
            'elegivel' => true,
            'criada' => $criada,
            'transacao' => $transacao,
        ];
    } catch (Throwable $erro) {
        cobrancasRecorrentesRollback($conexao);
        throw $erro;
    }
}

function cobrancasRecorrentesPayload(array $transacao): array
{
    $referenciaAnterior = $transacao['referencia_transacao_anterior'] ?? null;
    $credencial = [
        'payment_initiator' => 'merchant',
        'reason' => 'recurring',
        'first_payment' => $referenciaAnterior === null,
    ];
    if ($referenciaAnterior !== null) {
        $credencial['previous_transaction_reference'] = $referenciaAnterior;
    }

    return [
        'type' => 'online',
        'external_reference' => (string) $transacao['referencia_interna'],
        'total_amount' => (string) $transacao['valor'],
        'processing_mode' => 'automatic_async',
        'payer' => [
            'customer_id' => (string) $transacao['cliente_externo_id'],
        ],
        'transactions' => [
            'payments' => [[
                'amount' => (string) $transacao['valor'],
                'automatic_payments' => [
                    'payment_profile_id' => (string) $transacao['perfil_externo_id'],
                    'retries' => COBRANCAS_RECORRENTES_RETRIES_MP,
                ],
                'stored_credential' => $credencial,
            ]],
        ],
    ];
}

$resumo = [
    'analisadas' => 0,
    'elegiveis' => 0,
    'transacoes_criadas' => 0,
    'orders_enviadas' => 0,
    'recusadas' => 0,
    'ignoradas' => 0,
    'conciliacao' => 0,
    'erros' => 0,
];

try {
    if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
        throw new RuntimeException('Conexão indisponível.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Extensão cURL indisponível.');
    }
    $conexao->set_charset('utf8mb4');

    // A configuração é validada antes da seleção para não reservar transações sem credencial utilizável.
    $gateway = MercadoPagoGateway::daConfiguracao();
    $ambiente = $gateway->ambiente();

    $candidatas = cobrancasRecorrentesConsulta(
        $conexao,
        "SELECT c.id_cobranca
           FROM cobranca c
           JOIN empresa e
             ON e.id_empresa = c.id_empresa
            AND e.status = 'ativo'
           JOIN assinatura a
             ON a.id_assinatura = c.id_assinatura
            AND a.id_empresa = c.id_empresa
           JOIN plano p
             ON p.id_plano = a.id_plano
            AND p.gera_cobranca = 1
           JOIN assinatura_pagamento_recorrente apr
             ON apr.id_assinatura = a.id_assinatura
            AND apr.id_empresa = a.id_empresa
            AND apr.provedor = 'mercado_pago'
            AND apr.ambiente = ?
          WHERE c.status = 'pendente'
            AND c.data_vencimento <= CURDATE()
            AND a.status = 'ativa'
            AND a.modalidade = 'paga'
            AND apr.status = 'ativa'
            AND UPPER(apr.status_externo) = 'READY'
            AND apr.slot_ativo = 1
            AND apr.cobrar_a_partir_de IS NOT NULL
            AND apr.cobrar_a_partir_de <= NOW()
          ORDER BY c.data_vencimento ASC,c.id_cobranca ASC
          LIMIT " . COBRANCAS_RECORRENTES_LIMITE,
        's',
        $ambiente
    )->fetch_all(MYSQLI_ASSOC);

    foreach ($candidatas as $candidata) {
        $resumo['analisadas']++;
        try {
            $reserva = cobrancasRecorrentesReservar(
                $conexao,
                (int) $candidata['id_cobranca'],
                $ambiente
            );
            if ($reserva['elegivel']) {
                $resumo['elegiveis']++;
            }
            if ($reserva['resultado'] === 'conciliacao') {
                $resumo['conciliacao']++;
                continue;
            }
            if ($reserva['resultado'] !== 'enviar' || !isset($reserva['transacao'])) {
                $resumo['ignoradas']++;
                continue;
            }
            if ($reserva['criada']) {
                $resumo['transacoes_criadas']++;
            }

            $transacao = $reserva['transacao'];
            if (!assinaturaServicoPlanoGeraCobranca($conexao, (int) $transacao['id_assinatura'])) {
                cobrancasRecorrentesExecutar(
                    $conexao,
                    "UPDATE transacao_pagamento
                        SET status = 'erro',
                            erro_codigo = 'PLAN_NO_BILLING',
                            atualizado_em = NOW()
                      WHERE id_transacao = ?
                        AND ordem_externa_id IS NULL
                        AND status IN ('criada', 'enviando')",
                    'i',
                    (int) $transacao['id_transacao']
                );
                $resumo['ignoradas']++;
                continue;
            }
            $resumo['orders_enviadas']++;
            $resposta = $gateway->requisitar(
                'criar_order',
                [],
                cobrancasRecorrentesPayload($transacao),
                (string) $transacao['chave_idempotencia']
            );

            $status = ($resposta['sucesso'] ?? false)
                ? cobrancasRecorrentesAplicarOrder($conexao, $transacao, $resposta['dados'])
                : cobrancasRecorrentesTratarFalhaGateway($conexao, $transacao, $resposta);

            if ($status === 'recusada') {
                $resumo['recusadas']++;
            } elseif (in_array($status, ['confirmada', 'conciliacao'], true)) {
                $resumo['conciliacao']++;
            } elseif ($status === 'erro') {
                $resumo['erros']++;
            }
        } catch (Throwable) {
            cobrancasRecorrentesRollback($conexao);
            $resumo['erros']++;
            fwrite(STDERR, "Erro ao processar uma cobrança recorrente.\n");
        }
    }
} catch (Throwable) {
    fwrite(STDERR, "Falha global ao preparar cobranças recorrentes.\n");
    exit(1);
}

echo "Cobranças analisadas: {$resumo['analisadas']}\n";
echo "Cobranças elegíveis: {$resumo['elegiveis']}\n";
echo "Transações criadas: {$resumo['transacoes_criadas']}\n";
echo "Orders enviadas: {$resumo['orders_enviadas']}\n";
echo "Recusadas: {$resumo['recusadas']}\n";
echo "Ignoradas: {$resumo['ignoradas']}\n";
echo "Conciliação necessária: {$resumo['conciliacao']}\n";
echo "Erros: {$resumo['erros']}\n";

exit($resumo['erros'] > 0 ? 1 : 0);
