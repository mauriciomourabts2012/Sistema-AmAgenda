<?php
declare(strict_types=1);

/*
 * QUARENTENA: este teste legado usa a conexão do AmAgenda e IDs reais fixos.
 * O rollback não torna sua execução segura. Não liberar por argumento, variável
 * de ambiente ou constante: substituir por fixtures isoladas antes de reativar.
 * A interrupção deve permanecer ANTES de qualquer require/include.
 */
if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "BLOQUEADO: teste legado depende do banco AmAgenda; nenhuma conexão foi carregada.\n");
} else {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "BLOQUEADO: teste legado depende do banco AmAgenda.\n";
}
exit(2);

require_once __DIR__ . '/../../backend/_config/conexao.php';
require_once __DIR__ . '/../../backend/_servicos/assinatura.php';

function cancelarProgramadoAssertSame(mixed $esperado, mixed $atual, string $mensagem): void
{
    if ($esperado !== $atual) {
        throw new RuntimeException($mensagem . ' Esperado: ' . var_export($esperado, true) . '; atual: ' . var_export($atual, true) . '.');
    }
}

function cancelarProgramadoCriarAssinaturaTeste(mysqli $conexao, int $idEmpresa, int $idPlano): int
{
    $inicio = date('Y-m-d');
    $stmt = $conexao->prepare(
        "INSERT INTO assinatura (id_empresa, id_plano, valor_contratado, periodicidade, dia_vencimento, data_inicio, status, modalidade, observacao)
         VALUES (?, ?, 1.00, 'mensal', 1, ?, 'ativa', 'paga', 'fixture temporaria cancelamento programado')"
    );
    if (!$stmt) throw new RuntimeException('Não foi possível preparar a fixture de assinatura.');
    $stmt->bind_param('iis', $idEmpresa, $idPlano, $inicio);
    if (!$stmt->execute()) throw new RuntimeException('Não foi possível criar a fixture de assinatura.');
    $idAssinatura = (int)$stmt->insert_id;
    $stmt->close();
    return $idAssinatura;
}

