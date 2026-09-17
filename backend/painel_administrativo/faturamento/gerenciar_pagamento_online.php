<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_auth/require_auth.php';
require_once __DIR__ . '/../../_auth/csrf.php';
require_once __DIR__ . '/../../_regras/permissoes_usuario.php';
require_once __DIR__ . '/../../_gateways/mercado_pago.php';

final class PagamentoOnlineErro extends RuntimeException
{
    public function __construct(string $codigo, public readonly string $mensagemUsuario, public readonly int $httpStatus)
    {
        parent::__construct($codigo);
    }
}

function pagamentoOnlineFalhar(string $codigo, string $mensagem, int $httpStatus): never
{
    throw new PagamentoOnlineErro($codigo, $mensagem, $httpStatus);
}

function pagamentoOnlineId(mixed $valor): int
{
    if (!is_scalar($valor) || !preg_match('/^[1-9][0-9]*$/D', (string)$valor)
        || filter_var((string)$valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
        pagamentoOnlineFalhar('ID_INVALIDO', 'Registro de pagamento inválido.', 422);
    }
    return (int)$valor;
}

function pagamentoOnlineConsulta(mysqli $db, string $sql, string $tipos = '', mixed ...$valores): mysqli_result
{
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException('Falha ao preparar consulta de pagamento.');
    if ($tipos !== '') $stmt->bind_param($tipos, ...$valores);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Falha ao executar consulta de pagamento.');
    }
    $resultado = $stmt->get_result();
    $stmt->close();
    if (!$resultado) throw new RuntimeException('Resposta de consulta indisponível.');
    return $resultado;
}

function pagamentoOnlineExec(mysqli $db, string $sql, string $tipos, mixed ...$valores): int
{
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException('Falha ao preparar atualização de pagamento.');
    $stmt->bind_param($tipos, ...$valores);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Falha ao atualizar pagamento.');
    }
    $afetadas = $stmt->affected_rows;
    $stmt->close();
    return $afetadas;
}

function pagamentoOnlineGatewayTeste(): MercadoPagoGateway
{
    if (getenv('MERCADO_PAGO_AMBIENTE') !== 'teste') {
        pagamentoOnlineFalhar('PIX_CONFIGURACAO', 'O pagamento PIX de teste não está configurado.', 503);
    }
    try {
        return MercadoPagoGateway::daConfiguracao();
    } catch (RuntimeException $e) {
        pagamentoOnlineFalhar('PIX_CONFIGURACAO', 'O pagamento PIX de teste não está configurado.', 503);
    }
}

function pagamentoCartaoGatewayTeste(): MercadoPagoGateway
{
    if (getenv('MERCADO_PAGO_AMBIENTE') !== 'teste') {
        pagamentoOnlineFalhar('CARTAO_CONFIGURACAO', 'O cadastro de cartão de teste não está configurado.', 503);
    }
    try {
        return MercadoPagoGateway::daConfiguracao();
    } catch (RuntimeException $e) {
        pagamentoOnlineFalhar('CARTAO_CONFIGURACAO', 'O cadastro de cartão de teste não está configurado.', 503);
    }
}

function pagamentoOnlineStatus(string $externo): string
{
    return match ($externo) {
        'created', 'action_required' => 'aguardando',
        'processing' => 'processando',
        'processed' => 'confirmada',
        'expired' => 'expirada',
        'canceled' => 'cancelada',
        'failed' => 'recusada',
        default => 'conciliacao',
    };
}

function pagamentoOnlineCampo(?string $valor, int $maximo): ?string
{
    if ($valor === null || $valor === '' || strlen($valor) > $maximo
        || !preg_match('/^[a-zA-Z0-9_.-]+$/D', $valor)) return null;
    return $valor;
}

