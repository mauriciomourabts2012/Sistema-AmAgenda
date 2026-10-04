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

function cobrancaErroTransacional(mysqli $conexao, array $payload, int $code): void
{
    try {
        $conexao->rollback();
    } catch (Throwable) {
    }
    out($payload, $code);
}

function cobrancaIdEmpresaEntrada(): ?int
{
    $raw = $_POST['id_empresa'] ?? null;
    if (!is_scalar($raw) || preg_match('/^[1-9]\d*$/', trim((string)$raw)) !== 1) {
        return null;
    }
    return (int)$raw;
}

function cobrancaIdEntrada(array $origem, string $campo): ?int
{
    $raw = $origem[$campo] ?? null;
    if (!is_scalar($raw) || preg_match('/^[1-9]\d*$/', trim((string)$raw)) !== 1) {
        return null;
    }
    return (int)$raw;
}

function cobrancaData(string $valor, string $campo): DateTimeImmutable
{
    $data = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    $erros = DateTimeImmutable::getLastErrors();
    if (!$data || (is_array($erros) && (($erros['warning_count'] ?? 0) > 0 || ($erros['error_count'] ?? 0) > 0))) {
        throw new RuntimeException("Data inválida em {$campo}.");
    }
    return $data;
}

function cobrancaMesesPeriodicidade(string $periodicidade): int
{
    return match ($periodicidade) {
        'mensal' => 1,
        'trimestral' => 3,
        'semestral' => 6,
        'anual' => 12,
        default => throw new RuntimeException('Periodicidade da assinatura inválida.'),
    };
}

function cobrancaCalcularVencimento(DateTimeImmutable $inicio, int $dia): DateTimeImmutable
{
    if ($dia < 1 || $dia > 28) {
        throw new RuntimeException('Dia de vencimento da assinatura inválido.');
    }

    $primeiroMes = $inicio->modify('first day of this month');
    $vencimento = $primeiroMes->setDate((int)$primeiroMes->format('Y'), (int)$primeiroMes->format('m'), $dia);
    if ($vencimento < $inicio) {
        $proximoMes = $primeiroMes->modify('+1 month');
        $vencimento = $proximoMes->setDate((int)$proximoMes->format('Y'), (int)$proximoMes->format('m'), $dia);
    }
    return $vencimento;
}

function cobrancaAuditoriaAlteracoes(array $dados): array
{
    $resultado = [];
    foreach ($dados as $campo => $valor) {
        $resultado[$campo] = ['antes' => null, 'depois' => $valor];
    }
    return $resultado;
}

function pagamentoValorCentavos(string $valor): int
{
    $normalizado = str_replace(',', '.', trim($valor));
    if (preg_match('/^\d+(?:\.\d{1,2})?$/', $normalizado) !== 1) {
        throw new RuntimeException('Informe um valor de pagamento válido, com até duas casas decimais.');
    }
    [$inteiro, $decimal] = array_pad(explode('.', $normalizado, 2), 2, '');
    if (strlen($inteiro) > 12) {
        throw new RuntimeException('Valor de pagamento excede o limite permitido.');
    }
    return ((int)$inteiro * 100) + (int)str_pad($decimal, 2, '0');
}

function pagamentoCentavosDecimal(int $centavos): string
{
    return number_format($centavos / 100, 2, '.', '');
}

function pagamentoFormasPermitidas(): array
{
    return ['pix', 'boleto', 'cartao_credito', 'cartao_debito', 'transferencia', 'dinheiro', 'outro'];
}

function pagamentoTotalConfirmadoCentavos(mysqli $conexao, int $idCobranca, bool $bloquear = false): int
{
    $sql = "SELECT valor_pago FROM pagamento WHERE id_cobranca = ? AND status = 'confirmado'";
    if ($bloquear) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar recálculo dos pagamentos.');
    }
    $stmt->bind_param('i', $idCobranca);
    if (!$stmt->execute()) {
        $erro = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Falha ao recalcular pagamentos: ' . $erro);
    }
    $res = $stmt->get_result();
    $total = 0;
    while ($row = $res->fetch_assoc()) {
        $total += pagamentoValorCentavos((string)$row['valor_pago']);
    }
    $stmt->close();
    return $total;
}

function pagamentoSituacaoCobranca(string $status, string $vencimento): string
{
    return $status === 'pendente' && $vencimento < date('Y-m-d') ? 'vencida' : $status;
}

function pagamentoRespostaResumo(array $cobranca, int $totalCentavos): array
{
    $valorCentavos = pagamentoValorCentavos((string)$cobranca['valor']);
    return [
        'id_cobranca' => (int)$cobranca['id_cobranca'],
        'id_empresa' => (int)$cobranca['id_empresa'],
        'nome_empresa' => (string)$cobranca['nome_empresa'],
        'valor' => (float)$cobranca['valor'],
        'status' => (string)$cobranca['status'],
        'situacao' => pagamentoSituacaoCobranca((string)$cobranca['status'], (string)$cobranca['data_vencimento']),
        'data_vencimento' => (string)$cobranca['data_vencimento'],
        'total_pago_confirmado' => (float)pagamentoCentavosDecimal($totalCentavos),
        'saldo_restante' => (float)pagamentoCentavosDecimal(max(0, $valorCentavos - $totalCentavos)),
    ];
}

function pagamentoCarregarCobranca(mysqli $conexao, int $idCobranca, bool $bloquear = false): ?array
{
    $sql = "SELECT c.id_cobranca, c.id_empresa, c.id_assinatura,
                   DATE_FORMAT(c.periodo_inicio, '%Y-%m-%d') AS periodo_inicio,
                   DATE_FORMAT(c.periodo_fim, '%Y-%m-%d') AS periodo_fim,
                   c.valor, c.status,
                   DATE_FORMAT(c.data_vencimento, '%Y-%m-%d') AS data_vencimento,
                   e.nome AS nome_empresa
              FROM cobranca c
              INNER JOIN empresa e ON e.id_empresa = c.id_empresa
             WHERE c.id_cobranca = ?
             LIMIT 1";
    if ($bloquear) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar validação da cobrança.');
    }
    $stmt->bind_param('i', $idCobranca);
    if (!$stmt->execute()) {
        $erro = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Falha ao carregar cobrança: ' . $erro);
    }
    $res = $stmt->get_result();
    $cobranca = $res ? ($res->fetch_assoc() ?: null) : null;
    $stmt->close();
    return $cobranca;
}

