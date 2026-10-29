<?php

declare(strict_types=1);

/*
 * Cancelar assinatura x pagamento automático (Preapproval do Mercado Pago).
 *
 * Regra: o cancelamento comercial NUNCA cancela a Preapproval. Enquanto houver recorrência externa ativa, pendente de
 * cancelamento ou incerta, o contrato não é cancelado; a recorrência é encerrada pelo botão próprio ("Cancelar pagamento
 * automático", pagamentoCartaoCancelarAutomatico) e só o `canceled` confirmado pelo Mercado Pago libera o cancelamento.
 *
 * Usado pelo painel do proprietário (gerenciar_faturamento.php) e pelo Super Admin (gerenciar_assinatura.php).
 * A decisão (cancelamentoRecorrenciaVerificarBloqueio) não acessa banco nem rede: usa "portas" injetadas, o que permite
 * testá-la com simulações (tests/cancelamento_recorrencia_test.php).
 */

const CANCELAMENTO_RECORRENCIA_MSG_PRIMEIRO = 'Para cancelar sua assinatura, primeiro cancele o pagamento automático do cartão.';
const CANCELAMENTO_RECORRENCIA_MSG_AGUARDANDO = 'O cancelamento do pagamento automático ainda aguarda a confirmação do Mercado Pago. Assim que for confirmado, você poderá cancelar a assinatura.';
const CANCELAMENTO_RECORRENCIA_MSG_OK_ADMIN = 'Pagamento automático cancelado com sucesso. A assinatura continua ativa.';
const CANCELAMENTO_RECORRENCIA_MSG_PENDENTE_ADMIN = 'O cancelamento foi solicitado. Aguarde a confirmação do Mercado Pago.';
const CANCELAMENTO_RECORRENCIA_MSG_FALHA_ADMIN = 'Não foi possível cancelar o pagamento automático. Atualize a situação ou tente novamente.';
const CANCELAMENTO_RECORRENCIA_MSG_INCERTA = 'O pagamento automático ainda está sendo confirmado com o Mercado Pago. Tente novamente em instantes.';

/** Nome da trava por empresa, compartilhada com a troca de cartão e com "Cancelar pagamento automático". */
function cancelamentoRecorrenciaNomeTrava(int $empresa): string
{
    return 'amagenda_troca_cartao_' . $empresa;
}

/**
 * Cobrança da Preapproval ainda em curso no provedor (usada pelo botão "Cancelar pagamento automático"). Distingue:
 * - realmente pendente (criada, enviando, aguardando, conciliação automática, processando sem erro): bloqueia;
 * - recusa em reciclagem (`processando` com detalhe `retry`, ou com erro): nunca bloqueia;
 * - pendência de conciliação manual (`requer_conciliacao`): só bloqueia com $incluirConciliacaoManual = true.
 */
function cancelamentoRecorrenciaParcelaEmAndamento(mysqli $db, int $empresa, int $idAssinaturaPagamento, bool $incluirConciliacaoManual): bool
{
    $manual = $incluirConciliacaoManual ? " OR (requer_conciliacao=1 AND status<>'confirmada')" : '';

    return assinaturaServicoConsultaFinanceira($db,
        "SELECT 1 AS aberta FROM transacao_pagamento
          WHERE id_empresa=? AND id_assinatura_pagamento=? AND tipo_operacao='assinatura_preapproval'
            AND (status IN ('criada','enviando','aguardando','conciliacao')
                 OR (status='processando' AND erro_codigo IS NULL AND COALESCE(detalhe_status_externo,'')<>'retry'){$manual}) LIMIT 1",
        'ii', $empresa, $idAssinaturaPagamento) !== [];
}

/**
 * Decide se o cancelamento da assinatura está liberado. Liberado: sem vínculo Preapproval, vínculo já encerrado, vínculo
 * sem Preapproval externa e sem operação em aberto, ou `erro` cujo estado externo já é cancelado. Bloqueado: qualquer outro
 * vínculo com Preapproval (pendente, ativa, atualização necessária, suspensa, erro não confirmado, cancelamento pendente) e
 * criação incerta (operação aberta sem id externo).
 *
 * Portas (callables):
 *  vinculo(): ?array             vínculo Preapproval do contrato (status, status_externo, assinatura_externa_id)
 *  operacao_aberta(array): bool  há operação aberta no outbox (criação incerta)
 *  confirmar(array): void        GET autoritativo opcional para 'cancelamento_pendente'; encerra o vínculo se o provedor já
 *                                mostra `canceled`. Nunca envia cancelamento.
 *
 * @param array<string, callable> $p
 * @return array{ok: bool, recorrencia: bool, code?: string, msg?: string, http?: int}
 */
