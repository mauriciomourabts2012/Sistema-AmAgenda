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

function assinaturaErroTransacional(mysqli $conexao, array $payload, int $code): void
{
    try {
        $conexao->rollback();
    } catch (Throwable) {
    }

    out($payload, $code);
}

function assinaturaIdEntrada(array $origem, string $campo): ?int
{
    $valor = $origem[$campo] ?? null;
    if (!is_scalar($valor) || preg_match('/^[1-9]\d*$/', trim((string)$valor)) !== 1) {
        return null;
    }

    return (int)$valor;
}

function assinaturaAlteracoes(array $dados): array
{
    $resultado = [];
    foreach ($dados as $campo => $valor) {
        $resultado[$campo] = ['antes' => null, 'depois' => $valor];
    }

    return $resultado;
}

function assinaturaListar(mysqli $conexao): void
{
    $idEmpresa = assinaturaIdEntrada($_GET, 'id_empresa');
    $sql = "SELECT
                a.id_assinatura,
                a.id_empresa,
                e.nome AS empresa_nome,
                a.id_plano,
                p.nome AS plano_nome,
                a.valor_contratado,
                a.periodicidade,
                a.dia_vencimento,
                DATE_FORMAT(a.data_inicio, '%Y-%m-%d') AS data_inicio,
                DATE_FORMAT(a.data_fim, '%Y-%m-%d') AS data_fim,
                a.status,
                a.modalidade,
                DATE_FORMAT(a.teste_iniciado_em, '%Y-%m-%d %H:%i:%s') AS teste_iniciado_em,
                DATE_FORMAT(a.teste_expira_em, '%Y-%m-%d %H:%i:%s') AS teste_expira_em,
                a.motivo_suspensao,
                DATE_FORMAT(a.suspensa_em, '%Y-%m-%d %H:%i:%s') AS suspensa_em
            FROM assinatura a
            INNER JOIN empresa e ON e.id_empresa = a.id_empresa
            LEFT JOIN plano p ON p.id_plano = a.id_plano";

    if ($idEmpresa !== null) {
        $sql .= ' WHERE a.id_empresa = ?';
    }

    $sql .= " ORDER BY e.nome ASC,
                       CASE a.status
                           WHEN 'ativa' THEN 1
                           WHEN 'suspensa' THEN 2
                           WHEN 'cancelada' THEN 3
                           WHEN 'encerrada' THEN 4
                           ELSE 5
                       END,
                       a.data_inicio DESC,
                       a.id_assinatura DESC";

    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar a listagem de assinaturas.');
    }

    if ($idEmpresa !== null) {
        $stmt->bind_param('i', $idEmpresa);
    }

    if (!$stmt->execute()) {
        $erro = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Falha ao listar assinaturas: ' . $erro);
    }

    $resultado = $stmt->get_result();
    $items = [];
    while ($linha = $resultado->fetch_assoc()) {
        $items[] = [
            'id_assinatura' => (int)$linha['id_assinatura'],
            'id_empresa' => (int)$linha['id_empresa'],
            'empresa_nome' => (string)$linha['empresa_nome'],
            'id_plano' => (int)$linha['id_plano'],
            'plano_nome' => $linha['plano_nome'] === null ? '' : (string)$linha['plano_nome'],
            'valor_contratado' => (float)$linha['valor_contratado'],
            'periodicidade' => (string)$linha['periodicidade'],
            'dia_vencimento' => (int)$linha['dia_vencimento'],
            'data_inicio' => (string)$linha['data_inicio'],
            'data_fim' => $linha['data_fim'] === null ? null : (string)$linha['data_fim'],
            'status' => (string)$linha['status'],
            'modalidade' => (string)$linha['modalidade'],
            'teste_iniciado_em' => $linha['teste_iniciado_em'] === null ? null : (string)$linha['teste_iniciado_em'],
            'teste_expira_em' => $linha['teste_expira_em'] === null ? null : (string)$linha['teste_expira_em'],
            'motivo_suspensao' => $linha['motivo_suspensao'] === null ? null : (string)$linha['motivo_suspensao'],
            'suspensa_em' => $linha['suspensa_em'] === null ? null : (string)$linha['suspensa_em'],
        ];
    }
    $stmt->close();

    out([
        'ok' => true,
        'code' => 'ASSINATURAS_LISTADAS',
        'user_msg' => 'Assinaturas listadas com sucesso.',
        'data' => ['items' => $items],
    ]);
}

