<?php
declare(strict_types=1);

require_once __DIR__ . '/_comum.php';

try {
    clienteAgendamentoMetodo('GET');
    $contexto = clienteAgendamentoContexto($conexao);
    $idEmpresa = (int)$contexto['id_empresa'];

    $stmt = $conexao->prepare(
        "SELECT DISTINCT p.id_profissional, u.nome, u.foto_perfil,
                p.especialidade, p.descricao
           FROM profissional p
           INNER JOIN usuario u
                   ON u.id_usuario = p.id_usuario
                  AND u.status = 'ativo'
           INNER JOIN empresa_usuario eu
                   ON eu.id_usuario = p.id_usuario
                  AND eu.id_empresa = ?
                  AND eu.status = 'ativo'
                  AND eu.bloqueado_plano = 0
           INNER JOIN perfil pf
                   ON pf.id_perfil = eu.id_perfil
                  AND pf.status = 'ativo'
                  AND LOWER(TRIM(pf.nome)) IN ('profissional', 'profissionais')
          WHERE EXISTS (
                SELECT 1
                  FROM servico s
                 WHERE s.id_empresa = eu.id_empresa
                   AND s.id_profissional = p.id_profissional
                   AND s.status = 'ativo'
          )
          ORDER BY u.nome ASC, p.id_profissional ASC"
    );
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar a lista de profissionais.');
    }
    $stmt->bind_param('i', $idEmpresa);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Falha ao consultar os profissionais.');
    }

    $resultado = $stmt->get_result();
    $itens = [];
    while ($resultado && ($linha = $resultado->fetch_assoc())) {
        $itens[] = [
            'id_profissional' => (int)$linha['id_profissional'],
            'nome' => (string)$linha['nome'],
            'especialidade' => (string)$linha['especialidade'],
            'descricao' => $linha['descricao'] === null ? null : (string)$linha['descricao'],
            'foto_url' => clienteAgendamentoFotoUrl($linha['foto_perfil'] ?? null),
        ];
    }
    $stmt->close();

    out([
        'ok' => true,
        'code' => 'CLIENT_PROFESSIONALS_LISTED',
        'data' => [
            'quantidade' => count($itens),
            'itens' => $itens,
            'csrf_token' => clienteAgendamentoCsrfToken(),
        ],
    ]);
} catch (Throwable $e) {
    error_log('[cliente_agendamento_profissionais] ' . $e->getMessage());
    out(['ok' => false, 'code' => 'CLIENT_PROFESSIONALS_LOAD_ERROR', 'user_msg' => 'Não foi possível carregar os profissionais agora.'], 500);
}