function cancelarProgramadoCriarCobranca(mysqli $conexao, int $idEmpresa, int $idAssinatura, string $inicio, string $fim, string $status, string $finalidade, bool $pagamentoConfirmado): void
{
    $stmt = $conexao->prepare(
        'INSERT INTO cobranca (id_empresa, id_assinatura, periodo_inicio, periodo_fim, data_vencimento, valor, status, finalidade, observacao)
         VALUES (?, ?, ?, ?, ?, 1.00, ?, ?, \'fixture temporaria cancelamento programado\')'
    );
    if (!$stmt) throw new RuntimeException('Não foi possível preparar a fixture de cobrança.');
    $stmt->bind_param('iisssss', $idEmpresa, $idAssinatura, $inicio, $fim, $inicio, $status, $finalidade);
    if (!$stmt->execute()) throw new RuntimeException('Não foi possível criar a fixture de cobrança.');
    $idCobranca = (int)$stmt->insert_id;
    $stmt->close();

    if (!$pagamentoConfirmado) return;
    $pagamento = $conexao->prepare(
        "INSERT INTO pagamento (id_empresa, id_cobranca, valor_pago, data_pagamento, forma_pagamento, origem, status, observacao)
         VALUES (?, ?, 1.00, CURRENT_TIMESTAMP, 'pix', 'manual', 'confirmado', 'fixture temporaria cancelamento programado')"
    );
    if (!$pagamento) throw new RuntimeException('Não foi possível preparar a fixture de pagamento.');
    $pagamento->bind_param('ii', $idEmpresa, $idCobranca);
    if (!$pagamento->execute()) throw new RuntimeException('Não foi possível criar a fixture de pagamento.');
    $pagamento->close();
}

try {
    if (!$conexao->begin_transaction()) throw new RuntimeException('Não foi possível iniciar a transação de teste.');

    cancelarProgramadoAssertSame(
        '2026-11-23',
        assinaturaServicoFimPeriodoPago($conexao, 26, 28),
        'A conversão de trial quitada da assinatura 28 deve cobrir o período pago atual.'
    );
    cancelarProgramadoAssertSame(
        ['pode_fim_periodo_pago' => true, 'data_fim_periodo_pago' => '2026-11-23'],
        assinaturaServicoPreviaCancelamento($conexao, 26, 28),
        'A prévia deve expor a data calculada pelo servidor para a modalidade programada.'
    );

    $plano = $conexao->query('SELECT id_plano FROM assinatura WHERE id_assinatura = 28 AND id_empresa = 26 LIMIT 1')->fetch_assoc();
    if ($plano === null) throw new RuntimeException('Assinatura de referência não encontrada.');
    $idEmpresa = 26;
    $idPlano = (int)$plano['id_plano'];
    $hoje = new DateTimeImmutable('today');
    $inicioVigente = $hoje->format('Y-m-d');
    $fimVigente = $hoje->modify('+30 days')->format('Y-m-d');

    $regular = cancelarProgramadoCriarAssinaturaTeste($conexao, $idEmpresa, $idPlano);
    cancelarProgramadoCriarCobranca($conexao, $idEmpresa, $regular, $inicioVigente, $fimVigente, 'paga', 'regular', true);
    cancelarProgramadoAssertSame($fimVigente, assinaturaServicoFimPeriodoPago($conexao, $idEmpresa, $regular), 'Cobrança regular quitada deve ser reconhecida.');

    $expirada = cancelarProgramadoCriarAssinaturaTeste($conexao, $idEmpresa, $idPlano);
    cancelarProgramadoCriarCobranca($conexao, $idEmpresa, $expirada, $hoje->modify('-60 days')->format('Y-m-d'), $hoje->modify('-30 days')->format('Y-m-d'), 'paga', 'regular', true);
    cancelarProgramadoAssertSame(null, assinaturaServicoFimPeriodoPago($conexao, $idEmpresa, $expirada), 'Período quitado já vencido não pode programar cancelamento.');

    $semQuitacao = cancelarProgramadoCriarAssinaturaTeste($conexao, $idEmpresa, $idPlano);
    cancelarProgramadoCriarCobranca($conexao, $idEmpresa, $semQuitacao, $inicioVigente, $fimVigente, 'pendente', 'regular', false);
    cancelarProgramadoAssertSame(null, assinaturaServicoFimPeriodoPago($conexao, $idEmpresa, $semQuitacao), 'Cobrança pendente não pode ser tratada como quitada.');

    $upgrade = cancelarProgramadoCriarAssinaturaTeste($conexao, $idEmpresa, $idPlano);
    cancelarProgramadoCriarCobranca($conexao, $idEmpresa, $upgrade, $inicioVigente, $fimVigente, 'paga', 'upgrade', true);
    cancelarProgramadoAssertSame(null, assinaturaServicoFimPeriodoPago($conexao, $idEmpresa, $upgrade), 'Cobrança de upgrade não pode ser tratada como mensalidade quitada.');

    $cancelada = cancelarProgramadoCriarAssinaturaTeste($conexao, $idEmpresa, $idPlano);
    cancelarProgramadoCriarCobranca($conexao, $idEmpresa, $cancelada, $inicioVigente, $fimVigente, 'cancelada', 'regular', true);
    cancelarProgramadoAssertSame(null, assinaturaServicoFimPeriodoPago($conexao, $idEmpresa, $cancelada), 'Cobrança cancelada não pode ser tratada como quitada.');

    $conexao->rollback();
    echo "OK cancelamento programado: conversao_trial, regular, vencida, pendente, upgrade e cancelada.\n";
} catch (Throwable $erro) {
    try { $conexao->rollback(); } catch (Throwable) {}
    fwrite(STDERR, 'FALHOU: ' . $erro->getMessage() . "\n");
    exit(1);
}
