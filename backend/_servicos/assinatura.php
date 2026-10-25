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
    if (!in_array($periodicidade, ['mensal', 'trimestral', 'semestral', 'anual'], true)) {
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

/** Último dia de cobertura contínua e integralmente paga a partir de hoje. */
function assinaturaServicoFimPeriodoPago(mysqli $conexao, int $idEmpresa, int $idAssinatura): ?string
{
    $hoje = date('Y-m-d');
    $cobrancas = assinaturaServicoLinhas(
        $conexao,
        "SELECT id_cobranca, periodo_inicio, periodo_fim, valor, status
           FROM cobranca
          WHERE id_empresa = ? AND id_assinatura = ? AND finalidade = 'regular'
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

    $existente = assinaturaServicoBuscarCobrancaConversaoAtiva($conexao, $idAssinatura);
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
    $meses = assinaturaServicoMesesPeriodicidade((string)$assinatura['periodicidade']);
    $periodoFim = $periodoInicio->modify('+' . $meses . ' months')->modify('-1 day');
    $vencimento = assinaturaServicoCalcularVencimento($periodoInicio, (int)$assinatura['dia_vencimento']);
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
    $incertas = assinaturaServicoConsultaFinanceira(
        $conexao,
        "SELECT t.id_transacao
           FROM transacao_pagamento t
          INNER JOIN cobranca c ON c.id_cobranca = t.id_cobranca AND c.id_empresa = t.id_empresa
          WHERE t.id_empresa = ?
            AND c.status <> 'cancelada'
            AND (t.status IN ('criada', 'enviando', 'aguardando', 'processando', 'conciliacao')
                 OR t.requer_conciliacao = 1
                 OR (t.status = 'confirmada' AND t.id_pagamento IS NULL))
          LIMIT 1",
        'i',
        $idEmpresa
    );
    if ($incertas !== [] || $divergente) {
        return [
            'tipo' => 'incerto',
            'codigo' => 'PAGAMENTO_AGUARDANDO_CONFIRMACAO',
            'mensagem' => 'Reativação bloqueada: há pagamento aguardando confirmação ou conciliação. Tente novamente após a confirmação.',
            'id_cobranca' => null,
        ];
    }

    return null;
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
 * público (ativo, disponível ao público, com proprietário e usuários), com cobrança e preço positivos, e que
 * comportam os usuários atuais sem bloqueio. Com $idPlano, valida somente esse plano (com lock).
 *
 * @return list<array{id_plano: int, nome: string, valor: string, periodicidade: string}>
 */
function assinaturaServicoNovaContratacaoPlanos(mysqli $conexao, int $idEmpresa, ?int $idPlano = null): array
{
    $sql = "SELECT id_plano, nome, preco_mensal, cobranca, limite_usuarios, limite_proprietarios,
                   limite_profissionais, limite_recepcionistas
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
        $sql .= ' ORDER BY preco_mensal ASC, id_plano ASC';
    }

    $planos = [];
    foreach (assinaturaServicoConsultaFinanceira($conexao, $sql, $tipos, ...$valores) as $plano) {
        $valor = trim((string)$plano['preco_mensal']);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $valor) || pagamentoGatewayValorCentavos($valor) <= 0
            || !in_array((string)$plano['cobranca'], ['mensal', 'trimestral', 'semestral', 'anual'], true)
            || (int)$plano['limite_proprietarios'] < 1 || (int)$plano['limite_usuarios'] < 1
            || !assinaturaServicoPlanoComportaEmpresa($conexao, $idEmpresa, $plano)) {
            continue;
        }
        $planos[] = [
            'id_plano' => (int)$plano['id_plano'],
            'nome' => (string)$plano['nome'],
            'valor' => pagamentoGatewayValorDecimal(pagamentoGatewayValorCentavos($valor)),
            'periodicidade' => (string)$plano['cobranca'],
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

    return assinaturaServicoNovaContratacaoResultado(false, 'PAGAMENTO_AGUARDANDO_CONFIRMACAO', 'Há pagamento aguardando confirmação ou conciliação. Tente novamente após a confirmação.');
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
 * Gera a cobrança do primeiro período (oferta) do contrato pendente: começa hoje, vencimento pelo dia
 * contratado, finalidade regular. O acesso só é liberado após a quitação integral dela.
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
    $meses = assinaturaServicoMesesPeriodicidade((string)$assinatura['periodicidade']);
    $periodoInicio = $inicio->format('Y-m-d');
    $periodoFim = $inicio->modify("+{$meses} months")->modify('-1 day')->format('Y-m-d');
    $vencimento = assinaturaServicoCalcularVencimento($inicio, (int)$assinatura['dia_vencimento'])->format('Y-m-d');

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
        $criada = assinaturaServicoCriar($conexao, [
            'id_empresa' => $idEmpresa,
            'id_plano' => $idPlano,
            'valor_contratado' => (float)$plano['valor'],
            'periodicidade' => $plano['periodicidade'],
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
        // Falha fechada: se a coluna não aceitar o novo motivo (ex.: ENUM sem a opção), nada é gravado.
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
        assinaturaServicoAuditar($conexao, 'assinatura.criada', [
            'entidade_id' => $idNova,
            'entidade_rotulo' => 'Nova contratação da empresa ' . (string)$estado['empresa']['nome'],
            'descricao' => 'Criou nova contratação aguardando o pagamento do primeiro período.',
            'alteracoes' => assinaturaServicoAlteracoesNovas([
                'id_empresa' => $idEmpresa,
                'id_plano' => $idPlano,
                'valor_contratado' => (string)$conferencia[0]['valor_contratado'],
                'periodicidade' => $plano['periodicidade'],
                'dia_vencimento' => $diaVencimento,
                'data_inicio' => $hoje,
                'status' => 'suspensa',
            ]),
            'contexto' => ['origem' => 'nova_contratacao', 'id_empresa' => $idEmpresa, 'id_assinatura' => $idNova,
                'id_plano' => $idPlano, 'status_novo' => 'suspensa', 'motivo' => 'contratacao_pendente'],
        ], $ator);
        $assinatura = [
            'id_assinatura' => $idNova,
            'valor_contratado' => (string)$conferencia[0]['valor_contratado'],
            'periodicidade' => $plano['periodicidade'],
            'dia_vencimento' => $diaVencimento,
        ];
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
        return assinaturaServicoNovaContratacaoResultado(false, 'PAGAMENTO_PENDENTE', 'O pagamento da cobrança de contratação ainda não foi confirmado.');
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

    $dataInicio = (string)$oferta['periodo_inicio'];
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

    return assinaturaServicoNovaContratacaoResultado(true, null, 'Nova contratação ativada. O acesso foi liberado.', [
        'resultado' => 'ativada',
        'id_assinatura' => $idAssinatura,
        'id_cobranca' => (int)$oferta['id_cobranca'],
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
                    'data_vencimento' => $oferta['data_vencimento'] ?? null,
                    'paga' => $paga,
                ];
                $situacao['pode_concluir'] = $bloqueio === null && $paga;
                $situacao['mensagem'] = $paga
                    ? 'Pagamento confirmado. Conclua a contratação para liberar o acesso.'
                    : 'Nova contratação aguardando o pagamento da cobrança do primeiro período.';
            }
            if ($bloqueio !== null) {
                $situacao['codigo'] = $bloqueio['codigo'];
                $situacao['mensagem'] = $bloqueio['mensagem'];
            } elseif ($pendente === null && !assinaturaServicoColunaAceita($conexao, 'assinatura', 'motivo_suspensao', 'contratacao_pendente')) {
                $situacao['codigo'] = 'ESTRUTURA_PENDENTE';
                $situacao['mensagem'] = 'A nova contratação ainda não está disponível. Entre em contato com o suporte.';
            } elseif (!$situacao['pode_concluir']) {
                $situacao['planos'] = assinaturaServicoNovaContratacaoPlanos($conexao, $idEmpresa);
                $situacao['disponivel'] = $situacao['planos'] !== [];
                if ($situacao['planos'] === [] && $pendente === null) {
                    $situacao['codigo'] = 'PLANO_INDISPONIVEL';
                    $situacao['mensagem'] = 'Nenhum plano disponível comporta os usuários ativos da empresa. Entre em contato com o suporte.';
                }
            }
        }
        $cartao = assinaturaServicoConsultaFinanceira(
            $conexao,
            "SELECT 1 FROM assinatura_pagamento_recorrente
              WHERE id_empresa = ? AND status IN ('cancelamento_pendente', 'pendente', 'ativa', 'suspensa', 'atualizacao_necessaria')
              LIMIT 1",
            'i',
            $idEmpresa
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
    $sql = "SELECT id_plano, nome, preco_mensal, cobranca, limite_usuarios, limite_proprietarios,
                   limite_profissionais, limite_recepcionistas
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
        $sql .= ' ORDER BY preco_mensal ASC, id_plano ASC';
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

        return assinaturaServicoNovaContratacaoResultado(false, 'TROCA_PENDENTE', 'Já existe uma troca de plano em andamento. Cancele-a antes de solicitar outra.');
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
    $stmt = $conexao->prepare(
        "INSERT INTO assinatura_troca_plano
            (id_empresa, id_assinatura, tipo, status, id_plano_anterior, id_plano_novo, valor_anterior, valor_novo, periodicidade,
             valor_ciclo, ciclo_inicio, ciclo_fim, dias_ciclo, dias_restantes, valor_proporcional, expira_em)
         VALUES (?, ?, 'upgrade', 'aguardando_pagamento', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar a solicitação de upgrade.');
    }
    $idPlanoAnterior = (int)$assinatura['id_plano'];
    $valorNovo = (string)$plano['valor'];
    $periodicidade = (string)$assinatura['periodicidade'];
    $valorCiclo = (string)$previa['valor_atual'];
    $cicloInicio = (string)$previa['ciclo_inicio'];
    $cicloFim = (string)$previa['ciclo_fim'];
    $diasCiclo = (int)$previa['dias_ciclo'];
    $diasRestantes = (int)$previa['dias_restantes'];
    $valorProporcional = (string)$previa['valor_proporcional'];
    $stmt->bind_param('iiiissssssiiss', $idEmpresa, $idAssinatura, $idPlanoAnterior, $idPlano, $valorAnterior, $valorNovo, $periodicidade,
        $valorCiclo, $cicloInicio, $cicloFim, $diasCiclo, $diasRestantes, $valorProporcional, $expiraEm);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Falha ao registrar a solicitação de upgrade.');
    }
    $idTroca = (int)$stmt->insert_id;
    $stmt->close();

    if ((int)$previa['valor_proporcional_centavos'] > 0) {
        $finalidade = ASSINATURA_TROCA_FINALIDADE_UPGRADE;
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
        } catch (mysqli_sql_exception $erro) {
            $ok = false;
            $errno = (int)$erro->getCode();
        }
        if (!$ok) {
            $stmt->close();
            if ($errno === 1062) {
                return assinaturaServicoNovaContratacaoResultado(false, 'COBRANCA_CONFLITANTE', 'Não foi possível gerar a cobrança proporcional hoje. Tente novamente amanhã ou entre em contato com o suporte.');
            }
            throw new RuntimeException('Falha ao criar a cobrança proporcional.', $errno);
        }
        $idCobranca = (int)$stmt->insert_id;
        $stmt->close();
        $stmt = $conexao->prepare('UPDATE assinatura_troca_plano SET id_cobranca = ? WHERE id_troca = ? AND id_cobranca IS NULL');
        if (!$stmt) {
            throw new RuntimeException('Falha ao vincular a cobrança proporcional.');
        }
        $stmt->bind_param('ii', $idCobranca, $idTroca);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('Falha ao vincular a cobrança proporcional.');
        }
        $stmt->close();
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

    return assinaturaServicoNovaContratacaoResultado(true, null, (string)$previa['mensagem'], [
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
            'opcoes' => [],
            'pendente' => null,
        ];
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
