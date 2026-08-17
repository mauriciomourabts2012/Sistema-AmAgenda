<?php
declare(strict_types=1);

/*
|------------------------------------------------------------------
| lista_usuario.php
| Compatível com api_central.php
| Rota: superadmin/usuario/listar
|------------------------------------------------------------------
| Retorna somente usuários do perfil: Proprietario
|
| {
|   ok: true,
|   code: "USUARIO_LIST_OK",
|   meta: { page, limit, total, pages },
|   filtros: { q, perfil, id_perfil, status, id_empresa },
|   periodo: { data_inicio, data_fim },
|   data: [...]
| }
|------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!function_exists('out')) {
    function out(array $payload, int $httpCode = 200): void
    {
        http_response_code($httpCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('s')) {
    function s($v): ?string
    {
        if ($v === null) return null;
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }
}

if (!function_exists('clampInt')) {
    function clampInt($v, int $min, int $max, int $fallback): int
    {
        $n = filter_var($v, FILTER_VALIDATE_INT);
        if ($n === false) return $fallback;
        if ($n < $min) return $min;
        if ($n > $max) return $max;
        return $n;
    }
}

if (!function_exists('bindParamsDynamic')) {
    function bindParamsDynamic(mysqli_stmt $stmt, string $types, array $values): void
    {
        $refs = [];
        $refs[] = $types;

        foreach ($values as $k => $v) {
            $refs[] = &$values[$k];
        }

        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}

if (!function_exists('parseDateYmd')) {
    function parseDateYmd(?string $d): ?string
    {
        $d = s($d);
        if (!$d) return null;

        $dt = DateTime::createFromFormat('Y-m-d', $d);
        if (!$dt || $dt->format('Y-m-d') !== $d) {
            return null;
        }

        return $d;
    }
}

/* ==========================================================
   CONEXÃO
========================================================== */
require __DIR__ . '/../../_config/conexao.php';

if (isset($conexao) && $conexao instanceof mysqli) {
    $db = $conexao;
} elseif (isset($conn) && $conn instanceof mysqli) {
    $db = $conn;
} else {
    out([
        'ok' => false,
        'code' => 'DB_CONNECTION_FAIL',
        'user_msg' => 'Falha na conexão com o banco.'
    ], 500);
}

$db->set_charset('utf8mb4');

/* ==========================================================
   PARÂMETROS
========================================================== */
$q = s($_GET['q'] ?? ($_GET['busca'] ?? null));

/*
|--------------------------------------------------------------------------
| PERFIL FIXO NO BACKEND
|--------------------------------------------------------------------------
| Este endpoint lista SOMENTE usuários do perfil Proprietario.
| Não depende de $_GET['perfil'] para evitar manipulação pelo frontend.
*/
$perfilFixo = 'Proprietario';

$status = s($_GET['status'] ?? null);
if ($status === 'todos') {
    $status = null;
}

$idEmpresa = clampInt($_GET['id_empresa'] ?? ($_GET['empresa_id'] ?? 0), 0, 999999999, 0);

$page   = clampInt($_GET['page'] ?? 1, 1, 999999, 1);
$limit  = clampInt($_GET['limit'] ?? 10, 1, 100, 10);
$offset = ($page - 1) * $limit;

$data_inicio = parseDateYmd($_GET['data_inicio'] ?? null);
$data_fim    = parseDateYmd($_GET['data_fim'] ?? null);

/* ==========================================================
   PERÍODO DEFAULT
========================================================== */
$hoje = new DateTime('today');

if (!$data_fim) {
    $data_fim = $hoje->format('Y-m-d');
}

if (!$data_inicio) {
    $ini = (clone $hoje)->modify('-30 days');
    $data_inicio = $ini->format('Y-m-d');
}

/* ==========================================================
   VALIDAÇÕES
========================================================== */
if ($status !== null && !in_array($status, ['ativo', 'inativo', 'bloqueado'], true)) {
    out([
        'ok' => false,
        'code' => 'INVALID_STATUS',
        'user_msg' => 'Status inválido.'
    ], 400);
}