function cobrancaBindParametros(mysqli_stmt $stmt, string $tipos, array &$parametros): void
{
    $referencias = [$tipos];
    foreach ($parametros as $chave => &$parametro) {
        $referencias[] = &$parametro;
    }
    call_user_func_array([$stmt, 'bind_param'], $referencias);
}

function cobrancaFiltrosEntrada(): array
{
    $idEmpresaEntrada = $_GET['id_empresa'] ?? '';
    $idEmpresaRaw = is_scalar($idEmpresaEntrada) ? trim((string)$idEmpresaEntrada) : '__invalido__';
    $idEmpresa = $idEmpresaRaw === '' ? null : cobrancaIdEntrada($_GET, 'id_empresa');
    if ($idEmpresaRaw !== '' && $idEmpresa === null) {
        out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Empresa inválida.'], 422);
    }

    $situacaoEntrada = $_GET['situacao'] ?? 'todas';
    $situacao = is_scalar($situacaoEntrada) ? strtolower(trim((string)$situacaoEntrada)) : '';
    if (!in_array($situacao, ['todas', 'pendente', 'vencida', 'paga', 'cancelada'], true)) {
        out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Situação inválida.'], 422);
    }

    $dataDeEntrada = $_GET['data_vencimento_de'] ?? '';
    $dataAteEntrada = $_GET['data_vencimento_ate'] ?? '';
    $dataDe = is_scalar($dataDeEntrada) ? trim((string)$dataDeEntrada) : '__invalido__';
    $dataAte = is_scalar($dataAteEntrada) ? trim((string)$dataAteEntrada) : '__invalido__';
    if ($dataDe !== '') cobrancaData($dataDe, 'data_vencimento_de');
    if ($dataAte !== '') cobrancaData($dataAte, 'data_vencimento_ate');
    if ($dataDe !== '' && $dataAte !== '' && $dataDe > $dataAte) {
        out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'O intervalo de vencimento é inválido.'], 422);
    }

    return ['id_empresa' => $idEmpresa, 'situacao' => $situacao, 'data_de' => $dataDe, 'data_ate' => $dataAte];
}

function cobrancaFiltrosSql(array $filtros, array &$parametros, string &$tipos): string
{
    $condicoes = ['1 = 1'];
    $parametros = [];
    $tipos = '';
    if ($filtros['id_empresa'] !== null) {
        $condicoes[] = 'c.id_empresa = ?';
        $parametros[] = $filtros['id_empresa'];
        $tipos .= 'i';
    }
    if ($filtros['data_de'] !== '') {
        $condicoes[] = 'c.data_vencimento >= ?';
        $parametros[] = $filtros['data_de'];
        $tipos .= 's';
    }
    if ($filtros['data_ate'] !== '') {
        $condicoes[] = 'c.data_vencimento <= ?';
        $parametros[] = $filtros['data_ate'];
        $tipos .= 's';
    }
    $situacao = $filtros['situacao'];
    if ($situacao === 'pendente') $condicoes[] = "c.status = 'pendente' AND c.data_vencimento >= CURDATE()";
    if ($situacao === 'vencida') $condicoes[] = "c.status = 'pendente' AND c.data_vencimento < CURDATE()";
    if ($situacao === 'paga') $condicoes[] = "c.status = 'paga'";
    if ($situacao === 'cancelada') $condicoes[] = "c.status = 'cancelada'";
    return implode(' AND ', $condicoes);
}

function listarPagamentos(mysqli $conexao): void
{
    $idCobranca = cobrancaIdEntrada($_GET, 'id_cobranca');
    if ($idCobranca === null) {
        out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Cobrança inválida.'], 422);
    }

    try {
        $cobranca = pagamentoCarregarCobranca($conexao, $idCobranca);
        if (!$cobranca) {
            out(['ok' => false, 'code' => 'COBRANCA_NAO_ENCONTRADA', 'user_msg' => 'Cobrança não encontrada.'], 404);
        }
        $totalCentavos = pagamentoTotalConfirmadoCentavos($conexao, $idCobranca);
        $stmt = $conexao->prepare(
            "SELECT id_pagamento, valor_pago,
                    DATE_FORMAT(data_pagamento, '%Y-%m-%d %H:%i:%s') AS data_pagamento,
                    forma_pagamento, origem, status, observacao
               FROM pagamento
              WHERE id_cobranca = ?
              ORDER BY data_pagamento DESC, id_pagamento DESC"
        );
        if (!$stmt) {
            throw new RuntimeException('Falha ao preparar listagem de pagamentos.');
        }
        $stmt->bind_param('i', $idCobranca);
        if (!$stmt->execute()) {
            $erro = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Falha ao listar pagamentos: ' . $erro);
        }
        $res = $stmt->get_result();
        $items = [];
        while ($row = $res->fetch_assoc()) {
            $items[] = [
                'id_pagamento' => (int)$row['id_pagamento'],
                'valor_pago' => (float)$row['valor_pago'],
                'data_pagamento' => (string)$row['data_pagamento'],
                'forma_pagamento' => (string)$row['forma_pagamento'],
                'origem' => (string)$row['origem'],
                'status' => (string)$row['status'],
                'observacao' => $row['observacao'] === null ? '' : (string)$row['observacao'],
            ];
        }
        $stmt->close();
        out([
            'ok' => true,
            'code' => 'PAGAMENTOS_LISTADOS',
            'user_msg' => 'Pagamentos listados com sucesso.',
            'data' => ['cobranca' => pagamentoRespostaResumo($cobranca, $totalCentavos), 'items' => $items],
        ]);
    } catch (Throwable $e) {
        error_log('LISTA_PAGAMENTO_EXCEPTION: ' . $e->getMessage());
        out(['ok' => false, 'code' => 'LISTA_PAGAMENTO_ERROR', 'user_msg' => 'Erro ao listar pagamentos.'], 500);
    }
}

