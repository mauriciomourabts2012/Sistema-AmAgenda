<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_auth/require_auth.php';
require_once __DIR__ . '/../../_auth/csrf.php';
require_once __DIR__ . '/../../_regras/permissoes_usuario.php';
require_once __DIR__ . '/../../_gateways/mercado_pago.php';
require_once __DIR__ . '/../../_servicos/assinatura.php';

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

function pagamentoOnlineGatewayAtivo(): MercadoPagoGateway
{
    try {
        return MercadoPagoGateway::daConfiguracao();
    } catch (RuntimeException $e) {
        pagamentoOnlineFalhar('PIX_CONFIGURACAO', 'O pagamento PIX não está configurado.', 503);
    }
}

function pagamentoCartaoGatewayAtivo(): MercadoPagoGateway
{
    try {
        return MercadoPagoGateway::daConfiguracao();
    } catch (RuntimeException $e) {
        pagamentoOnlineFalhar('CARTAO_CONFIGURACAO', 'O cadastro de cartão não está configurado.', 503);
    }
}

function pagamentoOnlineStatus(string $externo): string
{
    return match ($externo) {
        'created', 'action_required' => 'aguardando',
        'processing', 'in_review' => 'processando',
        'processed' => 'confirmada',
        'expired' => 'expirada',
        'canceled' => 'cancelada',
        'failed' => 'recusada',
        default => 'conciliacao',
    };
}