function pagamentoOnlineExpiracao(array $order, array $payment): ?string
{
    $valor = $payment['expiration_time'] ?? $order['expiration_time'] ?? null;
    if (!is_string($valor) || $valor === '' || strlen($valor) > 50) return null;
    try {
        return (new DateTimeImmutable($valor))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

function pagamentoOnlineDadosPublicos(array $order, array $transacao): array
{
    $payment = $order['transactions']['payments'][0] ?? [];
    $payment = is_array($payment) ? $payment : [];
    $metodo = is_array($payment['payment_method'] ?? null) ? $payment['payment_method'] : [];
    $qr = $metodo['qr_code'] ?? null;
    $base64 = $metodo['qr_code_base64'] ?? null;
    $url = $metodo['ticket_url'] ?? null;
    if (!is_string($qr) || strlen($qr) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $qr)) $qr = null;
    if (!is_string($base64) || strlen($base64) > 1500000 || !preg_match('/^[A-Za-z0-9+\/=]+$/D', $base64)) $base64 = null;
    $host = is_string($url) ? strtolower((string)parse_url($url, PHP_URL_HOST)) : '';
    if (!is_string($url) || strlen($url) > 2048 || parse_url($url, PHP_URL_SCHEME) !== 'https'
        || !preg_match('/(^|\.)mercadopago\.com\.br$/D', $host)) $url = null;

    return [
        'id_transacao' => (int)$transacao['id_transacao'],
        'id_cobranca' => (int)$transacao['id_cobranca'],
        'valor' => (string)$transacao['valor'],
        'status' => (string)$transacao['status'],
        'status_externo' => $transacao['status_externo'],
        'expira_em' => $transacao['expira_em'],
        'qr_code' => $qr,
        'qr_code_base64' => $base64,
        'ticket_url' => $url,
    ];
}

function pagamentoOnlineMarcarConciliacao(mysqli $db, array $transacao, string $codigo): void
{
    pagamentoOnlineExec($db,
        "UPDATE transacao_pagamento SET status=IF(status='confirmada',status,'conciliacao'),requer_conciliacao=1,motivo_conciliacao='Resposta do provedor divergente da transação local.',erro_codigo=? WHERE id_transacao=? AND id_empresa=?",
        'sii', $codigo, (int)$transacao['id_transacao'], (int)$transacao['id_empresa']);
}

function pagamentoOnlineAplicarOrder(mysqli $db, array $transacao, array $order): array
{
    $orderId = pagamentoOnlineCampo($order['id'] ?? null, 150);
    $referencia = $order['external_reference'] ?? null;
    $valor = $order['total_amount'] ?? null;
    $payment = $order['transactions']['payments'][0] ?? [];
    $payment = is_array($payment) ? $payment : [];
    $paymentId = pagamentoOnlineCampo($payment['id'] ?? null, 150);
    $valorValido = false;
    if (is_string($valor)) {
        try {
            $valorValido = pagamentoGatewayValorCentavos($valor)
                === pagamentoGatewayValorCentavos((string)$transacao['valor']);
        } catch (InvalidArgumentException $e) {
            $valorValido = false;
        }
    }
    if ($orderId === null || $referencia !== $transacao['referencia_interna']
        || !$valorValido
        || (($transacao['ordem_externa_id'] ?? null) !== null && $transacao['ordem_externa_id'] !== $orderId)
        || (($transacao['pagamento_externo_id'] ?? null) !== null && $paymentId !== null
            && $transacao['pagamento_externo_id'] !== $paymentId)
        || !is_array($payment['payment_method'] ?? null)
        || ($payment['payment_method']['id'] ?? null) !== 'pix'
        || ($payment['payment_method']['type'] ?? null) !== 'bank_transfer') {
        pagamentoOnlineMarcarConciliacao($db, $transacao, 'resposta_order_divergente');
        pagamentoOnlineFalhar('PIX_RESPOSTA_INVALIDA', 'Não foi possível validar o PIX. Tente consultar novamente.', 502);
    }
    $statusExterno = pagamentoOnlineCampo($order['status'] ?? null, 50);
    $detalheExterno = pagamentoOnlineCampo($order['status_detail'] ?? null, 100);
    if ($statusExterno === null) {
        pagamentoOnlineMarcarConciliacao($db, $transacao, 'status_order_invalido');
        pagamentoOnlineFalhar('PIX_RESPOSTA_INVALIDA', 'Não foi possível validar o PIX. Tente consultar novamente.', 502);
    }
    $statusNovo = pagamentoOnlineStatus($statusExterno);
    if ($statusNovo === 'conciliacao') {
        pagamentoOnlineMarcarConciliacao($db, $transacao, 'status_order_desconhecido');
        pagamentoOnlineFalhar('PIX_CONCILIACAO', 'Este PIX precisa de verificação antes de uma nova tentativa.', 409);
    }
    $expiraEm = pagamentoOnlineExpiracao($order, $payment);

    if (!$db->begin_transaction()) throw new RuntimeException('Falha ao iniciar atualização de pagamento.');
    try {
        $atual = pagamentoOnlineConsulta($db,
            'SELECT id_transacao,id_empresa,id_cobranca,valor,status,status_externo,expira_em,referencia_interna,ordem_externa_id,pagamento_externo_id FROM transacao_pagamento WHERE id_transacao=? AND id_empresa=? FOR UPDATE',
            'ii', (int)$transacao['id_transacao'], (int)$transacao['id_empresa'])->fetch_assoc();
        if (!$atual) {
            pagamentoOnlineFalhar('PIX_CONCILIACAO', 'Este PIX precisa de verificação antes de uma nova tentativa.', 409);
        }
        if ($atual['ordem_externa_id'] !== null && $atual['ordem_externa_id'] !== $orderId) {
            pagamentoOnlineMarcarConciliacao($db, $atual, 'ordem_externa_divergente');
            if (!$db->commit()) throw new RuntimeException('Falha ao confirmar conciliação de pagamento.');
            pagamentoOnlineFalhar('PIX_CONCILIACAO', 'Este PIX precisa de verificação antes de uma nova tentativa.', 409);
        }
        if ($atual['pagamento_externo_id'] !== null && $paymentId !== null && $atual['pagamento_externo_id'] !== $paymentId) {
            pagamentoOnlineMarcarConciliacao($db, $atual, 'pagamento_externo_divergente');
            if (!$db->commit()) throw new RuntimeException('Falha ao confirmar conciliação de pagamento.');
            pagamentoOnlineFalhar('PIX_CONCILIACAO', 'Este PIX precisa de verificação antes de uma nova tentativa.', 409);
        }
        $terminal = ['confirmada', 'expirada', 'cancelada', 'recusada', 'conciliacao'];
        if (in_array((string)$atual['status'], $terminal, true) && $statusNovo !== 'confirmada') $statusNovo = (string)$atual['status'];
        if ($atual['status'] === 'processando' && $statusNovo === 'aguardando') $statusNovo = 'processando';
        $expiraEm = $expiraEm ?? $atual['expira_em'];
        $paymentId = $paymentId ?? $atual['pagamento_externo_id'];
        pagamentoOnlineExec($db,
            'UPDATE transacao_pagamento SET ordem_externa_id=?,pagamento_externo_id=?,status=?,status_externo=?,detalhe_status_externo=?,expira_em=?,ultima_consulta_em=NOW(),erro_codigo=NULL WHERE id_transacao=? AND id_empresa=?',
            'ssssssii', $orderId, $paymentId, $statusNovo, $statusExterno, $detalheExterno, $expiraEm,
            (int)$transacao['id_transacao'], (int)$transacao['id_empresa']);
        if (!$db->commit()) throw new RuntimeException('Falha ao confirmar atualização de pagamento.');
        $atual['status'] = $statusNovo;
        $atual['status_externo'] = $statusExterno;
        $atual['expira_em'] = $expiraEm;
        return pagamentoOnlineDadosPublicos($order, $atual);
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function pagamentoOnlineConsultarGateway(mysqli $db, MercadoPagoGateway $gateway, array $transacao): array
{
    $resposta = $gateway->requisitar('consultar_order', ['order_id' => (string)$transacao['ordem_externa_id']]);
    if (!($resposta['sucesso'] ?? false)) {
        pagamentoOnlineRegistrarErro($db, $transacao, (string)($resposta['codigo'] ?? 'falha_consulta'));
        pagamentoOnlineFalhar('PIX_CONSULTA_INDISPONIVEL', 'Não foi possível atualizar o PIX agora. Tente novamente.', 503);
    }
    return pagamentoOnlineAplicarOrder($db, $transacao, $resposta['dados']);
}

function pagamentoOnlineRegistrarErro(mysqli $db, array $transacao, string $codigo): void
{
    $codigo = pagamentoOnlineCampo($codigo, 100) ?? 'falha_gateway';
    pagamentoOnlineExec($db,
        'UPDATE transacao_pagamento SET erro_codigo=? WHERE id_transacao=? AND id_empresa=?',
        'sii', $codigo, (int)$transacao['id_transacao'], (int)$transacao['id_empresa']);
}

function pagamentoOnlineIniciar(mysqli $db, MercadoPagoGateway $gateway, int $empresa, int $cobranca, string $email): array
{
    if (!$db->begin_transaction()) throw new RuntimeException('Falha ao iniciar pagamento.');
    try {
        $c = pagamentoOnlineConsulta($db,
            'SELECT valor,status FROM cobranca WHERE id_cobranca=? AND id_empresa=? FOR UPDATE', 'ii', $cobranca, $empresa)->fetch_assoc();
        if (!$c) pagamentoOnlineFalhar('COBRANCA_NAO_ENCONTRADA', 'Cobrança não encontrada.', 404);
        if ($c['status'] === 'cancelada') pagamentoOnlineFalhar('COBRANCA_CANCELADA', 'Esta cobrança está cancelada.', 409);
        if ($c['status'] === 'paga') pagamentoOnlineFalhar('COBRANCA_PAGA', 'Esta cobrança já está paga.', 409);
        $pago = pagamentoOnlineConsulta($db,
            "SELECT COALESCE(SUM(valor_pago),0) AS total FROM pagamento WHERE id_cobranca=? AND id_empresa=? AND status='confirmado'",
            'ii', $cobranca, $empresa)->fetch_assoc();
        $saldo = pagamentoGatewayValorCentavos((string)$c['valor']) - pagamentoGatewayValorCentavos((string)($pago['total'] ?? '0'));
        if ($saldo <= 0) pagamentoOnlineFalhar('COBRANCA_PAGA', 'Esta cobrança já está integralmente paga.', 409);
        $valor = pagamentoGatewayValorDecimal($saldo);

        $ativas = pagamentoOnlineConsulta($db,
            "SELECT id_transacao,id_empresa,id_cobranca,valor,metodo,ambiente,status,chave_idempotencia,referencia_interna,ordem_externa_id,pagamento_externo_id,expira_em FROM transacao_pagamento WHERE id_empresa=? AND id_cobranca=? AND status IN ('criada','enviando','aguardando','processando','confirmada','conciliacao') ORDER BY id_transacao DESC FOR UPDATE",
            'ii', $empresa, $cobranca)->fetch_all(MYSQLI_ASSOC);
        if (count($ativas) > 1) pagamentoOnlineFalhar('PIX_CONCILIACAO', 'Existe mais de uma transação pendente de verificação.', 409);
        $reutilizada = (bool)$ativas;
        if ($ativas) {
            $transacao = $ativas[0];
            if ($transacao['metodo'] !== 'pix' || $transacao['ambiente'] !== 'teste'
                || pagamentoGatewayValorCentavos((string)$transacao['valor']) !== $saldo
                || in_array($transacao['status'], ['confirmada', 'conciliacao'], true)) {
                pagamentoOnlineFalhar('PIX_CONCILIACAO', 'Existe uma transação financeira que precisa de verificação.', 409);
            }
        } else {
            $chave = pagamentoGatewayChaveIdempotencia();
            $referencia = pagamentoGatewayReferenciaInterna();
            pagamentoOnlineExec($db,
                "INSERT INTO transacao_pagamento (id_empresa,id_cobranca,provedor,ambiente,metodo,tipo_operacao,valor,moeda,status,chave_idempotencia,referencia_interna) VALUES (?,?,'mercado_pago','teste','pix','pix',?,'BRL','criada',?,?)",
                'iisss', $empresa, $cobranca, $valor, $chave, $referencia);
            $id = (int)$db->insert_id;
            $transacao = ['id_transacao'=>$id,'id_empresa'=>$empresa,'id_cobranca'=>$cobranca,'valor'=>$valor,
                'metodo'=>'pix','ambiente'=>'teste','status'=>'criada','chave_idempotencia'=>$chave,
                'referencia_interna'=>$referencia,'ordem_externa_id'=>null,'pagamento_externo_id'=>null,'expira_em'=>null];
        }
        if (!$db->commit()) throw new RuntimeException('Falha ao confirmar transação local.');
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }

    if ($transacao['ordem_externa_id'] !== null) {
        $dados = pagamentoOnlineConsultarGateway($db, $gateway, $transacao);
        $dados['reutilizado'] = true;
        return $dados;
    }
    pagamentoOnlineExec($db,
        "UPDATE transacao_pagamento SET status='enviando',numero_tentativa=numero_tentativa+?,erro_codigo=NULL WHERE id_transacao=? AND id_empresa=? AND status IN ('criada','enviando')",
        'iii', $reutilizada ? 1 : 0, (int)$transacao['id_transacao'], (int)$transacao['id_empresa']);
    $payload = [
        'type' => 'online',
        'total_amount' => (string)$transacao['valor'],
        'external_reference' => (string)$transacao['referencia_interna'],
        'processing_mode' => 'automatic',
        'transactions' => ['payments' => [[
            'amount' => (string)$transacao['valor'],
            'payment_method' => ['id' => 'pix', 'type' => 'bank_transfer'],
        ]]],
        'payer' => ['email' => $email],
    ];
    $resposta = $gateway->requisitar('criar_order', [], $payload, (string)$transacao['chave_idempotencia']);
    if (!($resposta['sucesso'] ?? false)) {
        pagamentoOnlineRegistrarErro($db, $transacao, (string)($resposta['codigo'] ?? 'falha_criacao'));
        pagamentoOnlineFalhar('PIX_CRIACAO_INDISPONIVEL', 'Não foi possível gerar o PIX agora. Tente novamente.', 503);
    }
    $dados = pagamentoOnlineAplicarOrder($db, $transacao, $resposta['dados']);
    $dados['reutilizado'] = $reutilizada;
    return $dados;
}

function pagamentoOnlineConsultar(mysqli $db, MercadoPagoGateway $gateway, int $empresa, int $id): array
{
    $transacao = pagamentoOnlineConsulta($db,
        "SELECT id_transacao,id_empresa,id_cobranca,valor,status,status_externo,expira_em,referencia_interna,ordem_externa_id,pagamento_externo_id FROM transacao_pagamento WHERE id_transacao=? AND id_empresa=? AND metodo='pix' AND ambiente='teste' LIMIT 1",
        'ii', $id, $empresa)->fetch_assoc();
    if (!$transacao) pagamentoOnlineFalhar('PIX_NAO_ENCONTRADO', 'Transação PIX não encontrada.', 404);
    if ($transacao['ordem_externa_id'] === null) {
        return pagamentoOnlineDadosPublicos([], $transacao);
    }
    return pagamentoOnlineConsultarGateway($db, $gateway, $transacao);
}

function pagamentoCartaoAssinaturaAtiva(mysqli $db, int $empresa, bool $bloquear = false): ?array
{
    $sufixo = $bloquear ? ' FOR UPDATE' : '';
    $linhas = pagamentoOnlineConsulta($db,
        "SELECT id_assinatura,valor_contratado,status FROM assinatura WHERE id_empresa=? AND status='ativa' ORDER BY id_assinatura DESC{$sufixo}",
        'i', $empresa)->fetch_all(MYSQLI_ASSOC);
    if (count($linhas) > 1) pagamentoOnlineFalhar('ASSINATURA_INCONSISTENTE', 'Não foi possível determinar sua assinatura vigente.', 409);

    return $linhas[0] ?? null;
}

function pagamentoCartaoIdExterno(mixed $valor, int $maximo = 150): ?string
{
    if (!is_string($valor) && !is_int($valor)) return null;
    $texto = (string)$valor;
    if ($texto === '' || strlen($texto) > $maximo || !preg_match('/^[A-Za-z0-9_.-]+$/D', $texto)) return null;

    return $texto;
}

function pagamentoCartaoVinculoAtual(mysqli $db, int $empresa, bool $bloquear = false): ?array
{
    $sufixo = $bloquear ? ' FOR UPDATE' : '';
    return pagamentoOnlineConsulta($db,
        "SELECT id_assinatura_pagamento,id_empresa,id_assinatura,cliente_externo_id,perfil_externo_id,meio_pagamento_externo_id,cartao_externo_id,bandeira,final_cartao,status,status_externo,chave_idempotencia_criacao,cobrar_a_partir_de,slot_ativo FROM assinatura_pagamento_recorrente WHERE id_empresa=? AND provedor='mercado_pago' AND ambiente='teste' ORDER BY (slot_ativo=1) DESC,id_assinatura_pagamento DESC LIMIT 1{$sufixo}",
        'i', $empresa)->fetch_assoc() ?: null;
}

function pagamentoCartaoDadosPublicos(?array $vinculo, ?array $assinatura, ?string $chavePublica = null): array
{
    $status = $vinculo['status'] ?? 'nao_configurado';
    return [
        'configurado' => $status === 'ativa' && (int)($vinculo['slot_ativo'] ?? 0) === 1,
        'pode_configurar' => $assinatura !== null && !in_array($status, ['pendente', 'ativa', 'suspensa', 'atualizacao_necessaria', 'cancelamento_pendente'], true),
        'status' => $status,
        'status_externo' => $vinculo['status_externo'] ?? null,
        'bandeira' => $vinculo['bandeira'] ?? null,
        'final_cartao' => $vinculo['final_cartao'] ?? null,
        'cobrar_a_partir_de' => $vinculo['cobrar_a_partir_de'] ?? null,
        'valor_referencia' => $assinatura['valor_contratado'] ?? null,
        'public_key' => $chavePublica,
    ];
}

function pagamentoCartaoConfiguracao(mysqli $db, int $empresa): array
{
    try {
        $chave = MercadoPagoGateway::chavePublicaTeste();
    } catch (RuntimeException $e) {
        pagamentoOnlineFalhar('CARTAO_CONFIGURACAO', 'O cadastro de cartão de teste não está configurado.', 503);
    }
    return pagamentoCartaoDadosPublicos(
        pagamentoCartaoVinculoAtual($db, $empresa),
        pagamentoCartaoAssinaturaAtiva($db, $empresa),
        $chave
    );
}

function pagamentoCartaoReservar(mysqli $db, int $empresa): array
{
    if (!$db->begin_transaction()) throw new RuntimeException('Falha ao iniciar autorização do cartão.');
    try {
        $assinatura = pagamentoCartaoAssinaturaAtiva($db, $empresa, true);
        if (!$assinatura) pagamentoOnlineFalhar('ASSINATURA_INATIVA', 'É necessário possuir uma assinatura ativa para configurar o cartão.', 409);
        $bloqueios = pagamentoOnlineConsulta($db,
            "SELECT id_assinatura_pagamento,status FROM assinatura_pagamento_recorrente WHERE id_empresa=? AND provedor='mercado_pago' AND ambiente='teste' AND status IN ('pendente','ativa','suspensa','atualizacao_necessaria','cancelamento_pendente') ORDER BY id_assinatura_pagamento DESC FOR UPDATE",
            'i', $empresa)->fetch_all(MYSQLI_ASSOC);
        if ($bloqueios) {
            $mensagem = $bloqueios[0]['status'] === 'ativa'
                ? 'Já existe um cartão ativo para o pagamento automático.'
                : 'Já existe uma configuração de cartão em processamento ou que exige atenção.';
            pagamentoOnlineFalhar('CARTAO_VINCULO_EXISTENTE', $mensagem, 409);
        }
        $clienteAnterior = pagamentoOnlineConsulta($db,
            "SELECT cliente_externo_id FROM assinatura_pagamento_recorrente WHERE id_empresa=? AND provedor='mercado_pago' AND ambiente='teste' AND cliente_externo_id IS NOT NULL ORDER BY id_assinatura_pagamento DESC LIMIT 1",
            'i', $empresa)->fetch_assoc();
        $chave = pagamentoGatewayChaveIdempotencia();
        $cliente = $clienteAnterior['cliente_externo_id'] ?? null;
        pagamentoOnlineExec($db,
            "INSERT INTO assinatura_pagamento_recorrente (id_empresa,id_assinatura,provedor,ambiente,cliente_externo_id,status,chave_idempotencia_criacao,slot_ativo) VALUES (?,?,'mercado_pago','teste',?,'pendente',?,NULL)",
            'iiss', $empresa, (int)$assinatura['id_assinatura'], $cliente, $chave);
        $id = (int)$db->insert_id;
        if (!$db->commit()) throw new RuntimeException('Falha ao confirmar autorização do cartão.');
        return ['id_assinatura_pagamento'=>$id,'id_empresa'=>$empresa,'id_assinatura'=>(int)$assinatura['id_assinatura'],
            'cliente_externo_id'=>$cliente,'perfil_externo_id'=>null,'status'=>'pendente',
            'chave_idempotencia_criacao'=>$chave];
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function pagamentoCartaoMarcarErro(mysqli $db, array $vinculo, ?string $statusExterno = null): void
{
    pagamentoOnlineExec($db,
        "UPDATE assinatura_pagamento_recorrente SET status='erro',status_externo=?,slot_ativo=NULL,cobrar_a_partir_de=NULL WHERE id_assinatura_pagamento=? AND id_empresa=? AND status='pendente'",
        'sii', $statusExterno, (int)$vinculo['id_assinatura_pagamento'], (int)$vinculo['id_empresa']);
}

function pagamentoCartaoPersistirCliente(mysqli $db, array &$vinculo, string $cliente): void
{
    if (!$db->begin_transaction()) throw new RuntimeException('Falha ao iniciar vínculo do cliente de pagamento.');
    try {
        $atual = pagamentoOnlineConsulta($db,
            'SELECT cliente_externo_id,status FROM assinatura_pagamento_recorrente WHERE id_assinatura_pagamento=? AND id_empresa=? FOR UPDATE',
            'ii', (int)$vinculo['id_assinatura_pagamento'], (int)$vinculo['id_empresa'])->fetch_assoc();
        if (!$atual || $atual['status'] !== 'pendente') pagamentoOnlineFalhar('CARTAO_CONCORRENCIA', 'A configuração do cartão foi alterada em outra solicitação.', 409);
        if ($atual['cliente_externo_id'] !== null && $atual['cliente_externo_id'] !== $cliente) {
            pagamentoOnlineFalhar('CARTAO_CONCORRENCIA', 'O cliente de pagamento precisa de verificação.', 409);
        }
        pagamentoOnlineExec($db,
            'UPDATE assinatura_pagamento_recorrente SET cliente_externo_id=? WHERE id_assinatura_pagamento=? AND id_empresa=? AND cliente_externo_id IS NULL',
            'sii', $cliente, (int)$vinculo['id_assinatura_pagamento'], (int)$vinculo['id_empresa']);
        if (!$db->commit()) throw new RuntimeException('Falha ao confirmar cliente de pagamento.');
        $vinculo['cliente_externo_id'] = $cliente;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function pagamentoCartaoObterCliente(mysqli $db, MercadoPagoGateway $gateway, array &$vinculo, string $email): string
{
    $existente = pagamentoCartaoIdExterno($vinculo['cliente_externo_id'] ?? null);
    if ($existente !== null) return $existente;

    $busca = $gateway->requisitar('buscar_cliente', ['email'=>$email]);
    if (!($busca['sucesso'] ?? false)) {
        pagamentoOnlineFalhar('CARTAO_CLIENTE_INDISPONIVEL', 'Não foi possível localizar seu cadastro de pagamento agora.', 503);
    }
    $resultados = $busca['dados']['results'] ?? [];
    $correspondentes = [];
    if (is_array($resultados)) {
        foreach ($resultados as $item) {
            if (!is_array($item) || strcasecmp((string)($item['email'] ?? ''), $email) !== 0) continue;
            $id = pagamentoCartaoIdExterno($item['id'] ?? null);
            if ($id !== null) $correspondentes[$id] = $id;
        }
    }
    if (count($correspondentes) > 1) {
        pagamentoCartaoMarcarErro($db, $vinculo, 'CUSTOMER_CONFLICT');
        pagamentoOnlineFalhar('CARTAO_CLIENTE_CONFLITO', 'Seu cadastro de pagamento precisa de verificação antes de continuar.', 409);
    }
    $cliente = $correspondentes ? (string)reset($correspondentes) : null;
    if ($cliente === null) {
        $criacao = $gateway->requisitar('criar_cliente', [], ['email'=>$email]);
        if (!($criacao['sucesso'] ?? false)) {
            if (($criacao['codigo'] ?? '') === 'provedor_rejeitou') pagamentoCartaoMarcarErro($db, $vinculo, 'CUSTOMER_REJECTED');
            pagamentoOnlineFalhar('CARTAO_CLIENTE_INDISPONIVEL', 'Não foi possível preparar seu cadastro de pagamento agora.', 503);
        }
        $cliente = pagamentoCartaoIdExterno($criacao['dados']['id'] ?? null);
        if ($cliente === null || strcasecmp((string)($criacao['dados']['email'] ?? ''), $email) !== 0) {
            pagamentoCartaoMarcarErro($db, $vinculo, 'CUSTOMER_INVALID');
            pagamentoOnlineFalhar('CARTAO_CLIENTE_INVALIDO', 'Não foi possível validar seu cadastro de pagamento.', 502);
        }
    }
    pagamentoCartaoPersistirCliente($db, $vinculo, $cliente);

    return $cliente;
}

function pagamentoCartaoAplicarPerfil(mysqli $db, array $vinculo, array $perfil, ?string $bandeiraEsperada = null): array
{
    $perfilId = pagamentoCartaoIdExterno($perfil['id'] ?? null);
    $statusPerfil = strtoupper((string)($perfil['status'] ?? ''));
    $sequencia = strtoupper((string)($perfil['sequence_control'] ?? ''));
    $metodos = is_array($perfil['payment_methods'] ?? null) ? $perfil['payment_methods'] : [];
    $metodo = null;
    foreach ($metodos as $item) {
        if (!is_array($item) || ($item['type'] ?? null) !== 'credit_card') continue;
        if ($bandeiraEsperada !== null && strtolower((string)($item['id'] ?? '')) !== $bandeiraEsperada) continue;
        $metodo = $item;
        break;
    }
    $statusMetodo = strtoupper((string)($metodo['status'] ?? ''));
    $bandeira = $metodo ? strtolower((string)($metodo['id'] ?? '')) : '';
    $meioId = $metodo ? pagamentoCartaoIdExterno($metodo['payment_method_id'] ?? null) : null;
    $cartaoId = $metodo ? pagamentoCartaoIdExterno($metodo['card_id'] ?? null) : null;
    $final = $metodo['last_four_digits'] ?? null;
    if (is_int($final)) $final = str_pad((string)$final, 4, '0', STR_PAD_LEFT);
    $final = is_string($final) && preg_match('/^[0-9]{4}$/D', $final) ? $final : null;
    if ($perfilId === null || !in_array($statusPerfil, ['PENDING','READY','CANCELLED'], true)
        || $sequencia !== 'AUTO' || !$metodo || !preg_match('/^[a-z0-9_]{2,30}$/D', $bandeira)
        || !in_array($statusMetodo, ['PENDING','READY','REJECTED','DISABLED'], true)) {
        pagamentoCartaoMarcarErro($db, $vinculo, 'PROFILE_INVALID');
        pagamentoOnlineFalhar('CARTAO_PERFIL_INVALIDO', 'Não foi possível validar o cartão cadastrado.', 502);
    }
    if (($vinculo['perfil_externo_id'] ?? null) !== null && $vinculo['perfil_externo_id'] !== $perfilId) {
        pagamentoCartaoMarcarErro($db, $vinculo, 'PROFILE_CONFLICT');
        pagamentoOnlineFalhar('CARTAO_PERFIL_CONFLITO', 'O perfil de pagamento precisa de verificação.', 409);
    }
    $ativo = $statusPerfil === 'READY' && $statusMetodo === 'READY' && $meioId !== null && $cartaoId !== null && $final !== null;
    $rejeitado = $statusPerfil === 'CANCELLED' || in_array($statusMetodo, ['REJECTED','DISABLED'], true);
    $statusLocal = $ativo ? 'ativa' : ($rejeitado ? 'erro' : 'pendente');

    if (!$db->begin_transaction()) throw new RuntimeException('Falha ao iniciar atualização do perfil de pagamento.');
    try {
        $atual = pagamentoOnlineConsulta($db,
            'SELECT id_assinatura_pagamento,status,perfil_externo_id FROM assinatura_pagamento_recorrente WHERE id_assinatura_pagamento=? AND id_empresa=? FOR UPDATE',
            'ii', (int)$vinculo['id_assinatura_pagamento'], (int)$vinculo['id_empresa'])->fetch_assoc();
        if (!$atual || !in_array($atual['status'], ['pendente','ativa'], true)) {
            pagamentoOnlineFalhar('CARTAO_CONCORRENCIA', 'A configuração do cartão foi alterada em outra solicitação.', 409);
        }
        if ($atual['perfil_externo_id'] !== null && $atual['perfil_externo_id'] !== $perfilId) {
            pagamentoOnlineFalhar('CARTAO_PERFIL_CONFLITO', 'O perfil de pagamento precisa de verificação.', 409);
        }
        if ($ativo) {
            $outro = pagamentoOnlineConsulta($db,
                'SELECT id_assinatura_pagamento FROM assinatura_pagamento_recorrente WHERE id_empresa=? AND slot_ativo=1 AND id_assinatura_pagamento<>? FOR UPDATE',
                'ii', (int)$vinculo['id_empresa'], (int)$vinculo['id_assinatura_pagamento'])->fetch_assoc();
            if ($outro) pagamentoOnlineFalhar('CARTAO_SLOT_ATIVO', 'Já existe outro cartão ativo para esta empresa.', 409);
            pagamentoOnlineExec($db,
                "UPDATE assinatura_pagamento_recorrente SET perfil_externo_id=?,meio_pagamento_externo_id=?,cartao_externo_id=?,bandeira=?,final_cartao=?,status='ativa',status_externo=?,slot_ativo=1,cobrar_a_partir_de=COALESCE(cobrar_a_partir_de,CURDATE()) WHERE id_assinatura_pagamento=? AND id_empresa=?",
                'ssssssii', $perfilId, $meioId, $cartaoId, $bandeira, $final, $statusPerfil,
                (int)$vinculo['id_assinatura_pagamento'], (int)$vinculo['id_empresa']);
        } else {
            pagamentoOnlineExec($db,
                'UPDATE assinatura_pagamento_recorrente SET perfil_externo_id=?,meio_pagamento_externo_id=?,cartao_externo_id=?,bandeira=?,final_cartao=?,status=?,status_externo=?,slot_ativo=NULL,cobrar_a_partir_de=NULL WHERE id_assinatura_pagamento=? AND id_empresa=?',
                'sssssssii', $perfilId, $meioId, $cartaoId, $bandeira, $final, $statusLocal, $statusPerfil,
                (int)$vinculo['id_assinatura_pagamento'], (int)$vinculo['id_empresa']);
        }
        if (!$db->commit()) throw new RuntimeException('Falha ao confirmar perfil de pagamento.');
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    $vinculoAtual = pagamentoCartaoVinculoAtual($db, (int)$vinculo['id_empresa']);
    return pagamentoCartaoDadosPublicos($vinculoAtual, pagamentoCartaoAssinaturaAtiva($db, (int)$vinculo['id_empresa']));
}

function pagamentoCartaoAutorizar(mysqli $db, MercadoPagoGateway $gateway, int $empresa, string $email, string $token, string $bandeira): array
{
    if (!preg_match('/^[A-Za-z0-9_-]{32,33}$/D', $token)) pagamentoOnlineFalhar('CARTAO_TOKEN_INVALIDO', 'O token do cartão é inválido ou expirou.', 422);
    $bandeira = strtolower($bandeira);
    if (!preg_match('/^[a-z0-9_]{2,30}$/D', $bandeira)) pagamentoOnlineFalhar('CARTAO_BANDEIRA_INVALIDA', 'Não foi possível identificar a bandeira do cartão.', 422);
    $vinculo = pagamentoCartaoReservar($db, $empresa);
    $cliente = pagamentoCartaoObterCliente($db, $gateway, $vinculo, $email);
    $payload = [
        'description' => 'AmAgenda vinculo ' . (int)$vinculo['id_assinatura_pagamento'],
        'statement_descriptor' => 'AMAGENDA',
        'sequence_control' => 'AUTO',
        'payment_methods' => [[
            'id' => $bandeira,
            'type' => 'credit_card',
            'token' => $token,
            'default_method' => true,
        ]],
    ];
    $resposta = $gateway->requisitar('criar_perfil', ['customer_id'=>$cliente], $payload, (string)$vinculo['chave_idempotencia_criacao']);
    $payload['payment_methods'][0]['token'] = '';
    if (function_exists('sodium_memzero')) sodium_memzero($token); else $token = '';
    if (!($resposta['sucesso'] ?? false)) {
        if (($resposta['codigo'] ?? '') === 'provedor_rejeitou') {
            pagamentoCartaoMarcarErro($db, $vinculo, 'PROFILE_REJECTED');
            pagamentoOnlineFalhar('CARTAO_RECUSADO', 'O cartão não pôde ser validado. Confira os dados e tente novamente.', 422);
        }
        pagamentoOnlineFalhar('CARTAO_TEMPORARIAMENTE_INDISPONIVEL', 'Não foi possível concluir o cadastro do cartão agora. Consulte a situação antes de tentar novamente.', 503);
    }
    return pagamentoCartaoAplicarPerfil($db, $vinculo, $resposta['dados'], $bandeira);
}

function pagamentoCartaoConsultarStatus(mysqli $db, MercadoPagoGateway $gateway, int $empresa, string $email): array
{
    $assinatura = pagamentoCartaoAssinaturaAtiva($db, $empresa);
    $vinculo = pagamentoCartaoVinculoAtual($db, $empresa);
    if (!$vinculo) return pagamentoCartaoDadosPublicos(null, $assinatura);
    if ($vinculo['cliente_externo_id'] === null) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return pagamentoCartaoDadosPublicos($vinculo, $assinatura);
        $busca = $gateway->requisitar('buscar_cliente', ['email'=>$email]);
        if (!($busca['sucesso'] ?? false)) pagamentoOnlineFalhar('CARTAO_STATUS_INDISPONIVEL', 'Não foi possível atualizar a situação do cartão agora.', 503);
        $clientes = [];
        $resultados = $busca['dados']['results'] ?? [];
        if (is_array($resultados)) {
            foreach ($resultados as $item) {
                if (!is_array($item) || strcasecmp((string)($item['email'] ?? ''), $email) !== 0) continue;
                $idCliente = pagamentoCartaoIdExterno($item['id'] ?? null);
                if ($idCliente !== null) $clientes[$idCliente] = $idCliente;
            }
        }
        if (count($clientes) > 1) {
            pagamentoCartaoMarcarErro($db, $vinculo, 'CUSTOMER_CONFLICT');
            pagamentoOnlineFalhar('CARTAO_CLIENTE_CONFLITO', 'Seu cadastro de pagamento precisa de verificação antes de continuar.', 409);
        }
        if ($clientes) {
            pagamentoCartaoPersistirCliente($db, $vinculo, (string)reset($clientes));
            pagamentoCartaoMarcarErro($db, $vinculo, 'CUSTOMER_RECOVERED');
            return pagamentoCartaoDadosPublicos(pagamentoCartaoVinculoAtual($db, $empresa), $assinatura);
        }
        return pagamentoCartaoDadosPublicos($vinculo, $assinatura);
    }
    if ($vinculo['perfil_externo_id'] === null) {
        $lista = $gateway->requisitar('listar_perfis', ['customer_id'=>(string)$vinculo['cliente_externo_id']]);
        if (!($lista['sucesso'] ?? false)) pagamentoOnlineFalhar('CARTAO_STATUS_INDISPONIVEL', 'Não foi possível atualizar a situação do cartão agora.', 503);
        $descricao = 'AmAgenda vinculo ' . (int)$vinculo['id_assinatura_pagamento'];
        $encontrados = [];
        $perfis = $lista['dados']['data'] ?? [];
        if (is_array($perfis)) {
            foreach ($perfis as $perfil) {
                if (is_array($perfil) && ($perfil['description'] ?? null) === $descricao) $encontrados[] = $perfil;
            }
        }
        if (count($encontrados) > 1) {
            pagamentoCartaoMarcarErro($db, $vinculo, 'PROFILE_DUPLICATED');
            pagamentoOnlineFalhar('CARTAO_PERFIL_CONFLITO', 'O perfil de pagamento precisa de verificação.', 409);
        }
        if ($encontrados) return pagamentoCartaoAplicarPerfil($db, $vinculo, $encontrados[0]);
        return pagamentoCartaoDadosPublicos($vinculo, $assinatura);
    }
    $resposta = $gateway->requisitar('consultar_perfil', [
        'customer_id'=>(string)$vinculo['cliente_externo_id'],
        'profile_id'=>(string)$vinculo['perfil_externo_id'],
    ]);
    if (!($resposta['sucesso'] ?? false)) pagamentoOnlineFalhar('CARTAO_STATUS_INDISPONIVEL', 'Não foi possível atualizar a situação do cartão agora.', 503);
    return pagamentoCartaoAplicarPerfil($db, $vinculo, $resposta['dados']);
}

try {
    if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
        pagamentoOnlineFalhar('BANCO_INDISPONIVEL', 'Pagamento temporariamente indisponível.', 503);
    }
    $contexto = permissoesContexto($conexao);
    if (!($contexto['valido'] ?? false)) pagamentoOnlineFalhar('EMPRESA_INVALIDA', 'Não foi possível identificar sua empresa.', 403);
    $empresa = (int)$contexto['id_empresa'];
    $rota = trim((string)($_GET['path'] ?? ''), "/ \t\n\r\0\x0B");
    $metodo = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($rota === 'painel/faturamento/pagamento/pix/iniciar' && $metodo === 'POST') {
        exigirPermissao($conexao, 'faturamento.pagar', $contexto);
        csrfValidarSessao();
        $id = pagamentoOnlineId($_POST['id_cobranca'] ?? null);
        $email = trim((string)($_SESSION['auth']['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            pagamentoOnlineFalhar('EMAIL_INDISPONIVEL', 'Seu e-mail de acesso precisa ser válido para gerar o PIX.', 422);
        }
        $dados = pagamentoOnlineIniciar($conexao, pagamentoOnlineGatewayTeste(), $empresa, $id, $email);
        out(['ok'=>true,'code'=>$dados['reutilizado'] ? 'PIX_REUTILIZADO' : 'PIX_CRIADO',
            'user_msg'=>$dados['reutilizado'] ? 'PIX existente reutilizado com segurança.' : 'PIX criado com sucesso.',
            'data'=>$dados]);
    }
    if ($rota === 'painel/faturamento/pagamento/transacao' && $metodo === 'GET') {
        exigirPermissao($conexao, 'faturamento.visualizar', $contexto);
        $id = pagamentoOnlineId($_GET['id_transacao'] ?? null);
        $dados = pagamentoOnlineConsultar($conexao, pagamentoOnlineGatewayTeste(), $empresa, $id);
        out(['ok'=>true,'code'=>'PIX_CONSULTADO','user_msg'=>'Situação do PIX consultada.','data'=>$dados]);
    }
    if ($rota === 'painel/faturamento/pagamento/cartao/configuracao' && $metodo === 'GET') {
        exigirPermissao($conexao, 'faturamento.pagar', $contexto);
        out(['ok'=>true,'code'=>'CARTAO_CONFIGURACAO','user_msg'=>'Configuração do cartão consultada.',
            'data'=>pagamentoCartaoConfiguracao($conexao, $empresa)]);
    }
    if ($rota === 'painel/faturamento/pagamento/cartao/autorizar' && $metodo === 'POST') {
        exigirPermissao($conexao, 'faturamento.pagar', $contexto);
        csrfValidarSessao();
        $email = trim((string)($_SESSION['auth']['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            pagamentoOnlineFalhar('EMAIL_INDISPONIVEL', 'Seu e-mail de acesso precisa ser válido para configurar o cartão.', 422);
        }
        $tokenTemporario = trim((string)($_POST['token'] ?? ''));
        unset($_POST['token']);
        try {
            $dados = pagamentoCartaoAutorizar($conexao, pagamentoCartaoGatewayTeste(), $empresa, $email,
                $tokenTemporario, trim((string)($_POST['payment_method_id'] ?? '')));
        } finally {
            if (function_exists('sodium_memzero')) sodium_memzero($tokenTemporario); else $tokenTemporario = '';
        }
        out(['ok'=>true,'code'=>$dados['configurado'] ? 'CARTAO_AUTORIZADO' : 'CARTAO_PROCESSANDO',
            'user_msg'=>$dados['configurado'] ? 'Cartão configurado para pagamentos automáticos.' : 'Cartão recebido e em validação pelo Mercado Pago.',
            'data'=>$dados]);
    }
    if ($rota === 'painel/faturamento/pagamento/cartao/status' && $metodo === 'GET') {
        exigirPermissao($conexao, 'faturamento.pagar', $contexto);
        $email = trim((string)($_SESSION['auth']['email'] ?? ''));
        out(['ok'=>true,'code'=>'CARTAO_STATUS','user_msg'=>'Situação do cartão atualizada.',
            'data'=>pagamentoCartaoConsultarStatus($conexao, pagamentoCartaoGatewayTeste(), $empresa, $email)]);
    }
    pagamentoOnlineFalhar('ROTA_INVALIDA', 'Operação de pagamento inválida.', 404);
} catch (PagamentoOnlineErro $e) {
    out(['ok'=>false,'code'=>$e->getMessage(),'user_msg'=>$e->mensagemUsuario], $e->httpStatus);
} catch (Throwable $e) {
    error_log('PAGAMENTO_ONLINE_ERROR: ' . get_class($e));
    $codigoInterno = isset($rota) && str_contains($rota, '/cartao/') ? 'CARTAO_ERRO_INTERNO' : 'PIX_ERRO_INTERNO';
    out(['ok'=>false,'code'=>$codigoInterno,'user_msg'=>'Pagamento temporariamente indisponível.'], 500);
}