function registrarPagamento(mysqli $conexao): void
{
    require_once __DIR__ . '/../../_auth/csrf.php';
    csrfValidarSessao();
    $idCobranca = cobrancaIdEntrada($_POST, 'id_cobranca');
    $valorRaw = $_POST['valor_pago'] ?? null;
    $forma = is_scalar($_POST['forma_pagamento'] ?? null) ? trim((string)$_POST['forma_pagamento']) : '';
    $dataRaw = is_scalar($_POST['data_pagamento'] ?? null) ? trim((string)$_POST['data_pagamento']) : date('Y-m-d');
    $observacao = is_scalar($_POST['observacao'] ?? null) ? trim((string)$_POST['observacao']) : '';
    if ($idCobranca === null || !is_scalar($valorRaw) || !in_array($forma, pagamentoFormasPermitidas(), true) || strlen($observacao) > 1000) {
        out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Revise os dados do pagamento.'], 422);
    }
    try {
        $valorCentavos = pagamentoValorCentavos((string)$valorRaw);
        if ($valorCentavos <= 0) {
            throw new RuntimeException('O valor do pagamento deve ser maior que zero.');
        }
        $dataPagamento = cobrancaData($dataRaw, 'data_pagamento');
        $dataPagamentoSql = $dataPagamento->format('Y-m-d') . ' 00:00:00';
        $valorSql = pagamentoCentavosDecimal($valorCentavos);
        $conexao->begin_transaction();
        $cobranca = pagamentoCarregarCobranca($conexao, $idCobranca, true);
        if (!$cobranca) {
            cobrancaErroTransacional($conexao, ['ok' => false, 'code' => 'COBRANCA_NAO_ENCONTRADA', 'user_msg' => 'Cobrança não encontrada.'], 404);
        }
        if ((string)$cobranca['status'] === 'cancelada') {
            cobrancaErroTransacional($conexao, ['ok' => false, 'code' => 'COBRANCA_CANCELADA', 'user_msg' => 'Não é possível pagar uma cobrança cancelada.'], 409);
        }
        $totalAntes = pagamentoTotalConfirmadoCentavos($conexao, $idCobranca, true);
        $valorCobranca = pagamentoValorCentavos((string)$cobranca['valor']);
        $saldoAntes = max(0, $valorCobranca - $totalAntes);
        if ($valorCentavos > $saldoAntes) {
            cobrancaErroTransacional($conexao, ['ok' => false, 'code' => 'PAGAMENTO_EXCEDE_SALDO', 'user_msg' => 'O pagamento excede o saldo restante da cobrança.'], 422);
        }
        $idEmpresa = (int)$cobranca['id_empresa'];
        $statusPagamento = 'confirmado';
        $origem = 'manual';
        $stmt = $conexao->prepare(
            "INSERT INTO pagamento
                (id_empresa, id_cobranca, valor_pago, data_pagamento, forma_pagamento, origem, status, provedor, referencia_externa, observacao)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?)"
        );
        if (!$stmt) {
            throw new RuntimeException('Falha ao preparar registro do pagamento.');
        }
        $stmt->bind_param('iissssss', $idEmpresa, $idCobranca, $valorSql, $dataPagamentoSql, $forma, $origem, $statusPagamento, $observacao);
        if (!$stmt->execute()) {
            $erro = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Falha ao registrar pagamento: ' . $erro);
        }
        $idPagamento = (int)$stmt->insert_id;
        $stmt->close();
        $totalDepois = pagamentoTotalConfirmadoCentavos($conexao, $idCobranca, true);
        $saldoDepois = max(0, $valorCobranca - $totalDepois);
        $statusCobranca = $saldoDepois === 0 ? 'paga' : 'pendente';
        $stmtStatus = $conexao->prepare("UPDATE cobranca SET status = ? WHERE id_cobranca = ? AND status <> 'cancelada'");
        if (!$stmtStatus) {
            throw new RuntimeException('Falha ao preparar atualização da cobrança.');
        }
        $stmtStatus->bind_param('si', $statusCobranca, $idCobranca);
        if (!$stmtStatus->execute()) {
            $erro = $stmtStatus->error;
            $stmtStatus->close();
            throw new RuntimeException('Falha ao atualizar situação da cobrança: ' . $erro);
        }
        $stmtStatus->close();
        auditoriaRegistrar($conexao, 'pagamento.registrado', [
            'ator' => auditoriaResolverAtorSuperAdmin($conexao, $idEmpresa),
            'entidade_id' => $idPagamento,
            'entidade_rotulo' => 'Pagamento da cobrança #' . $idCobranca,
            'descricao' => 'Registrou pagamento manual da empresa ' . (string)$cobranca['nome_empresa'] . '.',
            'alteracoes' => cobrancaAuditoriaAlteracoes([
                'id_empresa' => $idEmpresa, 'id_cobranca' => $idCobranca, 'id_pagamento' => $idPagamento,
                'valor_pago' => (float)$valorSql, 'data_pagamento' => $dataPagamentoSql,
                'forma_pagamento' => $forma, 'origem' => $origem, 'status' => $statusPagamento,
                'total_pago_confirmado' => (float)pagamentoCentavosDecimal($totalDepois),
                'saldo_restante' => (float)pagamentoCentavosDecimal($saldoDepois),
            ]),
            'contexto' => ['origem' => 'painel_super_admin', 'id_empresa' => $idEmpresa, 'id_cobranca' => $idCobranca],
        ]);
        // O contrato é reavaliado após o recálculo da cobrança: converte o trial quando a cobrança de
        // conversão fica integralmente paga e reativa a assinatura paga suspensa por inadimplência dessa
        // mesma cobrança. O serviço confirma a finalidade e é idempotente.
        $conversaoTrial = null;
        if ($saldoDepois === 0) {
            $conversaoTrial = assinaturaServicoReavaliarConversaoTrial(
                $conexao,
                $idCobranca,
                auditoriaResolverAtorSuperAdmin($conexao, $idEmpresa),
                ['origem' => 'painel_super_admin']
            );
        }
        $conexao->commit();
        $dadosResposta = ['id_pagamento' => $idPagamento, 'id_cobranca' => $idCobranca, 'valor_pago' => (float)$valorSql, 'total_pago_confirmado' => (float)pagamentoCentavosDecimal($totalDepois), 'saldo_restante' => (float)pagamentoCentavosDecimal($saldoDepois), 'status_cobranca' => $statusCobranca];
        if ($conversaoTrial !== null && ($conversaoTrial['convertida'] || $conversaoTrial['requer_atencao'])) {
            $dadosResposta['trial_convertido'] = (bool)$conversaoTrial['convertida'];
            if ($conversaoTrial['requer_atencao']) {
                $dadosResposta['conversao_trial_pendente'] = (string)$conversaoTrial['motivo'];
            }
        }
        out([
            'ok' => true,
            'code' => 'PAGAMENTO_REGISTRADO',
            'user_msg' => 'Pagamento registrado com sucesso.',
            'data' => $dadosResposta,
        ], 201);
    } catch (Throwable $e) {
        try {
            $conexao->rollback();
        } catch (Throwable) {
        }
        error_log('REGISTRA_PAGAMENTO_EXCEPTION: ' . $e->getMessage());
        out(['ok' => false, 'code' => 'REGISTRA_PAGAMENTO_ERROR', 'user_msg' => 'Erro ao registrar pagamento.'], 500);
    }
}

