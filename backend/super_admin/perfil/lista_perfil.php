<?php
declare(strict_types=1);

/*
|------------------------------------------------------------------
| LISTAR PERFIS — AmAgenda
|------------------------------------------------------------------
| GET /public/api/api_central.php?path=superadmin/perfil/listar
|------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/*
|------------------------------------------------------------------
| Fallback:
| se o arquivo for aberto diretamente, cria out()
| se vier pela api_central.php, usa a out() de lá
|------------------------------------------------------------------
*/
if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

require __DIR__ . '/../../_config/conexao.php';

try {
    if (!isset($conexao) || !($conexao instanceof mysqli)) {
        out([
            'ok' => false,
            'code' => 'DB_CONNECTION_ERROR',
            'user_msg' => 'Erro de conexão com banco de dados.'
        ], 500);
    }

    $status = isset($_GET['status'])
        ? trim((string) $_GET['status'])
        : '';

    $statusPermitidos = ['ativo', 'inativo', 'bloqueado'];

    $sql = "
        SELECT
            id_perfil,
            nome,
            descricao,
            status,
            criado_em,
            atualizado_em
        FROM perfil
    ";

    $params = [];
    $types  = '';

    if ($status !== '') {
        if (!in_array($status, $statusPermitidos, true)) {
            out([
                'ok' => false,
                'code' => 'INVALID_STATUS',
                'user_msg' => 'Status inválido. Use: ativo, inativo ou bloqueado.'
            ], 400);
        }

        $sql .= " WHERE status = ? ";
        $params[] = $status;
        $types .= 's';
    }

    $sql .= " ORDER BY nome ASC ";

    $stmt = $conexao->prepare($sql);

    if (!$stmt) {
        out([
            'ok' => false,
            'code' => 'QUERY_PREPARE_ERROR',
            'user_msg' => 'Erro ao preparar consulta.'
        ], 500);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $stmt->close();

        out([
            'ok' => false,
            'code' => 'QUERY_EXECUTION_ERROR',
            'user_msg' => 'Erro ao executar consulta.'
        ], 500);
    }

    $result = $stmt->get_result();
    $perfis = [];

    while ($row = $result->fetch_assoc()) {
        $perfis[] = [
            'id_perfil'     => (int) $row['id_perfil'],
            'nome'          => (string) $row['nome'],
            'descricao'     => $row['descricao'],
            'status'        => (string) $row['status'],
            'criado_em'     => $row['criado_em'],
            'atualizado_em' => $row['atualizado_em'],
        ];
    }

    $stmt->close();

    out([
        'ok' => true,
        'code' => 'PERFIS_LISTADOS',
        'data' => $perfis,
        'meta' => [
            'total' => count($perfis),
            'filtro' => [
                'status' => $status !== '' ? $status : 'todos'
            ]
        ]
    ], 200);

} catch (Throwable $e) {
    error_log("PERFIL_LIST_ERROR: " . $e->getMessage());

    out([
        'ok' => false,
        'code' => 'INTERNAL_ERROR',
        'user_msg' => 'Erro interno ao listar perfis.'
    ], 500);
}