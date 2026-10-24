<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CONCILIAÇÃO DE PAGAMENTOS MERCADO PAGO (CLI)
|--------------------------------------------------------------------------
| Complementa o webhook: consulta a Order autoritativa no Mercado Pago e
| aplica o resultado com as MESMAS regras financeiras do webhook
| (mpWebhookAplicarOrder: validação, locks, idempotência por Payment ID,
| registro do pagamento, recálculo da cobrança, conversão/reativação,
| estorno, divergências e auditoria). Não cria Orders nem pagamentos externos,
| não simula notificações e não usa HMAC: a autenticidade vem da consulta
| direta à API com a configuração privada do servidor.
|
| Modos:
|   Diagnóstico (padrão): consulta o Mercado Pago e informa candidatos,
|   motivo de elegibilidade e a ação prevista. Não grava nada.
|   Efetivo: --executar. Em seleção individual exige também
|   --confirmar=<mesma Order>.
|
| Opções:
|   --limite=N         transações por execução (1..50, padrão 20)
|   --intervalo-min=N  intervalo mínimo entre consultas da mesma transação,
|                      pela coluna ultima_consulta_em (1..1440, padrão 10)
|   --janela-dias=N    considera somente transações atualizadas nos últimos
|                      N dias (1..90, padrão 30)
|   --pausa-ms=N       pausa entre consultas ao Mercado Pago (0..5000, padrão 250)
|   --empresa=ID --transacao=ID --order=ORD...   seleção individual (os três juntos)
|   --executar         aplica o resultado (efetivo)
|   --confirmar=ORD... confirmação da seleção individual efetiva
|
| Saída: resumo em STDOUT; falhas por transação em STDERR (sem payloads,
| tokens ou dados do pagador). Código 0 = sem falhas; 1 = falha técnica,
| inclusive banco indisponível durante o carregamento da conexão.
| Execuções simultâneas são impedidas por GET_LOCK do MySQL.
*/

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Execução permitida somente em CLI.\n");
    exit(1);
}

date_default_timezone_set('America/Sao_Paulo');

const CONCILIACAO_MP_LOCK = 'amagenda:conciliacao_mercado_pago';
const CONCILIACAO_MP_ORIGEM = 'conciliacao_mercado_pago';
const CONCILIACAO_MP_METODOS = ['pix', 'cartao_recorrente'];

/** @return array<string, int|string|bool|null> */
function conciliacaoMpOpcoes(array $argumentos): array
{
    $opcoes = [
        'limite' => 20,
        'intervalo-min' => 10,
        'janela-dias' => 30,
        'pausa-ms' => 250,
        'empresa' => null,
        'transacao' => null,
        'order' => null,
        'executar' => false,
        'confirmar' => null,
    ];
    $faixas = [
        'limite' => [1, 50],
        'intervalo-min' => [1, 1440],
        'janela-dias' => [1, 90],
        'pausa-ms' => [0, 5000],
        'empresa' => [1, PHP_INT_MAX],
        'transacao' => [1, PHP_INT_MAX],
    ];

    foreach (array_slice($argumentos, 1) as $argumento) {
        if ($argumento === '--executar') {
            $opcoes['executar'] = true;
            continue;
        }
        if (preg_match('/^--([a-z-]+)=(.+)$/D', (string) $argumento, $partes) !== 1
            || !array_key_exists($partes[1], $opcoes) || $partes[1] === 'executar') {
            throw new InvalidArgumentException('Argumento não reconhecido.');
        }
        [$chave, $valor] = [$partes[1], $partes[2]];
        if (isset($faixas[$chave])) {
            if (preg_match('/^[0-9]{1,9}$/D', $valor) !== 1
                || (int) $valor < $faixas[$chave][0] || (int) $valor > $faixas[$chave][1]) {
                throw new InvalidArgumentException("Valor inválido para --{$chave}.");
            }
            $opcoes[$chave] = (int) $valor;
            continue;
        }
        if (preg_match('/^[A-Za-z0-9_-]{1,150}$/D', $valor) !== 1) {
            throw new InvalidArgumentException("Valor inválido para --{$chave}.");
        }
        $opcoes[$chave] = $valor;
    }

    $individuais = array_filter([$opcoes['empresa'], $opcoes['transacao'], $opcoes['order']], static fn ($v) => $v !== null);
    if ($individuais !== [] && count($individuais) !== 3) {
        throw new InvalidArgumentException('A seleção individual exige --empresa, --transacao e --order juntos.');
    }
    $individual = count($individuais) === 3;
    if ($opcoes['confirmar'] !== null && (!$individual || !$opcoes['executar'])) {
        throw new InvalidArgumentException('--confirmar só é aceito com --executar em seleção individual.');
    }
    if ($individual && $opcoes['executar'] && $opcoes['confirmar'] !== $opcoes['order']) {
        throw new InvalidArgumentException('Execução efetiva individual exige --confirmar com a mesma Order.');
    }
    $opcoes['individual'] = $individual;

    return $opcoes;
}

