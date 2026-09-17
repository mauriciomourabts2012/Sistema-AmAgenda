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
                a.status
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

    if ($idAssinatura === null || $idEmpresaInformada === null || !in_array($acao, ['suspender', 'reativar', 'cancelar'], true)) {
        out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'Dados da assinatura inválidos.'], 422);
    }

    if ($acao === 'cancelar' && ($motivo === '' || strlen($motivo) > 500)) {
        out(['ok' => false, 'code' => 'MOTIVO_OBRIGATORIO', 'user_msg' => 'Informe um motivo válido para cancelar a assinatura.'], 422);
    }

    try {
        $conexao->begin_transaction();

        // Empresa e assinatura são bloqueadas antes da transição para impedir decisões sobre estado desatualizado.
        $stmtEmpresa = $conexao->prepare(
            'SELECT id_empresa, nome, plano_id
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
            "SELECT id_assinatura, id_empresa, id_plano, status
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
        $statusNovo = match ($acao) {
            'suspender' => 'suspensa',
            'reativar' => 'ativa',
            'cancelar' => 'cancelada',
        };
        $transicaoPermitida = ($acao === 'suspender' && $statusAnterior === 'ativa')
            || ($acao === 'reativar' && $statusAnterior === 'suspensa')
            || ($acao === 'cancelar' && in_array($statusAnterior, ['ativa', 'suspensa'], true));

        if (!$transicaoPermitida) {
            assinaturaErroTransacional($conexao, ['ok' => false, 'code' => 'TRANSICAO_INVALIDA', 'user_msg' => 'A transição de status da assinatura não é permitida.'], 409);
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
        }

        $dataFim = null;
        if ($acao === 'cancelar') {
            $dataFim = date('Y-m-d');
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
        } else {
            $stmtUpdate = $conexao->prepare(
                "UPDATE assinatura
                    SET status = ?
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

        $evento = match ($acao) {
            'suspender' => 'assinatura.suspensa',
            'reativar' => 'assinatura.reativada',
            'cancelar' => 'assinatura.cancelada',
        };
        $contexto = [
            'origem' => 'painel_super_admin',
            'id_empresa' => $idEmpresaInformada,
            'id_assinatura' => $idAssinatura,
            'id_plano' => (int)$assinatura['id_plano'],
            'status_anterior' => $statusAnterior,
            'status_novo' => $statusNovo,
        ];
        if ($dataFim !== null) {
            $contexto['data_fim'] = $dataFim;
            $contexto['motivo'] = $motivo;
        }

        $alteracoesDados = [
            'id_empresa' => $idEmpresaInformada,
            'id_assinatura' => $idAssinatura,
            'id_plano' => (int)$assinatura['id_plano'],
            'status' => $statusNovo,
            'status_anterior' => $statusAnterior,
            'status_novo' => $statusNovo,
        ];
        if ($dataFim !== null) {
            $alteracoesDados['data_fim'] = $dataFim;
        }

        $alteracoes = assinaturaAlteracoes($alteracoesDados);
        $alteracoes['status'] = ['antes' => $statusAnterior, 'depois' => $statusNovo];

        auditoriaRegistrar($conexao, $evento, [
            'ator' => auditoriaResolverAtorSuperAdmin($conexao, $idEmpresaInformada),
            'entidade_id' => $idAssinatura,
            'entidade_rotulo' => 'Assinatura da empresa ' . (string)$empresa['nome'],
            'descricao' => match ($acao) {
                'suspender' => 'Suspendeu a assinatura da empresa ' . (string)$empresa['nome'] . '.',
                'reativar' => 'Reativou a assinatura da empresa ' . (string)$empresa['nome'] . '.',
                'cancelar' => 'Cancelou a assinatura da empresa ' . (string)$empresa['nome'] . '.',
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
            ],
        ]);
    } catch (Throwable $erro) {
        try {
            $conexao->rollback();
        } catch (Throwable) {
        }
        error_log('GESTAO_ASSINATURA_EXCEPTION: ' . $erro->getMessage());
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

if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
    out(['ok' => false, 'code' => 'DB_CONN_ERROR', 'user_msg' => 'Falha ao conectar no banco.'], 500);
}

$conexao->set_charset('utf8mb4');
assinaturaAlterarStatus($conexao);
