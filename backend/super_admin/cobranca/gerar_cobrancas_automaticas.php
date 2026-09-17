<?php

declare(strict_types=1);

// A rotina não possui rota pública para impedir geração por requisições web.
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Execução permitida somente em CLI.\n");
    exit(1);
}

date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/../../_config/conexao.php';
require_once __DIR__ . '/../../_servicos/auditoria.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const COBRANCAS_AUTOMATICAS_MAX_ITERACOES = 120;

/** @return DateTimeImmutable */
function cobrancasAutomaticasData(string $valor, string $campo): DateTimeImmutable
{
    $data = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    $erros = DateTimeImmutable::getLastErrors();

    if ($data === false || ($erros !== false && ($erros['warning_count'] > 0 || $erros['error_count'] > 0))) {
        throw new RuntimeException("Data inválida em {$campo}.");
    }

    return $data;
}

function cobrancasAutomaticasMesesPeriodicidade(string $periodicidade): int
{
    return match ($periodicidade) {
        'mensal' => 1,
        'trimestral' => 3,
        'semestral' => 6,
        'anual' => 12,
        default => throw new RuntimeException('Periodicidade da assinatura inválida.'),
    };
}

function cobrancasAutomaticasCalcularVencimento(DateTimeImmutable $periodoInicio, int $diaVencimento): DateTimeImmutable
{
    if ($diaVencimento < 1 || $diaVencimento > 28) {
        throw new RuntimeException('Dia de vencimento da assinatura inválido.');
    }

    $vencimento = $periodoInicio->modify('first day of this month')->setDate(
        (int) $periodoInicio->format('Y'),
        (int) $periodoInicio->format('m'),
        $diaVencimento
    );

    if ($vencimento < $periodoInicio) {
        $vencimento = $periodoInicio->modify('first day of next month')->setDate(
            (int) $periodoInicio->modify('first day of next month')->format('Y'),
            (int) $periodoInicio->modify('first day of next month')->format('m'),
            $diaVencimento
        );
    }

    return $vencimento;
}

/** @param array<string, mixed> $dados @return array<string, array{antes: null, depois: mixed}> */
function cobrancasAutomaticasAlteracoes(array $dados): array
{
    $alteracoes = [];

    foreach ($dados as $campo => $valor) {
        $alteracoes[$campo] = ['antes' => null, 'depois' => $valor];
    }

    return $alteracoes;
}

function cobrancasAutomaticasRollback(mysqli $conexao): void
{
    try {
        $conexao->rollback();
    } catch (Throwable) {
    }
}

function cobrancasAutomaticasPossuiCobrancaDoPeriodo(
    mysqli $conexao,
    int $idAssinatura,
    string $periodoInicio,
    string $periodoFim
): bool {
    $consulta = $conexao->prepare(
        'SELECT id_cobranca
         FROM cobranca
         WHERE id_assinatura = ?
           AND periodo_inicio = ?
           AND periodo_fim = ?
         LIMIT 1
         FOR UPDATE'
    );
    $consulta->bind_param('iss', $idAssinatura, $periodoInicio, $periodoFim);
    $consulta->execute();
    $resultado = $consulta->get_result();
    $existe = $resultado->num_rows > 0;
    $consulta->close();

    return $existe;
}

$resumo = [
    'assinaturas_analisadas' => 0,
    'cobrancas_geradas' => 0,
    'sem_cobranca_necessaria' => 0,
    'inconsistencias' => 0,
    'erros' => 0,
];