function alterarStatusPagamento(mysqli $conexao): void
{
    require_once __DIR__ . '/../../_auth/csrf.php';
    csrfValidarSessao();
    $idPagamento = cobrancaIdEntrada($_POST, 'id_pagamento');
    $novoStatus = is_scalar($_POST['status'] ?? null) ? trim((string)$_POST['status']) : '';
    if ($idPagamento === null || !in_array($novoStatus, ['cancelado', 'estornado'], true)) {
        out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Status de pagamento inválido.'], 422);
    }
    try {
        $stmtBusca = $conexao->prepare('SELECT id_cobranca FROM pagamento WHERE id_pagamento = ? LIMIT 1');
        if (!$stmtBusca) {
            throw new RuntimeException('Falha ao preparar localização do pagamento.');
        }
        $stmtBusca->bind_param('i', $idPagamento);
        if (!$stmtBusca->execute()) {
            $erro = $stmtBusca->error;
            $stmtBusca->close();
            throw new RuntimeException('Falha ao localizar pagamento: ' . $erro);
        }
        $resBusca = $stmtBusca->get_result();
        $pagamentoBase = $resBusca ? ($resBusca->fetch_assoc() ?: null) : null;
        $stmtBusca->close();
        if (!$pagamentoBase) {
            out(['ok' => false, 'code' => 'PAGAMENTO_NAO_ENCONTRADO', 'user_msg' => 'Pagamento não encontrado.'], 404);
        }
        $idCobranca = (int)$pagamentoBase['id_cobranca'];
        $conexao->begin_transaction();
        $cobranca = pagamentoCarregarCobranca($conexao, $idCobranca, true);
        if (!$cobranca) {
            cobrancaErroTransacional($conexao, ['ok' => false, 'code' => 'COBRANCA_NAO_ENCONTRADA', 'user_msg' => 'Cobrança não encontrada.'], 404);
        }
        $stmtPagamento = $conexao->prepare('SELECT id_pagamento, valor_pago, data_pagamento, forma_pagamento, origem, status FROM pagamento WHERE id_pagamento = ? AND id_cobranca = ? LIMIT 1 FOR UPDATE');
        if (!$stmtPagamento) {
            throw new RuntimeException('Falha ao preparar bloqueio do pagamento.');
        }
        $stmtPagamento->bind_param('ii', $idPagamento, $idCobranca);
        if (!$stmtPagamento->execute()) {
            $erro = $stmtPagamento->error;
            $stmtPagamento->close();
            throw new RuntimeException('Falha ao bloquear pagamento: ' . $erro);
        }
        $resPagamento = $stmtPagamento->get_result();
        $pagamento = $resPagamento ? ($resPagamento->fetch_assoc() ?: null) : null;
        $stmtPagamento->close();
        if (!$pagamento) {
            cobrancaErroTransacional($conexao, ['ok' => false, 'code' => 'PAGAMENTO_NAO_ENCONTRADO', 'user_msg' => 'Pagamento não encontrado.'], 404);
        }
        if ((string)$pagamento['status'] !== 'confirmado') {
            cobrancaErroTransacional($conexao, ['ok' => false, 'code' => 'PAGAMENTO_NAO_CONFIRMADO', 'user_msg' => 'Somente pagamentos confirmados podem ser cancelados ou estornados.'], 409);
        }
        if ((string)$pagamento['origem'] === 'gateway') {
            cobrancaErroTransacional($conexao, [
                'ok' => false,
                'code' => 'PAGAMENTO_GATEWAY_AUTORITATIVO',
                'user_msg' => 'Pagamentos do gateway só podem ser alterados pela conciliação do provedor.',
            ], 409);
        }
        $stmtAtualiza = $conexao->prepare('UPDATE pagamento SET status = ?, atualizado_em = NOW() WHERE id_pagamento = ?');
        if (!$stmtAtualiza) {
            throw new RuntimeException('Falha ao preparar alteração do pagamento.');
        }
        $stmtAtualiza->bind_param('si', $novoStatus, $idPagamento);
        if (!$stmtAtualiza->execute()) {
            $erro = $stmtAtualiza->error;
            $stmtAtualiza->close();
            throw new RuntimeException('Falha ao alterar status do pagamento: ' . $erro);
        }
        $stmtAtualiza->close();
        $totalDepois = pagamentoTotalConfirmadoCentavos($conexao, $idCobranca, true);
        $valorCobranca = pagamentoValorCentavos((string)$cobranca['valor']);
        $saldoDepois = max(0, $valorCobranca - $totalDepois);
        if ((string)$cobranca['status'] !== 'cancelada') {
            $statusCobranca = $saldoDepois === 0 ? 'paga' : 'pendente';
            $stmtStatus = $conexao->prepare("UPDATE cobranca SET status = ? WHERE id_cobranca = ? AND status <> 'cancelada'");
            if (!$stmtStatus) {
                throw new RuntimeException('Falha ao preparar recálculo da cobrança.');
            }
            $stmtStatus->bind_param('si', $statusCobranca, $idCobranca);
            if (!$stmtStatus->execute()) {
                $erro = $stmtStatus->error;
                $stmtStatus->close();
                throw new RuntimeException('Falha ao recalcular situação da cobrança: ' . $erro);
            }
            $stmtStatus->close();
        } else {
            $statusCobranca = 'cancelada';
        }
        $evento = $novoStatus === 'estornado' ? 'pagamento.estornado' : 'pagamento.cancelado';
        $alteracoes = cobrancaAuditoriaAlteracoes([
            'id_empresa' => (int)$cobranca['id_empresa'], 'id_cobranca' => $idCobranca, 'id_pagamento' => $idPagamento,
            'valor_pago' => (float)$pagamento['valor_pago'], 'data_pagamento' => (string)$pagamento['data_pagamento'],
            'forma_pagamento' => (string)$pagamento['forma_pagamento'], 'origem' => (string)$pagamento['origem'],
            'status' => $novoStatus,
            'total_pago_confirmado' => (float)pagamentoCentavosDecimal($totalDepois), 'saldo_restante' => (float)pagamentoCentavosDecimal($saldoDepois),
        ]);
        $alteracoes['status'] = ['antes' => (string)$pagamento['status'], 'depois' => $novoStatus];
        auditoriaRegistrar($conexao, $evento, [
            'ator' => auditoriaResolverAtorSuperAdmin($conexao, (int)$cobranca['id_empresa']),
            'entidade_id' => $idPagamento,
            'entidade_rotulo' => 'Pagamento da cobrança #' . $idCobranca,
            'descricao' => ($novoStatus === 'estornado' ? 'Estornou' : 'Cancelou') . ' pagamento da empresa ' . (string)$cobranca['nome_empresa'] . '.',
            'alteracoes' => $alteracoes,
            'contexto' => ['origem' => 'painel_super_admin', 'id_empresa' => (int)$cobranca['id_empresa'], 'id_cobranca' => $idCobranca],
        ]);
        // Depois do recálculo: se a cobrança de conversão deixou de estar integralmente paga, a assinatura
        // já convertida é suspensa por inadimplência (nunca volta a teste). Neutro para outras cobranças.
        $contrato = assinaturaServicoReavaliarConversaoTrial(
            $conexao,
            $idCobranca,
            auditoriaResolverAtorSuperAdmin($conexao, (int)$cobranca['id_empresa']),
            ['origem' => 'painel_super_admin']
        );
        $conexao->commit();
        $dadosPagamento = ['id_pagamento' => $idPagamento, 'id_cobranca' => $idCobranca, 'total_pago_confirmado' => (float)pagamentoCentavosDecimal($totalDepois), 'saldo_restante' => (float)pagamentoCentavosDecimal($saldoDepois), 'status_cobranca' => $statusCobranca];
        if ($contrato['suspensa']) {
            $dadosPagamento['assinatura_suspensa_inadimplencia'] = true;
        }
        if ($contrato['requer_atencao']) {
            $dadosPagamento['conversao_trial_pendente'] = (string)$contrato['motivo'];
        }
        out([
            'ok' => true,
            'code' => strtoupper('PAGAMENTO_' . $novoStatus),
            'user_msg' => $novoStatus === 'estornado' ? 'Pagamento estornado com sucesso.' : 'Pagamento cancelado com sucesso.',
            'data' => $dadosPagamento,
        ]);
    } catch (Throwable $e) {
        try {
            $conexao->rollback();
        } catch (Throwable) {
        }
        error_log('STATUS_PAGAMENTO_EXCEPTION: ' . $e->getMessage());
        out(['ok' => false, 'code' => 'STATUS_PAGAMENTO_ERROR', 'user_msg' => 'Erro ao alterar pagamento.'], 500);
    }
}