/** Motivo de elegibilidade ou null quando a transação não deve ser conciliada automaticamente. */
function conciliacaoMpMotivo(array $transacao, string $ambiente): ?string
{
    if ((string) $transacao['ambiente'] !== $ambiente
        || !in_array((string) $transacao['metodo'], CONCILIACAO_MP_METODOS, true)
        || $transacao['ordem_externa_id'] === null
        || (int) $transacao['requer_conciliacao'] !== 0) {
        return null;
    }
    $status = (string) $transacao['status'];
    if ($status === 'confirmada' && $transacao['id_pagamento'] === null) {
        return 'confirmada_sem_pagamento_interno';
    }
    if (in_array($status, ['aguardando', 'processando'], true)) {
        return 'aguardando_confirmacao_externa';
    }

    return null;
}

/** @return list<array<string, mixed>> */
function conciliacaoMpCandidatos(mysqli $conexao, array $opcoes, string $ambiente): array
{
    $colunas = 'id_transacao,id_empresa,id_cobranca,id_pagamento,ambiente,metodo,status,valor,
                referencia_interna,ordem_externa_id,pagamento_externo_id,requer_conciliacao,ultima_consulta_em';

    if ($opcoes['individual']) {
        return mpWebhookConsulta(
            $conexao,
            "SELECT {$colunas}
               FROM transacao_pagamento
              WHERE id_transacao = ? AND id_empresa = ? AND provedor = ? AND ordem_externa_id = ?
              LIMIT 1",
            'iiss',
            (int) $opcoes['transacao'],
            (int) $opcoes['empresa'],
            MP_WEBHOOK_PROVEDOR,
            (string) $opcoes['order']
        )->fetch_all(MYSQLI_ASSOC);
    }

    // Lote: somente estados recuperáveis, com Order conhecida, sem marcação de
    // conciliação manual, dentro da janela e respeitando o intervalo mínimo.
    return mpWebhookConsulta(
        $conexao,
        "SELECT {$colunas}
           FROM transacao_pagamento
          WHERE provedor = ?
            AND ambiente = ?
            AND metodo IN ('pix', 'cartao_recorrente')
            AND ordem_externa_id IS NOT NULL
            AND requer_conciliacao = 0
            AND (status IN ('aguardando', 'processando')
                 OR (status = 'confirmada' AND id_pagamento IS NULL))
            AND atualizado_em >= DATE_SUB(NOW(), INTERVAL ? DAY)
            AND (ultima_consulta_em IS NULL
                 OR ultima_consulta_em <= DATE_SUB(NOW(), INTERVAL ? MINUTE))
          ORDER BY (ultima_consulta_em IS NOT NULL), ultima_consulta_em, id_transacao
          LIMIT ?",
        'ssiii',
        MP_WEBHOOK_PROVEDOR,
        $ambiente,
        (int) $opcoes['janela-dias'],
        (int) $opcoes['intervalo-min'],
        (int) $opcoes['limite']
    )->fetch_all(MYSQLI_ASSOC);
}

/**
 * Ação prevista (somente leitura, para o diagnóstico). Espelha as checagens de
 * mpWebhookAplicarOrder apenas para informar; a decisão autoritativa e as
 * escritas acontecem exclusivamente em mpWebhookAplicarOrder.
 */
