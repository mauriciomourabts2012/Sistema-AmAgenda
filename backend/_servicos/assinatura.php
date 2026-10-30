<?php
declare(strict_types=1);

require_once __DIR__ . '/auditoria.php';
require_once __DIR__ . '/pagamento_gateway.php';

/** Falha de regra contratual da regularização, com código e mensagem seguros para a resposta pública. */
final class AssinaturaServicoConversaoErro extends RuntimeException
{
    public function __construct(string $codigo, public readonly string $mensagemUsuario, public readonly int $httpStatus)
    {
        parent::__construct($codigo);
    }
}

function assinaturaServicoDataValida(string $valor, string $formato): bool
{
    $data = DateTimeImmutable::createFromFormat('!' . $formato, $valor);
    $erros = DateTimeImmutable::getLastErrors();

    return $data !== false
        && ($erros === false || ($erros['warning_count'] === 0 && $erros['error_count'] === 0))
        && $data->format($formato) === $valor;
}

/**
 * Informa se o plano contratual da assinatura participa dos fluxos financeiros.
 * A decisão é exclusivamente de plano.gera_cobranca; preço, nome e referência
 * não são usados como regra funcional.
 */
function assinaturaServicoPlanoGeraCobranca(mysqli $conexao, int $idAssinatura, bool $bloquear = false): bool
{
    if ($idAssinatura <= 0) {
        return false;
    }

    $sufixoBloqueio = $bloquear ? ' FOR UPDATE' : '';
    $stmt = $conexao->prepare(
        "SELECT p.gera_cobranca
           FROM assinatura a
           INNER JOIN plano p ON p.id_plano = a.id_plano
          WHERE a.id_assinatura = ?
          LIMIT 1{$sufixoBloqueio}"
    );
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar a validação financeira do plano.');
    }

    $stmt->bind_param('i', $idAssinatura);
    if (!$stmt->execute()) {
        $erro = (string)$stmt->error;
        $stmt->close();
        throw new RuntimeException('Falha ao validar a regra financeira do plano. Erro: ' . $erro);
    }

    $resultado = $stmt->get_result();
    $plano = $resultado ? ($resultado->fetch_assoc() ?: null) : null;
    $stmt->close();

    return $plano !== null && (int)$plano['gera_cobranca'] === 1;
}

/**
 * Cria uma assinatura paga ou de teste na transacao mantida pelo chamador.
 */
function assinaturaServicoCriar(mysqli $conexao, array $dados): array
{
    $idEmpresa = (int)($dados['id_empresa'] ?? 0);
    $idPlano = (int)($dados['id_plano'] ?? 0);
    $valor = (float)($dados['valor_contratado'] ?? 0);
    $periodicidade = (string)($dados['periodicidade'] ?? '');
    $diaVencimento = (int)($dados['dia_vencimento'] ?? 0);
    $dataInicio = (string)($dados['data_inicio'] ?? '');
    $status = (string)($dados['status'] ?? '');
    $modalidade = (string)($dados['modalidade'] ?? 'paga');
    $testeIniciadoEm = null;
    $testeExpiraEm = null;

    if ($idEmpresa <= 0 || $idPlano <= 0 || $valor < 0 || $dataInicio === '' || $status === '') {
        throw new InvalidArgumentException('Dados insuficientes para criar a assinatura.');
    }
    if (!assinaturaServicoDataValida($dataInicio, 'Y-m-d')) {
        throw new InvalidArgumentException('Data de inicio invalida para criar a assinatura.');
    }
    if (!in_array($periodicidade, ASSINATURA_SERVICO_PERIODICIDADES, true)) {
        throw new InvalidArgumentException('Periodicidade invalida para criar a assinatura.');
    }
    if ($diaVencimento < 1 || $diaVencimento > 28) {
        throw new InvalidArgumentException('Dia de vencimento invalido para criar a assinatura.');
    }
    if (!in_array($modalidade, ['paga', 'teste'], true)) {
        throw new InvalidArgumentException('Modalidade invalida para criar a assinatura.');
    }

    if ($modalidade === 'teste') {
        $testeIniciadoEm = (string)($dados['teste_iniciado_em'] ?? '');
        $testeExpiraEm = (string)($dados['teste_expira_em'] ?? '');
        $inicioTeste = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $testeIniciadoEm);
        $fimTeste = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $testeExpiraEm);
        if (!assinaturaServicoDataValida($testeIniciadoEm, 'Y-m-d H:i:s')
            || !assinaturaServicoDataValida($testeExpiraEm, 'Y-m-d H:i:s')
            || !$inicioTeste
            || !$fimTeste
            || $fimTeste <= $inicioTeste) {
            throw new InvalidArgumentException('Periodo de teste invalido para criar a assinatura.');
        }
        $stmt = $conexao->prepare(
            'INSERT INTO assinatura
                (id_empresa, id_plano, valor_contratado, periodicidade, dia_vencimento, data_inicio, data_fim,
                 status, modalidade, teste_iniciado_em, teste_expira_em)
             VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('Prepare insert assinatura falhou.');
        }
        $stmt->bind_param(
            'iidsisssss',
            $idEmpresa,
            $idPlano,
            $valor,
            $periodicidade,
            $diaVencimento,
            $dataInicio,
            $status,
            $modalidade,
            $testeIniciadoEm,
            $testeExpiraEm
        );
    } else {
        $stmt = $conexao->prepare(
            'INSERT INTO assinatura
                (id_empresa, id_plano, valor_contratado, periodicidade, dia_vencimento, data_inicio, data_fim, status)
             VALUES (?, ?, ?, ?, ?, ?, NULL, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('Prepare insert assinatura falhou.');
        }
        $stmt->bind_param('iidsiss', $idEmpresa, $idPlano, $valor, $periodicidade, $diaVencimento, $dataInicio, $status);
    }

    if (!$stmt->execute()) {
        $erro = (string)$stmt->error;
        $stmt->close();
        throw new RuntimeException('Erro ao criar assinatura. Erro: ' . $erro);
    }
    $idAssinatura = (int)$stmt->insert_id;
    $stmt->close();

    return [
        'id_assinatura' => $idAssinatura,
        'id_empresa' => $idEmpresa,
        'id_plano' => $idPlano,
        'valor_contratado' => $valor,
        'periodicidade' => $periodicidade,
        'dia_vencimento' => $diaVencimento,
        'data_inicio' => $dataInicio,
        'status' => $status,
        'modalidade' => $modalidade,
        'teste_iniciado_em' => $testeIniciadoEm,
        'teste_expira_em' => $testeExpiraEm,
    ];
}

/**
 * Lista referências de trials que podem ter a expiração persistida.
 * A elegibilidade é confirmada novamente sob lock no momento da alteração.
 *
 * @return list<int>
 */
function assinaturaServicoListarTestesExpirados(mysqli $conexao): array
{
    $resultado = $conexao->query(
        "SELECT id_assinatura
         FROM assinatura
         WHERE status = 'ativa'
           AND modalidade = 'teste'
           AND teste_expira_em IS NOT NULL
           AND CURRENT_TIMESTAMP >= teste_expira_em
         ORDER BY id_assinatura ASC"
    );

    if ($resultado === false) {
        throw new RuntimeException('Não foi possível consultar os testes expirados.');
    }

    $ids = [];
    while ($linha = $resultado->fetch_assoc()) {
        $idAssinatura = (int)($linha['id_assinatura'] ?? 0);
        if ($idAssinatura > 0) {
            $ids[] = $idAssinatura;
        }
    }
    $resultado->free();

    return $ids;
}

/**
 * Suspende um trial expirado na transação mantida pelo chamador.
 * Retorna null quando outra execução já tratou a assinatura ou ela deixou de ser elegível.
 *
 * @return array<string, int|string|null>|null
 */
function assinaturaServicoSuspenderTesteExpirado(mysqli $conexao, int $idAssinatura): ?array
{
    if ($idAssinatura <= 0) {
        throw new InvalidArgumentException('Assinatura inválida para expiração do teste.');
    }

    $consulta = $conexao->prepare(
        "SELECT id_assinatura, id_empresa, id_plano, status, modalidade, motivo_suspensao,
                DATE_FORMAT(teste_iniciado_em, '%Y-%m-%d %H:%i:%s') AS teste_iniciado_em,
                DATE_FORMAT(teste_expira_em, '%Y-%m-%d %H:%i:%s') AS teste_expira_em,
                DATE_FORMAT(suspensa_em, '%Y-%m-%d %H:%i:%s') AS suspensa_em_anterior
         FROM assinatura
         WHERE id_assinatura = ?
           AND status = 'ativa'
           AND modalidade = 'teste'
           AND teste_expira_em IS NOT NULL
           AND CURRENT_TIMESTAMP >= teste_expira_em
         LIMIT 1
         FOR UPDATE"
    );
    if (!$consulta) {
        throw new RuntimeException('Falha ao preparar a consulta do teste expirado.');
    }
    $consulta->bind_param('i', $idAssinatura);
    $consulta->execute();
    $assinatura = $consulta->get_result()->fetch_assoc();
    $consulta->close();

    if ($assinatura === null) {
        return null;
    }

    $atualizar = $conexao->prepare(
        "UPDATE assinatura
         SET status = 'suspensa',
             motivo_suspensao = 'teste_expirado',
             suspensa_em = CURRENT_TIMESTAMP
         WHERE id_assinatura = ?
           AND status = 'ativa'
           AND modalidade = 'teste'
           AND teste_expira_em IS NOT NULL
           AND CURRENT_TIMESTAMP >= teste_expira_em"
    );
    if (!$atualizar) {
        throw new RuntimeException('Falha ao preparar a suspensão do teste expirado.');
    }
    $atualizar->bind_param('i', $idAssinatura);
    $atualizar->execute();
    $alterada = $atualizar->affected_rows === 1;
    $atualizar->close();

    if (!$alterada) {
        return null;
    }

    $consultaSuspensao = $conexao->prepare(
        "SELECT DATE_FORMAT(suspensa_em, '%Y-%m-%d %H:%i:%s') AS suspensa_em
         FROM assinatura
         WHERE id_assinatura = ?
         LIMIT 1"
    );
    if (!$consultaSuspensao) {
        throw new RuntimeException('Falha ao consultar o instante da suspensão.');
    }
    $consultaSuspensao->bind_param('i', $idAssinatura);
    $consultaSuspensao->execute();
    $suspensao = $consultaSuspensao->get_result()->fetch_assoc();
    $consultaSuspensao->close();

    if ($suspensao === null || $suspensao['suspensa_em'] === null) {
        throw new RuntimeException('A suspensão do teste não retornou o instante persistido.');
    }

    return [
        'id_assinatura' => (int)$assinatura['id_assinatura'],
        'id_empresa' => (int)$assinatura['id_empresa'],
        'id_plano' => (int)$assinatura['id_plano'],
        'status_anterior' => (string)$assinatura['status'],
        'status_novo' => 'suspensa',
        'modalidade' => (string)$assinatura['modalidade'],
        'teste_iniciado_em' => $assinatura['teste_iniciado_em'],
        'teste_expira_em' => $assinatura['teste_expira_em'],
        'motivo_suspensao_anterior' => $assinatura['motivo_suspensao'],
        'motivo_suspensao' => 'teste_expirado',
        'suspensa_em_anterior' => $assinatura['suspensa_em_anterior'],
        'suspensa_em' => (string)$suspensao['suspensa_em'],
    ];
}

/**
 * Executa uma consulta preparada e devolve todas as linhas.
 *
 * @return list<array<string, mixed>>
 */
function assinaturaServicoLinhas(mysqli $conexao, string $sql, string $tipos = '', mixed ...$valores): array
{
    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar consulta contratual.');
    }
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$valores);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Falha ao executar consulta contratual.');
    }
    $resultado = $stmt->get_result();
    $linhas = $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $linhas;
}

function assinaturaServicoMesesPeriodicidade(string $periodicidade): int
{
    return match ($periodicidade) {
        'mensal' => 1,
        'trimestral' => 3,
        'semestral' => 6,
        'anual' => 12,
        default => throw new RuntimeException('Periodicidade da assinatura inválida.'),
    };
}

/**
 * Periodicidades aceitas para plano (`plano.cobranca`) e contrato (`assinatura.periodicidade`). A do contrato é copiada
 * do plano na contratação e não muda depois: editar o plano não converte contratos nem Preapprovals existentes.
 */
const ASSINATURA_SERVICO_PERIODICIDADES = ['diaria', 'mensal', 'trimestral', 'semestral', 'anual'];

/**
 * Homologação: novas assinaturas diárias cobrem dois dias por competência. O ciclo fica materializado no primeiro
 * período financeiro (e, antes dele, na referência da Preapproval), portanto reduzir esta constante para 1 volta o
 * padrão das novas contratações sem reescrever histórico nem converter autorizações existentes.
 */
const ASSINATURA_SERVICO_DIARIA_DIAS_NOVOS_CONTRATOS = 2;

/** Periodicidade contratual diária; a duração concreta da competência pode ser 1 ou 2 dias. */
function assinaturaServicoPeriodicidadeDiaria(string $periodicidade): bool
{
    return $periodicidade === 'diaria';
}

/** Duração inclusiva de um período diário já persistido (marcador financeiro do ciclo 1/days ou 2/days). */
function assinaturaServicoDiasPeriodoDiario(string $inicio, string $fim): int
{
    if (!assinaturaServicoDataValida($inicio, 'Y-m-d') || !assinaturaServicoDataValida($fim, 'Y-m-d')) {
        throw new RuntimeException('Período diário inválido.');
    }
    $dias = (int)(new DateTimeImmutable($inicio))->diff(new DateTimeImmutable($fim))->format('%r%a') + 1;
    if (!in_array($dias, [1, 2], true)) {
        throw new RuntimeException('Duração da competência diária incompatível com o ciclo financeiro.');
    }

    return $dias;
}

/** Último dia da competência que começa em `$inicio`, pela periodicidade do contrato. */
function assinaturaServicoFimCompetencia(
    DateTimeImmutable $inicio,
    string $periodicidade,
    int $diasDiaria = ASSINATURA_SERVICO_DIARIA_DIAS_NOVOS_CONTRATOS
): DateTimeImmutable
{
    if (assinaturaServicoPeriodicidadeDiaria($periodicidade)) {
        if (!in_array($diasDiaria, [1, 2], true)) {
            throw new RuntimeException('Ciclo diário inválido.');
        }

        return $inicio->modify('+' . ($diasDiaria - 1) . ' days');
    }

    return $inicio->modify('+' . assinaturaServicoMesesPeriodicidade($periodicidade) . ' months')->modify('-1 day');
}

/** Vencimento da competência: o próprio dia na periodicidade diária; senão, o dia de vencimento contratado. */
function assinaturaServicoVencimentoCompetencia(DateTimeImmutable $inicio, string $periodicidade, int $diaVencimento): DateTimeImmutable
{
    return assinaturaServicoPeriodicidadeDiaria($periodicidade) ? $inicio : assinaturaServicoCalcularVencimento($inicio, $diaVencimento);
}

/** Mesma regra do gerador de cobranças: primeiro vencimento no dia contratado, nunca antes do início do período. */
function assinaturaServicoCalcularVencimento(DateTimeImmutable $periodoInicio, int $diaVencimento): DateTimeImmutable
{
    if ($diaVencimento < 1 || $diaVencimento > 28) {
        throw new RuntimeException('Dia de vencimento da assinatura inválido.');
    }

    $primeiroMes = $periodoInicio->modify('first day of this month');
    $vencimento = $primeiroMes->setDate((int)$primeiroMes->format('Y'), (int)$primeiroMes->format('m'), $diaVencimento);
    if ($vencimento < $periodoInicio) {
        $proximoMes = $primeiroMes->modify('+1 month');
        $vencimento = $proximoMes->setDate((int)$proximoMes->format('Y'), (int)$proximoMes->format('m'), $diaVencimento);
    }

    return $vencimento;
}

/** Soma os pagamentos confirmados da cobrança em centavos, sem float. Deve ser chamada dentro da transação do chamador. */
function assinaturaServicoTotalPagoCentavos(mysqli $conexao, int $idEmpresa, int $idCobranca): int
{
    $linhas = assinaturaServicoLinhas(
        $conexao,
        "SELECT valor_pago FROM pagamento WHERE id_empresa = ? AND id_cobranca = ? AND status = 'confirmado'",
        'ii',
        $idEmpresa,
        $idCobranca
    );
    $total = 0;
    foreach ($linhas as $linha) {
        $total += pagamentoGatewayValorCentavos((string)$linha['valor_pago']);
    }

    return $total;
}

/**
 * Momento em que os pagamentos confirmados registrados no AmAgenda completaram o valor da cobrança.
 * As linhas devem estar ordenadas por criado_em e id_pagamento; pagamentos ainda não confirmados não participam.
 *
 * @param list<array{valor_pago: mixed, criado_em: mixed}> $pagamentos
 */
function assinaturaServicoDataQuitacaoRegistrada(array $pagamentos, int $valorCobrancaCentavos): DateTimeImmutable
{
    if ($valorCobrancaCentavos <= 0) {
        throw new RuntimeException('Valor inválido para determinar a confirmação financeira.');
    }

    $totalCentavos = 0;
    foreach ($pagamentos as $pagamento) {
        $totalCentavos += pagamentoGatewayValorCentavos((string)($pagamento['valor_pago'] ?? ''));
        if ($totalCentavos < $valorCobrancaCentavos) {
            continue;
        }

        $confirmacao = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string)($pagamento['criado_em'] ?? ''));
        if ($confirmacao === false) {
            throw new RuntimeException('Data inválida no registro da confirmação financeira.');
        }

        return $confirmacao;
    }

    throw new RuntimeException('Os pagamentos confirmados não completam o valor da cobrança.');
}

/**
 * Periodicidade diária: o período começa no dia da confirmação e conserva o ciclo financeiro (1 ou 2 dias).
 *
 * @return array{inicio: string, fim: string}
 */
function assinaturaServicoPeriodoConfirmado(
    DateTimeImmutable $confirmacao,
    string $periodicidade,
    int $diasDiaria = ASSINATURA_SERVICO_DIARIA_DIAS_NOVOS_CONTRATOS
): array
{
    $inicio = $confirmacao->setTime(0, 0);
    if (assinaturaServicoPeriodicidadeDiaria($periodicidade)) {
        return [
            'inicio' => $inicio->format('Y-m-d'),
            'fim' => assinaturaServicoFimCompetencia($inicio, $periodicidade, $diasDiaria)->format('Y-m-d'),
        ];
    }
    $meses = assinaturaServicoMesesPeriodicidade($periodicidade);
    $primeiroDiaDestino = $inicio->modify('first day of this month')->modify("+{$meses} months");
    $diaDestino = min((int)$inicio->format('d'), (int)$primeiroDiaDestino->format('t'));
    $proximoInicio = $primeiroDiaDestino->setDate(
        (int)$primeiroDiaDestino->format('Y'),
        (int)$primeiroDiaDestino->format('m'),
        $diaDestino
    );

    return [
        'inicio' => $inicio->format('Y-m-d'),
        'fim' => $proximoInicio->modify('-1 day')->format('Y-m-d'),
    ];
}

/** @return array{inicio: string, fim: string, vencimento: string} */
function assinaturaServicoOfertaContratacaoDatas(
    DateTimeImmutable $contratacao,
    string $periodicidade,
    int $diasDiaria = ASSINATURA_SERVICO_DIARIA_DIAS_NOVOS_CONTRATOS
): array
{
    $periodo = assinaturaServicoPeriodoConfirmado($contratacao, $periodicidade, $diasDiaria);

    return $periodo + ['vencimento' => $periodo['inicio']];
}

/** Deve ser chamada dentro da transação que conclui a contratação, após o lock da cobrança. */
function assinaturaServicoPeriodoQuitadoDaOferta(
    mysqli $conexao,
    int $idEmpresa,
    int $idCobranca,
    int $valorCobrancaCentavos,
    string $periodicidade,
    int $diasDiaria
): array {
    $pagamentos = assinaturaServicoLinhas(
        $conexao,
        "SELECT valor_pago, DATE_FORMAT(criado_em, '%Y-%m-%d %H:%i:%s') AS criado_em
           FROM pagamento
          WHERE id_empresa = ? AND id_cobranca = ? AND status = 'confirmado'
          ORDER BY criado_em, id_pagamento
          FOR UPDATE",
        'ii',
        $idEmpresa,
        $idCobranca
    );
    $confirmacao = assinaturaServicoDataQuitacaoRegistrada($pagamentos, $valorCobrancaCentavos);

    return assinaturaServicoPeriodoConfirmado($confirmacao, $periodicidade, $diasDiaria);
}

/** Último dia de cobertura contínua e integralmente paga a partir de hoje. */
function assinaturaServicoFimPeriodoPago(mysqli $conexao, int $idEmpresa, int $idAssinatura): ?string
{
    $hoje = date('Y-m-d');
    $cobrancas = assinaturaServicoLinhas(
        $conexao,
        "SELECT id_cobranca, periodo_inicio, periodo_fim, valor, status
           FROM cobranca
          WHERE id_empresa = ? AND id_assinatura = ?
            AND finalidade IN ('regular', 'conversao_trial')
          ORDER BY periodo_inicio, id_cobranca FOR UPDATE",
        'ii', $idEmpresa, $idAssinatura
    );
    $fim = null;
    foreach ($cobrancas as $cobranca) {
        if ((string)$cobranca['status'] !== 'paga'
            || assinaturaServicoTotalPagoCentavos($conexao, $idEmpresa, (int)$cobranca['id_cobranca'])
                < pagamentoGatewayValorCentavos((string)$cobranca['valor'])) {
            continue;
        }
        $inicio = (string)$cobranca['periodo_inicio'];
        $termino = (string)$cobranca['periodo_fim'];
        if ($fim === null) {
            if ($inicio <= $hoje && $termino >= $hoje) $fim = $termino;
        } elseif ($inicio <= (new DateTimeImmutable($fim))->modify('+1 day')->format('Y-m-d') && $termino > $fim) {
            $fim = $termino;
        }
    }
    return $fim;
}

/** Prévia somente leitura da elegibilidade para o cancelamento ao fim do período pago. */
function assinaturaServicoPreviaCancelamento(mysqli $conexao, int $idEmpresa, int $idAssinatura): array
{
    $assinaturas = assinaturaServicoLinhas(
        $conexao,
        "SELECT status, modalidade, data_fim
           FROM assinatura
          WHERE id_empresa = ? AND id_assinatura = ?
          LIMIT 1",
        'ii',
        $idEmpresa,
        $idAssinatura
    );
    $assinatura = $assinaturas[0] ?? null;
    if ($assinatura === null
        || (string)$assinatura['status'] !== 'ativa'
        || (string)$assinatura['modalidade'] !== 'paga'
        || $assinatura['data_fim'] !== null) {
        return ['pode_fim_periodo_pago' => false, 'data_fim_periodo_pago' => null];
    }

    $dataFim = assinaturaServicoFimPeriodoPago($conexao, $idEmpresa, $idAssinatura);
    return ['pode_fim_periodo_pago' => $dataFim !== null, 'data_fim_periodo_pago' => $dataFim];
}

/**
 * @param array<string, mixed> $linha
 * @return array<string, int|string|bool>
 */
function assinaturaServicoCobrancaConversaoResumo(mysqli $conexao, array $linha, bool $criada): array
{
    $valorCentavos = pagamentoGatewayValorCentavos((string)$linha['valor']);
    $pagoCentavos = assinaturaServicoTotalPagoCentavos($conexao, (int)$linha['id_empresa'], (int)$linha['id_cobranca']);

    return [
        'id_cobranca' => (int)$linha['id_cobranca'],
        'id_empresa' => (int)$linha['id_empresa'],
        'id_assinatura' => (int)$linha['id_assinatura'],
        'finalidade' => 'conversao_trial',
        'periodo_inicio' => (string)$linha['periodo_inicio'],
        'periodo_fim' => (string)$linha['periodo_fim'],
        'data_vencimento' => (string)$linha['data_vencimento'],
        'valor' => pagamentoGatewayValorDecimal($valorCentavos),
        'status' => (string)$linha['status'],
        'total_pago_confirmado' => pagamentoGatewayValorDecimal($pagoCentavos),
        'saldo_restante' => pagamentoGatewayValorDecimal(max(0, $valorCentavos - $pagoCentavos)),
        'criada' => $criada,
    ];
}

/**
 * Localiza a única cobrança de conversão ativa da assinatura.
 * Usa leitura sem bloqueio: o chamador já mantém o lock da assinatura, que serializa os criadores.
 *
 * @return array<string, mixed>|null
 */
function assinaturaServicoBuscarCobrancaConversaoAtiva(mysqli $conexao, int $idAssinatura): ?array
{
    $linhas = assinaturaServicoLinhas(
        $conexao,
        "SELECT id_cobranca, id_empresa, id_assinatura, valor, status,
                DATE_FORMAT(periodo_inicio, '%Y-%m-%d') AS periodo_inicio,
                DATE_FORMAT(periodo_fim, '%Y-%m-%d') AS periodo_fim,
                DATE_FORMAT(data_vencimento, '%Y-%m-%d') AS data_vencimento
           FROM cobranca
          WHERE id_assinatura = ?
            AND finalidade = 'conversao_trial'
            AND status <> 'cancelada'
          ORDER BY id_cobranca DESC",
        'i',
        $idAssinatura
    );
    if (count($linhas) > 1) {
        throw new AssinaturaServicoConversaoErro(
            'COBRANCA_CONVERSAO_INCONSISTENTE',
            'A regularização precisa de verificação do suporte.',
            409
        );
    }

    return $linhas[0] ?? null;
}

/**
 * Cobrança de conversão aberta pelo cartão do teste grátis (tem transação 'assinatura_preapproval') que ficou sem
 * pagamento: pendente, nada confirmado e nenhuma transação em andamento ou em conciliação. Na transação do chamador.
 *
 * @param array<string, mixed> $cobranca retorno de assinaturaServicoBuscarCobrancaConversaoAtiva()
 */
function assinaturaServicoConversaoDoCartaoEncerrada(mysqli $conexao, int $idEmpresa, array $cobranca): bool
{
    $idCobranca = (int)$cobranca['id_cobranca'];
    if ((string)$cobranca['status'] !== 'pendente' || assinaturaServicoTotalPagoCentavos($conexao, $idEmpresa, $idCobranca) > 0) {
        return false;
    }
    $transacoes = assinaturaServicoLinhas(
        $conexao,
        "SELECT tipo_operacao, status, requer_conciliacao FROM transacao_pagamento
          WHERE id_empresa = ? AND id_cobranca = ? FOR UPDATE",
        'ii',
        $idEmpresa,
        $idCobranca
    );
    $doCartao = false;
    foreach ($transacoes as $transacao) {
        if (in_array((string)$transacao['status'], ['criada', 'enviando', 'aguardando', 'processando', 'conciliacao', 'confirmada'], true)
            || (int)$transacao['requer_conciliacao'] === 1) {
            return false;
        }
        $doCartao = $doCartao || (string)$transacao['tipo_operacao'] === 'assinatura_preapproval';
    }

    return $doCartao;
}

/** Cancela (auditado) a cobrança de conversão do cartão encerrado. Na transação do chamador. */
function assinaturaServicoCancelarConversaoDoCartao(mysqli $conexao, int $idEmpresa, array $cobranca): void
{
    $idCobranca = (int)$cobranca['id_cobranca'];
    $stmt = $conexao->prepare("UPDATE cobranca SET status = 'cancelada' WHERE id_cobranca = ? AND id_empresa = ? AND status = 'pendente' AND finalidade = 'conversao_trial'");
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar o cancelamento da cobrança de conversão do cartão.');
    }
    $stmt->bind_param('ii', $idCobranca, $idEmpresa);
    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('Falha ao cancelar a cobrança de conversão do cartão.');
    }
    $stmt->close();
    assinaturaServicoAuditar($conexao, 'cobranca.cancelada', [
        'entidade_id' => $idCobranca,
        'entidade_rotulo' => "Cobrança #{$idCobranca}",
        'descricao' => "Cancelou a cobrança de conversão #{$idCobranca}, não paga pelo cartão já encerrado, antes da regularização por PIX.",
        'alteracoes' => assinaturaServicoAlteracoesNovas([
            'id_empresa' => $idEmpresa,
            'id_assinatura' => (int)$cobranca['id_assinatura'],
            'id_cobranca' => $idCobranca,
            'valor' => (string)$cobranca['valor'],
            'periodo_inicio' => (string)$cobranca['periodo_inicio'],
            'periodo_fim' => (string)$cobranca['periodo_fim'],
            'motivo' => 'conversao_do_cartao_nao_paga',
        ]) + ['status' => ['antes' => 'pendente', 'depois' => 'cancelada']],
        'contexto' => ['origem' => 'regularizacao_trial', 'id_empresa' => $idEmpresa, 'id_assinatura' => (int)$cobranca['id_assinatura'],
            'motivo' => 'conversao_do_cartao_nao_paga'],
    ], null);
}

/**
 * Cria ou reutiliza a única cobrança de conversão do trial expirado da empresa.
 * Participa da transação do chamador (sem commit). Não registra auditoria: o chamador audita
 * somente quando 'criada' for verdadeiro.
 *
 * @return array<string, int|string|bool>
 */
function assinaturaServicoObterOuCriarCobrancaConversao(mysqli $conexao, int $idEmpresa): array
{
    if ($idEmpresa <= 0) {
        throw new InvalidArgumentException('Empresa inválida para a regularização.');
    }

    // O lock da assinatura serializa criações concorrentes e revalida o estado contratual.
    $assinaturas = assinaturaServicoLinhas(
        $conexao,
        "SELECT id_assinatura, id_empresa, id_plano, valor_contratado, periodicidade, dia_vencimento,
                status, modalidade, motivo_suspensao,
                teste_iniciado_em IS NOT NULL AND teste_expira_em IS NOT NULL
                    AND teste_expira_em > teste_iniciado_em AND CURRENT_TIMESTAMP >= teste_expira_em AS teste_expirado
           FROM assinatura
          WHERE id_empresa = ?
            AND status IN ('ativa', 'suspensa')
          ORDER BY id_assinatura DESC
          FOR UPDATE",
        'i',
        $idEmpresa
    );
    if (count($assinaturas) > 1) {
        throw new AssinaturaServicoConversaoErro(
            'ASSINATURA_INCONSISTENTE',
            'Não foi possível determinar sua assinatura vigente.',
            409
        );
    }
    $assinatura = $assinaturas[0] ?? null;
    if ($assinatura === null) {
        throw new AssinaturaServicoConversaoErro(
            'ASSINATURA_REGULARIZACAO_INDISPONIVEL',
            'Não há assinatura em regularização para esta empresa.',
            409
        );
    }
    if ((string)$assinatura['status'] === 'ativa' && (string)$assinatura['modalidade'] === 'paga') {
        throw new AssinaturaServicoConversaoErro(
            'ASSINATURA_JA_PAGA',
            'A assinatura já está regularizada.',
            409
        );
    }
    if ((string)$assinatura['status'] !== 'suspensa'
        || (string)$assinatura['modalidade'] !== 'teste'
        || (string)$assinatura['motivo_suspensao'] !== 'teste_expirado'
        || (int)$assinatura['teste_expirado'] !== 1) {
        throw new AssinaturaServicoConversaoErro(
            'ASSINATURA_REGULARIZACAO_INDISPONIVEL',
            'Não há assinatura em regularização para esta empresa.',
            409
        );
    }

    $idAssinatura = (int)$assinatura['id_assinatura'];

    // Cobrança regular ativa em assinatura de teste nunca converte e não pode ser ignorada por inferência.
    $regulares = assinaturaServicoLinhas(
        $conexao,
        "SELECT id_cobranca FROM cobranca
          WHERE id_assinatura = ? AND finalidade = 'regular' AND status <> 'cancelada'
          LIMIT 1",
        'i',
        $idAssinatura
    );
    if ($regulares !== []) {
        throw new AssinaturaServicoConversaoErro(
            'ASSINATURA_TRIAL_INCONSISTENTE',
            'Os dados da assinatura precisam de verificação do suporte antes da regularização.',
            409
        );
    }

    // Cartão autorizado durante o teste: enquanto a Preapproval puder cobrar a primeira mensalidade (ou sua criação estiver
    // em conciliação), o PIX não cria cobrança paralela para o mesmo período.
    if (assinaturaServicoPreapprovalPrimeiraCobrancaViva($conexao, $idEmpresa, $idAssinatura)) {
        throw new AssinaturaServicoConversaoErro(
            'PAGAMENTO_CARTAO_EM_ANDAMENTO',
            'A primeira mensalidade está sendo cobrada no cartão autorizado. Para pagar com PIX, use antes "Pagar com PIX em vez do cartão" e aguarde a confirmação do Mercado Pago.',
            409
        );
    }

    $existente = assinaturaServicoBuscarCobrancaConversaoAtiva($conexao, $idAssinatura);
    if ($existente !== null && assinaturaServicoConversaoDoCartaoEncerrada($conexao, $idEmpresa, $existente)) {
        // Competência aberta pelo cartão (fim do teste) que ele não quitou e cujo cartão já foi encerrado: sem pagamento nem
        // transação em andamento, ela é cancelada e o PIX segue a regra da regularização (período a partir de hoje).
        assinaturaServicoCancelarConversaoDoCartao($conexao, $idEmpresa, $existente);
        $existente = null;
    }
    if ($existente !== null) {
        return assinaturaServicoCobrancaConversaoResumo($conexao, $existente, false);
    }

    if (!assinaturaServicoPlanoGeraCobranca($conexao, $idAssinatura, true)) {
        throw new AssinaturaServicoConversaoErro(
            'PLANO_SEM_COBRANCA',
            'Não há cobrança de regularização disponível para esta assinatura.',
            409
        );
    }

    $valor = trim((string)$assinatura['valor_contratado']);
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $valor) || pagamentoGatewayValorCentavos($valor) <= 0) {
        throw new AssinaturaServicoConversaoErro(
            'ASSINATURA_VALOR_INVALIDO',
            'O valor contratado da assinatura precisa de verificação do suporte.',
            409
        );
    }

    // A data de criação é o início do período pago; o intervalo entre a expiração e a regularização não é cobrado.
    $linhaData = assinaturaServicoLinhas($conexao, "SELECT DATE_FORMAT(CURDATE(), '%Y-%m-%d') AS hoje");
    $periodoInicio = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($linhaData[0]['hoje'] ?? ''));
    if ($periodoInicio === false) {
        throw new RuntimeException('Não foi possível determinar a data da regularização.');
    }
    $diasDiaria = assinaturaServicoDiasCicloContrato(
        $conexao,
        $idEmpresa,
        $idAssinatura,
        (string)$assinatura['periodicidade']
    );
    $periodoFim = assinaturaServicoFimCompetencia($periodoInicio, (string)$assinatura['periodicidade'], $diasDiaria);
    $vencimento = assinaturaServicoVencimentoCompetencia($periodoInicio, (string)$assinatura['periodicidade'], (int)$assinatura['dia_vencimento']);
    $periodoInicioSql = $periodoInicio->format('Y-m-d');
    $periodoFimSql = $periodoFim->format('Y-m-d');
    $vencimentoSql = $vencimento->format('Y-m-d');

    $inserir = $conexao->prepare(
        "INSERT INTO cobranca
            (id_empresa, id_assinatura, periodo_inicio, periodo_fim, data_vencimento, valor, status, finalidade)
         VALUES (?, ?, ?, ?, ?, ?, 'pendente', 'conversao_trial')"
    );
    if (!$inserir) {
        throw new RuntimeException('Falha ao preparar a cobrança de conversão.');
    }
    $inserir->bind_param('iissss', $idEmpresa, $idAssinatura, $periodoInicioSql, $periodoFimSql, $vencimentoSql, $valor);

    $duplicada = false;
    try {
        if (!$inserir->execute()) {
            if ((int)$inserir->errno !== 1062) {
                $erro = (string)$inserir->error;
                $inserir->close();
                throw new RuntimeException('Falha ao criar a cobrança de conversão. Erro: ' . $erro);
            }
            $duplicada = true;
        }
    } catch (mysqli_sql_exception $erro) {
        if ((int)$erro->getCode() !== 1062) {
            $inserir->close();
            throw $erro;
        }
        $duplicada = true;
    }
    $idNovaCobranca = $duplicada ? 0 : (int)$conexao->insert_id;
    $inserir->close();

    if ($duplicada) {
        // Com a unicidade restrita a cobranças não canceladas, um 1062 só indica concorrência real ou
        // conflito ativo: uma cobrança cancelada do mesmo período não gera mais colisão. Relê o estado atual.
        $existente = assinaturaServicoBuscarCobrancaConversaoAtiva($conexao, $idAssinatura);
        if ($existente !== null) {
            // Outra execução criou a cobrança de conversão ativa: reutiliza de forma idempotente.
            return assinaturaServicoCobrancaConversaoResumo($conexao, $existente, false);
        }
        $conflitantes = assinaturaServicoLinhas(
            $conexao,
            "SELECT id_cobranca FROM cobranca
              WHERE id_assinatura = ? AND periodo_inicio = ? AND periodo_fim = ? AND status <> 'cancelada'
              LIMIT 1",
            'iss',
            $idAssinatura,
            $periodoInicioSql,
            $periodoFimSql
        );
        if ($conflitantes !== []) {
            throw new AssinaturaServicoConversaoErro(
                'COBRANCA_PERIODO_CONFLITANTE',
                'Existe outra cobrança ativa para o mesmo período. A regularização precisa de verificação do suporte.',
                409
            );
        }
        // Duplicidade sem cobrança ativa visível: estado estrutural inesperado; não é fluxo normal.
        throw new AssinaturaServicoConversaoErro(
            'COBRANCA_CONVERSAO_INCONSISTENTE',
            'A regularização precisa de verificação do suporte.',
            409
        );
    }
    if ($idNovaCobranca <= 0) {
        throw new RuntimeException('A cobrança de conversão não retornou identificador.');
    }

    return assinaturaServicoCobrancaConversaoResumo($conexao, [
        'id_cobranca' => $idNovaCobranca,
        'id_empresa' => $idEmpresa,
        'id_assinatura' => $idAssinatura,
        'periodo_inicio' => $periodoInicioSql,
        'periodo_fim' => $periodoFimSql,
        'data_vencimento' => $vencimentoSql,
        'valor' => $valor,
        'status' => 'pendente',
    ], true);
}

/**
 * Converte o trial em assinatura paga quando, e somente quando, a cobrança informada é de
 * finalidade 'conversao_trial' e está integralmente quitada pelos pagamentos confirmados.
 *
 * Deve ser chamada pelo fluxo financeiro autoritativo, na transação do chamador, depois do
 * recálculo da cobrança. Não confirma nem desfaz transações. A auditoria é registrada aqui,
 * uma única vez, apenas quando há transição real.
 *
 * Resultado: 'convertida' (transição feita agora), 'idempotente' (já estava paga/ativa),
 * 'motivo' (quando não converteu) e 'requer_atencao' (conversão devida, porém impedida).
 *
 * @param array<string, mixed> $ator Contrato de ator de auditoria já resolvido pelo chamador.
 * @param array<string, mixed> $contextoAuditoria
 * @return array<string, mixed>
 */
function assinaturaServicoConverterTrialPago(mysqli $conexao, int $idCobranca, array $ator, array $contextoAuditoria = []): array
{
    if ($idCobranca <= 0) {
        throw new InvalidArgumentException('Cobrança inválida para a conversão do teste.');
    }
    $nao = static fn (string $motivo, bool $atencao = false): array => [
        'convertida' => false,
        'idempotente' => false,
        'motivo' => $motivo,
        'requer_atencao' => $atencao,
    ];

    $cobrancas = assinaturaServicoLinhas(
        $conexao,
        "SELECT id_cobranca, id_empresa, id_assinatura, finalidade, status, valor,
                DATE_FORMAT(periodo_inicio, '%Y-%m-%d') AS periodo_inicio,
                DATE_FORMAT(periodo_fim, '%Y-%m-%d') AS periodo_fim
           FROM cobranca
          WHERE id_cobranca = ?
          LIMIT 1
          FOR UPDATE",
        'i',
        $idCobranca
    );
    $cobranca = $cobrancas[0] ?? null;
    if ($cobranca === null) {
        throw new RuntimeException('Cobrança não localizada para a conversão do teste.');
    }

    // Somente a finalidade explícita autoriza a conversão; cobrança regular nunca converte.
    if ((string)$cobranca['finalidade'] !== 'conversao_trial') {
        return $nao('cobranca_nao_e_conversao');
    }
    if ((string)$cobranca['status'] === 'cancelada') {
        return $nao('cobranca_cancelada', true);
    }

    $idEmpresa = (int)$cobranca['id_empresa'];
    $idAssinatura = (int)$cobranca['id_assinatura'];
    $valorCentavos = pagamentoGatewayValorCentavos((string)$cobranca['valor']);
    $pagoCentavos = assinaturaServicoTotalPagoCentavos($conexao, $idEmpresa, $idCobranca);
    if ((string)$cobranca['status'] !== 'paga' || $valorCentavos <= 0 || $pagoCentavos < $valorCentavos) {
        return $nao('saldo_pendente');
    }

    $assinaturas = assinaturaServicoLinhas(
        $conexao,
        "SELECT id_assinatura, id_empresa, id_plano, status, modalidade, motivo_suspensao,
                DATE_FORMAT(teste_iniciado_em, '%Y-%m-%d %H:%i:%s') AS teste_iniciado_em,
                DATE_FORMAT(teste_expira_em, '%Y-%m-%d %H:%i:%s') AS teste_expira_em,
                DATE_FORMAT(suspensa_em, '%Y-%m-%d %H:%i:%s') AS suspensa_em
           FROM assinatura
          WHERE id_assinatura = ? AND id_empresa = ?
          LIMIT 1
          FOR UPDATE",
        'ii',
        $idAssinatura,
        $idEmpresa
    );
    $assinatura = $assinaturas[0] ?? null;
    if ($assinatura === null) {
        return $nao('assinatura_inexistente', true);
    }

    $statusAnterior = (string)$assinatura['status'];
    if ($statusAnterior === 'ativa' && (string)$assinatura['modalidade'] === 'paga') {
        return [
            'convertida' => false,
            'idempotente' => true,
            'motivo' => 'ja_convertida',
            'requer_atencao' => false,
        ];
    }

    // Assinatura já convertida e suspensa por inadimplência: a reversão é tratada pela reavaliação do contrato.
    if ($statusAnterior === 'suspensa' && (string)$assinatura['modalidade'] === 'paga') {
        return $nao('assinatura_paga_suspensa');
    }

    $testeConfigurado = $assinatura['teste_iniciado_em'] !== null && $assinatura['teste_expira_em'] !== null;
    $estadoElegivel = (string)$assinatura['modalidade'] === 'teste'
        && $testeConfigurado
        && (($statusAnterior === 'suspensa' && (string)$assinatura['motivo_suspensao'] === 'teste_expirado')
            || $statusAnterior === 'ativa');
    if (!$estadoElegivel) {
        return $nao('assinatura_fora_do_estado_de_conversao', true);
    }

    $outrasAtivas = assinaturaServicoLinhas(
        $conexao,
        "SELECT id_assinatura FROM assinatura WHERE id_empresa = ? AND status = 'ativa' AND id_assinatura <> ? FOR UPDATE",
        'ii',
        $idEmpresa,
        $idAssinatura
    );
    if ($outrasAtivas !== []) {
        return $nao('outra_assinatura_ativa', true);
    }

    // Mesma proteção da reativação administrativa: não restaura contrato divergente do plano operacional.
    $empresas = assinaturaServicoLinhas($conexao, 'SELECT plano_id FROM empresa WHERE id_empresa = ? LIMIT 1', 'i', $idEmpresa);
    if (!isset($empresas[0]) || (int)$empresas[0]['plano_id'] !== (int)$assinatura['id_plano']) {
        return $nao('plano_divergente', true);
    }

    $atualizar = $conexao->prepare(
        "UPDATE assinatura
            SET status = 'ativa', modalidade = 'paga', motivo_suspensao = NULL, suspensa_em = NULL
          WHERE id_assinatura = ? AND id_empresa = ? AND modalidade = 'teste' AND status = ?"
    );
    if (!$atualizar) {
        throw new RuntimeException('Falha ao preparar a conversão do teste.');
    }
    $atualizar->bind_param('iis', $idAssinatura, $idEmpresa, $statusAnterior);
    if (!$atualizar->execute()) {
        $erro = (string)$atualizar->error;
        $atualizar->close();
        throw new RuntimeException('Falha ao converter o teste em assinatura paga. Erro: ' . $erro);
    }
    $alterada = $atualizar->affected_rows === 1;
    $atualizar->close();
    if (!$alterada) {
        throw new RuntimeException('A conversão do teste não alterou exatamente uma assinatura.');
    }

    $idPlano = (int)$assinatura['id_plano'];
    $valorDecimal = pagamentoGatewayValorDecimal($valorCentavos);
    $depois = static fn (mixed $valor): array => ['antes' => null, 'depois' => $valor];
    auditoriaRegistrar($conexao, 'assinatura.convertida_paga', [
        'ator' => $ator,
        'entidade_id' => $idAssinatura,
        'entidade_rotulo' => "Assinatura #{$idAssinatura}",
        'descricao' => "Converteu o período de teste da assinatura #{$idAssinatura} em assinatura paga.",
        'alteracoes' => [
            'id_empresa' => $depois($idEmpresa),
            'id_assinatura' => $depois($idAssinatura),
            'id_cobranca' => $depois($idCobranca),
            'id_plano' => $depois($idPlano),
            'status' => ['antes' => $statusAnterior, 'depois' => 'ativa'],
            'modalidade' => ['antes' => 'teste', 'depois' => 'paga'],
            'status_anterior' => $depois($statusAnterior),
            'status_novo' => $depois('ativa'),
            'modalidade_anterior' => $depois('teste'),
            'modalidade_nova' => $depois('paga'),
            'teste_iniciado_em' => $depois($assinatura['teste_iniciado_em']),
            'teste_expira_em' => $depois($assinatura['teste_expira_em']),
            'valor' => $depois($valorDecimal),
            'periodo_inicio' => $depois((string)$cobranca['periodo_inicio']),
            'periodo_fim' => $depois((string)$cobranca['periodo_fim']),
            'motivo_suspensao' => ['antes' => $assinatura['motivo_suspensao'], 'depois' => null],
            'suspensa_em' => ['antes' => $assinatura['suspensa_em'], 'depois' => null],
        ],
        'contexto' => array_merge($contextoAuditoria, [
            'id_empresa' => $idEmpresa,
            'id_assinatura' => $idAssinatura,
            'id_plano' => $idPlano,
            'status_anterior' => $statusAnterior,
            'status_novo' => 'ativa',
        ]),
    ]);

    return [
        'convertida' => true,
        'idempotente' => false,
        'motivo' => null,
        'requer_atencao' => false,
        'id_empresa' => $idEmpresa,
        'id_assinatura' => $idAssinatura,
        'id_cobranca' => $idCobranca,
        'status_anterior' => $statusAnterior,
        'status_novo' => 'ativa',
        'modalidade_anterior' => 'teste',
        'modalidade_nova' => 'paga',
    ];
}

/**
 * Evidência de que a cobrança informada converteu a assinatura: o evento de auditoria
 * 'assinatura.convertida_paga' registrado na própria transição aponta para esta cobrança.
 * Sem esse vínculo inequívoco, nenhuma suspensão ou reativação é feita automaticamente.
 */
function assinaturaServicoConversaoComprovada(mysqli $conexao, int $idEmpresa, int $idAssinatura, int $idCobranca): bool
{
    $linhas = assinaturaServicoLinhas(
        $conexao,
        "SELECT alteracoes FROM auditoria
          WHERE id_empresa = ? AND evento_codigo = 'assinatura.convertida_paga'
            AND entidade_tipo = 'assinatura' AND entidade_id = ?
          LIMIT 50",
        'ii',
        $idEmpresa,
        $idAssinatura
    );
    foreach ($linhas as $linha) {
        $dados = json_decode((string)$linha['alteracoes'], true);
        $depois = is_array($dados) && is_array($dados['id_cobranca'] ?? null) ? ($dados['id_cobranca']['depois'] ?? null) : null;
        if ($depois !== null && (int)$depois === $idCobranca) {
            return true;
        }
    }

    return false;
}

/**
 * Reavalia o contrato a partir da cobrança de conversão, depois do recálculo financeiro do chamador.
 *
 * - Cobrança integralmente paga: converte o trial (ainda em teste) ou reativa a assinatura paga
 *   suspensa por inadimplência que foi convertida por ESTA cobrança.
 * - Cobrança deixa de estar integralmente paga (estorno/cancelamento de pagamento): uma assinatura
 *   já convertida (modalidade 'paga', ativa) passa a 'suspensa' por 'inadimplencia'. A modalidade
 *   nunca volta a 'teste'; teste_* e data_inicio são preservados.
 * - Qualquer outro caso é neutro. Auditoria só em transição real (assinatura.suspensa/reativada).
 *
 * Deve ser chamada na transação do chamador, depois do recálculo e antes do commit.
 *
 * @param array<string, mixed> $ator
 * @param array<string, mixed> $contextoAuditoria
 * @return array<string, mixed> Mesmas chaves do conversor, mais 'suspensa' e 'reativada'.
 */
function assinaturaServicoReavaliarConversaoTrial(mysqli $conexao, int $idCobranca, array $ator, array $contextoAuditoria = []): array
{
    if ($idCobranca <= 0) {
        throw new InvalidArgumentException('Cobrança inválida para a reavaliação do contrato.');
    }
    $resultado = static fn (string $motivo, bool $atencao = false, array $extra = []): array => array_merge([
        'convertida' => false,
        'idempotente' => false,
        'suspensa' => false,
        'reativada' => false,
        'motivo' => $motivo,
        'requer_atencao' => $atencao,
    ], $extra);

    $cobrancas = assinaturaServicoLinhas(
        $conexao,
        'SELECT id_cobranca, id_empresa, id_assinatura, finalidade, status, valor FROM cobranca WHERE id_cobranca = ? LIMIT 1 FOR UPDATE',
        'i',
        $idCobranca
    );
    $cobranca = $cobrancas[0] ?? null;
    if ($cobranca === null) {
        throw new RuntimeException('Cobrança não localizada para a reavaliação do contrato.');
    }
    if ((string)$cobranca['finalidade'] !== 'conversao_trial' || (string)$cobranca['status'] === 'cancelada') {
        return array_merge($resultado('cobranca_nao_e_conversao'), assinaturaServicoConverterTrialPago($conexao, $idCobranca, $ator, $contextoAuditoria), ['suspensa' => false, 'reativada' => false]);
    }

    $idEmpresa = (int)$cobranca['id_empresa'];
    $idAssinatura = (int)$cobranca['id_assinatura'];
    $valorCentavos = pagamentoGatewayValorCentavos((string)$cobranca['valor']);
    $pagoCentavos = assinaturaServicoTotalPagoCentavos($conexao, $idEmpresa, $idCobranca);
    $quitada = $valorCentavos > 0 && $pagoCentavos >= $valorCentavos;

    if ($quitada) {
        $conversao = assinaturaServicoConverterTrialPago($conexao, $idCobranca, $ator, $contextoAuditoria);
        if ((string)($conversao['motivo'] ?? '') !== 'assinatura_paga_suspensa') {
            return array_merge($resultado('sem_alteracao'), $conversao, ['suspensa' => false, 'reativada' => false]);
        }
    }

    $assinaturas = assinaturaServicoLinhas(
        $conexao,
        "SELECT id_assinatura, id_plano, status, modalidade, motivo_suspensao, data_fim,
                DATE_FORMAT(suspensa_em, '%Y-%m-%d %H:%i:%s') AS suspensa_em
           FROM assinatura
          WHERE id_assinatura = ? AND id_empresa = ?
          LIMIT 1
          FOR UPDATE",
        'ii',
        $idAssinatura,
        $idEmpresa
    );
    $assinatura = $assinaturas[0] ?? null;
    if ($assinatura === null) {
        return $resultado('assinatura_inexistente', true);
    }
    $status = (string)$assinatura['status'];
    $modalidade = (string)$assinatura['modalidade'];
    $motivoAtual = $assinatura['motivo_suspensao'] === null ? null : (string)$assinatura['motivo_suspensao'];
    $idPlano = (int)$assinatura['id_plano'];
    $suspensaPorInadimplencia = $status === 'suspensa' && $modalidade === 'paga' && $motivoAtual === 'inadimplencia';
    if ($assinatura['data_fim'] !== null && (string)$assinatura['data_fim'] < date('Y-m-d')) {
        return $resultado('cancelamento_efetivo');
    }
    $depois = static fn (mixed $valor): array => ['antes' => null, 'depois' => $valor];

    if ($quitada) {
        // Reversão: somente para a assinatura paga suspensa por inadimplência desta mesma cobrança.
        if (!$suspensaPorInadimplencia) {
            return $resultado('sem_alteracao');
        }
        if (!assinaturaServicoConversaoComprovada($conexao, $idEmpresa, $idAssinatura, $idCobranca)) {
            return $resultado('conversao_nao_comprovada', true);
        }
        $outrasAtivas = assinaturaServicoLinhas(
            $conexao,
            "SELECT id_assinatura FROM assinatura WHERE id_empresa = ? AND status = 'ativa' AND id_assinatura <> ? FOR UPDATE",
            'ii',
            $idEmpresa,
            $idAssinatura
        );
        if ($outrasAtivas !== []) {
            return $resultado('outra_assinatura_ativa', true);
        }
        $empresas = assinaturaServicoLinhas($conexao, 'SELECT plano_id FROM empresa WHERE id_empresa = ? LIMIT 1', 'i', $idEmpresa);
        if (!isset($empresas[0]) || (int)$empresas[0]['plano_id'] !== $idPlano) {
            return $resultado('plano_divergente', true);
        }
        $atualizar = $conexao->prepare(
            "UPDATE assinatura SET status = 'ativa', motivo_suspensao = NULL, suspensa_em = NULL
              WHERE id_assinatura = ? AND id_empresa = ? AND status = 'suspensa' AND modalidade = 'paga' AND motivo_suspensao = 'inadimplencia'"
        );
        if (!$atualizar) {
            throw new RuntimeException('Falha ao preparar a reativação da assinatura paga.');
        }
        $atualizar->bind_param('ii', $idAssinatura, $idEmpresa);
        if (!$atualizar->execute()) {
            $erro = (string)$atualizar->error;
            $atualizar->close();
            throw new RuntimeException('Falha ao reativar a assinatura paga. Erro: ' . $erro);
        }
        $alterada = $atualizar->affected_rows === 1;
        $atualizar->close();
        if (!$alterada) {
            throw new RuntimeException('A reativação não alterou exatamente uma assinatura.');
        }
        auditoriaRegistrar($conexao, 'assinatura.reativada', [
            'ator' => $ator,
            'entidade_id' => $idAssinatura,
            'entidade_rotulo' => "Assinatura #{$idAssinatura}",
            'descricao' => "Reativou a assinatura #{$idAssinatura} após nova quitação integral da cobrança de conversão #{$idCobranca}.",
            'alteracoes' => [
                'id_empresa' => $depois($idEmpresa),
                'id_assinatura' => $depois($idAssinatura),
                'id_cobranca' => $depois($idCobranca),
                'id_plano' => $depois($idPlano),
                'status' => ['antes' => 'suspensa', 'depois' => 'ativa'],
                'modalidade' => $depois('paga'),
                'status_anterior' => $depois('suspensa'),
                'status_novo' => $depois('ativa'),
                'motivo_suspensao' => ['antes' => 'inadimplencia', 'depois' => null],
                'suspensa_em' => ['antes' => $assinatura['suspensa_em'], 'depois' => null],
            ],
            'contexto' => array_merge($contextoAuditoria, [
                'id_empresa' => $idEmpresa,
                'id_assinatura' => $idAssinatura,
                'id_plano' => $idPlano,
                'status_anterior' => 'suspensa',
                'status_novo' => 'ativa',
                'motivo' => 'conversao_trial_requitada',
            ]),
        ]);

        return $resultado(null, false, ['reativada' => true, 'id_assinatura' => $idAssinatura, 'id_cobranca' => $idCobranca]);
    }

    // Cobrança de conversão não está mais integralmente paga.
    if ($modalidade !== 'paga') {
        return $resultado('assinatura_nao_convertida'); // ainda em teste: nada foi convertido, nada a suspender
    }
    if ($suspensaPorInadimplencia) {
        return $resultado('ja_suspensa_inadimplencia', false, ['idempotente' => true]);
    }
    if ($status !== 'ativa') {
        return $resultado('assinatura_fora_do_estado_de_suspensao'); // cancelada ou suspensa por outro motivo: não alterar
    }
    if (!assinaturaServicoConversaoComprovada($conexao, $idEmpresa, $idAssinatura, $idCobranca)) {
        return $resultado('conversao_nao_comprovada', true);
    }

    $atualizar = $conexao->prepare(
        "UPDATE assinatura SET status = 'suspensa', motivo_suspensao = 'inadimplencia', suspensa_em = CURRENT_TIMESTAMP
          WHERE id_assinatura = ? AND id_empresa = ? AND status = 'ativa' AND modalidade = 'paga'"
    );
    if (!$atualizar) {
        throw new RuntimeException('Falha ao preparar a suspensão por inadimplência.');
    }
    $atualizar->bind_param('ii', $idAssinatura, $idEmpresa);
    if (!$atualizar->execute()) {
        $erro = (string)$atualizar->error;
        $atualizar->close();
        throw new RuntimeException('Falha ao suspender a assinatura por inadimplência. Erro: ' . $erro);
    }
    $alterada = $atualizar->affected_rows === 1;
    $atualizar->close();
    if (!$alterada) {
        throw new RuntimeException('A suspensão por inadimplência não alterou exatamente uma assinatura.');
    }
    $suspensas = assinaturaServicoLinhas(
        $conexao,
        "SELECT DATE_FORMAT(suspensa_em, '%Y-%m-%d %H:%i:%s') AS suspensa_em FROM assinatura WHERE id_assinatura = ? AND id_empresa = ? LIMIT 1",
        'ii',
        $idAssinatura,
        $idEmpresa
    );
    auditoriaRegistrar($conexao, 'assinatura.suspensa', [
        'ator' => $ator,
        'entidade_id' => $idAssinatura,
        'entidade_rotulo' => "Assinatura #{$idAssinatura}",
        'descricao' => "Suspendeu a assinatura #{$idAssinatura} por inadimplência: a cobrança de conversão #{$idCobranca} deixou de estar integralmente paga.",
        'alteracoes' => [
            'id_empresa' => $depois($idEmpresa),
            'id_assinatura' => $depois($idAssinatura),
            'id_cobranca' => $depois($idCobranca),
            'id_plano' => $depois($idPlano),
            'status' => ['antes' => 'ativa', 'depois' => 'suspensa'],
            'modalidade' => $depois('paga'),
            'status_anterior' => $depois('ativa'),
            'status_novo' => $depois('suspensa'),
            'motivo_suspensao' => ['antes' => null, 'depois' => 'inadimplencia'],
            'suspensa_em' => ['antes' => null, 'depois' => $suspensas[0]['suspensa_em'] ?? null],
        ],
        'contexto' => array_merge($contextoAuditoria, [
            'id_empresa' => $idEmpresa,
            'id_assinatura' => $idAssinatura,
            'id_plano' => $idPlano,
            'status_anterior' => 'ativa',
            'status_novo' => 'suspensa',
            'motivo' => 'conversao_trial_nao_quitada',
        ]),
    ]);

    return $resultado(null, false, ['suspensa' => true, 'id_assinatura' => $idAssinatura, 'id_cobranca' => $idCobranca]);
}

/**
 * Carência, em dias corridos completos, entre o vencimento e a suspensão automática por inadimplência.
 * Vencimento em D: D+1..D+5 são a carência; D+6 é o primeiro dia elegível. A carência não altera o vencimento
 * nem o status da cobrança, e não vale para a verificação administrativa de reativação.
 */
const ASSINATURA_SERVICO_CARENCIA_INADIMPLENCIA_DIAS = 5;

/**
 * Consulta preparada das regras financeiras do contrato. O errno do MySQL vira o código da exceção para que
 * o chamador distinga deadlock/timeout de lock (1213/1205) de outras falhas.
 *
 * @return list<array<string, mixed>>
 */
function assinaturaServicoConsultaFinanceira(mysqli $conexao, string $sql, string $tipos = '', mixed ...$valores): array
{
    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar a verificação financeira do contrato.', (int)$conexao->errno);
    }
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$valores);
    }
    if (!$stmt->execute()) {
        $errno = (int)$stmt->errno;
        $stmt->close();
        throw new RuntimeException('Falha na verificação financeira do contrato.', $errno);
    }
    $resultado = $stmt->get_result();
    $linhas = $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $linhas;
}

/**
 * Regra financeira central do contrato, compartilhada por suspensão, regularização e reativação administrativa.
 * Deve rodar na transação do chamador, com empresa e assinatura já bloqueadas.
 *
 * Bloqueia (FOR UPDATE) as cobranças não canceladas da empresa, inclusive de contratos anteriores, antes de
 * ler pagamentos e transações; assim a decisão usa o estado financeiro atual e serializa com baixa, estorno
 * e conciliação.
 *
 * - 'divida': cobrança pendente, vencida (vencimento anterior à data atual, recuado em $carenciaDias dias
 *   corridos; 0 = vencida desde o dia seguinte ao vencimento) e com saldo maior que zero depois dos pagamentos
 *   confirmados. Estorno devolve o saldo; transação criada ou enviada não abate saldo.
 *   Com $conversaoPendenteImpede, cobrança de conversão de trial com saldo também conta, mesmo não vencida.
 * - 'incerto': cobrança pendente já coberta por pagamentos confirmados (recálculo ainda não aplicado) ou
 *   transação em andamento/conciliação. Nunca é tratado como quitação nem como dívida comprovada.
 * Ofertas (ASSINATURA_SERVICO_SQL_COBRANCA_OFERTA) continuam bloqueadas, mas não contam como dívida.
 *
 * @return array{tipo: string, codigo: string, mensagem: string, id_cobranca: int|null}|null
 */
function assinaturaServicoPendenciaFinanceira(mysqli $conexao, int $idEmpresa, bool $conversaoPendenteImpede = false, int $carenciaDias = 0): ?array
{
    if ($idEmpresa <= 0 || $carenciaDias < 0) {
        throw new InvalidArgumentException('Parâmetros inválidos para a verificação financeira.');
    }

    $cobrancas = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT c.id_cobranca, c.status, c.finalidade, c.valor,
                (c.data_vencimento < DATE_SUB(CURDATE(), INTERVAL {$carenciaDias} DAY)) AS vencida,
                (" . ASSINATURA_SERVICO_SQL_COBRANCA_OFERTA . ") AS oferta
           FROM cobranca c
          WHERE c.id_empresa = ?
            AND c.status <> 'cancelada'
          ORDER BY c.id_cobranca
          FOR UPDATE",
        'i',
        $idEmpresa
    );

    $idDivida = null;
    $divergente = false;
    foreach ($cobrancas as $cobranca) {
        if ((string)$cobranca['status'] !== 'pendente' || (int)$cobranca['oferta'] === 1) {
            continue; // Oferta de contratação não ativada ou conversão de trial não convertido não é dívida.
        }
        $contaComoDivida = (int)$cobranca['vencida'] === 1
            || ($conversaoPendenteImpede && (string)$cobranca['finalidade'] === 'conversao_trial');
        if (!$contaComoDivida) {
            continue; // Cobrança futura não é dívida impeditiva.
        }
        $valorCentavos = pagamentoGatewayValorCentavos((string)$cobranca['valor']);
        if ($valorCentavos <= 0) {
            continue;
        }
        $saldo = $valorCentavos - assinaturaServicoTotalPagoCentavos($conexao, $idEmpresa, (int)$cobranca['id_cobranca']);
        if ($saldo > 0) {
            $idDivida = (int)$cobranca['id_cobranca'];
            break;
        }
        $divergente = true;
    }

    if ($idDivida !== null) {
        return [
            'tipo' => 'divida',
            'codigo' => 'COBRANCA_VENCIDA_PENDENTE',
            'mensagem' => 'Reativação bloqueada: a empresa possui cobrança vencida ainda não quitada.',
            'id_cobranca' => $idDivida,
        ];
    }

    // Estados em andamento do gateway; transação criada ou enviada nunca é pagamento confirmado.
    $incertas = array_values(array_filter(
        assinaturaServicoConsultaFinanceira(
            $conexao,
            "SELECT t.id_transacao, t.id_cobranca, t.metodo, t.tipo_operacao, t.status, t.requer_conciliacao,
                    (t.ordem_externa_id IS NOT NULL) AS com_ordem, (t.id_pagamento IS NULL) AS sem_pagamento,
                    c.status AS cobranca_status, c.valor AS cobranca_valor
               FROM transacao_pagamento t
              INNER JOIN cobranca c ON c.id_cobranca = t.id_cobranca AND c.id_empresa = t.id_empresa
              WHERE t.id_empresa = ?
                AND c.status <> 'cancelada'
                AND (t.status IN ('criada', 'enviando', 'aguardando', 'processando', 'conciliacao')
                     OR t.requer_conciliacao = 1
                     OR (t.status = 'confirmada' AND t.id_pagamento IS NULL))
              ORDER BY t.id_transacao",
            'i',
            $idEmpresa
        ),
        static fn (array $t): bool => !assinaturaServicoPixResidual($conexao, $idEmpresa, $t)
    ));
    if ($incertas !== [] || $divergente) {
        return [
            'tipo' => 'incerto',
            'codigo' => 'PAGAMENTO_AGUARDANDO_CONFIRMACAO',
            'mensagem' => 'Reativação bloqueada: há pagamento aguardando confirmação ou conciliação. Tente novamente após a confirmação.',
            'id_cobranca' => null,
            // Só para orientar o cliente (PIX gerado x cartão); não altera a decisão.
            'metodo' => $incertas === [] ? null : (string)$incertas[0]['metodo'],
        ];
    }

    return null;
}

/**
 * PIX residual: PIX que ainda aguarda transferência, com Order conhecida e sem conciliação manual, de uma cobrança já
 * quitada por pagamentos confirmados. Não é pagamento incerto: se for pago depois, vira sobrepagamento em conciliação na
 * mesma cobrança (nunca quita outra) e continua sendo acompanhado pela conciliação de Orders. Cartão, Preapproval,
 * transação sem Order ou em conciliação nunca entram aqui.
 *
 * @param array<string, mixed> $t linha de transacao_pagamento com cobranca_status e cobranca_valor
 */
function assinaturaServicoPixResidual(mysqli $conexao, int $idEmpresa, array $t): bool
{
    if ((string)$t['metodo'] !== 'pix' || (string)$t['tipo_operacao'] === 'assinatura_preapproval'
        || (string)$t['status'] !== 'aguardando' || (int)$t['requer_conciliacao'] !== 0
        || (int)$t['com_ordem'] !== 1 || (int)$t['sem_pagamento'] !== 1 || (string)$t['cobranca_status'] !== 'paga') {
        return false;
    }
    $valor = pagamentoGatewayValorCentavos((string)$t['cobranca_valor']);

    return $valor > 0 && assinaturaServicoTotalPagoCentavos($conexao, $idEmpresa, (int)$t['id_cobranca']) >= $valor;
}

/**
 * Há PIX residual de cobrança quitada na empresa (só para orientar o cliente a não pagá-lo). Leitura sem bloqueio.
 */
function assinaturaServicoTemPixResidual(mysqli $conexao, int $idEmpresa): bool
{
    $linhas = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT t.id_cobranca, t.metodo, t.tipo_operacao, t.status, t.requer_conciliacao,
                (t.ordem_externa_id IS NOT NULL) AS com_ordem, (t.id_pagamento IS NULL) AS sem_pagamento,
                c.status AS cobranca_status, c.valor AS cobranca_valor
           FROM transacao_pagamento t
          INNER JOIN cobranca c ON c.id_cobranca = t.id_cobranca AND c.id_empresa = t.id_empresa
          WHERE t.id_empresa = ? AND t.metodo = 'pix' AND t.status = 'aguardando' AND c.status = 'paga'",
        'i',
        $idEmpresa
    );
    foreach ($linhas as $linha) {
        if (assinaturaServicoPixResidual($conexao, $idEmpresa, $linha)) {
            return true;
        }
    }

    return false;
}

/**
 * Decide se uma assinatura suspensa pode voltar a ficar ativa (reativação administrativa e regularização).
 * Deve rodar na transação do chamador, com empresa e assinatura bloqueadas. Não grava nada.
 *
 * $assinatura precisa de id_empresa, modalidade, motivo_suspensao, teste_configurado e teste_expirado.
 * Qualquer dúvida (origem desconhecida, teste, dívida, pagamento incerto) resulta em bloqueio.
 * $carenciaDias: 0 na reativação administrativa (qualquer dívida vencida bloqueia); a regularização automática
 * usa a mesma carência da suspensão, para não manter suspensa quem só tem dívida ainda dentro da carência.
 *
 * @param array<string, mixed> $empresa
 * @param array<string, mixed> $assinatura
 * @return array{code: string, user_msg: string}|null
 */
function assinaturaServicoBloqueioReativacao(mysqli $conexao, array $empresa, array $assinatura, int $carenciaDias = 0): ?array
{
    $bloqueio = static fn (string $code, string $msg): array => ['code' => $code, 'user_msg' => $msg];
    $modalidade = (string)$assinatura['modalidade'];
    $motivo = $assinatura['motivo_suspensao'] === null ? '' : strtolower(trim((string)$assinatura['motivo_suspensao']));

    // Teste grátis: a reativação nunca renova nem reabre o período gratuito.
    if ($motivo === 'teste_expirado' || ($modalidade === 'teste' && (int)$assinatura['teste_expirado'] === 1)) {
        return $bloqueio('TESTE_EXPIRADO', 'Reativação bloqueada: o teste grátis expirou. A empresa deve quitar a cobrança de conversão; a reativação administrativa não renova o teste.');
    }
    if ($modalidade === 'teste' && (int)$assinatura['teste_configurado'] !== 1) {
        return $bloqueio('ASSINATURA_TESTE_INCONSISTENTE', 'Reativação bloqueada: o período de teste da assinatura está inconsistente. Verifique o cadastro.');
    }
    if ($modalidade !== 'teste' && $modalidade !== 'paga') {
        return $bloqueio('ASSINATURA_MODALIDADE_INVALIDA', 'Reativação bloqueada: a modalidade da assinatura não é reconhecida.');
    }

    // Somente origens persistidas e conhecidas são tratadas; qualquer outra mantém o bloqueio seguro.
    if ($motivo !== 'manual' && $motivo !== 'inadimplencia') {
        return $bloqueio('SUSPENSAO_ORIGEM_INDEFINIDA', 'Reativação bloqueada: não foi possível identificar com segurança a origem da suspensão. Verifique o financeiro da empresa.');
    }

    if ((string)$empresa['status'] !== 'ativo') {
        return $bloqueio('EMPRESA_INATIVA', 'Reativação bloqueada: a empresa está inativa.');
    }

    // Suspensão por inadimplência também exige a cobrança de conversão (estornada) quitada, mesmo antes do vencimento.
    $pendencia = assinaturaServicoPendenciaFinanceira($conexao, (int)$assinatura['id_empresa'], $motivo === 'inadimplencia', $carenciaDias);
    if ($pendencia !== null) {
        return $bloqueio($pendencia['codigo'], $pendencia['mensagem']);
    }

    return null;
}

/**
 * Assinaturas pagas ativas que têm cobrança vencida além da carência na empresa. Leitura sem bloqueio: apenas candidatas; a
 * decisão é refeita sob lock por assinaturaServicoSuspenderPorInadimplencia().
 *
 * @return list<array{id_empresa: int, id_assinatura: int}>
 */
function assinaturaServicoListarCandidatasInadimplencia(mysqli $conexao): array
{
    $carencia = ASSINATURA_SERVICO_CARENCIA_INADIMPLENCIA_DIAS;
    $linhas = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT a.id_empresa, a.id_assinatura
           FROM assinatura a
          INNER JOIN empresa e ON e.id_empresa = a.id_empresa AND e.status = 'ativo'
          INNER JOIN plano p ON p.id_plano = a.id_plano AND p.gera_cobranca = 1
          WHERE a.status = 'ativa'
            AND a.modalidade = 'paga'
            AND EXISTS (SELECT 1 FROM cobranca c
                         WHERE c.id_empresa = a.id_empresa
                           AND c.status = 'pendente'
                           AND c.data_vencimento < DATE_SUB(CURDATE(), INTERVAL {$carencia} DAY)
                           AND NOT " . ASSINATURA_SERVICO_SQL_COBRANCA_OFERTA . ")
          ORDER BY a.id_empresa ASC, a.id_assinatura ASC"
    );

    return array_map(
        static fn (array $linha): array => ['id_empresa' => (int)$linha['id_empresa'], 'id_assinatura' => (int)$linha['id_assinatura']],
        $linhas
    );
}

/**
 * Assinaturas pagas suspensas por inadimplência, de empresa ativa e sem cobrança vencida além da carência.
 * Leitura sem bloqueio: apenas candidatas; a decisão é refeita sob lock por assinaturaServicoRegularizarInadimplencia().
 *
 * @return list<array{id_empresa: int, id_assinatura: int}>
 */
function assinaturaServicoListarCandidatasRegularizacao(mysqli $conexao): array
{
    $carencia = ASSINATURA_SERVICO_CARENCIA_INADIMPLENCIA_DIAS;
    $linhas = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT a.id_empresa, a.id_assinatura
           FROM assinatura a
          INNER JOIN empresa e ON e.id_empresa = a.id_empresa AND e.status = 'ativo'
          WHERE a.status = 'suspensa'
            AND a.modalidade = 'paga'
            AND a.motivo_suspensao = 'inadimplencia'
            AND NOT EXISTS (SELECT 1 FROM cobranca c
                             WHERE c.id_empresa = a.id_empresa
                               AND c.status = 'pendente'
                               AND c.data_vencimento < DATE_SUB(CURDATE(), INTERVAL {$carencia} DAY)
                               AND NOT " . ASSINATURA_SERVICO_SQL_COBRANCA_OFERTA . ")
          ORDER BY a.id_empresa ASC, a.id_assinatura ASC"
    );

    return array_map(
        static fn (array $linha): array => ['id_empresa' => (int)$linha['id_empresa'], 'id_assinatura' => (int)$linha['id_assinatura']],
        $linhas
    );
}

/**
 * Suspende por inadimplência uma assinatura paga ativa que tem cobrança vencida e não quitada.
 * Participa da transação do chamador (sem commit/rollback). Auditoria somente quando suspende de fato.
 *
 * Bloqueios na mesma ordem da reativação administrativa: empresa, assinatura, cobranças da empresa.
 * Não toca contratos cancelados, testes nem suspensões (a manual permanece manual). Só suspende dívida vencida
 * há mais de ASSINATURA_SERVICO_CARENCIA_INADIMPLENCIA_DIAS dias corridos; dentro da carência a assinatura segue
 * ativa. Pagamento em andamento não abate dívida.
 *
 * @param array<string, mixed> $ator
 * @param array<string, mixed> $contextoAuditoria
 * @return array{suspensa: bool, motivo: string|null, requer_atencao: bool, id_cobranca?: int}
 */
function assinaturaServicoSuspenderPorInadimplencia(mysqli $conexao, int $idEmpresa, int $idAssinatura, array $ator, array $contextoAuditoria = []): array
{
    if ($idEmpresa <= 0 || $idAssinatura <= 0) {
        throw new InvalidArgumentException('Contrato inválido para a suspensão por inadimplência.');
    }
    $resultado = static fn (?string $motivo, bool $atencao = false, array $extra = []): array => array_merge([
        'suspensa' => false,
        'motivo' => $motivo,
        'requer_atencao' => $atencao,
    ], $extra);

    $empresas = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT id_empresa FROM empresa WHERE id_empresa = ? AND status = 'ativo' LIMIT 1 FOR UPDATE",
        'i',
        $idEmpresa
    );
    if ($empresas === []) {
        return $resultado('empresa_inativa_ou_inexistente');
    }

    $assinaturas = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT id_assinatura, id_plano, status, modalidade
           FROM assinatura
          WHERE id_assinatura = ? AND id_empresa = ?
          LIMIT 1
          FOR UPDATE",
        'ii',
        $idAssinatura,
        $idEmpresa
    );
    $assinatura = $assinaturas[0] ?? null;
    if ($assinatura === null || (string)$assinatura['status'] !== 'ativa' || (string)$assinatura['modalidade'] !== 'paga') {
        return $resultado('assinatura_nao_elegivel');
    }
    if (!assinaturaServicoPlanoGeraCobranca($conexao, $idAssinatura, true)) {
        return $resultado('plano_sem_cobranca');
    }

    $outrasAtivas = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT id_assinatura FROM assinatura WHERE id_empresa = ? AND status = 'ativa' AND id_assinatura <> ? FOR UPDATE",
        'ii',
        $idEmpresa,
        $idAssinatura
    );
    if ($outrasAtivas !== []) {
        return $resultado('multiplas_ativas', true);
    }

    $pendencia = assinaturaServicoPendenciaFinanceira($conexao, $idEmpresa, false, ASSINATURA_SERVICO_CARENCIA_INADIMPLENCIA_DIAS);
    if ($pendencia === null) {
        return $resultado('sem_divida');
    }
    if ($pendencia['tipo'] !== 'divida') {
        return $resultado('pagamento_incerto');
    }
    $idCobranca = (int)$pendencia['id_cobranca'];

    $atualizar = $conexao->prepare(
        "UPDATE assinatura SET status = 'suspensa', motivo_suspensao = 'inadimplencia', suspensa_em = CURRENT_TIMESTAMP
          WHERE id_assinatura = ? AND id_empresa = ? AND status = 'ativa' AND modalidade = 'paga'"
    );
    if (!$atualizar) {
        throw new RuntimeException('Falha ao preparar a suspensão por inadimplência.');
    }
    $atualizar->bind_param('ii', $idAssinatura, $idEmpresa);
    if (!$atualizar->execute()) {
        $erro = (string)$atualizar->error;
        $atualizar->close();
        throw new RuntimeException('Falha ao suspender a assinatura por inadimplência. Erro: ' . $erro);
    }
    $alterada = $atualizar->affected_rows === 1;
    $atualizar->close();
    if (!$alterada) {
        throw new RuntimeException('A suspensão por inadimplência não alterou exatamente uma assinatura.');
    }

    $suspensas = assinaturaServicoLinhas(
        $conexao,
        "SELECT DATE_FORMAT(suspensa_em, '%Y-%m-%d %H:%i:%s') AS suspensa_em FROM assinatura WHERE id_assinatura = ? AND id_empresa = ? LIMIT 1",
        'ii',
        $idAssinatura,
        $idEmpresa
    );
    $idPlano = (int)$assinatura['id_plano'];
    $depois = static fn (mixed $valor): array => ['antes' => null, 'depois' => $valor];
    auditoriaRegistrar($conexao, 'assinatura.suspensa', [
        'ator' => $ator,
        'entidade_id' => $idAssinatura,
        'entidade_rotulo' => "Assinatura #{$idAssinatura}",
        'descricao' => "Suspendeu a assinatura #{$idAssinatura} por inadimplência: a cobrança #{$idCobranca} está vencida além da carência e não foi quitada.",
        'alteracoes' => [
            'id_empresa' => $depois($idEmpresa),
            'id_assinatura' => $depois($idAssinatura),
            'id_cobranca' => $depois($idCobranca),
            'id_plano' => $depois($idPlano),
            'status' => ['antes' => 'ativa', 'depois' => 'suspensa'],
            'modalidade' => $depois('paga'),
            'status_anterior' => $depois('ativa'),
            'status_novo' => $depois('suspensa'),
            'motivo_suspensao' => ['antes' => null, 'depois' => 'inadimplencia'],
            'suspensa_em' => ['antes' => null, 'depois' => $suspensas[0]['suspensa_em'] ?? null],
        ],
        'contexto' => array_merge($contextoAuditoria, [
            'id_empresa' => $idEmpresa,
            'id_assinatura' => $idAssinatura,
            'id_plano' => $idPlano,
            'status_anterior' => 'ativa',
            'status_novo' => 'suspensa',
            'motivo' => 'cobranca_vencida_nao_quitada',
        ]),
    ]);

    return $resultado(null, false, ['suspensa' => true, 'id_cobranca' => $idCobranca]);
}

/**
 * Regulariza a assinatura paga suspensa por inadimplência quando a dívida impeditiva foi integralmente quitada.
 * Participa da transação do chamador (sem commit/rollback). Auditoria somente quando reativa de fato.
 *
 * Só atua em status 'suspensa' + modalidade 'paga' + motivo 'inadimplencia': suspensão manual, teste expirado,
 * contrato cancelado e motivo desconhecido nunca são reativados aqui. Usa a mesma decisão da reativação
 * administrativa (assinaturaServicoBloqueioReativacao), de modo que pagamento incerto mantém a suspensão.
 *
 * @param array<string, mixed> $ator
 * @param array<string, mixed> $contextoAuditoria
 * @return array{reativada: bool, motivo: string|null, requer_atencao: bool}
 */
function assinaturaServicoRegularizarInadimplencia(mysqli $conexao, int $idEmpresa, int $idAssinatura, array $ator, array $contextoAuditoria = []): array
{
    if ($idEmpresa <= 0 || $idAssinatura <= 0) {
        throw new InvalidArgumentException('Contrato inválido para a regularização por inadimplência.');
    }
    $resultado = static fn (?string $motivo, bool $atencao = false): array => [
        'reativada' => false,
        'motivo' => $motivo,
        'requer_atencao' => $atencao,
    ];

    $empresas = assinaturaServicoConsultaFinanceira(
        $conexao,
        'SELECT id_empresa, nome, plano_id, status FROM empresa WHERE id_empresa = ? LIMIT 1 FOR UPDATE',
        'i',
        $idEmpresa
    );
    $empresa = $empresas[0] ?? null;
    if ($empresa === null) {
        return $resultado('empresa_inexistente', true);
    }

    $assinaturas = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT id_assinatura, id_empresa, id_plano, status, modalidade, motivo_suspensao, data_fim,
                DATE_FORMAT(suspensa_em, '%Y-%m-%d %H:%i:%s') AS suspensa_em,
                CASE WHEN teste_iniciado_em IS NOT NULL
                      AND teste_expira_em IS NOT NULL
                      AND teste_expira_em > teste_iniciado_em
                     THEN 1 ELSE 0 END AS teste_configurado,
                CASE WHEN teste_expira_em IS NOT NULL
                      AND CURRENT_TIMESTAMP >= teste_expira_em
                     THEN 1 ELSE 0 END AS teste_expirado
           FROM assinatura
          WHERE id_assinatura = ? AND id_empresa = ?
          LIMIT 1
          FOR UPDATE",
        'ii',
        $idAssinatura,
        $idEmpresa
    );
    $assinatura = $assinaturas[0] ?? null;
    if ($assinatura === null
        || (string)$assinatura['status'] !== 'suspensa'
        || (string)$assinatura['modalidade'] !== 'paga'
        || (string)$assinatura['motivo_suspensao'] !== 'inadimplencia'
        || ($assinatura['data_fim'] !== null && (string)$assinatura['data_fim'] < date('Y-m-d'))) {
        return $resultado('assinatura_nao_regularizavel');
    }

    $outrasAtivas = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT id_assinatura FROM assinatura WHERE id_empresa = ? AND status = 'ativa' AND id_assinatura <> ? FOR UPDATE",
        'ii',
        $idEmpresa,
        $idAssinatura
    );
    if ($outrasAtivas !== []) {
        return $resultado('outra_assinatura_ativa', true);
    }
    $idPlano = (int)$assinatura['id_plano'];
    if ($idPlano !== (int)$empresa['plano_id']) {
        return $resultado('plano_divergente', true);
    }

    $bloqueio = assinaturaServicoBloqueioReativacao($conexao, $empresa, $assinatura, ASSINATURA_SERVICO_CARENCIA_INADIMPLENCIA_DIAS);
    if ($bloqueio !== null) {
        // Dívida ou pagamento ainda incerto é o estado esperado enquanto não há quitação; o restante exige atenção.
        $esperado = in_array($bloqueio['code'], ['COBRANCA_VENCIDA_PENDENTE', 'PAGAMENTO_AGUARDANDO_CONFIRMACAO', 'EMPRESA_INATIVA'], true);

        return $resultado(strtolower($bloqueio['code']), !$esperado);
    }

    $atualizar = $conexao->prepare(
        "UPDATE assinatura SET status = 'ativa', motivo_suspensao = NULL, suspensa_em = NULL
          WHERE id_assinatura = ? AND id_empresa = ? AND status = 'suspensa' AND modalidade = 'paga' AND motivo_suspensao = 'inadimplencia'"
    );
    if (!$atualizar) {
        throw new RuntimeException('Falha ao preparar a regularização da assinatura.');
    }
    $atualizar->bind_param('ii', $idAssinatura, $idEmpresa);
    if (!$atualizar->execute()) {
        $erro = (string)$atualizar->error;
        $atualizar->close();
        throw new RuntimeException('Falha ao regularizar a assinatura. Erro: ' . $erro);
    }
    $alterada = $atualizar->affected_rows === 1;
    $atualizar->close();
    if (!$alterada) {
        throw new RuntimeException('A regularização não alterou exatamente uma assinatura.');
    }

    $depois = static fn (mixed $valor): array => ['antes' => null, 'depois' => $valor];
    auditoriaRegistrar($conexao, 'assinatura.reativada', [
        'ator' => $ator,
        'entidade_id' => $idAssinatura,
        'entidade_rotulo' => "Assinatura #{$idAssinatura}",
        'descricao' => "Reativou a assinatura #{$idAssinatura} após a quitação da dívida que motivou a suspensão por inadimplência.",
        'alteracoes' => [
            'id_empresa' => $depois($idEmpresa),
            'id_assinatura' => $depois($idAssinatura),
            'id_plano' => $depois($idPlano),
            'status' => ['antes' => 'suspensa', 'depois' => 'ativa'],
            'modalidade' => $depois('paga'),
            'status_anterior' => $depois('suspensa'),
            'status_novo' => $depois('ativa'),
            'motivo_suspensao' => ['antes' => 'inadimplencia', 'depois' => null],
            'suspensa_em' => ['antes' => $assinatura['suspensa_em'], 'depois' => null],
        ],
        'contexto' => array_merge($contextoAuditoria, [
            'id_empresa' => $idEmpresa,
            'id_assinatura' => $idAssinatura,
            'id_plano' => $idPlano,
            'status_anterior' => 'suspensa',
            'status_novo' => 'ativa',
            'motivo' => 'divida_quitada',
        ]),
    ]);

    return ['reativada' => true, 'motivo' => null, 'requer_atencao' => false];
}

/**
 * Cobrança que ainda não é dívida: oferta de nova contratação de um contrato que nunca foi ativado
 * (motivo 'contratacao_pendente'), cobrança de conversão de um trial que nunca foi convertido, ou cobrança
 * proporcional de upgrade que nunca recebeu pagamento (o upgrade só vale após quitação; estornada, volta a
 * ser dívida porque o pagamento estornado permanece registrado). Usa o alias c para cobranca.
 */
const ASSINATURA_SERVICO_SQL_COBRANCA_OFERTA = "(EXISTS (SELECT 1 FROM assinatura ao
                 WHERE ao.id_assinatura = c.id_assinatura
                   AND ao.id_empresa = c.id_empresa
                   AND (ao.motivo_suspensao = 'contratacao_pendente'
                        OR (c.finalidade = 'conversao_trial' AND ao.modalidade = 'teste')))
             OR (c.finalidade = 'upgrade'
                 AND NOT EXISTS (SELECT 1 FROM pagamento pz WHERE pz.id_cobranca = c.id_cobranca AND pz.id_empresa = c.id_empresa)))";

/** Resultado padronizado das operações de nova contratação. */
function assinaturaServicoNovaContratacaoResultado(bool $ok, ?string $codigo, string $mensagem, array $extra = []): array
{
    return array_merge(['ok' => $ok, 'codigo' => $codigo, 'mensagem' => $mensagem], $extra);
}

/** Data atual do banco, a mesma usada nas comparações SQL (CURDATE). */
function assinaturaServicoHojeBanco(mysqli $conexao): string
{
    $linhas = assinaturaServicoConsultaFinanceira($conexao, "SELECT DATE_FORMAT(CURDATE(), '%Y-%m-%d') AS hoje");
    $hoje = (string)($linhas[0]['hoje'] ?? '');
    if (!assinaturaServicoDataValida($hoje, 'Y-m-d')) {
        throw new RuntimeException('Não foi possível determinar a data atual do banco.');
    }

    return $hoje;
}

/** @return array<string, array{antes: null, depois: mixed}> */
function assinaturaServicoAlteracoesNovas(array $dados): array
{
    $resultado = [];
    foreach ($dados as $campo => $valor) {
        $resultado[$campo] = ['antes' => null, 'depois' => $valor];
    }

    return $resultado;
}

/** Registra auditoria com o ator informado ou, quando null, com o usuário da sessão. */
function assinaturaServicoAuditar(mysqli $conexao, string $evento, array $dados, ?array $ator): void
{
    if ($ator !== null) {
        $dados['ator'] = $ator;
    }
    auditoriaRegistrar($conexao, $evento, $dados);
}

/**
 * Indica se o plano comporta os usuários ativos da empresa sem bloquear ninguém.
 * Reutiliza o planejamento de downgrade da regra de limites; não altera dados.
 *
 * @param array<string, mixed> $plano
 */
function assinaturaServicoPlanoComportaEmpresa(mysqli $conexao, int $idEmpresa, array $plano): bool
{
    require_once __DIR__ . '/../_regras/limites_plano.php';
    $usuarios = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT eu.id_empresa_usuario, eu.id_usuario, u.nome, pf.nome AS perfil_nome
           FROM empresa_usuario eu
          INNER JOIN usuario u ON u.id_usuario = eu.id_usuario
          INNER JOIN perfil pf ON pf.id_perfil = eu.id_perfil
          WHERE eu.id_empresa = ?
            AND eu.status IN ('ativo', 'bloqueado')
            AND eu.bloqueado_plano = 0
            AND u.status IN ('ativo', 'bloqueado')
            AND u.tipo_usuario <> 'super_admin'
          FOR UPDATE",
        'i',
        $idEmpresa
    );
    $planejamento = limitesPlanoPlanejarDowngradeUsuarios($usuarios, $plano);

    return ($planejamento['ok'] ?? false) === true && ($planejamento['bloquear_ids'] ?? []) === [];
}

/**
 * Planos disponíveis para a nova contratação pelo Proprietário: as mesmas condições comerciais do cadastro
 * público (ativo, disponível ao público, na mesma ordem e com os mesmos dados exibidos no site), com cobrança e
 * preço positivos. Planos que não comportam a estrutura atual permanecem na lista apenas para informação, mas
 * indisponíveis para escolha. Com $idPlano, valida somente um plano elegível (com lock).
 *
 * @return list<array<string, mixed>>
 */
function assinaturaServicoNovaContratacaoPlanos(mysqli $conexao, int $idEmpresa, ?int $idPlano = null): array
{
    $sql = "SELECT id_plano, nome, preco_mensal, cobranca, descricao, limite_usuarios, limite_proprietarios,
                   limite_profissionais, limite_recepcionistas, limite_servicos, limite_agendamentos,
                   agenda_online, destaque, ref
              FROM plano
             WHERE status = 'ativo'
               AND disponivel_cadastro_publico = 1
               AND gera_cobranca = 1";
    $tipos = '';
    $valores = [];
    if ($idPlano !== null) {
        $sql .= ' AND id_plano = ? FOR UPDATE';
        $tipos = 'i';
        $valores[] = $idPlano;
    } else {
        // Mesma ordenação da rota pública planos/listar.
        $sql .= ' ORDER BY CAST(ref AS UNSIGNED) ASC, id_plano ASC';
    }

    $planos = [];
    foreach (assinaturaServicoConsultaFinanceira($conexao, $sql, $tipos, ...$valores) as $plano) {
        $valor = trim((string)$plano['preco_mensal']);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $valor) || pagamentoGatewayValorCentavos($valor) <= 0
            || !in_array((string)$plano['cobranca'], ASSINATURA_SERVICO_PERIODICIDADES, true)
            || (int)$plano['limite_proprietarios'] < 1 || (int)$plano['limite_usuarios'] < 1) {
            continue;
        }
        $comportaEmpresa = assinaturaServicoPlanoComportaEmpresa($conexao, $idEmpresa, $plano);
        // A validação autoritativa de uma escolha não pode aceitar plano incompatível.
        if ($idPlano !== null && !$comportaEmpresa) {
            continue;
        }
        $planos[] = [
            'id_plano' => (int)$plano['id_plano'],
            'nome' => (string)$plano['nome'],
            'valor' => pagamentoGatewayValorDecimal(pagamentoGatewayValorCentavos($valor)),
            'periodicidade' => (string)$plano['cobranca'],
            // Só para exibição: duração da competência de um contrato diário novo (homologação de 2 dias).
            'dias_ciclo' => assinaturaServicoPeriodicidadeDiaria((string)$plano['cobranca']) ? ASSINATURA_SERVICO_DIARIA_DIAS_NOVOS_CONTRATOS : null,
            'descricao' => (string)($plano['descricao'] ?? ''),
            'limite_usuarios' => (int)$plano['limite_usuarios'],
            'limite_proprietarios' => (int)$plano['limite_proprietarios'],
            'limite_profissionais' => (int)$plano['limite_profissionais'],
            'limite_recepcionistas' => (int)$plano['limite_recepcionistas'],
            'limite_servicos' => (int)$plano['limite_servicos'],
            'limite_agendamentos' => (int)$plano['limite_agendamentos'],
            'agenda_online' => (int)$plano['agenda_online'],
            'destaque' => (int)$plano['destaque'],
            'disponivel' => $comportaEmpresa,
            'motivo_indisponibilidade' => $comportaEmpresa
                ? null
                : 'Este plano não comporta a estrutura atual da empresa.',
        ];
    }

    return $planos;
}

/**
 * Estado contratual da empresa para a nova contratação, com empresa e assinaturas bloqueadas.
 * Participa da transação do chamador e não grava nada.
 *
 * Encerramento programado com data_fim já passada é tratado como cancelado (efetivado depois por
 * assinaturaServicoEfetivarFinsProgramados); com data_fim de hoje ou futura, o contrato segue vigente.
 *
 * @return array<string, mixed>
 */
function assinaturaServicoNovaContratacaoEstado(mysqli $conexao, int $idEmpresa): array
{
    if ($idEmpresa <= 0) {
        throw new InvalidArgumentException('Empresa inválida para a nova contratação.');
    }
    $hoje = assinaturaServicoHojeBanco($conexao);
    $empresas = assinaturaServicoConsultaFinanceira(
        $conexao,
        'SELECT id_empresa, nome, plano_id, status FROM empresa WHERE id_empresa = ? LIMIT 1 FOR UPDATE',
        'i',
        $idEmpresa
    );
    $empresa = $empresas[0] ?? null;
    if ($empresa === null || (string)$empresa['status'] !== 'ativo') {
        return assinaturaServicoNovaContratacaoResultado(false, 'EMPRESA_INATIVA', 'A empresa não está ativa. Entre em contato com o suporte.');
    }

    $assinaturas = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT a.id_assinatura, a.id_plano, a.valor_contratado, a.periodicidade, a.dia_vencimento,
                a.status, a.modalidade, a.motivo_suspensao,
                DATE_FORMAT(a.data_inicio, '%Y-%m-%d') AS data_inicio,
                DATE_FORMAT(a.data_fim, '%Y-%m-%d') AS data_fim,
                DATE_FORMAT(a.suspensa_em, '%Y-%m-%d %H:%i:%s') AS suspensa_em
           FROM assinatura a
          WHERE a.id_empresa = ?
          ORDER BY a.id_assinatura DESC
          FOR UPDATE",
        'i',
        $idEmpresa
    );
    if ($assinaturas === []) {
        return assinaturaServicoNovaContratacaoResultado(false, 'SEM_CONTRATO_ANTERIOR', 'Não há contrato anterior para esta empresa. Entre em contato com o suporte.');
    }

    $vigentes = [];
    $finsVencidos = [];
    foreach ($assinaturas as $assinatura) {
        if (!in_array((string)$assinatura['status'], ['ativa', 'suspensa'], true)) {
            continue;
        }
        if ($assinatura['data_fim'] !== null && (string)$assinatura['data_fim'] < $hoje) {
            $finsVencidos[] = $assinatura;
            continue;
        }
        $vigentes[] = $assinatura;
    }
    $base = ['empresa' => $empresa, 'hoje' => $hoje, 'fins_vencidos' => $finsVencidos, 'pendente' => null, 'ultima' => $assinaturas[0]];

    if (count($vigentes) > 1) {
        return assinaturaServicoNovaContratacaoResultado(false, 'CONTRATO_INCONSISTENTE', 'A empresa possui mais de um contrato vigente. Entre em contato com o suporte.', $base);
    }
    if (count($vigentes) === 1) {
        $vigente = $vigentes[0];
        if ((string)$vigente['status'] === 'suspensa' && (string)$vigente['modalidade'] === 'paga'
            && (string)$vigente['motivo_suspensao'] === 'contratacao_pendente' && $vigente['data_fim'] === null) {
            $base['pendente'] = $vigente;

            return assinaturaServicoNovaContratacaoResultado(true, null, 'Nova contratação aguardando pagamento.', $base);
        }
        if ($vigente['data_fim'] !== null) {
            $data = implode('/', array_reverse(explode('-', (string)$vigente['data_fim'])));

            return assinaturaServicoNovaContratacaoResultado(false, 'CANCELAMENTO_PROGRAMADO_VIGENTE', "Sua assinatura segue vigente até {$data}. A nova contratação ficará disponível após o encerramento.", $base);
        }

        return assinaturaServicoNovaContratacaoResultado(false, 'CONTRATO_VIGENTE', 'A empresa já possui uma assinatura vigente.', $base);
    }

    // Sem contrato vigente: o último contrato precisa estar cancelado (ou com encerramento já vencido).
    $ultima = $assinaturas[0];
    $ultimaEncerrada = (string)$ultima['status'] === 'cancelada'
        || ($ultima['data_fim'] !== null && (string)$ultima['data_fim'] < $hoje
            && in_array((string)$ultima['status'], ['ativa', 'suspensa'], true));
    if (!$ultimaEncerrada) {
        return assinaturaServicoNovaContratacaoResultado(false, 'CONTRATO_INCONSISTENTE', 'Não foi possível confirmar o encerramento do contrato anterior. Entre em contato com o suporte.', $base);
    }

    return assinaturaServicoNovaContratacaoResultado(true, null, 'Contratação disponível.', $base);
}

/**
 * Bloqueio financeiro da nova contratação. Sem carência: qualquer dívida vencida, inclusive de contratos
 * anteriores, impede contratar; ofertas não pagas não são dívida. Pagamento incerto também impede.
 */
function assinaturaServicoNovaContratacaoBloqueioFinanceiro(mysqli $conexao, int $idEmpresa): ?array
{
    $pendencia = assinaturaServicoPendenciaFinanceira($conexao, $idEmpresa);
    if ($pendencia === null) {
        return null;
    }
    if ($pendencia['tipo'] === 'divida') {
        return assinaturaServicoNovaContratacaoResultado(false, 'COBRANCA_VENCIDA_PENDENTE', 'Há cobrança vencida em aberto. Quite os débitos listados no faturamento para contratar novamente.');
    }

    return assinaturaServicoNovaContratacaoResultado(false, 'PAGAMENTO_AGUARDANDO_CONFIRMACAO', match ($pendencia['metodo'] ?? null) {
        'pix' => 'Há um PIX gerado que ainda pode ser pago no Mercado Pago. Conclua o pagamento ou aguarde a expiração do PIX e tente novamente.',
        'cartao_recorrente' => 'Há um pagamento no cartão em processamento ou conciliação no Mercado Pago. Atualize a situação do cartão e tente novamente.',
        default => 'Há pagamento aguardando confirmação ou conciliação. Tente novamente após a confirmação.',
    });
}

/**
 * Efetiva encerramentos programados já vencidos (mesma regra do CRON da Fase 2), para que a nova contratação
 * não conviva com um contrato 'ativa' cujo acesso já terminou. Participa da transação do chamador.
 *
 * @param list<array<string, mixed>> $finsVencidos
 */
function assinaturaServicoEfetivarFinsProgramados(mysqli $conexao, int $idEmpresa, array $finsVencidos, ?array $ator): void
{
    foreach ($finsVencidos as $fim) {
        $idAssinatura = (int)$fim['id_assinatura'];
        $statusAnterior = (string)$fim['status'];
        $stmt = $conexao->prepare(
            "UPDATE assinatura SET status = 'cancelada'
              WHERE id_empresa = ? AND id_assinatura = ? AND status = ? AND data_fim < CURDATE()"
        );
        if (!$stmt) {
            throw new RuntimeException('Falha ao preparar a efetivação do encerramento programado.');
        }
        $stmt->bind_param('iis', $idEmpresa, $idAssinatura, $statusAnterior);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('Falha ao efetivar o encerramento programado.');
        }
        $stmt->close();
        assinaturaServicoAuditar($conexao, 'assinatura.cancelada', [
            'entidade_id' => $idAssinatura,
            'entidade_rotulo' => "Assinatura #{$idAssinatura}",
            'descricao' => 'Efetivou o cancelamento programado da assinatura antes da nova contratação.',
            'alteracoes' => ['status' => ['antes' => $statusAnterior, 'depois' => 'cancelada']],
            'contexto' => ['origem' => 'nova_contratacao', 'id_empresa' => $idEmpresa, 'id_assinatura' => $idAssinatura,
                'data_fim' => (string)$fim['data_fim']],
        ], $ator);
    }
}

/**
 * Cancela ofertas não pagas (cobrança pendente sem pagamento confirmado) de contratos que nunca foram ativados
 * e de trials nunca convertidos já encerrados, para que não sejam pagas por engano. Participa da transação.
 * Retorna bloqueio quando alguma oferta já recebeu pagamento: exige análise antes de prosseguir.
 */
function assinaturaServicoCancelarOfertasNaoPagas(mysqli $conexao, int $idEmpresa, ?array $ator): ?array
{
    $ofertas = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT c.id_cobranca, c.id_assinatura, c.valor,
                DATE_FORMAT(c.periodo_inicio, '%Y-%m-%d') AS periodo_inicio,
                DATE_FORMAT(c.periodo_fim, '%Y-%m-%d') AS periodo_fim,
                DATE_FORMAT(c.data_vencimento, '%Y-%m-%d') AS data_vencimento
           FROM cobranca c
          INNER JOIN assinatura ao ON ao.id_assinatura = c.id_assinatura AND ao.id_empresa = c.id_empresa
          WHERE c.id_empresa = ?
            AND c.status = 'pendente'
            AND (ao.motivo_suspensao = 'contratacao_pendente'
                 OR (c.finalidade = 'conversao_trial' AND ao.modalidade = 'teste' AND ao.status IN ('cancelada', 'encerrada')))
          ORDER BY c.id_cobranca
          FOR UPDATE",
        'i',
        $idEmpresa
    );
    foreach ($ofertas as $oferta) {
        if (assinaturaServicoTotalPagoCentavos($conexao, $idEmpresa, (int)$oferta['id_cobranca']) > 0) {
            return assinaturaServicoNovaContratacaoResultado(false, 'OFERTA_COM_PAGAMENTO', 'Uma cobrança de contratação anterior recebeu pagamento e precisa de análise. Entre em contato com o suporte.');
        }
    }
    foreach ($ofertas as $oferta) {
        $idCobranca = (int)$oferta['id_cobranca'];
        $stmt = $conexao->prepare("UPDATE cobranca SET status = 'cancelada' WHERE id_cobranca = ? AND id_empresa = ? AND status = 'pendente'");
        if (!$stmt) {
            throw new RuntimeException('Falha ao preparar o cancelamento da oferta.');
        }
        $stmt->bind_param('ii', $idCobranca, $idEmpresa);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('Falha ao cancelar a oferta de contratação.');
        }
        $stmt->close();
        assinaturaServicoAuditar($conexao, 'cobranca.cancelada', [
            'entidade_id' => $idCobranca,
            'entidade_rotulo' => "Cobrança #{$idCobranca}",
            'descricao' => "Cancelou a cobrança #{$idCobranca}, oferta de contratação não paga substituída pela nova contratação.",
            'alteracoes' => assinaturaServicoAlteracoesNovas([
                'id_empresa' => $idEmpresa,
                'id_assinatura' => (int)$oferta['id_assinatura'],
                'id_cobranca' => $idCobranca,
                'valor' => (string)$oferta['valor'],
                'periodo_inicio' => (string)$oferta['periodo_inicio'],
                'periodo_fim' => (string)$oferta['periodo_fim'],
                'data_vencimento' => (string)$oferta['data_vencimento'],
                'motivo' => 'oferta_de_contratacao_substituida',
            ]) + ['status' => ['antes' => 'pendente', 'depois' => 'cancelada']],
            'contexto' => ['origem' => 'nova_contratacao', 'id_empresa' => $idEmpresa, 'id_assinatura' => (int)$oferta['id_assinatura'],
                'motivo' => 'oferta_de_contratacao_substituida'],
        ], $ator);
    }

    return null;
}

/**
 * Gera a cobrança do primeiro período (oferta) do contrato pendente: começa provisoriamente hoje, com
 * vencimento imediato e finalidade regular. Na quitação, o período é reposicionado para a data em que a
 * confirmação financeira foi registrada. O acesso só é liberado após a quitação integral dela.
 *
 * @param array<string, mixed> $assinatura
 * @return array<string, int|string>
 */
function assinaturaServicoGerarOfertaContratacao(mysqli $conexao, int $idEmpresa, array $assinatura, string $hoje, ?array $ator): array
{
    $idAssinatura = (int)$assinatura['id_assinatura'];
    $valor = trim((string)$assinatura['valor_contratado']);
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $valor) || pagamentoGatewayValorCentavos($valor) <= 0) {
        throw new RuntimeException('Valor inválido para a cobrança de contratação.');
    }
    $valor = pagamentoGatewayValorDecimal(pagamentoGatewayValorCentavos($valor));
    $inicio = DateTimeImmutable::createFromFormat('!Y-m-d', $hoje);
    if ($inicio === false) {
        throw new RuntimeException('Data inválida para a cobrança de contratação.');
    }
    $datasOferta = assinaturaServicoOfertaContratacaoDatas($inicio, (string)$assinatura['periodicidade']);
    $periodoInicio = $datasOferta['inicio'];
    $periodoFim = $datasOferta['fim'];
    $vencimento = $datasOferta['vencimento'];

    $stmt = $conexao->prepare(
        "INSERT INTO cobranca (id_empresa, id_assinatura, periodo_inicio, periodo_fim, data_vencimento, valor, status, finalidade)
         VALUES (?, ?, ?, ?, ?, ?, 'pendente', 'regular')"
    );
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar a cobrança de contratação.');
    }
    $stmt->bind_param('iissss', $idEmpresa, $idAssinatura, $periodoInicio, $periodoFim, $vencimento, $valor);
    if (!$stmt->execute()) {
        $erro = (string)$stmt->error;
        $stmt->close();
        throw new RuntimeException('Falha ao criar a cobrança de contratação. Erro: ' . $erro);
    }
    $idCobranca = (int)$stmt->insert_id;
    $stmt->close();

    assinaturaServicoAuditar($conexao, 'cobranca.gerada', [
        'entidade_id' => $idCobranca,
        'entidade_rotulo' => "Cobrança de contratação da assinatura #{$idAssinatura}",
        'descricao' => "Gerou a cobrança do primeiro período da nova contratação (assinatura #{$idAssinatura}).",
        'alteracoes' => assinaturaServicoAlteracoesNovas([
            'id_empresa' => $idEmpresa,
            'id_assinatura' => $idAssinatura,
            'periodo_inicio' => $periodoInicio,
            'periodo_fim' => $periodoFim,
            'data_vencimento' => $vencimento,
            'valor' => $valor,
            'status' => 'pendente',
            'finalidade' => 'regular',
        ]),
        'contexto' => ['origem' => 'nova_contratacao', 'origem_geracao' => 'nova_contratacao', 'id_empresa' => $idEmpresa,
            'id_assinatura' => $idAssinatura],
    ], $ator);

    return [
        'id_cobranca' => $idCobranca,
        'valor' => $valor,
        'periodo_inicio' => $periodoInicio,
        'periodo_fim' => $periodoFim,
        'data_vencimento' => $vencimento,
    ];
}

/**
 * Cobranças não canceladas do contrato pendente, com o total pago confirmado (centavos) e a quitação.
 *
 * @return list<array<string, mixed>>
 */
function assinaturaServicoOfertasDoContrato(mysqli $conexao, int $idEmpresa, int $idAssinatura): array
{
    $linhas = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT id_cobranca, status, valor,
                DATE_FORMAT(periodo_inicio, '%Y-%m-%d') AS periodo_inicio,
                DATE_FORMAT(periodo_fim, '%Y-%m-%d') AS periodo_fim,
                DATE_FORMAT(data_vencimento, '%Y-%m-%d') AS data_vencimento
           FROM cobranca
          WHERE id_empresa = ? AND id_assinatura = ? AND status <> 'cancelada'
          ORDER BY id_cobranca
          FOR UPDATE",
        'ii',
        $idEmpresa,
        $idAssinatura
    );
    foreach ($linhas as &$linha) {
        $valor = pagamentoGatewayValorCentavos((string)$linha['valor']);
        $pago = assinaturaServicoTotalPagoCentavos($conexao, $idEmpresa, (int)$linha['id_cobranca']);
        $linha['pago_centavos'] = $pago;
        $linha['quitada'] = (string)$linha['status'] === 'paga' && $valor > 0 && $pago >= $valor;
    }
    unset($linha);

    return $linhas;
}

/**
 * Solicita a nova contratação de uma empresa com contrato cancelado. Participa da transação do chamador.
 *
 * Não reativa o contrato anterior nem cria trial: cria um novo registro 'suspensa'/'paga' com motivo
 * 'contratacao_pendente' e a cobrança do primeiro período; o acesso pago só é liberado quando ela é quitada
 * (assinaturaServicoConcluirNovaContratacao). Idempotente: repetir a solicitação reutiliza a oferta do dia;
 * oferta de dia anterior sem pagamento é substituída; oferta já paga é concluída.
 *
 * @param array<string, mixed>|null $ator null usa o usuário da sessão
 * @return array<string, mixed>
 */
function assinaturaServicoSolicitarNovaContratacao(mysqli $conexao, int $idEmpresa, int $idPlano, ?array $ator = null): array
{
    if ($idPlano <= 0) {
        return assinaturaServicoNovaContratacaoResultado(false, 'PLANO_INDISPONIVEL', 'Selecione um plano disponível.');
    }
    $estado = assinaturaServicoNovaContratacaoEstado($conexao, $idEmpresa);
    if (!$estado['ok']) {
        return $estado;
    }
    $bloqueio = assinaturaServicoNovaContratacaoBloqueioFinanceiro($conexao, $idEmpresa);
    if ($bloqueio !== null) {
        return $bloqueio;
    }
    $recorrenciaAnterior = assinaturaServicoPreapprovalRecorrenciaAnteriorPendente($conexao, $idEmpresa);
    if ($recorrenciaAnterior !== null) {
        return $recorrenciaAnterior;
    }

    $pendente = $estado['pendente'];
    $hoje = (string)$estado['hoje'];
    if ($pendente !== null) {
        $ofertas = assinaturaServicoOfertasDoContrato($conexao, $idEmpresa, (int)$pendente['id_assinatura']);
        foreach ($ofertas as $oferta) {
            if ($oferta['quitada']) {
                if ((int)$pendente['id_plano'] !== $idPlano) {
                    return assinaturaServicoNovaContratacaoResultado(false, 'CONTRATACAO_JA_PAGA', 'A contratação anterior já foi paga. Conclua-a antes de escolher outro plano.');
                }

                return assinaturaServicoConcluirNovaContratacao($conexao, $idEmpresa, $ator, ['origem' => 'nova_contratacao']);
            }
        }
        if ((int)$pendente['id_plano'] === $idPlano && count($ofertas) === 1
            && (string)$ofertas[0]['status'] === 'pendente'
            && (string)$ofertas[0]['periodo_inicio'] === $hoje && (int)$ofertas[0]['pago_centavos'] === 0) {
            return assinaturaServicoNovaContratacaoResultado(true, null, 'Pague a cobrança de contratação para liberar o acesso.', [
                'resultado' => 'reutilizada',
                'id_assinatura' => (int)$pendente['id_assinatura'],
                'id_cobranca' => (int)$ofertas[0]['id_cobranca'],
                'valor' => (string)$ofertas[0]['valor'],
                'data_vencimento' => (string)$ofertas[0]['data_vencimento'],
            ]);
        }
    }

    $plano = assinaturaServicoNovaContratacaoPlanos($conexao, $idEmpresa, $idPlano)[0] ?? null;
    if ($plano === null) {
        return assinaturaServicoNovaContratacaoResultado(false, 'PLANO_INDISPONIVEL', 'O plano escolhido não está disponível para contratação ou não comporta os usuários ativos da empresa.');
    }

    if ($pendente !== null) {
        // Pagamento da primeira cobrança pelo cartão (Preapproval): enquanto a assinatura externa puder cobrar (mesmo sem
        // autorização, pois o pagador pode concluir um checkout antigo), nem a oferta é substituída nem o contrato trocado.
        if (assinaturaServicoPreapprovalPrimeiraCobrancaViva($conexao, $idEmpresa, (int)$pendente['id_assinatura'])) {
            return assinaturaServicoNovaContratacaoResultado(false, 'PAGAMENTO_CARTAO_EM_ANDAMENTO', 'A contratação atual está sendo paga pelo cartão no Mercado Pago. Para alterá-la, use antes a opção "Pagar com PIX em vez do cartão" e aguarde a confirmação.');
        }
    }

    // Ofertas antigas não pagas saem de cena antes da nova; oferta com pagamento exige análise.
    $bloqueioOferta = assinaturaServicoCancelarOfertasNaoPagas($conexao, $idEmpresa, $ator);
    if ($bloqueioOferta !== null) {
        return $bloqueioOferta;
    }
    assinaturaServicoEfetivarFinsProgramados($conexao, $idEmpresa, $estado['fins_vencidos'], $ator);

    if ($pendente !== null && (int)$pendente['id_plano'] === $idPlano) {
        $assinatura = $pendente; // Mesmo plano: renova somente a oferta do contrato pendente.
        $resultado = 'renovada';
    } else {
        $resultado = 'criada';
        if (!assinaturaServicoColunaAceita($conexao, 'assinatura', 'motivo_suspensao', 'contratacao_pendente')) {
            return assinaturaServicoNovaContratacaoResultado(false, 'ESTRUTURA_PENDENTE', 'A nova contratação ainda não está disponível. Entre em contato com o suporte.');
        }
        if ($pendente !== null) {
            // Troca de plano antes do pagamento: o contrato pendente (nunca ativado) é encerrado como histórico.
            $idPendente = (int)$pendente['id_assinatura'];
            $stmt = $conexao->prepare(
                "UPDATE assinatura SET status = 'cancelada', data_fim = CURDATE()
                  WHERE id_assinatura = ? AND id_empresa = ? AND status = 'suspensa' AND motivo_suspensao = 'contratacao_pendente'"
            );
            if (!$stmt) {
                throw new RuntimeException('Falha ao preparar a substituição da contratação pendente.');
            }
            $stmt->bind_param('ii', $idPendente, $idEmpresa);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException('Falha ao substituir a contratação pendente.');
            }
            $stmt->close();
            assinaturaServicoAuditar($conexao, 'assinatura.cancelada', [
                'entidade_id' => $idPendente,
                'entidade_rotulo' => "Assinatura #{$idPendente}",
                'descricao' => "Encerrou a contratação pendente #{$idPendente}, não paga, substituída por outro plano.",
                'alteracoes' => ['status' => ['antes' => 'suspensa', 'depois' => 'cancelada'], 'data_fim' => ['antes' => null, 'depois' => $hoje]],
                'contexto' => ['origem' => 'nova_contratacao', 'id_empresa' => $idEmpresa, 'id_assinatura' => $idPendente,
                    'data_fim' => $hoje, 'motivo' => 'contratacao_pendente_substituida'],
            ], $ator);
        }

        $diaVencimento = (int)($estado['ultima']['dia_vencimento'] ?? 0);
        if ($diaVencimento < 1 || $diaVencimento > 28) {
            $diaVencimento = 10;
        }
        $assinatura = assinaturaServicoCriarContratacaoPendente(
            $conexao, $idEmpresa, $idPlano, (float)$plano['valor'], (string)$plano['periodicidade'], $diaVencimento, $hoje
        );
        $idNova = (int)$assinatura['id_assinatura'];
        assinaturaServicoAuditar($conexao, 'assinatura.criada', [
            'entidade_id' => $idNova,
            'entidade_rotulo' => 'Nova contratação da empresa ' . (string)$estado['empresa']['nome'],
            'descricao' => 'Criou nova contratação aguardando o pagamento do primeiro período.',
            'alteracoes' => assinaturaServicoAlteracoesNovas([
                'id_empresa' => $idEmpresa,
                'id_plano' => $idPlano,
                'valor_contratado' => (string)$assinatura['valor_contratado'],
                'periodicidade' => $plano['periodicidade'],
                'dia_vencimento' => $diaVencimento,
                'data_inicio' => $hoje,
                'status' => 'suspensa',
            ]),
            'contexto' => ['origem' => 'nova_contratacao', 'id_empresa' => $idEmpresa, 'id_assinatura' => $idNova,
                'id_plano' => $idPlano, 'status_novo' => 'suspensa', 'motivo' => 'contratacao_pendente'],
        ], $ator);
    }

    $oferta = assinaturaServicoGerarOfertaContratacao($conexao, $idEmpresa, $assinatura, $hoje, $ator);

    return assinaturaServicoNovaContratacaoResultado(true, null, 'Pague a cobrança de contratação para liberar o acesso.', [
        'resultado' => $resultado,
        'id_assinatura' => (int)$assinatura['id_assinatura'],
        'id_cobranca' => (int)$oferta['id_cobranca'],
        'valor' => (string)$oferta['valor'],
        'data_vencimento' => (string)$oferta['data_vencimento'],
    ]);
}

/**
 * Cria o contrato 'suspensa'/'paga' com motivo 'contratacao_pendente' (sem acesso pago até a quitação da oferta).
 * Participa da transação do chamador; auditoria, oferta e validações de negócio ficam com o chamador.
 * Falha fechada: se a coluna não aceitar o motivo (ex.: ENUM sem a opção), lança exceção.
 *
 * @return array{id_assinatura: int, valor_contratado: string, periodicidade: string, dia_vencimento: int}
 */
function assinaturaServicoCriarContratacaoPendente(mysqli $conexao, int $idEmpresa, int $idPlano, float $valor, string $periodicidade, int $diaVencimento, string $hoje): array
{
    $criada = assinaturaServicoCriar($conexao, [
        'id_empresa' => $idEmpresa,
        'id_plano' => $idPlano,
        'valor_contratado' => $valor,
        'periodicidade' => $periodicidade,
        'dia_vencimento' => $diaVencimento,
        'data_inicio' => $hoje,
        'status' => 'suspensa',
        'modalidade' => 'paga',
    ]);
    $idNova = (int)$criada['id_assinatura'];
    $stmt = $conexao->prepare(
        "UPDATE assinatura SET motivo_suspensao = 'contratacao_pendente', suspensa_em = CURRENT_TIMESTAMP
          WHERE id_assinatura = ? AND id_empresa = ? AND status = 'suspensa'"
    );
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar a marcação da contratação pendente.');
    }
    $stmt->bind_param('ii', $idNova, $idEmpresa);
    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('Falha ao marcar a contratação pendente.');
    }
    $stmt->close();
    $conferencia = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT motivo_suspensao, valor_contratado, status, modalidade FROM assinatura WHERE id_assinatura = ? AND id_empresa = ? LIMIT 1",
        'ii',
        $idNova,
        $idEmpresa
    );
    if ((string)($conferencia[0]['motivo_suspensao'] ?? '') !== 'contratacao_pendente'
        || (string)($conferencia[0]['status'] ?? '') !== 'suspensa'
        || (string)($conferencia[0]['modalidade'] ?? '') !== 'paga') {
        throw new RuntimeException('O banco não registrou a contratação pendente (verifique a coluna assinatura.motivo_suspensao).');
    }

    return [
        'id_assinatura' => $idNova,
        'valor_contratado' => (string)$conferencia[0]['valor_contratado'],
        'periodicidade' => $periodicidade,
        'dia_vencimento' => $diaVencimento,
    ];
}

/**
 * Ativa a nova contratação depois da quitação integral da cobrança do primeiro período.
 * Participa da transação do chamador; auditoria somente quando ativa de fato.
 *
 * Exige: contrato pendente único, oferta paga e confirmada, nenhuma dívida vencida nem pagamento incerto na
 * empresa, e plano que comporta os usuários. Atualiza empresa.plano_id para o plano contratado.
 *
 * @param array<string, mixed>|null $ator null usa o usuário da sessão
 * @param array<string, mixed> $contextoAuditoria
 * @return array<string, mixed>
 */
function assinaturaServicoConcluirNovaContratacao(mysqli $conexao, int $idEmpresa, ?array $ator = null, array $contextoAuditoria = []): array
{
    $estado = assinaturaServicoNovaContratacaoEstado($conexao, $idEmpresa);
    if (!$estado['ok']) {
        return $estado;
    }
    $pendente = $estado['pendente'];
    if ($pendente === null) {
        return assinaturaServicoNovaContratacaoResultado(false, 'SEM_CONTRATACAO_PENDENTE', 'Não há nova contratação aguardando pagamento.');
    }
    $idAssinatura = (int)$pendente['id_assinatura'];
    $ofertas = assinaturaServicoOfertasDoContrato($conexao, $idEmpresa, $idAssinatura);
    if (count($ofertas) !== 1 || !$ofertas[0]['quitada']) {
        return assinaturaServicoNovaContratacaoResultado(false, 'PAGAMENTO_PENDENTE', 'Sua primeira mensalidade ainda não foi confirmada. Conclua o pagamento ou entre em contato com o atendimento.');
    }
    $oferta = $ofertas[0];
    $bloqueio = assinaturaServicoNovaContratacaoBloqueioFinanceiro($conexao, $idEmpresa);
    if ($bloqueio !== null) {
        return $bloqueio;
    }

    $idPlano = (int)$pendente['id_plano'];
    $planos = assinaturaServicoConsultaFinanceira(
        $conexao,
        'SELECT id_plano, limite_usuarios, limite_proprietarios, limite_profissionais, limite_recepcionistas
           FROM plano WHERE id_plano = ? LIMIT 1 FOR UPDATE',
        'i',
        $idPlano
    );
    if ($planos === [] || !assinaturaServicoPlanoComportaEmpresa($conexao, $idEmpresa, $planos[0])) {
        return assinaturaServicoNovaContratacaoResultado(false, 'PLANO_LIMITE_USUARIOS', 'O plano contratado não comporta os usuários ativos da empresa. Entre em contato com o suporte.', ['requer_atencao' => true]);
    }

    assinaturaServicoEfetivarFinsProgramados($conexao, $idEmpresa, $estado['fins_vencidos'], $ator);

    $periodoConfirmado = assinaturaServicoPeriodoQuitadoDaOferta(
        $conexao,
        $idEmpresa,
        (int)$oferta['id_cobranca'],
        pagamentoGatewayValorCentavos((string)$oferta['valor']),
        (string)$pendente['periodicidade'],
        assinaturaServicoPeriodicidadeDiaria((string)$pendente['periodicidade'])
            ? assinaturaServicoDiasPeriodoDiario((string)$oferta['periodo_inicio'], (string)$oferta['periodo_fim'])
            : 1
    );
    $dataInicio = $periodoConfirmado['inicio'];
    $periodoInicioAnterior = (string)$oferta['periodo_inicio'];
    $periodoFimAnterior = (string)$oferta['periodo_fim'];
    if ($dataInicio !== $periodoInicioAnterior || $periodoConfirmado['fim'] !== $periodoFimAnterior) {
        $stmtPeriodo = $conexao->prepare(
            "UPDATE cobranca SET periodo_inicio = ?, periodo_fim = ?
              WHERE id_cobranca = ? AND id_empresa = ? AND id_assinatura = ?
                AND status = 'paga' AND finalidade = 'regular'
                AND periodo_inicio = ? AND periodo_fim = ?"
        );
        if (!$stmtPeriodo) {
            throw new RuntimeException('Falha ao preparar o período pago da nova contratação.');
        }
        $idCobranca = (int)$oferta['id_cobranca'];
        $periodoFim = $periodoConfirmado['fim'];
        $stmtPeriodo->bind_param(
            'ssiiiss',
            $dataInicio,
            $periodoFim,
            $idCobranca,
            $idEmpresa,
            $idAssinatura,
            $periodoInicioAnterior,
            $periodoFimAnterior
        );
        if (!$stmtPeriodo->execute() || $stmtPeriodo->affected_rows !== 1) {
            $stmtPeriodo->close();
            throw new RuntimeException('A atualização do período pago não alterou exatamente uma cobrança.');
        }
        $stmtPeriodo->close();
    }
    $stmt = $conexao->prepare(
        "UPDATE assinatura SET status = 'ativa', motivo_suspensao = NULL, suspensa_em = NULL, data_inicio = ?
          WHERE id_assinatura = ? AND id_empresa = ? AND status = 'suspensa' AND modalidade = 'paga'
            AND motivo_suspensao = 'contratacao_pendente' AND data_fim IS NULL"
    );
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar a ativação da nova contratação.');
    }
    $stmt->bind_param('sii', $dataInicio, $idAssinatura, $idEmpresa);
    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('A ativação não alterou exatamente uma assinatura.');
    }
    $stmt->close();

    $empresa = $estado['empresa'];
    $planoAnterior = (int)$empresa['plano_id'];
    if ($planoAnterior !== $idPlano) {
        $stmt = $conexao->prepare('UPDATE empresa SET plano_id = ? WHERE id_empresa = ? AND plano_id = ?');
        if (!$stmt) {
            throw new RuntimeException('Falha ao preparar a atualização do plano da empresa.');
        }
        $stmt->bind_param('iii', $idPlano, $idEmpresa, $planoAnterior);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('Falha ao atualizar o plano da empresa.');
        }
        $stmt->close();
        assinaturaServicoAuditar($conexao, 'empresa.editada', [
            'entidade_id' => $idEmpresa,
            'entidade_rotulo' => (string)$empresa['nome'],
            'descricao' => 'Atualizou o plano da empresa pela ativação da nova contratação.',
            'alteracoes' => ['plano' => ['antes' => $planoAnterior, 'depois' => $idPlano]],
            'contexto' => ['origem' => 'nova_contratacao', 'id_empresa' => $idEmpresa, 'id_assinatura' => $idAssinatura, 'id_plano' => $idPlano],
        ], $ator);
    }

    $depois = static fn (mixed $valor): array => ['antes' => null, 'depois' => $valor];
    assinaturaServicoAuditar($conexao, 'assinatura.contratacao_ativada', [
        'entidade_id' => $idAssinatura,
        'entidade_rotulo' => "Assinatura #{$idAssinatura}",
        'descricao' => "Ativou a nova contratação #{$idAssinatura} após a quitação da cobrança #{$oferta['id_cobranca']}.",
        'alteracoes' => [
            'id_empresa' => $depois($idEmpresa),
            'id_assinatura' => $depois($idAssinatura),
            'id_cobranca' => $depois((int)$oferta['id_cobranca']),
            'id_plano' => $depois($idPlano),
            'status' => ['antes' => 'suspensa', 'depois' => 'ativa'],
            'modalidade' => $depois('paga'),
            'status_anterior' => $depois('suspensa'),
            'status_novo' => $depois('ativa'),
            'data_inicio' => ['antes' => (string)$pendente['data_inicio'], 'depois' => $dataInicio],
            'periodo_inicio' => ['antes' => $periodoInicioAnterior, 'depois' => $dataInicio],
            'periodo_fim' => ['antes' => $periodoFimAnterior, 'depois' => $periodoConfirmado['fim']],
            'motivo_suspensao' => ['antes' => 'contratacao_pendente', 'depois' => null],
            'suspensa_em' => ['antes' => $pendente['suspensa_em'], 'depois' => null],
        ],
        'contexto' => array_merge(['origem' => 'nova_contratacao'], $contextoAuditoria, [
            'id_empresa' => $idEmpresa,
            'id_assinatura' => $idAssinatura,
            'id_plano' => $idPlano,
            'status_anterior' => 'suspensa',
            'status_novo' => 'ativa',
            'motivo' => 'contratacao_paga',
        ]),
    ], $ator);

    return assinaturaServicoNovaContratacaoResultado(true, null, 'Contratação concluída com sucesso! Sua primeira mensalidade está paga e seu acesso ao AmAgenda foi liberado.', [
        'resultado' => 'ativada',
        'id_assinatura' => $idAssinatura,
        'id_cobranca' => (int)$oferta['id_cobranca'],
        'pix_residual' => assinaturaServicoTemPixResidual($conexao, $idEmpresa),
    ]);
}

/**
 * Contratos pendentes com oferta paga (candidatos à ativação pelo CRON). Leitura sem bloqueio; a decisão
 * é refeita sob lock por assinaturaServicoConcluirNovaContratacao().
 *
 * @return list<array{id_empresa: int, id_assinatura: int}>
 */
function assinaturaServicoListarContratacoesPagas(mysqli $conexao): array
{
    $linhas = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT a.id_empresa, a.id_assinatura
           FROM assinatura a
          INNER JOIN empresa e ON e.id_empresa = a.id_empresa AND e.status = 'ativo'
          WHERE a.status = 'suspensa'
            AND a.modalidade = 'paga'
            AND a.motivo_suspensao = 'contratacao_pendente'
            AND EXISTS (SELECT 1 FROM cobranca c
                         WHERE c.id_assinatura = a.id_assinatura AND c.id_empresa = a.id_empresa AND c.status = 'paga')
          ORDER BY a.id_empresa ASC, a.id_assinatura ASC"
    );

    return array_map(
        static fn (array $linha): array => ['id_empresa' => (int)$linha['id_empresa'], 'id_assinatura' => (int)$linha['id_assinatura']],
        $linhas
    );
}

/**
 * Situação da nova contratação para a tela de faturamento. Avalia com as mesmas regras e locks da operação,
 * dentro de uma transação desfeita ao final (nada é gravado). Retorna null quando não se aplica
 * (empresa com contrato vigente comum).
 *
 * @return array<string, mixed>|null
 */
function assinaturaServicoSituacaoNovaContratacao(mysqli $conexao, int $idEmpresa): ?array
{
    // Contrato vigente comum (sem encerramento programado nem contratação pendente): nada a avaliar, sem locks.
    $vigenteComum = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT 1 FROM assinatura
          WHERE id_empresa = ? AND status IN ('ativa', 'suspensa') AND data_fim IS NULL
            AND NOT (status = 'suspensa' AND motivo_suspensao <=> 'contratacao_pendente')
          LIMIT 1",
        'i',
        $idEmpresa
    );
    if ($vigenteComum !== []) {
        return null;
    }
    if (!$conexao->begin_transaction()) {
        throw new RuntimeException('Falha ao iniciar a consulta da nova contratação.');
    }
    try {
        $estado = assinaturaServicoNovaContratacaoEstado($conexao, $idEmpresa);
        if (!$estado['ok'] && in_array($estado['codigo'], ['CONTRATO_VIGENTE', 'SEM_CONTRATO_ANTERIOR'], true)) {
            return null;
        }
        $situacao = [
            'disponivel' => false,
            'pode_concluir' => false,
            'codigo' => $estado['codigo'],
            'mensagem' => $estado['mensagem'],
            'planos' => [],
            'pendente' => null,
            'cartao_sincronizacao_pendente' => false,
        ];
        if ($estado['ok']) {
            $bloqueio = assinaturaServicoNovaContratacaoBloqueioFinanceiro($conexao, $idEmpresa);
            $pendente = $estado['pendente'];
            if ($pendente !== null) {
                $ofertas = assinaturaServicoOfertasDoContrato($conexao, $idEmpresa, (int)$pendente['id_assinatura']);
                $oferta = $ofertas[0] ?? null;
                $nomes = assinaturaServicoConsultaFinanceira($conexao, 'SELECT nome FROM plano WHERE id_plano = ? LIMIT 1', 'i', (int)$pendente['id_plano']);
                $paga = $oferta !== null && count($ofertas) === 1 && $oferta['quitada'];
                $situacao['pendente'] = [
                    'id_assinatura' => (int)$pendente['id_assinatura'],
                    'id_plano' => (int)$pendente['id_plano'],
                    'plano' => (string)($nomes[0]['nome'] ?? ''),
                    'valor' => (string)$pendente['valor_contratado'],
                    'periodicidade' => (string)$pendente['periodicidade'],
                    'id_cobranca' => $oferta === null ? null : (int)$oferta['id_cobranca'],
                    'periodo_inicio' => $oferta['periodo_inicio'] ?? null,
                    'periodo_fim' => $oferta['periodo_fim'] ?? null,
                    'data_vencimento' => $oferta['data_vencimento'] ?? null,
                    'paga' => $paga,
                ];
                $situacao['pode_concluir'] = $bloqueio === null && $paga;
                $situacao['mensagem'] = $paga
                    ? 'Pagamento confirmado. Conclua a contratação para liberar o acesso.'
                    : 'Nova contratação aguardando o pagamento da cobrança do primeiro período.';
                $situacao['pix_residual'] = $paga && assinaturaServicoTemPixResidual($conexao, $idEmpresa);
            }
            if ($bloqueio !== null) {
                $situacao['codigo'] = $bloqueio['codigo'];
                $situacao['mensagem'] = $bloqueio['mensagem'];
            } elseif ($pendente === null && !assinaturaServicoColunaAceita($conexao, 'assinatura', 'motivo_suspensao', 'contratacao_pendente')) {
                $situacao['codigo'] = 'ESTRUTURA_PENDENTE';
                $situacao['mensagem'] = 'A nova contratação ainda não está disponível. Entre em contato com o suporte.';
            } elseif (!$situacao['pode_concluir']) {
                $situacao['planos'] = assinaturaServicoNovaContratacaoPlanos($conexao, $idEmpresa);
                $situacao['disponivel'] = array_filter(
                    $situacao['planos'],
                    static fn (array $plano): bool => ($plano['disponivel'] ?? false) === true
                ) !== [];
                if (!$situacao['disponivel'] && $pendente === null) {
                    $situacao['codigo'] = 'PLANO_INDISPONIVEL';
                    $situacao['mensagem'] = 'Nenhum plano disponível comporta os usuários ativos da empresa. Entre em contato com o suporte.';
                }
            }
        }
        // Só o cartão de contratos anteriores; o da própria contratação pendente é o pagamento da primeira cobrança.
        $cartao = assinaturaServicoConsultaFinanceira(
            $conexao,
            "SELECT 1 FROM assinatura_pagamento_recorrente
              WHERE id_empresa = ? AND status IN ('cancelamento_pendente', 'pendente', 'ativa', 'suspensa', 'atualizacao_necessaria')
                AND NOT (id_assinatura <=> ?)
              LIMIT 1",
            'ii',
            $idEmpresa,
            $estado['ok'] && $estado['pendente'] !== null ? (int)$estado['pendente']['id_assinatura'] : null
        );
        $situacao['cartao_sincronizacao_pendente'] = $cartao !== [];

        return $situacao;
    } finally {
        try {
            $conexao->rollback();
        } catch (Throwable) {
        }
    }
}

/* ==========================================================
   TROCA DE PLANO — UPGRADE PROPORCIONAL E DOWNGRADE PROGRAMADO
   ----------------------------------------------------------
   Estado persistido em assinatura_troca_plano (DDL manual). Sem a tabela, ou se
   cobranca.finalidade não aceitar 'upgrade', a troca fica indisponível (falha fechada)
   e nenhum outro fluxo é afetado.
   ========================================================== */

const ASSINATURA_TROCA_FINALIDADE_UPGRADE = 'upgrade';
const ASSINATURA_TROCA_NOTIFICACAO_PENDENTE = 'faturamento.upgrade_pendente';
const ASSINATURA_TROCA_NOTIFICACAO_CONCLUIDA = 'faturamento.upgrade_concluido';

/** Indica se a coluna existe e aceita o valor (ENUM/SET com a opção ou texto com tamanho suficiente). */
function assinaturaServicoColunaAceita(mysqli $conexao, string $tabela, string $coluna, string $valor): bool
{
    static $cache = [];
    $chave = "{$tabela}.{$coluna}={$valor}";
    if (array_key_exists($chave, $cache)) {
        return $cache[$chave];
    }
    $linhas = assinaturaServicoConsultaFinanceira(
        $conexao,
        'SELECT DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
        'ss',
        $tabela,
        $coluna
    );
    $info = $linhas[0] ?? null;
    if ($info === null) {
        return $cache[$chave] = false;
    }
    $tipo = strtolower((string)$info['DATA_TYPE']);
    if ($tipo === 'enum' || $tipo === 'set') {
        preg_match_all("/'((?:[^']|'')*)'/", (string)$info['COLUMN_TYPE'], $opcoes);
        $valores = array_map(static fn (string $opcao): string => str_replace("''", "'", $opcao), $opcoes[1] ?? []);

        return $cache[$chave] = in_array($valor, $valores, true);
    }
    if (in_array($tipo, ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'], true)) {
        $limite = $info['CHARACTER_MAXIMUM_LENGTH'] === null ? PHP_INT_MAX : (int)$info['CHARACTER_MAXIMUM_LENGTH'];

        return $cache[$chave] = mb_strlen($valor, 'UTF-8') <= $limite;
    }

    return $cache[$chave] = false;
}

/** Indica se a coluna existe no banco atual (estrutura opcional criada por SQL manual). */
function assinaturaServicoColunaExiste(mysqli $conexao, string $tabela, string $coluna): bool
{
    static $cache = [];
    $chave = "{$tabela}.{$coluna}";
    if (!array_key_exists($chave, $cache)) {
        $cache[$chave] = assinaturaServicoConsultaFinanceira(
            $conexao,
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            'ss',
            $tabela,
            $coluna
        ) !== [];
    }

    return $cache[$chave];
}

/** Indica se a tabela existe no banco atual. */
function assinaturaServicoTabelaExiste(mysqli $conexao, string $tabela): bool
{
    static $cache = [];
    if (!array_key_exists($tabela, $cache)) {
        $cache[$tabela] = assinaturaServicoConsultaFinanceira(
            $conexao,
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            's',
            $tabela
        ) !== [];
    }

    return $cache[$tabela];
}

/** Estrutura exigida pela troca de plano; null quando disponível. */
function assinaturaServicoTrocaEstruturaPendente(mysqli $conexao): ?array
{
    if (!assinaturaServicoTabelaExiste($conexao, 'assinatura_troca_plano')
        || !assinaturaServicoColunaAceita($conexao, 'cobranca', 'finalidade', ASSINATURA_TROCA_FINALIDADE_UPGRADE)) {
        return assinaturaServicoNovaContratacaoResultado(false, 'ESTRUTURA_PENDENTE', 'A troca de plano ainda não está disponível. Entre em contato com o suporte.');
    }

    return null;
}

/** Converte data Y-m-d em DateTimeImmutable ou falha. */
function assinaturaServicoData(string $valor): DateTimeImmutable
{
    $data = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    if ($data === false || $data->format('Y-m-d') !== $valor) {
        throw new RuntimeException('Data inválida no cálculo da troca de plano.');
    }

    return $data;
}

/** Diferença em dias corridos entre duas datas Y-m-d (fim - início). */
function assinaturaServicoDiasEntre(string $inicio, string $fim): int
{
    return (int)assinaturaServicoData($inicio)->diff(assinaturaServicoData($fim))->format('%r%a');
}

/**
 * Cálculo proporcional do upgrade, inteiramente em centavos e com o calendário real.
 * dias_ciclo = dias do ciclo pago (início e fim inclusos); dias_restantes = de hoje ao fim, inclusive.
 * proporcional = diferença × dias_restantes ÷ dias_ciclo, arredondado ao centavo (meio para cima).
 *
 * @return array{diferenca_centavos: int, dias_ciclo: int, dias_restantes: int, proporcional_centavos: int}
 */
function assinaturaServicoCalcularProporcional(int $valorCicloCentavos, int $valorNovoCentavos, string $cicloInicio, string $cicloFim, string $hoje): array
{
    $diasCiclo = assinaturaServicoDiasEntre($cicloInicio, $cicloFim) + 1;
    $diasRestantes = assinaturaServicoDiasEntre($hoje, $cicloFim) + 1;
    if ($diasCiclo <= 0 || $diasRestantes <= 0 || $diasRestantes > $diasCiclo) {
        throw new RuntimeException('Ciclo inválido para o cálculo proporcional.');
    }
    $diferenca = max(0, $valorNovoCentavos - $valorCicloCentavos);
    $proporcional = intdiv($diferenca * $diasRestantes * 2 + $diasCiclo, $diasCiclo * 2);

    return [
        'diferenca_centavos' => $diferenca,
        'dias_ciclo' => $diasCiclo,
        'dias_restantes' => $diasRestantes,
        'proporcional_centavos' => $proporcional,
    ];
}

/** Proprietários ativos da empresa (destinatários das notificações financeiras). @return list<int> */
function assinaturaServicoProprietariosEmpresa(mysqli $conexao, int $idEmpresa): array
{
    $linhas = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT DISTINCT u.id_usuario, pf.nome AS perfil
           FROM empresa_usuario eu
          INNER JOIN usuario u ON u.id_usuario = eu.id_usuario
          INNER JOIN perfil pf ON pf.id_perfil = eu.id_perfil
          WHERE eu.id_empresa = ? AND eu.status = 'ativo' AND u.status = 'ativo' AND u.tipo_usuario <> 'super_admin'",
        'i',
        $idEmpresa
    );
    $ids = [];
    foreach ($linhas as $linha) {
        $perfil = strtr(mb_strtolower(trim((string)$linha['perfil']), 'UTF-8'), ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
        if (in_array($perfil, ['proprietario', 'proprietarios'], true)) {
            $ids[] = (int)$linha['id_usuario'];
        }
    }

    return $ids;
}

/** Formata centavos em reais para mensagens (R$ 1.234,56). */
function assinaturaServicoMoeda(int $centavos): string
{
    return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
}

/** Data Y-m-d em d/m/Y. */
function assinaturaServicoDataBr(?string $data): string
{
    return $data === null || $data === '' ? '' : implode('/', array_reverse(explode('-', substr($data, 0, 10))));
}

/**
 * Contexto da troca com empresa, contrato vigente e troca pendente bloqueados (mesma ordem de locks da
 * reativação e da contratação: empresa, assinaturas, cobranças). Participa da transação do chamador.
 * A troca exige assinatura paga ativa, sem encerramento programado, no plano operacional da empresa.
 *
 * @return array<string, mixed>
 */
function assinaturaServicoTrocaContexto(mysqli $conexao, int $idEmpresa): array
{
    $estrutura = assinaturaServicoTrocaEstruturaPendente($conexao);
    if ($estrutura !== null) {
        return $estrutura;
    }
    $hoje = assinaturaServicoHojeBanco($conexao);
    $empresas = assinaturaServicoConsultaFinanceira(
        $conexao,
        'SELECT id_empresa, nome, plano_id, status FROM empresa WHERE id_empresa = ? LIMIT 1 FOR UPDATE',
        'i',
        $idEmpresa
    );
    $empresa = $empresas[0] ?? null;
    if ($empresa === null || (string)$empresa['status'] !== 'ativo') {
        return assinaturaServicoNovaContratacaoResultado(false, 'EMPRESA_INATIVA', 'A empresa não está ativa. Entre em contato com o suporte.');
    }
    $vigentes = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT a.id_assinatura, a.id_plano, p.nome AS plano, a.valor_contratado, a.periodicidade, a.dia_vencimento,
                a.status, a.modalidade, a.motivo_suspensao, DATE_FORMAT(a.data_fim, '%Y-%m-%d') AS data_fim
           FROM assinatura a
           LEFT JOIN plano p ON p.id_plano = a.id_plano
          WHERE a.id_empresa = ? AND a.status IN ('ativa', 'suspensa')
          ORDER BY a.id_assinatura DESC
          FOR UPDATE",
        'i',
        $idEmpresa
    );
    if (count($vigentes) !== 1) {
        return assinaturaServicoNovaContratacaoResultado(false, $vigentes === [] ? 'SEM_ASSINATURA_ATIVA' : 'CONTRATO_INCONSISTENTE',
            $vigentes === [] ? 'A troca de plano exige uma assinatura paga ativa.' : 'Não foi possível determinar a assinatura vigente. Entre em contato com o suporte.');
    }
    $assinatura = $vigentes[0];
    if ((string)$assinatura['status'] !== 'ativa' || (string)$assinatura['modalidade'] !== 'paga') {
        return assinaturaServicoNovaContratacaoResultado(false, 'SEM_ASSINATURA_ATIVA', 'A troca de plano exige uma assinatura paga ativa.');
    }
    if ($assinatura['data_fim'] !== null) {
        return assinaturaServicoNovaContratacaoResultado(false, 'CANCELAMENTO_PROGRAMADO', 'A assinatura tem encerramento programado. A troca de plano não está disponível.');
    }
    if ((int)$assinatura['id_plano'] !== (int)$empresa['plano_id']) {
        return assinaturaServicoNovaContratacaoResultado(false, 'PLANO_ASSINATURA_DIVERGENTE', 'A assinatura não corresponde ao plano atual da empresa. Entre em contato com o suporte.');
    }
    $trocas = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT * FROM assinatura_troca_plano
          WHERE id_empresa = ? AND status IN ('aguardando_pagamento', 'programada')
          ORDER BY id_troca DESC
          FOR UPDATE",
        'i',
        $idEmpresa
    );

    return assinaturaServicoNovaContratacaoResultado(true, null, '', [
        'hoje' => $hoje,
        'empresa' => $empresa,
        'assinatura' => $assinatura,
        'troca' => $trocas[0] ?? null,
        'trocas_pendentes' => $trocas,
    ]);
}

/**
 * Ciclo pago vigente: a cobrança regular do contrato que contém hoje, quitada com pagamentos confirmados.
 * Sem ele não há base legítima para proporcional (nada é calculado sobre período não pago).
 *
 * @return array<string, mixed>|null
 */
function assinaturaServicoCicloPagoVigente(mysqli $conexao, int $idEmpresa, int $idAssinatura, string $hoje): ?array
{
    $ciclos = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT id_cobranca, status, valor,
                DATE_FORMAT(periodo_inicio, '%Y-%m-%d') AS periodo_inicio,
                DATE_FORMAT(periodo_fim, '%Y-%m-%d') AS periodo_fim
           FROM cobranca
          WHERE id_empresa = ? AND id_assinatura = ? AND finalidade = 'regular' AND status <> 'cancelada'
            AND periodo_inicio <= ? AND periodo_fim >= ?
          FOR UPDATE",
        'iiss',
        $idEmpresa,
        $idAssinatura,
        $hoje,
        $hoje
    );
    if (count($ciclos) !== 1 || (string)$ciclos[0]['status'] !== 'paga') {
        return null;
    }
    $ciclo = $ciclos[0];
    $valor = pagamentoGatewayValorCentavos((string)$ciclo['valor']);
    if ($valor <= 0 || assinaturaServicoTotalPagoCentavos($conexao, $idEmpresa, (int)$ciclo['id_cobranca']) < $valor) {
        return null;
    }
    $ciclo['valor_centavos'] = $valor;

    return $ciclo;
}

/**
 * Planos disponíveis para troca: mesmas condições comerciais do cadastro público, mesma periodicidade do
 * contrato e diferente do plano atual. Com $idPlano, valida só esse plano (com lock).
 *
 * @return list<array<string, mixed>>
 */
function assinaturaServicoTrocaPlanos(mysqli $conexao, int $idEmpresa, array $assinatura, ?int $idPlano = null): array
{
    $sql = "SELECT id_plano, nome, preco_mensal, cobranca, descricao, limite_usuarios, limite_proprietarios,
                   limite_profissionais, limite_recepcionistas, limite_servicos, limite_agendamentos,
                   agenda_online, destaque, ref
              FROM plano
             WHERE status = 'ativo' AND disponivel_cadastro_publico = 1 AND gera_cobranca = 1
               AND cobranca = ? AND id_plano <> ?";
    $tipos = 'si';
    $valores = [(string)$assinatura['periodicidade'], (int)$assinatura['id_plano']];
    if ($idPlano !== null) {
        $sql .= ' AND id_plano = ? FOR UPDATE';
        $tipos .= 'i';
        $valores[] = $idPlano;
    } else {
        // Mesma ordenação da rota pública planos/listar.
        $sql .= ' ORDER BY CAST(ref AS UNSIGNED) ASC, id_plano ASC';
    }
    $atual = pagamentoGatewayValorCentavos(pagamentoGatewayValorDecimal(pagamentoGatewayValorCentavos(
        number_format((float)$assinatura['valor_contratado'], 2, '.', '')
    )));
    $planos = [];
    foreach (assinaturaServicoConsultaFinanceira($conexao, $sql, $tipos, ...$valores) as $plano) {
        $preco = trim((string)$plano['preco_mensal']);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $preco) || pagamentoGatewayValorCentavos($preco) <= 0
            || (int)$plano['limite_proprietarios'] < 1 || (int)$plano['limite_usuarios'] < 1) {
            continue;
        }
        $centavos = pagamentoGatewayValorCentavos($preco);
        $planos[] = [
            'id_plano' => (int)$plano['id_plano'],
            'nome' => (string)$plano['nome'],
            'valor' => pagamentoGatewayValorDecimal($centavos),
            'valor_centavos' => $centavos,
            'periodicidade' => (string)$plano['cobranca'],
            'descricao' => (string)($plano['descricao'] ?? ''),
            'limite_usuarios' => (int)$plano['limite_usuarios'],
            'limite_proprietarios' => (int)$plano['limite_proprietarios'],
            'limite_profissionais' => (int)$plano['limite_profissionais'],
            'limite_recepcionistas' => (int)$plano['limite_recepcionistas'],
            'limite_servicos' => (int)$plano['limite_servicos'],
            'limite_agendamentos' => (int)$plano['limite_agendamentos'],
            'agenda_online' => (int)$plano['agenda_online'],
            'destaque' => (int)$plano['destaque'],
            'ordem' => (int)$plano['ref'],
            'tipo' => $centavos < $atual ? 'downgrade' : 'upgrade',
            'comporta_usuarios' => assinaturaServicoPlanoComportaEmpresa($conexao, $idEmpresa, $plano),
        ];
    }

    return $planos;
}

/**
 * Prévia do upgrade com os mesmos dados usados na cobrança. Participa da transação do chamador.
 *
 * @return array<string, mixed>
 */
function assinaturaServicoPreviaUpgrade(mysqli $conexao, array $contexto, array $plano): array
{
    $assinatura = $contexto['assinatura'];
    $hoje = (string)$contexto['hoje'];
    $ciclo = assinaturaServicoCicloPagoVigente($conexao, (int)$contexto['empresa']['id_empresa'], (int)$assinatura['id_assinatura'], $hoje);
    if ($ciclo === null) {
        return assinaturaServicoNovaContratacaoResultado(false, 'CICLO_NAO_PAGO', 'O upgrade exige o ciclo atual pago. Quite a cobrança vigente antes de trocar de plano.');
    }
    // Upgrade já ativado neste mesmo ciclo: a diferença dele já foi paga, então a base passa a ser o plano que ele liberou.
    $anterior = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT valor_novo FROM assinatura_troca_plano
          WHERE id_empresa = ? AND id_assinatura = ? AND tipo = 'upgrade' AND status = 'ativada'
            AND DATE(ciclo_inicio) = ? AND DATE(ciclo_fim) = ?
          ORDER BY id_troca DESC LIMIT 1",
        'iiss',
        (int)$contexto['empresa']['id_empresa'],
        (int)$assinatura['id_assinatura'],
        (string)$ciclo['periodo_inicio'],
        (string)$ciclo['periodo_fim']
    )[0] ?? null;
    if ($anterior !== null) {
        $ciclo['valor_centavos'] = max((int)$ciclo['valor_centavos'], pagamentoGatewayValorCentavos((string)$anterior['valor_novo']));
    }
    $calculo = assinaturaServicoCalcularProporcional((int)$ciclo['valor_centavos'], (int)$plano['valor_centavos'], (string)$ciclo['periodo_inicio'], (string)$ciclo['periodo_fim'], $hoje);
    $proximaRenovacao = assinaturaServicoData((string)$ciclo['periodo_fim'])->modify('+1 day')->format('Y-m-d');
    $proporcional = $calculo['proporcional_centavos'];
    $mensagem = $proporcional > 0
        ? sprintf('Você pagará %s pela diferença proporcional dos %d dias restantes. Seu plano %s será liberado após a confirmação do pagamento. Na próxima renovação, sua mensalidade será de %s.',
            assinaturaServicoMoeda($proporcional), $calculo['dias_restantes'], $plano['nome'], assinaturaServicoMoeda((int)$plano['valor_centavos']))
        : sprintf('Não há valor adicional a pagar. Seu plano %s será liberado imediatamente. Na próxima renovação, sua mensalidade será de %s.',
            $plano['nome'], assinaturaServicoMoeda((int)$plano['valor_centavos']));

    return assinaturaServicoNovaContratacaoResultado(true, null, $mensagem, [
        'tipo' => 'upgrade',
        'id_plano_novo' => (int)$plano['id_plano'],
        'plano_atual' => (string)$assinatura['plano'],
        'plano_novo' => (string)$plano['nome'],
        'valor_atual' => pagamentoGatewayValorDecimal((int)$ciclo['valor_centavos']),
        'valor_novo' => (string)$plano['valor'],
        'diferenca' => pagamentoGatewayValorDecimal($calculo['diferenca_centavos']),
        'dias_ciclo' => $calculo['dias_ciclo'],
        'dias_restantes' => $calculo['dias_restantes'],
        'ciclo_inicio' => (string)$ciclo['periodo_inicio'],
        'ciclo_fim' => (string)$ciclo['periodo_fim'],
        'valor_proporcional' => pagamentoGatewayValorDecimal($proporcional),
        'valor_proporcional_centavos' => $proporcional,
        'formula' => sprintf('%s × %d ÷ %d = %s', assinaturaServicoMoeda($calculo['diferenca_centavos']), $calculo['dias_restantes'], $calculo['dias_ciclo'], assinaturaServicoMoeda($proporcional)),
        'proxima_renovacao' => $proximaRenovacao,
        'id_ciclo_cobranca' => (int)$ciclo['id_cobranca'],
    ]);
}

/**
 * Data da próxima competência ainda não cobrada (dia seguinte ao último período gerado do contrato).
 */
function assinaturaServicoProximaCompetencia(mysqli $conexao, int $idEmpresa, int $idAssinatura): ?string
{
    $linhas = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT DATE_FORMAT(MAX(periodo_fim), '%Y-%m-%d') AS fim FROM cobranca
          WHERE id_empresa = ? AND id_assinatura = ? AND finalidade = 'regular' AND status <> 'cancelada'",
        'ii',
        $idEmpresa,
        $idAssinatura
    );
    $fim = $linhas[0]['fim'] ?? null;

    return $fim === null ? null : assinaturaServicoData((string)$fim)->modify('+1 day')->format('Y-m-d');
}

/** Prévia do downgrade programado. @return array<string, mixed> */
function assinaturaServicoPreviaDowngrade(mysqli $conexao, array $contexto, array $plano): array
{
    $assinatura = $contexto['assinatura'];
    $idEmpresa = (int)$contexto['empresa']['id_empresa'];
    $efetivacao = assinaturaServicoProximaCompetencia($conexao, $idEmpresa, (int)$assinatura['id_assinatura']);
    if ($efetivacao === null || $efetivacao <= (string)$contexto['hoje']) {
        return assinaturaServicoNovaContratacaoResultado(false, 'COMPETENCIA_INDISPONIVEL', 'Não foi possível determinar a próxima competência. Entre em contato com o suporte.');
    }
    if (!$plano['comporta_usuarios']) {
        return assinaturaServicoNovaContratacaoResultado(false, 'PLANO_LIMITE_USUARIOS', sprintf('O plano %s não comporta os usuários ativos da empresa. Desative ou ajuste os vínculos excedentes antes de agendar o downgrade; nenhum dado é excluído automaticamente.', $plano['nome']));
    }

    return assinaturaServicoNovaContratacaoResultado(true, null, sprintf('Seu plano atual continua até %s. A partir de %s, o plano %s passa a valer com mensalidade de %s. Não há estorno nem crédito proporcional.',
        assinaturaServicoDataBr(assinaturaServicoData($efetivacao)->modify('-1 day')->format('Y-m-d')), assinaturaServicoDataBr($efetivacao), $plano['nome'], assinaturaServicoMoeda((int)$plano['valor_centavos'])), [
        'tipo' => 'downgrade',
        'id_plano_novo' => (int)$plano['id_plano'],
        'plano_atual' => (string)$assinatura['plano'],
        'plano_novo' => (string)$plano['nome'],
        'valor_atual' => pagamentoGatewayValorDecimal(pagamentoGatewayValorCentavos(number_format((float)$assinatura['valor_contratado'], 2, '.', ''))),
        'valor_novo' => (string)$plano['valor'],
        'data_efetivacao' => $efetivacao,
    ]);
}

/** Bloqueio financeiro da troca: dívida vencida (sem carência) ou pagamento incerto. */
function assinaturaServicoTrocaBloqueioFinanceiro(mysqli $conexao, int $idEmpresa): ?array
{
    $pendencia = assinaturaServicoPendenciaFinanceira($conexao, $idEmpresa);
    if ($pendencia === null) {
        return null;
    }

    return $pendencia['tipo'] === 'divida'
        ? assinaturaServicoNovaContratacaoResultado(false, 'COBRANCA_VENCIDA_PENDENTE', 'Há cobrança vencida em aberto. Quite os débitos antes de trocar de plano.')
        : assinaturaServicoNovaContratacaoResultado(false, 'PAGAMENTO_AGUARDANDO_CONFIRMACAO', 'Há pagamento aguardando confirmação ou conciliação. Tente novamente após a confirmação.');
}

/** Valores da troca persistida em centavos e datas, para auditoria e mensagens. @return array<string, mixed> */
function assinaturaServicoTrocaResumo(mysqli $conexao, array $troca): array
{
    $nomes = [];
    foreach (assinaturaServicoConsultaFinanceira($conexao, 'SELECT id_plano, nome FROM plano WHERE id_plano IN (?, ?)', 'ii', (int)$troca['id_plano_anterior'], (int)$troca['id_plano_novo']) as $linha) {
        $nomes[(int)$linha['id_plano']] = (string)$linha['nome'];
    }

    return [
        'id_troca' => (int)$troca['id_troca'],
        'tipo' => (string)$troca['tipo'],
        'status' => (string)$troca['status'],
        'id_plano_anterior' => (int)$troca['id_plano_anterior'],
        'id_plano_novo' => (int)$troca['id_plano_novo'],
        'plano_anterior' => $nomes[(int)$troca['id_plano_anterior']] ?? ('#' . (int)$troca['id_plano_anterior']),
        'plano_novo' => $nomes[(int)$troca['id_plano_novo']] ?? ('#' . (int)$troca['id_plano_novo']),
        'valor_anterior' => (string)$troca['valor_anterior'],
        'valor_novo' => (string)$troca['valor_novo'],
        'valor_ciclo' => $troca['valor_ciclo'] === null ? null : (string)$troca['valor_ciclo'],
        'dias_ciclo' => $troca['dias_ciclo'] === null ? null : (int)$troca['dias_ciclo'],
        'dias_restantes' => $troca['dias_restantes'] === null ? null : (int)$troca['dias_restantes'],
        'ciclo_inicio' => $troca['ciclo_inicio'] === null ? null : substr((string)$troca['ciclo_inicio'], 0, 10),
        'ciclo_fim' => $troca['ciclo_fim'] === null ? null : substr((string)$troca['ciclo_fim'], 0, 10),
        'valor_proporcional' => $troca['valor_proporcional'] === null ? null : (string)$troca['valor_proporcional'],
        'id_cobranca' => $troca['id_cobranca'] === null ? null : (int)$troca['id_cobranca'],
        'data_efetivacao' => $troca['data_efetivacao'] === null ? null : substr((string)$troca['data_efetivacao'], 0, 10),
        'expira_em' => $troca['expira_em'] === null ? null : (string)$troca['expira_em'],
    ];
}

/** Alterações de auditoria padronizadas da troca (campos do catálogo). */
function assinaturaServicoTrocaAlteracoes(array $resumo, array $extra = []): array
{
    $campos = [
        'id_troca' => $resumo['id_troca'],
        'tipo' => $resumo['tipo'],
        'id_plano_anterior' => $resumo['id_plano_anterior'],
        'id_plano_novo' => $resumo['id_plano_novo'],
        'plano_anterior' => $resumo['plano_anterior'],
        'plano_novo' => $resumo['plano_novo'],
        'valor_anterior' => $resumo['valor_anterior'],
        'valor_novo' => $resumo['valor_novo'],
    ];
    if ($resumo['tipo'] === 'upgrade') {
        $campos += [
            'valor_ciclo' => $resumo['valor_ciclo'],
            'dias_ciclo' => $resumo['dias_ciclo'],
            'dias_restantes' => $resumo['dias_restantes'],
            'ciclo_inicio' => $resumo['ciclo_inicio'],
            'ciclo_fim' => $resumo['ciclo_fim'],
            'valor_proporcional' => $resumo['valor_proporcional'],
            'id_cobranca' => $resumo['id_cobranca'],
            'proxima_renovacao' => $resumo['ciclo_fim'] === null ? null : assinaturaServicoData((string)$resumo['ciclo_fim'])->modify('+1 day')->format('Y-m-d'),
        ];
    } else {
        $campos['data_efetivacao'] = $resumo['data_efetivacao'];
    }

    return assinaturaServicoAlteracoesNovas($campos + $extra);
}

/** Cria a notificação pendente de pagamento do upgrade para cada Proprietário (idempotente por chave). */
function assinaturaServicoNotificarUpgradePendente(mysqli $conexao, int $idEmpresa, array $resumo): void
{
    require_once __DIR__ . '/notificacao.php';
    foreach (assinaturaServicoProprietariosEmpresa($conexao, $idEmpresa) as $idUsuario) {
        notificacaoCriar($conexao, [
            'destinatario_tipo' => 'usuario',
            'destinatario_id' => $idUsuario,
            'id_empresa' => $idEmpresa,
            'origem_tipo' => 'sistema',
            'prioridade' => 'alta',
            'obrigatoria' => false,
            'codigo' => ASSINATURA_TROCA_NOTIFICACAO_PENDENTE,
            'categoria' => 'faturamento',
            'titulo' => 'Upgrade aguardando pagamento',
            'mensagem' => sprintf('O upgrade do plano %s para o %s será liberado após a confirmação do pagamento proporcional de %s. Abra o faturamento para pagar.',
                $resumo['plano_anterior'], $resumo['plano_novo'], assinaturaServicoMoeda(pagamentoGatewayValorCentavos((string)$resumo['valor_proporcional']))),
            'acao_codigo' => 'faturamento.abrir',
            'contexto' => ['id_troca' => (int)$resumo['id_troca']],
            'prazo_em' => $resumo['expira_em'],
            'chave_deduplicacao' => sprintf('%s:%d:%d', ASSINATURA_TROCA_NOTIFICACAO_PENDENTE, (int)$resumo['id_troca'], $idUsuario),
        ]);
    }
}

/**
 * Encerra as notificações pendentes do upgrade (concluídas na ativação; canceladas na desistência/expiração),
 * para não deixar aviso financeiro desatualizado.
 */
function assinaturaServicoReconciliarNotificacaoUpgrade(mysqli $conexao, int $idEmpresa, int $idTroca, bool $concluir): void
{
    $campo = $concluir ? 'concluida_em' : 'cancelada_em';
    $prefixo = sprintf('%s:%d:', ASSINATURA_TROCA_NOTIFICACAO_PENDENTE, $idTroca);
    $stmt = $conexao->prepare(
        "UPDATE notificacao SET {$campo} = CURRENT_TIMESTAMP
          WHERE id_empresa = ? AND codigo = ? AND LEFT(chave_deduplicacao, ?) = ?
            AND concluida_em IS NULL AND cancelada_em IS NULL"
    );
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar a reconciliação da notificação do upgrade.');
    }
    $codigo = ASSINATURA_TROCA_NOTIFICACAO_PENDENTE;
    $tamanho = strlen($prefixo);
    $stmt->bind_param('isis', $idEmpresa, $codigo, $tamanho, $prefixo);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Falha ao reconciliar a notificação do upgrade.');
    }
    $stmt->close();
}

/** Notificação informativa da ativação do upgrade (idempotente por chave). */
function assinaturaServicoNotificarUpgradeConcluido(mysqli $conexao, int $idEmpresa, array $resumo): void
{
    require_once __DIR__ . '/notificacao.php';
    foreach (assinaturaServicoProprietariosEmpresa($conexao, $idEmpresa) as $idUsuario) {
        notificacaoCriar($conexao, [
            'destinatario_tipo' => 'usuario',
            'destinatario_id' => $idUsuario,
            'id_empresa' => $idEmpresa,
            'origem_tipo' => 'sistema',
            'prioridade' => 'normal',
            'obrigatoria' => false,
            'codigo' => ASSINATURA_TROCA_NOTIFICACAO_CONCLUIDA,
            'categoria' => 'faturamento',
            'titulo' => 'Upgrade concluído',
            'mensagem' => sprintf('Seu plano foi alterado de %s para %s. O novo plano já está liberado.', $resumo['plano_anterior'], $resumo['plano_novo']),
            'acao_codigo' => 'faturamento.abrir',
            'contexto' => ['id_troca' => (int)$resumo['id_troca']],
            'chave_deduplicacao' => sprintf('%s:%d:%d', ASSINATURA_TROCA_NOTIFICACAO_CONCLUIDA, (int)$resumo['id_troca'], $idUsuario),
        ]);
    }
}

/**
 * Aplica o novo plano ao contrato vigente: plano e valor passam a valer na próxima competência gerada; a data de
 * renovação (períodos já gerados) não muda. Exige que o plano comporte os usuários (nenhum vínculo é bloqueado
 * ou excluído automaticamente). Participa da transação do chamador.
 */
function assinaturaServicoAplicarPlanoTroca(mysqli $conexao, array $contexto, array $troca): ?array
{
    $idEmpresa = (int)$contexto['empresa']['id_empresa'];
    $assinatura = $contexto['assinatura'];
    $idPlanoNovo = (int)$troca['id_plano_novo'];
    $idPlanoAnterior = (int)$troca['id_plano_anterior'];
    if ((int)$assinatura['id_assinatura'] !== (int)$troca['id_assinatura'] || (int)$assinatura['id_plano'] !== $idPlanoAnterior) {
        return assinaturaServicoNovaContratacaoResultado(false, 'CONTRATO_ALTERADO', 'O contrato mudou desde a solicitação da troca. Entre em contato com o suporte.', ['requer_atencao' => true]);
    }
    $planos = assinaturaServicoConsultaFinanceira(
        $conexao,
        'SELECT id_plano, limite_usuarios, limite_proprietarios, limite_profissionais, limite_recepcionistas FROM plano WHERE id_plano = ? LIMIT 1 FOR UPDATE',
        'i',
        $idPlanoNovo
    );
    if ($planos === [] || !assinaturaServicoPlanoComportaEmpresa($conexao, $idEmpresa, $planos[0])) {
        return assinaturaServicoNovaContratacaoResultado(false, 'PLANO_LIMITE_USUARIOS', 'O novo plano não comporta os usuários ativos da empresa. Ajuste os vínculos ou entre em contato com o suporte.', ['requer_atencao' => true]);
    }
    $valorNovo = (string)$troca['valor_novo'];
    $idAssinatura = (int)$assinatura['id_assinatura'];
    $stmt = $conexao->prepare(
        "UPDATE assinatura SET id_plano = ?, valor_contratado = ?
          WHERE id_assinatura = ? AND id_empresa = ? AND status = 'ativa' AND modalidade = 'paga' AND id_plano = ? AND data_fim IS NULL"
    );
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar a troca de plano da assinatura.');
    }
    $stmt->bind_param('isiii', $idPlanoNovo, $valorNovo, $idAssinatura, $idEmpresa, $idPlanoAnterior);
    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('A troca de plano não alterou exatamente uma assinatura.');
    }
    $stmt->close();
    $stmt = $conexao->prepare('UPDATE empresa SET plano_id = ? WHERE id_empresa = ? AND plano_id = ?');
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar a troca do plano da empresa.');
    }
    $stmt->bind_param('iii', $idPlanoNovo, $idEmpresa, $idPlanoAnterior);
    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('Falha ao atualizar o plano da empresa.');
    }
    $stmt->close();

    return null;
}

/** Atualiza o status da troca de forma condicional (idempotência). */
function assinaturaServicoAtualizarTroca(mysqli $conexao, int $idTroca, string $de, string $para, ?string $dataEfetivacao = null): void
{
    $sql = 'UPDATE assinatura_troca_plano SET status = ?, concluido_em = CURRENT_TIMESTAMP'
        . ($dataEfetivacao !== null ? ', data_efetivacao = ?' : '')
        . ' WHERE id_troca = ? AND status = ?';
    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar a atualização da troca de plano.');
    }
    if ($dataEfetivacao !== null) {
        $stmt->bind_param('ssis', $para, $dataEfetivacao, $idTroca, $de);
    } else {
        $stmt->bind_param('sis', $para, $idTroca, $de);
    }
    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('A troca de plano foi alterada por outra operação.');
    }
    $stmt->close();
}

/**
 * Ativa um upgrade (pago ou sem valor). Idempotente: só ativa a troca em 'aguardando_pagamento'. Participa da
 * transação do chamador. Notificações e auditoria somente quando ativa de fato.
 */
function assinaturaServicoAtivarUpgrade(mysqli $conexao, array $contexto, array $troca, ?array $ator, array $contextoAuditoria, bool $pagamentoConfirmado): array
{
    $aplicacao = assinaturaServicoAplicarPlanoTroca($conexao, $contexto, $troca);
    if ($aplicacao !== null) {
        return $aplicacao;
    }
    $idEmpresa = (int)$contexto['empresa']['id_empresa'];
    $idAssinatura = (int)$troca['id_assinatura'];
    $hoje = (string)$contexto['hoje'];
    assinaturaServicoAtualizarTroca($conexao, (int)$troca['id_troca'], 'aguardando_pagamento', 'ativada', $hoje);
    // Preapproval: o novo valor recorrente vai ao outbox (uma atualização externa por troca); o upgrade já pago não depende dele.
    assinaturaServicoPreapprovalSincronizarValorNaTransacao($conexao, $idEmpresa, $idAssinatura, $ator);
    $resumo = assinaturaServicoTrocaResumo($conexao, array_merge($troca, ['status' => 'ativada', 'data_efetivacao' => $hoje]));
    $base = ['origem' => 'troca_plano'] + $contextoAuditoria + ['id_empresa' => $idEmpresa, 'id_assinatura' => $idAssinatura, 'id_plano' => (int)$troca['id_plano_novo']];
    if ($pagamentoConfirmado) {
        assinaturaServicoAuditar($conexao, 'assinatura.upgrade_pagamento_confirmado', [
            'entidade_id' => $idAssinatura,
            'entidade_rotulo' => "Assinatura #{$idAssinatura}",
            'descricao' => sprintf('Confirmou o pagamento proporcional de %s do upgrade #%d.', assinaturaServicoMoeda(pagamentoGatewayValorCentavos((string)$resumo['valor_proporcional'])), $resumo['id_troca']),
            'alteracoes' => assinaturaServicoTrocaAlteracoes($resumo),
            'contexto' => $base + ['motivo' => 'pagamento_proporcional_confirmado'],
        ], $ator);
    }
    assinaturaServicoAuditar($conexao, 'assinatura.upgrade_ativado', [
        'entidade_id' => $idAssinatura,
        'entidade_rotulo' => "Assinatura #{$idAssinatura}",
        'descricao' => sprintf('Ativou o upgrade do plano %s para o %s.', $resumo['plano_anterior'], $resumo['plano_novo']),
        'alteracoes' => assinaturaServicoTrocaAlteracoes($resumo, [])
            + ['id_plano' => ['antes' => $resumo['id_plano_anterior'], 'depois' => $resumo['id_plano_novo']],
               'valor_contratado' => ['antes' => $resumo['valor_anterior'], 'depois' => $resumo['valor_novo']]],
        'contexto' => $base + ['motivo' => $pagamentoConfirmado ? 'upgrade_pago' : 'upgrade_sem_valor_adicional'],
    ], $ator);
    assinaturaServicoReconciliarNotificacaoUpgrade($conexao, $idEmpresa, (int)$troca['id_troca'], true);
    assinaturaServicoNotificarUpgradeConcluido($conexao, $idEmpresa, $resumo);

    return assinaturaServicoNovaContratacaoResultado(true, null, sprintf('Upgrade concluído. O plano %s já está liberado.', $resumo['plano_novo']), [
        'resultado' => 'ativada',
        'id_troca' => (int)$troca['id_troca'],
    ]);
}

/** Lê a troca pelo id com lock. */
function assinaturaServicoTrocaPorId(mysqli $conexao, int $idEmpresa, int $idTroca): ?array
{
    $linhas = assinaturaServicoConsultaFinanceira($conexao, 'SELECT * FROM assinatura_troca_plano WHERE id_troca = ? AND id_empresa = ? LIMIT 1 FOR UPDATE', 'ii', $idTroca, $idEmpresa);

    return $linhas[0] ?? null;
}

/** Situação da cobrança proporcional: quitada, em andamento (transação/pagamento) ou livre. @return array<string, bool> */
function assinaturaServicoSituacaoCobrancaUpgrade(mysqli $conexao, int $idEmpresa, int $idCobranca): array
{
    $cobrancas = assinaturaServicoConsultaFinanceira($conexao, 'SELECT status, valor FROM cobranca WHERE id_cobranca = ? AND id_empresa = ? LIMIT 1 FOR UPDATE', 'ii', $idCobranca, $idEmpresa);
    $cobranca = $cobrancas[0] ?? null;
    $pago = assinaturaServicoTotalPagoCentavos($conexao, $idEmpresa, $idCobranca);
    $valor = $cobranca === null ? 0 : pagamentoGatewayValorCentavos((string)$cobranca['valor']);
    $emAndamento = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT 1 FROM transacao_pagamento
          WHERE id_empresa = ? AND id_cobranca = ?
            AND (status IN ('criada', 'enviando', 'aguardando', 'processando', 'conciliacao')
                 OR requer_conciliacao = 1 OR (status = 'confirmada' AND id_pagamento IS NULL))
          LIMIT 1",
        'ii',
        $idEmpresa,
        $idCobranca
    ) !== [];
    $qualquerPagamento = assinaturaServicoConsultaFinanceira($conexao, 'SELECT 1 FROM pagamento WHERE id_empresa = ? AND id_cobranca = ? LIMIT 1', 'ii', $idEmpresa, $idCobranca) !== [];

    return [
        'existe' => $cobranca !== null,
        'quitada' => $cobranca !== null && (string)$cobranca['status'] === 'paga' && $valor > 0 && $pago >= $valor,
        'em_andamento' => $emAndamento,
        'com_pagamento' => $qualquerPagamento,
        'cancelada' => $cobranca !== null && (string)$cobranca['status'] === 'cancelada',
    ];
}

/**
 * Ordem única de locks das operações administrativas sobre uma cobrança: empresa -> assinaturas (vigentes e a da
 * cobrança) -> trocas pendentes -> (o chamador bloqueia a cobrança). É a mesma ordem do CRON, do Faturamento e do
 * processamento de trocas, evitando deadlock entre cancelamento/baixa/estorno manual e a troca de plano.
 * Deve ser chamada logo após begin_transaction(), antes de bloquear a cobrança. A leitura inicial não bloqueia.
 */
function assinaturaServicoTravarContratoDaCobranca(mysqli $conexao, int $idCobranca): void
{
    $base = assinaturaServicoConsultaFinanceira($conexao, 'SELECT id_empresa, id_assinatura FROM cobranca WHERE id_cobranca = ? LIMIT 1', 'i', $idCobranca)[0] ?? null;
    if ($base === null) {
        return;
    }
    $idEmpresa = (int)$base['id_empresa'];
    assinaturaServicoConsultaFinanceira($conexao, 'SELECT id_empresa FROM empresa WHERE id_empresa = ? LIMIT 1 FOR UPDATE', 'i', $idEmpresa);
    assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT id_assinatura FROM assinatura
          WHERE id_empresa = ? AND (status IN ('ativa', 'suspensa') OR id_assinatura = ?)
          ORDER BY id_assinatura FOR UPDATE",
        'ii',
        $idEmpresa,
        (int)$base['id_assinatura']
    );
    if (assinaturaServicoTrocaEstruturaPendente($conexao) === null) {
        assinaturaServicoConsultaFinanceira(
            $conexao,
            "SELECT id_troca FROM assinatura_troca_plano
              WHERE id_empresa = ? AND status IN ('aguardando_pagamento', 'programada')
              ORDER BY id_troca FOR UPDATE",
            'i',
            $idEmpresa
        );
    }
}

/** Há transação de pagamento iniciada, em processamento ou a conciliar para a cobrança (nada é considerado pago). Dentro da transação do chamador. */
function assinaturaServicoCobrancaPagamentoEmAndamento(mysqli $conexao, int $idEmpresa, int $idCobranca): bool
{
    return assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT 1 FROM transacao_pagamento
          WHERE id_empresa = ? AND id_cobranca = ?
            AND (status IN ('criada', 'enviando', 'aguardando', 'processando', 'conciliacao')
                 OR requer_conciliacao = 1 OR (status = 'confirmada' AND id_pagamento IS NULL))
          LIMIT 1",
        'ii',
        $idEmpresa,
        $idCobranca
    ) !== [];
}

/**
 * Cobrança proporcional de upgrade cancelada manualmente: a troca em 'aguardando_pagamento' vinculada a ela é
 * encerrada como cancelada (o plano atual permanece) e a notificação pendente é reconciliada. Neutro para
 * qualquer outra cobrança e idempotente. Participa da transação do chamador.
 */
function assinaturaServicoEncerrarTrocaPorCobrancaCancelada(mysqli $conexao, int $idEmpresa, int $idCobranca, ?array $ator, array $contextoAuditoria = []): bool
{
    if (assinaturaServicoTrocaEstruturaPendente($conexao) !== null) {
        return false;
    }
    $trocas = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT * FROM assinatura_troca_plano
          WHERE id_empresa = ? AND id_cobranca = ? AND tipo = 'upgrade' AND status = 'aguardando_pagamento'
          LIMIT 1 FOR UPDATE",
        'ii',
        $idEmpresa,
        $idCobranca
    );
    if ($trocas === []) {
        return false;
    }
    $troca = $trocas[0];
    assinaturaServicoAtualizarTroca($conexao, (int)$troca['id_troca'], 'aguardando_pagamento', 'cancelada');
    assinaturaServicoReconciliarNotificacaoUpgrade($conexao, $idEmpresa, (int)$troca['id_troca'], false);
    $resumo = assinaturaServicoTrocaResumo($conexao, $troca);
    assinaturaServicoAuditar($conexao, 'assinatura.troca_cancelada', [
        'entidade_id' => (int)$troca['id_assinatura'],
        'entidade_rotulo' => 'Assinatura #' . (int)$troca['id_assinatura'],
        'descricao' => sprintf('Encerrou a troca #%d (%s para %s) como cancelada: cobrança proporcional cancelada.', (int)$troca['id_troca'], $resumo['plano_anterior'], $resumo['plano_novo']),
        'alteracoes' => assinaturaServicoTrocaAlteracoes($resumo) + ['status' => ['antes' => 'aguardando_pagamento', 'depois' => 'cancelada']],
        'contexto' => ['origem' => 'troca_plano'] + $contextoAuditoria + ['id_empresa' => $idEmpresa, 'id_assinatura' => (int)$troca['id_assinatura'], 'motivo' => 'cobranca_cancelada'],
    ], $ator);

    return true;
}

/** Cancela a troca pendente (upgrade sem pagamento ou downgrade programado), com cobrança e notificações. */
function assinaturaServicoEncerrarTroca(mysqli $conexao, int $idEmpresa, array $troca, string $statusFinal, string $motivo, ?array $ator, array $contextoAuditoria = []): ?array
{
    $idTroca = (int)$troca['id_troca'];
    if ((string)$troca['tipo'] === 'upgrade' && $troca['id_cobranca'] !== null) {
        $situacao = assinaturaServicoSituacaoCobrancaUpgrade($conexao, $idEmpresa, (int)$troca['id_cobranca']);
        if ($situacao['em_andamento'] || $situacao['com_pagamento'] || $situacao['quitada']) {
            return assinaturaServicoNovaContratacaoResultado(false, 'PAGAMENTO_EM_ANDAMENTO', 'O pagamento do upgrade já foi iniciado e precisa ser concluído ou conciliado antes do cancelamento.');
        }
        if (!$situacao['cancelada'] && $situacao['existe']) {
            $idCobranca = (int)$troca['id_cobranca'];
            $stmt = $conexao->prepare("UPDATE cobranca SET status = 'cancelada' WHERE id_cobranca = ? AND id_empresa = ? AND status = 'pendente'");
            if (!$stmt) {
                throw new RuntimeException('Falha ao preparar o cancelamento da cobrança do upgrade.');
            }
            $stmt->bind_param('ii', $idCobranca, $idEmpresa);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException('Falha ao cancelar a cobrança do upgrade.');
            }
            $stmt->close();
            assinaturaServicoAuditar($conexao, 'cobranca.cancelada', [
                'entidade_id' => $idCobranca,
                'entidade_rotulo' => "Cobrança #{$idCobranca}",
                'descricao' => "Cancelou a cobrança proporcional #{$idCobranca} do upgrade #{$idTroca} ({$motivo}).",
                'alteracoes' => assinaturaServicoAlteracoesNovas(['id_empresa' => $idEmpresa, 'id_cobranca' => $idCobranca,
                    'valor' => (string)$troca['valor_proporcional'], 'motivo' => $motivo]) + ['status' => ['antes' => 'pendente', 'depois' => 'cancelada']],
                'contexto' => ['origem' => 'troca_plano', 'id_empresa' => $idEmpresa, 'id_assinatura' => (int)$troca['id_assinatura'], 'motivo' => $motivo],
            ], $ator);
        }
        assinaturaServicoReconciliarNotificacaoUpgrade($conexao, $idEmpresa, $idTroca, false);
    }
    assinaturaServicoAtualizarTroca($conexao, $idTroca, (string)$troca['status'], $statusFinal);
    assinaturaServicoPreapprovalReverterValor($conexao, $idEmpresa, $troca, $ator);
    $resumo = assinaturaServicoTrocaResumo($conexao, $troca);
    assinaturaServicoAuditar($conexao, 'assinatura.troca_cancelada', [
        'entidade_id' => (int)$troca['id_assinatura'],
        'entidade_rotulo' => 'Assinatura #' . (int)$troca['id_assinatura'],
        'descricao' => sprintf('Encerrou a troca #%d (%s para %s) como %s: %s.', $idTroca, $resumo['plano_anterior'], $resumo['plano_novo'], $statusFinal, $motivo),
        'alteracoes' => assinaturaServicoTrocaAlteracoes($resumo) + ['status' => ['antes' => (string)$troca['status'], 'depois' => $statusFinal]],
        'contexto' => ['origem' => 'troca_plano'] + $contextoAuditoria + ['id_empresa' => $idEmpresa, 'id_assinatura' => (int)$troca['id_assinatura'], 'motivo' => $motivo],
    ], $ator);

    return null;
}

/**
 * Cobrança proporcional recusada por chave única (MySQL 1062). Nada foi gravado. A causa é identificada pelo índice:
 * - uq_cobranca_upgrade_pendente: já existe cobrança de upgrade pendente para o contrato (solicitação concorrente);
 * - uq_cobranca_periodo_ativo: banco ainda sem a migration que separa a mensalidade da cobrança proporcional, e o
 *   upgrade cai exatamente no período da mensalidade (contratação e upgrade no mesmo dia).
 */
function assinaturaServicoUpgradeCobrancaConflitante(string $erroBanco): array
{
    if (str_contains($erroBanco, 'uq_cobranca_upgrade_pendente')) {
        return assinaturaServicoNovaContratacaoResultado(false, 'TROCA_PENDENTE', 'Já existe uma alteração de plano aguardando pagamento. Conclua ou resolva essa solicitação antes de iniciar outra.');
    }
    if (str_contains($erroBanco, 'uq_cobranca_periodo_ativo')) {
        error_log('ASSINATURA_UPGRADE_INDICE_PERIODO_ATIVO: migration da cobrança proporcional pendente.');

        return assinaturaServicoNovaContratacaoResultado(false, 'COBRANCA_CONFLITANTE', 'Não foi possível registrar a cobrança proporcional porque o período coincide com o da sua mensalidade atual, e o sistema ainda não permite essa situação. Nenhum valor foi cobrado. Entre em contato com o suporte para concluir a alteração de plano.');
    }

    return assinaturaServicoNovaContratacaoResultado(false, 'COBRANCA_CONFLITANTE', 'Não foi possível registrar a cobrança proporcional por um conflito com outra cobrança do contrato. Nenhum valor foi cobrado. Entre em contato com o suporte.');
}

/**
 * Solicita a troca de plano pelo Proprietário. Participa da transação do chamador.
 * $valorConfirmadoCentavos é o valor exibido ao cliente: se o cálculo atual divergir, nada é gravado e a
 * nova prévia é devolvida para nova confirmação (o valor cobrado é sempre o valor apresentado).
 */
function assinaturaServicoSolicitarTrocaPlano(mysqli $conexao, int $idEmpresa, int $idPlano, ?int $valorConfirmadoCentavos, ?array $ator = null): array
{
    $contexto = assinaturaServicoTrocaContexto($conexao, $idEmpresa);
    if (!$contexto['ok']) {
        return $contexto;
    }
    $assinatura = $contexto['assinatura'];
    $plano = assinaturaServicoTrocaPlanos($conexao, $idEmpresa, $assinatura, $idPlano)[0] ?? null;
    if ($plano === null) {
        return assinaturaServicoNovaContratacaoResultado(false, 'PLANO_INDISPONIVEL', 'O plano escolhido não está disponível para troca. A troca é feita entre planos públicos com a mesma periodicidade.');
    }
    $pendente = $contexto['troca'];
    if ($pendente !== null) {
        if ((string)$pendente['tipo'] === 'upgrade' && (string)$pendente['status'] === 'aguardando_pagamento'
            && (int)$pendente['id_plano_novo'] === $idPlano && (string)$pendente['expira_em'] >= date('Y-m-d H:i:s')) {
            $resumo = assinaturaServicoTrocaResumo($conexao, $pendente);

            return assinaturaServicoNovaContratacaoResultado(true, null, 'Upgrade já solicitado. Pague a cobrança proporcional para liberar o novo plano.', [
                'resultado' => 'reutilizada', 'id_troca' => (int)$pendente['id_troca'], 'id_cobranca' => $resumo['id_cobranca'], 'valor' => $resumo['valor_proporcional'],
            ]);
        }

        return assinaturaServicoNovaContratacaoResultado(false, 'TROCA_PENDENTE', (string)$pendente['tipo'] === 'upgrade'
            ? 'Já existe uma alteração de plano aguardando pagamento. Conclua ou resolva essa solicitação antes de iniciar outra.'
            : 'Já existe uma alteração de plano programada. Cancele-a antes de solicitar outra.');
    }
    $bloqueio = assinaturaServicoTrocaBloqueioFinanceiro($conexao, $idEmpresa);
    if ($bloqueio !== null) {
        return $bloqueio;
    }

    $previa = $plano['tipo'] === 'upgrade'
        ? assinaturaServicoPreviaUpgrade($conexao, $contexto, $plano)
        : assinaturaServicoPreviaDowngrade($conexao, $contexto, $plano);
    if (!$previa['ok']) {
        return $previa;
    }
    $valorAtual = $plano['tipo'] === 'upgrade' ? (int)$previa['valor_proporcional_centavos'] : (int)$plano['valor_centavos'];
    if ($valorConfirmadoCentavos === null || $valorConfirmadoCentavos !== $valorAtual) {
        return assinaturaServicoNovaContratacaoResultado(false, 'VALOR_ALTERADO', 'Os valores foram atualizados. Revise o novo cálculo antes de confirmar.', ['previa' => $previa]);
    }

    $idAssinatura = (int)$assinatura['id_assinatura'];
    $valorAnterior = pagamentoGatewayValorDecimal(pagamentoGatewayValorCentavos(number_format((float)$assinatura['valor_contratado'], 2, '.', '')));
    $hoje = (string)$contexto['hoje'];

    if ($plano['tipo'] === 'downgrade') {
        if (!$plano['comporta_usuarios']) {
            return assinaturaServicoNovaContratacaoResultado(false, 'PLANO_LIMITE_USUARIOS', 'O plano escolhido não comporta os usuários ativos.');
        }
        $stmt = $conexao->prepare(
            "INSERT INTO assinatura_troca_plano
                (id_empresa, id_assinatura, tipo, status, id_plano_anterior, id_plano_novo, valor_anterior, valor_novo, periodicidade, data_efetivacao)
             VALUES (?, ?, 'downgrade', 'programada', ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            throw new RuntimeException('Falha ao preparar o downgrade programado.');
        }
        $idPlanoAnterior = (int)$assinatura['id_plano'];
        $valorNovo = (string)$plano['valor'];
        $periodicidade = (string)$assinatura['periodicidade'];
        $efetivacao = (string)$previa['data_efetivacao'];
        $stmt->bind_param('iiiissss', $idEmpresa, $idAssinatura, $idPlanoAnterior, $idPlano, $valorAnterior, $valorNovo, $periodicidade, $efetivacao);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Falha ao registrar o downgrade programado.');
        }
        $idTroca = (int)$stmt->insert_id;
        $stmt->close();
        // Preapproval: atualização do valor agendada para antes da efetivação; a efetivação local depende da confirmação externa.
        assinaturaServicoPreapprovalSincronizarValorNaTransacao($conexao, $idEmpresa, $idAssinatura, $ator);
        $resumo = assinaturaServicoTrocaResumo($conexao, assinaturaServicoTrocaPorId($conexao, $idEmpresa, $idTroca));
        assinaturaServicoAuditar($conexao, 'assinatura.downgrade_programado', [
            'entidade_id' => $idAssinatura,
            'entidade_rotulo' => "Assinatura #{$idAssinatura}",
            'descricao' => sprintf('Programou o downgrade do plano %s para o %s a partir de %s.', $resumo['plano_anterior'], $resumo['plano_novo'], assinaturaServicoDataBr($efetivacao)),
            'alteracoes' => assinaturaServicoTrocaAlteracoes($resumo),
            'contexto' => ['origem' => 'troca_plano', 'id_empresa' => $idEmpresa, 'id_assinatura' => $idAssinatura, 'id_plano' => $idPlano, 'motivo' => 'downgrade_programado'],
        ], $ator);

        return assinaturaServicoNovaContratacaoResultado(true, null, (string)$previa['mensagem'], ['resultado' => 'programada', 'id_troca' => $idTroca, 'data_efetivacao' => $efetivacao]);
    }

    // Upgrade: snapshot do cálculo apresentado; cobrança proporcional única por solicitação.
    $expira = assinaturaServicoData($hoje)->modify('+1 day')->format('Y-m-d');
    if ($expira > (string)$previa['ciclo_fim']) {
        $expira = (string)$previa['ciclo_fim'];
    }
    $expiraEm = $expira . ' 23:59:59';
    $idPlanoAnterior = (int)$assinatura['id_plano'];
    $valorNovo = (string)$plano['valor'];
    $periodicidade = (string)$assinatura['periodicidade'];
    $valorCiclo = (string)$previa['valor_atual'];
    $cicloInicio = (string)$previa['ciclo_inicio'];
    $cicloFim = (string)$previa['ciclo_fim'];
    $diasCiclo = (int)$previa['dias_ciclo'];
    $diasRestantes = (int)$previa['dias_restantes'];
    $valorProporcional = (string)$previa['valor_proporcional'];

    // A cobrança vem antes da troca: se o banco recusar a cobrança, nenhuma troca é gravada (nem depende do rollback do
    // chamador) e jamais existe troca com valor a pagar sem cobrança vinculada.
    $idCobranca = null;
    $finalidade = ASSINATURA_TROCA_FINALIDADE_UPGRADE;
    if ((int)$previa['valor_proporcional_centavos'] > 0) {
        $stmt = $conexao->prepare(
            "INSERT INTO cobranca (id_empresa, id_assinatura, periodo_inicio, periodo_fim, data_vencimento, valor, status, finalidade)
             VALUES (?, ?, ?, ?, ?, ?, 'pendente', ?)"
        );
        if (!$stmt) {
            throw new RuntimeException('Falha ao preparar a cobrança proporcional.');
        }
        $stmt->bind_param('iisssss', $idEmpresa, $idAssinatura, $hoje, $cicloFim, $expira, $valorProporcional, $finalidade);
        try {
            $ok = $stmt->execute();
            $errno = (int)$stmt->errno;
            $erroBanco = (string)$stmt->error;
        } catch (mysqli_sql_exception $erro) {
            $ok = false;
            $errno = (int)$erro->getCode();
            $erroBanco = $erro->getMessage();
        }
        if (!$ok) {
            $stmt->close();
            if ($errno === 1062) {
                return assinaturaServicoUpgradeCobrancaConflitante($erroBanco);
            }
            throw new RuntimeException('Falha ao criar a cobrança proporcional.', $errno);
        }
        $idCobranca = (int)$stmt->insert_id;
        $stmt->close();
    }

    $stmt = $conexao->prepare(
        "INSERT INTO assinatura_troca_plano
            (id_empresa, id_assinatura, tipo, status, id_plano_anterior, id_plano_novo, valor_anterior, valor_novo, periodicidade,
             valor_ciclo, ciclo_inicio, ciclo_fim, dias_ciclo, dias_restantes, valor_proporcional, id_cobranca, expira_em)
         VALUES (?, ?, 'upgrade', 'aguardando_pagamento', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar a solicitação de upgrade.');
    }
    $stmt->bind_param('iiiissssssiisis', $idEmpresa, $idAssinatura, $idPlanoAnterior, $idPlano, $valorAnterior, $valorNovo, $periodicidade,
        $valorCiclo, $cicloInicio, $cicloFim, $diasCiclo, $diasRestantes, $valorProporcional, $idCobranca, $expiraEm);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Falha ao registrar a solicitação de upgrade.');
    }
    $idTroca = (int)$stmt->insert_id;
    $stmt->close();

    if ($idCobranca !== null) {
        assinaturaServicoAuditar($conexao, 'cobranca.gerada', [
            'entidade_id' => $idCobranca,
            'entidade_rotulo' => "Cobrança proporcional do upgrade #{$idTroca}",
            'descricao' => "Gerou a cobrança proporcional do upgrade #{$idTroca}.",
            'alteracoes' => assinaturaServicoAlteracoesNovas(['id_empresa' => $idEmpresa, 'id_assinatura' => $idAssinatura, 'periodo_inicio' => $hoje,
                'periodo_fim' => $cicloFim, 'data_vencimento' => $expira, 'valor' => $valorProporcional, 'status' => 'pendente', 'finalidade' => $finalidade]),
            'contexto' => ['origem' => 'troca_plano', 'origem_geracao' => 'upgrade_proporcional', 'id_empresa' => $idEmpresa, 'id_assinatura' => $idAssinatura],
        ], $ator);
    }

    $troca = assinaturaServicoTrocaPorId($conexao, $idEmpresa, $idTroca);
    $resumo = assinaturaServicoTrocaResumo($conexao, $troca);
    assinaturaServicoAuditar($conexao, 'assinatura.troca_solicitada', [
        'entidade_id' => $idAssinatura,
        'entidade_rotulo' => "Assinatura #{$idAssinatura}",
        'descricao' => sprintf('Solicitou o upgrade do plano %s para o %s (%s).', $resumo['plano_anterior'], $resumo['plano_novo'], (string)$previa['formula']),
        'alteracoes' => assinaturaServicoTrocaAlteracoes($resumo),
        'contexto' => ['origem' => 'troca_plano', 'id_empresa' => $idEmpresa, 'id_assinatura' => $idAssinatura, 'id_plano' => $idPlano, 'motivo' => 'upgrade_solicitado'],
    ], $ator);

    if ((int)$previa['valor_proporcional_centavos'] === 0) {
        return assinaturaServicoAtivarUpgrade($conexao, $contexto, $troca, $ator, [], false);
    }
    assinaturaServicoNotificarUpgradePendente($conexao, $idEmpresa, $resumo);

    return assinaturaServicoNovaContratacaoResultado(true, null, 'Sua solicitação de alteração de plano foi registrada. Conclua o pagamento da diferença proporcional para finalizar o upgrade.', [
        'resultado' => 'aguardando_pagamento', 'id_troca' => $idTroca, 'id_cobranca' => $resumo['id_cobranca'], 'valor' => $resumo['valor_proporcional'],
    ]);
}

/**
 * Conclui o upgrade pendente quando a cobrança proporcional está integralmente paga e confirmada.
 * Idempotente e seguro para chamadas concorrentes (Proprietário, CRON): a troca é bloqueada e só
 * 'aguardando_pagamento' é ativada. PIX gerado ou transação em andamento não ativam.
 */
function assinaturaServicoConcluirUpgrade(mysqli $conexao, int $idEmpresa, ?array $ator = null, array $contextoAuditoria = []): array
{
    $contexto = assinaturaServicoTrocaContexto($conexao, $idEmpresa);
    if (!$contexto['ok']) {
        return $contexto;
    }
    $troca = $contexto['troca'];
    if ($troca === null || (string)$troca['tipo'] !== 'upgrade' || (string)$troca['status'] !== 'aguardando_pagamento') {
        return assinaturaServicoNovaContratacaoResultado(false, 'SEM_UPGRADE_PENDENTE', 'Não há upgrade aguardando pagamento.');
    }
    if ($troca['id_cobranca'] === null) {
        // Sem cobrança só é legítimo quando não há valor a pagar; troca com valor e sem cobrança é registro incompleto.
        if (pagamentoGatewayValorCentavos((string)($troca['valor_proporcional'] ?? '0')) > 0) {
            return assinaturaServicoNovaContratacaoResultado(false, 'TROCA_INCONSISTENTE', 'A alteração de plano está sem a cobrança da diferença proporcional. Entre em contato com o suporte.', ['requer_atencao' => true]);
        }

        return assinaturaServicoAtivarUpgrade($conexao, $contexto, $troca, $ator, $contextoAuditoria, false);
    }
    $situacao = assinaturaServicoSituacaoCobrancaUpgrade($conexao, $idEmpresa, (int)$troca['id_cobranca']);
    if ($situacao['em_andamento']) {
        return assinaturaServicoNovaContratacaoResultado(false, 'PAGAMENTO_AGUARDANDO_CONFIRMACAO', 'O pagamento do upgrade ainda está em confirmação.');
    }
    if (!$situacao['quitada']) {
        return assinaturaServicoNovaContratacaoResultado(false, 'PAGAMENTO_PENDENTE', 'O pagamento proporcional do upgrade ainda não foi confirmado.');
    }

    return assinaturaServicoAtivarUpgrade($conexao, $contexto, $troca, $ator, $contextoAuditoria, true);
}

/** Cancela, a pedido do Proprietário, o upgrade sem pagamento iniciado ou o downgrade programado. */
function assinaturaServicoCancelarTrocaPlano(mysqli $conexao, int $idEmpresa, ?array $ator = null): array
{
    $contexto = assinaturaServicoTrocaContexto($conexao, $idEmpresa);
    if (!$contexto['ok']) {
        return $contexto;
    }
    $troca = $contexto['troca'];
    if ($troca === null) {
        return assinaturaServicoNovaContratacaoResultado(false, 'SEM_TROCA_PENDENTE', 'Não há troca de plano pendente.');
    }
    $bloqueio = assinaturaServicoEncerrarTroca($conexao, $idEmpresa, $troca, 'cancelada', 'cancelada_pelo_proprietario', $ator, ['origem' => 'painel_administrativo']);
    if ($bloqueio !== null) {
        return $bloqueio;
    }

    return assinaturaServicoNovaContratacaoResultado(true, null, 'Troca de plano cancelada.', ['resultado' => 'cancelada', 'id_troca' => (int)$troca['id_troca']]);
}

/**
 * Processa as trocas pendentes de uma empresa (CRON e geração manual): ativa upgrades pagos, expira upgrades
 * vencidos sem pagamento, efetiva downgrades devidos e encerra trocas cujo contrato deixou de ser elegível.
 * Participa da transação do chamador.
 *
 * @return array{ativados: int, expirados: int, downgrades: int, encerradas: int, atencao: int}
 */
function assinaturaServicoProcessarTrocasEmpresa(mysqli $conexao, int $idEmpresa, array $ator): array
{
    $resumo = ['ativados' => 0, 'expirados' => 0, 'downgrades' => 0, 'encerradas' => 0, 'atencao' => 0];
    if (assinaturaServicoTrocaEstruturaPendente($conexao) !== null) {
        return $resumo;
    }
    $contexto = assinaturaServicoTrocaContexto($conexao, $idEmpresa);
    $pendentes = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT * FROM assinatura_troca_plano WHERE id_empresa = ? AND status IN ('aguardando_pagamento', 'programada') ORDER BY id_troca FOR UPDATE",
        'i',
        $idEmpresa
    );
    foreach ($pendentes as $troca) {
        $contratoValido = $contexto['ok'] && (int)$contexto['assinatura']['id_assinatura'] === (int)$troca['id_assinatura']
            && (int)$contexto['assinatura']['id_plano'] === (int)$troca['id_plano_anterior'];
        if ((string)$troca['tipo'] === 'upgrade') {
            $situacao = $troca['id_cobranca'] === null ? null : assinaturaServicoSituacaoCobrancaUpgrade($conexao, $idEmpresa, (int)$troca['id_cobranca']);
            if ($situacao !== null && $situacao['quitada'] && !$situacao['em_andamento']) {
                if (!$contratoValido) {
                    $resumo['atencao']++; // Pago, mas o contrato mudou: exige análise, nada é revertido.
                    continue;
                }
                $resultado = assinaturaServicoAtivarUpgrade($conexao, $contexto, $troca, $ator, ['origem' => 'geracao_automatica'], true);
                $resultado['ok'] ? $resumo['ativados']++ : $resumo['atencao']++;
                continue;
            }
            $expirada = (string)$troca['expira_em'] < date('Y-m-d H:i:s');
            if (($expirada || !$contratoValido) && ($situacao === null || (!$situacao['em_andamento'] && !$situacao['com_pagamento']))) {
                assinaturaServicoEncerrarTroca($conexao, $idEmpresa, $troca, 'expirada', $expirada ? 'expirada_sem_pagamento' : 'contrato_alterado', $ator, ['origem' => 'geracao_automatica']);
                $resumo['expirados']++;
            } elseif ($expirada || !$contratoValido) {
                $resumo['atencao']++;
            }
            continue;
        }
        // Downgrade programado.
        if (!$contratoValido) {
            assinaturaServicoEncerrarTroca($conexao, $idEmpresa, $troca, 'cancelada', 'contrato_alterado', $ator, ['origem' => 'geracao_automatica']);
            $resumo['encerradas']++;
            continue;
        }
        if ((string)$troca['data_efetivacao'] > (string)$contexto['hoje']) {
            continue;
        }
        if (assinaturaServicoPreapprovalBloqueioEfetivacao($conexao, $idEmpresa, $troca, $ator) !== null) {
            // Preapproval ainda com o valor antigo: o downgrade não é concluído em silêncio; fica programado e sinalizado.
            $resumo['atencao']++;
            continue;
        }
        $aplicacao = assinaturaServicoAplicarPlanoTroca($conexao, $contexto, $troca);
        if ($aplicacao !== null) {
            // Limites não comportados na data: o downgrade é cancelado e o plano atual permanece (nada é excluído).
            assinaturaServicoEncerrarTroca($conexao, $idEmpresa, $troca, 'cancelada', 'limites_incompativeis', $ator, ['origem' => 'geracao_automatica']);
            $resumo['encerradas']++;
            continue;
        }
        assinaturaServicoAtualizarTroca($conexao, (int)$troca['id_troca'], 'programada', 'efetivada');
        $dados = assinaturaServicoTrocaResumo($conexao, $troca);
        $idAssinatura = (int)$troca['id_assinatura'];
        assinaturaServicoAuditar($conexao, 'assinatura.downgrade_efetivado', [
            'entidade_id' => $idAssinatura,
            'entidade_rotulo' => "Assinatura #{$idAssinatura}",
            'descricao' => sprintf('Efetivou o downgrade do plano %s para o %s na competência iniciada em %s.', $dados['plano_anterior'], $dados['plano_novo'], assinaturaServicoDataBr($dados['data_efetivacao'])),
            'alteracoes' => assinaturaServicoTrocaAlteracoes($dados)
                + ['id_plano' => ['antes' => $dados['id_plano_anterior'], 'depois' => $dados['id_plano_novo']],
                   'valor_contratado' => ['antes' => $dados['valor_anterior'], 'depois' => $dados['valor_novo']]],
            'contexto' => ['origem' => 'geracao_automatica', 'id_empresa' => $idEmpresa, 'id_assinatura' => $idAssinatura, 'id_plano' => $dados['id_plano_novo'], 'motivo' => 'downgrade_efetivado'],
        ], $ator);
        $resumo['downgrades']++;
        $contexto = assinaturaServicoTrocaContexto($conexao, $idEmpresa);
    }

    return $resumo;
}

/** Empresas com trocas pendentes (candidatas do CRON). Leitura sem bloqueio. @return list<int> */
function assinaturaServicoListarEmpresasComTroca(mysqli $conexao): array
{
    if (assinaturaServicoTrocaEstruturaPendente($conexao) !== null) {
        return [];
    }

    return array_map(
        static fn (array $linha): int => (int)$linha['id_empresa'],
        assinaturaServicoConsultaFinanceira($conexao, "SELECT DISTINCT id_empresa FROM assinatura_troca_plano WHERE status IN ('aguardando_pagamento', 'programada') ORDER BY id_empresa")
    );
}

/**
 * Downgrade programado que já deveria valer no período a gerar: a geração manual não pode cobrar o preço
 * antigo numa competência do novo plano. Sem a estrutura, não há trocas.
 */
function assinaturaServicoDowngradeProgramadoAte(mysqli $conexao, int $idAssinatura, string $periodoInicio): bool
{
    if (assinaturaServicoTrocaEstruturaPendente($conexao) !== null) {
        return false;
    }

    return assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT 1 FROM assinatura_troca_plano WHERE id_assinatura = ? AND tipo = 'downgrade' AND status = 'programada' AND data_efetivacao <= ? LIMIT 1",
        'is',
        $idAssinatura,
        $periodoInicio
    ) !== [];
}

/** Há troca pendente para a empresa (bloqueia troca administrativa paralela). */
function assinaturaServicoTrocaPendenteEmpresa(mysqli $conexao, int $idEmpresa): bool
{
    if (assinaturaServicoTrocaEstruturaPendente($conexao) !== null) {
        return false;
    }

    return assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT 1 FROM assinatura_troca_plano WHERE id_empresa = ? AND status IN ('aguardando_pagamento', 'programada') LIMIT 1",
        'i',
        $idEmpresa
    ) !== [];
}

/**
 * Situação da troca para o faturamento do Proprietário. Avalia sob lock dentro de uma transação desfeita ao
 * final (nada é gravado). Retorna null quando não há contrato pago ativo.
 *
 * @return array<string, mixed>|null
 */
function assinaturaServicoSituacaoTrocaPlano(mysqli $conexao, int $idEmpresa): ?array
{
    $ativa = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT 1 FROM assinatura WHERE id_empresa = ? AND status = 'ativa' AND modalidade = 'paga' LIMIT 1",
        'i',
        $idEmpresa
    );
    if ($ativa === []) {
        return null;
    }
    if (!$conexao->begin_transaction()) {
        throw new RuntimeException('Falha ao iniciar a consulta da troca de plano.');
    }
    try {
        $contexto = assinaturaServicoTrocaContexto($conexao, $idEmpresa);
        if (!$contexto['ok']) {
            return ['disponivel' => false, 'codigo' => $contexto['codigo'], 'mensagem' => $contexto['mensagem'], 'opcoes' => [], 'pendente' => null];
        }
        $assinatura = $contexto['assinatura'];
        $situacao = [
            'disponivel' => false,
            'codigo' => null,
            'mensagem' => '',
            'plano_atual' => (string)$assinatura['plano'],
            'valor_atual' => pagamentoGatewayValorDecimal(pagamentoGatewayValorCentavos(number_format((float)$assinatura['valor_contratado'], 2, '.', ''))),
            'periodicidade' => (string)$assinatura['periodicidade'],
            'plano_atual_dados' => null,
            'opcoes' => [],
            'pendente' => null,
        ];
        // Dados de exibição do plano atual (mesmos campos do site público); não participa de regra financeira.
        $dadosAtual = assinaturaServicoConsultaFinanceira(
            $conexao,
            'SELECT id_plano, nome, descricao, limite_usuarios, limite_proprietarios, limite_profissionais,
                    limite_recepcionistas, limite_servicos, limite_agendamentos, agenda_online, ref
               FROM plano WHERE id_plano = ? LIMIT 1',
            'i',
            (int)$assinatura['id_plano']
        );
        if ($dadosAtual !== []) {
            $d = $dadosAtual[0];
            $situacao['plano_atual_dados'] = [
                'id_plano' => (int)$d['id_plano'],
                'nome' => (string)$d['nome'],
                'valor' => $situacao['valor_atual'],
                'periodicidade' => $situacao['periodicidade'],
                'descricao' => (string)($d['descricao'] ?? ''),
                'limite_usuarios' => (int)$d['limite_usuarios'],
                'limite_proprietarios' => (int)$d['limite_proprietarios'],
                'limite_profissionais' => (int)$d['limite_profissionais'],
                'limite_recepcionistas' => (int)$d['limite_recepcionistas'],
                'limite_servicos' => (int)$d['limite_servicos'],
                'limite_agendamentos' => (int)$d['limite_agendamentos'],
                'agenda_online' => (int)$d['agenda_online'],
                'ordem' => (int)$d['ref'],
            ];
        }
        if ($contexto['troca'] !== null) {
            $troca = $contexto['troca'];
            $resumo = assinaturaServicoTrocaResumo($conexao, $troca);
            if ($troca['id_cobranca'] !== null) {
                $cobranca = assinaturaServicoSituacaoCobrancaUpgrade($conexao, $idEmpresa, (int)$troca['id_cobranca']);
                $resumo['paga'] = $cobranca['quitada'];
                $resumo['em_andamento'] = $cobranca['em_andamento'];
            }
            $situacao['pendente'] = $resumo;
            $situacao['mensagem'] = (string)$troca['tipo'] === 'upgrade'
                ? sprintf('Upgrade para o plano %s aguardando o pagamento proporcional de %s.', $resumo['plano_novo'], assinaturaServicoMoeda(pagamentoGatewayValorCentavos((string)$resumo['valor_proporcional'])))
                : sprintf('Downgrade para o plano %s programado para %s.', $resumo['plano_novo'], assinaturaServicoDataBr($resumo['data_efetivacao']));

            return $situacao;
        }
        $bloqueio = assinaturaServicoTrocaBloqueioFinanceiro($conexao, $idEmpresa);
        if ($bloqueio !== null) {
            $situacao['codigo'] = $bloqueio['codigo'];
            $situacao['mensagem'] = $bloqueio['mensagem'];

            return $situacao;
        }
        foreach (assinaturaServicoTrocaPlanos($conexao, $idEmpresa, $assinatura) as $plano) {
            $previa = $plano['tipo'] === 'upgrade'
                ? assinaturaServicoPreviaUpgrade($conexao, $contexto, $plano)
                : assinaturaServicoPreviaDowngrade($conexao, $contexto, $plano);
            unset($previa['id_ciclo_cobranca']);
            $situacao['opcoes'][] = [
                'id_plano' => $plano['id_plano'],
                'nome' => $plano['nome'],
                'valor' => $plano['valor'],
                'periodicidade' => $plano['periodicidade'],
                'descricao' => $plano['descricao'],
                'limite_usuarios' => $plano['limite_usuarios'],
                'limite_proprietarios' => $plano['limite_proprietarios'],
                'limite_profissionais' => $plano['limite_profissionais'],
                'limite_recepcionistas' => $plano['limite_recepcionistas'],
                'limite_servicos' => $plano['limite_servicos'],
                'limite_agendamentos' => $plano['limite_agendamentos'],
                'agenda_online' => $plano['agenda_online'],
                'destaque' => $plano['destaque'],
                'ordem' => $plano['ordem'],
                'tipo' => $plano['tipo'],
                'disponivel' => (bool)$previa['ok'],
                'valor_confirmacao' => $previa['ok'] ? ($plano['tipo'] === 'upgrade' ? (int)$previa['valor_proporcional_centavos'] : (int)$plano['valor_centavos']) : null,
                'previa' => $previa,
            ];
        }
        $situacao['disponivel'] = array_filter($situacao['opcoes'], static fn (array $opcao): bool => $opcao['disponivel']) !== [];
        $situacao['mensagem'] = $situacao['opcoes'] === [] ? 'Não há outros planos disponíveis para troca com a mesma periodicidade.' : '';

        return $situacao;
    } finally {
        try {
            $conexao->rollback();
        } catch (Throwable) {
        }
    }
}

/*
 * ---------------------------------------------------------------------
 * Subscriptions (Mercado Pago Preapproval): preparo, criação, conciliação e vínculo.
 *
 * Camada interna e DESLIGADA: sem rota, tela, webhook ou CRON. O gateway só envia
 * algo à rede com `subscriptions.habilitado === true`; desligado, nada é reservado
 * nem enviado. O chamador (futuro endpoint) é responsável por sessão, permissão e
 * CSRF e passa apenas `$idEmpresa` extraído da sessão e o e-mail do usuário
 * autenticado; valor, plano e assinatura vêm sempre do contrato interno.
 *
 * Exclusividade e duplicidade:
 * - `assinatura_pagamento_recorrente` tem UNIQUE (id_assinatura): um único vínculo por
 *   contrato. Existindo vínculo Orders (qualquer status) ou de outro ambiente, nada é criado.
 * - A tentativa externa é serializada por `operacao_assinatura_gateway` (tipo 'conciliar'),
 *   cujo índice único de operação aberta impede dois envios simultâneos. A operação só sai de
 *   'processando'/'conciliacao' quando o estado externo é conhecido.
 * - 'resultado_incerto', resposta divergente ou ausência de confirmação mantêm a operação
 *   aberta: nunca há segundo POST enquanto a primeira tentativa não for esclarecida.
 * - O vínculo permanece 'pendente' (slot_ativo NULL): nada é ativado nem cobrado aqui.
 *
 * Deve ser chamada FORA de transação: cada etapa abre e fecha a sua e a chamada externa
 * nunca ocorre com transação ou lock aberto.
 * ---------------------------------------------------------------------
 */

const ASSINATURA_SERVICO_PREAPPROVAL_PROVEDOR = 'mercado_pago';
/** Acima disso uma operação 'processando' é tratada como interrompida (POST tem timeout de 15 s). */
const ASSINATURA_SERVICO_PREAPPROVAL_OPERACAO_INTERROMPIDA_SEGUNDOS = 180;
const ASSINATURA_SERVICO_PREAPPROVAL_BUSCA_LIMITE = 50;
const ASSINATURA_SERVICO_PREAPPROVAL_BUSCA_PAGINAS = 4;
const ASSINATURA_SERVICO_PREAPPROVAL_FUSO = 'America/Sao_Paulo';
/** Antecedência da `start_date` da Preapproval da primeira cobrança em relação ao envio. */
const ASSINATURA_SERVICO_PREAPPROVAL_PRIMEIRA_MARGEM_MINUTOS = 10;
/** Distância máxima (dias) entre o início da oferta e a primeira parcela; a mesma de MP_SUB_TOLERANCIA_COMPETENCIA_DIAS. */
const ASSINATURA_SERVICO_PREAPPROVAL_TOLERANCIA_PRIMEIRA_DIAS = 1;
/** Sufixo opaco que preserva o ciclo de uma Preapproval 2/days antes de existir a primeira competência financeira. */
const ASSINATURA_SERVICO_PREAPPROVAL_REFERENCIA_DIARIA_48H = '.d2';

/** @return array{ok: bool, codigo: ?string, mensagem: string} */
function assinaturaServicoPreapprovalFalha(AssinaturaServicoConversaoErro $erro): array
{
    return assinaturaServicoNovaContratacaoResultado(false, $erro->getMessage(), $erro->mensagemUsuario, ['http_status' => $erro->httpStatus]);
}

/** Periodicidades que o /preapproval aceita sem plano: frequência em meses. */
function assinaturaServicoPreapprovalFrequenciaMeses(string $periodicidade): ?int
{
    return match ($periodicidade) {
        'mensal' => 1,
        'trimestral' => 3,
        'semestral' => 6,
        'anual' => 12,
        default => null,
    };
}

/**
 * Recorrência da Preapproval pela periodicidade do contrato: `frequencia` e `tipo` (auto_recurring.frequency/
 * frequency_type). Diária: 1/days no legado ou 2/days na homologação; demais: meses. Null: periodicidade sem suporte.
 *
 * @return array{frequencia: int, tipo: string}|null
 */
function assinaturaServicoPreapprovalRecorrencia(
    string $periodicidade,
    int $diasDiaria = ASSINATURA_SERVICO_DIARIA_DIAS_NOVOS_CONTRATOS
): ?array
{
    if (assinaturaServicoPeriodicidadeDiaria($periodicidade)) {
        if (!in_array($diasDiaria, [1, 2], true)) {
            throw new RuntimeException('Ciclo diário inválido para a Preapproval.');
        }

        return ['frequencia' => $diasDiaria, 'tipo' => 'days'];
    }
    $meses = assinaturaServicoPreapprovalFrequenciaMeses($periodicidade);

    return $meses === null ? null : ['frequencia' => $meses, 'tipo' => 'months'];
}

/**
 * Resolve o ciclo de uma assinatura diária sem converter histórico: primeiro usa a competência financeira mais antiga
 * ainda válida; antes dela, usa a referência do vínculo mais recente; só contratos sem evidência adotam a configuração
 * de homologação. Demais periodicidades devolvem 1 apenas como parâmetro neutro dos helpers de data.
 */
function assinaturaServicoDiasCicloContrato(
    mysqli $conexao,
    int $idEmpresa,
    int $idAssinatura,
    string $periodicidade
): int {
    if (!assinaturaServicoPeriodicidadeDiaria($periodicidade)) {
        return 1;
    }
    $periodos = assinaturaServicoLinhas(
        $conexao,
        "SELECT DATE_FORMAT(periodo_inicio, '%Y-%m-%d') AS periodo_inicio,
                DATE_FORMAT(periodo_fim, '%Y-%m-%d') AS periodo_fim
           FROM cobranca
          WHERE id_empresa = ? AND id_assinatura = ? AND status <> 'cancelada'
            AND finalidade IN ('regular', 'conversao_trial')
          ORDER BY id_cobranca
          LIMIT 1",
        'ii',
        $idEmpresa,
        $idAssinatura
    );
    if ($periodos !== []) {
        return assinaturaServicoDiasPeriodoDiario(
            (string)$periodos[0]['periodo_inicio'],
            (string)$periodos[0]['periodo_fim']
        );
    }
    $vinculos = assinaturaServicoLinhas(
        $conexao,
        "SELECT referencia_externa_assinatura
           FROM assinatura_pagamento_recorrente
          WHERE id_empresa = ? AND id_assinatura = ? AND motor_recorrencia = 'preapproval'
          ORDER BY id_assinatura_pagamento DESC
          LIMIT 1",
        'ii',
        $idEmpresa,
        $idAssinatura
    );
    if ($vinculos !== []) {
        $referencia = (string)($vinculos[0]['referencia_externa_assinatura'] ?? '');

        return str_ends_with($referencia, ASSINATURA_SERVICO_PREAPPROVAL_REFERENCIA_DIARIA_48H) ? 2 : 1;
    }

    return ASSINATURA_SERVICO_DIARIA_DIAS_NOVOS_CONTRATOS;
}

/** Data (00:00 no fuso do negócio) em que a Preapproval pode cobrar pela primeira vez: o dia seguinte ao fim do período pago. */
function assinaturaServicoPreapprovalInicioCobranca(string $fimPeriodoPago): DateTimeImmutable
{
    if (!assinaturaServicoDataValida($fimPeriodoPago, 'Y-m-d')) {
        throw new InvalidArgumentException('Fim do período pago inválido.');
    }
    $fuso = new DateTimeZone(ASSINATURA_SERVICO_PREAPPROVAL_FUSO);

    return (new DateTimeImmutable($fimPeriodoPago . ' 00:00:00', $fuso))->modify('+1 day');
}

/**
 * Contrato em teste grátis cuja primeira mensalidade pode ser cobrada pela Preapproval no fim do teste: teste
 * configurado, sem encerramento programado, ainda em teste ('ativa') ou suspenso pela expiração. Não decide se o
 * teste ainda permite nova autorização (isso é do banco, em assinaturaServicoPreapprovalContrato()).
 *
 * @param array<string, mixed> $assinatura status, modalidade, motivo_suspensao, data_fim, teste_expira_em ('Y-m-d H:i:s')
 */
function assinaturaServicoPreapprovalTesteApto(array $assinatura): bool
{
    $status = (string)($assinatura['status'] ?? '');

    return (string)($assinatura['modalidade'] ?? '') === 'teste'
        && ($assinatura['data_fim'] ?? null) === null
        && is_string($assinatura['teste_expira_em'] ?? null)
        && assinaturaServicoDataValida((string)$assinatura['teste_expira_em'], 'Y-m-d H:i:s')
        && ($status === 'ativa' || ($status === 'suspensa' && (string)($assinatura['motivo_suspensao'] ?? '') === 'teste_expirado'));
}

/**
 * Início do primeiro período pago de um teste grátis: o instante de `teste_expira_em` (gravado no fuso da aplicação),
 * no fuso do negócio e arredondado para o minuto seguinte, para que a primeira parcela nunca anteceda o fim do teste.
 * O dia civil desse instante é o início da competência da cobrança de conversão.
 */
function assinaturaServicoPreapprovalInicioTeste(string $testeExpiraEm): DateTimeImmutable
{
    if (!assinaturaServicoDataValida($testeExpiraEm, 'Y-m-d H:i:s')) {
        throw new InvalidArgumentException('Fim do período de teste inválido.');
    }
    $fim = (new DateTimeImmutable($testeExpiraEm, new DateTimeZone(date_default_timezone_get())))
        ->setTimezone(new DateTimeZone(ASSINATURA_SERVICO_PREAPPROVAL_FUSO));
    $minuto = $fim->setTime((int)$fim->format('H'), (int)$fim->format('i'));

    return $minuto < $fim ? $minuto->modify('+1 minute') : $minuto;
}

/**
 * Primeira mensalidade devida já no checkout: contratação nova cujo período da oferta (`$inicio`, 00:00 no fuso do
 * negócio) já começou. Oferta com início ainda futuro (ex.: dia do banco à frente do fuso) não é imediata.
 *
 * @param array<string, mixed> $contrato retorno de assinaturaServicoPreapprovalContrato()
 */
function assinaturaServicoPreapprovalPrimeiraImediata(array $contrato, ?DateTimeImmutable $inicio, ?DateTimeImmutable $agora = null): bool
{
    return ($contrato['primeira_cobranca'] ?? null) !== null
        && $inicio !== null
        && $inicio <= ($agora ?? new DateTimeImmutable('now', new DateTimeZone(ASSINATURA_SERVICO_PREAPPROVAL_FUSO)));
}

/**
 * Data enviada em auto_recurring.start_date. A primeira mensalidade imediata omite o campo (o provedor cobra na
 * autorização); trials, recorrências posteriores e ofertas com início futuro preservam a data calculada.
 *
 * @param array<string, mixed> $contrato retorno de assinaturaServicoPreapprovalContrato()
 */
function assinaturaServicoPreapprovalInicioPayload(array $contrato, DateTimeImmutable $inicio, ?DateTimeImmutable $inicioEnvio, ?DateTimeImmutable $agora = null): ?DateTimeImmutable
{
    return assinaturaServicoPreapprovalPrimeiraImediata($contrato, $inicio, $agora)
        ? null
        : ($inicioEnvio ?? $inicio);
}

/**
 * Corpo do POST /preapproval, sem plano e sem card_token_id: nasce 'pending' e só é
 * autorizada pelo pagador no provedor. Nunca contém dado de cartão. A primeira
 * mensalidade imediata omite start_date; contratos com início futuro (como o trial)
 * continuam enviando a data explicitamente.
 *
 * @param array<string, mixed> $contrato plano_nome, valor_centavos, frequencia_meses
 */
function assinaturaServicoPreapprovalMontarCorpo(array $contrato, string $referencia, string $emailPagador, ?DateTimeImmutable $inicio, ?string $backUrl): array
{
    $reason = mb_substr('AmAgenda - ' . trim((string)$contrato['plano_nome']), 0, 120, 'UTF-8');
    $corpo = [
        'reason' => $reason,
        'external_reference' => $referencia,
        'payer_email' => $emailPagador,
        'status' => 'pending',
        'auto_recurring' => [
            'frequency' => (int)$contrato['frequencia_meses'],
            'frequency_type' => (string)($contrato['frequencia_tipo'] ?? 'months'),
            'transaction_amount' => (float)pagamentoGatewayValorDecimal((int)$contrato['valor_centavos']),
            'currency_id' => 'BRL',
        ],
    ];
    if ($inicio !== null) {
        $corpo['auto_recurring']['start_date'] = $inicio->format('Y-m-d\TH:i:s.vP');
    }
    if ($backUrl !== null) {
        $corpo['back_url'] = $backUrl;
    }

    return $corpo;
}

/**
 * Confere a Preapproval devolvida pelo provedor com o contrato interno.
 * Retorna null quando tudo confere; senão, o código da divergência. Nada é gravado.
 *
 * @param array<string, mixed> $dados resposta do provedor
 * @param array<string, mixed> $esperado referencia, ambiente, valor_centavos, frequencia_meses (frequência na unidade de
 *        frequencia_tipo), frequencia_tipo? ('months' por padrão; 'days' na periodicidade diária), id?,
 *        email?, inicio?, inicio_pendente_opcional?
 */
function assinaturaServicoPreapprovalValidarExterna(array $dados, array $esperado): ?string
{
    $id = $dados['id'] ?? null;
    if ((!is_string($id) && !is_int($id)) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,149}$/D', (string)$id)) {
        return 'ID_EXTERNO_INVALIDO';
    }
    if (isset($esperado['id']) && (string)$id !== (string)$esperado['id']) {
        return 'ID_EXTERNO_DIVERGENTE';
    }
    if (!is_string($dados['external_reference'] ?? null) || $dados['external_reference'] !== $esperado['referencia']) {
        return 'REFERENCIA_EXTERNA_DIVERGENTE';
    }
    if (!in_array($dados['status'] ?? null, $esperado['status_permitidos'] ?? ['pending', 'authorized'], true)) {
        return 'STATUS_EXTERNO_INESPERADO';
    }
    if (array_key_exists('live_mode', $dados) && is_bool($dados['live_mode'])
        && $dados['live_mode'] !== ($esperado['ambiente'] === 'producao')) {
        return 'AMBIENTE_EXTERNO_DIVERGENTE';
    }

    $recorrencia = $dados['auto_recurring'] ?? null;
    if (!is_array($recorrencia) || array_is_list($recorrencia)) {
        return 'RECORRENCIA_EXTERNA_AUSENTE';
    }
    if (($recorrencia['currency_id'] ?? null) !== 'BRL') {
        return 'MOEDA_EXTERNA_DIVERGENTE';
    }
    $valor = $recorrencia['transaction_amount'] ?? null;
    if (!(is_int($valor) || is_float($valor) || (is_string($valor) && is_numeric($valor)))
        || (int)round(((float)$valor) * 100) !== (int)$esperado['valor_centavos']) {
        return 'VALOR_EXTERNO_DIVERGENTE';
    }
    $tipoEsperado = (string)($esperado['frequencia_tipo'] ?? 'months');
    if (($recorrencia['frequency_type'] ?? null) !== $tipoEsperado
        || !is_int($recorrencia['frequency'] ?? null)
        || $recorrencia['frequency'] !== (int)$esperado['frequencia_meses']) {
        return 'PERIODICIDADE_EXTERNA_DIVERGENTE';
    }

    $email = $dados['payer_email'] ?? null;
    if (isset($esperado['email']) && is_string($email) && $email !== ''
        && strcasecmp($email, (string)$esperado['email']) !== 0) {
        return 'PAGADOR_EXTERNO_DIVERGENTE';
    }

    if (isset($esperado['inicio']) && $esperado['inicio'] instanceof DateTimeImmutable) {
        $codigoData = assinaturaServicoPreapprovalConferirInicioExterno(
            $dados,
            $esperado['inicio'],
            ($esperado['inicio_pendente_opcional'] ?? false) === true,
            (int)($esperado['antecipacao_dias'] ?? 0),
            ($esperado['em_teste'] ?? false) === true
        );
        if ($codigoData !== null) {
            return $codigoData;
        }
    }

    return null;
}

/**
 * Proteção contra cobrança antecipada, conforme o estado da Preapproval:
 * - pending: `next_payment_date` é provisório (o provedor pode devolver a data de criação antes da autorização do
 *   pagador); vale o início contratual `auto_recurring.start_date`, que precisa ser o dia seguinte ao fim do período
 *   pago ou depois. Sem início verificável, nada é vinculado (INICIO_EXTERNO_INDETERMINADO), exceto com
 *   `$permitirAusentePendente` (primeira mensalidade imediata, enviada sem start_date).
 * - authorized (e demais estados): vale a próxima cobrança efetivamente programada (`next_payment_date`; na falta
 *   dela, `start_date`). Sem nenhuma das duas, INICIO_EXTERNO_INDETERMINADO.
 * Retorna null quando a data respeita o período pago.
 *
 * Antecipação controlada (`$diasAntecipacao` > 0, ver assinaturaServicoPreapprovalAntecipacaoDias): o provedor pode
 * programar a primeira cobrança antes do `start_date` contratual (ex.: no dia da autorização). Só então, e só para a
 * `next_payment_date` de uma Preapproval já autorizada, vale o dia civil (fuso do negócio) de até `$diasAntecipacao` dia(s)
 * antes do primeiro dia não pago, desde que o `start_date` contratual respeite o período pago. A antecipação apenas
 * autoriza o vínculo: nenhuma fatura é criada nem quitada por esta data (isso é do débito e do pagamento autoritativos).
 *
 * Teste grátis (`$emTeste`): `$inicio` é o instante em que a gratuidade termina. A autorização do cartão pode acontecer
 * durante o teste, mas a primeira cobrança nunca pode ser programada antes do fim real dele: uma `next_payment_date`
 * de Preapproval autorizada anterior a esse instante (ainda que no mesmo dia civil) devolve
 * COBRANCA_EXTERNA_DENTRO_DO_TESTE, diagnóstico próprio que mantém o vínculo pendente. Nenhuma antecipação vale no teste e
 * nada é corrigido automaticamente (nem datas do contrato nem agenda do provedor).
 */
function assinaturaServicoPreapprovalConferirInicioExterno(array $dados, DateTimeImmutable $inicio, bool $permitirAusentePendente = false, int $diasAntecipacao = 0, bool $emTeste = false): ?string
{
    $recorrencia = is_array($dados['auto_recurring'] ?? null) ? $dados['auto_recurring'] : [];
    $inicioExterno = assinaturaServicoPreapprovalDataExterna($recorrencia['start_date'] ?? null);
    if (($dados['status'] ?? null) === 'pending' && $inicioExterno === null && $permitirAusentePendente) {
        return null;
    }
    $proximaExterna = assinaturaServicoPreapprovalDataExterna($dados['next_payment_date'] ?? null);
    $data = ($dados['status'] ?? null) === 'pending'
        ? $inicioExterno
        : ($proximaExterna ?? $inicioExterno);
    if ($data === null) {
        return 'INICIO_EXTERNO_INDETERMINADO';
    }
    if ($emTeste && ($dados['status'] ?? null) === 'authorized' && $proximaExterna !== null
        && $proximaExterna->getTimestamp() < $inicio->getTimestamp() - 60) {
        return 'COBRANCA_EXTERNA_DENTRO_DO_TESTE';
    }
    if (assinaturaServicoPreapprovalDataNaoAntecipada($data, $inicio)) {
        return null;
    }
    if ($diasAntecipacao > 0 && ($dados['status'] ?? null) === 'authorized' && $proximaExterna !== null
        && $inicioExterno !== null && assinaturaServicoPreapprovalDataNaoAntecipada($inicioExterno, $inicio)
        && assinaturaServicoPreapprovalDataDentroAntecipacao($proximaExterna, $inicio, $diasAntecipacao)) {
        return null;
    }

    return 'COBRANCA_EXTERNA_ANTECIPADA';
}

/**
 * Política UNIVERSAL de antecipação CONTROLADA da cobrança externa: no máximo 1 dia civil antes do primeiro dia não pago,
 * igual para todas as periodicidades suportadas (diária de 1 ou 2 dias, mensal, trimestral, semestral e anual) e sem
 * exceção por empresa. É uma política local de segurança, não uma janela oficial do Mercado Pago: o provedor foi observado
 * programando a tentativa na véspera do `start_date` (diária de 2 dias na empresa 35; mensal na empresa 34). Mais de um dia
 * exige regra formal adicional e continua recusado (COBRANCA_EXTERNA_ANTECIPADA).
 *
 * Só autoriza o reconhecimento da Preapproval `authorized`; nunca cria fatura, competência ou pagamento (isso é do débito
 * e do pagamento autoritativos). Não vale para teste grátis nem para primeira cobrança (ver ...AntecipacaoDiasContrato).
 */
const ASSINATURA_SERVICO_PREAPPROVAL_ANTECIPACAO_DIAS_MAXIMO = 1;

/** `$diasCiclo` é mantido por compatibilidade das chamadas: a política não depende dele. */
function assinaturaServicoPreapprovalAntecipacaoDias(string $periodicidade, int $diasCiclo = 1): int
{
    return in_array($periodicidade, ASSINATURA_SERVICO_PERIODICIDADES, true)
        ? ASSINATURA_SERVICO_PREAPPROVAL_ANTECIPACAO_DIAS_MAXIMO : 0;
}

/**
 * Antecipação só vale para a recorrência que preserva um período já pago (contrato pago sem primeira cobrança nem teste
 * grátis em curso); nos demais casos as datas têm outro significado e nada é antecipado.
 *
 * @param array<string, mixed> $contrato retorno de assinaturaServicoPreapprovalContrato()
 */
function assinaturaServicoPreapprovalAntecipacaoDiasContrato(array $contrato): int
{
    $diasCiclo = (string)($contrato['frequencia_tipo'] ?? '') === 'days' ? (int)($contrato['frequencia_meses'] ?? 1) : 1;

    return ($contrato['primeira_cobranca'] ?? null) === null && ($contrato['teste'] ?? null) === null
        ? assinaturaServicoPreapprovalAntecipacaoDias((string)($contrato['periodicidade'] ?? ''), $diasCiclo)
        : 0;
}

/** O dia civil (fuso do negócio) da data externa está a no máximo `$dias` dia(s) antes do primeiro dia não pago. */
function assinaturaServicoPreapprovalDataDentroAntecipacao(DateTimeImmutable $externa, DateTimeImmutable $inicio, int $dias): bool
{
    $fuso = new DateTimeZone(ASSINATURA_SERVICO_PREAPPROVAL_FUSO);
    $limite = $inicio->setTimezone($fuso)->setTime(0, 0)->modify('-' . max(0, $dias) . ' days')->format('Y-m-d');

    return $externa->setTimezone($fuso)->format('Y-m-d') >= $limite;
}

/**
 * A data externa não antecede o primeiro dia não pago. O provedor pode representar o mesmo dia em outro fuso
 * (ex.: meia-noite UTC ou -04:00); vale o dia civil do próprio valor ou do fuso do negócio, nunca um dia anterior.
 */
function assinaturaServicoPreapprovalDataNaoAntecipada(DateTimeImmutable $externa, DateTimeImmutable $inicio): bool
{
    $fuso = new DateTimeZone(ASSINATURA_SERVICO_PREAPPROVAL_FUSO);
    $limite = $inicio->setTimezone($fuso)->format('Y-m-d');

    return $externa->format('Y-m-d') >= $limite || $externa->setTimezone($fuso)->format('Y-m-d') >= $limite;
}

function assinaturaServicoPreapprovalDataExterna(mixed $valor): ?DateTimeImmutable
{
    if (!is_string($valor) || $valor === '' || strlen($valor) > 40) {
        return null;
    }
    try {
        return new DateTimeImmutable($valor);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Campos persistidos após confirmação autoritativa. `versao_externa` é o instante de
 * `last_modified` (ou `date_created`) em milissegundos, pois o provedor não expõe contador.
 *
 * @param array<string, mixed> $dados
 * @return array{id: string, versao: int, status_externo: string, proxima: string}
 */
function assinaturaServicoPreapprovalCamposVinculo(array $dados, DateTimeImmutable $inicio): array
{
    $referenciaVersao = assinaturaServicoPreapprovalDataExterna($dados['last_modified'] ?? null)
        ?? assinaturaServicoPreapprovalDataExterna($dados['date_created'] ?? null);
    $versao = $referenciaVersao === null
        ? 1
        : max(1, ((int)$referenciaVersao->format('U')) * 1000 + (int)$referenciaVersao->format('v'));
    $proxima = assinaturaServicoPreapprovalDataExterna($dados['next_payment_date'] ?? null) ?? $inicio;

    return [
        'id' => (string)$dados['id'],
        'versao' => $versao,
        'status_externo' => (string)$dados['status'],
        'proxima' => $proxima->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s.u'),
    ];
}

/**
 * Link de autorização para o pagador; só HTTPS no checkout de assinaturas do Mercado Pago e para a MESMA Preapproval.
 * `activation` (parâmetro opaco que o provedor acrescenta ao link) só é aceito quando o link veio da consulta
 * autoritativa (GET por id) da Preapproval vinculada; o valor é preservado como veio, nunca interpretado.
 */
function assinaturaServicoPreapprovalInitPoint(array $dados, bool $permitirActivation = false): ?string
{
    return assinaturaServicoPreapprovalInitPointMotivo($dados, $permitirActivation) === 'valido' ? (string)$dados['init_point'] : null;
}

/**
 * Conferência do init_point: 'valido' ou o motivo da recusa (sem valores) — só estrutura: host, caminho e NOMES dos
 * parâmetros. Nunca devolve a URL, o id da Preapproval, o valor de `activation`, e-mail ou credenciais.
 */
function assinaturaServicoPreapprovalInitPointMotivo(array $dados, bool $permitirActivation = false): string
{
    $url = $dados['init_point'] ?? null;
    if (!array_key_exists('init_point', $dados) || $url === null || $url === '') {
        return 'ausente';
    }
    if (!is_string($url)) {
        return 'tipo_' . gettype($url);
    }
    if (strlen($url) > 1000 || preg_match('/[\x00-\x20\x7F\\\\]/', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return 'url_malformada';
    }
    $partes = parse_url($url);
    $limpo = static fn ($v): string => substr((string)preg_replace('/[^A-Za-z0-9._,\/-]/', '?', (string)$v), 0, 80);
    if (!is_array($partes) || ($partes['scheme'] ?? '') !== 'https') {
        return 'esquema=' . $limpo($partes['scheme'] ?? '');
    }
    if (isset($partes['user']) || isset($partes['pass']) || isset($partes['fragment']) || (isset($partes['port']) && $partes['port'] !== 443)) {
        return 'credencial_fragmento_ou_porta';
    }
    $host = strtolower((string)($partes['host'] ?? ''));
    if (!in_array($host, ['www.mercadopago.com.br', 'mercadopago.com.br'], true)) {
        return 'host=' . $limpo($host);
    }
    if (($partes['path'] ?? '') !== '/subscriptions/checkout') {
        return 'caminho=' . $limpo($partes['path'] ?? '');
    }

    // Query lida literalmente (sem decodificar): pares nome=valor, cada nome no máximo uma vez.
    $parametros = [];
    foreach (explode('&', (string)($partes['query'] ?? '')) as $par) {
        if (preg_match('/^([a-z_]{1,40})=([^=&]*)$/D', $par, $m) !== 1) {
            return 'parametros_malformados';
        }
        if (array_key_exists($m[1], $parametros)) {
            return 'parametro_duplicado=' . $m[1];
        }
        $parametros[$m[1]] = $m[2];
    }
    $nomes = array_keys($parametros);
    $permitidos = $permitirActivation ? ['preapproval_id', 'activation'] : ['preapproval_id'];
    if (!isset($parametros['preapproval_id']) || array_diff($nomes, $permitidos) !== []) {
        return (!$permitirActivation && isset($parametros['activation']) ? 'activation_fora_da_consulta;' : '')
            . 'parametros=' . $limpo(implode(',', $nomes));
    }
    if (preg_match('/^[A-Za-z0-9_-]{1,150}$/D', $parametros['preapproval_id']) !== 1) {
        return 'preapproval_id_invalido';
    }
    // O link precisa ser da Preapproval conferida: o id é obrigatório e idêntico.
    if (!isset($dados['id']) || (string)$dados['id'] !== $parametros['preapproval_id']) {
        return 'id_divergente';
    }
    // activation: opaco; só caracteres de URL não reservados e escapes %XX bem formados (rejeita adulteração óbvia).
    if (isset($parametros['activation'])
        && (preg_match('/^[A-Za-z0-9._~%-]{1,512}$/D', $parametros['activation']) !== 1
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $parametros['activation']) === 1)) {
        return 'activation_invalido';
    }

    return 'valido';
}

/**
 * Link de autorização de uma Preapproval pendente já conferida. Recusa registra só o motivo estrutural, para
 * diagnóstico; a recusa nunca cria outra Preapproval nem altera o vínculo.
 */
function assinaturaServicoPreapprovalLinkAutorizacao(array $dados, int $idAssinaturaPagamento, bool $consultaAutoritativa = false): ?string
{
    $url = assinaturaServicoPreapprovalInitPoint($dados, $consultaAutoritativa);
    if ($url === null) {
        error_log('[assinatura_preapproval] init_point_recusado vinculo=' . $idAssinaturaPagamento
            . ' status=' . substr((string)preg_replace('/[^a-z_]/', '', (string)($dados['status'] ?? '')), 0, 20)
            . ' motivo=' . assinaturaServicoPreapprovalInitPointMotivo($dados, $consultaAutoritativa));
    }

    return $url;
}

/** Destino fixo do retorno; parâmetros enviados pelo navegador nunca escolhem a URL. */
function assinaturaServicoPreapprovalBackUrl(?string $base): ?string
{
    return $base === null ? null : rtrim($base, '/') . '/public/views/painel-administrativo/painel-administrativo.html?mp_assinatura_retorno=1';
}

/** Retoma apenas uma Preapproval já vinculada; nunca executa POST nem confirma autorização local. */
function assinaturaServicoPreapprovalAutorizacaoExistente(object $gateway, array $reserva, ?string $emailPagador): array
{
    $v = $reserva['vinculo'];
    $c = $reserva['contrato'];
    $base = ['id_assinatura_pagamento' => (int)$v['id_assinatura_pagamento'], 'estado' => 'vinculada'];
    if (!$gateway::subscriptionsHabilitado()) {
        return assinaturaServicoNovaContratacaoResultado(false, 'SUBSCRIPTIONS_DESABILITADO', 'Pagamento recorrente indisponível.', ['http_status' => 503]);
    }
    if ((int)$v['id_empresa'] !== (int)$c['id_empresa'] || (int)$v['id_assinatura'] !== (int)$c['id_assinatura']
        || (string)$v['ambiente'] !== $gateway->ambiente()) {
        return assinaturaServicoNovaContratacaoResultado(false, 'VINCULO_INCONSISTENTE', 'Não foi possível conferir a assinatura recorrente.', ['http_status' => 409]);
    }
    $resposta = $gateway->consultarPreapproval((string)$v['assinatura_externa_id']);
    $d = $resposta['dados'] ?? null;
    if (($resposta['sucesso'] ?? false) !== true || !is_array($d)) {
        return assinaturaServicoNovaContratacaoResultado(false, 'CONSULTA_INDISPONIVEL', 'Não foi possível verificar a autorização. Atualize a situação antes de tentar novamente.', $base + ['http_status' => 503]);
    }
    if (($d['status'] ?? null) === 'cancelled') $d['status'] = 'canceled';
    $erro = assinaturaServicoPreapprovalValidarExterna($d, [
        'id' => (string)$v['assinatura_externa_id'], 'referencia' => (string)$v['referencia_externa_assinatura'],
        'ambiente' => (string)$v['ambiente'], 'valor_centavos' => $c['valor_centavos'],
        'frequencia_meses' => $c['frequencia_meses'], 'frequencia_tipo' => $c['frequencia_tipo'], 'inicio' => $reserva['inicio'],
        'inicio_pendente_opcional' => assinaturaServicoPreapprovalPrimeiraImediata($c, $reserva['inicio']),
        'antecipacao_dias' => assinaturaServicoPreapprovalAntecipacaoDiasContrato($c),
        'em_teste' => ($c['teste'] ?? null) !== null,
        'status_permitidos' => ['pending', 'authorized', 'paused', 'canceled'],
    ]);
    if ($erro !== null) {
        return assinaturaServicoNovaContratacaoResultado(false, $erro, 'A assinatura externa precisa de verificação. Nenhuma nova assinatura foi criada.', $base + ['http_status' => 409]);
    }
    return assinaturaServicoNovaContratacaoResultado(true, 'JA_VINCULADA', 'Assinatura recorrente existente consultada.', $base + [
        'status_externo' => $d['status'],
        'init_point' => $d['status'] === 'pending' ? assinaturaServicoPreapprovalLinkAutorizacao($d, (int)$v['id_assinatura_pagamento'], true) : null,
    ]);
}

/** Escrita preparada; devolve linhas afetadas e propaga falha (código = errno do MySQL). */
function assinaturaServicoPreapprovalExec(mysqli $conexao, string $sql, string $tipos = '', mixed ...$valores): int
{
    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar a gravação da assinatura recorrente.', (int)$conexao->errno);
    }
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$valores);
    }
    if (!$stmt->execute()) {
        $errno = (int)$stmt->errno;
        $stmt->close();
        throw new RuntimeException('Falha ao gravar a assinatura recorrente.', $errno);
    }
    $afetadas = $stmt->affected_rows;
    $stmt->close();

    return (int)$afetadas;
}

function assinaturaServicoPreapprovalChaveDuplicada(Throwable $e): bool
{
    return (int)$e->getCode() === 1062;
}

/**
 * Contrato vigente, bloqueado para a transação corrente, com todas as condições para criar Preapproval.
 *
 * @return array<string, mixed>
 * @throws AssinaturaServicoConversaoErro
 */
function assinaturaServicoPreapprovalContrato(mysqli $conexao, int $idEmpresa): array
{
    $empresa = assinaturaServicoConsultaFinanceira($conexao, 'SELECT status FROM empresa WHERE id_empresa = ? LIMIT 1', 'i', $idEmpresa);
    if (($empresa[0]['status'] ?? null) !== 'ativo') {
        throw new AssinaturaServicoConversaoErro('EMPRESA_INVALIDA', 'Não foi possível identificar sua empresa.', 403);
    }

    $linhas = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT a.id_assinatura, a.id_plano, a.valor_contratado, a.periodicidade, a.status, a.modalidade, a.motivo_suspensao, a.data_fim,
                DATE_FORMAT(a.teste_expira_em, '%Y-%m-%d %H:%i:%s') AS teste_expira_em,
                (a.teste_iniciado_em IS NOT NULL AND a.teste_expira_em > a.teste_iniciado_em
                    AND a.teste_expira_em > DATE_ADD(CURRENT_TIMESTAMP, INTERVAL " . ASSINATURA_SERVICO_PREAPPROVAL_PRIMEIRA_MARGEM_MINUTOS . " MINUTE)) AS teste_autorizavel,
                p.nome AS plano_nome, p.gera_cobranca
           FROM assinatura a
          INNER JOIN plano p ON p.id_plano = a.id_plano
          WHERE a.id_empresa = ? AND a.status IN ('ativa', 'suspensa')
          ORDER BY a.id_assinatura DESC
          FOR UPDATE",
        'i',
        $idEmpresa
    );
    if (count($linhas) > 1) {
        throw new AssinaturaServicoConversaoErro('ASSINATURA_INCONSISTENTE', 'Não foi possível determinar sua assinatura vigente.', 409);
    }
    $contrato = $linhas[0] ?? null;
    // Contratação nova ainda não paga: a Preapproval quita a cobrança do primeiro período (oferta) e segue recorrente.
    $primeiraCobranca = $contrato !== null && assinaturaServicoPreapprovalContratacaoPendente($contrato);
    if ($contrato === null || ((string)$contrato['status'] !== 'ativa' && !$primeiraCobranca)) {
        throw new AssinaturaServicoConversaoErro('ASSINATURA_INATIVA', 'Não há assinatura ativa para configurar o pagamento recorrente.', 409);
    }
    $teste = null;
    if ((string)$contrato['modalidade'] === 'teste') {
        // Teste grátis: o cartão pode ser autorizado enquanto o teste dura (com margem para o checkout); a primeira
        // mensalidade só é cobrada no fim do teste e o contrato continua em teste até o pagamento confirmado.
        if ((string)$contrato['status'] !== 'ativa' || !assinaturaServicoPreapprovalTesteApto($contrato)
            || (int)$contrato['teste_autorizavel'] !== 1) {
            throw new AssinaturaServicoConversaoErro('TESTE_SEM_RECORRENCIA', 'O teste grátis terminou ou está terminando. Pague a primeira mensalidade pela regularização no Faturamento.', 409);
        }
        if ((int)$contrato['gera_cobranca'] !== 1) {
            throw new AssinaturaServicoConversaoErro('PLANO_SEM_COBRANCA', 'O plano atual não permite configurar pagamento recorrente.', 409);
        }
        $teste = ['expira_em' => (string)$contrato['teste_expira_em']];
    } elseif ((string)$contrato['modalidade'] !== 'paga' || (int)$contrato['gera_cobranca'] !== 1) {
        throw new AssinaturaServicoConversaoErro('PLANO_SEM_COBRANCA', 'O plano atual não permite configurar pagamento recorrente.', 409);
    }
    if ($contrato['data_fim'] !== null) {
        throw new AssinaturaServicoConversaoErro('CANCELAMENTO_PROGRAMADO', 'A assinatura possui cancelamento programado.', 409);
    }
    $diasDiaria = assinaturaServicoDiasCicloContrato(
        $conexao,
        $idEmpresa,
        (int)$contrato['id_assinatura'],
        (string)$contrato['periodicidade']
    );
    $recorrencia = assinaturaServicoPreapprovalRecorrencia((string)$contrato['periodicidade'], $diasDiaria);
    if ($recorrencia === null) {
        throw new AssinaturaServicoConversaoErro('PERIODICIDADE_NAO_SUPORTADA', 'A periodicidade da assinatura não permite pagamento recorrente.', 409);
    }
    try {
        $centavos = pagamentoGatewayValorCentavos((string)$contrato['valor_contratado']);
    } catch (InvalidArgumentException $e) {
        $centavos = 0;
    }
    if ($centavos <= 0) {
        throw new AssinaturaServicoConversaoErro('VALOR_CONTRATADO_INVALIDO', 'O valor da assinatura não permite pagamento recorrente.', 409);
    }

    return [
        'id_empresa' => $idEmpresa,
        'id_assinatura' => (int)$contrato['id_assinatura'],
        'id_plano' => (int)$contrato['id_plano'],
        'plano_nome' => (string)$contrato['plano_nome'],
        'periodicidade' => (string)$contrato['periodicidade'],
        'valor_centavos' => $centavos,
        // Frequência na unidade de frequencia_tipo (meses; dias na periodicidade diária).
        'frequencia_meses' => $recorrencia['frequencia'],
        'frequencia_tipo' => $recorrencia['tipo'],
        'primeira_cobranca' => $primeiraCobranca
            ? assinaturaServicoPreapprovalOfertaPrimeiraCobranca($conexao, $idEmpresa, (int)$contrato['id_assinatura'], $centavos)
            : null,
        'teste' => $teste,
    ];
}

/** Contrato pago criado pela nova contratação e ainda aguardando o pagamento do primeiro período. */
function assinaturaServicoPreapprovalContratacaoPendente(array $contrato): bool
{
    return (string)$contrato['status'] === 'suspensa' && (string)($contrato['motivo_suspensao'] ?? '') === 'contratacao_pendente'
        && (string)$contrato['modalidade'] === 'paga' && $contrato['data_fim'] === null;
}

/**
 * Cobrança do primeiro período que a Preapproval vai quitar: a única oferta do contrato, pendente, sem pagamento
 * confirmado e com o valor contratado. Deve rodar na transação do chamador (bloqueia a cobrança).
 *
 * @return array{id_cobranca: int, periodo_inicio: string, periodo_fim: string}
 * @throws AssinaturaServicoConversaoErro
 */
function assinaturaServicoPreapprovalOfertaPrimeiraCobranca(mysqli $conexao, int $idEmpresa, int $idAssinatura, int $valorCentavos): array
{
    $ofertas = assinaturaServicoOfertasDoContrato($conexao, $idEmpresa, $idAssinatura);
    $oferta = count($ofertas) === 1 ? $ofertas[0] : null;
    if ($oferta !== null && $oferta['quitada']) {
        throw new AssinaturaServicoConversaoErro('CONTRATACAO_JA_PAGA', 'A cobrança de contratação já foi paga. Conclua a contratação para liberar o acesso.', 409);
    }
    if ($oferta === null || (string)$oferta['status'] !== 'pendente' || (int)$oferta['pago_centavos'] !== 0
        || pagamentoGatewayValorCentavos((string)$oferta['valor']) !== $valorCentavos) {
        throw new AssinaturaServicoConversaoErro('OFERTA_CONTRATACAO_INDISPONIVEL', 'A cobrança de contratação precisa de verificação antes do pagamento no cartão.', 409);
    }

    return [
        'id_cobranca' => (int)$oferta['id_cobranca'],
        'periodo_inicio' => (string)$oferta['periodo_inicio'],
        'periodo_fim' => (string)$oferta['periodo_fim'],
    ];
}

/**
 * Datas da Preapproval que quita a primeira cobrança. Sem período pago, nada há a proteger antes do início da oferta:
 * - limite: 00:00 do início do período da oferta (nenhuma cobrança externa pode cair antes disso);
 * - envio: `start_date` enviada ao provedor, alguns minutos à frente (o provedor não aceita início no passado), para
 *   que a primeira parcela seja cobrada logo após a autorização do pagador, dentro do período da oferta.
 *
 * @return array{limite: DateTimeImmutable, envio: DateTimeImmutable}
 */
function assinaturaServicoPreapprovalInicioPrimeiraCobranca(string $periodoInicio, ?DateTimeImmutable $agora = null): array
{
    if (!assinaturaServicoDataValida($periodoInicio, 'Y-m-d')) {
        throw new InvalidArgumentException('Início do período da oferta inválido.');
    }
    $fuso = new DateTimeZone(ASSINATURA_SERVICO_PREAPPROVAL_FUSO);
    $limite = new DateTimeImmutable($periodoInicio . ' 00:00:00', $fuso);
    $envio = ($agora ?? new DateTimeImmutable('now', $fuso))->setTimezone($fuso)
        ->modify('+' . ASSINATURA_SERVICO_PREAPPROVAL_PRIMEIRA_MARGEM_MINUTOS . ' minutes');
    $envio = $envio->setTime((int)$envio->format('H'), (int)$envio->format('i'));

    return ['limite' => $limite, 'envio' => $envio > $limite ? $envio : $limite];
}

/**
 * A primeira cobrança da contratação já tem uma Preapproval que pode cobrar (ou cuja criação está em conciliação):
 * enquanto isso, PIX e troca de plano ficam bloqueados para não haver duas cobranças do mesmo período.
 * Deve rodar na transação do chamador.
 */
function assinaturaServicoPreapprovalPrimeiraCobrancaViva(mysqli $conexao, int $idEmpresa, int $idAssinatura): bool
{
    return assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT 1 FROM assinatura_pagamento_recorrente v
          WHERE v.id_empresa = ? AND v.id_assinatura = ? AND v.motor_recorrencia = 'preapproval'
            AND v.status NOT IN ('cancelada', 'encerrada')
            AND (v.assinatura_externa_id IS NOT NULL
                 OR EXISTS (SELECT 1 FROM operacao_assinatura_gateway o
                             WHERE o.id_assinatura_pagamento = v.id_assinatura_pagamento
                               AND o.status_processamento IN ('pendente', 'processando', 'conciliacao')))
          LIMIT 1",
        'ii',
        $idEmpresa,
        $idAssinatura
    ) !== [];
}

/**
 * Oferta de outro dia na primeira cobrança pelo cartão. Na transação do chamador.
 * - Sem Preapproval que possa cobrar: a oferta (sem pagamento) é renovada pelo mesmo caminho da nova contratação
 *   (mesmo plano; a antiga é cancelada e auditada), para que a primeira parcela caia no início do período.
 * - Com Preapproval ainda pendente e oferta fora da tolerância: a oferta NÃO é tocada (o pagador pode concluir o
 *   checkout antigo); a Preapproval antiga é cancelada pelo outbox e a nova tentativa só ocorre após a confirmação.
 * - Demais casos (autorizada, em conciliação ou dentro da tolerância): nada muda.
 *
 * @param array<string, mixed> $contrato retorno de assinaturaServicoPreapprovalContrato() com primeira_cobranca
 * @return array{contrato: array<string, mixed>, expirada: ?array<string, mixed>}
 * @throws AssinaturaServicoConversaoErro
 */
function assinaturaServicoPreapprovalRenovarOfertaAntiga(mysqli $conexao, int $idEmpresa, array $contrato, ?array $ator = null): array
{
    $hoje = assinaturaServicoHojeBanco($conexao);
    $inicioOferta = (string)$contrato['primeira_cobranca']['periodo_inicio'];
    if ($inicioOferta === $hoje) {
        return ['contrato' => $contrato, 'expirada' => null];
    }
    $idAssinatura = (int)$contrato['id_assinatura'];
    if (assinaturaServicoPreapprovalPrimeiraCobrancaViva($conexao, $idEmpresa, $idAssinatura)) {
        $vinculo = assinaturaServicoPreapprovalVinculoDoContrato($conexao, $idEmpresa, $idAssinatura);
        $limite = (new DateTimeImmutable($hoje))->modify('-' . ASSINATURA_SERVICO_PREAPPROVAL_TOLERANCIA_PRIMEIRA_DIAS . ' days')->format('Y-m-d');
        $pendenteNoProvedor = $vinculo !== null && $vinculo['assinatura_externa_id'] !== null && (string)$vinculo['status'] === 'pendente'
            && (string)($vinculo['status_externo'] ?? '') === 'pending';
        if ($inicioOferta >= $limite || !$pendenteNoProvedor) {
            return ['contrato' => $contrato, 'expirada' => null];
        }
        $cancelamento = assinaturaServicoPreapprovalCancelarPrimeiraExterna($conexao, $vinculo, $ator);
        if ($cancelamento === null) {
            throw new AssinaturaServicoConversaoErro('VINCULO_INCONSISTENTE', 'O pagamento no cartão precisa de verificação. Fale com o suporte.', 409);
        }

        return ['contrato' => $contrato, 'expirada' => $cancelamento];
    }
    $renovada = assinaturaServicoSolicitarNovaContratacao($conexao, $idEmpresa, (int)$contrato['id_plano'], $ator);
    if (($renovada['ok'] ?? false) !== true) {
        throw new AssinaturaServicoConversaoErro((string)$renovada['codigo'], (string)$renovada['mensagem'], 409);
    }

    return ['contrato' => assinaturaServicoPreapprovalContrato($conexao, $idEmpresa), 'expirada' => null];
}

/**
 * Fases 5B.2 e 5B.4: o cartão é cadastrado depois da primeira mensalidade quitada ou, opcionalmente, durante o teste
 * grátis, sempre para cobranças futuras (start_date na competência seguinte ou no fim do teste). Liberação global por
 * decisão comercial; a chave existe apenas para desligar novas autorizações em todas as empresas de uma vez (aqui e
 * em Reservar()), sem afetar conciliação, troca de cartão ou cancelamento.
 */
const ASSINATURA_SERVICO_PREAPPROVAL_CADASTRO_CARTAO_LIBERADO = true;

const ASSINATURA_SERVICO_CADASTRO_CARTAO_MENSAGENS = [
    'CONTRATO_NAO_ELEGIVEL' => 'O cadastro do cartão fica disponível com o plano ativo, depois da primeira mensalidade paga.',
    'TESTE_ENCERRADO' => 'O teste grátis terminou ou está terminando. Pague a primeira mensalidade via PIX pela regularização; depois disso, cadastre o cartão.',
    'PERIODO_PAGO_INDISPONIVEL' => 'O cadastro do cartão fica disponível quando a mensalidade do período atual estiver paga.',
    'TROCA_PLANO_PENDENTE' => 'Há uma troca de plano em andamento. Conclua ou cancele a troca antes de cadastrar o cartão.',
    'COBRANCA_VENCIDA_PENDENTE' => 'Há cobrança vencida em aberto. Quite os débitos do faturamento antes de cadastrar o cartão.',
    'PAGAMENTO_AGUARDANDO_CONFIRMACAO' => 'O pagamento anterior ainda precisa de confirmação. Atualize a situação antes de realizar uma nova tentativa ou entre em contato com o suporte.',
    'OPERACAO_EXTERNA_PENDENTE' => 'Há uma operação do pagamento automático aguardando confirmação do Mercado Pago. Atualize a situação em instantes.',
    'RECORRENCIA_EXISTENTE' => 'Já existe uma configuração de pagamento automático para esta empresa. Atualize a situação para conferir.',
    'NOVA_AUTORIZACAO_INDISPONIVEL' => 'Uma nova autorização no cartão ainda não está disponível para este contrato. Fale com o suporte.',
];

/**
 * Elegibilidade local do cadastro do cartão, em dois caminhos: teste grátis ativo (cadastro opcional; a primeira
 * mensalidade só depois do fim do teste) e contrato pago ativo com a competência atual quitada. A contratação sem teste
 * ainda não paga não é elegível (primeira mensalidade via PIX). Somente leitura: a criação refaz as validações sob lock
 * (Reservar() e assinaturaServicoPreapprovalContrato()) e fica bloqueada se `liberado` for desligado.
 * `primeira_cobranca_prevista`: data enviada como start_date (dia seguinte ao período pago, ou o fim do teste); é
 * previsão local até a confirmação do Mercado Pago (next_payment_date na sincronização), nunca garantia de que o
 * provedor nada cobra antes dela.
 *
 * @param array<string, mixed>|null $contrato id_assinatura, status, modalidade, motivo_suspensao, data_fim, gera_cobranca,
 *                                            teste_expira_em, teste_autorizavel (pagamentoCartaoAssinaturaAtiva())
 * @return array{caminho: ?string, elegivel: bool, liberado: bool, motivo: ?string, mensagem: ?string, primeira_cobranca_prevista: ?string}
 */
function assinaturaServicoPreapprovalCadastroCartao(mysqli $conexao, int $idEmpresa, ?array $contrato): array
{
    ['caminho' => $caminho, 'motivo' => $motivo] = assinaturaServicoPreapprovalCadastroCartaoMotivo($conexao, $idEmpresa, $contrato);
    $prevista = null;
    if ($motivo === null && $caminho === 'teste') {
        $prevista = assinaturaServicoPreapprovalInicioTeste((string)$contrato['teste_expira_em']);
    } elseif ($motivo === null) {
        $fimPago = assinaturaServicoFimPeriodoPago($conexao, $idEmpresa, (int)$contrato['id_assinatura']);
        $prevista = $fimPago === null ? null : assinaturaServicoPreapprovalInicioCobranca($fimPago);
    }

    return [
        'caminho' => $caminho,
        'elegivel' => $motivo === null,
        'liberado' => ASSINATURA_SERVICO_PREAPPROVAL_CADASTRO_CARTAO_LIBERADO,
        'motivo' => $motivo,
        'mensagem' => $motivo === null ? null : ASSINATURA_SERVICO_CADASTRO_CARTAO_MENSAGENS[$motivo],
        'primeira_cobranca_prevista' => $prevista?->format('Y-m-d'),
    ];
}

/**
 * Caminho do contrato no cadastro do cartão, sem banco: 'teste' (teste grátis ativo, com tempo para o checkout antes do
 * fim) ou 'pago' (contrato pago ativo). Os demais estados não são elegíveis.
 *
 * @param array<string, mixed>|null $contrato
 * @return array{caminho: ?string, motivo: ?string}
 */
function assinaturaServicoPreapprovalCadastroCartaoCaminho(?array $contrato): array
{
    if ($contrato === null || ($contrato['data_fim'] ?? null) !== null || (int)($contrato['gera_cobranca'] ?? 0) !== 1) {
        return ['caminho' => null, 'motivo' => 'CONTRATO_NAO_ELEGIVEL'];
    }
    if ((string)$contrato['modalidade'] === 'teste') {
        if ((string)$contrato['status'] !== 'ativa' || !assinaturaServicoPreapprovalTesteApto($contrato)) {
            return ['caminho' => null, 'motivo' => 'TESTE_ENCERRADO'];
        }
        // Mesma margem de assinaturaServicoPreapprovalContrato(): o checkout precisa terminar antes do fim do teste.
        return (int)($contrato['teste_autorizavel'] ?? 0) === 1
            ? ['caminho' => 'teste', 'motivo' => null]
            : ['caminho' => null, 'motivo' => 'TESTE_ENCERRADO'];
    }
    // Contrato pago e ativo: só existe depois da quitação da primeira mensalidade (contratação ou conversão do teste).
    if ((string)$contrato['modalidade'] === 'paga' && (string)$contrato['status'] === 'ativa') {
        return ['caminho' => 'pago', 'motivo' => null];
    }

    return ['caminho' => null, 'motivo' => 'CONTRATO_NAO_ELEGIVEL'];
}

/**
 * @param array<string, mixed>|null $contrato
 * @return array{caminho: ?string, motivo: ?string}
 */
function assinaturaServicoPreapprovalCadastroCartaoMotivo(mysqli $conexao, int $idEmpresa, ?array $contrato): array
{
    $avaliacao = assinaturaServicoPreapprovalCadastroCartaoCaminho($contrato);
    $caminho = $avaliacao['caminho'];
    $bloqueio = static fn (string $motivo): array => ['caminho' => $caminho, 'motivo' => $motivo];
    if ($avaliacao['motivo'] !== null) {
        return $avaliacao;
    }
    $idAssinatura = (int)$contrato['id_assinatura'];
    // Contrato pago: competência de hoje coberta por pagamentos confirmados; um estorno devolve o saldo e desfaz a cobertura.
    if ($caminho === 'pago' && assinaturaServicoFimPeriodoPago($conexao, $idEmpresa, $idAssinatura) === null) {
        return $bloqueio('PERIODO_PAGO_INDISPONIVEL');
    }
    if (assinaturaServicoTrocaPendenteEmpresa($conexao, $idEmpresa)) {
        return $bloqueio('TROCA_PLANO_PENDENTE');
    }
    // PIX ou cartão em andamento/conciliação também impedem: um único meio por competência. Mesmos parâmetros de
    // Reservar(), para o botão nunca divergir da autorização efetiva.
    $pendencia = assinaturaServicoPendenciaFinanceira($conexao, $idEmpresa, true);
    if ($pendencia !== null) {
        return $bloqueio($pendencia['tipo'] === 'divida' ? 'COBRANCA_VENCIDA_PENDENTE' : 'PAGAMENTO_AGUARDANDO_CONFIRMACAO');
    }
    $vinculos = assinaturaServicoLinhas(
        $conexao,
        "SELECT v.id_assinatura, v.status, v.slot_ativo, v.assinatura_externa_id,
                EXISTS (SELECT 1 FROM operacao_assinatura_gateway o
                         WHERE o.id_assinatura_pagamento = v.id_assinatura_pagamento
                           AND o.status_processamento IN ('pendente', 'processando', 'conciliacao')
                           AND NOT (o.status_processamento = 'processando' AND o.erro_codigo IS NULL
                                    AND TIMESTAMPDIFF(SECOND, COALESCE(o.ultima_tentativa_em, o.criado_em), NOW(6)) > " . ASSINATURA_SERVICO_PREAPPROVAL_OPERACAO_INTERROMPIDA_SEGUNDOS . ")) AS operacao_aberta
           FROM assinatura_pagamento_recorrente v
          WHERE v.id_empresa = ?",
        'i',
        $idEmpresa
    );
    $vinculosAvaliados = assinaturaServicoPreapprovalCadastroVinculosMotivo($vinculos, $idAssinatura);
    if ($vinculosAvaliados['motivo'] !== null) {
        return $bloqueio($vinculosAvaliados['motivo']);
    }
    if ($vinculosAvaliados['encerrado_do_contrato'] && !assinaturaServicoPreapprovalNovoVinculoPermitido($conexao)) {
        return $bloqueio('NOVA_AUTORIZACAO_INDISPONIVEL');
    }

    return ['caminho' => $caminho, 'motivo' => null];
}

/**
 * Vínculos da empresa que impedem o cadastro, sem rede nem banco. Alinhado a Reservar(): de outro contrato, só o
 * histórico que não pode mais cobrar deixa de contar (encerrado com cancelamento confirmado, ou erro sem Preapproval
 * criada), e nunca com slot ativo ou operação externa aberta. Do próprio contrato, qualquer vínculo não encerrado conta
 * (o vínculo pendente ou em erro dele é retomado sob lock pelo serviço, nunca recriado). Status desconhecido conta.
 *
 * @param list<array<string, mixed>> $vinculos id_assinatura, status, slot_ativo, assinatura_externa_id, operacao_aberta
 * @return array{motivo: ?string, encerrado_do_contrato: bool}
 */
function assinaturaServicoPreapprovalCadastroVinculosMotivo(array $vinculos, int $idAssinatura): array
{
    $encerradoDoContrato = false;
    foreach ($vinculos as $vinculo) {
        $status = (string)($vinculo['status'] ?? '');
        $encerrado = in_array($status, ['cancelada', 'encerrada'], true);
        $slotAtivo = (int)($vinculo['slot_ativo'] ?? 0) === 1;
        $operacaoAberta = (int)($vinculo['operacao_aberta'] ?? 0) === 1;
        $doContrato = (int)($vinculo['id_assinatura'] ?? 0) === $idAssinatura;
        $historicoInerte = !$slotAtivo && !$operacaoAberta
            && ($encerrado || ($status === 'erro' && ($vinculo['assinatura_externa_id'] ?? null) === null));
        if (!$doContrato && $historicoInerte) {
            continue;
        }
        if ($operacaoAberta) {
            return ['motivo' => 'OPERACAO_EXTERNA_PENDENTE', 'encerrado_do_contrato' => $encerradoDoContrato];
        }
        if ($slotAtivo || !$encerrado) {
            return ['motivo' => 'RECORRENCIA_EXISTENTE', 'encerrado_do_contrato' => $encerradoDoContrato];
        }
        $encerradoDoContrato = true;
    }

    return ['motivo' => null, 'encerrado_do_contrato' => $encerradoDoContrato];
}

/**
 * Estrutura que permite um novo vínculo para o mesmo contrato depois do encerramento confirmado do anterior
 * (SQL manual da Fase 21: o índice único passa a valer só para vínculos não encerrados). Sem ela, um contrato
 * continua com um único vínculo, e nenhuma nova autorização é criada após o cancelamento (falha fechada).
 */
function assinaturaServicoPreapprovalNovoVinculoPermitido(mysqli $conexao): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $indices = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT INDEX_NAME, MIN(NON_UNIQUE) AS non_unique, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS colunas
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assinatura_pagamento_recorrente'
          GROUP BY INDEX_NAME"
    );
    $unicoPorContrato = false;
    $unicoVigente = false;
    foreach ($indices as $indice) {
        if ((int)$indice['non_unique'] !== 0) {
            continue;
        }
        $unicoPorContrato = $unicoPorContrato || (string)$indice['colunas'] === 'id_assinatura';
        $unicoVigente = $unicoVigente || (string)$indice['colunas'] === 'vinculo_vigente_assinatura';
    }

    return $cache = !$unicoPorContrato && $unicoVigente;
}

/**
 * Identidade do pagador de uma NOVA Preapproval (função pura, sem banco). Confere os dados lidos do banco contra a
 * sessão: o usuário e a empresa precisam ser os da sessão; usuário, empresa, vínculo e perfil, ativos; o perfil, o do
 * proprietário; o e-mail do banco, válido e igual (sem diferenciar maiúsculas) ao da sessão. Qualquer falha exige nova
 * autenticação (401 SESSION_REAUTH_REQUIRED), sem expor e-mails na mensagem. Devolve exclusivamente o e-mail do banco.
 *
 * @param array<string, mixed> $dados id_usuario, id_empresa, usuario_status, empresa_status, vinculo_status, perfil_status, perfil_nome, email
 * @throws AssinaturaServicoConversaoErro
 */
function assinaturaServicoPreapprovalIdentidadePagadorValidarDados(array $dados, int $idEmpresa, int $idUsuario, string $emailSessao): string
{
    $emailValido = static fn (string $e): bool => $e !== '' && strlen($e) <= 254 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false;
    $perfil = mb_strtolower(trim((string)($dados['perfil_nome'] ?? '')), 'UTF-8');
    $emailBanco = trim((string)($dados['email'] ?? ''));
    $emailSessao = trim($emailSessao);

    if ($idEmpresa <= 0 || $idUsuario <= 0
        || (int)($dados['id_usuario'] ?? 0) !== $idUsuario || (int)($dados['id_empresa'] ?? 0) !== $idEmpresa
        || ($dados['usuario_status'] ?? null) !== 'ativo' || ($dados['empresa_status'] ?? null) !== 'ativo'
        || ($dados['vinculo_status'] ?? null) !== 'ativo' || ($dados['perfil_status'] ?? null) !== 'ativo'
        || !in_array($perfil, ['proprietário', 'proprietario'], true)
        || !$emailValido($emailBanco) || !$emailValido($emailSessao)
        || mb_strtolower($emailBanco, 'UTF-8') !== mb_strtolower($emailSessao, 'UTF-8')) {
        throw assinaturaServicoPreapprovalIdentidadePagadorErro();
    }

    return $emailBanco;
}

/** Erro único de identidade do pagador: 401 SESSION_REAUTH_REQUIRED, sem qualquer e-mail na mensagem. */
function assinaturaServicoPreapprovalIdentidadePagadorErro(): AssinaturaServicoConversaoErro
{
    return new AssinaturaServicoConversaoErro(
        'SESSION_REAUTH_REQUIRED',
        'Não foi possível confirmar a sua identidade para este pagamento. Entre novamente e tente de novo.',
        401
    );
}

/**
 * Lê o usuário e o vínculo empresarial atuais e devolve o e-mail validado para `payer_email`. Só é chamada quando a
 * operação realmente vai criar uma nova Preapproval, dentro da transação de reserva: qualquer falha desfaz a reserva
 * (nenhuma operação fica em 'processando') e nenhum POST é feito. O e-mail nunca vem do navegador: a sessão fornece
 * apenas `id_usuario` e `email`, que são conferidos contra o banco.
 *
 * @param array<string, mixed> $identidade id_usuario e email da sessão autenticada
 * @throws AssinaturaServicoConversaoErro
 */
function assinaturaServicoPreapprovalIdentidadePagador(mysqli $conexao, int $idEmpresa, array $identidade): string
{
    $idUsuario = is_int($identidade['id_usuario'] ?? null) ? $identidade['id_usuario'] : 0;
    $emailSessao = is_string($identidade['email'] ?? null) ? $identidade['email'] : '';
    $dados = $idEmpresa > 0 && $idUsuario > 0 ? (assinaturaServicoConsultaFinanceira(
        $conexao,
        'SELECT u.id_usuario, eu.id_empresa, u.status AS usuario_status, e.status AS empresa_status,
                eu.status AS vinculo_status, pf.status AS perfil_status, pf.nome AS perfil_nome, u.email
           FROM empresa_usuario eu
          INNER JOIN usuario u ON u.id_usuario = eu.id_usuario
          INNER JOIN empresa e ON e.id_empresa = eu.id_empresa
          INNER JOIN perfil pf ON pf.id_perfil = eu.id_perfil
          WHERE eu.id_empresa = ? AND eu.id_usuario = ?
          LIMIT 1',
        'ii',
        $idEmpresa,
        $idUsuario
    )[0] ?? []) : [];

    return assinaturaServicoPreapprovalIdentidadePagadorValidarDados($dados, $idEmpresa, $idUsuario, $emailSessao);
}

/**
 * Etapa 1 (transação): valida, reserva a intenção local e a "vez" de falar com o provedor.
 *
 * Contratação nova não paga (primeira cobrança): não há período pago; `inicio` é o início da oferta (nenhuma cobrança
 * externa antes dele) e `inicio_envio` é a `start_date` enviada. Nos demais casos `inicio_envio` é null.
 *
 * Identidade do pagador: quando `$identidadePagador` é informada e a ação é 'criar', o e-mail atual do proprietário é
 * validado no banco (assinaturaServicoPreapprovalIdentidadePagador) e devolvido em `email_pagador`; falha desfaz a
 * transação inteira. Chamadas que nunca criam (conciliação) passam null e seguem inalteradas. As demais ações
 * (vinculada, conciliar, em_andamento, expirada) não consultam a identidade: Preapprovals existentes não mudam.
 *
 * @param array<string, mixed>|null $identidadePagador id_usuario e email da sessão; null somente para quem não cria
 * @return array{acao: string, contrato: array<string, mixed>, vinculo: array<string, mixed>, operacao: ?array<string, mixed>, inicio: DateTimeImmutable, inicio_envio: ?DateTimeImmutable, email_pagador?: string}
 *         acao: 'criar' | 'conciliar' | 'vinculada' | 'em_andamento' | 'sem_pendencia' (só com $permiteCriar = false)
 * @throws AssinaturaServicoConversaoErro
 */
function assinaturaServicoPreapprovalReservar(mysqli $conexao, string $ambiente, int $idEmpresa, ?array $ator = null, ?array $identidadePagador = null, bool $permiteCriar = true): array
{
    $contrato = assinaturaServicoPreapprovalContrato($conexao, $idEmpresa);
    $idAssinatura = $contrato['id_assinatura'];

    if (assinaturaServicoTrocaPendenteEmpresa($conexao, $idEmpresa)) {
        throw new AssinaturaServicoConversaoErro('TROCA_PLANO_PENDENTE', 'Há uma troca de plano em andamento. Conclua ou cancele antes de configurar o pagamento recorrente.', 409);
    }
    $pendencia = assinaturaServicoPendenciaFinanceira($conexao, $idEmpresa, true);
    if ($pendencia !== null) {
        throw new AssinaturaServicoConversaoErro((string)$pendencia['codigo'], (string)$pendencia['mensagem'], 409);
    }
    $envio = null;
    if ($contrato['primeira_cobranca'] !== null) {
        $renovacao = assinaturaServicoPreapprovalRenovarOfertaAntiga($conexao, $idEmpresa, $contrato, $ator);
        $contrato = $renovacao['contrato'];
        if ($renovacao['expirada'] !== null) {
            // Checkout antigo: nenhuma autorização é oferecida até o cancelamento da Preapproval anterior ser confirmado.
            return ['acao' => 'expirada', 'contrato' => $contrato, 'vinculo' => assinaturaServicoPreapprovalVinculoDoContrato($conexao, $idEmpresa, $idAssinatura),
                'operacao' => ['id_operacao' => $renovacao['expirada']['id_operacao'] ?? null], 'inicio' => null, 'inicio_envio' => null];
        }
        $datas = assinaturaServicoPreapprovalInicioPrimeiraCobranca($contrato['primeira_cobranca']['periodo_inicio']);
        $inicio = $datas['limite'];
        $envio = $datas['envio'];
    } elseif ($contrato['teste'] !== null) {
        // Teste grátis: start_date é o fim do teste; nenhuma parcela antes dele (COBRANCA_EXTERNA_ANTECIPADA).
        $inicio = assinaturaServicoPreapprovalInicioTeste((string)$contrato['teste']['expira_em']);
    } else {
        $fimPago = assinaturaServicoFimPeriodoPago($conexao, $idEmpresa, $idAssinatura);
        if ($fimPago === null) {
            throw new AssinaturaServicoConversaoErro('PERIODO_PAGO_INDISPONIVEL', 'Não há período pago vigente para preservar.', 409);
        }
        $inicio = assinaturaServicoPreapprovalInicioCobranca($fimPago);
    }

    $vinculos = assinaturaServicoConsultaFinanceira(
        $conexao,
        'SELECT id_assinatura_pagamento, id_empresa, id_assinatura, provedor, ambiente, motor_recorrencia, status, slot_ativo,
                assinatura_externa_id, referencia_externa_assinatura
           FROM assinatura_pagamento_recorrente
          WHERE id_empresa = ?
          ORDER BY id_assinatura_pagamento
          FOR UPDATE',
        'i',
        $idEmpresa
    );
    $vinculo = null;
    $encerrado = null;
    foreach ($vinculos as $linha) {
        if ((int)$linha['id_assinatura'] === $idAssinatura) {
            if ((string)$linha['motor_recorrencia'] === 'preapproval' && in_array((string)$linha['status'], ['cancelada', 'encerrada'], true)) {
                $encerrado = $linha; // Histórico: uma Preapproval encerrada nunca é reaproveitada.
            } else {
                $vinculo = $linha;
            }
            continue;
        }
        if ((int)($linha['slot_ativo'] ?? 0) === 1
            || in_array((string)$linha['status'], ['pendente', 'ativa', 'suspensa', 'atualizacao_necessaria', 'cancelamento_pendente'], true)) {
            throw new AssinaturaServicoConversaoErro('MOTOR_RECORRENTE_ATIVO', 'Já existe uma configuração de pagamento automático em andamento ou ativa.', 409);
        }
    }

    if ($vinculo === null && $encerrado !== null && !assinaturaServicoPreapprovalNovoVinculoPermitido($conexao)) {
        throw new AssinaturaServicoConversaoErro('NOVA_AUTORIZACAO_INDISPONIVEL', 'O pagamento no cartão deste contrato foi encerrado e uma nova autorização ainda não está disponível. Use o PIX.', 409);
    }
    $intencaoNova = $vinculo === null;
    if ($intencaoNova) {
        // Sem vínculo vigente (nenhum, ou só encerrados com a estrutura da Fase 21): nova intenção, nova referência.
        $diasReferencia = (string)$contrato['frequencia_tipo'] === 'days' ? (int)$contrato['frequencia_meses'] : null;
        $vinculo = assinaturaServicoPreapprovalInserirIntencao(
            $conexao,
            $ambiente,
            $idEmpresa,
            $idAssinatura,
            $diasReferencia
        );
    } else {
        if ((string)$vinculo['provedor'] !== ASSINATURA_SERVICO_PREAPPROVAL_PROVEDOR || (string)$vinculo['ambiente'] !== $ambiente) {
            throw new AssinaturaServicoConversaoErro('AMBIENTE_INCOMPATIVEL', 'A configuração de pagamento existente pertence a outro ambiente.', 409);
        }
        if ((string)$vinculo['motor_recorrencia'] !== 'preapproval') {
            throw new AssinaturaServicoConversaoErro('MOTOR_ORDERS_EXISTENTE', 'Já existe um vínculo de pagamento por cartão no motor atual para este contrato.', 409);
        }
        if ((string)$vinculo['status'] === 'cancelamento_pendente') {
            // Nunca devolve o link de uma Preapproval cujo cancelamento já foi pedido.
            throw new AssinaturaServicoConversaoErro('CANCELAMENTO_CARTAO_EM_ANDAMENTO', 'O cancelamento da autorização anterior no cartão ainda está sendo confirmado pelo Mercado Pago. Tente novamente em instantes.', 409);
        }
        if ($vinculo['assinatura_externa_id'] !== null) {
            return ['acao' => 'vinculada', 'contrato' => $contrato, 'vinculo' => $vinculo, 'operacao' => null, 'inicio' => $inicio, 'inicio_envio' => $envio];
        }
        if ($vinculo['referencia_externa_assinatura'] === null || !in_array((string)$vinculo['status'], ['pendente', 'erro'], true)) {
            throw new AssinaturaServicoConversaoErro('VINCULO_INCONSISTENTE', 'O vínculo de pagamento recorrente está inconsistente.', 409);
        }
    }

    $operacoes = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT id_operacao, status_processamento, erro_codigo,
                TIMESTAMPDIFF(SECOND, COALESCE(ultima_tentativa_em, criado_em), NOW(6)) AS idade_segundos
           FROM operacao_assinatura_gateway
          WHERE id_assinatura_pagamento = ? AND status_processamento IN ('pendente', 'processando', 'conciliacao')
          FOR UPDATE",
        'i',
        (int)$vinculo['id_assinatura_pagamento']
    );
    $aberta = $operacoes[0] ?? null;
    $acao = 'criar';

    if ($aberta !== null) {
        $interrompida = (string)$aberta['status_processamento'] === 'processando'
            && (int)$aberta['idade_segundos'] > ASSINATURA_SERVICO_PREAPPROVAL_OPERACAO_INTERROMPIDA_SEGUNDOS;
        if ((string)$aberta['status_processamento'] === 'processando' && !$interrompida) {
            return ['acao' => 'em_andamento', 'contrato' => $contrato, 'vinculo' => $vinculo, 'operacao' => $aberta, 'inicio' => $inicio, 'inicio_envio' => $envio];
        }
        if ($interrompida && $aberta['erro_codigo'] === null) {
            // Morreu antes de marcar o envio: o POST certamente não saiu; a tentativa é encerrada e refeita.
            assinaturaServicoPreapprovalExec(
                $conexao,
                "UPDATE operacao_assinatura_gateway
                    SET status_processamento = 'erro', erro_codigo = 'TENTATIVA_NAO_ENVIADA', erro_motivo = 'Tentativa interrompida antes do envio.', concluida_em = NULL
                  WHERE id_operacao = ? AND status_processamento = 'processando'",
                'i',
                (int)$aberta['id_operacao']
            );
            $aberta = null;
        } else {
            // Pendente, interrompida após o envio ou em conciliação: só conciliação, jamais novo POST.
            $afetadas = assinaturaServicoPreapprovalExec(
                $conexao,
                "UPDATE operacao_assinatura_gateway
                    SET status_processamento = 'processando', tentativas_processamento = tentativas_processamento + 1,
                        ultima_tentativa_em = NOW(6), proxima_tentativa_em = NULL
                  WHERE id_operacao = ? AND status_processamento IN ('pendente', 'processando', 'conciliacao')",
                'i',
                (int)$aberta['id_operacao']
            );
            if ($afetadas !== 1) {
                throw new AssinaturaServicoConversaoErro('OPERACAO_EM_ANDAMENTO', 'Já existe uma operação de assinatura recorrente em andamento.', 409);
            }
            $acao = 'conciliar';
        }
    }

    if ($acao === 'criar' && !$permiteCriar) {
        // Conciliação nunca cria. Vínculo recém-inserido: a exceção desfaz a transação, como antes. Vínculo existente:
        // devolve sem inserir nada, para que o encerramento acima de uma tentativa interrompida antes do envio
        // (TENTATIVA_NAO_ENVIADA) seja gravado em vez de desfeito junto com a intenção de criar.
        if ($intencaoNova) {
            throw new AssinaturaServicoConversaoErro('SEM_CONCILIACAO_PENDENTE', 'Não há conciliação pendente.', 409);
        }

        return ['acao' => 'sem_pendencia', 'contrato' => $contrato, 'vinculo' => $vinculo, 'operacao' => null, 'inicio' => $inicio, 'inicio_envio' => $envio];
    }
    if ($acao === 'criar' && $contrato['primeira_cobranca'] !== null) {
        // Contratação sem teste grátis: a primeira mensalidade é paga por PIX ou pelo suporte e o cartão só é cadastrado
        // depois dela. No teste grátis ativo o cadastro é opcional (start_date = fim do teste, validado na criação e na
        // ativação). Vínculos já existentes seguem apenas pela conciliação.
        throw new AssinaturaServicoConversaoErro('CARTAO_SOMENTE_APOS_QUITACAO', 'Pague a primeira mensalidade via PIX. O cartão poderá ser cadastrado depois da ativação do plano.', 409);
    }
    if ($acao === 'criar' && !ASSINATURA_SERVICO_PREAPPROVAL_CADASTRO_CARTAO_LIBERADO) {
        throw new AssinaturaServicoConversaoErro('CADASTRO_CARTAO_INDISPONIVEL', 'O cadastro do cartão ainda não está disponível. Pague as mensalidades via PIX ou fale com o suporte.', 409);
    }
    if ($acao === 'criar') {
        $chave = pagamentoGatewayChaveIdempotencia();
        try {
            assinaturaServicoPreapprovalExec(
                $conexao,
                "INSERT INTO operacao_assinatura_gateway
                    (id_empresa, id_assinatura_pagamento, tipo_operacao, chave_operacao, status_processamento, tentativas_processamento, ultima_tentativa_em)
                 VALUES (?, ?, 'conciliar', ?, 'processando', 1, NOW(6))",
                'iis',
                $idEmpresa,
                (int)$vinculo['id_assinatura_pagamento'],
                $chave
            );
        } catch (RuntimeException $e) {
            if (assinaturaServicoPreapprovalChaveDuplicada($e)) {
                throw new AssinaturaServicoConversaoErro('OPERACAO_EM_ANDAMENTO', 'Já existe uma operação de assinatura recorrente em andamento.', 409);
            }
            throw $e;
        }
        $aberta = ['id_operacao' => (int)$conexao->insert_id, 'status_processamento' => 'processando', 'erro_codigo' => null];
        assinaturaServicoPreapprovalExec(
            $conexao,
            "UPDATE assinatura_pagamento_recorrente
                SET status = 'pendente', ultimo_erro_codigo = NULL, ultimo_erro_motivo = NULL
              WHERE id_assinatura_pagamento = ? AND id_empresa = ? AND status IN ('pendente', 'erro') AND assinatura_externa_id IS NULL",
            'ii',
            (int)$vinculo['id_assinatura_pagamento'],
            $idEmpresa
        );
    }

    $reserva = ['acao' => $acao, 'contrato' => $contrato, 'vinculo' => $vinculo, 'operacao' => $aberta, 'inicio' => $inicio, 'inicio_envio' => $envio];
    if ($reserva['acao'] === 'criar') {
        // Só uma nova Preapproval consulta a identidade do pagador. Falha aqui desfaz a reserva (rollback do chamador) e
        // nenhum POST é feito. Quem nunca cria (conciliação) não informa identidade e segue o fluxo de antes.
        if ($identidadePagador !== null) {
            $reserva['email_pagador'] = assinaturaServicoPreapprovalIdentidadePagador($conexao, $idEmpresa, $identidadePagador);
        }
    }

    return $reserva;
}

/** Insere a intenção local (referência opaca e única) antes de qualquer chamada externa. */
function assinaturaServicoPreapprovalInserirIntencao(
    mysqli $conexao,
    string $ambiente,
    int $idEmpresa,
    int $idAssinatura,
    ?int $diasDiaria = null
): array
{
    for ($tentativa = 0; $tentativa < 3; $tentativa++) {
        $referencia = pagamentoGatewayReferenciaInterna();
        if ($diasDiaria !== null && !in_array($diasDiaria, [1, 2], true)) {
            throw new RuntimeException('Ciclo diário inválido para a referência da Preapproval.');
        }
        if ($diasDiaria === 2) {
            $referencia .= ASSINATURA_SERVICO_PREAPPROVAL_REFERENCIA_DIARIA_48H;
        }
        try {
            assinaturaServicoPreapprovalExec(
                $conexao,
                "INSERT INTO assinatura_pagamento_recorrente
                    (id_empresa, id_assinatura, provedor, ambiente, motor_recorrencia, status, chave_idempotencia_criacao, referencia_externa_assinatura, slot_ativo)
                 VALUES (?, ?, ?, ?, 'preapproval', 'pendente', ?, ?, NULL)",
                'iissss',
                $idEmpresa,
                $idAssinatura,
                ASSINATURA_SERVICO_PREAPPROVAL_PROVEDOR,
                $ambiente,
                pagamentoGatewayChaveIdempotencia(),
                $referencia
            );
        } catch (RuntimeException $e) {
            if (assinaturaServicoPreapprovalChaveDuplicada($e)) {
                continue; // Colisão improvável de referência/chave; o vínculo do contrato já foi verificado sob lock.
            }
            throw $e;
        }

        return [
            'id_assinatura_pagamento' => (int)$conexao->insert_id,
            'id_empresa' => $idEmpresa,
            'id_assinatura' => $idAssinatura,
            'provedor' => ASSINATURA_SERVICO_PREAPPROVAL_PROVEDOR,
            'ambiente' => $ambiente,
            'motor_recorrencia' => 'preapproval',
            'status' => 'pendente',
            'slot_ativo' => null,
            'assinatura_externa_id' => null,
            'referencia_externa_assinatura' => $referencia,
        ];
    }

    throw new RuntimeException('Não foi possível reservar a referência externa da assinatura.');
}

/** Executa uma etapa dentro de transação própria; qualquer falha desfaz tudo. */
function assinaturaServicoPreapprovalTransacao(mysqli $conexao, callable $etapa): mixed
{
    if (!$conexao->begin_transaction()) {
        throw new RuntimeException('Falha ao iniciar a transação da assinatura recorrente.');
    }
    try {
        $resultado = $etapa();
        if (!$conexao->commit()) {
            throw new RuntimeException('Falha ao confirmar a assinatura recorrente.');
        }

        return $resultado;
    } catch (Throwable $e) {
        try {
            $conexao->rollback();
        } catch (Throwable) {
        }
        throw $e;
    }
}

/**
 * Fecha a operação aberta que esta execução detém ('processando'). A conciliação nunca vira 'erro' sozinha (isso
 * liberaria um novo POST e uma Preapproval duplicada); por isso a espera cresce com as tentativas, até um dia, e a
 * pendência antiga aparece no diagnóstico para análise manual.
 */
function assinaturaServicoPreapprovalEncerrarOperacao(mysqli $conexao, int $idOperacao, string $status, ?string $codigo, ?string $motivo): bool
{
    return assinaturaServicoPreapprovalExec(
        $conexao,
        "UPDATE operacao_assinatura_gateway
            SET status_processamento = ?, erro_codigo = ?, erro_motivo = ?,
                concluida_em = CASE WHEN ? = 'concluida' THEN NOW(6) ELSE NULL END,
                proxima_tentativa_em = CASE WHEN ? = 'conciliacao' THEN DATE_ADD(NOW(6), INTERVAL
                    CASE WHEN tentativas_processamento <= 3 THEN 5 WHEN tentativas_processamento <= 6 THEN 30
                         WHEN tentativas_processamento <= 10 THEN 120 WHEN tentativas_processamento <= 15 THEN 720
                         ELSE 1440 END MINUTE) ELSE NULL END
          WHERE id_operacao = ? AND status_processamento = 'processando'",
        'sssssi',
        $status,
        $codigo,
        $motivo,
        $status,
        $status,
        $idOperacao
    ) === 1;
}

/** Registra no vínculo o último problema, sem texto do provedor. */
function assinaturaServicoPreapprovalRegistrarErro(mysqli $conexao, array $vinculo, string $codigo, string $motivo, bool $definitivo): void
{
    assinaturaServicoPreapprovalExec(
        $conexao,
        "UPDATE assinatura_pagamento_recorrente
            SET ultimo_erro_codigo = ?, ultimo_erro_motivo = ?, status = CASE WHEN ? = 1 THEN 'erro' ELSE status END
          WHERE id_assinatura_pagamento = ? AND id_empresa = ? AND status IN ('pendente', 'erro') AND assinatura_externa_id IS NULL",
        'ssiii',
        $codigo,
        $motivo,
        $definitivo ? 1 : 0,
        (int)$vinculo['id_assinatura_pagamento'],
        (int)$vinculo['id_empresa']
    );
}

/**
 * Grava o vínculo após confirmação autoritativa e encerra a operação, atomicamente.
 * O vínculo continua 'pendente' (slot_ativo NULL): autorização não é quitação nem ativação.
 *
 * @param array<string, mixed> $dados Preapproval já validada
 * @return string 'vinculada' | 'conflito'
 */
function assinaturaServicoPreapprovalGravarVinculo(mysqli $conexao, array $vinculo, int $idOperacao, array $dados, DateTimeImmutable $inicio): string
{
    $campos = assinaturaServicoPreapprovalCamposVinculo($dados, $inicio);
    try {
        return assinaturaServicoPreapprovalTransacao($conexao, static function () use ($conexao, $vinculo, $idOperacao, $campos): string {
            $atual = assinaturaServicoConsultaFinanceira(
                $conexao,
                'SELECT assinatura_externa_id FROM assinatura_pagamento_recorrente WHERE id_assinatura_pagamento = ? AND id_empresa = ? FOR UPDATE',
                'ii',
                (int)$vinculo['id_assinatura_pagamento'],
                (int)$vinculo['id_empresa']
            );
            $jaVinculado = $atual[0]['assinatura_externa_id'] ?? null;
            if ($atual === [] || ($jaVinculado !== null && $jaVinculado !== $campos['id'])) {
                return 'conflito';
            }
            if ($jaVinculado === null) {
                $afetadas = assinaturaServicoPreapprovalExec(
                    $conexao,
                    "UPDATE assinatura_pagamento_recorrente
                        SET motor_recorrencia = 'preapproval', status = 'pendente', assinatura_externa_id = ?, referencia_externa_assinatura = ?,
                            versao_externa = ?, status_externo = ?, proxima_cobranca_em = ?, ultima_sincronizacao_em = NOW(6),
                            ultimo_erro_codigo = NULL, ultimo_erro_motivo = NULL
                      WHERE id_assinatura_pagamento = ? AND id_empresa = ? AND provedor = ? AND ambiente = ?
                        AND motor_recorrencia = 'preapproval' AND assinatura_externa_id IS NULL
                        AND referencia_externa_assinatura = ? AND status IN ('pendente', 'erro') AND slot_ativo IS NULL",
                    'ssissiisss',
                    $campos['id'],
                    (string)$vinculo['referencia_externa_assinatura'],
                    $campos['versao'],
                    $campos['status_externo'],
                    $campos['proxima'],
                    (int)$vinculo['id_assinatura_pagamento'],
                    (int)$vinculo['id_empresa'],
                    (string)$vinculo['provedor'],
                    (string)$vinculo['ambiente'],
                    (string)$vinculo['referencia_externa_assinatura']
                );
                if ($afetadas !== 1) {
                    return 'conflito';
                }
            }
            if (!assinaturaServicoPreapprovalEncerrarOperacao($conexao, $idOperacao, 'concluida', null, null)) {
                throw new RuntimeException('Operação de assinatura recorrente não está mais sob controle desta execução.');
            }

            return 'vinculada';
        });
    } catch (RuntimeException $e) {
        if (assinaturaServicoPreapprovalChaveDuplicada($e)) {
            return 'conflito'; // assinatura_externa_id já pertence a outro vínculo.
        }
        throw $e;
    }
}

/** Mantém a operação aberta, em conciliação, com motivo seguro. */
function assinaturaServicoPreapprovalMarcarConciliacao(mysqli $conexao, array $vinculo, int $idOperacao, string $codigo, string $motivo): void
{
    assinaturaServicoPreapprovalEncerrarOperacao($conexao, $idOperacao, 'conciliacao', $codigo, $motivo);
    assinaturaServicoPreapprovalRegistrarErro($conexao, $vinculo, $codigo, $motivo, false);
}

/**
 * Classifica a resposta da pesquisa: 'ok', 'recusada' (requisição inválida ou rejeitada: não é queda do provedor e se
 * repetiria para sempre) ou 'indisponivel' (falha transitória ou resposta que não prova nada).
 *
 * @param array<string, mixed> $resposta
 */
function assinaturaServicoPreapprovalEstadoBusca(array $resposta): string
{
    if (($resposta['sucesso'] ?? false) === true && is_array($resposta['dados']['results'] ?? null)) {
        return 'ok';
    }

    return in_array((string)($resposta['codigo'] ?? ''), ['operacao_invalida', 'requisicao_invalida', 'provedor_rejeitou'], true)
        ? 'recusada' : 'indisponivel';
}

/**
 * Status em que uma Preapproval criada ainda pode ser recuperada, autorizada ou cobrar. O Mercado Pago NÃO honra o filtro
 * `external_reference` do /preapproval/search (observado: devolve a conta inteira); o filtro `status` é honrado. A pesquisa
 * percorre, então, cada status por completo (com paginação) e confere a referência de cada item, sem depender do filtro de
 * referência e sem limite prático de tamanho da conta.
 */
const ASSINATURA_SERVICO_PREAPPROVAL_STATUS_BUSCA = ['pending', 'authorized', 'paused'];

/**
 * Varre todas as páginas de um status. `$referencia` (opcional) só vai ao provedor como filtro adicional; quem decide se um
 * item corresponde é `$casa`, sempre no servidor.
 *
 * @param callable(string): bool $casa
 * @return array{estado: string, completa: bool, ids: array<string, string>, itens: int, outras_referencias: bool, status_honrado: bool, mais_recente: ?DateTimeImmutable}
 */
function assinaturaServicoPreapprovalVarrerStatus(object $gateway, ?string $referencia, string $status, int $paginas, callable $casa): array
{
    $r = ['estado' => 'ok', 'completa' => false, 'ids' => [], 'itens' => 0, 'outras_referencias' => false, 'status_honrado' => true, 'mais_recente' => null];
    $normalizar = static fn (mixed $s): string => $s === 'cancelled' ? 'canceled' : (string)$s;
    $offset = 0;
    for ($pagina = 0; $pagina < $paginas; $pagina++) {
        $filtros = ['status' => $status, 'limit' => ASSINATURA_SERVICO_PREAPPROVAL_BUSCA_LIMITE, 'offset' => $offset];
        if ($referencia !== null) {
            $filtros = ['external_reference' => $referencia] + $filtros;
        }
        $resposta = $gateway->buscarPreapprovals($filtros);
        $estadoBusca = assinaturaServicoPreapprovalEstadoBusca($resposta);
        if ($estadoBusca !== 'ok') {
            // Só código e HTTP, sem corpo do provedor nem referência.
            error_log('[assinatura_preapproval] busca_' . $estadoBusca . ' codigo=' . preg_replace('/[^a-z_]/', '', (string)($resposta['codigo'] ?? ''))
                . ' http=' . (int)($resposta['http_status'] ?? 0));
            $r['estado'] = $estadoBusca;

            return $r;
        }
        $resultados = $resposta['dados']['results'];
        foreach ($resultados as $item) {
            if (!is_array($item)) {
                continue;
            }
            $r['itens']++;
            $externa = $item['external_reference'] ?? null;
            if (is_string($externa) && $casa($externa) && isset($item['id'])) {
                $r['ids'][(string)$item['id']] = $externa; // id => referência encontrada (base igual pode trazer outra rotação)
            } else {
                $r['outras_referencias'] = true;
            }
            if ($normalizar($item['status'] ?? null) !== $normalizar($status)) {
                $r['status_honrado'] = false;
            }
            $criada = assinaturaServicoPreapprovalDataExterna($item['date_created'] ?? null);
            if ($criada !== null && ($r['mais_recente'] === null || $criada > $r['mais_recente'])) {
                $r['mais_recente'] = $criada;
            }
        }
        $total = $resposta['dados']['paging']['total'] ?? null;
        $offset += ASSINATURA_SERVICO_PREAPPROVAL_BUSCA_LIMITE;
        if (count($resultados) < ASSINATURA_SERVICO_PREAPPROVAL_BUSCA_LIMITE || (is_int($total) && $offset >= $total)) {
            $r['completa'] = true;

            return $r;
        }
    }

    return $r;
}

/**
 * Procura a Preapproval pela referência, conferindo cada resultado (a pesquisa não garante filtro exato).
 *
 * @return array{estado: string, id: ?string} estado: 'encontrada' | 'nao_encontrada' | 'multiplas' | 'recusada' | 'indisponivel'
 */
function assinaturaServicoPreapprovalLocalizar(object $gateway, string $referencia): array
{
    $ids = [];
    $completa = true;
    $casa = static fn (string $externa): bool => $externa === $referencia;
    foreach (ASSINATURA_SERVICO_PREAPPROVAL_STATUS_BUSCA as $status) {
        $varredura = assinaturaServicoPreapprovalVarrerStatus($gateway, $referencia, $status, ASSINATURA_SERVICO_PREAPPROVAL_BUSCA_PAGINAS, $casa);
        if ($varredura['estado'] !== 'ok') {
            return ['estado' => $varredura['estado'], 'id' => null];
        }
        $completa = $completa && $varredura['completa'];
        foreach (array_keys($varredura['ids']) as $id) {
            $ids[(string)$id] = true;
        }
    }

    if (count($ids) > 1) {
        return ['estado' => 'multiplas', 'id' => null];
    }
    if (count($ids) === 1) {
        return ['estado' => 'encontrada', 'id' => (string)array_key_first($ids)];
    }

    // Sem resultado, ou pesquisa incompleta: a inexistência não está provada.
    return ['estado' => $completa ? 'nao_encontrada' : 'indisponivel', 'id' => null];
}
/**
 * Etapa 3: esclarece o estado externo da tentativa (pesquisa + consulta autoritativa por ID) e vincula.
 * Mantém a operação em conciliação quando a existência não pode ser confirmada.
 *
 * @param array<string, mixed> $reserva retorno de assinaturaServicoPreapprovalReservar()
 */
function assinaturaServicoPreapprovalEsclarecer(mysqli $conexao, object $gateway, array $reserva, ?string $emailPagador): array
{
    $vinculo = $reserva['vinculo'];
    $idOperacao = (int)$reserva['operacao']['id_operacao'];
    $contrato = $reserva['contrato'];
    $referencia = (string)$vinculo['referencia_externa_assinatura'];
    $resultado = static fn (bool $ok, string $codigo, string $mensagem, array $extra = []): array => assinaturaServicoNovaContratacaoResultado(
        $ok,
        $codigo,
        $mensagem,
        array_merge(['id_assinatura_pagamento' => (int)$vinculo['id_assinatura_pagamento']], $extra)
    );

    try {
        $local = assinaturaServicoPreapprovalLocalizar($gateway, $referencia);
        if ($local['estado'] !== 'encontrada') {
            $mapa = [
                'multiplas' => ['MULTIPLAS_ASSINATURAS_EXTERNAS', 'Mais de uma assinatura externa usa a mesma referência; é necessária análise manual.'],
                'nao_encontrada' => ['EXISTENCIA_NAO_CONFIRMADA', 'A busca não encontrou a assinatura externa, o que ainda não prova que ela não foi criada.'],
                'recusada' => ['CONCILIACAO_CONSULTA_RECUSADA', 'A consulta ao provedor foi recusada; é necessária análise do suporte.'],
                'indisponivel' => ['CONCILIACAO_INDISPONIVEL', 'Não foi possível consultar o provedor agora.'],
            ];
            [$codigo, $motivo] = $mapa[$local['estado']];
            assinaturaServicoPreapprovalMarcarConciliacao($conexao, $vinculo, $idOperacao, $codigo, $motivo);

            $publicas = [
                'multiplas' => 'Encontramos mais de uma autorização para este cadastro. Por segurança, nenhuma nova será criada; fale com o suporte.',
                'nao_encontrada' => 'Ainda não localizamos o cadastro do cartão no Mercado Pago, e isso não prova que ele não foi aberto. Nenhuma nova tentativa será feita automaticamente; atualize a situação mais tarde ou fale com o suporte.',
                'recusada' => 'A consulta ao Mercado Pago foi recusada. Nenhuma nova tentativa será feita; fale com o suporte.',
            ];

            return $resultado(false, $codigo, $publicas[$local['estado']] ?? 'Não foi possível confirmar a assinatura recorrente. Nenhuma nova tentativa será feita até a conciliação.', ['estado' => 'conciliacao']);
        }

        $consulta = $gateway->consultarPreapproval((string)$local['id']);
        if (($consulta['sucesso'] ?? false) !== true || !is_array($consulta['dados'] ?? null)) {
            assinaturaServicoPreapprovalMarcarConciliacao($conexao, $vinculo, $idOperacao, 'CONCILIACAO_INDISPONIVEL', 'Não foi possível consultar o provedor agora.');

            return $resultado(false, 'CONCILIACAO_INDISPONIVEL', 'Não foi possível confirmar a assinatura recorrente. Nenhuma nova tentativa será feita até a conciliação.', ['estado' => 'conciliacao']);
        }

        return assinaturaServicoPreapprovalVincularConfirmada($conexao, $vinculo, $idOperacao, $contrato, $consulta['dados'], $reserva['inicio'], $emailPagador, (string)$local['id']);
    } catch (Throwable $e) {
        // Nada em memória decide o destino da operação: ela continua aberta para nova conciliação.
        try {
            assinaturaServicoPreapprovalMarcarConciliacao($conexao, $vinculo, $idOperacao, 'CONCILIACAO_FALHOU', 'Falha interna durante a conciliação.');
        } catch (Throwable) {
        }

        return $resultado(false, 'CONCILIACAO_FALHOU', 'Não foi possível confirmar a assinatura recorrente.', ['estado' => 'conciliacao']);
    }
}

/**
 * Valida uma Preapproval autoritativa contra o contrato e, se coerente, vincula.
 * Divergência mantém a operação em conciliação e NÃO vincula nem cria outra.
 */
function assinaturaServicoPreapprovalVincularConfirmada(mysqli $conexao, array $vinculo, int $idOperacao, array $contrato, array $dados, DateTimeImmutable $inicio, ?string $emailPagador, ?string $idEsperado): array
{
    $base = ['id_assinatura_pagamento' => (int)$vinculo['id_assinatura_pagamento']];
    $divergencia = assinaturaServicoPreapprovalValidarExterna($dados, [
        'referencia' => (string)$vinculo['referencia_externa_assinatura'],
        'ambiente' => (string)$vinculo['ambiente'],
        'valor_centavos' => $contrato['valor_centavos'],
        'frequencia_meses' => $contrato['frequencia_meses'],
        'frequencia_tipo' => $contrato['frequencia_tipo'],
        'id' => $idEsperado,
        'email' => $emailPagador,
        'inicio' => $inicio,
        'inicio_pendente_opcional' => assinaturaServicoPreapprovalPrimeiraImediata($contrato, $inicio),
        'antecipacao_dias' => assinaturaServicoPreapprovalAntecipacaoDiasContrato($contrato),
        'em_teste' => ($contrato['teste'] ?? null) !== null,
    ]);
    if ($divergencia !== null) {
        assinaturaServicoPreapprovalMarcarConciliacao($conexao, $vinculo, $idOperacao, $divergencia, 'Assinatura externa divergente do contrato interno.');

        return assinaturaServicoNovaContratacaoResultado(false, $divergencia, 'A assinatura externa não confere com o contrato. Nenhuma nova tentativa será feita.', $base + ['estado' => 'conciliacao']);
    }

    if (assinaturaServicoPreapprovalGravarVinculo($conexao, $vinculo, $idOperacao, $dados, $inicio) !== 'vinculada') {
        assinaturaServicoPreapprovalMarcarConciliacao($conexao, $vinculo, $idOperacao, 'CONFLITO_VINCULO_EXTERNO', 'Assinatura externa já vinculada a outro registro.');

        return assinaturaServicoNovaContratacaoResultado(false, 'CONFLITO_VINCULO_EXTERNO', 'Não foi possível vincular a assinatura externa.', $base + ['estado' => 'conciliacao']);
    }

    return assinaturaServicoNovaContratacaoResultado(true, 'PREAPPROVAL_VINCULADA', 'Assinatura recorrente criada e vinculada; aguardando autorização do pagador.', $base + [
        'estado' => 'vinculada',
        'status_externo' => (string)$dados['status'],
        'init_point' => (string)$dados['status'] === 'pending'
            // Só a consulta por id (conciliação) é autoritativa; a resposta da criação não aceita parâmetros extras.
            ? assinaturaServicoPreapprovalLinkAutorizacao($dados, (int)$vinculo['id_assinatura_pagamento'], $idEsperado !== null) : null,
    ]);
}

/**
 * Cria (uma única vez) e vincula a Preapproval do contrato vigente da empresa.
 * Idempotente: chamadas repetidas reutilizam a intenção local, concluem a conciliação pendente
 * ou devolvem o vínculo existente. Nenhuma ativação, cobrança ou alteração de contrato/cobrança.
 *
 * @param object $gateway MercadoPagoGateway (ou equivalente com a mesma interface, em testes)
 * @param array<string, mixed>|null $ator auditoria da renovação da oferta da primeira cobrança; null usa a sessão
 * @param int|null $idUsuario usuário autenticado (sessão) que contrata; `$emailPagador` é o e-mail da sessão. Uma NOVA
 *        Preapproval só é criada com o e-mail atual do proprietário lido do banco e conferido com a sessão
 *        (assinaturaServicoPreapprovalIdentidadePagador); sem `$idUsuario`, a criação é recusada (401) antes de qualquer POST.
 *        A retomada de uma Preapproval existente continua usando só `$emailPagador`, como antes.
 */
function assinaturaServicoPreapprovalIniciar(mysqli $conexao, object $gateway, int $idEmpresa, string $emailPagador, ?array $ator = null, ?int $idUsuario = null): array
{
    if ($idEmpresa <= 0 || strlen($emailPagador) > 254 || !filter_var($emailPagador, FILTER_VALIDATE_EMAIL)) {
        return assinaturaServicoNovaContratacaoResultado(false, 'DADOS_INVALIDOS', 'Dados inválidos para configurar o pagamento recorrente.', ['http_status' => 422]);
    }
    if (!$gateway::subscriptionsHabilitado()) {
        return assinaturaServicoNovaContratacaoResultado(false, 'SUBSCRIPTIONS_DESABILITADO', 'Pagamento recorrente indisponível.', ['http_status' => 503]);
    }
    $ambiente = $gateway->ambiente();
    $identidadePagador = ['id_usuario' => (int)($idUsuario ?? 0), 'email' => $emailPagador];

    try {
        $reserva = assinaturaServicoPreapprovalTransacao(
            $conexao,
            static function () use ($conexao, $ambiente, $idEmpresa, $ator, $identidadePagador): array {
                $reserva = assinaturaServicoPreapprovalReservar($conexao, $ambiente, $idEmpresa, $ator, $identidadePagador);
                if ($reserva['acao'] === 'criar' && !is_string($reserva['email_pagador'] ?? null)) {
                    // Nunca criar sem identidade validada: a exceção desfaz a reserva (nada fica em 'processando').
                    throw assinaturaServicoPreapprovalIdentidadePagadorErro();
                }

                return $reserva;
            }
        );
    } catch (AssinaturaServicoConversaoErro $e) {
        // Inclui SESSION_REAUTH_REQUIRED (401): a transação já foi desfeita e nenhum POST foi feito.
        return assinaturaServicoPreapprovalFalha($e);
    }

    $vinculo = $reserva['vinculo'];
    $base = ['id_assinatura_pagamento' => (int)$vinculo['id_assinatura_pagamento']];
    if ($reserva['acao'] === 'vinculada') {
        return assinaturaServicoPreapprovalAutorizacaoExistente($gateway, $reserva, $emailPagador);
    }
    if ($reserva['acao'] === 'em_andamento') {
        return assinaturaServicoNovaContratacaoResultado(false, 'OPERACAO_EM_ANDAMENTO', 'Já existe uma operação de assinatura recorrente em andamento.', $base + ['http_status' => 409]);
    }
    if ($reserva['acao'] === 'expirada') {
        return assinaturaServicoPreapprovalResultadoExpirada($reserva);
    }
    if ($reserva['acao'] === 'conciliar') {
        return assinaturaServicoPreapprovalEsclarecer($conexao, $gateway, $reserva, $emailPagador);
    }

    // Vínculo com tentativa anterior: antes de qualquer novo POST, a pesquisa completa precisa mostrar que nenhuma
    // Preapproval com esta referência existe (ou vincular a que existir). Nunca se cria às cegas.
    $conferida = assinaturaServicoPreapprovalConferirAntesDeCriar($conexao, $gateway, $reserva, $emailPagador);
    if ($conferida !== null) {
        return $conferida;
    }

    // Nova Preapproval: payer_email é exclusivamente o e-mail validado no banco (nunca o da requisição); a transação
    // acima já garantiu que ele existe.
    return assinaturaServicoPreapprovalEnviar($conexao, $gateway, $reserva, (string)$reserva['email_pagador']);
}

/** Checkout antigo cancelado pelo outbox: o chamador pode processar a operação; a nova autorização vem depois. */
function assinaturaServicoPreapprovalResultadoExpirada(array $reserva): array
{
    return assinaturaServicoNovaContratacaoResultado(false, 'AUTORIZACAO_ANTERIOR_EXPIRADA', 'A autorização anterior no cartão era de outro dia e está sendo cancelada no Mercado Pago. Assim que o cancelamento for confirmado, você poderá autorizar novamente ou pagar com PIX.', [
        'http_status' => 409,
        'id_assinatura_pagamento' => (int)($reserva['vinculo']['id_assinatura_pagamento'] ?? 0),
        'id_operacao' => $reserva['operacao']['id_operacao'] ?? null,
    ]);
}

/** Etapa 2: o único POST, sem transação aberta, depois de marcar na operação que o envio foi iniciado. */
function assinaturaServicoPreapprovalEnviar(mysqli $conexao, object $gateway, array $reserva, string $emailPagador): array
{
    $vinculo = $reserva['vinculo'];
    $idOperacao = (int)$reserva['operacao']['id_operacao'];
    $base = ['id_assinatura_pagamento' => (int)$vinculo['id_assinatura_pagamento']];
    $corpo = assinaturaServicoPreapprovalMontarCorpo(
        $reserva['contrato'],
        (string)$vinculo['referencia_externa_assinatura'],
        $emailPagador,
        assinaturaServicoPreapprovalInicioPayload($reserva['contrato'], $reserva['inicio'], $reserva['inicio_envio']),
        assinaturaServicoPreapprovalBackUrl($gateway::urlPublicaConfigurada())
    );

    // Marca de envio: sem ela, uma operação interrompida prova que nada saiu; com ela, só conciliação.
    $marcada = assinaturaServicoPreapprovalExec(
        $conexao,
        "UPDATE operacao_assinatura_gateway SET erro_codigo = 'ENVIO_INICIADO'
          WHERE id_operacao = ? AND status_processamento = 'processando' AND erro_codigo IS NULL",
        'i',
        $idOperacao
    );
    if ($marcada !== 1) {
        return assinaturaServicoNovaContratacaoResultado(false, 'OPERACAO_EM_ANDAMENTO', 'Já existe uma operação de assinatura recorrente em andamento.', $base + ['http_status' => 409]);
    }

    try {
        $resposta = $gateway->criarPreapproval($corpo);
    } catch (Throwable $e) {
        $resposta = pagamentoGatewayResultado(false, 'resultado_incerto', 'Não foi possível confirmar a operação de pagamento.');
    }
    $codigo = (string)($resposta['codigo'] ?? 'resultado_incerto');

    if (($resposta['sucesso'] ?? false) === true && is_array($resposta['dados'] ?? null)) {
        try {
            return assinaturaServicoPreapprovalVincularConfirmada(
                $conexao,
                $vinculo,
                $idOperacao,
                $reserva['contrato'],
                $resposta['dados'],
                $reserva['inicio'],
                $emailPagador,
                null
            );
        } catch (Throwable $e) {
            $codigo = 'resultado_incerto'; // O POST foi aceito; a gravação falhou: conciliar, nunca recriar.
        }
    }

    if ($codigo === 'resultado_incerto' || ($resposta['sucesso'] ?? false) === true) {
        assinaturaServicoPreapprovalMarcarConciliacao($conexao, $vinculo, $idOperacao, 'RESULTADO_INCERTO', 'Não foi possível confirmar a criação no provedor.');
        // Uma única consulta imediata: se o provedor já mostra a assinatura, vincula; senão fica para a conciliação.
        $retomada = assinaturaServicoPreapprovalExec(
            $conexao,
            "UPDATE operacao_assinatura_gateway SET status_processamento = 'processando', ultima_tentativa_em = NOW(6), proxima_tentativa_em = NULL
              WHERE id_operacao = ? AND status_processamento = 'conciliacao'",
            'i',
            $idOperacao
        );
        if ($retomada === 1) {
            return assinaturaServicoPreapprovalEsclarecer($conexao, $gateway, $reserva, $emailPagador);
        }

        return assinaturaServicoNovaContratacaoResultado(false, 'RESULTADO_INCERTO', 'Não foi possível confirmar a assinatura recorrente. Nenhuma nova tentativa será feita até a conciliação.', $base + ['estado' => 'conciliacao']);
    }

    // Falha comprovadamente anterior à criação (rejeição, transporte, chave desligada): a tentativa se encerra.
    $rejeitada = $codigo === 'provedor_rejeitou';
    $codigoLocal = strtoupper($codigo);
    $motivo = $rejeitada ? 'O provedor rejeitou a criação da assinatura.' : 'Provedor indisponível ou criação não enviada.';
    assinaturaServicoPreapprovalEncerrarOperacao($conexao, $idOperacao, 'erro', $codigoLocal, $motivo);
    assinaturaServicoPreapprovalRegistrarErro($conexao, $vinculo, $codigoLocal, $motivo, $rejeitada);

    return assinaturaServicoNovaContratacaoResultado(false, $codigoLocal, 'Não foi possível criar a assinatura recorrente agora.', $base + ['estado' => $rejeitada ? 'erro' : 'pendente', 'http_status' => $rejeitada ? 422 : 503]);
}

/**
 * Reconcilia uma tentativa incerta do contrato vigente (sem criar nada no provedor).
 * Só atua com operação em 'conciliacao' (ou interrompida); sem pendência, devolve o estado atual.
 */
function assinaturaServicoPreapprovalConciliar(mysqli $conexao, object $gateway, int $idEmpresa, ?string $emailPagador = null): array
{
    if ($idEmpresa <= 0) {
        return assinaturaServicoNovaContratacaoResultado(false, 'DADOS_INVALIDOS', 'Dados inválidos.', ['http_status' => 422]);
    }
    if (!$gateway::subscriptionsHabilitado()) {
        return assinaturaServicoNovaContratacaoResultado(false, 'SUBSCRIPTIONS_DESABILITADO', 'Pagamento recorrente indisponível.', ['http_status' => 503]);
    }

    try {
        $reserva = assinaturaServicoPreapprovalTransacao(
            $conexao,
            static fn (): array => assinaturaServicoPreapprovalReservar($conexao, $gateway->ambiente(), $idEmpresa, null, null, false)
        );
    } catch (AssinaturaServicoConversaoErro $e) {
        return assinaturaServicoPreapprovalFalha($e);
    }

    $base = ['id_assinatura_pagamento' => (int)$reserva['vinculo']['id_assinatura_pagamento']];
    return match ($reserva['acao']) {
        // Nada a conciliar (a transação já gravou o eventual encerramento de uma tentativa não enviada).
        'sem_pendencia' => assinaturaServicoNovaContratacaoResultado(false, 'SEM_CONCILIACAO_PENDENTE', 'Não há conciliação pendente.', $base + ['http_status' => 409]),
        'vinculada' => assinaturaServicoPreapprovalAutorizacaoExistente($gateway, $reserva, $emailPagador),
        'em_andamento' => assinaturaServicoNovaContratacaoResultado(false, 'OPERACAO_EM_ANDAMENTO', 'Já existe uma operação de assinatura recorrente em andamento.', $base + ['http_status' => 409]),
        'expirada' => assinaturaServicoPreapprovalResultadoExpirada($reserva),
        default => assinaturaServicoPreapprovalEsclarecer($conexao, $gateway, $reserva, $emailPagador),
    };
}

/**
 * Base da referência externa (sem rotação nem sufixo de ciclo): todas as tentativas de um mesmo vínculo a compartilham.
 */
function assinaturaServicoPreapprovalReferenciaBase(string $referencia): string
{
    $base = strstr($referencia, '.', true);

    return $base === false ? $referencia : $base;
}

/**
 * Próxima referência de uma nova tentativa depois de uma criação incerta encerrada pelo suporte: mesma base (a tentativa
 * antiga continua identificável), rotação ".r2" a ".r9" e o sufixo de ciclo preservado. Null quando as rotações acabaram.
 */
function assinaturaServicoPreapprovalReferenciaRotacionada(string $referencia): ?string
{
    if (preg_match('/^(amg_[0-9a-f]{32})(?:\.r([2-9]))?(\.d2)?$/D', $referencia, $m) !== 1) {
        return null;
    }
    $proxima = ($m[2] ?? '') === '' ? 2 : (int)$m[2] + 1;

    return $proxima > 9 ? null : $m[1] . '.r' . $proxima . ($m[3] ?? '');
}

/**
 * Pré-verificação de uma NOVA criação num vínculo que já teve tentativa (a primeira criação do vínculo não paga a consulta).
 * Uma única varredura por status confere a BASE da referência, então enxerga também as tentativas anteriores (referência
 * antes da rotação):
 * - Preapproval de tentativa anterior ainda capaz de ser autorizada/cobrar: bloqueia (conciliação), nada é criado;
 * - encontrada (referência atual): vincula pelos caminhos normais (sem POST);
 * - nenhuma, pesquisa completa: segue para o POST;
 * - múltiplas com a referência atual: bloqueia, em análise manual;
 * - recusada/indisponível: encerra só esta tentativa (nada foi enviado) para repetir depois.
 *
 * @param array<string, mixed> $reserva retorno de assinaturaServicoPreapprovalReservar() com acao 'criar'
 * @return array<string, mixed>|null null = pode seguir para o POST
 */
function assinaturaServicoPreapprovalConferirAntesDeCriar(mysqli $conexao, object $gateway, array $reserva, ?string $emailPagador): ?array
{
    $vinculo = $reserva['vinculo'];
    $idOperacao = (int)$reserva['operacao']['id_operacao'];
    $anteriores = assinaturaServicoConsultaFinanceira(
        $conexao,
        'SELECT COUNT(*) AS n FROM operacao_assinatura_gateway WHERE id_assinatura_pagamento = ? AND id_empresa = ? AND id_operacao <> ?',
        'iii',
        (int)$vinculo['id_assinatura_pagamento'],
        (int)$vinculo['id_empresa'],
        $idOperacao
    );
    if ((int)($anteriores[0]['n'] ?? 0) === 0) {
        return null;
    }

    $atual = (string)$vinculo['referencia_externa_assinatura'];
    $raiz = assinaturaServicoPreapprovalReferenciaBase($atual);
    $casa = static fn (string $externa): bool => $externa === $raiz || str_starts_with($externa, $raiz . '.');
    $estado = 'ok';
    $completa = true;
    $exatas = [];
    $antigas = [];
    try {
        foreach (ASSINATURA_SERVICO_PREAPPROVAL_STATUS_BUSCA as $status) {
            $v = assinaturaServicoPreapprovalVarrerStatus($gateway, $atual, $status, ASSINATURA_SERVICO_PREAPPROVAL_BUSCA_PAGINAS, $casa);
            if ($v['estado'] !== 'ok') {
                $estado = $v['estado'];
                break;
            }
            $completa = $completa && $v['completa'];
            foreach ($v['ids'] as $id => $externa) {
                if ($externa === $atual) {
                    $exatas[(string)$id] = true;
                } else {
                    $antigas[(string)$id] = true;
                }
            }
        }
    } catch (Throwable $e) {
        $estado = 'indisponivel';
    }
    $local = match (true) {
        $estado !== 'ok' => ['estado' => $estado],
        $antigas !== [] => ['estado' => 'anterior'],
        count($exatas) > 1 => ['estado' => 'multiplas'],
        count($exatas) === 1 => ['estado' => 'encontrada'],
        default => ['estado' => $completa ? 'nao_encontrada' : 'indisponivel'],
    };
    if ($local['estado'] === 'nao_encontrada') {
        return null;
    }
    if ($local['estado'] === 'encontrada') {
        return assinaturaServicoPreapprovalEsclarecer($conexao, $gateway, $reserva, $emailPagador);
    }

    $base = ['id_assinatura_pagamento' => (int)$vinculo['id_assinatura_pagamento'], 'estado' => 'conciliacao'];
    if ($local['estado'] === 'anterior') {
        // Uma tentativa anterior (mesma base, outra referência) apareceu no provedor: criar agora geraria duas recorrências
        // para o mesmo contrato. Fica em conciliação para o Super Admin encerrar a antiga no provedor antes.
        assinaturaServicoPreapprovalMarcarConciliacao($conexao, $vinculo, $idOperacao, 'PREAPPROVAL_ANTERIOR_ENCONTRADA', 'Uma assinatura externa de tentativa anterior deste contrato existe no provedor; nenhuma nova será criada até o encerramento dela.');

        return assinaturaServicoNovaContratacaoResultado(false, 'PREAPPROVAL_ANTERIOR_ENCONTRADA', 'Encontramos uma autorização anterior para este cadastro no Mercado Pago. Por segurança, nenhuma nova será criada; fale com o suporte.', $base);
    }
    if ($local['estado'] === 'multiplas') {
        assinaturaServicoPreapprovalMarcarConciliacao($conexao, $vinculo, $idOperacao, 'MULTIPLAS_ASSINATURAS_EXTERNAS', 'Mais de uma assinatura externa usa a mesma referência; é necessária análise manual.');

        return assinaturaServicoNovaContratacaoResultado(false, 'MULTIPLAS_ASSINATURAS_EXTERNAS', 'Encontramos mais de uma autorização para este cadastro. Por segurança, nenhuma nova será criada; fale com o suporte.', $base);
    }
    $codigo = $local['estado'] === 'recusada' ? 'CONSULTA_PREVIA_RECUSADA' : 'CONSULTA_PREVIA_INDISPONIVEL';
    $motivo = 'A conferência anterior à nova criação não pôde ser concluída; nada foi criado.';
    assinaturaServicoPreapprovalEncerrarOperacao($conexao, $idOperacao, 'erro', $codigo, $motivo);
    assinaturaServicoPreapprovalRegistrarErro($conexao, $vinculo, $codigo, $motivo, false);

    return assinaturaServicoNovaContratacaoResultado(false, $codigo, 'Não foi possível conferir o cadastro anterior no Mercado Pago agora. Nada foi criado; tente novamente em instantes.', $base + ['http_status' => 503, 'estado' => 'pendente']);
}

/** Máximo de páginas por status na coleta de evidência (50 itens por página). */
const ASSINATURA_SERVICO_PREAPPROVAL_EVIDENCIA_PAGINAS = 20;
/**
 * "Marca d'água": a Preapproval mais recente da conta é posterior à tentativa por esta margem. Só indica que o índice de
 * pesquisa já cobre aquele período; NUNCA prova sozinha a inexistência da tentativa (consistência por item não garantida).
 */
const ASSINATURA_SERVICO_PREAPPROVAL_EVIDENCIA_MARGEM_SEGUNDOS = 600;
const ASSINATURA_SERVICO_PREAPPROVAL_ATESTADO_MINIMO = 20;
/** Fontes aceitas para a conferência independente registrada pelo Super Admin. */
const ASSINATURA_SERVICO_PREAPPROVAL_FONTES_EVIDENCIA = ['painel_mercado_pago', 'suporte_mercado_pago'];

/**
 * Evidência externa SOMENTE LEITURA sobre uma criação incerta: enumeração completa da conta por status (independente do
 * filtro de referência, que o provedor ignora), correspondências pela base da referência (qualquer rotação ou sufixo) e a
 * "marca d'água" do índice (a Preapproval mais recente da conta), que é só indício de cobertura.
 *
 * @return array<string, mixed>
 */
function assinaturaServicoPreapprovalEvidenciaExterna(object $gateway, string $referencia, DateTimeImmutable $tentativaEm): array
{
    $base = assinaturaServicoPreapprovalReferenciaBase($referencia);
    $casa = static fn (string $externa): bool => $externa === $base || str_starts_with($externa, $base . '.');
    $ev = ['estado' => 'ok', 'completa' => true, 'ids' => [], 'itens' => 0, 'status_honrado' => true, 'mais_recente' => null, 'marca_dagua' => false];
    $maisRecente = null;
    foreach (['pending', 'authorized', 'paused', 'cancelled'] as $status) {
        $informativa = $status === 'cancelled';
        $v = assinaturaServicoPreapprovalVarrerStatus($gateway, null, $status, ASSINATURA_SERVICO_PREAPPROVAL_EVIDENCIA_PAGINAS, $casa);
        if ($v['estado'] !== 'ok') {
            if ($informativa) {
                continue;
            }

            return ['estado' => $v['estado'], 'completa' => false] + $ev;
        }
        if (!$informativa) {
            $ev['completa'] = $ev['completa'] && $v['completa'];
            $ev['status_honrado'] = $ev['status_honrado'] && $v['status_honrado'];
        }
        $ev['itens'] += $v['itens'];
        foreach (array_keys($v['ids']) as $id) {
            $ev['ids'][(string)$id] = true;
        }
        if ($v['mais_recente'] !== null && ($maisRecente === null || $v['mais_recente'] > $maisRecente)) {
            $maisRecente = $v['mais_recente'];
        }
    }
    $ev['ids'] = array_map('strval', array_keys($ev['ids']));
    $ev['mais_recente'] = $maisRecente?->format('Y-m-d H:i:s P');
    $ev['marca_dagua'] = $maisRecente !== null
        && $maisRecente->getTimestamp() >= $tentativaEm->getTimestamp() + ASSINATURA_SERVICO_PREAPPROVAL_EVIDENCIA_MARGEM_SEGUNDOS;

    return $ev;
}

/**
 * Conferência independente registrada pelo Super Admin: fonte conhecida, data e hora da conferência (depois da tentativa e
 * não no futuro) e a identificação da evidência consultada (filtros usados, protocolo do suporte; mínimo de
 * ASSINATURA_SERVICO_PREAPPROVAL_ATESTADO_MINIMO caracteres). Retorna o motivo da recusa ou null quando completa.
 *
 * @param array{fonte?: mixed, conferido_em?: mixed, descricao?: mixed}|null $atestado
 */
function assinaturaServicoPreapprovalAtestadoMotivo(?array $atestado, DateTimeImmutable $tentativaEm, ?DateTimeImmutable $agora = null): ?string
{
    if ($atestado === null) {
        return 'ATESTADO_AUSENTE';
    }
    if (!in_array($atestado['fonte'] ?? null, ASSINATURA_SERVICO_PREAPPROVAL_FONTES_EVIDENCIA, true)) {
        return 'ATESTADO_FONTE_INVALIDA';
    }
    $conferido = is_string($atestado['conferido_em'] ?? null)
        ? DateTimeImmutable::createFromFormat('!Y-m-d H:i', $atestado['conferido_em'], new DateTimeZone(ASSINATURA_SERVICO_PREAPPROVAL_FUSO))
        : false;
    $agora ??= new DateTimeImmutable('now', new DateTimeZone(ASSINATURA_SERVICO_PREAPPROVAL_FUSO));
    if ($conferido === false || $conferido->format('Y-m-d H:i') !== $atestado['conferido_em']
        || $conferido->getTimestamp() < $tentativaEm->getTimestamp() - 60 || $conferido->getTimestamp() > $agora->getTimestamp() + 300) {
        return 'ATESTADO_DATA_INVALIDA';
    }
    $descricao = is_string($atestado['descricao'] ?? null) ? trim($atestado['descricao']) : '';
    if (mb_strlen($descricao, 'UTF-8') < ASSINATURA_SERVICO_PREAPPROVAL_ATESTADO_MINIMO || mb_strlen($descricao, 'UTF-8') > 300) {
        return 'ATESTADO_DESCRICAO_INVALIDA';
    }

    return null;
}

/**
 * Decisão pura sobre a evidência. Resultado vazio de pesquisa, tempo decorrido, a marca d'água ou um texto livre NUNCA
 * bastam sozinhos:
 * - Preapproval correspondente existente (qualquer status, inclusive cancelada): nunca libera (a tentativa existiu);
 * - enumeração incompleta, filtro de status não honrado, consulta recusada ou indisponível: nunca libera;
 * - enumeração completa sem correspondência: libera somente com a conferência independente completa
 *   (assinaturaServicoPreapprovalAtestadoMotivo), que fica na auditoria junto com a evidência automática.
 *
 * @param array<string, mixed> $ev
 * @param array<string, mixed>|null $atestado
 * @return array{liberar: bool, codigo: string, atestado_necessario: bool, atestado_motivo: ?string}
 */
function assinaturaServicoPreapprovalAvaliarEvidencia(array $ev, ?array $atestado, DateTimeImmutable $tentativaEm, ?DateTimeImmutable $agora = null): array
{
    $r = static fn (bool $liberar, string $codigo, bool $atestadoNecessario = false, ?string $motivo = null): array
        => ['liberar' => $liberar, 'codigo' => $codigo, 'atestado_necessario' => $atestadoNecessario, 'atestado_motivo' => $motivo];
    $estado = (string)($ev['estado'] ?? '');
    if ($estado === 'recusada') {
        return $r(false, 'CONSULTA_RECUSADA');
    }
    if ($estado !== 'ok') {
        return $r(false, 'EVIDENCIA_INDISPONIVEL');
    }
    if (($ev['ids'] ?? []) !== []) {
        return $r(false, 'PREAPPROVAL_EXTERNA_ENCONTRADA');
    }
    if (($ev['completa'] ?? false) !== true || ($ev['status_honrado'] ?? false) !== true) {
        return $r(false, 'EVIDENCIA_INCOMPLETA');
    }
    $motivo = assinaturaServicoPreapprovalAtestadoMotivo($atestado, $tentativaEm, $agora);
    if ($motivo !== null) {
        return $r(false, 'ATESTADO_NECESSARIO', true, $motivo);
    }

    return $r(true, 'EVIDENCIA_COM_ATESTADO');
}

/**
 * Resumo da evidência com os quatro estados separados: consulta completa realizada, referência localizada, existência
 * externa (nunca "inexistente": sem correspondência ela é só "não confirmada") e evidência suficiente para a resolução.
 *
 * @param array<string, mixed> $ev
 * @param array{liberar: bool, codigo: string} $avaliacao
 * @return array<string, mixed>
 */
function assinaturaServicoPreapprovalResumoEvidencia(array $ev, array $avaliacao, string $tentativaEm): array
{
    $localizada = ($ev['ids'] ?? []) !== [];

    return [
        'consulta_completa' => ($ev['estado'] ?? '') === 'ok' && ($ev['completa'] ?? false) === true && ($ev['status_honrado'] ?? false) === true,
        'referencia_localizada' => $localizada,
        'existencia_externa' => $localizada ? 'confirmada' : 'nao_confirmada',
        'evidencia_suficiente' => $avaliacao['liberar'] === true,
        'itens_varridos' => (int)($ev['itens'] ?? 0),
        'preapproval_mais_recente' => $ev['mais_recente'] ?? null,
        'cobertura_indice' => ($ev['marca_dagua'] ?? false) === true,
        'marca_dagua' => ($ev['marca_dagua'] ?? false) === true,
        'criacao_enviada_em' => $tentativaEm,
        'resultado' => $avaliacao['codigo'],
    ];
}

/**
 * Resolução ADMINISTRATIVA controlada de uma criação enviada com resultado incerto (Super Admin; trava por empresa e
 * auditoria a cargo do chamador/serviço). Não é um reset: a operação histórica é preservada (status 'cancelada', motivo
 * com a referência usada), nada é criado nem cancelado no provedor e o contrato não muda. Só libera com a enumeração
 * externa completa sem correspondência E a conferência independente completa do Super Admin (fonte, data/hora e
 * identificação da evidência). A nova tentativa recebe uma referência rotacionada (mesma base): a antiga continua
 * identificável e uma Preapproval tardia dela é detectada antes de criar e no webhook, sem ser confundida com a nova.
 * Duas fases: sem `$confirmado` apenas devolve a evidência (nada é gravado).
 *
 * @param array<string, mixed>|null $ator
 * @param array{fonte?: mixed, conferido_em?: mixed, descricao?: mixed}|null $atestado
 * @return array<string, mixed>
 */
function assinaturaServicoPreapprovalResolverCriacaoIncerta(mysqli $conexao, object $gateway, int $idEmpresa, int $idAssinatura, ?array $ator, bool $confirmado, ?array $atestado): array
{
    $falha = static fn (string $codigo, string $mensagem, int $http = 409, array $extra = []): array => assinaturaServicoNovaContratacaoResultado(false, $codigo, $mensagem, ['http_status' => $http] + $extra);
    if ($idEmpresa <= 0 || $idAssinatura <= 0 || $ator === null) {
        return $falha('DADOS_INVALIDOS', 'Dados inválidos para resolver o cadastro do cartão.', 422);
    }
    if (!$gateway::subscriptionsHabilitado()) {
        return $falha('SUBSCRIPTIONS_DESABILITADO', 'Pagamento recorrente indisponível.', 503);
    }
    $vinculo = assinaturaServicoPreapprovalVinculoDoContrato($conexao, $idEmpresa, $idAssinatura);
    if ($vinculo === null || (string)$vinculo['motor_recorrencia'] !== 'preapproval' || $vinculo['assinatura_externa_id'] !== null
        || !in_array((string)$vinculo['status'], ['pendente', 'erro'], true) || (string)$vinculo['ambiente'] !== $gateway->ambiente()) {
        return $falha('SEM_CRIACAO_INCERTA', 'Não há criação incerta de cartão para esta assinatura.');
    }
    $operacao = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT id_operacao, status_processamento, erro_codigo, tentativas_processamento,
                DATE_FORMAT(criado_em, '%Y-%m-%d %H:%i:%s') AS criado_em, DATE_FORMAT(atualizado_em, '%Y-%m-%d %H:%i:%s.%f') AS atualizado_em
           FROM operacao_assinatura_gateway
          WHERE id_assinatura_pagamento = ? AND id_empresa = ? AND tipo_operacao = 'conciliar'
            AND status_processamento IN ('pendente', 'processando', 'conciliacao') LIMIT 1",
        'ii',
        (int)$vinculo['id_assinatura_pagamento'],
        $idEmpresa
    )[0] ?? null;
    if ($operacao === null || (string)$operacao['status_processamento'] !== 'conciliacao') {
        return $falha($operacao === null ? 'SEM_CRIACAO_INCERTA' : 'OPERACAO_EM_ANDAMENTO', $operacao === null
            ? 'Não há criação incerta de cartão para esta assinatura.' : 'A operação ainda está em andamento. Aguarde a conclusão.');
    }

    $tentativaEm = new DateTimeImmutable((string)$operacao['criado_em'], new DateTimeZone(date_default_timezone_get()));
    try {
        $evidencia = assinaturaServicoPreapprovalEvidenciaExterna($gateway, (string)$vinculo['referencia_externa_assinatura'], $tentativaEm);
    } catch (Throwable $e) {
        $evidencia = ['estado' => 'indisponivel', 'completa' => false, 'ids' => []];
    }
    $avaliacao = assinaturaServicoPreapprovalAvaliarEvidencia($evidencia, $atestado, $tentativaEm);
    $resumo = assinaturaServicoPreapprovalResumoEvidencia($evidencia, $avaliacao, (string)$operacao['criado_em']);
    $referenciaAnterior = (string)$vinculo['referencia_externa_assinatura'];
    $referenciaNova = assinaturaServicoPreapprovalReferenciaRotacionada($referenciaAnterior);
    $mensagens = [
        'PREAPPROVAL_EXTERNA_ENCONTRADA' => ['Existe uma assinatura externa correspondente a esta tentativa (ou a uma anterior do mesmo contrato). Ela deve ser recuperada pela conciliação ("Atualizar situação") ou encerrada no Mercado Pago; nunca descartada.', 409],
        'EVIDENCIA_INCOMPLETA' => ['A consulta ao Mercado Pago não percorreu todas as assinaturas. Tente novamente mais tarde; nada foi alterado.', 409],
        'EVIDENCIA_INDISPONIVEL' => ['Não foi possível consultar o Mercado Pago agora. Tente novamente em instantes; nada foi alterado.', 503],
        'CONSULTA_RECUSADA' => ['A consulta ao Mercado Pago foi recusada. Nada foi alterado; é necessária análise técnica.', 409],
        'ATESTADO_NECESSARIO' => ['A varredura completa não encontrou a assinatura, mas isso não prova que ela não exista. Confira no painel (ou com o suporte) do Mercado Pago e registre a fonte, a data e hora da conferência e a evidência consultada (mínimo de ' . ASSINATURA_SERVICO_PREAPPROVAL_ATESTADO_MINIMO . ' caracteres) para continuar.', 409],
    ];
    if (!$avaliacao['liberar']) {
        [$mensagem, $http] = $mensagens[$avaliacao['codigo']] ?? ['Não foi possível liberar uma nova tentativa.', 409];

        return $falha($avaliacao['codigo'], $mensagem, $http, ['evidencia' => $resumo, 'atestado_necessario' => $avaliacao['atestado_necessario'],
            'atestado_motivo' => $avaliacao['atestado_motivo']]);
    }
    if ($referenciaNova === null) {
        return $falha('REFERENCIA_ESGOTADA', 'Este contrato já teve tentativas demais de cadastro do cartão. Nada foi alterado; é necessária análise técnica.', 409, ['evidencia' => $resumo]);
    }
    if (!$confirmado) {
        return $falha('CONFIRMACAO_OBRIGATORIA', 'A evidência externa e a conferência registrada permitem encerrar a tentativa anterior. Confirme para liberar um novo cadastro de cartão com nova referência. O contrato e o teste grátis não serão alterados.', 409,
            ['evidencia' => $resumo, 'atestado_necessario' => false]);
    }

    $conferencia = [
        'fonte' => (string)$atestado['fonte'],
        'conferido_em' => (string)$atestado['conferido_em'],
        'descricao' => trim((string)$atestado['descricao']),
    ];
    $resultado = assinaturaServicoPreapprovalTransacao(
        $conexao,
        static function () use ($conexao, $vinculo, $operacao, $idEmpresa, $idAssinatura, $ator, $avaliacao, $resumo, $conferencia, $referenciaAnterior, $referenciaNova, $falha): array {
            $atual = assinaturaServicoConsultaFinanceira(
                $conexao,
                "SELECT v.status, v.assinatura_externa_id, v.referencia_externa_assinatura, o.status_processamento, o.tentativas_processamento,
                        DATE_FORMAT(o.atualizado_em, '%Y-%m-%d %H:%i:%s.%f') AS atualizado_em
                   FROM assinatura_pagamento_recorrente v
                   JOIN operacao_assinatura_gateway o ON o.id_assinatura_pagamento = v.id_assinatura_pagamento
                  WHERE v.id_assinatura_pagamento = ? AND v.id_empresa = ? AND o.id_operacao = ?
                  FOR UPDATE",
                'iii',
                (int)$vinculo['id_assinatura_pagamento'],
                $idEmpresa,
                (int)$operacao['id_operacao']
            )[0] ?? null;
            // Qualquer mudança desde a leitura (conciliação do cron, vínculo criado, nova tentativa) cancela a resolução.
            if ($atual === null || $atual['assinatura_externa_id'] !== null || (string)$atual['status_processamento'] !== 'conciliacao'
                || (int)$atual['tentativas_processamento'] !== (int)$operacao['tentativas_processamento']
                || (string)$atual['atualizado_em'] !== (string)$operacao['atualizado_em']
                || (string)$atual['referencia_externa_assinatura'] !== $referenciaAnterior) {
                return $falha('ESTADO_ALTERADO', 'O estado do cadastro mudou durante a verificação. Nada foi alterado; consulte novamente.');
            }
            // A referência usada pela tentativa encerrada fica gravada na própria operação histórica.
            $motivo = 'Encerrada pelo suporte (' . $avaliacao['codigo'] . ', ' . $conferencia['fonte'] . ' em ' . $conferencia['conferido_em']
                . '): enumeração externa completa sem a assinatura. Referência da tentativa: ' . $referenciaAnterior . '.';
            $encerradas = assinaturaServicoPreapprovalExec(
                $conexao,
                "UPDATE operacao_assinatura_gateway
                    SET status_processamento = 'cancelada', erro_codigo = 'ENCERRADA_SEM_PREAPPROVAL_EXTERNA', erro_motivo = ?, proxima_tentativa_em = NULL
                  WHERE id_operacao = ? AND id_empresa = ? AND status_processamento = 'conciliacao'",
                'sii',
                $motivo,
                (int)$operacao['id_operacao'],
                $idEmpresa
            );
            if ($encerradas !== 1) {
                throw new RuntimeException('A operação não pôde ser encerrada.');
            }
            // O vínculo continua o mesmo (pendente, sem id externo): só a referência da PRÓXIMA tentativa muda (mesma base,
            // rotação seguinte) e o sinal do último erro registra a resolução. A unicidade da referência é do banco.
            $rotacionado = assinaturaServicoPreapprovalExec(
                $conexao,
                "UPDATE assinatura_pagamento_recorrente
                    SET referencia_externa_assinatura = ?, ultimo_erro_codigo = 'TENTATIVA_ENCERRADA_PELO_SUPORTE',
                        ultimo_erro_motivo = 'A tentativa anterior foi encerrada pelo suporte com evidência externa; um novo cadastro está liberado com nova referência.'
                  WHERE id_assinatura_pagamento = ? AND id_empresa = ? AND assinatura_externa_id IS NULL AND referencia_externa_assinatura = ?",
                'siis',
                $referenciaNova,
                (int)$vinculo['id_assinatura_pagamento'],
                $idEmpresa,
                $referenciaAnterior
            );
            if ($rotacionado !== 1) {
                throw new RuntimeException('A referência da nova tentativa não pôde ser registrada.');
            }
            auditoriaRegistrar($conexao, 'assinatura.criacao_cartao_encerrada', [
                'ator' => $ator,
                'entidade_id' => $idAssinatura,
                'entidade_rotulo' => "Assinatura #{$idAssinatura}",
                'descricao' => "Encerrou a tentativa incerta de cadastro de cartão da assinatura #{$idAssinatura} (operação #" . (int)$operacao['id_operacao'] . ') com evidência externa.',
                'alteracoes' => [
                    'id_empresa' => ['antes' => null, 'depois' => $idEmpresa],
                    'id_assinatura' => ['antes' => null, 'depois' => $idAssinatura],
                    'id_operacao' => ['antes' => null, 'depois' => (int)$operacao['id_operacao']],
                    'status' => ['antes' => 'conciliacao', 'depois' => 'cancelada'],
                    'erro_codigo' => ['antes' => (string)$operacao['erro_codigo'], 'depois' => 'ENCERRADA_SEM_PREAPPROVAL_EXTERNA'],
                    'itens_varridos' => ['antes' => null, 'depois' => $resumo['itens_varridos']],
                    'marca_dagua' => ['antes' => null, 'depois' => $resumo['marca_dagua'] ? 'sim' : 'nao'],
                    'referencia_externa_assinatura' => ['antes' => $referenciaAnterior, 'depois' => $referenciaNova],
                    'fonte_evidencia' => ['antes' => null, 'depois' => $conferencia['fonte']],
                    'conferido_em' => ['antes' => null, 'depois' => $conferencia['conferido_em']],
                    'evidencia' => ['antes' => null, 'depois' => $resumo],
                ],
                'contexto' => ['origem' => 'painel_super_admin', 'id_empresa' => $idEmpresa, 'id_assinatura' => $idAssinatura, 'motivo' => $conferencia['descricao']],
            ]);

            return assinaturaServicoNovaContratacaoResultado(true, 'CRIACAO_INCERTA_ENCERRADA',
                'A tentativa anterior foi encerrada com segurança. O cliente já pode cadastrar o cartão novamente (nova referência). O contrato e o teste grátis não foram alterados.',
                ['id_operacao' => (int)$operacao['id_operacao'], 'evidencia' => $resumo, 'referencia_nova' => $referenciaNova]);
        }
    );

    return $resultado;
}
/*
 * ---------------------------------------------------------------------
 * Exclusividade PIX x Preapproval por competência.
 *
 * Uma cobrança regular é "governada" pela Preapproval quando há cobrança externa em andamento
 * para ela (transação 'assinatura_preapproval' ainda não terminal) ou quando o contrato tem
 * Preapproval ativa (slot ocupado) e a competência começa na data da primeira cobrança externa
 * ou depois. Nesse caso nenhum PIX pode ser iniciado. O fallback PIX só existe se a constante
 * abaixo for habilitada de forma explícita e, mesmo assim, apenas sem cobrança externa em
 * andamento e com a Preapproval sincronizada há pouco tempo.
 * ---------------------------------------------------------------------
 */
const ASSINATURA_SERVICO_PREAPPROVAL_FALLBACK_PIX_AUTORIZADO = false;
const ASSINATURA_SERVICO_PREAPPROVAL_SINCRONIA_MAX_SEGUNDOS = 600;

/**
 * Deve rodar na transação do chamador, depois de bloquear a cobrança.
 *
 * @return array{codigo: string, mensagem: string}|null null quando o PIX não conflita com a Preapproval
 */
function assinaturaServicoPreapprovalBloqueioPix(mysqli $conexao, int $idEmpresa, int $idCobranca, bool $fallbackAutorizado = ASSINATURA_SERVICO_PREAPPROVAL_FALLBACK_PIX_AUTORIZADO): ?array
{
    $cobranca = assinaturaServicoConsultaFinanceira(
        $conexao,
        'SELECT id_assinatura, finalidade, status, periodo_inicio, motor_cobranca
           FROM cobranca WHERE id_cobranca = ? AND id_empresa = ? LIMIT 1',
        'ii',
        $idCobranca,
        $idEmpresa
    )[0] ?? null;
    if ($cobranca === null || !in_array((string)$cobranca['finalidade'], ['regular', 'conversao_trial'], true)) {
        return null;
    }

    $emAndamento = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT 1 FROM transacao_pagamento
          WHERE id_empresa = ? AND id_cobranca = ? AND tipo_operacao = 'assinatura_preapproval'
            AND (status IN ('criada', 'enviando', 'aguardando', 'processando', 'conciliacao')
                 OR (requer_conciliacao = 1 AND status <> 'confirmada'))
          LIMIT 1",
        'ii',
        $idEmpresa,
        $idCobranca
    ) !== [];
    if ($emAndamento) {
        return [
            'codigo' => 'COBRANCA_PREAPPROVAL_EM_ANDAMENTO',
            'mensagem' => 'Esta cobrança está sendo processada pelo pagamento automático no cartão (inclusive novas tentativas após recusa). '
                . 'Para pagar com PIX, cancele o pagamento automático e aguarde a confirmação do Mercado Pago, ou aguarde a próxima tentativa no cartão.',
        ];
    }

    if ((string)$cobranca['status'] !== 'pendente') {
        return null;
    }
    if ((string)$cobranca['finalidade'] === 'conversao_trial') {
        // Primeira mensalidade após o teste grátis com cartão autorizado: o PIX só volta depois do cancelamento confirmado.
        if (assinaturaServicoPreapprovalPrimeiraCobrancaViva($conexao, $idEmpresa, (int)$cobranca['id_assinatura'])) {
            return [
                'codigo' => 'COBRANCA_PRIMEIRA_PREAPPROVAL',
                'mensagem' => 'A primeira mensalidade está sendo cobrada no cartão autorizado. Para pagar com PIX, use antes a opção "Pagar com PIX em vez do cartão".',
            ];
        }
        // Competência aberta pelo cartão e não paga: o PIX usa a cobrança de regularização (período a partir do pagamento).
        $doCartao = assinaturaServicoConsultaFinanceira(
            $conexao,
            "SELECT 1 FROM transacao_pagamento WHERE id_empresa = ? AND id_cobranca = ? AND tipo_operacao = 'assinatura_preapproval' LIMIT 1",
            'ii',
            $idEmpresa,
            $idCobranca
        ) !== [];

        return $doCartao ? [
            'codigo' => 'COBRANCA_CARTAO_ENCERRADA',
            'mensagem' => 'Esta cobrança era do cartão já cancelado. Use "Gerar cobrança de regularização" no Faturamento para pagar com PIX.',
        ] : null;
    }
    // Primeira cobrança da contratação: existindo Preapproval que pode cobrar (mesmo ainda não autorizada, pois o
    // pagador pode concluir o checkout a qualquer momento), o PIX só volta depois do cancelamento confirmado.
    $contratacaoPendente = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT 1 FROM assinatura WHERE id_assinatura = ? AND id_empresa = ? AND status = 'suspensa' AND motivo_suspensao = 'contratacao_pendente' LIMIT 1",
        'ii',
        (int)$cobranca['id_assinatura'],
        $idEmpresa
    ) !== [];
    if ($contratacaoPendente && assinaturaServicoPreapprovalPrimeiraCobrancaViva($conexao, $idEmpresa, (int)$cobranca['id_assinatura'])) {
        return [
            'codigo' => 'COBRANCA_PRIMEIRA_PREAPPROVAL',
            'mensagem' => 'Esta contratação está sendo paga pelo cartão no Mercado Pago. Para pagar com PIX, use antes a opção "Pagar com PIX em vez do cartão".',
        ];
    }
    $vinculo = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT cobrar_a_partir_de,
                TIMESTAMPDIFF(SECOND, ultima_sincronizacao_em, NOW(6)) AS idade_sincronia
           FROM assinatura_pagamento_recorrente
          WHERE id_empresa = ? AND id_assinatura = ? AND motor_recorrencia = 'preapproval'
            AND assinatura_externa_id IS NOT NULL
            AND ((slot_ativo = 1 AND status IN ('ativa', 'atualizacao_necessaria', 'cancelamento_pendente'))
                 -- Autorizada no provedor mas sem ativação local (ex.: data de cobrança em análise): o provedor pode cobrar.
                 OR (status = 'pendente' AND status_externo = 'authorized'))
          LIMIT 1",
        'ii',
        $idEmpresa,
        (int)$cobranca['id_assinatura']
    )[0] ?? null;
    if ($vinculo === null) {
        return null;
    }

    $inicioGoverno = $vinculo['cobrar_a_partir_de'] === null
        ? null
        : (new DateTimeImmutable((string)$vinculo['cobrar_a_partir_de']))->modify('-1 day')->format('Y-m-d');
    $governada = (string)$cobranca['motor_cobranca'] === 'preapproval'
        || $inicioGoverno === null
        || (string)$cobranca['periodo_inicio'] >= $inicioGoverno;
    if (!$governada) {
        return null;
    }

    $sincroniaRecente = $vinculo['idade_sincronia'] !== null
        && (int)$vinculo['idade_sincronia'] <= ASSINATURA_SERVICO_PREAPPROVAL_SINCRONIA_MAX_SEGUNDOS;
    if ($fallbackAutorizado && $sincroniaRecente) {
        return null;
    }

    return [
        'codigo' => 'COBRANCA_GOVERNADA_PREAPPROVAL',
        'mensagem' => 'Esta cobrança será paga pelo pagamento automático no cartão e não aceita PIX. '
            . 'Para pagar com PIX, cancele o pagamento automático e aguarde a confirmação do Mercado Pago.',
    ];
}

/*
 * ---------------------------------------------------------------------
 * Situação do pagamento automático (Preapproval) de cada cobrança, para Proprietário e Super Admin.
 *
 * Fonte: a transação local 'assinatura_preapproval' da cobrança, que só muda por fatura e pagamento autoritativos
 * (webhook ou conciliação, mpSubAplicarFatura). O status da Preapproval ('authorized') e a próxima data de cobrança
 * (next_payment_date) NUNCA indicam pagamento: só o pagamento aprovado e creditado confirma a cobrança.
 * Leitura apenas: nada aqui grava, quita ou libera.
 * ---------------------------------------------------------------------
 */

/** Mensagens amigáveis para o status_detail do Mercado Pago; código fora da lista não ganha motivo inventado. */
const ASSINATURA_SERVICO_PREAPPROVAL_MOTIVOS_RECUSA = [
    'cc_rejected_insufficient_amount' => 'Pagamento recusado pelo banco. O cartão pode estar sem saldo ou limite disponível suficiente.',
    'cc_rejected_bad_filled_card_number' => 'Pagamento recusado: o número do cartão cadastrado não confere.',
    'cc_rejected_bad_filled_date' => 'Pagamento recusado: a data de validade do cartão não confere.',
    'cc_rejected_bad_filled_security_code' => 'Pagamento recusado: o código de segurança do cartão não confere.',
    'cc_rejected_bad_filled_other' => 'Pagamento recusado: há dados do cartão que precisam ser revisados.',
    'cc_rejected_call_for_authorize' => 'Pagamento recusado: o banco emissor exige que você autorize o pagamento junto a ele.',
    'cc_rejected_card_disabled' => 'Pagamento recusado: o cartão está desativado. Entre em contato com o banco emissor.',
    'cc_rejected_card_error' => 'Pagamento recusado: o cartão não pôde ser processado.',
    'cc_rejected_duplicated_payment' => 'Pagamento recusado por ter sido identificado como duplicado.',
    'cc_rejected_high_risk' => 'Pagamento recusado pela análise de segurança do Mercado Pago.',
    'cc_rejected_max_attempts' => 'Pagamento recusado: o limite de tentativas permitidas para o cartão foi atingido.',
    'cc_rejected_other_reason' => 'Pagamento recusado pelo banco emissor do cartão.',
];

/** Detalhes técnicos que não são motivo de recusa (marcadores locais e estados de sucesso). */
const ASSINATURA_SERVICO_PREAPPROVAL_DETALHES_NAO_MOTIVO = ['retry', 'aguardando_gateway', 'accredited', 'refunded'];

const ASSINATURA_SERVICO_PREAPPROVAL_SITUACOES_PAGAMENTO = [
    'aguardando_processamento' => ['Aguardando processamento', 'A cobrança será feita automaticamente no cartão cadastrado, pelo Mercado Pago.'],
    'processando' => ['Pagamento em processamento', 'O Mercado Pago está processando o pagamento no cartão.'],
    'conciliacao' => ['Pagamento em conferência', 'O pagamento no cartão está em conferência. Aguarde a confirmação antes de pagar por outro meio.'],
    'recusado_retentativa' => ['Pagamento recusado — nova tentativa programada', 'O Mercado Pago tentará cobrar o cartão novamente de forma automática. A cobrança continua em aberto.'],
    'recusado' => ['Pagamento recusado', 'O Mercado Pago encerrou as tentativas de cobrança no cartão sem aprovação. A cobrança continua em aberto.'],
    'encerrada_sem_pagamento' => ['Cobrança no cartão encerrada', 'O Mercado Pago encerrou esta cobrança no cartão sem pagamento. A cobrança continua em aberto.'],
    'aprovado' => ['Pagamento aprovado', 'Pagamento no cartão aprovado e confirmado.'],
    'recorrencia_cancelada' => ['Pagamento automático cancelado', 'O pagamento automático no cartão foi cancelado no Mercado Pago.'],
];

/** Mensagem amigável do status_detail; null quando o provedor não informou motivo reconhecível. */
function assinaturaServicoPreapprovalMotivoRecusa(?string $detalhe): ?string
{
    return $detalhe === null ? null : (ASSINATURA_SERVICO_PREAPPROVAL_MOTIVOS_RECUSA[$detalhe] ?? null);
}

/**
 * Situação exibível do pagamento automático de uma cobrança (função pura).
 *
 * @param array<string, mixed>|null $transacao última transação 'assinatura_preapproval' da cobrança
 * @param array<string, mixed>|null $vinculo vínculo vigente (ou o mais recente) do contrato: status, status_externo
 * @param array{codigo: string, mensagem: string}|null $bloqueioPix resultado de assinaturaServicoPreapprovalBloqueioPix
 * @return array<string, mixed>|null null quando a cobrança não envolve pagamento automático
 */
function assinaturaServicoPreapprovalSituacaoPagamento(?array $transacao, ?array $vinculo, ?array $bloqueioPix): ?array
{
    $statusVinculo = $vinculo === null ? null : (string)$vinculo['status'];
    $vinculoCancelado = in_array($statusVinculo, ['cancelada', 'encerrada'], true);
    $detalhe = $transacao === null || $transacao['detalhe_status_externo'] === null ? null : (string)$transacao['detalhe_status_externo'];
    $codigoRecusa = $detalhe !== null && !in_array($detalhe, ASSINATURA_SERVICO_PREAPPROVAL_DETALHES_NAO_MOTIVO, true) ? $detalhe : null;

    if ($transacao === null) {
        if ($bloqueioPix === null) {
            return null;
        }
        $situacao = 'aguardando_processamento';
    } else {
        $status = (string)$transacao['status'];
        $situacao = match (true) {
            (int)($transacao['requer_conciliacao'] ?? 0) === 1 || $status === 'conciliacao' => 'conciliacao',
            $status === 'confirmada' => 'aprovado',
            // Recusa com nova tentativa do provedor: a transação segue em processamento e a recusa fica em erro_codigo
            // (fatura 'recycling' ou reagendada como 'scheduled'; não depende do nome do status externo).
            $status === 'processando' && (($transacao['erro_codigo'] ?? null) !== null || $detalhe === 'retry') => 'recusado_retentativa',
            $status === 'processando' => 'processando',
            in_array($status, ['criada', 'enviando', 'aguardando'], true) => 'aguardando_processamento',
            $status === 'recusada' => $vinculoCancelado ? 'recorrencia_cancelada' : 'recusado',
            $status === 'cancelada' => $vinculoCancelado ? 'recorrencia_cancelada' : ($codigoRecusa !== null ? 'recusado' : 'encerrada_sem_pagamento'),
            default => 'conciliacao',
        };
    }
    [$titulo, $mensagem] = ASSINATURA_SERVICO_PREAPPROVAL_SITUACOES_PAGAMENTO[$situacao];
    $recusa = in_array($situacao, ['recusado', 'recusado_retentativa', 'recorrencia_cancelada'], true) ? $codigoRecusa : null;
    $tentativa = $transacao === null ? 0 : (int)($transacao['numero_tentativa'] ?? 0);

    return [
        'situacao' => $situacao,
        'titulo' => $titulo,
        'mensagem' => $mensagem,
        // Código técnico preservado para diagnóstico; a mensagem só existe para códigos conhecidos.
        'motivo_codigo' => $recusa,
        'motivo' => assinaturaServicoPreapprovalMotivoRecusa($recusa),
        'tentativa' => $tentativa > 0 ? $tentativa : null,
        // Só a data informada pelo provedor (next_retry_date da fatura); sem ela, nada é estimado.
        'proxima_tentativa_em' => $situacao === 'recusado_retentativa' && ($transacao['proxima_tentativa_em'] ?? null) !== null
            ? (string)$transacao['proxima_tentativa_em'] : null,
        'atualizado_em' => $transacao === null || ($transacao['ultima_consulta_em'] ?? null) === null ? null : (string)$transacao['ultima_consulta_em'],
        'cancelamento_pendente' => $statusVinculo === 'cancelamento_pendente',
        'pix_bloqueado' => $bloqueioPix !== null,
        'pix_codigo' => $bloqueioPix['codigo'] ?? null,
        'pix_mensagem' => $bloqueioPix['mensagem'] ?? null,
    ];
}

/**
 * Situação do pagamento automático de várias cobranças (listagens). Leitura apenas, sem travas.
 *
 * @param list<array{id_cobranca: int, id_empresa: int, id_assinatura: int, status: string}> $cobrancas
 * @return array<int, array<string, mixed>|null> por id_cobranca
 */
function assinaturaServicoPreapprovalSituacoesCobrancas(mysqli $conexao, array $cobrancas): array
{
    if ($cobrancas === []) {
        return [];
    }
    $ids = array_map(static fn (array $c): int => (int)$c['id_cobranca'], $cobrancas);
    $contratos = array_values(array_unique(array_map(static fn (array $c): int => (int)$c['id_assinatura'], $cobrancas)));
    $marcas = static fn (array $lista): string => implode(',', array_fill(0, count($lista), '?'));

    // A data da próxima tentativa depende da coluna opcional criada por SQL manual; sem ela, a listagem segue igual.
    $proxima = assinaturaServicoColunaExiste($conexao, 'transacao_pagamento', 'proxima_tentativa_em')
        ? "DATE_FORMAT(proxima_tentativa_em, '%Y-%m-%d %H:%i:%s')" : 'NULL';
    $transacoes = [];
    foreach (assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT id_empresa, id_cobranca, status, status_externo, detalhe_status_externo, erro_codigo, requer_conciliacao, numero_tentativa,
                DATE_FORMAT(ultima_consulta_em, '%Y-%m-%d %H:%i:%s') AS ultima_consulta_em, {$proxima} AS proxima_tentativa_em
           FROM transacao_pagamento
          WHERE tipo_operacao = 'assinatura_preapproval' AND id_cobranca IN (" . $marcas($ids) . ")
          ORDER BY id_transacao DESC",
        str_repeat('i', count($ids)),
        ...$ids
    ) as $t) {
        $transacoes[(int)$t['id_cobranca']] ??= $t;
    }
    $vinculos = [];
    foreach (assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT id_empresa, id_assinatura, status, status_externo
           FROM assinatura_pagamento_recorrente
          WHERE motor_recorrencia = 'preapproval' AND id_assinatura IN (" . $marcas($contratos) . ")
          ORDER BY (status IN ('cancelada', 'encerrada')) ASC, id_assinatura_pagamento DESC",
        str_repeat('i', count($contratos)),
        ...$contratos
    ) as $v) {
        $vinculos[(int)$v['id_assinatura']] ??= $v;
    }

    $r = [];
    foreach ($cobrancas as $c) {
        $id = (int)$c['id_cobranca'];
        $empresa = (int)$c['id_empresa'];
        $transacao = isset($transacoes[$id]) && (int)$transacoes[$id]['id_empresa'] === $empresa ? $transacoes[$id] : null;
        $vinculo = isset($vinculos[(int)$c['id_assinatura']]) && (int)$vinculos[(int)$c['id_assinatura']]['id_empresa'] === $empresa
            ? $vinculos[(int)$c['id_assinatura']] : null;
        // A mesma regra do backend que recusa o PIX (sem cartão envolvido não há o que consultar).
        $bloqueio = (string)$c['status'] === 'pendente' && ($transacao !== null || $vinculo !== null)
            ? assinaturaServicoPreapprovalBloqueioPix($conexao, $empresa, $id) : null;
        $r[$id] = assinaturaServicoPreapprovalSituacaoPagamento($transacao, $vinculo, $bloqueio);
    }

    return $r;
}

/*
 * ---------------------------------------------------------------------
 * Ciclo contratual x Preapproval: intenção de cancelar, pausar e reativar.
 *
 * Regras:
 * - A intenção é gravada na mesma transação da mudança contratual (sem rede) e fica em
 *   `operacao_assinatura_gateway` (uma operação aberta por vínculo; ver uq_oag_operacao_aberta).
 *   O envio ao provedor é feito por mpSubOperacoesProcessarPendentes() (webhook_mercado_pago.php),
 *   nunca por estas funções.
 * - `chk_aspr_slot_ativo` exige slot_ativo = 1 para preapproval em ativa, atualizacao_necessaria
 *   e cancelamento_pendente. Por isso o cancelamento externo pendente MANTÉM o slot ocupado: outro
 *   motor recorrente só é possível depois da confirmação externa ('cancelada', slot NULL).
 * - Inadimplência nunca gera operação externa; suspensão administrativa gera 'pausar'.
 * - Nada aqui apaga cobrança, pagamento ou histórico, nem estorna pagamento.
 * ---------------------------------------------------------------------
 */
const ASSINATURA_SERVICO_PREAPPROVAL_DESTINOS = ['pausar' => 'paused', 'reativar' => 'authorized', 'cancelar' => 'canceled'];

/** Vínculo do contrato, bloqueado. Deve rodar na transação do chamador, com a assinatura já bloqueada. */
function assinaturaServicoPreapprovalVinculoDoContrato(mysqli $conexao, int $idEmpresa, int $idAssinatura, ?int $idAssinaturaPagamento = null): ?array
{
    // Vários vínculos por contrato só depois de encerrados (histórico): vale o vigente; sem ele, o mais recente.
    $linhas = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT id_assinatura_pagamento, id_empresa, id_assinatura, provedor, ambiente, motor_recorrencia, status, slot_ativo,
                assinatura_externa_id, referencia_externa_assinatura, cobrar_a_partir_de, status_externo
           FROM assinatura_pagamento_recorrente
          WHERE id_empresa = ? AND id_assinatura = ?
          ORDER BY (status IN ('cancelada', 'encerrada')) ASC, id_assinatura_pagamento DESC
          FOR UPDATE",
        'ii',
        $idEmpresa,
        $idAssinatura
    );
    if ($idAssinaturaPagamento === null) {
        return $linhas[0] ?? null;
    }
    foreach ($linhas as $linha) {
        if ((int)$linha['id_assinatura_pagamento'] === $idAssinaturaPagamento) {
            return $linha;
        }
    }

    return null;
}

function assinaturaServicoPreapprovalAuditarOperacao(mysqli $conexao, string $evento, array $vinculo, string $tipo, ?int $idOperacao, array $extra, ?array $ator): void
{
    $alteracoes = [];
    foreach (array_merge([
        'id_empresa' => (int)$vinculo['id_empresa'],
        'id_assinatura' => (int)$vinculo['id_assinatura'],
        'id_assinatura_pagamento' => (int)$vinculo['id_assinatura_pagamento'],
        'id_operacao' => $idOperacao,
        'tipo_operacao' => $tipo,
        'status_desejado' => ASSINATURA_SERVICO_PREAPPROVAL_DESTINOS[$tipo] ?? null,
        'status_vinculo' => (string)$vinculo['status'],
    ], $extra) as $campo => $valor) {
        $alteracoes[$campo] = ['antes' => null, 'depois' => $valor];
    }
    assinaturaServicoAuditar($conexao, $evento, [
        'entidade_id' => (int)$vinculo['id_assinatura_pagamento'],
        'entidade_rotulo' => 'Assinatura recorrente #' . (int)$vinculo['id_assinatura_pagamento'],
        'alteracoes' => $alteracoes,
        'contexto' => ['origem' => 'ciclo_contratual_preapproval', 'id_empresa' => (int)$vinculo['id_empresa']],
    ], $ator);
}

/**
 * Registra (idempotente) a intenção de operação externa. Sem rede.
 *
 * @return array{id_operacao: ?int, situacao: string} situacao: criada | existente | substituida | adiada | prevalece_cancelamento
 */
function assinaturaServicoPreapprovalRegistrarOperacao(mysqli $conexao, array $vinculo, string $tipo, ?array $ator = null): array
{
    if (!isset(ASSINATURA_SERVICO_PREAPPROVAL_DESTINOS[$tipo])) {
        throw new InvalidArgumentException('Operação externa inválida.');
    }
    $aberta = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT id_operacao, tipo_operacao, status_processamento FROM operacao_assinatura_gateway
          WHERE id_assinatura_pagamento = ? AND status_processamento IN ('pendente', 'processando', 'conciliacao') FOR UPDATE",
        'i',
        (int)$vinculo['id_assinatura_pagamento']
    )[0] ?? null;

    $situacao = 'criada';
    if ($aberta !== null) {
        if ((string)$aberta['tipo_operacao'] === $tipo) {
            return ['id_operacao' => (int)$aberta['id_operacao'], 'situacao' => 'existente'];
        }
        if ((string)$aberta['tipo_operacao'] === 'cancelar') {
            return ['id_operacao' => (int)$aberta['id_operacao'], 'situacao' => 'prevalece_cancelamento'];
        }
        if ((string)$aberta['status_processamento'] !== 'pendente' || (string)$aberta['tipo_operacao'] === 'conciliar') {
            // Em andamento ou incerta: a intenção continua no estado do vínculo e do contrato e é retomada depois.
            return ['id_operacao' => (int)$aberta['id_operacao'], 'situacao' => 'adiada'];
        }
        assinaturaServicoPreapprovalExec(
            $conexao,
            "UPDATE operacao_assinatura_gateway
                SET status_processamento = 'cancelada', erro_codigo = 'SUBSTITUIDA', erro_motivo = 'Substituída por nova intenção.'
              WHERE id_operacao = ? AND status_processamento = 'pendente'",
            'i',
            (int)$aberta['id_operacao']
        );
        $situacao = 'substituida';
    }

    assinaturaServicoPreapprovalExec(
        $conexao,
        "INSERT INTO operacao_assinatura_gateway
            (id_empresa, id_assinatura_pagamento, tipo_operacao, status_desejado, chave_operacao, status_processamento, proxima_tentativa_em)
         VALUES (?, ?, ?, ?, ?, 'pendente', NOW(6))",
        'iisss',
        (int)$vinculo['id_empresa'],
        (int)$vinculo['id_assinatura_pagamento'],
        $tipo,
        ASSINATURA_SERVICO_PREAPPROVAL_DESTINOS[$tipo],
        pagamentoGatewayChaveIdempotencia()
    );
    $idOperacao = (int)$conexao->insert_id;
    assinaturaServicoPreapprovalAuditarOperacao($conexao, 'assinatura.recorrencia_operacao_solicitada', $vinculo, $tipo, $idOperacao, ['situacao' => $situacao], $ator);

    return ['id_operacao' => $idOperacao, 'situacao' => $situacao];
}

/**
 * Cancelamento (imediato ou ao fim do período pago) do contrato: registra a intenção de cancelar a Preapproval.
 * Deve rodar na transação do cancelamento, com empresa e assinatura bloqueadas e já atualizadas.
 * O acesso local segue a regra contratual; isto NÃO significa que a cobrança externa foi cancelada.
 *
 * @return array<string, mixed> aplicavel=false quando o contrato não usa Preapproval
 */
function assinaturaServicoPreapprovalSolicitarCancelamento(mysqli $conexao, int $idEmpresa, int $idAssinatura, ?array $ator = null): array
{
    $vinculo = assinaturaServicoPreapprovalVinculoDoContrato($conexao, $idEmpresa, $idAssinatura);
    if ($vinculo === null || (string)$vinculo['motor_recorrencia'] !== 'preapproval') {
        return ['aplicavel' => false];
    }
    $status = (string)$vinculo['status'];
    if (in_array($status, ['cancelada', 'encerrada'], true)) {
        return ['aplicavel' => true, 'situacao' => 'ja_cancelada'];
    }
    if ($vinculo['assinatura_externa_id'] === null) {
        // Sem Preapproval conhecida: nada a cancelar no provedor; a criação deixa de ser possível porque o contrato
        // deixa de ser elegível. Uma criação incerta, se existir, é tratada na conciliação.
        assinaturaServicoPreapprovalExec(
            $conexao,
            "UPDATE assinatura_pagamento_recorrente
                SET status = 'erro', ultimo_erro_codigo = 'CANCELADO_LOCALMENTE', ultimo_erro_motivo = 'Contrato cancelado antes da criação da assinatura recorrente.'
              WHERE id_assinatura_pagamento = ? AND id_empresa = ? AND status IN ('pendente', 'erro') AND assinatura_externa_id IS NULL",
            'ii',
            (int)$vinculo['id_assinatura_pagamento'],
            $idEmpresa
        );

        return ['aplicavel' => true, 'situacao' => 'sem_assinatura_externa'];
    }

    if (in_array($status, ['ativa', 'atualizacao_necessaria', 'suspensa'], true)) {
        // slot_ativo e cobrar_a_partir_de permanecem: chk_aspr_slot_ativo e chk_aspr_data_cobranca.
        assinaturaServicoPreapprovalExec(
            $conexao,
            "UPDATE assinatura_pagamento_recorrente SET status = 'cancelamento_pendente'
              WHERE id_assinatura_pagamento = ? AND id_empresa = ? AND status IN ('ativa', 'atualizacao_necessaria', 'suspensa') AND slot_ativo = 1",
            'ii',
            (int)$vinculo['id_assinatura_pagamento'],
            $idEmpresa
        );
    }
    $operacao = assinaturaServicoPreapprovalRegistrarOperacao($conexao, $vinculo, 'cancelar', $ator);

    return ['aplicavel' => true, 'situacao' => 'solicitado', 'id_operacao' => $operacao['id_operacao'], 'situacao_operacao' => $operacao['situacao']];
}

/**
 * Cancelamento só do pagamento automático (o contrato continua) recusado pelo provedor: o vínculo volta ao estado em que
 * estava ANTES do pedido, informado por quem registrou a intenção (`$statusOrigem`); nada fica cancelado apenas no banco.
 * - 'ativa': volta a 'ativa' (a Preapproval segue autorizada e cobrando). Exige contrato vigente sem fim programado (o
 *   cancelamento do contrato não é desfeito aqui).
 * - 'pendente' (autorizada no provedor, ainda não ativada aqui): volta a 'pendente', sem slot e sem data de cobrança, como
 *   a sincronização a grava; nunca vira 'ativa' por causa de uma recusa. O sinal (ex.: COBRANCA_EXTERNA_ANTECIPADA) é
 *   recalculado pela sincronização que o chamador executa em seguida.
 * - Qualquer outra origem (inclusive 'cancelamento_pendente' retomado, de origem desconhecida: o estado anterior não é
 *   persistido) NÃO é revertida: o vínculo permanece em 'cancelamento_pendente', estado seguro e conciliável.
 * Exige a operação informada encerrada como PROVEDOR_REJEITOU, nenhuma outra operação aberta e o estado externo já
 * sincronizado como 'authorized'. Na transação do chamador.
 */
function assinaturaServicoPreapprovalReverterCancelamentoRecusado(mysqli $conexao, int $idEmpresa, int $idAssinaturaPagamento, int $idOperacao, string $statusOrigem = ''): bool
{
    $operacaoRecusada = "EXISTS (SELECT 1 FROM operacao_assinatura_gateway o
                         WHERE o.id_operacao = ? AND o.id_assinatura_pagamento = v.id_assinatura_pagamento AND o.tipo_operacao = 'cancelar'
                           AND o.status_processamento = 'erro' AND o.erro_codigo = 'PROVEDOR_REJEITOU')
            AND NOT EXISTS (SELECT 1 FROM operacao_assinatura_gateway o2
                             WHERE o2.id_assinatura_pagamento = v.id_assinatura_pagamento
                               AND o2.status_processamento IN ('pendente', 'processando', 'conciliacao'))";
    if ($statusOrigem === 'ativa') {
        return assinaturaServicoPreapprovalExec(
            $conexao,
            "UPDATE assinatura_pagamento_recorrente v
               JOIN assinatura a ON a.id_assinatura = v.id_assinatura AND a.id_empresa = v.id_empresa
                SET v.status = 'ativa', v.ultimo_erro_codigo = 'CANCELAMENTO_RECUSADO',
                    v.ultimo_erro_motivo = 'O provedor recusou o cancelamento do pagamento automático.'
              WHERE v.id_assinatura_pagamento = ? AND v.id_empresa = ? AND v.status = 'cancelamento_pendente' AND v.slot_ativo = 1
                AND v.status_externo = 'authorized' AND a.status = 'ativa' AND a.data_fim IS NULL
                AND {$operacaoRecusada}",
            'iii',
            $idAssinaturaPagamento,
            $idEmpresa,
            $idOperacao
        ) === 1;
    }
    if ($statusOrigem === 'pendente') {
        return assinaturaServicoPreapprovalExec(
            $conexao,
            "UPDATE assinatura_pagamento_recorrente v
                SET v.status = 'pendente', v.slot_ativo = NULL, v.cobrar_a_partir_de = NULL
              WHERE v.id_assinatura_pagamento = ? AND v.id_empresa = ? AND v.status = 'cancelamento_pendente' AND v.slot_ativo = 1
                AND v.status_externo = 'authorized' AND v.assinatura_externa_id IS NOT NULL
                AND {$operacaoRecusada}",
            'iii',
            $idAssinaturaPagamento,
            $idEmpresa,
            $idOperacao
        ) === 1;
    }

    return false;
}

/**
 * "Pagar com PIX em vez do cartão" na primeira cobrança da contratação: registra o cancelamento da Preapproval do
 * contrato pendente no outbox (sem rede). Enquanto o provedor não confirmar o cancelamento, o PIX segue bloqueado
 * (assinaturaServicoPreapprovalBloqueioPix) e o slot fica ocupado, como em qualquer cancelamento externo pendente.
 * Deve rodar na transação do chamador. A cobrança de contratação não é alterada.
 *
 * @return array<string, mixed>
 */
function assinaturaServicoPreapprovalDesistirPrimeiraCobranca(mysqli $conexao, int $idEmpresa, ?array $ator = null): array
{
    $contratos = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT id_assinatura, status, modalidade, motivo_suspensao, data_fim,
                DATE_FORMAT(teste_expira_em, '%Y-%m-%d %H:%i:%s') AS teste_expira_em
           FROM assinatura
          WHERE id_empresa = ? AND status IN ('ativa', 'suspensa') FOR UPDATE",
        'i',
        $idEmpresa
    );
    // Também vale para o teste grátis com cartão autorizado (antes ou depois da expiração): o cartão deixa de cobrar a
    // primeira mensalidade e, expirado o teste, a regularização segue por PIX.
    $teste = count($contratos) === 1 && assinaturaServicoPreapprovalTesteApto($contratos[0]);
    if (count($contratos) !== 1 || (!$teste && !assinaturaServicoPreapprovalContratacaoPendente($contratos[0]))) {
        return assinaturaServicoNovaContratacaoResultado(false, 'SEM_CONTRATACAO_PENDENTE', 'Não há contratação aguardando o pagamento da primeira cobrança.', ['http_status' => 409]);
    }
    $idAssinatura = (int)$contratos[0]['id_assinatura'];
    $vinculo = assinaturaServicoPreapprovalVinculoDoContrato($conexao, $idEmpresa, $idAssinatura);
    if ($vinculo === null || (string)$vinculo['motor_recorrencia'] !== 'preapproval'
        || in_array((string)$vinculo['status'], ['cancelada', 'encerrada'], true)) {
        return assinaturaServicoNovaContratacaoResultado(true, 'SEM_CARTAO', 'Não há pagamento no cartão para esta contratação.', ['situacao' => 'sem_cartao']);
    }
    $operacaoAberta = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT tipo_operacao FROM operacao_assinatura_gateway
          WHERE id_assinatura_pagamento = ? AND status_processamento IN ('pendente', 'processando', 'conciliacao') FOR UPDATE",
        'i',
        (int)$vinculo['id_assinatura_pagamento']
    )[0]['tipo_operacao'] ?? null;
    if ($vinculo['assinatura_externa_id'] === null) {
        if ($operacaoAberta !== null) {
            // A criação pode ter chegado ao provedor: só a conciliação diz se há algo a cancelar.
            return assinaturaServicoNovaContratacaoResultado(false, 'CRIACAO_EM_CONCILIACAO', 'Ainda estamos confirmando se o cadastro do cartão chegou a ser aberto no Mercado Pago. Use "Atualizar situação" ou fale com o suporte. Sua assinatura e o teste grátis não são afetados.', ['http_status' => 409]);
        }
        $r = assinaturaServicoPreapprovalSolicitarCancelamento($conexao, $idEmpresa, $idAssinatura, $ator);

        return assinaturaServicoNovaContratacaoResultado(true, 'CARTAO_DESCARTADO', $teste
            ? 'A autorização do cartão foi descartada. Nenhuma mensalidade será cobrada nele.'
            : 'O pagamento no cartão foi descartado. Você já pode pagar com PIX.', ['situacao' => (string)$r['situacao']]);
    }
    $r = assinaturaServicoPreapprovalCancelarPrimeiraExterna($conexao, $vinculo, $ator);
    if ($r === null) {
        return assinaturaServicoNovaContratacaoResultado(false, 'VINCULO_INCONSISTENTE', 'O pagamento no cartão precisa de verificação. Fale com o suporte.', ['http_status' => 409]);
    }

    return assinaturaServicoNovaContratacaoResultado(true, 'CANCELAMENTO_CARTAO_SOLICITADO', $teste
        ? 'O cancelamento do cartão foi solicitado ao Mercado Pago. Nenhuma mensalidade será cobrada nele depois da confirmação.'
        : 'O cancelamento do pagamento no cartão foi solicitado. O PIX será liberado assim que o Mercado Pago confirmar.', [
        'situacao' => (string)$r['situacao'],
        'id_operacao' => $r['id_operacao'] ?? null,
    ]);
}

/**
 * Cancelamento externo da Preapproval (já criada) da primeira cobrança, pelo outbox. Na transação do chamador, com o
 * contrato bloqueado. Null quando o vínculo não pôde passar a 'cancelamento_pendente'.
 *
 * @param array<string, mixed> $vinculo retorno de assinaturaServicoPreapprovalVinculoDoContrato()
 * @return array<string, mixed>|null resultado de assinaturaServicoPreapprovalSolicitarCancelamento()
 */
function assinaturaServicoPreapprovalCancelarPrimeiraExterna(mysqli $conexao, array $vinculo, ?array $ator = null): ?array
{
    $idEmpresa = (int)$vinculo['id_empresa'];
    if ((string)$vinculo['status'] === 'pendente') {
        // Mesmo sem autorização local, o pagador ainda pode concluir o checkout: cancelamento externo obrigatório, com o
        // slot ocupado até a confirmação (chk_aspr_slot_ativo, chk_aspr_data_cobranca).
        try {
            $afetadas = assinaturaServicoPreapprovalExec(
                $conexao,
                "UPDATE assinatura_pagamento_recorrente
                    SET status = 'cancelamento_pendente', slot_ativo = 1,
                        cobrar_a_partir_de = COALESCE(cobrar_a_partir_de, proxima_cobranca_em, NOW())
                  WHERE id_assinatura_pagamento = ? AND id_empresa = ? AND status = 'pendente' AND slot_ativo IS NULL
                    AND assinatura_externa_id IS NOT NULL",
                'ii',
                (int)$vinculo['id_assinatura_pagamento'],
                $idEmpresa
            );
        } catch (RuntimeException $e) {
            if (!assinaturaServicoPreapprovalChaveDuplicada($e)) {
                throw $e;
            }
            $afetadas = 0;
        }
        if ($afetadas !== 1) {
            return null;
        }
    }

    return assinaturaServicoPreapprovalSolicitarCancelamento($conexao, $idEmpresa, (int)$vinculo['id_assinatura'], $ator);
}

/** Suspensão administrativa: registra 'pausar'. Inadimplência jamais chama esta função. */
function assinaturaServicoPreapprovalSolicitarPausa(mysqli $conexao, int $idEmpresa, int $idAssinatura, ?array $ator = null): array
{
    $vinculo = assinaturaServicoPreapprovalVinculoDoContrato($conexao, $idEmpresa, $idAssinatura);
    if ($vinculo === null || (string)$vinculo['motor_recorrencia'] !== 'preapproval' || $vinculo['assinatura_externa_id'] === null) {
        return ['aplicavel' => false];
    }
    if (!in_array((string)$vinculo['status'], ['ativa', 'atualizacao_necessaria'], true)) {
        return ['aplicavel' => true, 'situacao' => 'sem_pausa_necessaria'];
    }
    $operacao = assinaturaServicoPreapprovalRegistrarOperacao($conexao, $vinculo, 'pausar', $ator);

    return ['aplicavel' => true, 'situacao' => 'solicitado', 'id_operacao' => $operacao['id_operacao'], 'situacao_operacao' => $operacao['situacao']];
}

/**
 * Reativação administrativa: reverte uma pausa ainda não enviada ou registra 'reativar'. O vínculo só volta a
 * ser operacional depois que o provedor confirmar 'authorized'.
 */
function assinaturaServicoPreapprovalSolicitarReativacao(mysqli $conexao, int $idEmpresa, int $idAssinatura, ?array $ator = null): array
{
    $vinculo = assinaturaServicoPreapprovalVinculoDoContrato($conexao, $idEmpresa, $idAssinatura);
    if ($vinculo === null || (string)$vinculo['motor_recorrencia'] !== 'preapproval' || $vinculo['assinatura_externa_id'] === null) {
        return ['aplicavel' => false];
    }
    $status = (string)$vinculo['status'];
    if ($status === 'suspensa') {
        $operacao = assinaturaServicoPreapprovalRegistrarOperacao($conexao, $vinculo, 'reativar', $ator);

        return ['aplicavel' => true, 'situacao' => 'solicitado', 'id_operacao' => $operacao['id_operacao'], 'situacao_operacao' => $operacao['situacao']];
    }
    if (in_array($status, ['ativa', 'atualizacao_necessaria'], true)) {
        $reverteu = assinaturaServicoPreapprovalExec(
            $conexao,
            "UPDATE operacao_assinatura_gateway
                SET status_processamento = 'cancelada', erro_codigo = 'REVERTIDA', erro_motivo = 'Suspensão revertida antes do envio.'
              WHERE id_assinatura_pagamento = ? AND tipo_operacao = 'pausar' AND status_processamento = 'pendente'",
            'i',
            (int)$vinculo['id_assinatura_pagamento']
        );

        return ['aplicavel' => true, 'situacao' => $reverteu > 0 ? 'pausa_revertida' : 'sem_reativacao_necessaria'];
    }

    return ['aplicavel' => true, 'situacao' => 'sem_reativacao_necessaria'];
}

/**
 * Operação desejada a partir do estado do contrato e do vínculo (rede de segurança do outbox).
 * 'cancelar' quando há intenção de cancelamento ou o contrato foi cancelado/tem fim programado;
 * 'pausar' somente na suspensão administrativa (motivo 'manual'); inadimplência nunca gera operação.
 */
function assinaturaServicoPreapprovalOperacaoDesejada(array $vinculo, array $contrato): ?string
{
    $statusVinculo = (string)$vinculo['status'];
    if ($vinculo['assinatura_externa_id'] === null || in_array($statusVinculo, ['cancelada', 'encerrada'], true)) {
        return null;
    }
    if ($statusVinculo === 'cancelamento_pendente' || in_array((string)$contrato['status'], ['cancelada', 'encerrada'], true)
        || $contrato['data_fim'] !== null) {
        return 'cancelar';
    }
    if ((string)$contrato['status'] === 'suspensa' && (string)($contrato['motivo_suspensao'] ?? '') === 'manual'
        && (in_array($statusVinculo, ['ativa', 'atualizacao_necessaria'], true)
            || ($statusVinculo === 'pendente' && (string)($vinculo['status_externo'] ?? '') === 'authorized'))) {
        return 'pausar';
    }

    return null;
}

/** Garante, numa transação própria, que a intenção derivada do estado esteja registrada no outbox. */
function assinaturaServicoPreapprovalGarantirOperacao(mysqli $conexao, int $idAssinaturaPagamento, ?array $ator = null, ?int $idEmpresaEscopo = null): ?array
{
    if ($idEmpresaEscopo !== null && $idEmpresaEscopo <= 0) throw new InvalidArgumentException('Empresa inválida.');
    $ref = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT id_empresa, id_assinatura FROM assinatura_pagamento_recorrente WHERE id_assinatura_pagamento = ? AND motor_recorrencia = 'preapproval'
           AND (? IS NULL OR id_empresa = ?)",
        'iii',
        $idAssinaturaPagamento,
        $idEmpresaEscopo,
        $idEmpresaEscopo
    )[0] ?? null;
    if ($ref === null) {
        return null;
    }
    if ($idEmpresaEscopo !== null && (int)$ref['id_empresa'] !== $idEmpresaEscopo) {
        throw new RuntimeException('Vínculo fora do escopo de empresa.');
    }

    return assinaturaServicoPreapprovalTransacao($conexao, static function () use ($conexao, $ref, $ator, $idAssinaturaPagamento): ?array {
        $contrato = assinaturaServicoConsultaFinanceira(
            $conexao,
            'SELECT status, data_fim, motivo_suspensao FROM assinatura WHERE id_assinatura = ? AND id_empresa = ? FOR UPDATE',
            'ii',
            (int)$ref['id_assinatura'],
            (int)$ref['id_empresa']
        )[0] ?? null;
        $vinculo = assinaturaServicoPreapprovalVinculoDoContrato($conexao, (int)$ref['id_empresa'], (int)$ref['id_assinatura'], $idAssinaturaPagamento);
        if ($contrato === null || $vinculo === null) {
            return null;
        }
        $tipo = assinaturaServicoPreapprovalOperacaoDesejada($vinculo, $contrato);
        if ($tipo === null) {
            return null;
        }
        // Falha recente da mesma operação: sem nova tentativa automática (evita PUT repetido às cegas). Se a última dessas
        // operações foi rejeitada pelo provedor (ou achou destino incompatível), o bloqueio não expira: só a reabertura
        // controlada, com a causa confirmada como corrigida (mpSubOperacaoReabrir), volta a enviar.
        $falhouAntes = assinaturaServicoConsultaFinanceira(
            $conexao,
            "SELECT 1 FROM operacao_assinatura_gateway o
              WHERE o.id_assinatura_pagamento = ? AND o.tipo_operacao = ? AND o.status_processamento = 'erro'
                AND (o.atualizado_em > DATE_SUB(NOW(6), INTERVAL 1 DAY)
                     OR (o.erro_codigo IN ('PROVEDOR_REJEITOU', 'DESTINO_INCOMPATIVEL')
                         AND o.id_operacao = (SELECT MAX(u.id_operacao) FROM operacao_assinatura_gateway u
                                               WHERE u.id_assinatura_pagamento = o.id_assinatura_pagamento
                                                 AND u.tipo_operacao = o.tipo_operacao))) LIMIT 1",
            'is',
            (int)$vinculo['id_assinatura_pagamento'],
            $tipo
        ) !== [];
        if ($falhouAntes) {
            return null;
        }
        if ($tipo === 'cancelar' && in_array((string)$vinculo['status'], ['ativa', 'atualizacao_necessaria', 'suspensa'], true)) {
            assinaturaServicoPreapprovalExec(
                $conexao,
                "UPDATE assinatura_pagamento_recorrente SET status = 'cancelamento_pendente'
                  WHERE id_assinatura_pagamento = ? AND id_empresa = ? AND status IN ('ativa', 'atualizacao_necessaria', 'suspensa') AND slot_ativo = 1",
                'ii',
                (int)$vinculo['id_assinatura_pagamento'],
                (int)$vinculo['id_empresa']
            );
        }

        return assinaturaServicoPreapprovalRegistrarOperacao($conexao, $vinculo, $tipo, $ator);
    });
}

/*
 * ---------------------------------------------------------------------
 * Troca de plano x valor recorrente da Preapproval.
 *
 * - Upgrade: a cobrança proporcional avulsa segue o fluxo existente. Depois que o upgrade é ativado
 *   (pagamento confirmado), a atualização do valor recorrente entra no outbox como 'atualizar_valor'
 *   (id_troca preenchido: uq_oag_troca_tipo garante uma única atualização externa por troca).
 * - Downgrade: o plano atual vale até o fim da competência paga. A atualização do valor é agendada para
 *   imediatamente (a competência atual já está paga; não há antecedência mínima documentada) e a efetivação
 *   local só ocorre depois que o provedor confirmar o novo valor; sem confirmação, a troca fica em
 *   'programada' e sinalizada, nunca concluída em silêncio.
 * - A intenção é durável: quando outra operação ocupa o slot do outbox, o estado das trocas permite
 *   registrá-la depois (assinaturaServicoPreapprovalGarantirValor).
 * - O valor sempre vem do contrato/troca internos; o pagamento do upgrade nunca é desfeito.
 * ---------------------------------------------------------------------
 */

function assinaturaServicoPreapprovalVinculoAceitaValor(array $vinculo): bool
{
    return (string)$vinculo['motor_recorrencia'] === 'preapproval' && $vinculo['assinatura_externa_id'] !== null
        && !in_array((string)$vinculo['status'], ['cancelada', 'encerrada', 'erro', 'cancelamento_pendente'], true);
}

/**
 * Registra (idempotente) a intenção de atualizar o valor recorrente. Sem rede. Participa da transação do chamador.
 *
 * @return array{id_operacao: ?int, situacao: string} situacao: criada | existente | reaberta | adiada | prevalece_cancelamento | nao_aplicavel | bloqueada
 */
function assinaturaServicoPreapprovalRegistrarValor(mysqli $conexao, array $vinculo, ?int $idTroca, string $valorDecimal, ?DateTimeImmutable $naoAntesDe = null, ?array $ator = null): array
{
    if (!assinaturaServicoPreapprovalVinculoAceitaValor($vinculo) || pagamentoGatewayValorCentavos($valorDecimal) <= 0) {
        return ['id_operacao' => null, 'situacao' => 'nao_aplicavel'];
    }
    $idAspr = (int)$vinculo['id_assinatura_pagamento'];
    $momento = $naoAntesDe !== null && $naoAntesDe > new DateTimeImmutable() ? $naoAntesDe->format('Y-m-d H:i:s') : null;

    $aberta = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT id_operacao, tipo_operacao, status_processamento FROM operacao_assinatura_gateway
          WHERE id_assinatura_pagamento = ? AND status_processamento IN ('pendente', 'processando', 'conciliacao') FOR UPDATE",
        'i',
        $idAspr
    )[0] ?? null;
    $daTroca = $idTroca === null ? null : (assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT id_operacao, status_processamento, erro_codigo FROM operacao_assinatura_gateway
          WHERE id_troca = ? AND tipo_operacao = 'atualizar_valor' FOR UPDATE",
        'i',
        $idTroca
    )[0] ?? null);

    if ($daTroca !== null) {
        $status = (string)$daTroca['status_processamento'];
        if (in_array($status, ['pendente', 'processando', 'conciliacao', 'concluida'], true)) {
            return ['id_operacao' => (int)$daTroca['id_operacao'], 'situacao' => 'existente'];
        }
        if ($status !== 'cancelada' || (string)$daTroca['erro_codigo'] !== 'SUBSTITUIDA') {
            return ['id_operacao' => (int)$daTroca['id_operacao'], 'situacao' => 'bloqueada'];
        }
        if ($aberta !== null) {
            return ['id_operacao' => (int)$daTroca['id_operacao'], 'situacao' => (string)$aberta['tipo_operacao'] === 'cancelar' ? 'prevalece_cancelamento' : 'adiada'];
        }
        assinaturaServicoPreapprovalExec(
            $conexao,
            "UPDATE operacao_assinatura_gateway
                SET status_processamento = 'pendente', erro_codigo = NULL, erro_motivo = NULL, valor_desejado = ?,
                    tentativas_processamento = 0, concluida_em = NULL, proxima_tentativa_em = COALESCE(?, NOW(6))
              WHERE id_operacao = ? AND status_processamento = 'cancelada'",
            'ssi',
            $valorDecimal,
            $momento,
            (int)$daTroca['id_operacao']
        );
        assinaturaServicoPreapprovalAuditarOperacao($conexao, 'assinatura.recorrencia_operacao_solicitada', $vinculo, 'atualizar_valor', (int)$daTroca['id_operacao'], ['situacao' => 'reaberta'], $ator);

        return ['id_operacao' => (int)$daTroca['id_operacao'], 'situacao' => 'reaberta'];
    }

    if ($aberta !== null) {
        if ((string)$aberta['tipo_operacao'] === 'cancelar') {
            return ['id_operacao' => (int)$aberta['id_operacao'], 'situacao' => 'prevalece_cancelamento'];
        }
        if ((string)$aberta['tipo_operacao'] === 'atualizar_valor' && $idTroca === null) {
            return ['id_operacao' => (int)$aberta['id_operacao'], 'situacao' => 'existente'];
        }

        return ['id_operacao' => (int)$aberta['id_operacao'], 'situacao' => 'adiada'];
    }

    assinaturaServicoPreapprovalExec(
        $conexao,
        "INSERT INTO operacao_assinatura_gateway
            (id_empresa, id_assinatura_pagamento, id_troca, tipo_operacao, valor_desejado, chave_operacao, status_processamento, proxima_tentativa_em)
         VALUES (?, ?, ?, 'atualizar_valor', ?, ?, 'pendente', COALESCE(?, NOW(6)))",
        'iiisss',
        (int)$vinculo['id_empresa'],
        $idAspr,
        $idTroca,
        $valorDecimal,
        pagamentoGatewayChaveIdempotencia(),
        $momento
    );
    $idOperacao = (int)$conexao->insert_id;
    assinaturaServicoPreapprovalAuditarOperacao($conexao, 'assinatura.recorrencia_operacao_solicitada', $vinculo, 'atualizar_valor', $idOperacao, ['situacao' => 'criada'], $ator);

    return ['id_operacao' => $idOperacao, 'situacao' => 'criada'];
}

/**
 * Deriva do estado das trocas a atualização de valor devida e a registra. Deve rodar na transação do chamador
 * (a assinatura é bloqueada aqui, o que é idempotente se já estiver).
 *
 * @return array{id_operacao: ?int, situacao: string}|null null quando o contrato não usa Preapproval ou não há troca relevante
 */
function assinaturaServicoPreapprovalSincronizarValorNaTransacao(mysqli $conexao, int $idEmpresa, int $idAssinatura, ?array $ator = null): ?array
{
    $contrato = assinaturaServicoConsultaFinanceira(
        $conexao,
        'SELECT valor_contratado FROM assinatura WHERE id_assinatura = ? AND id_empresa = ? FOR UPDATE',
        'ii',
        $idAssinatura,
        $idEmpresa
    )[0] ?? null;
    $vinculo = assinaturaServicoPreapprovalVinculoDoContrato($conexao, $idEmpresa, $idAssinatura);
    if ($contrato === null || $vinculo === null || (string)$vinculo['motor_recorrencia'] !== 'preapproval'
        || assinaturaServicoTrocaEstruturaPendente($conexao) !== null) {
        return null;
    }
    $troca = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT t.id_troca, t.status, t.valor_novo, t.data_efetivacao
           FROM assinatura_troca_plano t
           JOIN assinatura_pagamento_recorrente v ON v.id_assinatura_pagamento = ?
          WHERE t.id_empresa = ? AND t.id_assinatura = ? AND t.status IN ('ativada', 'efetivada', 'programada')
            AND t.criado_em >= v.criado_em
          ORDER BY t.id_troca DESC LIMIT 1",
        'iii',
        (int)$vinculo['id_assinatura_pagamento'],
        $idEmpresa,
        $idAssinatura
    )[0] ?? null;
    $resultado = null;
    if ($troca !== null) {
        // Sem espera: a documentação do provedor não fixa antecedência mínima nem o instante em que o novo valor passa a
        // valer, então o downgrade é sincronizado assim que programado (a competência atual já está paga) e a efetivação
        // local só ocorre com o valor confirmado. Um prazo fixo antes da competência não é garantia suficiente.
        $resultado = (string)$troca['status'] === 'programada'
            ? assinaturaServicoPreapprovalRegistrarValor($conexao, $vinculo, (int)$troca['id_troca'], (string)$troca['valor_novo'], null, $ator)
            : assinaturaServicoPreapprovalRegistrarValor($conexao, $vinculo, (int)$troca['id_troca'], (string)$contrato['valor_contratado'], null, $ator);
    }
    if ($resultado === null || $resultado['situacao'] === 'existente') {
        $reversao = assinaturaServicoPreapprovalReversaoDevida($conexao, $vinculo, (string)$contrato['valor_contratado'], $ator);
        if ($reversao !== null) {
            return $reversao;
        }
    }

    return $resultado;
}

/**
 * Reversão durável: se o último valor levado ao provedor com sucesso difere do valor do contrato e não há downgrade
 * programado que o justifique (troca cancelada ou expirada depois do envio), o valor do contrato é reenviado. Derivada
 * do estado, portanto recuperável mesmo quando outra operação ocupava o outbox no momento do cancelamento.
 *
 * @return array{id_operacao: ?int, situacao: string}|null
 */
function assinaturaServicoPreapprovalReversaoDevida(mysqli $conexao, array $vinculo, string $valorContrato, ?array $ator = null): ?array
{
    $ultima = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT o.valor_desejado, o.status_processamento, t.status AS troca_status, t.valor_novo
           FROM operacao_assinatura_gateway o
           LEFT JOIN assinatura_troca_plano t ON t.id_troca = o.id_troca
          WHERE o.id_assinatura_pagamento = ? AND o.tipo_operacao = 'atualizar_valor'
          ORDER BY o.id_operacao DESC LIMIT 1",
        'i',
        (int)$vinculo['id_assinatura_pagamento']
    )[0] ?? null;
    if ($ultima === null || (string)$ultima['status_processamento'] !== 'concluida' || $ultima['valor_desejado'] === null) {
        return null;
    }
    $enviado = pagamentoGatewayValorCentavos((string)$ultima['valor_desejado']);
    if ($enviado === pagamentoGatewayValorCentavos($valorContrato)) {
        return null;
    }
    if ((string)($ultima['troca_status'] ?? '') === 'programada' && $ultima['valor_novo'] !== null
        && pagamentoGatewayValorCentavos((string)$ultima['valor_novo']) === $enviado) {
        return null;
    }

    return assinaturaServicoPreapprovalRegistrarValor($conexao, $vinculo, null, $valorContrato, null, $ator);
}

/** Versão transacional (rotina do outbox e retomadas). */
function assinaturaServicoPreapprovalGarantirValor(mysqli $conexao, int $idAssinaturaPagamento, ?array $ator = null, ?int $idEmpresaEscopo = null): ?array
{
    if ($idEmpresaEscopo !== null && $idEmpresaEscopo <= 0) throw new InvalidArgumentException('Empresa inválida.');
    $ref = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT id_empresa, id_assinatura FROM assinatura_pagamento_recorrente WHERE id_assinatura_pagamento = ? AND motor_recorrencia = 'preapproval'
           AND (? IS NULL OR id_empresa = ?)",
        'iii',
        $idAssinaturaPagamento,
        $idEmpresaEscopo,
        $idEmpresaEscopo
    )[0] ?? null;
    if ($ref === null) {
        return null;
    }
    if ($idEmpresaEscopo !== null && (int)$ref['id_empresa'] !== $idEmpresaEscopo) {
        throw new RuntimeException('Vínculo fora do escopo de empresa.');
    }

    return assinaturaServicoPreapprovalTransacao($conexao, static fn (): ?array
        => assinaturaServicoPreapprovalSincronizarValorNaTransacao($conexao, (int)$ref['id_empresa'], (int)$ref['id_assinatura'], $ator));
}

/**
 * Troca encerrada (cancelada/expirada): cancela a atualização ainda não enviada ou, se o provedor já recebeu o novo
 * valor, registra a reversão ao valor do contrato. Participa da transação do chamador.
 */
function assinaturaServicoPreapprovalReverterValor(mysqli $conexao, int $idEmpresa, array $troca, ?array $ator = null): array
{
    $vinculo = assinaturaServicoPreapprovalVinculoDoContrato($conexao, $idEmpresa, (int)$troca['id_assinatura']);
    if ($vinculo === null || (string)$vinculo['motor_recorrencia'] !== 'preapproval') {
        return ['situacao' => 'nao_aplicavel'];
    }
    $op = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT id_operacao, status_processamento FROM operacao_assinatura_gateway
          WHERE id_troca = ? AND tipo_operacao = 'atualizar_valor' FOR UPDATE",
        'i',
        (int)$troca['id_troca']
    )[0] ?? null;
    if ($op === null) {
        return ['situacao' => 'sem_operacao'];
    }
    $status = (string)$op['status_processamento'];
    if ($status === 'pendente') {
        assinaturaServicoPreapprovalExec(
            $conexao,
            "UPDATE operacao_assinatura_gateway SET status_processamento = 'cancelada', erro_codigo = 'REVERTIDA', erro_motivo = 'Troca encerrada antes do envio.'
              WHERE id_operacao = ? AND status_processamento = 'pendente'",
            'i',
            (int)$op['id_operacao']
        );

        return ['situacao' => 'cancelada'];
    }
    if ($status === 'concluida') {
        $contrato = assinaturaServicoConsultaFinanceira(
            $conexao,
            'SELECT valor_contratado FROM assinatura WHERE id_assinatura = ? AND id_empresa = ?',
            'ii',
            (int)$troca['id_assinatura'],
            $idEmpresa
        )[0] ?? null;
        if ($contrato !== null) {
            $r = assinaturaServicoPreapprovalRegistrarValor($conexao, $vinculo, null, (string)$contrato['valor_contratado'], null, $ator);

            return ['situacao' => 'reversao_' . $r['situacao']];
        }
    }

    return ['situacao' => 'em_andamento'];
}

/**
 * Efetivação do downgrade: com Preapproval, só ocorre depois da confirmação externa do novo valor.
 *
 * @return array{codigo: string}|null null quando a efetivação pode seguir
 */
function assinaturaServicoPreapprovalBloqueioEfetivacao(mysqli $conexao, int $idEmpresa, array $troca, ?array $ator = null): ?array
{
    $vinculo = assinaturaServicoPreapprovalVinculoDoContrato($conexao, $idEmpresa, (int)$troca['id_assinatura']);
    if ($vinculo === null || !assinaturaServicoPreapprovalVinculoAceitaValor($vinculo)) {
        return null;
    }
    $op = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT status_processamento FROM operacao_assinatura_gateway WHERE id_troca = ? AND tipo_operacao = 'atualizar_valor' FOR UPDATE",
        'i',
        (int)$troca['id_troca']
    )[0] ?? null;
    if ($op !== null && (string)$op['status_processamento'] === 'concluida') {
        return null;
    }
    if ($op === null || (string)$op['status_processamento'] === 'cancelada') {
        assinaturaServicoPreapprovalRegistrarValor($conexao, $vinculo, (int)$troca['id_troca'], (string)$troca['valor_novo'], null, $ator);
    }
    assinaturaServicoPreapprovalExec(
        $conexao,
        "UPDATE assinatura_pagamento_recorrente SET ultimo_erro_codigo = 'DOWNGRADE_AGUARDA_VALOR_EXTERNO',
                ultimo_erro_motivo = 'Downgrade não efetivado: o novo valor recorrente ainda não foi confirmado no provedor.'
          WHERE id_assinatura_pagamento = ? AND id_empresa = ?",
        'ii',
        (int)$vinculo['id_assinatura_pagamento'],
        $idEmpresa
    );

    return ['codigo' => 'VALOR_EXTERNO_NAO_CONFIRMADO'];
}

/**
 * Valores externos aceitáveis enquanto há atualização de valor em andamento ou downgrade programado já sincronizado:
 * o novo valor e o valor anterior da troca. Evita que a sincronização trate a transição como divergência.
 *
 * @return list<int> centavos
 */
function assinaturaServicoPreapprovalValoresTransitorios(mysqli $conexao, int $idAssinaturaPagamento): array
{
    $linhas = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT o.valor_desejado, t.valor_anterior
           FROM operacao_assinatura_gateway o
           LEFT JOIN assinatura_troca_plano t ON t.id_troca = o.id_troca
          WHERE o.id_assinatura_pagamento = ? AND o.tipo_operacao = 'atualizar_valor' AND o.valor_desejado IS NOT NULL
            AND (o.status_processamento IN ('pendente', 'processando', 'conciliacao')
                 OR (o.status_processamento = 'concluida' AND t.status = 'programada'))",
        'i',
        $idAssinaturaPagamento
    );
    $valores = [];
    foreach ($linhas as $linha) {
        foreach (['valor_desejado', 'valor_anterior'] as $campo) {
            if ($linha[$campo] !== null) {
                $valores[] = pagamentoGatewayValorCentavos((string)$linha[$campo]);
            }
        }
    }

    return array_values(array_unique($valores));
}

/**
 * Valor que a operação ainda deve levar ao provedor, ou null se a intenção deixou de valer.
 *
 * @param array<string, mixed> $contrato status, valor_contratado
 */
function assinaturaServicoPreapprovalValorEsperadoOperacao(mysqli $conexao, array $vinculo, array $contrato, array $operacao): ?int
{
    if (!in_array((string)$contrato['status'], ['ativa', 'suspensa'], true) || !assinaturaServicoPreapprovalVinculoAceitaValor($vinculo)) {
        return null;
    }
    if ($operacao['id_troca'] === null) {
        return pagamentoGatewayValorCentavos((string)$contrato['valor_contratado']);
    }
    $troca = assinaturaServicoConsultaFinanceira(
        $conexao,
        'SELECT status, valor_novo FROM assinatura_troca_plano WHERE id_troca = ? AND id_empresa = ?',
        'ii',
        (int)$operacao['id_troca'],
        (int)$vinculo['id_empresa']
    )[0] ?? null;
    if ($troca === null) {
        return null;
    }

    return match ((string)$troca['status']) {
        'ativada', 'efetivada' => pagamentoGatewayValorCentavos((string)$contrato['valor_contratado']),
        'programada' => pagamentoGatewayValorCentavos((string)$troca['valor_novo']),
        default => null,
    };
}

/**
 * Recontratação: enquanto o cancelamento externo da Preapproval de um contrato anterior não for confirmado, a
 * nova contratação não é aberta (evita duas cobranças recorrentes potenciais). Não reaproveita cartão nem Preapproval.
 */
function assinaturaServicoPreapprovalRecorrenciaAnteriorPendente(mysqli $conexao, int $idEmpresa): ?array
{
    $pendente = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT v.id_assinatura_pagamento
           FROM assinatura_pagamento_recorrente v
           JOIN assinatura a ON a.id_assinatura = v.id_assinatura AND a.id_empresa = v.id_empresa
          WHERE v.id_empresa = ? AND v.motor_recorrencia = 'preapproval' AND v.assinatura_externa_id IS NOT NULL
            AND v.status IN ('pendente', 'ativa', 'atualizacao_necessaria', 'suspensa', 'cancelamento_pendente')
            AND a.status IN ('cancelada', 'encerrada')
          LIMIT 1",
        'i',
        $idEmpresa
    );
    if ($pendente === []) {
        return null;
    }

    return assinaturaServicoNovaContratacaoResultado(
        false,
        'RECORRENCIA_ANTERIOR_PENDENTE',
        'O cancelamento do pagamento automático no cartão do contrato anterior ainda não foi confirmado pelo Mercado Pago. Para evitar duas cobranças, uma nova contratação só pode ser aberta depois dessa confirmação. Tente novamente em alguns minutos.'
    );
}

/*
 * Troca do cartão de uma Preapproval já autorizada (PUT /preapproval/{id} com card_token_id).
 * - A mesma Preapproval é mantida: nada é cancelado nem criado.
 * - O token vem do MercadoPago.js (Card Payment Brick) e é de uso único; número, CVV e validade nunca chegam ao servidor.
 * - Só o GET autoritativo conclui a troca: card_id novo, status authorized e as mesmas condições (valor, periodicidade,
 *   moeda, referência e próxima cobrança) de antes do PUT.
 * - Nenhuma chamada de rede ocorre dentro de transação; o chamador serializa a troca por empresa.
 */
const ASSINATURA_SERVICO_TROCA_CARTAO_TOKEN = '/^[A-Za-z0-9_-]{32,33}$/D';

/**
 * Pré-condições locais da troca, sem rede. Retorna o código de bloqueio ou null.
 * `$pagamentoEmAndamento`: parcela no cartão ainda sem desfecho conhecido (recusa em recycling não bloqueia: trocar o
 * cartão é justamente o que permite a nova tentativa do provedor).
 */
function assinaturaServicoPreapprovalTrocaCartaoElegivel(?array $vinculo, ?array $contrato, int $idEmpresa, bool $operacaoAberta, bool $pagamentoEmAndamento = false): ?string
{
    if ($vinculo === null || $contrato === null) {
        return 'TROCA_CARTAO_SEM_AUTORIZACAO';
    }
    if ((int)$vinculo['id_empresa'] !== $idEmpresa) {
        return 'TROCA_CARTAO_EMPRESA_DIVERGENTE';
    }
    if (($vinculo['motor_recorrencia'] ?? '') !== 'preapproval'
        || (int)$vinculo['id_assinatura'] !== (int)$contrato['id_assinatura']
        || !is_string($vinculo['assinatura_externa_id'] ?? null)
        || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,149}$/D', $vinculo['assinatura_externa_id'])) {
        return 'TROCA_CARTAO_SEM_AUTORIZACAO';
    }
    if ((string)$contrato['status'] !== 'ativa' || (int)($contrato['gera_cobranca'] ?? 0) !== 1) {
        return 'TROCA_CARTAO_CONTRATO_INATIVO';
    }
    if ((string)$vinculo['status'] !== 'ativa' || ($vinculo['status_externo'] ?? '') !== 'authorized'
        || (int)($vinculo['slot_ativo'] ?? 0) !== 1) {
        return 'TROCA_CARTAO_AUTORIZACAO_INATIVA';
    }
    // Troca de valor, pausa ou cancelamento em curso: um segundo PUT concorrente não é enviado.
    if ($operacaoAberta) {
        return 'TROCA_CARTAO_OPERACAO_PENDENTE';
    }
    if ($pagamentoEmAndamento) {
        return 'TROCA_CARTAO_PAGAMENTO_EM_ANDAMENTO';
    }

    return null;
}

/** Identificação do cartão informada pelo provedor; cada campo só é aceito no formato esperado. */
function assinaturaServicoPreapprovalTrocaCartaoDados(array $dados): array
{
    $cartao = $dados['card_id'] ?? null;
    $cartao = (is_string($cartao) || is_int($cartao)) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', (string)$cartao) ? (string)$cartao : null;
    $bandeira = is_string($dados['payment_method_id'] ?? null) ? strtolower($dados['payment_method_id']) : '';
    $final = $dados['last_four_digits'] ?? (is_array($dados['card'] ?? null) ? ($dados['card']['last_four_digits'] ?? null) : null);
    if (is_int($final)) {
        $final = str_pad((string)$final, 4, '0', STR_PAD_LEFT);
    }

    return [
        'card_id' => $cartao,
        'bandeira' => preg_match('/^[a-z0-9_]{2,30}$/D', $bandeira) ? $bandeira : null,
        'final_cartao' => is_string($final) && preg_match('/^[0-9]{4}$/D', $final) ? $final : null,
    ];
}

/** Condições financeiras que a troca de cartão não pode alterar. */
function assinaturaServicoPreapprovalTrocaCartaoCondicoes(array $dados): array
{
    $recorrencia = is_array($dados['auto_recurring'] ?? null) ? $dados['auto_recurring'] : [];
    $valor = $recorrencia['transaction_amount'] ?? null;
    $proxima = assinaturaServicoPreapprovalDataExterna($dados['next_payment_date'] ?? null);

    return [
        'external_reference' => is_string($dados['external_reference'] ?? null) ? $dados['external_reference'] : null,
        'valor_centavos' => is_int($valor) || is_float($valor) || (is_string($valor) && is_numeric($valor))
            ? (int)round(((float)$valor) * 100) : null,
        'moeda' => $recorrencia['currency_id'] ?? null,
        'frequencia' => $recorrencia['frequency'] ?? null,
        'tipo_frequencia' => $recorrencia['frequency_type'] ?? null,
        'proxima_cobranca' => $proxima?->setTimezone(new DateTimeZone(ASSINATURA_SERVICO_PREAPPROVAL_FUSO))->format('Y-m-d'),
    ];
}

/**
 * Executa a troca no provedor e classifica o desfecho pelo GET autoritativo:
 * confirmada | recusada | sem_alteracao | incerta | divergente | bloqueada.
 * O token nunca integra o resultado.
 *
 * @param array{referencia:string,ambiente:string,valor_centavos:int,frequencia_meses:int,frequencia_tipo?:string} $esperado
 */
function assinaturaServicoPreapprovalTrocarCartao(object $gateway, string $preapprovalId, string $tokenCartao, array $esperado): array
{
    $resultado = static fn(string $situacao, string $codigo, array $extra = []): array
        => ['resultado' => $situacao, 'codigo' => $codigo] + $extra;
    if (!preg_match(ASSINATURA_SERVICO_TROCA_CARTAO_TOKEN, $tokenCartao)) {
        return $resultado('bloqueada', 'TOKEN_CARTAO_INVALIDO');
    }
    $validar = static fn(array $dados): ?string
        => assinaturaServicoPreapprovalValidarExterna($dados, $esperado + ['id' => $preapprovalId, 'status_permitidos' => ['authorized']]);
    $normalizar = static function (array $dados): array {
        $dados['status'] = ($dados['status'] ?? null) === 'cancelled' ? 'canceled' : ($dados['status'] ?? null);
        return $dados;
    };

    $antes = $gateway->consultarPreapproval($preapprovalId);
    if (($antes['sucesso'] ?? false) !== true || !is_array($antes['dados'] ?? null)) {
        return $resultado('bloqueada', 'CONSULTA_PREVIA_INDISPONIVEL');
    }
    $dadosAntes = $normalizar($antes['dados']);
    if (($dadosAntes['status'] ?? null) !== 'authorized') {
        return $resultado('bloqueada', 'ASSINATURA_EXTERNA_NAO_AUTORIZADA', [
            'status_externo' => is_string($dadosAntes['status'] ?? null) ? $dadosAntes['status'] : null,
        ]);
    }
    // Divergência anterior à troca não é "corrigida" por um PUT: nada é enviado e o caso vai para verificação.
    $divergenciaPrevia = $validar($dadosAntes);
    if ($divergenciaPrevia !== null) {
        return $resultado('bloqueada', $divergenciaPrevia, ['divergencia_previa' => true]);
    }
    $cartaoAntes = assinaturaServicoPreapprovalTrocaCartaoDados($dadosAntes);
    $condicoesAntes = assinaturaServicoPreapprovalTrocaCartaoCondicoes($dadosAntes);

    $envio = $gateway->atualizarPreapproval($preapprovalId, ['card_token_id' => $tokenCartao]);
    $rejeitado = ($envio['sucesso'] ?? false) !== true && ($envio['codigo'] ?? '') === 'provedor_rejeitou';

    $depois = $gateway->consultarPreapproval($preapprovalId);
    if (($depois['sucesso'] ?? false) !== true || !is_array($depois['dados'] ?? null)) {
        // Rejeição explícita não altera o cartão; sem ela, o desfecho real fica desconhecido até nova consulta.
        return $rejeitado
            ? $resultado('recusada', 'CARTAO_RECUSADO', ['cartao_anterior' => $cartaoAntes])
            : $resultado('incerta', 'CONFIRMACAO_INDISPONIVEL', ['cartao_anterior' => $cartaoAntes]);
    }
    $dadosDepois = $normalizar($depois['dados']);
    $cartaoDepois = assinaturaServicoPreapprovalTrocaCartaoDados($dadosDepois);
    $condicoesDepois = assinaturaServicoPreapprovalTrocaCartaoCondicoes($dadosDepois);
    $extra = [
        'cartao_anterior' => $cartaoAntes,
        'cartao' => $cartaoDepois,
        'condicoes_antes' => $condicoesAntes,
        'condicoes_depois' => $condicoesDepois,
        'status_externo' => is_string($dadosDepois['status'] ?? null) ? $dadosDepois['status'] : null,
    ];

    $divergencia = $validar($dadosDepois) ?? ($condicoesAntes !== $condicoesDepois ? 'CONDICOES_ALTERADAS_NA_TROCA' : null);
    if ($divergencia !== null) {
        return $resultado('divergente', $divergencia, $extra);
    }
    if ($cartaoDepois['card_id'] === null) {
        return $resultado('incerta', 'CARTAO_EXTERNO_NAO_INFORMADO', $extra);
    }
    if ($cartaoDepois['card_id'] !== $cartaoAntes['card_id']) {
        return $resultado('confirmada', 'CARTAO_ALTERADO', $extra);
    }

    return $rejeitado
        ? $resultado('recusada', 'CARTAO_RECUSADO', $extra)
        : $resultado('sem_alteracao', ($envio['sucesso'] ?? false) === true ? 'CARTAO_NAO_ALTERADO' : 'TROCA_NAO_APLICADA', $extra);
}

/*
 * ---------------------------------------------------------------------
 * Diagnóstico somente leitura da Preapproval de uma empresa (CLI de conciliação, --modo=preapproval).
 *
 * Lê o vínculo, o contrato, os proprietários e as transações locais e consulta no provedor apenas
 * GET /preapproval/{id} e GET /authorized_payments/search. Nada é gravado, nenhuma rotina de
 * processamento é chamada e nenhum POST/PUT/DELETE é feito. A saída contém somente identificadores
 * técnicos, estados, datas e valores: nunca e-mail, payer_id, init_point, token ou dado de cartão.
 * O e-mail do pagador só é comparado, e o resultado é um código.
 * ---------------------------------------------------------------------
 */

/**
 * Correspondência do pagador com o responsável pela contratação, sem expor endereços.
 * Responsável inequívoco: o único usuário auditado em `assinatura.criada` do contrato que ainda é proprietário
 * ativo ou, sem auditoria de contratação, o único proprietário ativo. Nos demais casos não há afirmação.
 *
 * @param array<int, string> $proprietarios id_usuario => e-mail dos proprietários ativos
 * @param list<int> $contratantes id_usuario auditados na criação do contrato
 * @return array{confere: string, base: string, pagador_entre_proprietarios: string}
 *         confere: sim | nao | indeterminado | indisponivel
 */
function assinaturaServicoPreapprovalDiagnosticoPagador(mixed $emailPagador, array $proprietarios, array $contratantes): array
{
    $normalizar = static fn(mixed $email): ?string => is_string($email) && trim($email) !== ''
        ? mb_strtolower(trim($email), 'UTF-8') : null;
    $pagador = $normalizar($emailPagador);
    $contratantes = array_values(array_unique(array_map('intval', $contratantes)));
    $responsavel = null;
    if (count($contratantes) === 1 && array_key_exists($contratantes[0], $proprietarios)) {
        $responsavel = $contratantes[0];
        $base = 'contratante_auditado';
    } elseif ($contratantes === [] && count($proprietarios) === 1) {
        $responsavel = (int)array_key_first($proprietarios);
        $base = 'proprietario_unico';
    } elseif ($proprietarios === []) {
        $base = 'sem_proprietario_ativo';
    } elseif (count($contratantes) === 1) {
        $base = 'contratante_nao_e_proprietario_ativo';
    } else {
        $base = count($contratantes) > 1 ? 'multiplos_contratantes' : 'multiplos_proprietarios';
    }
    if ($pagador === null) {
        return ['confere' => 'indisponivel', 'base' => $base, 'pagador_entre_proprietarios' => 'indisponivel'];
    }

    $entre = 'nao';
    foreach ($proprietarios as $email) {
        if ($normalizar($email) === $pagador) {
            $entre = 'sim';
            break;
        }
    }
    $confere = $responsavel === null ? 'indeterminado' : ($normalizar($proprietarios[$responsavel]) === $pagador ? 'sim' : 'nao');

    return ['confere' => $confere, 'base' => $base, 'pagador_entre_proprietarios' => $entre];
}

/** Identificador externo seguro para exibição (ou null). */
function assinaturaServicoPreapprovalDiagnosticoId(mixed $valor): ?string
{
    if (!is_string($valor) && !is_int($valor)) {
        return null;
    }
    $texto = (string)$valor;

    return preg_match('/^[A-Za-z0-9_.-]{1,150}$/D', $texto) === 1 ? $texto : null;
}

/** Código técnico curto do provedor (status, status_detail), sem texto livre. */
function assinaturaServicoPreapprovalDiagnosticoCodigo(mixed $valor): ?string
{
    return is_string($valor) && preg_match('/^[A-Za-z0-9 _.-]{1,60}$/D', $valor) === 1 ? str_replace(' ', '_', $valor) : null;
}

/** Data do provedor no fuso do negócio (ou null). */
function assinaturaServicoPreapprovalDiagnosticoData(mixed $valor): ?string
{
    $data = assinaturaServicoPreapprovalDataExterna($valor);

    return $data?->setTimezone(new DateTimeZone(ASSINATURA_SERVICO_PREAPPROVAL_FUSO))->format('Y-m-d\TH:i:sP');
}

function assinaturaServicoPreapprovalDiagnosticoCentavos(mixed $valor): ?int
{
    return is_int($valor) || is_float($valor) || (is_string($valor) && is_numeric($valor)) ? (int)round(((float)$valor) * 100) : null;
}

/**
 * Campos técnicos da Preapproval. Lista fechada: e-mail, payer_id, init_point, card_id e meio de pagamento
 * nunca entram.
 *
 * @param array<string, mixed> $dados resposta de GET /preapproval/{id}
 * @return array<string, mixed>
 */
function assinaturaServicoPreapprovalDiagnosticoResumo(array $dados): array
{
    $recorrencia = is_array($dados['auto_recurring'] ?? null) ? $dados['auto_recurring'] : [];

    return [
        'preapproval_id' => assinaturaServicoPreapprovalDiagnosticoId($dados['id'] ?? null),
        'status' => assinaturaServicoPreapprovalDiagnosticoCodigo($dados['status'] ?? null),
        'live_mode' => is_bool($dados['live_mode'] ?? null) ? ($dados['live_mode'] ? 'sim' : 'nao') : null,
        'criada_em' => assinaturaServicoPreapprovalDiagnosticoData($dados['date_created'] ?? null),
        'alterada_em' => assinaturaServicoPreapprovalDiagnosticoData($dados['last_modified'] ?? null),
        'proxima_cobranca' => assinaturaServicoPreapprovalDiagnosticoData($dados['next_payment_date'] ?? null),
        'start_date_presente' => ($recorrencia['start_date'] ?? null) !== null ? 'sim' : 'nao',
        'start_date' => assinaturaServicoPreapprovalDiagnosticoData($recorrencia['start_date'] ?? null),
        'end_date' => assinaturaServicoPreapprovalDiagnosticoData($recorrencia['end_date'] ?? null),
        'valor_centavos' => assinaturaServicoPreapprovalDiagnosticoCentavos($recorrencia['transaction_amount'] ?? null),
        'moeda' => assinaturaServicoPreapprovalDiagnosticoCodigo($recorrencia['currency_id'] ?? null),
        'frequencia' => is_int($recorrencia['frequency'] ?? null) ? $recorrencia['frequency'] : null,
        'tipo_frequencia' => assinaturaServicoPreapprovalDiagnosticoCodigo($recorrencia['frequency_type'] ?? null),
    ];
}

/**
 * Campos técnicos de uma fatura autorizada (authorized_payment) e do pagamento resultante.
 *
 * @param array<string, mixed> $item resultado de GET /authorized_payments/search
 * @return array<string, mixed>
 */
function assinaturaServicoPreapprovalDiagnosticoFatura(array $item): array
{
    $pagamento = is_array($item['payment'] ?? null) ? $item['payment'] : [];

    return [
        'fatura_id' => assinaturaServicoPreapprovalDiagnosticoId($item['id'] ?? null),
        'status' => assinaturaServicoPreapprovalDiagnosticoCodigo($item['status'] ?? null),
        'criada_em' => assinaturaServicoPreapprovalDiagnosticoData($item['date_created'] ?? null),
        'debito_em' => assinaturaServicoPreapprovalDiagnosticoData($item['debit_date'] ?? null),
        'tentativa' => is_int($item['retry_attempt'] ?? null) ? $item['retry_attempt'] : null,
        'valor_centavos' => assinaturaServicoPreapprovalDiagnosticoCentavos($item['transaction_amount'] ?? null),
        'motivo_rejeicao' => assinaturaServicoPreapprovalDiagnosticoCodigo($item['rejection_code'] ?? null),
        'pagamento_id' => assinaturaServicoPreapprovalDiagnosticoId($pagamento['id'] ?? null),
        'pagamento_status' => assinaturaServicoPreapprovalDiagnosticoCodigo($pagamento['status'] ?? null),
        'pagamento_detalhe' => assinaturaServicoPreapprovalDiagnosticoCodigo($pagamento['status_detail'] ?? null),
    ];
}

/**
 * Proprietários ativos da empresa (id_usuario => e-mail), pelo mesmo vínculo exigido em permissoesContexto().
 *
 * @return array<int, string>
 */
function assinaturaServicoPreapprovalDiagnosticoProprietarios(mysqli $conexao, int $idEmpresa): array
{
    $proprietarios = [];
    foreach (assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT eu.id_usuario, u.email, pf.nome AS perfil
           FROM empresa_usuario eu
          INNER JOIN usuario u ON u.id_usuario = eu.id_usuario AND u.status = 'ativo'
          INNER JOIN perfil pf ON pf.id_perfil = eu.id_perfil AND pf.status = 'ativo'
          WHERE eu.id_empresa = ? AND eu.status = 'ativo' AND eu.bloqueado_plano = 0",
        'i',
        $idEmpresa
    ) as $linha) {
        if (in_array(mb_strtolower(trim((string)$linha['perfil']), 'UTF-8'), ['proprietario', 'proprietário'], true)) {
            $proprietarios[(int)$linha['id_usuario']] = (string)$linha['email'];
        }
    }

    return $proprietarios;
}

/**
 * Diagnóstico da Preapproval vinculada à empresa no ambiente do gateway. Somente leitura (banco e provedor);
 * o vínculo é escolhido como no painel (slot ativo primeiro, depois o mais recente).
 *
 * @param object $gateway MercadoPagoGateway (ou equivalente com a mesma interface, em testes)
 * @return array<string, mixed> resultado: ok | sem_vinculo | sem_preapproval | consulta_indisponivel
 */
function assinaturaServicoPreapprovalDiagnostico(mysqli $conexao, object $gateway, int $idEmpresa): array
{
    $ambiente = $gateway->ambiente();
    $resultado = ['ambiente' => $ambiente, 'id_empresa' => $idEmpresa];
    $vinculo = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT id_assinatura_pagamento, id_assinatura, status, status_externo, assinatura_externa_id, referencia_externa_assinatura,
                proxima_cobranca_em, ultima_sincronizacao_em, ultimo_erro_codigo, slot_ativo
           FROM assinatura_pagamento_recorrente
          WHERE id_empresa = ? AND provedor = ? AND ambiente = ? AND motor_recorrencia = 'preapproval'
          ORDER BY (slot_ativo = 1) DESC, id_assinatura_pagamento DESC
          LIMIT 1",
        'iss',
        $idEmpresa,
        ASSINATURA_SERVICO_PREAPPROVAL_PROVEDOR,
        $ambiente
    )[0] ?? null;
    if ($vinculo === null) {
        return $resultado + ['resultado' => 'sem_vinculo'];
    }
    $idAssinatura = (int)$vinculo['id_assinatura'];
    $idVinculo = (int)$vinculo['id_assinatura_pagamento'];
    $contrato = assinaturaServicoConsultaFinanceira(
        $conexao,
        'SELECT status, modalidade, motivo_suspensao, valor_contratado, periodicidade FROM assinatura WHERE id_assinatura = ? AND id_empresa = ? LIMIT 1',
        'ii',
        $idAssinatura,
        $idEmpresa
    )[0] ?? null;
    $resultado['vinculo'] = [
        'id_assinatura_pagamento' => $idVinculo,
        'id_assinatura' => $idAssinatura,
        'status' => $vinculo['status'],
        'status_externo' => $vinculo['status_externo'],
        'preapproval_id' => $vinculo['assinatura_externa_id'],
        'proxima_cobranca' => $vinculo['proxima_cobranca_em'],
        'ultima_sincronizacao' => $vinculo['ultima_sincronizacao_em'],
        'ultimo_erro' => $vinculo['ultimo_erro_codigo'],
        'slot_ativo' => (int)($vinculo['slot_ativo'] ?? 0) === 1 ? 'sim' : 'nao',
    ];
    $resultado['contrato'] = $contrato === null ? null : [
        'status' => $contrato['status'],
        'modalidade' => $contrato['modalidade'],
        'motivo_suspensao' => $contrato['motivo_suspensao'],
        'valor_centavos' => pagamentoGatewayValorCentavos((string)$contrato['valor_contratado']),
        'periodicidade' => $contrato['periodicidade'],
    ];
    $resultado['transacoes'] = array_map(static fn(array $t): array => [
        'id_transacao' => (int)$t['id_transacao'],
        'status' => $t['status'],
        'fatura_id' => $t['fatura_externa_id'],
        'pagamento_id' => $t['pagamento_externo_id'],
        'status_externo' => $t['status_externo'],
        'detalhe' => $t['detalhe_status_externo'],
        'requer_conciliacao' => (int)$t['requer_conciliacao'] === 1 ? 'sim' : 'nao',
    ], assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT id_transacao, status, fatura_externa_id, pagamento_externo_id, status_externo, detalhe_status_externo, requer_conciliacao
           FROM transacao_pagamento
          WHERE id_empresa = ? AND id_assinatura_pagamento = ? AND tipo_operacao = 'assinatura_preapproval'
          ORDER BY id_transacao DESC
          LIMIT 20",
        'ii',
        $idEmpresa,
        $idVinculo
    ));

    if ($vinculo['assinatura_externa_id'] === null) {
        return $resultado + ['resultado' => 'sem_preapproval'];
    }
    $preapprovalId = (string)$vinculo['assinatura_externa_id'];
    $consulta = $gateway->consultarPreapproval($preapprovalId);
    $dados = $consulta['dados'] ?? null;
    if (($consulta['sucesso'] ?? false) !== true || !is_array($dados)) {
        return $resultado + ['resultado' => 'consulta_indisponivel',
            'codigo' => assinaturaServicoPreapprovalDiagnosticoCodigo($consulta['codigo'] ?? null)];
    }
    if (($dados['status'] ?? null) === 'cancelled') {
        $dados['status'] = 'canceled';
    }
    $recorrencia = $contrato === null ? null : assinaturaServicoPreapprovalRecorrencia(
        (string)$contrato['periodicidade'],
        assinaturaServicoDiasCicloContrato(
            $conexao,
            $idEmpresa,
            (int)$vinculo['id_assinatura'],
            (string)$contrato['periodicidade']
        )
    );
    $resultado['preapproval'] = assinaturaServicoPreapprovalDiagnosticoResumo($dados) + [
        'referencia_confere' => ($dados['external_reference'] ?? null) === $vinculo['referencia_externa_assinatura'] ? 'sim' : 'nao',
        'conferencia_contrato' => $contrato === null || $recorrencia === null ? 'CONTRATO_LOCAL_INDISPONIVEL'
            : (assinaturaServicoPreapprovalValidarExterna($dados, [
                'id' => $preapprovalId,
                'referencia' => (string)$vinculo['referencia_externa_assinatura'],
                'ambiente' => $ambiente,
                'valor_centavos' => pagamentoGatewayValorCentavos((string)$contrato['valor_contratado']),
                'frequencia_meses' => $recorrencia['frequencia'],
                'frequencia_tipo' => $recorrencia['tipo'],
                'status_permitidos' => ['pending', 'authorized', 'paused', 'canceled'],
            ]) ?? 'OK'),
    ];

    $contratantes = array_map(static fn(array $l): int => (int)$l['id_ator'], assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT DISTINCT id_ator FROM auditoria
          WHERE id_empresa = ? AND evento_codigo = 'assinatura.criada' AND entidade_id = ?
            AND ator_tipo = 'usuario' AND origem = 'empresa' AND id_ator IS NOT NULL",
        'ii',
        $idEmpresa,
        $idAssinatura
    ));
    $resultado['pagador'] = assinaturaServicoPreapprovalDiagnosticoPagador(
        $dados['payer_email'] ?? null,
        assinaturaServicoPreapprovalDiagnosticoProprietarios($conexao, $idEmpresa),
        $contratantes
    );

    $resultado['faturas'] = [];
    $busca = $gateway->buscarFaturasAutorizadas(['preapproval_id' => $preapprovalId]);
    if (($busca['sucesso'] ?? false) === true && is_array($busca['dados']['results'] ?? null)) {
        foreach ($busca['dados']['results'] as $item) {
            if (is_array($item) && (string)($item['preapproval_id'] ?? '') === $preapprovalId) {
                $resultado['faturas'][] = assinaturaServicoPreapprovalDiagnosticoFatura($item);
            }
        }
        $resultado['faturas_consulta'] = 'ok';
    } else {
        $resultado['faturas_consulta'] = assinaturaServicoPreapprovalDiagnosticoCodigo($busca['codigo'] ?? null) ?? 'indisponivel';
    }

    return $resultado + ['resultado' => 'ok'];
}
