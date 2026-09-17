<?php
declare(strict_types=1);

/**
 * Valida o acesso contratual da empresa a partir do estado atual da assinatura.
 * A empresa precisa ter exatamente uma assinatura ativa; registros históricos
 * não substituem a contratação vigente. O estado operacional de empresa
 * continua sendo validado separadamente pelos fluxos de autenticação.
 */
function acessoAssinaturaValidar(mysqli $conexao, int $idEmpresa): array
{
    $mensagemSuspensa = 'Assinatura temporariamente suspensa. Entre em contato com o suporte.';
    $mensagemInativa = 'Assinatura não está ativa. Entre em contato com o suporte.';

    if ($idEmpresa <= 0) {
        return [
            'permitido' => false,
            'motivo' => 'contexto_invalido',
            'user_msg' => $mensagemInativa,
        ];
    }

    $stmt = $conexao->prepare(
        'SELECT status
           FROM assinatura
          WHERE id_empresa = ?'
    );

    if (!$stmt) {
        return [
            'permitido' => false,
            'motivo' => 'erro_validacao',
            'erro_tecnico' => true,
            'user_msg' => 'Não foi possível validar a assinatura da empresa.',
        ];
    }

    $stmt->bind_param('i', $idEmpresa);

    if (!$stmt->execute() || !$stmt->bind_result($status)) {
        $stmt->close();

        return [
            'permitido' => false,
            'motivo' => 'erro_validacao',
            'erro_tecnico' => true,
            'user_msg' => 'Não foi possível validar a assinatura da empresa.',
        ];
    }

    $quantidadeAtivas = 0;
    $possuiSuspensa = false;

    while ($stmt->fetch()) {
        $statusNormalizado = mb_strtolower(trim((string)$status), 'UTF-8');

        if ($statusNormalizado === 'ativa') {
            $quantidadeAtivas++;
            continue;
        }

        if ($statusNormalizado === 'suspensa') {
            $possuiSuspensa = true;
        }
    }

    $stmt->close();

    if ($quantidadeAtivas === 1) {
        return [
            'permitido' => true,
            'motivo' => 'ativa',
        ];
    }

    if ($quantidadeAtivas > 1) {
        // Uma inconsistência contratual não pode escolher arbitrariamente qual assinatura autoriza o acesso.
        return [
            'permitido' => false,
            'motivo' => 'multiplas_ativas',
            'user_msg' => $mensagemInativa,
        ];
    }

    if ($possuiSuspensa) {
        return [
            'permitido' => false,
            'motivo' => 'suspensa',
            'user_msg' => $mensagemSuspensa,
        ];
    }

    return [
        'permitido' => false,
        'motivo' => 'sem_contrato_ativo',
        'user_msg' => $mensagemInativa,
    ];
}