function pagamentoOnlineCampo(mixed $valor, int $maximo): ?string
{
    if (!is_string($valor) && !is_int($valor)) return null;
    $texto = (string)$valor;
    if ($texto === '' || strlen($texto) > $maximo
        || !preg_match('/^[a-zA-Z0-9_.-]+$/D', $texto)) return null;
    return $texto;
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
    $statusPagamento = pagamentoOnlineCampo($payment['status'] ?? null, 50);
    $detalhePagamento = pagamentoOnlineCampo($payment['status_detail'] ?? null, 100);
    $valorValido = false;
    $valorPagamentoValido = false;
    if (is_string($valor)) {
        try {
            $valorValido = pagamentoGatewayValorCentavos($valor)
                === pagamentoGatewayValorCentavos((string)$transacao['valor']);
        } catch (InvalidArgumentException $e) {
            $valorValido = false;
        }
    }
    if (is_string($payment['amount'] ?? null)) {
        try {
            $valorPagamentoValido = pagamentoGatewayValorCentavos($payment['amount'])
                === pagamentoGatewayValorCentavos((string)$transacao['valor']);
        } catch (InvalidArgumentException $e) {
            $valorPagamentoValido = false;
        }
    }
    if ($orderId === null || $referencia !== $transacao['referencia_interna']
        || !$valorValido || !$valorPagamentoValido
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
    $confirmacaoValida = $statusNovo !== 'confirmada'
        || ($detalheExterno === 'accredited' && $paymentId !== null
            && $statusPagamento === 'processed' && $detalhePagamento === 'accredited');
    if (!$confirmacaoValida) {
        pagamentoOnlineMarcarConciliacao($db, $transacao, 'confirmacao_order_divergente');
        pagamentoOnlineFalhar('PIX_CONCILIACAO', 'Este PIX precisa de verificação antes de uma nova tentativa.', 409);
    }
    if ($statusNovo === 'conciliacao') {
        pagamentoOnlineMarcarConciliacao($db, $transacao, 'status_order_desconhecido');
        pagamentoOnlineFalhar('PIX_CONCILIACAO', 'Este PIX precisa de verificação antes de uma nova tentativa.', 409);
    }
    $expiraEm = pagamentoOnlineExpiracao($order, $payment);

    if (!$db->begin_transaction()) throw new RuntimeException('Falha ao iniciar atualização de pagamento.');
    try {
        $atual = pagamentoOnlineConsulta($db,
            'SELECT id_transacao,id_empresa,id_cobranca,id_pagamento,valor,status,status_externo,expira_em,referencia_interna,ordem_externa_id,pagamento_externo_id FROM transacao_pagamento WHERE id_transacao=? AND id_empresa=? FOR UPDATE',
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
        // Aprovação externa não é baixa financeira: 'confirmada' só existe com o pagamento interno
        // vinculado (webhook ou conciliação). Até lá, a transação permanece em processamento.
        if ($statusNovo === 'confirmada' && $atual['status'] !== 'confirmada' && $atual['id_pagamento'] === null) {
            $statusNovo = 'processando';
        }
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

function pagamentoOnlineIniciar(mysqli $db, MercadoPagoGateway $gateway, int $empresa, int $cobranca, string $email, string $perfil = ''): array
{
    $ambiente = $gateway->ambiente();
    if (!$db->begin_transaction()) throw new RuntimeException('Falha ao iniciar pagamento.');
    try {
        $c = pagamentoOnlineConsulta($db,
            "SELECT c.id_assinatura,c.valor,c.status,c.finalidade,c.periodo_inicio,p.gera_cobranca,
                    a.modalidade,a.teste_iniciado_em,a.teste_expira_em,
                    a.status AS assinatura_status,a.motivo_suspensao,a.data_fim,
                    (a.teste_expira_em IS NOT NULL AND CURRENT_TIMESTAMP >= a.teste_expira_em) AS teste_expirado
               FROM cobranca c
               JOIN assinatura a
                 ON a.id_assinatura=c.id_assinatura
                 AND a.id_empresa=c.id_empresa
               JOIN plano p
                 ON p.id_plano=a.id_plano
              WHERE c.id_cobranca=? AND c.id_empresa=?
              FOR UPDATE", 'ii', $cobranca, $empresa)->fetch_assoc();
        if (!$c) pagamentoOnlineFalhar('COBRANCA_NAO_ENCONTRADA', 'Cobrança não encontrada.', 404);
        if ($c['data_fim'] !== null && $c['periodo_inicio'] !== null
            && (string)$c['periodo_inicio'] > (string)$c['data_fim']) {
            pagamentoOnlineFalhar('COBRANCA_APOS_ENCERRAMENTO', 'Esta cobrança requer revisão antes do pagamento.', 409);
        }
        if ($c['modalidade'] === 'teste') {
            if ($c['teste_iniciado_em'] === null || $c['teste_expira_em'] === null) {
                pagamentoOnlineFalhar('ASSINATURA_TRIAL_INCONSISTENTE', 'Os dados do período de teste da assinatura precisam de verificação.', 409);
            }
            // Única exceção em assinatura de teste: cobrança de conversão de trial expirado, paga pelo Proprietário
            // (perfil vindo do vínculo autenticado) enquanto a assinatura está suspensa por expiração do teste.
            $regularizacaoTrial = $perfil === 'proprietario'
                && $c['finalidade'] === 'conversao_trial'
                && $c['assinatura_status'] === 'suspensa'
                && $c['motivo_suspensao'] === 'teste_expirado'
                && (int)$c['teste_expirado'] === 1;
            if (!$regularizacaoTrial) {
                pagamentoOnlineFalhar('ASSINATURA_TRIAL_SEM_COBRANCA', 'Não é possível iniciar pagamento durante o período de teste.', 409);
            }
        } elseif ($c['modalidade'] !== 'paga') {
            pagamentoOnlineFalhar('ASSINATURA_MODALIDADE_INVALIDA', 'A modalidade da assinatura precisa de verificação.', 409);
        }
        if ($c['status'] === 'cancelada') pagamentoOnlineFalhar('COBRANCA_CANCELADA', 'Esta cobrança está cancelada.', 409);
        if ($c['status'] === 'paga') pagamentoOnlineFalhar('COBRANCA_PAGA', 'Esta cobrança já está paga.', 409);
        // Competência governada por assinatura recorrente (Preapproval): o PIX poderia duplicar a cobrança externa.
        $governada = assinaturaServicoPreapprovalBloqueioPix($db, $empresa, $cobranca);
        if ($governada !== null) pagamentoOnlineFalhar($governada['codigo'], $governada['mensagem'], 409);
        $pago = pagamentoOnlineConsulta($db,
            "SELECT COALESCE(SUM(valor_pago),0) AS total FROM pagamento WHERE id_cobranca=? AND id_empresa=? AND status='confirmado'",
            'ii', $cobranca, $empresa)->fetch_assoc();
        $saldo = pagamentoGatewayValorCentavos((string)$c['valor']) - pagamentoGatewayValorCentavos((string)($pago['total'] ?? '0'));
        if ($saldo <= 0) pagamentoOnlineFalhar('COBRANCA_PAGA', 'Esta cobrança já está integralmente paga.', 409);
        $valor = pagamentoGatewayValorDecimal($saldo);

        $ativas = pagamentoOnlineConsulta($db,
            "SELECT id_transacao,id_empresa,id_cobranca,valor,metodo,ambiente,status,chave_idempotencia,referencia_interna,ordem_externa_id,pagamento_externo_id,expira_em FROM transacao_pagamento WHERE id_empresa=? AND id_cobranca=? AND ambiente=? AND status IN ('criada','enviando','aguardando','processando','confirmada','conciliacao') ORDER BY id_transacao DESC FOR UPDATE",
            'iis', $empresa, $cobranca, $ambiente)->fetch_all(MYSQLI_ASSOC);
        if (count($ativas) > 1) pagamentoOnlineFalhar('PIX_CONCILIACAO', 'Existe mais de uma transação pendente de verificação.', 409);
        $reutilizada = (bool)$ativas;
        if ($ativas) {
            $transacao = $ativas[0];
            if ($transacao['metodo'] !== 'pix' || $transacao['ambiente'] !== $ambiente
                || pagamentoGatewayValorCentavos((string)$transacao['valor']) !== $saldo
                || in_array($transacao['status'], ['confirmada', 'conciliacao'], true)) {
                pagamentoOnlineFalhar('PIX_CONCILIACAO', 'Existe uma transação financeira que precisa de verificação.', 409);
            }
        } else {
            if ((int)($c['gera_cobranca'] ?? 0) !== 1) {
                pagamentoOnlineFalhar('PLANO_SEM_COBRANCA', 'O plano atual não permite gerar um novo pagamento.', 409);
            }
            $chave = pagamentoGatewayChaveIdempotencia();
            $referencia = pagamentoGatewayReferenciaInterna();
            pagamentoOnlineExec($db,
                "INSERT INTO transacao_pagamento (id_empresa,id_cobranca,provedor,ambiente,metodo,tipo_operacao,valor,moeda,status,chave_idempotencia,referencia_interna) VALUES (?,?,'mercado_pago',?,'pix','pix',?,'BRL','criada',?,?)",
                'iissss', $empresa, $cobranca, $ambiente, $valor, $chave, $referencia);
            $id = (int)$db->insert_id;
            $transacao = ['id_transacao'=>$id,'id_empresa'=>$empresa,'id_cobranca'=>$cobranca,'valor'=>$valor,
                'metodo'=>'pix','ambiente'=>$ambiente,'status'=>'criada','chave_idempotencia'=>$chave,
                'referencia_interna'=>$referencia,'ordem_externa_id'=>null,'pagamento_externo_id'=>null,'expira_em'=>null];
        }
        if ($transacao['ordem_externa_id'] === null && (int)($c['gera_cobranca'] ?? 0) !== 1) {
            pagamentoOnlineFalhar('PLANO_SEM_COBRANCA', 'O plano atual não permite gerar um novo pagamento.', 409);
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
    if (!assinaturaServicoPlanoGeraCobranca($db, (int)$c['id_assinatura'])) {
        pagamentoOnlineExec($db,
            "UPDATE transacao_pagamento SET status='erro',erro_codigo='PLAN_NO_BILLING' WHERE id_transacao=? AND id_empresa=? AND ordem_externa_id IS NULL AND status IN ('criada','enviando')",
            'ii', (int)$transacao['id_transacao'], (int)$transacao['id_empresa']);
        pagamentoOnlineFalhar('PLANO_SEM_COBRANCA', 'O plano atual não permite gerar um novo pagamento.', 409);
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
    if (!assinaturaServicoPlanoGeraCobranca($db, (int)$c['id_assinatura'])) {
        pagamentoOnlineExec($db,
            "UPDATE transacao_pagamento SET status='erro',erro_codigo='PLAN_NO_BILLING' WHERE id_transacao=? AND id_empresa=? AND ordem_externa_id IS NULL AND status IN ('criada','enviando')",
            'ii', (int)$transacao['id_transacao'], (int)$transacao['id_empresa']);
        pagamentoOnlineFalhar('PLANO_SEM_COBRANCA', 'O plano atual não permite gerar um novo pagamento.', 409);
    }
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
    $ambiente = $gateway->ambiente();
    $transacao = pagamentoOnlineConsulta($db,
        "SELECT id_transacao,id_empresa,id_cobranca,valor,status,status_externo,expira_em,referencia_interna,ordem_externa_id,pagamento_externo_id FROM transacao_pagamento WHERE id_transacao=? AND id_empresa=? AND metodo='pix' AND ambiente=? LIMIT 1",
        'iis', $id, $empresa, $ambiente)->fetch_assoc();
    if (!$transacao) pagamentoOnlineFalhar('PIX_NAO_ENCONTRADO', 'Transação PIX não encontrada.', 404);
    if ($transacao['ordem_externa_id'] === null) {
        return pagamentoOnlineDadosPublicos([], $transacao);
    }
    return pagamentoOnlineConsultarGateway($db, $gateway, $transacao);
}

/**
 * Inicia a regularização do trial expirado: cria ou reutiliza a única cobrança de conversão.
 * O valor, a periodicidade e o vencimento vêm exclusivamente da assinatura persistida.
 */
function pagamentoOnlineRegularizar(mysqli $db, int $empresa, string $perfil, bool $modoRegularizacao): array
{
    if ($perfil !== 'proprietario') {
        pagamentoOnlineFalhar('REGULARIZACAO_SOMENTE_PROPRIETARIO', 'Somente o Proprietário pode iniciar a regularização.', 403);
    }
    if (!$modoRegularizacao) {
        pagamentoOnlineFalhar('REGULARIZACAO_INDISPONIVEL', 'A assinatura não está em modo de regularização.', 409);
    }
    if (!$db->begin_transaction()) throw new RuntimeException('Falha ao iniciar regularização.');
    try {
        try {
            $cobranca = assinaturaServicoObterOuCriarCobrancaConversao($db, $empresa);
        } catch (AssinaturaServicoConversaoErro $e) {
            pagamentoOnlineFalhar($e->getMessage(), $e->mensagemUsuario, $e->httpStatus);
        }
        if ($cobranca['criada']) {
            $depois = static fn (mixed $valor): array => ['antes' => null, 'depois' => $valor];
            auditoriaRegistrar($db, 'cobranca.gerada', [
                'entidade_id' => (int)$cobranca['id_cobranca'],
                'entidade_rotulo' => 'Cobrança de conversão da assinatura #' . (int)$cobranca['id_assinatura'],
                'descricao' => 'Gerou a cobrança de conversão do período de teste da assinatura #' . (int)$cobranca['id_assinatura'] . '.',
                'alteracoes' => [
                    'id_empresa' => $depois($empresa),
                    'id_assinatura' => $depois((int)$cobranca['id_assinatura']),
                    'periodo_inicio' => $depois((string)$cobranca['periodo_inicio']),
                    'periodo_fim' => $depois((string)$cobranca['periodo_fim']),
                    'data_vencimento' => $depois((string)$cobranca['data_vencimento']),
                    'valor' => $depois((string)$cobranca['valor']),
                    'status' => $depois((string)$cobranca['status']),
                    'finalidade' => $depois('conversao_trial'),
                ],
                'contexto' => ['origem' => 'regularizacao_trial', 'origem_geracao' => 'proprietario'],
            ]);
        }
        if (!$db->commit()) throw new RuntimeException('Falha ao confirmar regularização.');
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }

    return [
        'id_cobranca' => (int)$cobranca['id_cobranca'],
        'id_assinatura' => (int)$cobranca['id_assinatura'],
        'finalidade' => 'conversao_trial',
        'periodo_inicio' => (string)$cobranca['periodo_inicio'],
        'periodo_fim' => (string)$cobranca['periodo_fim'],
        'data_vencimento' => (string)$cobranca['data_vencimento'],
        'valor' => (string)$cobranca['valor'],
        'status' => (string)$cobranca['status'],
        'total_pago_confirmado' => (string)$cobranca['total_pago_confirmado'],
        'saldo_restante' => (string)$cobranca['saldo_restante'],
        'reutilizada' => !$cobranca['criada'],
    ];
}

function pagamentoCartaoAssinaturaAtiva(mysqli $db, int $empresa, bool $bloquear = false): ?array
{
    $sufixo = $bloquear ? ' FOR UPDATE' : '';
    $linhas = pagamentoOnlineConsulta($db,
        "SELECT a.id_assinatura,a.valor_contratado,a.status,p.gera_cobranca
           FROM assinatura a
           INNER JOIN plano p
                   ON p.id_plano = a.id_plano
          WHERE a.id_empresa=?
            AND a.status IN ('ativa','suspensa')
          ORDER BY a.id_assinatura DESC{$sufixo}",
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

function pagamentoCartaoVinculoAtual(mysqli $db, int $empresa, string $ambiente, bool $bloquear = false): ?array
{
    $sufixo = $bloquear ? ' FOR UPDATE' : '';
    return pagamentoOnlineConsulta($db,
        "SELECT id_assinatura_pagamento,id_empresa,id_assinatura,ambiente,motor_recorrencia,assinatura_externa_id,referencia_externa_assinatura,cliente_externo_id,perfil_externo_id,meio_pagamento_externo_id,cartao_externo_id,bandeira,final_cartao,status,status_externo,chave_idempotencia_criacao,cobrar_a_partir_de,slot_ativo,TIMESTAMPDIFF(SECOND,ultima_sincronizacao_em,NOW(6)) AS idade_sincronia FROM assinatura_pagamento_recorrente WHERE id_empresa=? AND provedor='mercado_pago' AND ambiente=? ORDER BY (slot_ativo=1) DESC,id_assinatura_pagamento DESC LIMIT 1{$sufixo}",
        'is', $empresa, $ambiente)->fetch_assoc() ?: null;
}

function pagamentoCartaoDadosPublicos(?array $vinculo, ?array $assinatura, ?string $chavePublica = null): array
{
    $status = $vinculo['status'] ?? 'nao_configurado';
    return [
        'configurado' => $status === 'ativa' && (int)($vinculo['slot_ativo'] ?? 0) === 1,
        'pode_configurar' => $assinatura !== null
            && (int)($assinatura['gera_cobranca'] ?? 0) === 1
            && !in_array($status, ['pendente', 'ativa', 'suspensa', 'atualizacao_necessaria', 'cancelamento_pendente'], true),
        'status' => $status,
        'status_externo' => $vinculo['status_externo'] ?? null,
        'bandeira' => $vinculo['bandeira'] ?? null,
        'final_cartao' => $vinculo['final_cartao'] ?? null,
        'cobrar_a_partir_de' => $vinculo['cobrar_a_partir_de'] ?? null,
        'valor_referencia' => $assinatura['valor_contratado'] ?? null,
        'public_key' => $chavePublica,
    ];
}

function pagamentoCartaoConfiguracao(mysqli $db, MercadoPagoGateway $gateway, int $empresa): array
{
    $vinculo = pagamentoCartaoVinculoAtual($db, $empresa, $gateway->ambiente());
    $assinatura = pagamentoCartaoAssinaturaAtiva($db, $empresa);
    if (pagamentoCartaoMotor($vinculo, $assinatura, $gateway::subscriptionsHabilitado()) === 'preapproval') {
        return pagamentoCartaoDadosPreapproval($vinculo, $assinatura, $gateway::subscriptionsHabilitado());
    }
    try {
        $chave = MercadoPagoGateway::chavePublicaAtiva();
    } catch (RuntimeException $e) {
        pagamentoOnlineFalhar('CARTAO_CONFIGURACAO', 'O cadastro de cartão não está configurado.', 503);
    }
    return pagamentoCartaoDadosPublicos(
        $vinculo,
        $assinatura,
        $chave
    ) + ['motor_recorrencia' => 'orders'];
}

/**
 * A decisão do fluxo é do servidor, nunca do navegador. Vínculo do próprio contrato mantém seu motor (sem migração
 * automática de cartões), assim como vínculo legado ainda vigente de outro contrato; sem vínculo vigente, a chave
 * mestra decide (desligada, nada de Preapproval é criado).
 */
function pagamentoCartaoMotor(?array $vinculo, ?array $assinatura, bool $habilitado): string
{
    if ($vinculo !== null) {
        $doContrato = $assinatura !== null && (int)$vinculo['id_assinatura'] === (int)$assinatura['id_assinatura'];
        $encerrado = in_array((string)($vinculo['status'] ?? ''), ['cancelada', 'encerrada'], true);
        if ($doContrato || !$encerrado) {
            return (string)($vinculo['motor_recorrencia'] ?? 'orders');
        }
    }

    return $habilitado ? 'preapproval' : 'orders';
}

function pagamentoCartaoDadosPreapproval(?array $vinculo, ?array $assinatura, bool $habilitado): array
{
    $atual = $vinculo !== null && $assinatura !== null && (int)$vinculo['id_assinatura'] === (int)$assinatura['id_assinatura'];
    $v = $atual ? $vinculo : null;
    $status = $v['status'] ?? 'nao_configurado';
    $novo = $vinculo === null || (!$atual && in_array((string)$vinculo['status'], ['cancelada', 'encerrada'], true));
    $criada = $v !== null && $v['assinatura_externa_id'] !== null;
    // Autorização retomável: (a) já criada e ainda pendente no provedor (o serviço devolve o mesmo link, sem novo POST);
    // (b) vínculo pendente ou em erro SEM Preapproval criada (tentativa anterior falhou ou ficou incerta): o serviço
    // concilia pela mesma referência e só recria quando está provado que nada foi criado. Estados externos
    // authorized/paused/canceled nunca oferecem autorização.
    $retomavel = $atual && (($status === 'pendente' && (!$criada || ($v['status_externo'] ?? '') === 'pending'))
        || ($status === 'erro' && !$criada));
    return [
        'motor_recorrencia' => 'preapproval', 'subscriptions_habilitado' => $habilitado,
        'autorizacao_criada' => $criada,
        'configurado' => $atual && $status === 'ativa' && ($v['status_externo'] ?? '') === 'authorized' && (int)($v['slot_ativo'] ?? 0) === 1,
        'pode_configurar' => $habilitado && $assinatura !== null && $assinatura['status'] === 'ativa'
            && (int)($assinatura['gera_cobranca'] ?? 0) === 1 && ($novo || $retomavel),
        'pode_verificar' => $habilitado && $criada,
        'status' => $status, 'status_externo' => $v['status_externo'] ?? null,
        'valor_referencia' => $assinatura['valor_contratado'] ?? null,
        'cobrar_a_partir_de' => $v['cobrar_a_partir_de'] ?? null,
    ];
}

function pagamentoCartaoPreapprovalIniciar(mysqli $db, object $gateway, int $empresa, string $email): array
{
    $r = assinaturaServicoPreapprovalIniciar($db, $gateway, $empresa, $email);
    if (($r['ok'] ?? false) !== true) {
        pagamentoOnlineFalhar((string)$r['codigo'], (string)$r['mensagem'], (int)($r['http_status'] ?? 409));
    }
    $statusExterno = (string)($r['status_externo'] ?? '');
    if ($statusExterno !== '' && $statusExterno !== 'pending') {
        // O provedor já saiu do estado pendente (autorizada, pausada ou cancelada): persiste o estado real e não oferece link.
        $dados = pagamentoCartaoPreapprovalConsultar($db, $gateway, $empresa, $email, true);
        $dados['init_point'] = null;

        return $dados;
    }
    $dados = pagamentoCartaoDadosPreapproval(pagamentoCartaoVinculoAtual($db, $empresa, $gateway->ambiente()), pagamentoCartaoAssinaturaAtiva($db, $empresa), $gateway::subscriptionsHabilitado());
    // Só o link retornado pelo serviço e validado novamente pode sair ao navegador.
    $dados['init_point'] = ($r['status_externo'] ?? '') === 'pending'
        ? assinaturaServicoPreapprovalInitPoint(['init_point' => $r['init_point'] ?? null]) : null;
    if (($r['status_externo'] ?? '') === 'pending' && $dados['init_point'] === null) {
        pagamentoOnlineFalhar('AUTORIZACAO_LINK_INDISPONIVEL', 'O link de autorização está indisponível. Atualize a situação antes de continuar.', 503);
    }
    return $dados;
}

/** O retorno só sincroniza a autorização. Faturas/pagamentos permanecem no webhook e na conciliação financeira. */
function pagamentoCartaoPreapprovalConsultar(mysqli $db, object $gateway, int $empresa, string $email, bool $forcar = false): array
{
    if (!$gateway::subscriptionsHabilitado()) {
        pagamentoOnlineFalhar('SUBSCRIPTIONS_DESABILITADO', 'A verificação do pagamento recorrente está indisponível.', 503);
    }
    $v = pagamentoCartaoVinculoAtual($db, $empresa, $gateway->ambiente());
    $contratoAtual = pagamentoCartaoAssinaturaAtiva($db, $empresa);
    if ($v === null || pagamentoCartaoMotor($v, $contratoAtual, true) !== 'preapproval'
        || $contratoAtual === null || (int)$v['id_assinatura'] !== (int)$contratoAtual['id_assinatura']) {
        pagamentoOnlineFalhar('VINCULO_INDISPONIVEL', 'Não há autorização recorrente para verificar.', 409);
    }
    // Retorno duplicado ou atualização repetida: sem nova consulta ao provedor logo após uma sincronização.
    if (!$forcar && $v['assinatura_externa_id'] !== null && $v['idade_sincronia'] !== null && (int)$v['idade_sincronia'] >= 0 && (int)$v['idade_sincronia'] < 10) {
        return pagamentoCartaoDadosPreapproval($v, $contratoAtual, true);
    }
    if ($v['assinatura_externa_id'] === null) {
        $r = assinaturaServicoPreapprovalConciliar($db, $gateway, $empresa, $email);
        if (($r['ok'] ?? false) !== true) {
            pagamentoOnlineFalhar((string)$r['codigo'], (string)$r['mensagem'], (int)($r['http_status'] ?? 409));
        }
        $v = pagamentoCartaoVinculoAtual($db, $empresa, $gateway->ambiente());
    }
    if ($v === null || (int)$v['id_empresa'] !== $empresa || $v['assinatura_externa_id'] === null) {
        pagamentoOnlineFalhar('VINCULO_INDISPONIVEL', 'A autorização ainda precisa de verificação.', 409);
    }
    if (!defined('MP_WEBHOOK_BIBLIOTECA')) define('MP_WEBHOOK_BIBLIOTECA', true);
    if (MP_WEBHOOK_BIBLIOTECA !== true) throw new RuntimeException('Conciliação indisponível.');
    require_once __DIR__ . '/../../webhooks/webhook_mercado_pago.php';
    $r = mpSubSincronizarPreapproval($db, $gateway, $gateway->ambiente(), (string)$v['assinatura_externa_id'], null, $empresa);
    if ($r['resultado'] !== 'processado') {
        pagamentoOnlineFalhar('AUTORIZACAO_VERIFICACAO_PENDENTE', 'Não foi possível confirmar a autorização. Atualize a situação novamente mais tarde.', 503);
    }
    return pagamentoCartaoDadosPreapproval(pagamentoCartaoVinculoAtual($db, $empresa, $gateway->ambiente()), pagamentoCartaoAssinaturaAtiva($db, $empresa), true);
}

function pagamentoCartaoReservar(mysqli $db, int $empresa, string $ambiente): array
{
    if (!$db->begin_transaction()) throw new RuntimeException('Falha ao iniciar autorização do cartão.');
    try {
        $assinatura = pagamentoCartaoAssinaturaAtiva($db, $empresa, true);
        if (!$assinatura) pagamentoOnlineFalhar('ASSINATURA_INATIVA', 'Não há assinatura ativa para configurar o pagamento recorrente.', 409);
        if ((int)($assinatura['gera_cobranca'] ?? 0) !== 1) {
            pagamentoOnlineFalhar('PLANO_SEM_COBRANCA', 'O plano atual não permite configurar pagamento recorrente.', 409);
        }
        $preapproval = pagamentoOnlineConsulta($db,
            "SELECT id_assinatura_pagamento FROM assinatura_pagamento_recorrente
              WHERE id_empresa=? AND id_assinatura=? AND motor_recorrencia='preapproval' LIMIT 1 FOR UPDATE",
            'ii', $empresa, (int)$assinatura['id_assinatura'])->fetch_assoc();
        if ($preapproval) pagamentoOnlineFalhar('MOTOR_PREAPPROVAL_EXISTENTE', 'Este contrato já utiliza autorização recorrente pelo Mercado Pago.', 409);
        $bloqueios = pagamentoOnlineConsulta($db,
            "SELECT id_assinatura_pagamento,status FROM assinatura_pagamento_recorrente WHERE id_empresa=? AND provedor='mercado_pago' AND ambiente=? AND status IN ('pendente','ativa','suspensa','atualizacao_necessaria','cancelamento_pendente') ORDER BY id_assinatura_pagamento DESC FOR UPDATE",
            'is', $empresa, $ambiente)->fetch_all(MYSQLI_ASSOC);
        if ($bloqueios) {
            $mensagem = $bloqueios[0]['status'] === 'ativa'
                ? 'Já existe um cartão ativo para o pagamento automático.'
                : 'Já existe uma configuração de cartão em processamento ou que exige atenção.';
            pagamentoOnlineFalhar('CARTAO_VINCULO_EXISTENTE', $mensagem, 409);
        }
        $clienteAnterior = pagamentoOnlineConsulta($db,
            "SELECT cliente_externo_id FROM assinatura_pagamento_recorrente WHERE id_empresa=? AND provedor='mercado_pago' AND ambiente=? AND cliente_externo_id IS NOT NULL ORDER BY id_assinatura_pagamento DESC LIMIT 1",
            'is', $empresa, $ambiente)->fetch_assoc();
        $chave = pagamentoGatewayChaveIdempotencia();
        $cliente = $clienteAnterior['cliente_externo_id'] ?? null;
        pagamentoOnlineExec($db,
            "INSERT INTO assinatura_pagamento_recorrente (id_empresa,id_assinatura,provedor,ambiente,cliente_externo_id,status,chave_idempotencia_criacao,slot_ativo) VALUES (?,?,'mercado_pago',?,?,'pendente',?,NULL)",
            'iisss', $empresa, (int)$assinatura['id_assinatura'], $ambiente, $cliente, $chave);
        $id = (int)$db->insert_id;
        if (!$db->commit()) throw new RuntimeException('Falha ao confirmar autorização do cartão.');
        return ['id_assinatura_pagamento'=>$id,'id_empresa'=>$empresa,'id_assinatura'=>(int)$assinatura['id_assinatura'],'ambiente'=>$ambiente,
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

    if (!assinaturaServicoPlanoGeraCobranca($db, (int)$vinculo['id_assinatura'])) {
        pagamentoCartaoMarcarErro($db, $vinculo, 'PLAN_NO_BILLING');
        pagamentoOnlineFalhar('PLANO_SEM_COBRANCA', 'O plano atual não permite configurar pagamento recorrente.', 409);
    }
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
        if (!assinaturaServicoPlanoGeraCobranca($db, (int)$vinculo['id_assinatura'])) {
            pagamentoCartaoMarcarErro($db, $vinculo, 'PLAN_NO_BILLING');
            pagamentoOnlineFalhar('PLANO_SEM_COBRANCA', 'O plano atual não permite configurar pagamento recorrente.', 409);
        }
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
    // Os 4 últimos dígitos (last_four_digits) servem somente para exibição: são gravados quando
    // vierem no formato válido, mas não condicionam a ativação. A ativação continua exigindo perfil
    // e método READY e os identificadores do meio de pagamento e do cartão.
    $ativo = $statusPerfil === 'READY' && $statusMetodo === 'READY' && $meioId !== null && $cartaoId !== null;
    $rejeitado = $statusPerfil === 'CANCELLED' || in_array($statusMetodo, ['REJECTED','DISABLED'], true);
    $statusLocal = $ativo ? 'ativa' : ($rejeitado ? 'erro' : 'pendente');
    // Diagnóstico sem dado sensível, na coluna existente status_externo (somente quando não ativo;
    // o vínculo ativo mantém 'READY', exigido pelo processador recorrente). Códigos com no máximo
    // 18 caracteres, o mesmo limite já usado por pagamentoCartaoMarcarErro ('CUSTOMER_RECOVERED').
    $statusExternoLocal = $statusPerfil;
    if (!$ativo && $statusPerfil === 'READY') {
        $statusExternoLocal = $statusMetodo !== 'READY'
            ? 'METHOD_' . $statusMetodo
            : 'PROFILE_INCOMPLETE';
    }

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
                'sssssssii', $perfilId, $meioId, $cartaoId, $bandeira, $final, $statusLocal, $statusExternoLocal,
                (int)$vinculo['id_assinatura_pagamento'], (int)$vinculo['id_empresa']);
        }
        if (!$db->commit()) throw new RuntimeException('Falha ao confirmar perfil de pagamento.');
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    $vinculoAtual = pagamentoCartaoVinculoAtual($db, (int)$vinculo['id_empresa'], (string)$vinculo['ambiente']);
    return pagamentoCartaoDadosPublicos($vinculoAtual, pagamentoCartaoAssinaturaAtiva($db, (int)$vinculo['id_empresa']));
}

function pagamentoCartaoAutorizar(mysqli $db, MercadoPagoGateway $gateway, int $empresa, string $email, string $token, string $bandeira): array
{
    if (!preg_match('/^[A-Za-z0-9_-]{32,33}$/D', $token)) pagamentoOnlineFalhar('CARTAO_TOKEN_INVALIDO', 'O token do cartão é inválido ou expirou.', 422);
    $bandeira = strtolower($bandeira);
    if (!preg_match('/^[a-z0-9_]{2,30}$/D', $bandeira)) pagamentoOnlineFalhar('CARTAO_BANDEIRA_INVALIDA', 'Não foi possível identificar a bandeira do cartão.', 422);
    $vinculo = pagamentoCartaoReservar($db, $empresa, $gateway->ambiente());
    if (!assinaturaServicoPlanoGeraCobranca($db, (int)$vinculo['id_assinatura'])) {
        pagamentoCartaoMarcarErro($db, $vinculo, 'PLAN_NO_BILLING');
        pagamentoOnlineFalhar('PLANO_SEM_COBRANCA', 'O plano atual não permite configurar pagamento recorrente.', 409);
    }
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
    if (!assinaturaServicoPlanoGeraCobranca($db, (int)$vinculo['id_assinatura'])) {
        pagamentoCartaoMarcarErro($db, $vinculo, 'PLAN_NO_BILLING');
        pagamentoOnlineFalhar('PLANO_SEM_COBRANCA', 'O plano atual não permite configurar pagamento recorrente.', 409);
    }
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
    $ambiente = $gateway->ambiente();
    $vinculo = pagamentoCartaoVinculoAtual($db, $empresa, $ambiente);
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
            return pagamentoCartaoDadosPublicos(pagamentoCartaoVinculoAtual($db, $empresa, $ambiente), $assinatura);
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
        $dados = pagamentoOnlineIniciar($conexao, pagamentoOnlineGatewayAtivo(), $empresa, $id, $email, (string)($contexto['perfil'] ?? ''));
        out(['ok'=>true,'code'=>$dados['reutilizado'] ? 'PIX_REUTILIZADO' : 'PIX_CRIADO',
            'user_msg'=>$dados['reutilizado'] ? 'PIX existente reutilizado com segurança.' : 'PIX criado com sucesso.',
            'data'=>$dados]);
    }
    if ($rota === 'painel/faturamento/regularizacao/cobranca' && $metodo === 'POST') {
        csrfValidarSessao();
        $dados = pagamentoOnlineRegularizar(
            $conexao,
            $empresa,
            (string)($contexto['perfil'] ?? ''),
            (bool)($_SESSION['auth']['modo_regularizacao'] ?? false)
        );
        out(['ok'=>true,'code'=>$dados['reutilizada'] ? 'COBRANCA_CONVERSAO_REUTILIZADA' : 'COBRANCA_CONVERSAO_CRIADA',
            'user_msg'=>$dados['reutilizada'] ? 'Cobrança de regularização já existente.' : 'Cobrança de regularização gerada com sucesso.',
            'data'=>$dados], $dados['reutilizada'] ? 200 : 201);
    }
    if ($rota === 'painel/faturamento/pagamento/transacao' && $metodo === 'GET') {
        exigirPermissao($conexao, 'faturamento.visualizar', $contexto);
        $id = pagamentoOnlineId($_GET['id_transacao'] ?? null);
        $dados = pagamentoOnlineConsultar($conexao, pagamentoOnlineGatewayAtivo(), $empresa, $id);
        out(['ok'=>true,'code'=>'PIX_CONSULTADO','user_msg'=>'Situação do PIX consultada.','data'=>$dados]);
    }
    if ($rota === 'painel/faturamento/pagamento/cartao/configuracao' && $metodo === 'GET') {
        exigirPermissao($conexao, 'faturamento.pagar', $contexto);
        out(['ok'=>true,'code'=>'CARTAO_CONFIGURACAO','user_msg'=>'Configuração do cartão consultada.',
            'data'=>pagamentoCartaoConfiguracao($conexao, pagamentoCartaoGatewayAtivo(), $empresa)]);
    }
    if ($rota === 'painel/faturamento/pagamento/cartao/autorizar' && $metodo === 'POST') {
        exigirPermissao($conexao, 'faturamento.pagar', $contexto);
        csrfValidarSessao();
        $email = trim((string)($_SESSION['auth']['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            pagamentoOnlineFalhar('EMAIL_INDISPONIVEL', 'Seu e-mail de acesso precisa ser válido para configurar o cartão.', 422);
        }
        $gatewayCartao = pagamentoCartaoGatewayAtivo();
        $assinaturaCartao = pagamentoCartaoAssinaturaAtiva($conexao, $empresa);
        $vinculoCartao = pagamentoCartaoVinculoAtual($conexao, $empresa, $gatewayCartao->ambiente());
        if (pagamentoCartaoMotor($vinculoCartao, $assinaturaCartao, $gatewayCartao::subscriptionsHabilitado()) === 'preapproval') {
            // Fluxo por redirecionamento: nenhum dado de cartão trafega por aqui; valor, plano e contrato vêm do servidor.
            unset($_POST['token'], $_POST['payment_method_id']);
            $dados = pagamentoCartaoPreapprovalIniciar($conexao, $gatewayCartao, $empresa, $email);
            out(['ok'=>true,
                'code'=>$dados['init_point'] !== null ? 'AUTORIZACAO_REDIRECIONAMENTO' : 'AUTORIZACAO_EXISTENTE',
                'user_msg'=>$dados['init_point'] !== null
                    ? 'Você será levado ao Mercado Pago para autorizar o cartão. Nenhuma cobrança será feita agora.'
                    : 'A autorização recorrente já existe. Atualize a situação para conferir.',
                'data'=>$dados]);
        }
        $tokenTemporario = trim((string)($_POST['token'] ?? ''));
        unset($_POST['token']);
        try {
            $dados = pagamentoCartaoAutorizar($conexao, pagamentoCartaoGatewayAtivo(), $empresa, $email,
                $tokenTemporario, trim((string)($_POST['payment_method_id'] ?? '')));
        } finally {
            if (function_exists('sodium_memzero')) sodium_memzero($tokenTemporario); else $tokenTemporario = '';
        }
        out(['ok'=>true,'code'=>$dados['configurado'] ? 'CARTAO_AUTORIZADO' : 'CARTAO_PROCESSANDO',
            'user_msg'=>$dados['configurado'] ? 'Cartão configurado para pagamentos automáticos.' : 'Cartão recebido e em validação pelo Mercado Pago.',
            'data'=>$dados]);
    }
    if ($rota === 'painel/faturamento/pagamento/cartao/status' && ($metodo === 'GET' || $metodo === 'POST')) {
        exigirPermissao($conexao, 'faturamento.pagar', $contexto);
        $email = trim((string)($_SESSION['auth']['email'] ?? ''));
        $gatewayCartao = pagamentoCartaoGatewayAtivo();
        $assinaturaCartao = pagamentoCartaoAssinaturaAtiva($conexao, $empresa);
        $vinculoCartao = pagamentoCartaoVinculoAtual($conexao, $empresa, $gatewayCartao->ambiente());
        $motorCartao = pagamentoCartaoMotor($vinculoCartao, $assinaturaCartao, $gatewayCartao::subscriptionsHabilitado());
        if ($metodo === 'POST') {
            // Única operação que grava a partir do retorno do Mercado Pago: sincroniza a autorização consultando o
            // provedor. O retorno em si nunca é prova de autorização, e nenhum pagamento é registrado aqui.
            csrfValidarSessao();
            if ($motorCartao !== 'preapproval') {
                pagamentoOnlineFalhar('METODO_INVALIDO', 'Operação inválida para este contrato.', 405);
            }
            $dados = pagamentoCartaoPreapprovalConsultar($conexao, $gatewayCartao, $empresa, $email);
            $mensagem = $dados['configurado'] ? 'Autorização confirmada pelo Mercado Pago.'
                : (($dados['status_externo'] ?? '') === 'pending' ? 'A autorização ainda não foi concluída no Mercado Pago.'
                : 'Situação da autorização atualizada.');
            out(['ok'=>true,'code'=>$dados['configurado'] ? 'AUTORIZACAO_CONFIRMADA' : 'AUTORIZACAO_VERIFICADA','user_msg'=>$mensagem,'data'=>$dados]);
        }
        if ($motorCartao === 'preapproval') {
            out(['ok'=>true,'code'=>'CARTAO_STATUS','user_msg'=>'Situação do pagamento automático.',
                'data'=>pagamentoCartaoDadosPreapproval($vinculoCartao, $assinaturaCartao, $gatewayCartao::subscriptionsHabilitado())]);
        }
        out(['ok'=>true,'code'=>'CARTAO_STATUS','user_msg'=>'Situação do cartão atualizada.',
            'data'=>pagamentoCartaoConsultarStatus($conexao, $gatewayCartao, $empresa, $email)]);
    }
    pagamentoOnlineFalhar('ROTA_INVALIDA', 'Operação de pagamento inválida.', 404);
} catch (PagamentoOnlineErro $e) {
    out(['ok'=>false,'code'=>$e->getMessage(),'user_msg'=>$e->mensagemUsuario], $e->httpStatus);
} catch (Throwable $e) {
    error_log('PAGAMENTO_ONLINE_ERROR: ' . get_class($e));
    $codigoInterno = isset($rota) && str_contains($rota, '/cartao/') ? 'CARTAO_ERRO_INTERNO' : 'PIX_ERRO_INTERNO';
    out(['ok'=>false,'code'=>$codigoInterno,'user_msg'=>'Pagamento temporariamente indisponível.'], 500);
}
