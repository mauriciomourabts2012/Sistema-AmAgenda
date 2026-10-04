<?php
declare(strict_types=1);

function acessoAssinaturaPerfilProprietario(string $perfil): bool
{
    $normalizado = mb_strtolower(trim($perfil), 'UTF-8');
    $normalizado = strtr($normalizado, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
        'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o',
        'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c',
    ]);

    return in_array($normalizado, ['proprietario', 'proprietarios'], true);
}

/**
 * Valida o acesso contratual usando o perfil obtido do vínculo autenticado.
 * O chamador deve fornecer o perfil lido de empresa_usuario + perfil, nunca do
 * frontend. Trial expirado produz efeito imediato de suspensão apenas para a
 * decisão de acesso; este fluxo não altera a assinatura no banco.
 */
function acessoAssinaturaValidar(mysqli $conexao, int $idEmpresa, string $perfilNome): array
{
    $mensagemSuspensa = 'A assinatura está suspensa. Regularize o faturamento para continuar usando o sistema.';
    $mensagemInativa = 'Assinatura não está ativa. Entre em contato com o suporte.';

    if ($idEmpresa <= 0 || trim($perfilNome) === '') {
        return [
            'permitido' => false,
            'estado_acesso' => 'bloqueado',
            'modo_regularizacao' => false,
            'motivo' => 'contexto_invalido',
            'user_msg' => $mensagemInativa,
        ];
    }

    $stmt = $conexao->prepare(
        "SELECT status, modalidade, teste_iniciado_em, teste_expira_em, motivo_suspensao,
                CASE
                    WHEN modalidade = 'teste'
                     AND teste_iniciado_em IS NOT NULL
                     AND teste_expira_em IS NOT NULL
                     AND teste_expira_em > teste_iniciado_em
                    THEN 1 ELSE 0
                END AS teste_configurado,
                CASE
                    WHEN modalidade = 'teste'
                     AND teste_expira_em IS NOT NULL
                     AND CURRENT_TIMESTAMP >= teste_expira_em
                    THEN 1 ELSE 0
                END AS teste_expirado
           FROM assinatura
          WHERE id_empresa = ?
          ORDER BY id_assinatura DESC"
    );

    if (!$stmt) {
        return [
            'permitido' => false,
            'estado_acesso' => 'bloqueado',
            'modo_regularizacao' => false,
            'motivo' => 'erro_validacao',
            'erro_tecnico' => true,
            'user_msg' => 'Não foi possível validar a assinatura da empresa.',
        ];
    }

    $stmt->bind_param('i', $idEmpresa);

    if (!$stmt->execute()
        || !$stmt->bind_result(
            $status,
            $modalidade,
            $testeIniciadoEm,
            $testeExpiraEm,
            $motivoSuspensao,
            $testeConfigurado,
            $testeExpirado
        )) {
        $stmt->close();

        return [
            'permitido' => false,
            'estado_acesso' => 'bloqueado',
            'modo_regularizacao' => false,
            'motivo' => 'erro_validacao',
            'erro_tecnico' => true,
            'user_msg' => 'Não foi possível validar a assinatura da empresa.',
        ];
    }

    $quantidadeAtivas = 0;
    $assinaturaMaisRecente = null;

    while ($stmt->fetch()) {
        $statusNormalizado = mb_strtolower(trim((string)$status), 'UTF-8');
        if ($assinaturaMaisRecente === null) {
            $assinaturaMaisRecente = [
                'status' => $statusNormalizado,
                'modalidade' => mb_strtolower(trim((string)$modalidade), 'UTF-8'),
                'teste_iniciado_em' => $testeIniciadoEm,
                'teste_expira_em' => $testeExpiraEm,
                'motivo_suspensao' => mb_strtolower(trim((string)$motivoSuspensao), 'UTF-8'),
                'teste_configurado' => (int)$testeConfigurado === 1,
                'teste_expirado' => (int)$testeExpirado === 1,
            ];
        }

        if ($statusNormalizado === 'ativa') {
            $quantidadeAtivas++;
        }
    }

    $stmt->close();

    if ($quantidadeAtivas > 1) {
        // Uma inconsistência contratual não pode escolher arbitrariamente qual assinatura autoriza o acesso.
        return [
            'permitido' => false,
            'estado_acesso' => 'bloqueado',
            'modo_regularizacao' => false,
            'motivo' => 'multiplas_ativas',
            'user_msg' => $mensagemInativa,
        ];
    }

    if ($quantidadeAtivas === 1 && ($assinaturaMaisRecente['status'] ?? '') === 'ativa') {
        $modalidadeAtual = (string)($assinaturaMaisRecente['modalidade'] ?? '');

        if ($modalidadeAtual === 'paga') {
            return [
                'permitido' => true,
                'estado_acesso' => 'acesso_normal',
                'modo_regularizacao' => false,
                'motivo' => 'ativa',
                'modalidade' => 'paga',
            ];
        }

        if ($modalidadeAtual !== 'teste' || !($assinaturaMaisRecente['teste_configurado'] ?? false)) {
            return [
                'permitido' => false,
                'estado_acesso' => 'bloqueado',
                'modo_regularizacao' => false,
                'motivo' => 'assinatura_inconsistente',
                'modalidade' => $modalidadeAtual,
                'user_msg' => $mensagemInativa,
            ];
        }

        if (!($assinaturaMaisRecente['teste_expirado'] ?? false)) {
            return [
                'permitido' => true,
                'estado_acesso' => 'acesso_normal',
                'modo_regularizacao' => false,
                'motivo' => 'teste_ativo',
                'modalidade' => 'teste',
            ];
        }

        return acessoAssinaturaResultadoSuspenso(
            acessoAssinaturaPerfilProprietario($perfilNome),
            'teste_expirado',
            'teste',
            $mensagemSuspensa
        );
    }

    if ($quantidadeAtivas === 0 && ($assinaturaMaisRecente['status'] ?? '') === 'suspensa') {
        $motivoAtual = (string)($assinaturaMaisRecente['motivo_suspensao'] ?? '');

        return acessoAssinaturaResultadoSuspenso(
            acessoAssinaturaPerfilProprietario($perfilNome),
            $motivoAtual !== '' ? $motivoAtual : 'suspensa',
            (string)($assinaturaMaisRecente['modalidade'] ?? ''),
            $mensagemSuspensa
        );
    }

    return [
        'permitido' => false,
        'estado_acesso' => 'bloqueado',
        'modo_regularizacao' => false,
        'motivo' => 'sem_contrato_ativo',
        'user_msg' => $mensagemInativa,
    ];
}