function cancelamentoRecorrenciaVerificarBloqueio(array $p): array
{
    $bloqueio = static fn(string $code, string $msg): array => ['ok' => false, 'recorrencia' => true, 'code' => $code, 'msg' => $msg, 'http' => 409];
    $livre = static function (?array $v): bool {
        if ($v === null) return true;
        $status = (string)$v['status'];

        return in_array($status, ['cancelada', 'encerrada'], true)
            || ($status === 'erro' && $v['assinatura_externa_id'] !== null && in_array((string)($v['status_externo'] ?? ''), ['canceled', 'cancelled'], true));
    };

    $vinculo = $p['vinculo']();
    if ($livre($vinculo)) {
        return ['ok' => true, 'recorrencia' => $vinculo !== null && $vinculo['assinatura_externa_id'] !== null];
    }
    if ($vinculo['assinatura_externa_id'] === null) {
        // Sem Preapproval conhecida; só uma criação incerta (operação aberta) pode ter chegado ao provedor.
        return $p['operacao_aberta']($vinculo)
            ? $bloqueio('CANCELAMENTO_RECORRENCIA_INCERTA', CANCELAMENTO_RECORRENCIA_MSG_INCERTA)
            : ['ok' => true, 'recorrencia' => false];
    }
    if ((string)$vinculo['status'] === 'cancelamento_pendente') {
        // Já solicitado pelo botão próprio: tenta só confirmar (GET); sem `canceled` lido do provedor, segue bloqueado.
        try {
            $p['confirmar']($vinculo);
        } catch (Throwable $erro) {
            error_log('CANCELAMENTO_RECORRENCIA_CONFIRMAR: ' . get_class($erro));
        }
        if ($livre($p['vinculo']())) {
            return ['ok' => true, 'recorrencia' => true];
        }

        return $bloqueio('CANCELAMENTO_RECORRENCIA_AGUARDANDO', CANCELAMENTO_RECORRENCIA_MSG_AGUARDANDO);
    }

    return $bloqueio('CANCELAR_PAGAMENTO_AUTOMATICO_PRIMEIRO', CANCELAMENTO_RECORRENCIA_MSG_PRIMEIRO);
}

/** Carrega o gateway e a biblioteca do webhook (sem executar o receptor público). */
function cancelamentoRecorrenciaCarregarGateway(): MercadoPagoGateway
{
    require_once __DIR__ . '/../_gateways/mercado_pago.php';
    if (!defined('MP_WEBHOOK_BIBLIOTECA')) define('MP_WEBHOOK_BIBLIOTECA', true);
    if (MP_WEBHOOK_BIBLIOTECA !== true) throw new RuntimeException('Processamento indisponível.');
    require_once __DIR__ . '/../webhooks/webhook_mercado_pago.php';

    return MercadoPagoGateway::daConfiguracao();
}

/**
 * Portas reais (banco e leitura no Mercado Pago). Exige assinatura.php carregado. Nenhuma porta cancela a Preapproval.
 *
 * @return array<string, callable>
 */
