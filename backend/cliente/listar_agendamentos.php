<?php
declare(strict_types=1);

require_once __DIR__ . '/_sessao_cliente.php';
require_once __DIR__ . '/../_config/conexao.php';

try {
    $sessao = exigirSessaoCliente();
    $cliente = buscarClienteDaSessao($conexao, $sessao);

    if ($cliente === null) {
        out([
            'ok' => true,
            'code' => 'CLIENT_APPOINTMENTS_LISTED',
            'data' => ['quantidade' => 0, 'itens' => []],
        ]);
    }

    $idCliente = (int)$cliente['id_cliente'];

    // profissional não possui coluna id_empresa (vínculo é por profissional.id_usuario).
    // O isolamento por empresa/cliente já é garantido por a.id_empresa, a.id_cliente
    // e s.id_empresa — não adicionar id_empresa ao JOIN de profissional.
    $stmt = $conexao->prepare(
        "SELECT
            a.id_agendamento,
            DATE_FORMAT(a.data_agendamento, '%Y-%m-%d') AS data_agendamento,
            DATE_FORMAT(a.hora_inicio, '%H:%i') AS hora_inicio,
            DATE_FORMAT(a.hora_fim, '%H:%i') AS hora_fim,
            a.status,
            a.valor_aplicado,
            s.nome AS servico,
            u.nome AS profissional
         FROM agendamento a
         INNER JOIN servico s
            ON s.id_servico = a.id_servico
           AND s.id_empresa = a.id_empresa
         INNER JOIN profissional p
            ON p.id_profissional = a.id_profissional
         INNER JOIN usuario u
            ON u.id_usuario = p.id_usuario
         WHERE a.id_empresa = ?
           AND a.id_cliente = ?
         ORDER BY
           (a.data_agendamento < CURRENT_DATE) ASC,
           a.data_agendamento ASC,
           a.hora_inicio ASC
         LIMIT 100"
    );
    if (!$stmt) {
        throw new RuntimeException('Não foi possível preparar a lista de agendamentos.');
    }
    $stmt->bind_param('ii', $sessao['id_empresa'], $idCliente);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Não foi possível consultar os agendamentos.');
    }

    $resultado = $stmt->get_result();
    $itens = [];
    while ($resultado && ($linha = $resultado->fetch_assoc())) {
        $itens[] = [
            'id_agendamento' => (int)$linha['id_agendamento'],
            'data' => (string)$linha['data_agendamento'],
            'hora_inicio' => (string)$linha['hora_inicio'],
            'hora_fim' => (string)$linha['hora_fim'],
            'servico' => (string)$linha['servico'],
            'profissional' => (string)$linha['profissional'],
            'status' => (string)$linha['status'],
            'valor' => (float)$linha['valor_aplicado'],
        ];
    }
    $stmt->close();

    out([
        'ok' => true,
        'code' => 'CLIENT_APPOINTMENTS_LISTED',
        'data' => ['quantidade' => count($itens), 'itens' => $itens],
    ]);
} catch (Throwable $e) {
    error_log('[cliente_listar_agendamentos] ' . $e->getMessage());
    out([
        'ok' => false,
        'code' => 'CLIENT_APPOINTMENTS_LOAD_ERROR',
        'user_msg' => 'Não foi possível carregar seus agendamentos agora.',
    ], 500);
}