function cancelarCobranca(mysqli $conexao): void
{
    require_once __DIR__ . '/../../_auth/csrf.php';
    csrfValidarSessao();
    $idCobranca = cobrancaIdEntrada($_POST, 'id_cobranca');
    $motivo = is_scalar($_POST['motivo'] ?? null) ? trim((string)$_POST['motivo']) : '';
    if ($idCobranca === null || $motivo === '' || strlen($motivo) > 500) {
        out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Informe um motivo curto para cancelar a cobrança.'], 422);
    }

    try {
        $conexao->begin_transaction();
        $cobranca = pagamentoCarregarCobranca($conexao, $idCobranca, true);
        if (!$cobranca) {
            cobrancaErroTransacional($conexao, ['ok' => false, 'code' => 'COBRANCA_NAO_ENCONTRADA', 'user_msg' => 'Cobrança não encontrada.'], 404);
        }
        $totalConfirmado = pagamentoTotalConfirmadoCentavos($conexao, $idCobranca, true);
        // Uma baixa confirmada é autoridade financeira; cancelar a cobrança não pode desfazê-la.
        if ($totalConfirmado > 0) {
            cobrancaErroTransacional($conexao, [
                'ok' => false,
                'code' => 'COBRANCA_COM_PAGAMENTO_CONFIRMADO',
                'user_msg' => 'A cobrança possui pagamento confirmado. Cancele ou estorne os pagamentos antes.',
            ], 409);
        }
        if ((string)$cobranca['status'] !== 'pendente') {
            cobrancaErroTransacional($conexao, [
                'ok' => false,
                'code' => 'COBRANCA_NAO_PENDENTE',
                'user_msg' => 'Somente cobranças pendentes ou vencidas podem ser canceladas.',
            ], 409);
        }

        $statusAnterior = (string)$cobranca['status'];
        $statusNovo = 'cancelada';
        $stmt = $conexao->prepare("UPDATE cobranca SET status = ? WHERE id_cobranca = ? AND status = 'pendente'");
        if (!$stmt) {
            throw new RuntimeException('Falha ao preparar cancelamento da cobrança.');
        }
        $stmt->bind_param('si', $statusNovo, $idCobranca);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $erro = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Falha ao cancelar cobrança: ' . $erro);
        }
        $stmt->close();

        $alteracoes = cobrancaAuditoriaAlteracoes([
            'id_empresa' => (int)$cobranca['id_empresa'],
            'id_assinatura' => (int)$cobranca['id_assinatura'],
            'id_cobranca' => $idCobranca,
            'valor' => (float)$cobranca['valor'],
            'periodo_inicio' => (string)$cobranca['periodo_inicio'],
            'periodo_fim' => (string)$cobranca['periodo_fim'],
            'data_vencimento' => (string)$cobranca['data_vencimento'],
            'status' => $statusNovo,
            'motivo' => $motivo,
        ]);
        $alteracoes['status'] = ['antes' => $statusAnterior, 'depois' => $statusNovo];
        auditoriaRegistrar($conexao, 'cobranca.cancelada', [
            'ator' => auditoriaResolverAtorSuperAdmin($conexao, (int)$cobranca['id_empresa']),
            'entidade_id' => $idCobranca,
            'entidade_rotulo' => 'Cobrança da empresa ' . (string)$cobranca['nome_empresa'],
            'descricao' => 'Cancelou cobrança da empresa ' . (string)$cobranca['nome_empresa'] . '.',
            'alteracoes' => $alteracoes,
            'contexto' => ['origem' => 'painel_super_admin', 'motivo' => $motivo],
        ]);
        $conexao->commit();
        out([
            'ok' => true,
            'code' => 'COBRANCA_CANCELADA',
            'user_msg' => 'Cobrança cancelada com sucesso.',
            'data' => ['id_cobranca' => $idCobranca, 'status' => $statusNovo],
        ]);
    } catch (Throwable $e) {
        try {
            $conexao->rollback();
        } catch (Throwable) {
        }
        error_log('CANCELA_COBRANCA_EXCEPTION: ' . $e->getMessage());
        out(['ok' => false, 'code' => 'CANCELA_COBRANCA_ERROR', 'user_msg' => 'Erro ao cancelar cobrança.'], 500);
    }
}

$metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($metodo, ['GET', 'POST'], true)) {
    out(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED', 'user_msg' => 'Método não permitido.'], 405);
}

require __DIR__ . '/../../_auth/bloquear.php';
require_once __DIR__ . '/../../_config/conexao.php';
require_once __DIR__ . '/../../_servicos/auditoria.php';
require_once __DIR__ . '/../../_servicos/assinatura.php';

if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
    out(['ok' => false, 'code' => 'DB_CONNECTION_ERROR', 'user_msg' => 'Falha ao conectar no banco.'], 500);
}
$conexao->set_charset('utf8mb4');
$rota = trim((string)($_GET['path'] ?? ''), "/ \t\n\r\0\x0B");

if ($metodo === 'GET' && $rota === 'superadmin/cobranca/pagamentos') {
    listarPagamentos($conexao);
}
if ($metodo === 'POST' && $rota === 'superadmin/cobranca/pagamento/registrar') {
    registrarPagamento($conexao);
}
if ($metodo === 'POST' && $rota === 'superadmin/cobranca/pagamento/status') {
    alterarStatusPagamento($conexao);
}
if ($metodo === 'POST' && $rota === 'superadmin/cobranca/cancelar') {
    cancelarCobranca($conexao);
}

if ($metodo === 'GET') {
    try {
        try {
            $filtros = cobrancaFiltrosEntrada();
        } catch (RuntimeException $e) {
            out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => $e->getMessage()], 422);
        }
        $parametros = [];
        $tipos = '';
        $where = cobrancaFiltrosSql($filtros, $parametros, $tipos);
        $from = " FROM cobranca c
                  INNER JOIN empresa e ON e.id_empresa = c.id_empresa
                  INNER JOIN assinatura a ON a.id_assinatura = c.id_assinatura
                  LEFT JOIN plano p ON p.id_plano = a.id_plano
                  LEFT JOIN (
                      SELECT id_cobranca,
                             SUM(CASE WHEN status = 'confirmado' THEN valor_pago ELSE 0 END) AS total_pago_confirmado
                        FROM pagamento
                       GROUP BY id_cobranca
                  ) pg ON pg.id_cobranca = c.id_cobranca
                 WHERE {$where}";

        $stmtResumo = $conexao->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN c.status <> 'cancelada' AND c.valor - COALESCE(pg.total_pago_confirmado, 0) > 0
                    THEN c.valor - COALESCE(pg.total_pago_confirmado, 0) ELSE 0 END), 0) AS a_receber,
                COALESCE(SUM(COALESCE(pg.total_pago_confirmado, 0)), 0) AS recebido,
                COALESCE(SUM(CASE WHEN c.status = 'pendente' AND c.data_vencimento < CURDATE()
                    AND c.valor - COALESCE(pg.total_pago_confirmado, 0) > 0
                    THEN c.valor - COALESCE(pg.total_pago_confirmado, 0) ELSE 0 END), 0) AS vencido,
                COUNT(c.id_cobranca) AS total_cobrancas
             {$from}"
        );
        if (!$stmtResumo) {
            throw new RuntimeException('Falha ao preparar resumo financeiro.');
        }
        $parametrosResumo = $parametros;
        if ($tipos !== '') cobrancaBindParametros($stmtResumo, $tipos, $parametrosResumo);
        if (!$stmtResumo->execute()) {
            $erro = $stmtResumo->error;
            $stmtResumo->close();
            throw new RuntimeException('Falha ao calcular resumo financeiro: ' . $erro);
        }
        $resResumo = $stmtResumo->get_result();
        $resumo = $resResumo ? ($resResumo->fetch_assoc() ?: []) : [];
        $stmtResumo->close();

        $limit = filter_var($_GET['limit'] ?? 100, FILTER_VALIDATE_INT);
        $limit = $limit === false ? 100 : max(1, min(200, $limit));
        $sql = "SELECT c.id_cobranca, c.id_empresa, e.nome AS nome_empresa,
                       c.id_assinatura, p.nome AS plano_nome,
                       DATE_FORMAT(c.periodo_inicio, '%Y-%m-%d') AS periodo_inicio,
                       DATE_FORMAT(c.periodo_fim, '%Y-%m-%d') AS periodo_fim,
                       DATE_FORMAT(c.data_vencimento, '%Y-%m-%d') AS data_vencimento,
                       c.valor, c.status, COALESCE(pg.total_pago_confirmado, 0) AS total_pago_confirmado,
                       DATE_FORMAT(c.criado_em, '%Y-%m-%d %H:%i:%s') AS criado_em
                 {$from}
              ORDER BY c.data_vencimento DESC, c.id_cobranca DESC
                 LIMIT ?";
        $stmt = $conexao->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Falha ao preparar listagem de cobranças.');
        }
        $parametrosLista = $parametros;
        $parametrosLista[] = $limit;
        cobrancaBindParametros($stmt, $tipos . 'i', $parametrosLista);
        if (!$stmt->execute()) {
            $erro = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Falha ao listar cobranças: ' . $erro);
        }
        $res = $stmt->get_result();
        $hoje = date('Y-m-d');
        $items = [];
        while ($row = $res->fetch_assoc()) {
            $status = (string)$row['status'];
            $vencida = $status === 'pendente' && (string)$row['data_vencimento'] < $hoje;
            $valorCentavos = pagamentoValorCentavos((string)$row['valor']);
            $totalPagoCentavos = pagamentoValorCentavos((string)$row['total_pago_confirmado']);
            $items[] = [
                'id_cobranca' => (int)$row['id_cobranca'], 'id_empresa' => (int)$row['id_empresa'],
                'nome_empresa' => (string)$row['nome_empresa'], 'id_assinatura' => (int)$row['id_assinatura'],
                'plano_nome' => $row['plano_nome'] === null ? null : (string)$row['plano_nome'],
                'periodo_inicio' => (string)$row['periodo_inicio'], 'periodo_fim' => (string)$row['periodo_fim'],
                'data_vencimento' => (string)$row['data_vencimento'], 'valor' => (float)$row['valor'],
                'total_pago_confirmado' => (float)pagamentoCentavosDecimal($totalPagoCentavos),
                'saldo_restante' => (float)pagamentoCentavosDecimal(max(0, $valorCentavos - $totalPagoCentavos)),
                'status' => $status, 'situacao' => $vencida ? 'vencida' : $status,
                'criado_em' => $row['criado_em'] === null ? null : (string)$row['criado_em'],
            ];
        }
        $stmt->close();
        out(['ok' => true, 'code' => 'COBRANCAS_LISTADAS', 'user_msg' => 'Cobranças listadas com sucesso.', 'data' => [
            'items' => $items,
            'limit' => $limit,
            'filtros' => $filtros,
            'resumo' => [
                'a_receber' => (float)pagamentoCentavosDecimal(pagamentoValorCentavos((string)($resumo['a_receber'] ?? '0.00'))),
                'recebido' => (float)pagamentoCentavosDecimal(pagamentoValorCentavos((string)($resumo['recebido'] ?? '0.00'))),
                'vencido' => (float)pagamentoCentavosDecimal(pagamentoValorCentavos((string)($resumo['vencido'] ?? '0.00'))),
                'total_cobrancas' => (int)($resumo['total_cobrancas'] ?? 0),
            ],
        ]]);
    } catch (Throwable $e) {
        error_log('LISTA_COBRANCA_EXCEPTION: ' . $e->getMessage());
        out(['ok' => false, 'code' => 'LISTA_COBRANCA_ERROR', 'user_msg' => 'Erro ao listar cobranças.'], 500);
    }
}