function cancelamentoRecorrenciaPortasReais(mysqli $db, int $empresa, int $idAssinatura): array
{
    return [
        'vinculo' => static fn(): ?array => assinaturaServicoConsultaFinanceira($db,
            "SELECT id_assinatura_pagamento, id_empresa, ambiente, status, status_externo, assinatura_externa_id FROM assinatura_pagamento_recorrente
              WHERE id_empresa = ? AND id_assinatura = ? AND motor_recorrencia = 'preapproval'
              ORDER BY (status IN ('cancelada','encerrada')) ASC, id_assinatura_pagamento DESC LIMIT 1", 'ii', $empresa, $idAssinatura)[0] ?? null,
        'operacao_aberta' => static fn(array $v): bool => assinaturaServicoConsultaFinanceira($db,
            "SELECT 1 AS aberta FROM operacao_assinatura_gateway
              WHERE id_assinatura_pagamento = ? AND status_processamento IN ('pendente','processando','conciliacao') LIMIT 1",
            'i', (int)$v['id_assinatura_pagamento']) !== [],
        'confirmar' => static function (array $v) use ($db, $empresa): void {
            try {
                $gateway = cancelamentoRecorrenciaCarregarGateway();
                if (!MercadoPagoGateway::subscriptionsHabilitado()) return;
                mpSubConfirmarCancelamentoExterno($db, $gateway, $v + ['id_empresa' => $empresa], $empresa);
            } catch (Throwable $erro) {
                if (function_exists('mpWebhookRollback')) mpWebhookRollback($db);
                throw $erro;
            }
        },
    ];
}

/**
 * Conclusão local, na transação do chamador, depois de liberado o bloqueio. Um vínculo sem Preapproval externa é
 * descartado pelo serviço existente; nunca se registra cancelamento no provedor.
 *
 * @return bool false quando surgiu recorrência externa viva entre a verificação e a transação (o chamador desfaz)
 */
function cancelamentoRecorrenciaFinalizarLocal(mysqli $db, int $empresa, int $idAssinatura): bool
{
    $v = assinaturaServicoPreapprovalVinculoDoContrato($db, $empresa, $idAssinatura);
    if ($v === null || (string)$v['motor_recorrencia'] !== 'preapproval') return true;
    if (in_array((string)$v['status'], ['cancelada', 'encerrada'], true)) return true;
    if ($v['assinatura_externa_id'] === null) {
        assinaturaServicoPreapprovalSolicitarCancelamento($db, $empresa, $idAssinatura); // só marca como erro o vínculo sem Preapproval
        return true;
    }

    return (string)$v['status'] === 'erro' && in_array((string)($v['status_externo'] ?? ''), ['canceled', 'cancelled'], true);
}

/**
 * Resumo da recorrência por assinatura, para a listagem do Super Admin (só leitura).
 * situacao: ativa | cancelamento_pendente | verificacao (erro com Preapproval) | incerta (criação em aberto);
 * pode_cancelar: a ação "Cancelar pagamento automático" é oferecida (nunca para vínculo já cancelado/encerrado).
 *
 * @param list<int> $ids
 * @return array<int, array{situacao: string, pode_cancelar: bool, status_vinculo: string}>
 */
function cancelamentoRecorrenciaResumoPorAssinaturas(mysqli $db, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0)));
    if ($ids === []) return [];
    $linhas = assinaturaServicoConsultaFinanceira($db,
        "SELECT v.id_assinatura, v.id_assinatura_pagamento, v.status, v.status_externo, v.assinatura_externa_id,
                EXISTS (SELECT 1 FROM operacao_assinatura_gateway o WHERE o.id_assinatura_pagamento = v.id_assinatura_pagamento
                         AND o.status_processamento IN ('pendente','processando','conciliacao')) AS operacao_aberta
           FROM assinatura_pagamento_recorrente v
          WHERE v.motor_recorrencia = 'preapproval' AND v.status NOT IN ('cancelada','encerrada')
            AND v.id_assinatura IN (" . implode(',', array_fill(0, count($ids), '?')) . ')
          ORDER BY v.id_assinatura_pagamento ASC',
        str_repeat('i', count($ids)), ...$ids);
    $resumo = [];
    foreach ($linhas as $v) {
        $cancelado = in_array((string)($v['status_externo'] ?? ''), ['canceled', 'cancelled'], true);
        if ($v['assinatura_externa_id'] === null) {
            if ((int)$v['operacao_aberta'] === 1) {
                $resumo[(int)$v['id_assinatura']] = ['situacao' => 'incerta', 'pode_cancelar' => false, 'status_vinculo' => (string)$v['status']];
            }
            continue;
        }
        if ((string)$v['status'] === 'erro' && $cancelado) continue;
        $situacao = match ((string)$v['status']) {
            'cancelamento_pendente' => 'cancelamento_pendente',
            'erro' => 'verificacao',
            default => 'ativa',
        };
        $resumo[(int)$v['id_assinatura']] = ['situacao' => $situacao, 'pode_cancelar' => true, 'status_vinculo' => (string)$v['status']];
    }

    return $resumo;
}

