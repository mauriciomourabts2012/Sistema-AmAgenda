<?php
declare(strict_types=1);

require_once __DIR__ . '/_comum.php';

try {
    clienteAgendamentoMetodo('GET');
    $contexto = clienteAgendamentoContexto($conexao);
    $idEmpresa = (int)$contexto['id_empresa'];
    $idProfissional = clienteAgendamentoId($_GET['id_profissional'] ?? null);
    if ($idProfissional <= 0) {
        out(['ok' => false, 'code' => 'PROFESSIONAL_ID_INVALID', 'user_msg' => 'Selecione um profissional válido.'], 422);
    }

    $stmt = $conexao->prepare(
        "SELECT p.id_profissional
           FROM profissional p
           INNER JOIN usuario u ON u.id_usuario = p.id_usuario AND u.status = 'ativo'
           INNER JOIN empresa_usuario eu
                   ON eu.id_usuario = p.id_usuario
                  AND eu.id_empresa = ?
                  AND eu.status = 'ativo'
                  AND eu.bloqueado_plano = 0
           INNER JOIN perfil pf
                   ON pf.id_perfil = eu.id_perfil
                  AND pf.status = 'ativo'
                  AND LOWER(TRIM(pf.nome)) IN ('profissional', 'profissionais')
          WHERE p.id_profissional = ?
          LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar a validação do profissional.');
    }
    $stmt->bind_param('ii', $idEmpresa, $idProfissional);
    $stmt->execute();
    $stmt->store_result();
    $profissionalValido = $stmt->num_rows === 1;
    $stmt->close();
    if (!$profissionalValido) {
        out(['ok' => false, 'code' => 'PROFESSIONAL_NOT_FOUND', 'user_msg' => 'Profissional não encontrado ou indisponível.'], 404);
    }

    $stmt = $conexao->prepare(
        "SELECT id_servico, nome, descricao, duracao_min, valor
           FROM servico
          WHERE id_empresa = ?
            AND id_profissional = ?
            AND status = 'ativo'
          ORDER BY nome ASC, id_servico ASC"
    );
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar a lista de serviços.');
    }
    $stmt->bind_param('ii', $idEmpresa, $idProfissional);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Falha ao consultar os serviços.');
    }
    $resultado = $stmt->get_result();
    $itens = [];
    while ($resultado && ($linha = $resultado->fetch_assoc())) {
        $itens[] = [
            'id_servico' => (int)$linha['id_servico'],
            'nome' => (string)$linha['nome'],
            'descricao' => $linha['descricao'] === null ? null : (string)$linha['descricao'],
            'duracao_min' => (int)$linha['duracao_min'],
            'valor' => (float)$linha['valor'],
        ];
    }
    $stmt->close();

    out(['ok' => true, 'code' => 'CLIENT_SERVICES_LISTED', 'data' => ['quantidade' => count($itens), 'itens' => $itens]]);
} catch (Throwable $e) {
    error_log('[cliente_agendamento_servicos] ' . $e->getMessage());
    out(['ok' => false, 'code' => 'CLIENT_SERVICES_LOAD_ERROR', 'user_msg' => 'Não foi possível carregar os serviços agora.'], 500);
}