function conciliacaoMpAcaoPrevista(mysqli $conexao, array $transacao, array $order, ?array $pagamentoOrder): string
{
    if ($pagamentoOrder === null) {
        return 'conciliacao_manual:formato_de_pagamentos_nao_reconhecido';
    }
    $confirmada = $order['status'] === 'processed' && $order['detalhe'] === 'accredited'
        && $pagamentoOrder['status'] === 'processed' && $pagamentoOrder['detalhe'] === 'accredited';
    if (!$confirmada) {
        return 'atualizar_status_externo:' . $order['status'];
    }
    if ($pagamentoOrder['id'] === null || !is_int($pagamentoOrder['pago_centavos']) || $pagamentoOrder['pago_centavos'] <= 0) {
        return 'conciliacao_manual:pagamento_sem_identificador_ou_valor';
    }
    $metodoValido = (string) $transacao['metodo'] === 'pix'
        ? $pagamentoOrder['id_metodo'] === 'pix' && $pagamentoOrder['tipo_metodo'] === 'bank_transfer'
        : $pagamentoOrder['tipo_metodo'] === 'credit_card';
    if (!$metodoValido) {
        return 'conciliacao_manual:metodo_divergente';
    }
    $valorEsperado = pagamentoGatewayValorCentavos((string) $transacao['valor']);
    $ressalvas = [];
    if ($pagamentoOrder['pago_centavos'] !== $valorEsperado || $order['total_centavos'] !== $valorEsperado) {
        $ressalvas[] = 'valor_divergente';
    }
    $cobranca = mpWebhookConsulta(
        $conexao,
        'SELECT status FROM cobranca WHERE id_cobranca = ? AND id_empresa = ? LIMIT 1',
        'ii',
        (int) $transacao['id_cobranca'],
        (int) $transacao['id_empresa']
    )->fetch_assoc();
    if (($cobranca['status'] ?? '') === 'cancelada') {
        $ressalvas[] = 'cobranca_cancelada';
    }
    $sufixo = $ressalvas === [] ? '' : ':com_conciliacao_manual_' . implode('_', $ressalvas);
    $existente = mpWebhookConsulta(
        $conexao,
        'SELECT id_empresa,id_cobranca,status FROM pagamento WHERE provedor = ? AND referencia_externa = ? LIMIT 1',
        'ss',
        MP_WEBHOOK_PROVEDOR,
        (string) $pagamentoOrder['id']
    )->fetch_assoc();
    if ($existente) {
        return (int) $existente['id_empresa'] === (int) $transacao['id_empresa']
            && (int) $existente['id_cobranca'] === (int) $transacao['id_cobranca']
            ? 'vincular_pagamento_ja_registrado' . $sufixo
            : 'conciliacao_manual:payment_id_em_outro_registro';
    }

    return 'registrar_pagamento_e_recalcular_cobranca' . $sufixo;
}

/** @return array<string, mixed>|null */
function conciliacaoMpEstadoLocal(mysqli $conexao, int $idTransacao, int $idEmpresa): ?array
{
    return mpWebhookConsulta(
        $conexao,
        'SELECT t.status, t.id_pagamento, t.requer_conciliacao, c.status AS cobranca_status
           FROM transacao_pagamento t
           JOIN cobranca c ON c.id_cobranca = t.id_cobranca AND c.id_empresa = t.id_empresa
          WHERE t.id_transacao = ? AND t.id_empresa = ?
          LIMIT 1',
        'ii',
        $idTransacao,
        $idEmpresa
    )->fetch_assoc() ?: null;
}

function conciliacaoMpLinha(array $campos): void
{
    $partes = [];
    foreach ($campos as $chave => $valor) {
        $partes[] = $chave . '=' . preg_replace('/[^A-Za-z0-9_.:\-]/', '_', (string) $valor);
    }
    echo implode(' ', $partes), "\n";
}

$resumo = [
    'candidatas' => 0,
    'consultadas' => 0,
    'conciliadas' => 0,
    'atualizadas' => 0,
    'sem_alteracao' => 0,
    'conciliacao_manual' => 0,
    'ignoradas' => 0,
    'falhas' => 0,
];
$lockObtido = false;

