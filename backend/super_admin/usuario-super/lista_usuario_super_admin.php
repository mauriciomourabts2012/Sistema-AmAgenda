<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

require __DIR__ . '/../../_auth/bloquear.php';

try {
    if (!in_array(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'POST'], true)) {
        out([
            'ok' => false,
            'code' => 'METHOD_NOT_ALLOWED',
            'user_msg' => 'Método não permitido.'
        ], 405);
    }

    require __DIR__ . '/../../_config/conexao.php';

    if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
        out([
            'ok' => false,
            'code' => 'DB_CONNECTION_ERROR',
            'user_msg' => 'Erro de conexão com banco de dados.'
        ], 500);
    }

    $conexao->set_charset('utf8mb4');

    /* ==========================================================
       HELPERS
    ========================================================== */
    function s(mixed $v): string {
        return trim((string)$v);
    }

    function intRange(mixed $v, int $min, int $max, int $default): int {
        $n = filter_var($v, FILTER_VALIDATE_INT);
        if ($n === false) {
            return $default;
        }
        if ($n < $min) {
            return $min;
        }
        if ($n > $max) {
            return $max;
        }
        return $n;
    }

    function normalizarStatus(?string $v): string {
        $v = mb_strtolower(trim((string)$v));
        if ($v === '' || $v === 'todos') {
            return 'todos';
        }

        return in_array($v, ['ativo', 'inativo', 'bloqueado'], true) ? $v : 'todos';
    }

    function normalizarFotoPerfil(?string $foto): ?string {
        $foto = trim((string)$foto);

        if ($foto === '') {
            return null;
        }

        // Garante sempre caminho absoluto relativo ao sistema
        if ($foto[0] !== '/') {
            $foto = '/' . $foto;
        }

        return $foto;
    }

    /* ==========================================================
       INPUTS
       Aceita GET ou POST
    ========================================================== */
    $src = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : $_GET;

    $busca  = s($src['busca'] ?? '');
    $status = normalizarStatus($src['status'] ?? 'ativo');
    $inicio = s($src['inicio'] ?? ($src['data_inicio'] ?? ''));
    $fim    = s($src['fim'] ?? ($src['data_fim'] ?? ''));
    $ordemRaw = strtolower(s($src['ordem'] ?? 'recentes'));
    $page   = intRange($src['page'] ?? 1, 1, 999999, 1);
    $limit  = intRange($src['limit'] ?? 10, 1, 100, 10);
    $offset = ($page - 1) * $limit;

    /* ==========================================================
       WHERE
    ========================================================== */
    $where = [];
    $types = '';
    $params = [];

    $where[] = "u.tipo_usuario = 'super_admin'";

    if ($status !== 'todos') {
        $where[] = "u.status = ?";
        $types .= 's';
        $params[] = $status;
    }

    if ($inicio !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio)) {
        out(['ok' => false, 'code' => 'INVALID_DATE_START', 'user_msg' => 'Data inicial inválida.'], 422);
    }
    if ($fim !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fim)) {
        out(['ok' => false, 'code' => 'INVALID_DATE_END', 'user_msg' => 'Data final inválida.'], 422);
    }
    if ($inicio !== '' && $fim !== '' && $inicio > $fim) {
        out(['ok' => false, 'code' => 'INVALID_DATE_RANGE', 'user_msg' => 'A data inicial não pode ser maior que a final.'], 422);
    }
    if ($inicio !== '') {
        $where[] = 'u.criado_em >= ?';
        $types .= 's';
        $params[] = $inicio . ' 00:00:00';
    }
    if ($fim !== '') {
        $where[] = 'u.criado_em <= ?';
        $types .= 's';
        $params[] = $fim . ' 23:59:59';
    }

    if ($busca !== '') {
        $where[] = "(
            u.nome LIKE ?
            OR u.email LIKE ?
            OR u.telefone LIKE ?
            OR CAST(u.id_usuario AS CHAR) LIKE ?
        )";

        $like = '%' . $busca . '%';

        $types .= 'ssss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $ordenacoesPermitidas = [
        'recentes' => 'u.criado_em DESC, u.id_usuario DESC',
        'antigos' => 'u.criado_em ASC, u.id_usuario ASC',
        'nome_asc' => 'u.nome ASC, u.id_usuario ASC',
        'nome_desc' => 'u.nome DESC, u.id_usuario DESC',
    ];
    $ordem = array_key_exists($ordemRaw, $ordenacoesPermitidas) ? $ordemRaw : 'recentes';
    $orderBySql = $ordenacoesPermitidas[$ordem];

    /* ==========================================================
       TOTAL
    ========================================================== */
    $sqlTotal = "
        SELECT COUNT(*) AS total
        FROM usuario u
        {$whereSql}
    ";

    $stmt = $conexao->prepare($sqlTotal);
    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar total: ' . $conexao->error);
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        throw new RuntimeException('Erro ao executar total: ' . $stmt->error);
    }

    $resTotal = $stmt->get_result();
    $rowTotal = $resTotal ? $resTotal->fetch_assoc() : null;
    $stmt->close();

    $total = (int)($rowTotal['total'] ?? 0);
    $pages = max(1, (int)ceil($total / $limit));

    if ($page > $pages) {
        $page = $pages;
        $offset = ($page - 1) * $limit;
    }

    /* ==========================================================
       LISTA
    ========================================================== */
    $sql = "
        SELECT
            u.id_usuario,
            u.nome,
            u.email,
            u.telefone,
            u.foto_perfil,
            u.status,
            u.ultimo_login_em,
            u.criado_em,
            u.atualizado_em,
            u.tipo_usuario
        FROM usuario u
        {$whereSql}
        ORDER BY {$orderBySql}
        LIMIT ? OFFSET ?
    ";

    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar listagem: ' . $conexao->error);
    }

    $typesList = $types . 'ii';
    $paramsList = $params;
    $paramsList[] = $limit;
    $paramsList[] = $offset;

    $stmt->bind_param($typesList, ...$paramsList);

    if (!$stmt->execute()) {
        throw new RuntimeException('Erro ao executar listagem: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    $lista = [];

    while ($row = $result->fetch_assoc()) {
        $fotoPerfil = normalizarFotoPerfil($row['foto_perfil'] ?? null);

        $lista[] = [
            'id_usuario'       => (int)$row['id_usuario'],
            'nome'             => (string)$row['nome'],
            'email'            => (string)$row['email'],
            'telefone'         => $row['telefone'] !== null ? (string)$row['telefone'] : null,
            'foto_perfil'      => $fotoPerfil,
            'foto_url'         => $fotoPerfil, // pronto para o JS usar direto
            'status'           => (string)$row['status'],
            'ultimo_login_em'  => $row['ultimo_login_em'],
            'criado_em'        => $row['criado_em'],
            'atualizado_em'    => $row['atualizado_em'],
            'tipo_usuario'     => (string)$row['tipo_usuario']
        ];
    }

    $stmt->close();

    out([
        'ok' => true,
        'code' => 'SUPER_ADMIN_LISTED',
        'user_msg' => $total > 0
            ? 'Super Admin listado com sucesso.'
            : 'Nenhum Super Admin encontrado.',
        'data' => $lista,
        'meta' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => $pages,
            'busca' => $busca,
            'status' => $status
            ,'inicio' => $inicio
            ,'fim' => $fim
            ,'ordem' => $ordem
        ]
    ]);

} catch (Throwable $e) {
    error_log('[listar_super_admin] ' . $e->getMessage());

    out([
        'ok' => false,
        'code' => 'INTERNAL_ERROR',
        'user_msg' => 'Erro interno ao listar Super Admin.',
        'debug' => [
            'message' => $e->getMessage()
        ]
    ], 500);
}