require_once __DIR__ . '/../../_auth/csrf.php';
csrfValidarSessao();
$idEmpresa = cobrancaIdEmpresaEntrada();
if ($idEmpresa === null) {
    out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Informe uma empresa válida.', 'fields' => ['id_empresa' => 'Empresa inválida.']], 422);
}

try {
    $conexao->begin_transaction();
    $stmtEmpresa = $conexao->prepare("SELECT id_empresa, nome FROM empresa WHERE id_empresa = ? AND status = 'ativo' LIMIT 1 FOR UPDATE");
    if (!$stmtEmpresa) {
        throw new RuntimeException('Falha ao preparar validação da empresa.');
    }
    $stmtEmpresa->bind_param('i', $idEmpresa);
    if (!$stmtEmpresa->execute()) {
        $erro = $stmtEmpresa->error;
        $stmtEmpresa->close();
        throw new RuntimeException('Falha ao validar empresa: ' . $erro);
    }
    $resEmpresa = $stmtEmpresa->get_result();
    $empresa = $resEmpresa ? ($resEmpresa->fetch_assoc() ?: null) : null;
    $stmtEmpresa->close();
    if (!$empresa) {
        cobrancaErroTransacional($conexao, ['ok' => false, 'code' => 'EMPRESA_NAO_ENCONTRADA', 'user_msg' => 'Empresa não encontrada.'], 404);
    }
    $stmtAssinaturas = $conexao->prepare("SELECT id_assinatura, id_plano, valor_contratado, periodicidade, dia_vencimento,
                DATE_FORMAT(data_inicio, '%Y-%m-%d') AS data_inicio,
                modalidade, teste_iniciado_em, teste_expira_em
           FROM assinatura WHERE id_empresa = ? AND status = 'ativa' ORDER BY id_assinatura DESC FOR UPDATE");
    if (!$stmtAssinaturas) {
        throw new RuntimeException('Falha ao preparar assinatura ativa.');
    }
    $stmtAssinaturas->bind_param('i', $idEmpresa);
    if (!$stmtAssinaturas->execute()) {
        $erro = $stmtAssinaturas->error;
        $stmtAssinaturas->close();
        throw new RuntimeException('Falha ao localizar assinatura ativa: ' . $erro);
    }
    $resAssinaturas = $stmtAssinaturas->get_result();
    $assinaturas = $resAssinaturas ? $resAssinaturas->fetch_all(MYSQLI_ASSOC) : [];
    $stmtAssinaturas->close();
    if (count($assinaturas) === 0) {
        // Esta tela gera apenas cobranças regulares. Trial expirado é regularizado pelo proprietário, no
        // Faturamento do painel da empresa, com a cobrança de conversão; aqui nada é gerado.
        $stmtRegularizacao = $conexao->prepare("SELECT 1 FROM assinatura WHERE id_empresa = ? AND status = 'suspensa' AND modalidade = 'teste' AND motivo_suspensao = 'teste_expirado' LIMIT 1");
        if (!$stmtRegularizacao) {
            throw new RuntimeException('Falha ao preparar verificação de regularização.');
        }
        $stmtRegularizacao->bind_param('i', $idEmpresa);
        if (!$stmtRegularizacao->execute()) {
            $erro = $stmtRegularizacao->error;
            $stmtRegularizacao->close();
            throw new RuntimeException('Falha ao verificar regularização: ' . $erro);
        }
        $resRegularizacao = $stmtRegularizacao->get_result();
        $emRegularizacao = $resRegularizacao && $resRegularizacao->fetch_row() !== null;
        $stmtRegularizacao->close();
        if ($emRegularizacao) {
            cobrancaErroTransacional($conexao, ['ok' => false, 'code' => 'ASSINATURA_EM_REGULARIZACAO', 'user_msg' => 'O período de teste desta empresa expirou. A cobrança de regularização deve ser gerada pelo proprietário, na aba Faturamento do painel da empresa.'], 409);
        }
        cobrancaErroTransacional($conexao, ['ok' => false, 'code' => 'ASSINATURA_ATIVA_NAO_ENCONTRADA', 'user_msg' => 'A empresa não possui assinatura ativa.'], 409);
    }
    if (count($assinaturas) > 1) {
        cobrancaErroTransacional($conexao, ['ok' => false, 'code' => 'MULTIPLAS_ASSINATURAS_ATIVAS', 'user_msg' => 'A empresa possui mais de uma assinatura ativa.'], 409);
    }
    $assinatura = $assinaturas[0];
    if (!assinaturaServicoPlanoGeraCobranca($conexao, (int)$assinatura['id_assinatura'], true)) {
        cobrancaErroTransacional($conexao, [
            'ok' => false,
            'code' => 'PLANO_SEM_COBRANCA',
            'user_msg' => 'O plano contratual desta empresa não permite gerar novas cobranças.',
        ], 409);
    }
    if ((string)$assinatura['modalidade'] === 'teste') {
        if ($assinatura['teste_iniciado_em'] === null || $assinatura['teste_expira_em'] === null) {
            cobrancaErroTransacional($conexao, [
                'ok' => false,
                'code' => 'ASSINATURA_TRIAL_INCONSISTENTE',
                'user_msg' => 'Os dados do período de teste da assinatura precisam de verificação.',
            ], 409);
        }
        cobrancaErroTransacional($conexao, [
            'ok' => false,
            'code' => 'ASSINATURA_TRIAL_SEM_COBRANCA',
            'user_msg' => 'Não é possível gerar cobrança para uma assinatura em período de teste.',
        ], 409);
    }
    if ((string)$assinatura['modalidade'] !== 'paga') {
        cobrancaErroTransacional($conexao, [
            'ok' => false,
            'code' => 'ASSINATURA_MODALIDADE_INVALIDA',
            'user_msg' => 'A modalidade da assinatura precisa de verificação.',
        ], 409);
    }
    $idAssinatura = (int)$assinatura['id_assinatura'];
    $meses = cobrancaMesesPeriodicidade((string)$assinatura['periodicidade']);
    $dataInicioAssinatura = cobrancaData((string)$assinatura['data_inicio'], 'data_inicio da assinatura');
    $valor = (float)$assinatura['valor_contratado'];
    if ($valor < 0) {
        throw new RuntimeException('Valor contratado da assinatura inválido.');
    }
    $stmtUltima = $conexao->prepare('SELECT periodo_fim FROM cobranca WHERE id_assinatura = ? ORDER BY periodo_fim DESC, id_cobranca DESC LIMIT 1 FOR UPDATE');
    if (!$stmtUltima) {
        throw new RuntimeException('Falha ao preparar busca do último período.');
    }
    $stmtUltima->bind_param('i', $idAssinatura);
    if (!$stmtUltima->execute()) {
        $erro = $stmtUltima->error;
        $stmtUltima->close();
        throw new RuntimeException('Falha ao localizar último período: ' . $erro);
    }
    $resUltima = $stmtUltima->get_result();
    $ultima = $resUltima ? ($resUltima->fetch_assoc() ?: null) : null;
    $stmtUltima->close();
    $periodoInicio = $ultima ? cobrancaData((string)$ultima['periodo_fim'], 'periodo_fim da cobrança anterior')->modify('+1 day') : $dataInicioAssinatura;
    $periodoFim = $periodoInicio->modify('+' . $meses . ' months')->modify('-1 day');
    $dataVencimento = cobrancaCalcularVencimento($periodoInicio, (int)$assinatura['dia_vencimento']);
    $periodoInicioSql = $periodoInicio->format('Y-m-d');
    $periodoFimSql = $periodoFim->format('Y-m-d');
    $dataVencimentoSql = $dataVencimento->format('Y-m-d');
    $stmtDuplicidade = $conexao->prepare('SELECT id_cobranca FROM cobranca WHERE id_assinatura = ? AND periodo_inicio = ? AND periodo_fim = ? LIMIT 1');
    if (!$stmtDuplicidade) {
        throw new RuntimeException('Falha ao preparar validação de duplicidade.');
    }
    $stmtDuplicidade->bind_param('iss', $idAssinatura, $periodoInicioSql, $periodoFimSql);
    if (!$stmtDuplicidade->execute()) {
        $erro = $stmtDuplicidade->error;
        $stmtDuplicidade->close();
        throw new RuntimeException('Falha ao validar duplicidade: ' . $erro);
    }
    $stmtDuplicidade->store_result();
    $duplicada = $stmtDuplicidade->num_rows > 0;
    $stmtDuplicidade->close();
    if ($duplicada) {
        cobrancaErroTransacional($conexao, ['ok' => false, 'code' => 'COBRANCA_DUPLICADA', 'user_msg' => 'Já existe cobrança para o próximo período da assinatura.'], 409);
    }
    $status = 'pendente';
    $stmtInsert = $conexao->prepare('INSERT INTO cobranca (id_empresa, id_assinatura, periodo_inicio, periodo_fim, data_vencimento, valor, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
    if (!$stmtInsert) {
        throw new RuntimeException('Falha ao preparar criação da cobrança.');
    }
    $stmtInsert->bind_param('iisssds', $idEmpresa, $idAssinatura, $periodoInicioSql, $periodoFimSql, $dataVencimentoSql, $valor, $status);
    if (!$stmtInsert->execute()) {
        $errno = (int)$stmtInsert->errno;
        $erro = $stmtInsert->error;
        $stmtInsert->close();
        if ($errno === 1062) {
            cobrancaErroTransacional($conexao, ['ok' => false, 'code' => 'COBRANCA_DUPLICADA', 'user_msg' => 'Já existe cobrança para o próximo período da assinatura.'], 409);
        }
        throw new RuntimeException('Falha ao criar cobrança: ' . $erro);
    }
    $idCobranca = (int)$stmtInsert->insert_id;
    $stmtInsert->close();
    auditoriaRegistrar($conexao, 'cobranca.gerada', [
        'ator' => auditoriaResolverAtorSuperAdmin($conexao, $idEmpresa), 'entidade_id' => $idCobranca,
        'entidade_rotulo' => 'Cobrança da empresa ' . (string)$empresa['nome'], 'descricao' => 'Gerou cobrança para a empresa ' . (string)$empresa['nome'] . '.',
        'alteracoes' => cobrancaAuditoriaAlteracoes(['id_empresa' => $idEmpresa, 'id_assinatura' => $idAssinatura, 'periodo_inicio' => $periodoInicioSql, 'periodo_fim' => $periodoFimSql, 'data_vencimento' => $dataVencimentoSql, 'valor' => $valor, 'status' => $status]),
        'contexto' => ['origem' => 'painel_super_admin'],
    ]);
    $conexao->commit();
    out(['ok' => true, 'code' => 'COBRANCA_CRIADA', 'user_msg' => 'Cobrança gerada com sucesso.', 'data' => ['id_cobranca' => $idCobranca, 'id_empresa' => $idEmpresa, 'id_assinatura' => $idAssinatura, 'periodo_inicio' => $periodoInicioSql, 'periodo_fim' => $periodoFimSql, 'data_vencimento' => $dataVencimentoSql, 'valor' => $valor, 'status' => $status, 'situacao' => $status]], 201);
} catch (Throwable $e) {
    try {
        $conexao->rollback();
    } catch (Throwable) {
    }
    error_log('GERAR_COBRANCA_EXCEPTION: ' . $e->getMessage());
    out(['ok' => false, 'code' => 'GERAR_COBRANCA_ERROR', 'user_msg' => 'Erro ao gerar cobrança.'], 500);
}
