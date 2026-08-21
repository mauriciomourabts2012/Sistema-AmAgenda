<?php
declare(strict_types=1);

/* ==========================================================
   lista_usuario.php
   ✅ Mesmo padrão do lista_cliente.php
   ✅ Mesmo padrão de sessão
   ✅ Lista somente usuários da empresa da sessão
   ✅ Exclui perfil Proprietário
   ✅ Busca real
   ✅ Filtro por status
   ✅ Filtro por perfil
   ✅ Filtro por período (opcional)
   ✅ Paginação real
   ✅ Compatível com lista-usuario.js
   ✅ Especialidade via LEFT JOIN profissional
========================================================== */

// ✅ NÃO defina header aqui (api_central já define)
if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

try {
    /* ==========================================================
       MÉTODO
    ========================================================== */
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        out([
            'ok' => false,
            'code' => 'METHOD_NOT_ALLOWED',
            'user_msg' => 'Método não permitido.'
        ], 405);
    }

    /* ==========================================================
       SESSÃO
    ========================================================== */
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $auth = $_SESSION['auth'] ?? null;

    if (!$auth || empty($auth['id_usuario'])) {
        out([
            'ok' => false,
            'code' => 'NOT_AUTHENTICATED',
            'user_msg' => 'Sessão expirada. Faça login novamente.'
        ], 401);
    }

    $statusUsuarioSessao = (string)($auth['status'] ?? '');
    if ($statusUsuarioSessao !== '' && $statusUsuarioSessao !== 'ativo') {
        out([
            'ok' => false,
            'code' => 'SESSION_USER_INACTIVE',
            'user_msg' => 'Seu usuário não está ativo. Faça login novamente.'
        ], 403);
    }

    /* ==========================================================
       EMPRESA DA SESSÃO
    ========================================================== */
    $idEmpresaSessao = 0;

    if (isset($auth['id_empresa'])) {
        $idEmpresaSessao = (int)$auth['id_empresa'];
    } elseif (isset($_SESSION['empresa_id'])) {
        $idEmpresaSessao = (int)$_SESSION['empresa_id'];
    } elseif (isset($_SESSION['id_empresa'])) {
        $idEmpresaSessao = (int)$_SESSION['id_empresa'];
    } elseif (isset($_SESSION['empresa']['id_empresa'])) {
        $idEmpresaSessao = (int)$_SESSION['empresa']['id_empresa'];
    } elseif (isset($_SESSION['empresa']['id'])) {
        $idEmpresaSessao = (int)$_SESSION['empresa']['id'];
    }

    if ($idEmpresaSessao <= 0) {
        out([
            'ok' => false,
            'code' => 'SESSION_WITHOUT_COMPANY',
            'user_msg' => 'Não foi possível identificar a empresa da sessão.'
        ], 403);
    }

    /* ==========================================================
       CONEXÃO
    ========================================================== */
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

    function lower(mixed $v): string {
        return mb_strtolower(trim((string)$v), 'UTF-8');
    }

    function somenteDigitos(?string $v): string {
        return preg_replace('/\D+/', '', (string)$v) ?? '';
    }

    function isDataIsoValida(?string $data): bool {
        $data = trim((string)$data);
        if ($data === '') {
            return false;
        }

        $dt = DateTime::createFromFormat('Y-m-d', $data);
        return $dt instanceof DateTime && $dt->format('Y-m-d') === $data;
    }

    function bindParamsDynamic(mysqli_stmt $stmt, string $types, array &$values): void {
        $params = [];
        $params[] = $types;

        foreach ($values as $k => &$v) {
            $params[] = &$v;
        }

        call_user_func_array([$stmt, 'bind_param'], $params);
    }

    /* ==========================================================
       ENTRADAS
    ========================================================== */
    $busca  = s($_GET['q'] ?? $_GET['busca'] ?? '');
    $status = lower($_GET['status'] ?? 'ativo');
    $perfil = lower($_GET['perfil'] ?? '');
    $inicio = s($_GET['inicio'] ?? '');
    $fim    = s($_GET['fim'] ?? '');
    $ordem  = lower($_GET['ordem'] ?? 'nome_asc');

    $pagina = (int)($_GET['pagina'] ?? 1);
    $limite = (int)($_GET['limite'] ?? 10);

    if ($pagina < 1) {
        $pagina = 1;
    }

    if ($limite < 1) {
        $limite = 10;
    }

    if ($limite > 100) {
        $limite = 100;
    }

    $offset = ($pagina - 1) * $limite;

    if (!in_array($status, ['todos', 'ativo', 'inativo', 'bloqueado'], true)) {
        $status = 'ativo';
    }

    if (!in_array($perfil, ['', 'todos', 'super_admin', 'usuario', 'profissional', 'recepcao'], true)) {
        $perfil = '';
    }

    if ($inicio !== '' && !isDataIsoValida($inicio)) {
        out([
            'ok' => false,
            'code' => 'INVALID_START_DATE',
            'user_msg' => 'Data inicial inválida.'
        ], 422);
    }

    if ($fim !== '' && !isDataIsoValida($fim)) {
        out([
            'ok' => false,
            'code' => 'INVALID_END_DATE',
            'user_msg' => 'Data final inválida.'
        ], 422);
    }

    if ($inicio !== '' && $fim !== '' && $inicio > $fim) {
        out([
            'ok' => false,
            'code' => 'INVALID_DATE_RANGE',
            'user_msg' => 'A data inicial não pode ser maior que a data final.'
        ], 422);
    }

    /* ==========================================================
       VALIDAR EMPRESA DA SESSÃO
    ========================================================== */
    $sqlEmpresa = "
        SELECT id_empresa, nome, status
        FROM empresa
        WHERE id_empresa = ?
        LIMIT 1
    ";

    $stmtEmpresa = $conexao->prepare($sqlEmpresa);
    if (!$stmtEmpresa) {
        throw new RuntimeException('Erro ao preparar validação da empresa: ' . $conexao->error);
    }

    $stmtEmpresa->bind_param('i', $idEmpresaSessao);

    if (!$stmtEmpresa->execute()) {
        throw new RuntimeException('Erro ao executar validação da empresa: ' . $stmtEmpresa->error);
    }

    $resultEmpresa = $stmtEmpresa->get_result();
    $empresa = $resultEmpresa ? $resultEmpresa->fetch_assoc() : null;
    $stmtEmpresa->close();

    if (!$empresa) {
        out([
            'ok' => false,
            'code' => 'EMPRESA_NOT_FOUND',
            'user_msg' => 'Empresa da sessão não encontrada.'
        ], 404);
    }

    if ((string)($empresa['status'] ?? '') !== 'ativo') {
        out([
            'ok' => false,
            'code' => 'EMPRESA_INACTIVE',
            'user_msg' => 'A empresa vinculada à sessão está inativa.'
        ], 403);
    }

    /* ==========================================================
       EXPRESSÃO DE STATUS EFETIVO
       - Se vínculo ou usuário estiver bloqueado => bloqueado
       - Se vínculo ou usuário estiver inativo   => inativo
       - Senão                                  => ativo
    ========================================================== */
    $statusEfetivoSql = "
        CASE
            WHEN eu.status = 'bloqueado' OR u.status = 'bloqueado' THEN 'bloqueado'
            WHEN eu.status = 'inativo'   OR u.status = 'inativo'   THEN 'inativo'
            ELSE 'ativo'
        END
    ";

    /* ==========================================================
       FILTROS
    ========================================================== */
    $where = [];
    $types = '';
    $params = [];

    $where[] = 'eu.id_empresa = ?';
    $types .= 'i';
    $params[] = $idEmpresaSessao;

    // Exclui Proprietário sem depender de id fixo
    $where[] = "LOWER(TRIM(p.nome)) <> 'proprietario'";

    if ($status !== 'todos') {
        $where[] = "{$statusEfetivoSql} = ?";
        $types .= 's';
        $params[] = $status;
    }

    if ($perfil !== '' && $perfil !== 'todos') {
        if ($perfil === 'profissional') {
            $where[] = "LOWER(TRIM(p.nome)) = 'profissional'";
        } elseif ($perfil === 'recepcao') {
            $where[] = "LOWER(TRIM(p.nome)) = 'recepcao'";
        } elseif ($perfil === 'super_admin') {
            $where[] = "u.tipo_usuario = 'super_admin'";
        } elseif ($perfil === 'usuario') {
            $where[] = "u.tipo_usuario = 'usuario'";
        }
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
        $buscaLike = '%' . $busca . '%';
        $buscaDigits = somenteDigitos($busca);
        $buscaParts = [];

        $buscaParts[] = 'u.nome LIKE ?';
        $types .= 's';
        $params[] = $buscaLike;

        $buscaParts[] = 'u.email LIKE ?';
        $types .= 's';
        $params[] = $buscaLike;

        $buscaParts[] = 'p.nome LIKE ?';
        $types .= 's';
        $params[] = $buscaLike;

        $buscaParts[] = 'pr.especialidade LIKE ?';
        $types .= 's';
        $params[] = $buscaLike;

        if ($buscaDigits !== '') {
            $buscaParts[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(u.telefone, '(', ''), ')', ''), '-', ''), ' ', ''), '+', '') LIKE ?";
            $types .= 's';
            $params[] = '%' . $buscaDigits . '%';
        } else {
            $buscaParts[] = 'u.telefone LIKE ?';
            $types .= 's';
            $params[] = $buscaLike;
        }

        $where[] = '(' . implode(' OR ', $buscaParts) . ')';
    }

    $whereSql = implode(' AND ', $where);

    /* ==========================================================
       TOTAL
    ========================================================== */
    $sqlTotal = "
        SELECT COUNT(*) AS total
        FROM empresa_usuario eu
        INNER JOIN usuario u
            ON u.id_usuario = eu.id_usuario
        INNER JOIN perfil p
            ON p.id_perfil = eu.id_perfil
        LEFT JOIN profissional pr
            ON pr.id_usuario = u.id_usuario
        WHERE {$whereSql}
    ";

    $stmtTotal = $conexao->prepare($sqlTotal);
    if (!$stmtTotal) {
        throw new RuntimeException('Erro ao preparar total de usuários: ' . $conexao->error);
    }

    $paramsTotal = $params;
    if ($types !== '') {
        bindParamsDynamic($stmtTotal, $types, $paramsTotal);
    }

    if (!$stmtTotal->execute()) {
        throw new RuntimeException('Erro ao executar total de usuários: ' . $stmtTotal->error);
    }

    $resultTotal = $stmtTotal->get_result();
    $rowTotal = $resultTotal ? $resultTotal->fetch_assoc() : null;
    $stmtTotal->close();

    $totalRegistros = (int)($rowTotal['total'] ?? 0);
    $totalPaginas = $totalRegistros > 0 ? (int)ceil($totalRegistros / $limite) : 1;

    if ($pagina > $totalPaginas && $totalRegistros > 0) {
        $pagina = $totalPaginas;
        $offset = ($pagina - 1) * $limite;
    }

    /* ==========================================================
       LISTA
    ========================================================== */
    $sqlLista = "
        SELECT
            eu.id_empresa_usuario,
            eu.id_empresa,
            eu.id_usuario,
            eu.id_perfil,
            eu.status AS status_vinculo,

            u.nome,
            u.email,
            u.telefone,
            u.foto_perfil,
            u.status AS status_usuario,
            u.ultimo_login_em,
            u.criado_em,
            u.atualizado_em,
            u.tipo_usuario,

            p.nome AS perfil_nome,
            p.descricao AS perfil_descricao,

            pr.id_profissional,
            pr.especialidade,

            {$statusEfetivoSql} AS status
        FROM empresa_usuario eu
        INNER JOIN usuario u
            ON u.id_usuario = eu.id_usuario
        INNER JOIN perfil p
            ON p.id_perfil = eu.id_perfil
        LEFT JOIN profissional pr
            ON pr.id_usuario = u.id_usuario
        WHERE {$whereSql}
        ORDER BY " . ([
            'nome_asc' => 'u.nome ASC, u.id_usuario ASC',
            'nome_desc' => 'u.nome DESC, u.id_usuario DESC',
            'recentes' => 'u.criado_em DESC, u.id_usuario DESC',
            'antigos' => 'u.criado_em ASC, u.id_usuario ASC',
        ][$ordem] ?? 'u.nome ASC, u.id_usuario ASC') . "
        LIMIT ? OFFSET ?
    ";

    $stmtLista = $conexao->prepare($sqlLista);
    if (!$stmtLista) {
        throw new RuntimeException('Erro ao preparar lista de usuários: ' . $conexao->error);
    }

    $typesLista = $types . 'ii';
    $paramsLista = $params;
    $paramsLista[] = $limite;
    $paramsLista[] = $offset;

    bindParamsDynamic($stmtLista, $typesLista, $paramsLista);

    if (!$stmtLista->execute()) {
        throw new RuntimeException('Erro ao executar lista de usuários: ' . $stmtLista->error);
    }

    $resultLista = $stmtLista->get_result();
    if (!$resultLista) {
        throw new RuntimeException('Erro ao obter resultado da lista de usuários.');
    }

    $items = [];

    while ($row = $resultLista->fetch_assoc()) {
        $perfilNome = (string)($row['perfil_nome'] ?? '');
        $perfilSlug = 'usuario';

        $perfilNomeNorm = mb_strtolower(trim($perfilNome), 'UTF-8');

        if ($perfilNomeNorm === 'profissional') {
            $perfilSlug = 'profissional';
        } elseif ($perfilNomeNorm === 'recepcao' || $perfilNomeNorm === 'recepção') {
            $perfilSlug = 'recepcao';
        } elseif ((string)($row['tipo_usuario'] ?? '') === 'super_admin') {
            $perfilSlug = 'super_admin';
        }

        $items[] = [
            'id_empresa_usuario' => (int)$row['id_empresa_usuario'],
            'id_empresa'         => (int)$row['id_empresa'],
            'id_usuario'         => (int)$row['id_usuario'],
            'id_perfil'          => (int)$row['id_perfil'],

            'nome'               => (string)$row['nome'],
            'email'              => $row['email'] !== null ? (string)$row['email'] : null,
            'telefone'           => $row['telefone'] !== null ? (string)$row['telefone'] : null,
            'foto_perfil'        => $row['foto_perfil'] !== null ? (string)$row['foto_perfil'] : null,

            'perfil'             => $perfilSlug,
            'perfil_nome'        => $perfilNome,
            'perfil_descricao'   => $row['perfil_descricao'] !== null ? (string)$row['perfil_descricao'] : null,

            'tipo_usuario'       => (string)$row['tipo_usuario'],

            'especialidade'      => $row['especialidade'] !== null ? (string)$row['especialidade'] : null,
            'id_profissional'    => $row['id_profissional'] !== null ? (int)$row['id_profissional'] : null,

            'status'             => (string)$row['status'],
            'status_usuario'     => (string)$row['status_usuario'],
            'status_vinculo'     => (string)$row['status_vinculo'],

            'ultimo_login_em'    => $row['ultimo_login_em'] !== null ? (string)$row['ultimo_login_em'] : null,
            'criado_em'          => $row['criado_em'] !== null ? (string)$row['criado_em'] : null,
            'atualizado_em'      => $row['atualizado_em'] !== null ? (string)$row['atualizado_em'] : null
        ];
    }

    $stmtLista->close();

    /* ==========================================================
       RESPOSTA
    ========================================================== */
    out([
        'ok' => true,
        'code' => 'USUARIOS_LISTADOS',
        'user_msg' => 'Usuários listados com sucesso.',
        'data' => [
            'empresa' => [
                'id_empresa' => (int)$empresa['id_empresa'],
                'nome'       => (string)$empresa['nome'],
                'status'     => (string)$empresa['status']
            ],
            'filtros' => [
                'busca'  => $busca,
                'status' => $status,
                'perfil' => $perfil,
                'inicio' => $inicio,
                'fim'    => $fim,
                'pagina' => $pagina,
                'limite' => $limite
                ,'ordem' => $ordem
            ],
            'paginacao' => [
                'pagina_atual'    => $pagina,
                'limite'          => $limite,
                'total_registros' => $totalRegistros,
                'total_paginas'   => $totalPaginas,
                'tem_anterior'    => ($pagina > 1),
                'tem_proxima'     => ($pagina < $totalPaginas)
            ],
            'items' => $items
        ]
    ], 200);

} catch (Throwable $e) {
    error_log('[lista_usuario.php] ' . $e->getMessage());

    out([
        'ok' => false,
        'code' => 'INTERNAL_ERROR',
        'user_msg' => 'Erro interno ao listar usuários.'
    ], 500);
}
