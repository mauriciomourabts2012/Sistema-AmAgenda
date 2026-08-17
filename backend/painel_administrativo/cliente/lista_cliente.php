<?php
declare(strict_types=1);

/* ==========================================================
   lista_cliente.php
   ✅ Mesmo padrão de conexão
   ✅ Mesmo padrão de sessão
   ✅ Lista somente clientes da empresa da sessão
   ✅ Busca real funcionando corretamente
   ✅ Filtro por status
   ✅ Filtro por período (inicio/fim opcionais)
   ✅ Paginação
   ✅ Compatível com ListaClientes.js
   ✅ Ordenação melhorada por movimentação
   ✅ Padrão inicial: somente ativos
========================================================== */

// ✅ NÃO defina header aqui (api_central já define)
// ✅ NÃO redefina out() se já existir
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
    $inicio = s($_GET['inicio'] ?? '');
    $fim    = s($_GET['fim'] ?? '');

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
       FILTROS
    ========================================================== */
    $where = [];
    $types = '';
    $params = [];

    $where[] = 'c.id_empresa = ?';
    $types .= 'i';
    $params[] = $idEmpresaSessao;

    if ($status !== 'todos') {
        $where[] = 'c.status = ?';
        $types .= 's';
        $params[] = $status;
    }

    if ($inicio !== '') {
        $where[] = 'DATE(c.criado_em) >= ?';
        $types .= 's';
        $params[] = $inicio;
    }

    if ($fim !== '') {
        $where[] = 'DATE(c.criado_em) <= ?';
        $types .= 's';
        $params[] = $fim;
    }

    if ($busca !== '') {
        $buscaLike = '%' . $busca . '%';
        $buscaDigitsLimpo = somenteDigitos($busca);
        $buscaParts = [];

        $buscaParts[] = 'c.nome_completo LIKE ?';
        $types .= 's';
        $params[] = $buscaLike;

        if ($buscaDigitsLimpo !== '') {
            $buscaParts[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.whatsapp_celular, '(', ''), ')', ''), '-', ''), ' ', ''), '+', '') LIKE ?";
            $types .= 's';
            $params[] = '%' . $buscaDigitsLimpo . '%';

            $buscaParts[] = "REPLACE(REPLACE(c.cpf, '.', ''), '-', '') LIKE ?";
            $types .= 's';
            $params[] = '%' . $buscaDigitsLimpo . '%';
        } else {
            $buscaParts[] = 'c.cpf LIKE ?';
            $types .= 's';
            $params[] = $buscaLike;
        }

        $buscaParts[] = 'c.email LIKE ?';
        $types .= 's';
        $params[] = $buscaLike;

        $buscaParts[] = 'c.cidade LIKE ?';
        $types .= 's';
        $params[] = $buscaLike;

        $buscaParts[] = 'c.bairro LIKE ?';
        $types .= 's';
        $params[] = $buscaLike;

        $buscaParts[] = 'c.uf LIKE ?';
        $types .= 's';
        $params[] = $buscaLike;

        $where[] = '(' . implode(' OR ', $buscaParts) . ')';
    }

    $whereSql = implode(' AND ', $where);

    /* ==========================================================
       TOTAL
    ========================================================== */
    $sqlTotal = "
        SELECT COUNT(*) AS total
        FROM cliente c
        WHERE {$whereSql}
    ";

    $stmtTotal = $conexao->prepare($sqlTotal);
    if (!$stmtTotal) {
        throw new RuntimeException('Erro ao preparar total de clientes: ' . $conexao->error);
    }

    $paramsTotal = $params;
    bindParamsDynamic($stmtTotal, $types, $paramsTotal);

    if (!$stmtTotal->execute()) {
        throw new RuntimeException('Erro ao executar total de clientes: ' . $stmtTotal->error);
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
       ORDENAÇÃO
       ✅ Mais movimentados primeiro
       ✅ Em empate, mais novos primeiro
    ========================================================== */
    $orderBy = 'ORDER BY c.ultima_movimentacao_em DESC, c.id_cliente DESC';

    /* ==========================================================
       LISTA
    ========================================================== */
    $sqlLista = "
        SELECT
            c.id_cliente,
            c.id_empresa,
            c.nome_completo,
            c.whatsapp_celular,
            c.foto_perfil,
            c.email,
            c.cpf,
            c.data_nascimento,
            c.cep,
            c.logradouro,
            c.numero,
            c.bairro,
            c.cidade,
            c.uf,
            c.complemento,
            c.observacao,
            c.cadastro_completo,
            c.status,
            c.primeiro_acesso_em,
            c.ultimo_login_em,
            c.ultimo_agendamento_em,
            c.ultimo_atendimento_em,
            c.ultima_movimentacao_em,
            c.total_agendamentos,
            c.criado_em,
            c.atualizado_em
        FROM cliente c
        WHERE {$whereSql}
        {$orderBy}
        LIMIT ? OFFSET ?
    ";

    $stmtLista = $conexao->prepare($sqlLista);
    if (!$stmtLista) {
        throw new RuntimeException('Erro ao preparar lista de clientes: ' . $conexao->error);
    }

    $typesLista = $types . 'ii';
    $paramsLista = $params;
    $paramsLista[] = $limite;
    $paramsLista[] = $offset;

    bindParamsDynamic($stmtLista, $typesLista, $paramsLista);

    if (!$stmtLista->execute()) {
        throw new RuntimeException('Erro ao executar lista de clientes: ' . $stmtLista->error);
    }

    $resultLista = $stmtLista->get_result();
    if (!$resultLista) {
        throw new RuntimeException('Erro ao obter resultado da lista de clientes.');
    }

    $items = [];

    while ($row = $resultLista->fetch_assoc()) {
        $items[] = [
            'id_cliente'             => (int)$row['id_cliente'],
            'id_empresa'             => (int)$row['id_empresa'],
            'nome_completo'          => $row['nome_completo'] !== null ? (string)$row['nome_completo'] : null,
            'whatsapp_celular'       => (string)$row['whatsapp_celular'],
            'foto_perfil'            => $row['foto_perfil'] !== null ? (string)$row['foto_perfil'] : null,
            'email'                  => $row['email'] !== null ? (string)$row['email'] : null,
            'cpf'                    => $row['cpf'] !== null ? (string)$row['cpf'] : null,
            'data_nascimento'        => $row['data_nascimento'] !== null ? (string)$row['data_nascimento'] : null,
            'cep'                    => $row['cep'] !== null ? (string)$row['cep'] : null,
            'logradouro'             => $row['logradouro'] !== null ? (string)$row['logradouro'] : null,
            'numero'                 => $row['numero'] !== null ? (string)$row['numero'] : null,
            'bairro'                 => $row['bairro'] !== null ? (string)$row['bairro'] : null,
            'cidade'                 => $row['cidade'] !== null ? (string)$row['cidade'] : null,
            'uf'                     => $row['uf'] !== null ? (string)$row['uf'] : null,
            'complemento'            => $row['complemento'] !== null ? (string)$row['complemento'] : null,
            'observacao'             => $row['observacao'] !== null ? (string)$row['observacao'] : null,
            'cadastro_completo'      => (int)$row['cadastro_completo'],
            'status'                 => (string)$row['status'],
            'primeiro_acesso_em'     => $row['primeiro_acesso_em'] !== null ? (string)$row['primeiro_acesso_em'] : null,
            'ultimo_login_em'        => $row['ultimo_login_em'] !== null ? (string)$row['ultimo_login_em'] : null,
            'ultimo_agendamento_em'  => $row['ultimo_agendamento_em'] !== null ? (string)$row['ultimo_agendamento_em'] : null,
            'ultimo_atendimento_em'  => $row['ultimo_atendimento_em'] !== null ? (string)$row['ultimo_atendimento_em'] : null,
            'ultima_movimentacao_em' => $row['ultima_movimentacao_em'] !== null ? (string)$row['ultima_movimentacao_em'] : null,
            'total_agendamentos'     => (int)$row['total_agendamentos'],
            'criado_em'              => $row['criado_em'] !== null ? (string)$row['criado_em'] : null,
            'atualizado_em'          => $row['atualizado_em'] !== null ? (string)$row['atualizado_em'] : null
        ];
    }

    $stmtLista->close();

    /* ==========================================================
       RESPOSTA
    ========================================================== */
    out([
        'ok' => true,
        'code' => 'CLIENTES_LISTADOS',
        'user_msg' => 'Clientes listados com sucesso.',
        'data' => [
            'empresa' => [
                'id_empresa' => (int)$empresa['id_empresa'],
                'nome'       => (string)$empresa['nome'],
                'status'     => (string)$empresa['status']
            ],
            'filtros' => [
                'busca'  => $busca,
                'status' => $status,
                'inicio' => $inicio,
                'fim'    => $fim,
                'pagina' => $pagina,
                'limite' => $limite
            ],
            'ordenacao' => [
                'campo_principal'  => 'ultima_movimentacao_em',
                'direcao_principal'=> 'DESC',
                'desempate'        => 'id_cliente DESC'
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
    error_log('[lista_cliente.php] ' . $e->getMessage());

    out([
        'ok' => false,
        'code' => 'INTERNAL_ERROR',
        'user_msg' => 'Erro interno ao listar clientes.'
    ], 500);
}