function assinaturaAlterarStatus(mysqli $conexao): void
{
    require_once __DIR__ . '/../../_auth/csrf.php';
    csrfValidarSessao();

    $idAssinatura = assinaturaIdEntrada($_POST, 'id_assinatura');
    $idEmpresaInformada = assinaturaIdEntrada($_POST, 'id_empresa');
    $acao = is_scalar($_POST['acao'] ?? null) ? strtolower(trim((string)$_POST['acao'])) : '';
    $motivo = is_scalar($_POST['motivo'] ?? null) ? trim((string)$_POST['motivo']) : '';
    $modalidadeCancelamento = is_scalar($_POST['modalidade_cancelamento'] ?? null)
        ? trim((string)$_POST['modalidade_cancelamento']) : '';

    if ($idAssinatura === null || $idEmpresaInformada === null || !in_array($acao, ['suspender', 'reativar', 'cancelar'], true)) {
        out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Dados da assinatura inválidos.'], 422);
    }

    if ($acao === 'cancelar' && ($motivo === '' || strlen($motivo) > 500)) {
        out(['ok' => false, 'code' => 'MOTIVO_OBRIGATORIO', 'user_msg' => 'Informe um motivo válido para cancelar a assinatura.'], 422);
    }
    if ($acao === 'cancelar' && !in_array($modalidadeCancelamento, ['imediato', 'fim_periodo_pago'], true)) {
        out(['ok' => false, 'code' => 'MODALIDADE_CANCELAMENTO_INVALIDA', 'user_msg' => 'Escolha a modalidade do cancelamento.'], 422);
    }

    try {
        $conexao->begin_transaction();

        // Empresa e assinatura são bloqueadas antes da transição para impedir decisões sobre estado desatualizado.
        $stmtEmpresa = $conexao->prepare(
            'SELECT id_empresa, nome, plano_id, status
               FROM empresa
              WHERE id_empresa = ?
              LIMIT 1
              FOR UPDATE'
        );
        if (!$stmtEmpresa) {
            throw new RuntimeException('Falha ao preparar o bloqueio da empresa.');
        }
        $stmtEmpresa->bind_param('i', $idEmpresaInformada);
        if (!$stmtEmpresa->execute()) {
            $erro = $stmtEmpresa->error;
            $stmtEmpresa->close();
            throw new RuntimeException('Falha ao bloquear empresa: ' . $erro);
        }
        $empresa = $stmtEmpresa->get_result()->fetch_assoc();
        $stmtEmpresa->close();

        if (!$empresa) {
            assinaturaErroTransacional($conexao, ['ok' => false, 'code' => 'EMPRESA_NAO_ENCONTRADA', 'user_msg' => 'Empresa não encontrada.'], 404);
        }

        $stmtAssinatura = $conexao->prepare(
            "SELECT id_assinatura, id_empresa, id_plano, status, modalidade,
                    motivo_suspensao, suspensa_em, data_fim,
                    CASE WHEN teste_iniciado_em IS NOT NULL
                          AND teste_expira_em IS NOT NULL
                          AND teste_expira_em > teste_iniciado_em
                         THEN 1 ELSE 0 END AS teste_configurado,
                    CASE WHEN teste_expira_em IS NOT NULL
                          AND CURRENT_TIMESTAMP >= teste_expira_em
                         THEN 1 ELSE 0 END AS teste_expirado
               FROM assinatura
              WHERE id_assinatura = ?
              LIMIT 1
              FOR UPDATE"
        );
        if (!$stmtAssinatura) {
            throw new RuntimeException('Falha ao preparar o bloqueio da assinatura.');
        }
        $stmtAssinatura->bind_param('i', $idAssinatura);
        if (!$stmtAssinatura->execute()) {
            $erro = $stmtAssinatura->error;
            $stmtAssinatura->close();
            throw new RuntimeException('Falha ao bloquear assinatura: ' . $erro);
        }
        $assinatura = $stmtAssinatura->get_result()->fetch_assoc();
        $stmtAssinatura->close();

        if (!$assinatura || (int)$assinatura['id_empresa'] !== $idEmpresaInformada) {
            assinaturaErroTransacional($conexao, ['ok' => false, 'code' => 'ASSINATURA_NAO_ENCONTRADA', 'user_msg' => 'Assinatura não encontrada para esta empresa.'], 404);
        }

        $statusAnterior = (string)$assinatura['status'];
        if ($acao === 'cancelar' && $assinatura['data_fim'] !== null) {
            assinaturaErroTransacional($conexao, ['ok' => false, 'code' => 'CANCELAMENTO_JA_REGISTRADO', 'user_msg' => 'Esta assinatura já tem uma data de encerramento. Atualize a lista.'], 409);
        }
        if ($acao === 'reativar' && $assinatura['data_fim'] !== null) {
            assinaturaErroTransacional($conexao, ['ok' => false, 'code' => 'CANCELAMENTO_PROGRAMADO', 'user_msg' => 'Uma assinatura com encerramento programado não pode ser reativada.'], 409);
        }
        $statusNovo = match ($acao) {
            'suspender' => 'suspensa',
            'reativar' => 'ativa',
            'cancelar' => $modalidadeCancelamento === 'fim_periodo_pago' ? 'ativa' : 'cancelada',
        };
        $transicaoPermitida = ($acao === 'suspender' && $statusAnterior === 'ativa')
            || ($acao === 'reativar' && $statusAnterior === 'suspensa')
            || ($acao === 'cancelar' && in_array($statusAnterior, ['ativa', 'suspensa'], true));

        if (!$transicaoPermitida) {
            assinaturaErroTransacional($conexao, ['ok' => false, 'code' => 'TRANSICAO_INVALIDA', 'user_msg' => 'A transição de status da assinatura não é permitida.'], 409);
        }
        if ($acao === 'cancelar' && $modalidadeCancelamento === 'fim_periodo_pago' && $statusAnterior !== 'ativa') {
            assinaturaErroTransacional($conexao, ['ok' => false, 'code' => 'CANCELAMENTO_PROGRAMADO_INDISPONIVEL', 'user_msg' => 'O cancelamento ao fim do período pago exige assinatura ativa.'], 409);
        }

        if ($acao === 'reativar') {
            // A reativação não pode criar uma segunda assinatura ativa nem restaurar plano divergente.
            $stmtAtivas = $conexao->prepare(
                "SELECT id_assinatura
                   FROM assinatura
                  WHERE id_empresa = ?
                    AND status = 'ativa'
                    AND id_assinatura <> ?
                  FOR UPDATE"
            );
            if (!$stmtAtivas) {
                throw new RuntimeException('Falha ao preparar a validação de assinaturas ativas.');
            }
            $stmtAtivas->bind_param('ii', $idEmpresaInformada, $idAssinatura);
            if (!$stmtAtivas->execute()) {
                $erro = $stmtAtivas->error;
                $stmtAtivas->close();
                throw new RuntimeException('Falha ao validar assinaturas ativas: ' . $erro);
            }
            $quantidadeAtivas = $stmtAtivas->get_result()->num_rows;
            $stmtAtivas->close();

            if ($quantidadeAtivas > 0) {
                assinaturaErroTransacional($conexao, ['ok' => false, 'code' => 'ASSINATURA_ATIVA_EXISTENTE', 'user_msg' => 'A empresa já possui outra assinatura ativa.'], 409);
            }

            if ((int)$assinatura['id_plano'] !== (int)$empresa['plano_id']) {
                assinaturaErroTransacional($conexao, ['ok' => false, 'code' => 'PLANO_ASSINATURA_DIVERGENTE', 'user_msg' => 'A assinatura não corresponde ao plano operacional atual.'], 409);
            }

            // Segurança financeira: origem da suspensão, teste grátis, dívida e pagamento incerto.
            $bloqueioReativacao = assinaturaServicoBloqueioReativacao($conexao, $empresa, $assinatura);
            if ($bloqueioReativacao !== null) {
                assinaturaErroTransacional($conexao, ['ok' => false, 'code' => $bloqueioReativacao['code'], 'user_msg' => $bloqueioReativacao['user_msg']], 409);
            }
        }

        $operacoesEmAndamento = 0;
        if ($acao === 'cancelar') {
            $stmtOperacoes = $conexao->prepare(
                "SELECT COUNT(*) AS total FROM transacao_pagamento t
                   INNER JOIN cobranca c ON c.id_cobranca = t.id_cobranca AND c.id_empresa = t.id_empresa
                  WHERE c.id_empresa = ? AND c.id_assinatura = ?
                    AND (t.status IN ('criada','enviando','aguardando','processando','conciliacao')
                         OR t.requer_conciliacao = 1)"
            );
            if (!$stmtOperacoes) throw new RuntimeException('Falha ao consultar transações em andamento.');
            $stmtOperacoes->bind_param('ii', $idEmpresaInformada, $idAssinatura);
            if (!$stmtOperacoes->execute()) throw new RuntimeException('Falha ao consultar transações em andamento.');
            $operacoesEmAndamento = (int)$stmtOperacoes->get_result()->fetch_assoc()['total'];
            $stmtOperacoes->close();
        }

        $dataFim = null;
        $motivoSuspensao = $assinatura['motivo_suspensao'];
        $suspensaEm = $assinatura['suspensa_em'];
        if ($acao === 'cancelar') {
            if ($modalidadeCancelamento === 'fim_periodo_pago') {
                if ((string)$assinatura['modalidade'] !== 'paga') {
                    assinaturaErroTransacional($conexao, ['ok' => false, 'code' => 'SEM_PERIODO_PAGO', 'user_msg' => 'Esta assinatura não possui período pago válido. Use o cancelamento imediato.'], 409);
                }
                $dataFim = assinaturaServicoFimPeriodoPago($conexao, $idEmpresaInformada, $idAssinatura);
                if ($dataFim === null) {
                    assinaturaErroTransacional($conexao, ['ok' => false, 'code' => 'SEM_PERIODO_PAGO', 'user_msg' => 'Não há período integralmente pago que inclua a data atual.'], 409);
                }
            } else {
                $dataFim = date('Y-m-d');
            }
            $stmtUpdate = $conexao->prepare(
                "UPDATE assinatura
                    SET status = ?, data_fim = ?
                  WHERE id_assinatura = ?
                    AND status = ?"
            );
            if (!$stmtUpdate) {
                throw new RuntimeException('Falha ao preparar cancelamento da assinatura.');
            }
            $stmtUpdate->bind_param('ssis', $statusNovo, $dataFim, $idAssinatura, $statusAnterior);
        } elseif ($acao === 'suspender') {
            $motivoSuspensao = 'manual';
            $suspensaEm = date('Y-m-d H:i:s');
            $stmtUpdate = $conexao->prepare(
                "UPDATE assinatura
                    SET status = ?, motivo_suspensao = ?, suspensa_em = ?
                  WHERE id_assinatura = ?
                    AND status = ?"
            );
            if (!$stmtUpdate) {
                throw new RuntimeException('Falha ao preparar suspensão da assinatura.');
            }
            $stmtUpdate->bind_param('sssis', $statusNovo, $motivoSuspensao, $suspensaEm, $idAssinatura, $statusAnterior);
        } else {
            $motivoSuspensao = null;
            $suspensaEm = null;
            $stmtUpdate = $conexao->prepare(
                "UPDATE assinatura
                    SET status = ?, motivo_suspensao = NULL, suspensa_em = NULL
                  WHERE id_assinatura = ?
                    AND status = ?"
            );
            if (!$stmtUpdate) {
                throw new RuntimeException('Falha ao preparar alteração da assinatura.');
            }
            $stmtUpdate->bind_param('sis', $statusNovo, $idAssinatura, $statusAnterior);
        }

        if (!$stmtUpdate->execute()) {
            $erro = $stmtUpdate->error;
            $stmtUpdate->close();
            throw new RuntimeException('Falha ao atualizar assinatura: ' . $erro);
        }
        $alterados = $stmtUpdate->affected_rows;
        $stmtUpdate->close();

        if ($alterados !== 1) {
            assinaturaErroTransacional($conexao, ['ok' => false, 'code' => 'ASSINATURA_ALTERADA', 'user_msg' => 'A assinatura foi alterada por outra operação. Atualize a lista.'], 409);
        }

        if ($acao === 'cancelar') {
            // O perfil externo continua sujeito à conciliação; este bloqueio é somente da cobrança local.
            $stmtRecorrencia = $conexao->prepare(
                "UPDATE assinatura_pagamento_recorrente
                    SET status = 'cancelamento_pendente', slot_ativo = NULL, cobrar_a_partir_de = NULL
                  WHERE id_empresa = ? AND id_assinatura = ?
                    AND status IN ('pendente','ativa','suspensa','atualizacao_necessaria')"
            );
            if (!$stmtRecorrencia) throw new RuntimeException('Falha ao preparar bloqueio da recorrência.');
            $stmtRecorrencia->bind_param('ii', $idEmpresaInformada, $idAssinatura);
            if (!$stmtRecorrencia->execute()) throw new RuntimeException('Falha ao bloquear recorrência.');
            $stmtRecorrencia->close();
        }

        $evento = match ($acao) {
            'suspender' => 'assinatura.suspensa',
            'reativar' => 'assinatura.reativada',
            'cancelar' => $modalidadeCancelamento === 'fim_periodo_pago' ? 'assinatura.cancelamento_programado' : 'assinatura.cancelada',
        };
        $contexto = [
            'origem' => 'painel_super_admin',
            'id_empresa' => $idEmpresaInformada,
            'id_assinatura' => $idAssinatura,
            'id_plano' => (int)$assinatura['id_plano'],
            'modalidade' => (string)$assinatura['modalidade'],
            'status_anterior' => $statusAnterior,
            'status_novo' => $statusNovo,
        ];
        if ($dataFim !== null) {
            $contexto['data_fim'] = $dataFim;
            $contexto['motivo'] = $motivo;
            $contexto['modalidade_cancelamento'] = $modalidadeCancelamento;
        }
        if (in_array($acao, ['suspender', 'reativar'], true)) {
            $contexto['motivo_suspensao'] = $motivoSuspensao;
            $contexto['suspensa_em'] = $suspensaEm;
        }

        $alteracoesDados = [
            'id_empresa' => $idEmpresaInformada,
            'id_assinatura' => $idAssinatura,
            'id_plano' => (int)$assinatura['id_plano'],
            'modalidade' => (string)$assinatura['modalidade'],
            'status' => $statusNovo,
            'status_anterior' => $statusAnterior,
            'status_novo' => $statusNovo,
        ];
        if ($dataFim !== null) {
            $alteracoesDados['data_fim'] = $dataFim;
        }

        $alteracoes = assinaturaAlteracoes($alteracoesDados);
        $alteracoes['status'] = ['antes' => $statusAnterior, 'depois' => $statusNovo];
        if (in_array($acao, ['suspender', 'reativar'], true)) {
            $alteracoes['motivo_suspensao'] = [
                'antes' => $assinatura['motivo_suspensao'],
                'depois' => $motivoSuspensao,
            ];
            $alteracoes['suspensa_em'] = [
                'antes' => $assinatura['suspensa_em'],
                'depois' => $suspensaEm,
            ];
        }

        auditoriaRegistrar($conexao, $evento, [
            'ator' => auditoriaResolverAtorSuperAdmin($conexao, $idEmpresaInformada),
            'entidade_id' => $idAssinatura,
            'entidade_rotulo' => 'Assinatura da empresa ' . (string)$empresa['nome'],
            'descricao' => match ($acao) {
                'suspender' => 'Suspendeu a assinatura da empresa ' . (string)$empresa['nome'] . '.',
                'reativar' => 'Reativou a assinatura da empresa ' . (string)$empresa['nome'] . '.',
                'cancelar' => $modalidadeCancelamento === 'fim_periodo_pago'
                    ? 'Programou o cancelamento da assinatura da empresa ' . (string)$empresa['nome'] . ' para ' . $dataFim . '.'
                    : 'Cancelou imediatamente a assinatura da empresa ' . (string)$empresa['nome'] . '.',
            },
            'alteracoes' => $alteracoes,
            'contexto' => $contexto,
        ]);

        $conexao->commit();
        out([
            'ok' => true,
            'code' => 'ASSINATURA_STATUS_ATUALIZADO',
            'user_msg' => 'Status da assinatura atualizado com sucesso.',
            'data' => [
                'id_assinatura' => $idAssinatura,
                'id_empresa' => $idEmpresaInformada,
                'status_anterior' => $statusAnterior,
                'status_novo' => $statusNovo,
                'data_fim' => $dataFim,
                'modalidade_cancelamento' => $modalidadeCancelamento,
                'operacoes_em_andamento' => $operacoesEmAndamento,
                'modalidade' => (string)$assinatura['modalidade'],
                'motivo_suspensao' => $motivoSuspensao,
                'suspensa_em' => $suspensaEm,
            ],
        ]);
    } catch (Throwable $erro) {
        try {
            $conexao->rollback();
        } catch (Throwable) {
        }
        error_log('GESTAO_ASSINATURA_EXCEPTION: ' . $erro->getMessage());
        if (in_array($erro->getCode(), [1205, 1213], true)) {
            // Deadlock ou timeout de lock com operação financeira concorrente: nada foi alterado; basta repetir.
            out(['ok' => false, 'code' => 'FINANCEIRO_EM_ATUALIZACAO', 'user_msg' => 'O financeiro da empresa está sendo atualizado por outra operação. Tente novamente em instantes.'], 409);
        }
        out(['ok' => false, 'code' => 'ASSINATURA_STATUS_ERROR', 'user_msg' => 'Não foi possível atualizar a assinatura.'], 500);
    }
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    require __DIR__ . '/../../_auth/bloquear.php';
    require_once __DIR__ . '/../../_config/conexao.php';
    require_once __DIR__ . '/../../_servicos/auditoria.php';
    if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
        out(['ok' => false, 'code' => 'DB_CONN_ERROR', 'user_msg' => 'Falha ao conectar no banco.'], 500);
    }
    $conexao->set_charset('utf8mb4');
    try {
        assinaturaListar($conexao);
    } catch (Throwable $erro) {
        error_log('LISTA_ASSINATURA_EXCEPTION: ' . $erro->getMessage());
        out(['ok' => false, 'code' => 'ASSINATURA_LIST_ERROR', 'user_msg' => 'Não foi possível listar as assinaturas.'], 500);
    }
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    out(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED', 'user_msg' => 'Método não permitido.'], 405);
}

require __DIR__ . '/../../_auth/bloquear.php';
require_once __DIR__ . '/../../_config/conexao.php';
require_once __DIR__ . '/../../_servicos/auditoria.php';
require_once __DIR__ . '/../../_servicos/assinatura.php';

if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
    out(['ok' => false, 'code' => 'DB_CONN_ERROR', 'user_msg' => 'Falha ao conectar no banco.'], 500);
}

$conexao->set_charset('utf8mb4');
assinaturaAlterarStatus($conexao);