function acessoAssinaturaResultadoSuspenso(
    bool $proprietario,
    string $motivo,
    string $modalidade,
    string $mensagem
): array {
    return [
        'permitido' => $proprietario,
        'estado_acesso' => $proprietario ? 'modo_regularizacao' : 'bloqueado',
        'modo_regularizacao' => $proprietario,
        'motivo' => $motivo,
        'motivo_suspensao' => $motivo,
        'modalidade' => $modalidade,
        'user_msg' => $mensagem,
    ];
}

/**
 * Decide o entitlement da Agenda Online sem expor detalhes comerciais.
 * A regra contratual e o cálculo do trial permanecem centralizados em
 * acessoAssinaturaValidar(); este recurso exige acesso normal e nunca aceita
 * o modo de regularização como autorização.
 */
function acessoAssinaturaAgendaOnlineDisponivel(mysqli $conexao, int $idEmpresa): bool
{
    if ($idEmpresa <= 0) {
        return false;
    }

    $stmt = $conexao->prepare(
        "SELECT e.status, e.plano_id, p.agenda_online
           FROM empresa e
           LEFT JOIN plano p ON p.id_plano = e.plano_id
          WHERE e.id_empresa = ?
          LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $idEmpresa);
    if (!$stmt->execute() || !$stmt->bind_result($statusEmpresa, $idPlano, $agendaOnlinePlano) || !$stmt->fetch()) {
        $stmt->close();
        return false;
    }
    $stmt->close();

    if (mb_strtolower(trim((string)$statusEmpresa), 'UTF-8') !== 'ativo') {
        return false;
    }

    // Contexto público não é proprietário: suspensão/regularização deve sempre falhar fechada.
    $acesso = acessoAssinaturaValidar($conexao, $idEmpresa, 'cliente');
    if (!($acesso['permitido'] ?? false)
        || ($acesso['estado_acesso'] ?? '') !== 'acesso_normal'
        || ($acesso['modo_regularizacao'] ?? false)) {
        return false;
    }

    if (($acesso['modalidade'] ?? '') === 'teste' && ($acesso['motivo'] ?? '') === 'teste_ativo') {
        return true;
    }

    return (int)$idPlano > 0 && (int)$agendaOnlinePlano === 1;
}
