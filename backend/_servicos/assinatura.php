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
        "SELECT id_assinatura, id_plano, status, modalidade, motivo_suspensao,
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