if ($data_inicio > $data_fim) {
    out([
        'ok' => false,
        'code' => 'INVALID_DATE_RANGE',
        'user_msg' => 'A data inicial não pode ser maior que a data final.'
    ], 400);
}

/* ==========================================================
   WHERE DINÂMICO
========================================================== */
$where  = [];
$types  = '';
$params = [];

/* período sobre o vínculo */
$inicioDT = $data_inicio . ' 00:00:00';
$fimDT    = $data_fim . ' 23:59:59';

$where[]  = "eu.criado_em BETWEEN ? AND ?";
$params[] = $inicioDT;
$params[] = $fimDT;
$types   .= 'ss';

/* empresa */
if ($idEmpresa > 0) {
    $where[]  = "eu.id_empresa = ?";
    $params[] = $idEmpresa;
    $types   .= 'i';
}

/* busca */
if ($q !== null) {
    $where[] = "(
        u.nome LIKE ?
        OR u.email LIKE ?
        OR u.telefone LIKE ?
        OR p.nome LIKE ?
        OR e.nome LIKE ?
    )";

    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= 'sssss';
}

/* PERFIL FIXO: SOMENTE PROPRIETARIO */
$where[]  = "p.nome = ?";
$params[] = $perfilFixo;
$types   .= 's';

/* status do vínculo */
if ($status !== null) {
    $where[]  = "eu.status = ?";
    $params[] = $status;
    $types   .= 's';
}

$whereSql = !empty($where)
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

/* ==========================================================
   TOTAL
========================================================== */
$sqlTotal = "
    SELECT COUNT(*) AS total
    FROM empresa_usuario eu
    INNER JOIN usuario u ON u.id_usuario = eu.id_usuario
    INNER JOIN perfil p  ON p.id_perfil = eu.id_perfil
    INNER JOIN empresa e ON e.id_empresa = eu.id_empresa
    {$whereSql}
";

$stmtT = $db->prepare($sqlTotal);

if (!$stmtT) {
    out([
        'ok' => false,
        'code' => 'PREPARE_TOTAL_FAIL',
        'user_msg' => 'Falha ao preparar consulta de total.',
        'debug_sql' => $sqlTotal
    ], 500);
}

if ($types !== '') {
    bindParamsDynamic($stmtT, $types, $params);
}

if (!$stmtT->execute()) {
    $erro = $stmtT->error;
    $stmtT->close();

    out([
        'ok' => false,
        'code' => 'EXEC_TOTAL_FAIL',
        'user_msg' => 'Falha ao executar consulta de total.',
        'debug_error' => $erro
    ], 500);
}

$total = 0;

if (method_exists($stmtT, 'get_result')) {
    $resT = $stmtT->get_result();
    $rowT = $resT ? $resT->fetch_assoc() : null;
    $total = (int)($rowT['total'] ?? 0);
} else {
    $stmtT->bind_result($totalTmp);
    $stmtT->fetch();
    $total = (int)$totalTmp;
}

$stmtT->close();

/* ==========================================================
   LISTA
========================================================== */
$sql = "
    SELECT
        eu.id_empresa_usuario,
        eu.id_empresa,
        e.nome AS empresa_nome,

        eu.id_usuario,
        u.nome,
        u.email,
        u.telefone,
        u.foto_perfil,
        u.status AS status_usuario,
        u.ultimo_login_em,

        eu.id_perfil,
        p.nome AS perfil,
        p.descricao AS perfil_descricao,

        eu.status AS status_vinculo,
        eu.criado_em,
        eu.atualizado_em

    FROM empresa_usuario eu
    INNER JOIN usuario u ON u.id_usuario = eu.id_usuario
    INNER JOIN perfil p  ON p.id_perfil = eu.id_perfil
    INNER JOIN empresa e ON e.id_empresa = eu.id_empresa
    {$whereSql}
    ORDER BY eu.criado_em DESC, u.nome ASC
    LIMIT ? OFFSET ?
";

$stmt = $db->prepare($sql);

if (!$stmt) {
    out([
        'ok' => false,
        'code' => 'PREPARE_LIST_FAIL',
        'user_msg' => 'Falha ao preparar consulta da lista.',
        'debug_sql' => $sql
    ], 500);
}