try {
    if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
        throw new RuntimeException('Conexão indisponível.');
    }

    $conexao->set_charset('utf8mb4');
    $assinaturasAtivas = $conexao->query(
        "SELECT id_assinatura, id_empresa
         FROM assinatura
         WHERE status = 'ativa'
         ORDER BY id_empresa ASC, id_assinatura ASC"
    );

    if ($assinaturasAtivas === false) {
        throw new RuntimeException('Não foi possível consultar as assinaturas.');
    }

    $assinaturas = $assinaturasAtivas->fetch_all(MYSQLI_ASSOC);
    $assinaturasAtivas->free();
    $quantidadeAtivasPorEmpresa = [];

    foreach ($assinaturas as $assinatura) {
        $idEmpresa = (int) $assinatura['id_empresa'];
        $quantidadeAtivasPorEmpresa[$idEmpresa] = ($quantidadeAtivasPorEmpresa[$idEmpresa] ?? 0) + 1;
    }

    $empresasInconsistentes = [];

    foreach ($quantidadeAtivasPorEmpresa as $idEmpresa => $quantidade) {
        if ($quantidade > 1) {
            $empresasInconsistentes[(int) $idEmpresa] = true;
            $resumo['inconsistencias']++;
        }
    }

    foreach ($assinaturas as $referencia) {
        $idAssinaturaReferencia = (int) $referencia['id_assinatura'];
        $idEmpresaReferencia = (int) $referencia['id_empresa'];
        $resumo['assinaturas_analisadas']++;

        if (isset($empresasInconsistentes[$idEmpresaReferencia])) {
            continue;
        }

        $cobrancasGeradasNestaAssinatura = 0;

        while (true) {
            if ($cobrancasGeradasNestaAssinatura >= COBRANCAS_AUTOMATICAS_MAX_ITERACOES) {
                // Evita processamento ilimitado quando dados corrompidos impedem o avanço do período.
                $resumo['inconsistencias']++;
                $resumo['erros']++;
                break;
            }

            try {
                $conexao->begin_transaction();

                // O bloqueio mantém o cálculo do próximo período consistente entre execuções concorrentes.
                $consultaAssinatura = $conexao->prepare(
                    "SELECT id_assinatura, id_empresa, valor_contratado, periodicidade, dia_vencimento,
                            DATE_FORMAT(data_inicio, '%Y-%m-%d') AS data_inicio
                     FROM assinatura
                     WHERE id_assinatura = ?
                       AND status = 'ativa'
                     LIMIT 1
                     FOR UPDATE"
                );
                $consultaAssinatura->bind_param('i', $idAssinaturaReferencia);
                $consultaAssinatura->execute();
                $assinaturaBloqueada = $consultaAssinatura->get_result()->fetch_assoc();
                $consultaAssinatura->close();

                if ($assinaturaBloqueada === null) {
                    cobrancasAutomaticasRollback($conexao);

                    if ($cobrancasGeradasNestaAssinatura === 0) {
                        $resumo['sem_cobranca_necessaria']++;
                    }

                    break;
                }

                $idAssinatura = (int) $assinaturaBloqueada['id_assinatura'];
                $idEmpresa = (int) $assinaturaBloqueada['id_empresa'];
                $consultaAtivasEmpresa = $conexao->prepare(
                    "SELECT id_assinatura
                     FROM assinatura
                     WHERE id_empresa = ?
                       AND status = 'ativa'
                     FOR UPDATE"
                );
                $consultaAtivasEmpresa->bind_param('i', $idEmpresa);
                $consultaAtivasEmpresa->execute();
                $quantidadeAtivas = $consultaAtivasEmpresa->get_result()->num_rows;
                $consultaAtivasEmpresa->close();

                if ($quantidadeAtivas !== 1) {
                    cobrancasAutomaticasRollback($conexao);
                    if (!isset($empresasInconsistentes[$idEmpresa])) {
                        $empresasInconsistentes[$idEmpresa] = true;
                        $resumo['inconsistencias']++;
                    }
                    break;
                }

                $consultaUltimaCobranca = $conexao->prepare(
                    'SELECT DATE_FORMAT(periodo_fim, \'%Y-%m-%d\') AS periodo_fim
                     FROM cobranca
                     WHERE id_assinatura = ?
                     ORDER BY periodo_fim DESC, id_cobranca DESC
                     LIMIT 1
                     FOR UPDATE'
                );
                $consultaUltimaCobranca->bind_param('i', $idAssinatura);
                $consultaUltimaCobranca->execute();
                $ultimaCobranca = $consultaUltimaCobranca->get_result()->fetch_assoc();
                $consultaUltimaCobranca->close();

                // Cobranças canceladas também ocupam o período e não podem ser geradas novamente.
                $periodoInicio = $ultimaCobranca === null
                    ? cobrancasAutomaticasData((string) $assinaturaBloqueada['data_inicio'], 'data_inicio')
                    : cobrancasAutomaticasData((string) $ultimaCobranca['periodo_fim'], 'periodo_fim')->modify('+1 day');
                $hoje = new DateTimeImmutable('today');

                if ($periodoInicio > $hoje) {
                    cobrancasAutomaticasRollback($conexao);

                    if ($cobrancasGeradasNestaAssinatura === 0) {
                        $resumo['sem_cobranca_necessaria']++;
                    }

                    break;
                }

                $meses = cobrancasAutomaticasMesesPeriodicidade((string) $assinaturaBloqueada['periodicidade']);
                $periodoFim = $periodoInicio->modify("+{$meses} months")->modify('-1 day');
                $diaVencimento = (int) $assinaturaBloqueada['dia_vencimento'];
                $dataVencimento = cobrancasAutomaticasCalcularVencimento($periodoInicio, $diaVencimento);
                $valor = trim((string) $assinaturaBloqueada['valor_contratado']);

                if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $valor)) {
                    throw new RuntimeException('Valor contratado da assinatura inválido.');
                }

                $periodoInicioSql = $periodoInicio->format('Y-m-d');
                $periodoFimSql = $periodoFim->format('Y-m-d');
                $dataVencimentoSql = $dataVencimento->format('Y-m-d');

                if (cobrancasAutomaticasPossuiCobrancaDoPeriodo($conexao, $idAssinatura, $periodoInicioSql, $periodoFimSql)) {
                    cobrancasAutomaticasRollback($conexao);
                    $resumo['inconsistencias']++;
                    break;
                }

                $status = 'pendente';
                $inserirCobranca = $conexao->prepare(
                    'INSERT INTO cobranca
                        (id_empresa, id_assinatura, periodo_inicio, periodo_fim, data_vencimento, valor, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $inserirCobranca->bind_param(
                    'iisssss',
                    $idEmpresa,
                    $idAssinatura,
                    $periodoInicioSql,
                    $periodoFimSql,
                    $dataVencimentoSql,
                    $valor,
                    $status
                );

                try {
                    $inserirCobranca->execute();
                } catch (mysqli_sql_exception $erro) {
                    $inserirCobranca->close();

                    if ((int) $erro->getCode() === 1062) {
                        cobrancasAutomaticasRollback($conexao);
                        $resumo['inconsistencias']++;
                        break;
                    }

                    throw $erro;
                }

                $idCobranca = (int) $conexao->insert_id;
                $inserirCobranca->close();
                auditoriaRegistrar($conexao, 'cobranca.gerada', [
                    'ator' => auditoriaResolverAtorSistema($conexao, $idEmpresa),
                    'entidade_id' => $idCobranca,
                    'entidade_rotulo' => "Cobrança automática da empresa #{$idEmpresa}",
                    'descricao' => "Gerou cobrança automática para a empresa #{$idEmpresa}.",
                    'alteracoes' => cobrancasAutomaticasAlteracoes([
                        'id_empresa' => $idEmpresa,
                        'id_assinatura' => $idAssinatura,
                        'periodo_inicio' => $periodoInicioSql,
                        'periodo_fim' => $periodoFimSql,
                        'data_vencimento' => $dataVencimentoSql,
                        'valor' => $valor,
                        'status' => $status,
                    ]),
                    'contexto' => [
                        'origem' => 'geracao_automatica',
                        'origem_geracao' => 'automatica',
                    ],
                ]);
                $conexao->commit();

                $resumo['cobrancas_geradas']++;
                $cobrancasGeradasNestaAssinatura++;
            } catch (Throwable) {
                cobrancasAutomaticasRollback($conexao);
                $resumo['erros']++;
                fwrite(STDERR, "Erro ao processar assinatura #{$idAssinaturaReferencia}.\n");
                break;
            }
        }
    }
} catch (Throwable) {
    fwrite(STDERR, "Falha global ao preparar a execução automática.\n");
    exit(1);
}

echo "Assinaturas analisadas: {$resumo['assinaturas_analisadas']}\n";
echo "Cobranças geradas: {$resumo['cobrancas_geradas']}\n";
echo "Sem cobrança necessária: {$resumo['sem_cobranca_necessaria']}\n";
echo "Inconsistências: {$resumo['inconsistencias']}\n";
echo "Erros: {$resumo['erros']}\n";