/**
 * "Cancelar pagamento automático" pelo Super Admin: cancela no provedor só a Preapproval do contrato (empresa já validada
 * pelo chamador); contrato, acesso, cobranças e pagamentos não mudam. Mesmo serviço e outbox do botão do proprietário
 * (assinaturaServicoPreapprovalSolicitarCancelamento / mpSubOperacaoProcessar). O vínculo só fica 'cancelada' com o
 * `canceled` lido do provedor; recusa explícita devolve o vínculo a 'ativa'; resultado incerto mantém
 * 'cancelamento_pendente' para a reconciliação. O chamador mantém a trava por empresa.
 *
 * @param array<string, mixed>|null $ator ator da auditoria (Super Admin)
 * @return array{desfecho: string, http: int, code: string, msg: string} desfecho: cancelado | pendente | recusado | indisponivel
 */
function cancelamentoRecorrenciaCancelarPreapproval(mysqli $db, object $gateway, int $empresa, int $idAssinatura, ?array $ator): array
{
    $r = static fn(string $desfecho, int $http, string $code, string $msg): array => ['desfecho' => $desfecho, 'http' => $http, 'code' => $code, 'msg' => $msg];
    $falha = static fn(string $code = 'CANCELAMENTO_AUTOMATICO_RECUSADO', int $http = 409): array => $r('recusado', $http, $code, CANCELAMENTO_RECORRENCIA_MSG_FALHA_ADMIN);
    $ok = $r('cancelado', 200, 'PAGAMENTO_AUTOMATICO_CANCELADO', CANCELAMENTO_RECORRENCIA_MSG_OK_ADMIN);
    $pendente = $r('pendente', 202, 'CANCELAMENTO_AUTOMATICO_PENDENTE', CANCELAMENTO_RECORRENCIA_MSG_PENDENTE_ADMIN);
    $ler = static fn(): ?array => assinaturaServicoConsultaFinanceira($db,
        "SELECT id_assinatura_pagamento, id_empresa, ambiente, status, status_externo, assinatura_externa_id FROM assinatura_pagamento_recorrente
          WHERE id_empresa = ? AND id_assinatura = ? AND motor_recorrencia = 'preapproval'
          ORDER BY (status IN ('cancelada','encerrada')) ASC, id_assinatura_pagamento DESC LIMIT 1", 'ii', $empresa, $idAssinatura)[0] ?? null;
    $encerrado = static fn(?array $v): bool => $v !== null && in_array((string)$v['status'], ['cancelada', 'encerrada'], true);

    $vinculo = $ler();
    if ($vinculo === null || $vinculo['assinatura_externa_id'] === null) {
        return $r('recusado', 409, 'PAGAMENTO_AUTOMATICO_INEXISTENTE', 'Não há pagamento automático ativo para cancelar.');
    }
    if ($encerrado($vinculo)) {
        return $r('recusado', 409, 'PAGAMENTO_AUTOMATICO_JA_CANCELADO', 'O pagamento automático já está cancelado.');
    }
    if (!$gateway::subscriptionsHabilitado() || $gateway->ambiente() !== (string)$vinculo['ambiente']) {
        return $r('indisponivel', 503, 'SUBSCRIPTIONS_DESABILITADO', CANCELAMENTO_RECORRENCIA_MSG_FALHA_ADMIN);
    }

    $idAspr = (int)$vinculo['id_assinatura_pagamento'];
    if ((string)$vinculo['status'] === 'erro') {
        // Situação incerta/erro: sem novo envio; só a atualização pelo provedor (GET) pode confirmar o encerramento.
        try {
            mpSubConfirmarCancelamentoExterno($db, $gateway, $vinculo, $empresa);
        } catch (Throwable $e) {
            if (function_exists('mpWebhookRollback')) mpWebhookRollback($db);
            error_log('CANCELAMENTO_RECORRENCIA_ADMIN_CONFIRMAR: ' . get_class($e));
        }

        return $encerrado($ler()) ? $ok : $r('recusado', 409, 'PAGAMENTO_AUTOMATICO_REQUER_VERIFICACAO',
            'A situação do pagamento automático precisa de verificação. Atualize a situação ou consulte o suporte técnico.');
    }

    // Etapa 1 (transação, sem rede): intenção registrada com o contrato bloqueado, pelo serviço existente.
    try {
        if (!$db->begin_transaction()) throw new RuntimeException('Falha ao iniciar o cancelamento do pagamento automático.');
        assinaturaServicoConsultaFinanceira($db, 'SELECT id_assinatura FROM assinatura WHERE id_assinatura = ? AND id_empresa = ? FOR UPDATE', 'ii', $idAssinatura, $empresa);
        $atual = assinaturaServicoPreapprovalVinculoDoContrato($db, $empresa, $idAssinatura, $idAspr);
        if ($atual === null || $encerrado($atual)) {
            $db->rollback();
            return $encerrado($atual) ? $ok : $falha();
        }
        $pedido = (string)$atual['status'] === 'pendente'
            ? assinaturaServicoPreapprovalCancelarPrimeiraExterna($db, $atual, $ator)
            : assinaturaServicoPreapprovalSolicitarCancelamento($db, $empresa, $idAssinatura, $ator);
        if (($pedido['situacao'] ?? '') !== 'solicitado' || (int)($pedido['id_operacao'] ?? 0) <= 0) {
            $db->rollback();
            return $falha();
        }
        if (!$db->commit()) throw new RuntimeException('Falha ao registrar o cancelamento do pagamento automático.');
    } catch (Throwable $e) {
        try { $db->rollback(); } catch (Throwable) {}
        error_log('CANCELAMENTO_RECORRENCIA_ADMIN_REGISTRO: ' . get_class($e));
        return $falha('CANCELAMENTO_AUTOMATICO_ERRO', 500);
    }
    $idOperacao = (int)$pedido['id_operacao'];

    // Etapa 2 (rede): GET, PUT `canceled` e GET de confirmação, pelo processador do outbox.
    try {
        $res = mpSubOperacaoProcessar($db, $gateway, $idOperacao, $empresa);
    } catch (Throwable $e) {
        if (function_exists('mpWebhookRollback')) mpWebhookRollback($db);
        error_log('CANCELAMENTO_RECORRENCIA_ADMIN_PROCESSAR: ' . get_class($e));
        $res = ['resultado' => 'erro', 'codigo' => 'FALHA_INTERNA'];
    }
    // Sucesso só pelo vínculo local encerrado a partir do `canceled` lido no provedor, nunca pelo retorno da chamada.
    if ($encerrado($ler())) return $ok;
    if (($res['resultado'] ?? '') === 'erro' && ($res['codigo'] ?? '') === 'PROVEDOR_REJEITOU') {
        try {
            mpSubSincronizarPreapproval($db, $gateway, $gateway->ambiente(), (string)$vinculo['assinatura_externa_id'], null, $empresa);
        } catch (Throwable $e) {
            if (function_exists('mpWebhookRollback')) mpWebhookRollback($db);
        }
        if ($encerrado($ler())) return $ok;
        try {
            if (!$db->begin_transaction()) throw new RuntimeException('Falha ao registrar a recusa.');
            assinaturaServicoPreapprovalReverterCancelamentoRecusado($db, $empresa, $idAspr, $idOperacao);
            if (!$db->commit()) throw new RuntimeException('Falha ao registrar a recusa.');
        } catch (Throwable $e) {
            try { $db->rollback(); } catch (Throwable) {}
            error_log('CANCELAMENTO_RECORRENCIA_ADMIN_RECUSA: ' . get_class($e));
        }

        return $falha('CANCELAMENTO_AUTOMATICO_RECUSADO', 422);
    }

    return $pendente;
}

/** Trava por empresa (não bloqueante). false quando outra operação de cartão/cancelamento está em andamento. */
function cancelamentoRecorrenciaObterTrava(mysqli $db, int $empresa): bool
{
    return (int)(assinaturaServicoConsultaFinanceira($db, 'SELECT GET_LOCK(?, 0) AS obtida', 's', cancelamentoRecorrenciaNomeTrava($empresa))[0]['obtida'] ?? 0) === 1;
}

function cancelamentoRecorrenciaLiberarTrava(mysqli $db, int $empresa): void
{
    try {
        assinaturaServicoConsultaFinanceira($db, 'SELECT RELEASE_LOCK(?) AS liberada', 's', cancelamentoRecorrenciaNomeTrava($empresa));
    } catch (Throwable $erro) {
        error_log('CANCELAMENTO_RECORRENCIA_TRAVA: ' . get_class($erro));
    }
}