try {
    $opcoes = conciliacaoMpOpcoes($argv ?? []);
} catch (InvalidArgumentException $erro) {
    fwrite(STDERR, $erro->getMessage() . "\n");
    exit(1);
}

// conexao.php encerra com exit('mensagem') quando o banco falha, o que no PHP
// resulta em código 0. Até a conexão ser carregada, qualquer encerramento é
// tratado aqui como falha técnica (código 1), sem alterar o conexao.php global.
$conciliacaoMpConexaoCarregada = false;
register_shutdown_function(static function () use (&$conciliacaoMpConexaoCarregada): void {
    if (!$conciliacaoMpConexaoCarregada) {
        fwrite(STDERR, "Conciliação encerrada antes de carregar a conexão com o banco.\n");
        exit(1);
    }
});

define('MP_WEBHOOK_BIBLIOTECA', true);
require_once __DIR__ . '/../_config/conexao.php';
require_once __DIR__ . '/../webhooks/webhook_mercado_pago.php';
$conciliacaoMpConexaoCarregada = true;

try {
    if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
        throw new RuntimeException('Conexão indisponível.');
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conexao->set_charset('utf8mb4');
    mpWebhookOrigemAuditoria(CONCILIACAO_MP_ORIGEM);

    $gateway = MercadoPagoGateway::daConfiguracao();
    $ambiente = $gateway->ambiente();

    $lock = mpWebhookConsulta($conexao, 'SELECT GET_LOCK(?, 0) AS obtido', 's', CONCILIACAO_MP_LOCK)->fetch_assoc();
    if ((int) ($lock['obtido'] ?? 0) !== 1) {
        echo "Outra execução da conciliação está em andamento. Nada foi feito.\n";
        exit(0);
    }
    $lockObtido = true;

    $modo = $opcoes['executar'] ? 'EFETIVO' : 'DIAGNOSTICO';
    echo "Conciliação Mercado Pago — modo {$modo} — ambiente {$ambiente}\n";

    $candidatas = conciliacaoMpCandidatos($conexao, $opcoes, $ambiente);
    if ($opcoes['individual'] && $candidatas === []) {
        echo "Transação não localizada para a empresa e Order informadas.\n";
    }

    foreach ($candidatas as $indice => $transacao) {
        $resumo['candidatas']++;
        $idTransacao = (int) $transacao['id_transacao'];
        $idEmpresa = (int) $transacao['id_empresa'];
        $linha = ['transacao' => $idTransacao, 'empresa' => $idEmpresa, 'cobranca' => (int) $transacao['id_cobranca']];

        try {
            $motivo = conciliacaoMpMotivo($transacao, $ambiente);
            $linha['motivo'] = $motivo ?? 'nao_elegivel';
            if ($motivo === null) {
                $resumo['ignoradas']++;
                conciliacaoMpLinha($linha + ['status_local' => $transacao['status'], 'resultado' => 'ignorada']);
                continue;
            }

            if ($indice > 0 && (int) $opcoes['pausa-ms'] > 0) {
                usleep((int) $opcoes['pausa-ms'] * 1000);
            }

            $ordemId = (string) $transacao['ordem_externa_id'];
            $resposta = $gateway->requisitar('consultar_order', ['order_id' => $ordemId]);
            $resumo['consultadas']++;
            if (!($resposta['sucesso'] ?? false) || !is_array($resposta['dados'] ?? null)) {
                $resumo['falhas']++;
                fwrite(STDERR, sprintf(
                    "Falha ao consultar a Order da transação #%d (codigo=%s http=%d).\n",
                    $idTransacao,
                    (string) ($resposta['codigo'] ?? ''),
                    (int) ($resposta['http_status'] ?? 0)
                ));
                conciliacaoMpLinha($linha + ['resultado' => 'falha_consulta']);
                continue;
            }

            $order = mpWebhookNormalizarOrder($resposta['dados'], $ordemId);
            if ($order === null) {
                $resumo['falhas']++;
                $estrutura = mpWebhookEstruturaOrder($resposta['dados']);
                fwrite(STDERR, sprintf(
                    "Order da transação #%d não reconhecida (currency=%s type=%s payments=%s).\n",
                    $idTransacao,
                    $estrutura['currency'],
                    $estrutura['type'],
                    $estrutura['payments']
                ));
                conciliacaoMpLinha($linha + ['resultado' => 'resposta_invalida']);
                continue;
            }

            // Mapeamento inequívoco: o mesmo localizador do webhook precisa apontar para esta transação.
            if (mpWebhookLocalizarTransacao($conexao, $order, $ambiente) !== $idTransacao) {
                $resumo['ignoradas']++;
                conciliacaoMpLinha($linha + ['resultado' => 'ignorada_mapeamento_ambiguo']);
                continue;
            }

            $pagamentoOrder = mpWebhookPagamentoOrder($order);
            $acao = conciliacaoMpAcaoPrevista($conexao, $transacao, $order, $pagamentoOrder);
            $linha += [
                'order' => $ordemId,
                'order_status' => $order['status'] . '/' . ($order['detalhe'] ?? '-'),
                'payment_id' => $pagamentoOrder['id'] ?? '-',
                'valor_centavos' => $order['total_centavos'],
                'status_local' => $transacao['status'],
                'acao_prevista' => $acao,
            ];

            if (!$opcoes['executar']) {
                $estado = conciliacaoMpEstadoLocal($conexao, $idTransacao, $idEmpresa);
                conciliacaoMpLinha($linha + [
                    'cobranca_status' => $estado['cobranca_status'] ?? '-',
                    'resultado' => 'diagnostico_sem_escrita',
                ]);
                continue;
            }

            $antes = conciliacaoMpEstadoLocal($conexao, $idTransacao, $idEmpresa);
            mpWebhookAplicarOrder($conexao, null, $idTransacao, $order, $ambiente);
            $depois = conciliacaoMpEstadoLocal($conexao, $idTransacao, $idEmpresa);

            if ($depois === null) {
                throw new RuntimeException('Estado local indisponível após a conciliação.');
            }
            if ((int) $depois['requer_conciliacao'] === 1) {
                $resultado = 'conciliacao_manual';
            } elseif (($antes['id_pagamento'] ?? null) === null && $depois['id_pagamento'] !== null) {
                $resultado = 'conciliadas';
            } elseif ($antes !== $depois) {
                $resultado = 'atualizadas';
            } else {
                $resultado = 'sem_alteracao';
            }
            $resumo[$resultado]++;
            conciliacaoMpLinha($linha + [
                'status_final' => $depois['status'],
                'id_pagamento' => $depois['id_pagamento'] ?? '-',
                'cobranca_status' => $depois['cobranca_status'],
                'resultado' => $resultado,
            ]);
        } catch (Throwable $erro) {
            $resumo['falhas']++;
            fwrite(STDERR, sprintf(
                "Falha ao conciliar a transação #%d (%s).\n",
                $idTransacao,
                get_class($erro)
            ));
            error_log('[conciliacao_mercado_pago] transacao=' . $idTransacao . ' ' . get_class($erro) . ': '
                . mb_substr($erro->getMessage(), 0, 300));
            conciliacaoMpLinha($linha + ['resultado' => 'falha']);
        }
    }
} catch (Throwable $erro) {
    fwrite(STDERR, 'Falha global na conciliação (' . get_class($erro) . ").\n");
    if ($lockObtido && isset($conexao) && $conexao instanceof mysqli) {
        try {
            mpWebhookConsulta($conexao, 'SELECT RELEASE_LOCK(?) AS liberado', 's', CONCILIACAO_MP_LOCK);
        } catch (Throwable) {
        }
    }
    exit(1);
}

if ($lockObtido) {
    try {
        mpWebhookConsulta($conexao, 'SELECT RELEASE_LOCK(?) AS liberado', 's', CONCILIACAO_MP_LOCK);
    } catch (Throwable) {
    }
}

foreach ($resumo as $chave => $valor) {
    echo str_replace('_', ' ', ucfirst($chave)), ": {$valor}\n";
}

exit($resumo['falhas'] > 0 ? 1 : 0);