$paramsList = $params;
$paramsList[] = $limit;
$paramsList[] = $offset;
$typesList = $types . 'ii';

bindParamsDynamic($stmt, $typesList, $paramsList);

if (!$stmt->execute()) {
    $erro = $stmt->error;
    $stmt->close();

    out([
        'ok' => false,
        'code' => 'EXEC_LIST_FAIL',
        'user_msg' => 'Falha ao executar consulta da lista.',
        'debug_error' => $erro
    ], 500);
}

$data = [];

if (method_exists($stmt, 'get_result')) {
    $res = $stmt->get_result();

    while ($r = $res->fetch_assoc()) {
        $data[] = [
            'id_empresa_usuario' => (int)$r['id_empresa_usuario'],
            'id_empresa'         => (int)$r['id_empresa'],
            'empresa_nome'       => (string)$r['empresa_nome'],

            'id_usuario'         => (int)$r['id_usuario'],
            'nome'               => (string)$r['nome'],
            'email'              => (string)$r['email'],
            'telefone'           => $r['telefone'] !== null ? (string)$r['telefone'] : null,
            'foto_perfil'        => $r['foto_perfil'] !== null ? (string)$r['foto_perfil'] : null,

            'id_perfil'          => (int)$r['id_perfil'],
            'perfil'             => (string)$r['perfil'],
            'perfil_descricao'   => $r['perfil_descricao'] !== null ? (string)$r['perfil_descricao'] : null,

            'status'             => (string)$r['status_vinculo'],
            'status_vinculo'     => (string)$r['status_vinculo'],
            'status_usuario'     => (string)$r['status_usuario'],

            'ultimo_login_em'    => $r['ultimo_login_em'] !== null ? (string)$r['ultimo_login_em'] : null,
            'criado_em'          => (string)$r['criado_em'],
            'atualizado_em'      => (string)$r['atualizado_em'],
        ];
    }
} else {
    $stmt->bind_result(
        $id_empresa_usuario,
        $id_empresa_r,
        $empresa_nome,
        $id_usuario,
        $nome,
        $email,
        $telefone,
        $foto_perfil,
        $status_usuario,
        $ultimo_login_em,
        $id_perfil_r,
        $perfil_r,
        $perfil_descricao,
        $status_vinculo,
        $criado_em,
        $atualizado_em
    );

    while ($stmt->fetch()) {
        $data[] = [
            'id_empresa_usuario' => (int)$id_empresa_usuario,
            'id_empresa'         => (int)$id_empresa_r,
            'empresa_nome'       => (string)$empresa_nome,

            'id_usuario'         => (int)$id_usuario,
            'nome'               => (string)$nome,
            'email'              => (string)$email,
            'telefone'           => $telefone !== null ? (string)$telefone : null,
            'foto_perfil'        => $foto_perfil !== null ? (string)$foto_perfil : null,

            'id_perfil'          => (int)$id_perfil_r,
            'perfil'             => (string)$perfil_r,
            'perfil_descricao'   => $perfil_descricao !== null ? (string)$perfil_descricao : null,

            'status'             => (string)$status_vinculo,
            'status_vinculo'     => (string)$status_vinculo,
            'status_usuario'     => (string)$status_usuario,

            'ultimo_login_em'    => $ultimo_login_em !== null ? (string)$ultimo_login_em : null,
            'criado_em'          => (string)$criado_em,
            'atualizado_em'      => (string)$atualizado_em,
        ];
    }
}

$stmt->close();

/* ==========================================================
   RESPOSTA
========================================================== */
out([
    'ok' => true,
    'code' => 'USUARIO_LIST_OK',
    'meta' => [
        'page'  => $page,
        'limit' => $limit,
        'total' => $total,
        'pages' => (int) ceil($total / max(1, $limit)),
    ],
    'filtros' => [
        'q'          => $q,
        'perfil'     => $perfilFixo,
        'id_perfil'  => null,
        'status'     => $status,
        'id_empresa' => $idEmpresa > 0 ? $idEmpresa : null,
    ],
    'periodo' => [
        'data_inicio' => $data_inicio,
        'data_fim'    => $data_fim,
    ],
    'data' => $data
], 200);