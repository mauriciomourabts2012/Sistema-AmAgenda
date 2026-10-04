<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LISTAR EMPRESAS — Super Admin
|--------------------------------------------------------------------------
| Compatível com:
| GET /public/api/api_central.php?path=superadmin/empresa/listar
|
| Filtros aceitos:
| - de       => Y-m-d ou d/m/Y
| - ate      => Y-m-d ou d/m/Y
| - status   => ativo | inativo | bloqueado | todos
| - busca    => texto livre
| - page     => página atual
| - limit    => itens por página
|--------------------------------------------------------------------------
*/

if (!function_exists('out')) {
    function out(array $payload, int $http = 200): void {
        http_response_code($http);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

require __DIR__ . '/../../_auth/bloquear.php';

/**
 * As rotas de suporte reutilizam este handler para não criar um segundo ponto
 * de entrada para o mesmo contexto de empresa. A identidade continua sendo a
 * do Super Admin; somente a empresa ativa é anexada temporariamente à sessão.
 */
$rotaAtual = isset($rota) && is_string($rota) ? $rota : '';
$rotasSuporte = ['superadmin/suporte/entrar', 'superadmin/suporte/sair'];

if (in_array($rotaAtual, $rotasSuporte, true)) {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        out(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED', 'user_msg' => 'Método não permitido.'], 405);
    }

    require_once __DIR__ . '/../../_auth/csrf.php';
    require_once __DIR__ . '/../../_config/conexao.php';
    require_once __DIR__ . '/../../_servicos/auditoria.php';
    csrfValidarSessao();

    if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
        out(['ok' => false, 'code' => 'DB_CONNECTION_ERROR', 'user_msg' => 'Não foi possível validar o modo suporte.'], 500);
    }
    $conexao->set_charset('utf8mb4');

    $authSuporte = is_array($_SESSION['auth'] ?? null) ? $_SESSION['auth'] : [];
    $idSuperAdmin = (int)($authSuporte['id_usuario'] ?? 0);
    $emModoSuporte = (bool)($authSuporte['modo_suporte'] ?? false);

    if ($rotaAtual === 'superadmin/suporte/entrar') {
        if ($emModoSuporte) {
            out(['ok' => false, 'code' => 'SUPPORT_ALREADY_ACTIVE', 'user_msg' => 'Finalize o modo suporte atual antes de selecionar outra empresa.'], 409);
        }

        $idEmpresaSuporte = filter_var($_POST['id_empresa'] ?? null, FILTER_VALIDATE_INT);
        if ($idEmpresaSuporte === false || $idEmpresaSuporte <= 0) {
            out(['ok' => false, 'code' => 'INVALID_COMPANY', 'user_msg' => 'Empresa inválida.'], 422);
        }

        $stmtEmpresaSuporte = $conexao->prepare("SELECT nome FROM empresa WHERE id_empresa=? AND status='ativo' LIMIT 1");
        if (!$stmtEmpresaSuporte) {
            out(['ok' => false, 'code' => 'SUPPORT_COMPANY_CHECK_ERROR', 'user_msg' => 'Não foi possível validar a empresa.'], 500);
        }
        $stmtEmpresaSuporte->bind_param('i', $idEmpresaSuporte);
        $stmtEmpresaSuporte->execute();
        $stmtEmpresaSuporte->bind_result($nomeEmpresaSuporte);
        $empresaSuporteValida = $stmtEmpresaSuporte->fetch();
        $stmtEmpresaSuporte->close();

        if (!$empresaSuporteValida) {
            out(['ok' => false, 'code' => 'SUPPORT_COMPANY_INACTIVE', 'user_msg' => 'A empresa não existe ou não está ativa.'], 422);
        }

        $sessaoAnterior = $_SESSION;
        try {
            if (!session_regenerate_id(true)) {
                throw new RuntimeException('Não foi possível renovar a sessão.');
            }

            $iniciadoEm = date('Y-m-d H:i:s');
            $_SESSION['empresa_id'] = (int)$idEmpresaSuporte;
            $_SESSION['id_empresa'] = (int)$idEmpresaSuporte;
            $_SESSION['empresa_nome'] = (string)$nomeEmpresaSuporte;
            $_SESSION['perfil_id'] = 0;
            $_SESSION['perfil_nome'] = 'super_admin';
            $_SESSION['modo_suporte'] = true;
            $_SESSION['modo_suporte_iniciado_em'] = $iniciadoEm;
            $_SESSION['auth']['empresa_id'] = (int)$idEmpresaSuporte;
            $_SESSION['auth']['id_empresa'] = (int)$idEmpresaSuporte;
            $_SESSION['auth']['empresa_nome'] = (string)$nomeEmpresaSuporte;
            $_SESSION['auth']['perfil_id'] = 0;
            $_SESSION['auth']['perfil_nome'] = 'super_admin';
            $_SESSION['auth']['modo_suporte'] = true;
            $_SESSION['auth']['modo_suporte_iniciado_em'] = $iniciadoEm;

            $conexao->begin_transaction();
            auditoriaRegistrar($conexao, 'suporte.iniciado', [
                'entidade_rotulo' => (string)$nomeEmpresaSuporte,
                'descricao' => 'Iniciou o modo suporte na empresa ' . (string)$nomeEmpresaSuporte . '.',
                'contexto' => ['origem' => 'painel_super_admin'],
            ]);
            $conexao->commit();
        } catch (Throwable $e) {
            try { $conexao->rollback(); } catch (Throwable) {}
            $_SESSION = $sessaoAnterior;
            error_log('[modo_suporte] Falha ao iniciar o modo suporte: ' . $e->getMessage());
            out(['ok' => false, 'code' => 'SUPPORT_START_ERROR', 'user_msg' => 'Não foi possível iniciar o modo suporte.'], 500);
        }

        out([
            'ok' => true,
            'code' => 'SUPPORT_STARTED',
            'user_msg' => 'Modo suporte iniciado.',
            'data' => [
                'empresa_id' => (int)$idEmpresaSuporte,
                'empresa_nome' => (string)$nomeEmpresaSuporte,
                'redirect' => '/views/painel-administrativo/painel-administrativo.html',
            ],
        ]);
    }

    $idEmpresaSuporte = (int)($authSuporte['empresa_id'] ?? $_SESSION['empresa_id'] ?? 0);
    if (!$emModoSuporte || $idEmpresaSuporte <= 0) {
        out(['ok' => false, 'code' => 'SUPPORT_NOT_ACTIVE', 'user_msg' => 'O modo suporte não está ativo.'], 409);
    }

    $stmtContextoSuporte = $conexao->prepare("SELECT u.nome, e.nome FROM usuario u INNER JOIN empresa e ON e.id_empresa=? WHERE u.id_usuario=? AND u.tipo_usuario='super_admin' AND u.status='ativo' LIMIT 1");
    if (!$stmtContextoSuporte) {
        out(['ok' => false, 'code' => 'SUPPORT_CONTEXT_CHECK_ERROR', 'user_msg' => 'Não foi possível validar o modo suporte.'], 500);
    }
    $stmtContextoSuporte->bind_param('ii', $idEmpresaSuporte, $idSuperAdmin);
    $stmtContextoSuporte->execute();
    $stmtContextoSuporte->bind_result($nomeSuperAdmin, $nomeEmpresaSuporte);
    $contextoSuporteValido = $stmtContextoSuporte->fetch();
    $stmtContextoSuporte->close();
    if (!$contextoSuporteValido) {
        out(['ok' => false, 'code' => 'SUPPORT_CONTEXT_INVALID', 'user_msg' => 'O contexto do modo suporte não é mais válido.'], 403);
    }

    try {
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Não foi possível renovar a sessão.');
        }

        $conexao->begin_transaction();
        auditoriaRegistrar($conexao, 'suporte.finalizado', [
            'ator' => [
                'ator_tipo' => 'super_admin',
                'id_ator' => $idSuperAdmin,
                'ator_nome' => (string)$nomeSuperAdmin,
                'ator_perfil' => 'super_admin',
                'id_empresa' => $idEmpresaSuporte,
                'modo_suporte' => true,
                'origem' => 'modo_suporte',
            ],
            'entidade_rotulo' => (string)$nomeEmpresaSuporte,
            'descricao' => 'Finalizou o modo suporte na empresa ' . (string)$nomeEmpresaSuporte . '.',
            'contexto' => ['origem' => 'painel_administrativo'],
        ]);
        $conexao->commit();
    } catch (Throwable $e) {
        try { $conexao->rollback(); } catch (Throwable) {}
        error_log('[modo_suporte] Falha ao finalizar o modo suporte: ' . $e->getMessage());
        out(['ok' => false, 'code' => 'SUPPORT_FINISH_ERROR', 'user_msg' => 'Não foi possível finalizar o modo suporte.'], 500);
    }

    unset(
        $_SESSION['empresa_id'],
        $_SESSION['id_empresa'],
        $_SESSION['empresa_nome'],
        $_SESSION['perfil_id'],
        $_SESSION['perfil_nome'],
        $_SESSION['modo_suporte_iniciado_em']
    );
    $_SESSION['modo_suporte'] = false;
    $_SESSION['auth']['empresa_id'] = 0;
    $_SESSION['auth']['id_empresa'] = 0;
    $_SESSION['auth']['empresa_nome'] = '';
    $_SESSION['auth']['perfil_id'] = 0;
    $_SESSION['auth']['perfil_nome'] = 'super_admin';
    $_SESSION['auth']['modo_suporte'] = false;
    unset($_SESSION['auth']['modo_suporte_iniciado_em']);

    out([
        'ok' => true,
        'code' => 'SUPPORT_FINISHED',
        'user_msg' => 'Modo suporte finalizado.',
        'data' => ['redirect' => '/views/super-admin/painel-super-admin.html'],
    ]);
}

/* ==========================================================
   HELPERS
========================================================== */
function s($v): ?string {
    if ($v === null) {
        return null;
    }

    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

function clampInt($v, int $min, int $max, int $fallback): int {
    $n = filter_var($v, FILTER_VALIDATE_INT);
    if ($n === false) {
        return $fallback;
    }

    if ($n < $min) {
        return $min;
    }

    if ($n > $max) {
        return $max;
    }

    return $n;
}

function parseDate(?string $date): ?DateTimeImmutable {
    if ($date === null) {
        return null;
    }

    $date = trim($date);
    if ($date === '') {
        return null;
    }

    $formats = ['Y-m-d', 'd/m/Y'];

    foreach ($formats as $fmt) {
        $dt = DateTimeImmutable::createFromFormat('!' . $fmt, $date);
        if ($dt instanceof DateTimeImmutable) {
            $errors = DateTimeImmutable::getLastErrors();

            $warningCount = is_array($errors) ? (int)($errors['warning_count'] ?? 0) : 0;
            $errorCount   = is_array($errors) ? (int)($errors['error_count'] ?? 0) : 0;

            if ($warningCount === 0 && $errorCount === 0) {
                return $dt;
            }
        }
    }

    return null;
}

/**
 * bind_param dinâmico com referências
 */
function bindParams(mysqli_stmt $stmt, string $types, array &$values): bool {
    if ($types === '') {
        return true;
    }

    $refs = [];
    $refs[] = $types;

    foreach ($values as $k => $v) {
        $refs[] = &$values[$k];
    }

    return $stmt->bind_param(...$refs);
}

try {
    /* ==========================================================
       MÉTODO
       A API central já valida, mas mantemos aqui por segurança
    ========================================================== */
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'GET') {
        out([
            'ok'       => false,
            'code'     => 'METHOD_NOT_ALLOWED',
            'user_msg' => 'Método não permitido.'
        ], 405);
    }

    /* ==========================================================
       CONEXÃO
    ========================================================== */
    require __DIR__ . '/../../_config/conexao.php';

    if (!isset($conexao) || !($conexao instanceof mysqli)) {
        out([
            'ok'       => false,
            'code'     => 'DB_CONNECTION_INVALID',
            'user_msg' => 'Conexão com banco de dados inválida.'
        ], 500);
    }

    if ($conexao->connect_errno) {
        out([
            'ok'       => false,
            'code'     => 'DB_CONNECTION_ERROR',
            'user_msg' => 'Erro de conexão com banco de dados.'
        ], 500);
    }

    $conexao->set_charset('utf8mb4');

    /* ==========================================================
       ENTRADAS
    ========================================================== */
    $deRaw     = s($_GET['de'] ?? null);
    $ateRaw    = s($_GET['ate'] ?? null);
    $statusRaw = s($_GET['status'] ?? 'ativo');
    $buscaRaw  = s($_GET['busca'] ?? null);
    $planoId   = clampInt($_GET['plano_id'] ?? 0, 0, 999999999, 0);
    $ordemRaw  = strtolower((string)($_GET['ordem'] ?? 'recentes'));

    $page  = clampInt($_GET['page'] ?? 1, 1, 999999, 1);
    $limit = clampInt($_GET['limit'] ?? 10, 1, 100, 10);

    $deDate  = $deRaw !== null ? parseDate($deRaw) : null;
    $ateDate = $ateRaw !== null ? parseDate($ateRaw) : null;

    if ($deRaw !== null && !$deDate) {
        out([
            'ok'       => false,
            'code'     => 'INVALID_DATE_DE',
            'user_msg' => 'Data inicial inválida. Use o formato Y-m-d ou d/m/Y.'
        ], 422);
    }

    if ($ateRaw !== null && !$ateDate) {
        out([
            'ok'       => false,
            'code'     => 'INVALID_DATE_ATE',
            'user_msg' => 'Data final inválida. Use o formato Y-m-d ou d/m/Y.'
        ], 422);
    }

    if ($deDate && $ateDate && $deDate > $ateDate) {
        out([
            'ok'       => false,
            'code'     => 'INVALID_PERIOD',
            'user_msg' => 'A data inicial não pode ser maior que a data final.'
        ], 422);
    }

    $status = strtolower((string)($statusRaw ?? 'ativo'));
    $statusPermitidos = ['ativo', 'inativo', 'bloqueado', 'todos'];

    if (!in_array($status, $statusPermitidos, true)) {
        out([
            'ok'       => false,
            'code'     => 'INVALID_STATUS',
            'user_msg' => 'Status inválido. Use: ativo, inativo, bloqueado ou todos.'
        ], 422);
    }

    $offset = ($page - 1) * $limit;

    /* ==========================================================
       WHERE DINÂMICO
    ========================================================== */
    $where  = [];
    $types  = '';
    $params = [];

    if ($deDate) {
        $where[] = 'e.criado_em >= ?';
        $types .= 's';
        $params[] = $deDate->format('Y-m-d') . ' 00:00:00';
    }

    if ($ateDate) {
        $where[] = 'e.criado_em <= ?';
        $types .= 's';
        $params[] = $ateDate->format('Y-m-d') . ' 23:59:59';
    }

    if ($status !== 'todos') {
        $where[]  = 'e.status = ?';
        $types   .= 's';
        $params[] = $status;
    }

    if ($planoId > 0) {
        $where[] = 'e.plano_id = ?';
        $types .= 'i';
        $params[] = $planoId;
    }

    if ($buscaRaw !== null) {
        $where[] = '(
            e.nome LIKE ?
            OR e.cnpj LIKE ?
            OR e.email LIKE ?
            OR e.telefone LIKE ?
        )';

        $like = '%' . $buscaRaw . '%';

        $types   .= 'ssss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Whitelist de ORDER BY: valores externos nunca são tratados como coluna SQL.
    $ordenacoesPermitidas = [
        'recentes' => 'e.criado_em DESC, e.id_empresa DESC',
        'antigos' => 'e.criado_em ASC, e.id_empresa ASC',
        'nome_asc' => 'e.nome ASC, e.id_empresa ASC',
        'nome_desc' => 'e.nome DESC, e.id_empresa DESC',
    ];
    $ordem = array_key_exists($ordemRaw, $ordenacoesPermitidas) ? $ordemRaw : 'recentes';
    $orderBySql = $ordenacoesPermitidas[$ordem];

    /* ==========================================================
       TOTAL
    ========================================================== */
    $sqlCount = "
        SELECT COUNT(*) AS total
        FROM empresa e
        {$whereSql}
    ";

    $stmtCount = $conexao->prepare($sqlCount);
    if (!$stmtCount) {
        throw new RuntimeException('Erro ao preparar COUNT: ' . $conexao->error);
    }

    if (!bindParams($stmtCount, $types, $params)) {
        throw new RuntimeException('Erro ao vincular parâmetros do COUNT.');
    }

    if (!$stmtCount->execute()) {
        throw new RuntimeException('Erro ao executar COUNT: ' . $stmtCount->error);
    }

    $resCount = $stmtCount->get_result();
    $rowCount = $resCount ? $resCount->fetch_assoc() : null;
    $total    = (int)($rowCount['total'] ?? 0);

    $stmtCount->close();

    /* ==========================================================
       LISTA
    ========================================================== */
    $sql = "
        SELECT
            e.id_empresa,
            e.nome,
            e.cnpj,
            e.email,
            e.telefone,
            e.plano_id,
            p.nome AS plano_nome,
            e.status,
            e.endereco,
            e.observacao,
            DATE_FORMAT(e.criado_em, '%Y-%m-%d %H:%i:%s') AS criado_em,
            DATE_FORMAT(e.atualizado_em, '%Y-%m-%d %H:%i:%s') AS atualizado_em
        FROM empresa e
        LEFT JOIN plano p
            ON p.id_plano = e.plano_id
        {$whereSql}
        ORDER BY {$orderBySql}
        LIMIT ? OFFSET ?
    ";

    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar SELECT: ' . $conexao->error);
    }

    $typesList  = $types . 'ii';
    $paramsList = $params;
    $paramsList[] = $limit;
    $paramsList[] = $offset;

    if (!bindParams($stmt, $typesList, $paramsList)) {
        throw new RuntimeException('Erro ao vincular parâmetros da listagem.');
    }

    if (!$stmt->execute()) {
        throw new RuntimeException('Erro ao executar SELECT: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    $items  = [];

    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'id_empresa'    => isset($row['id_empresa']) ? (int)$row['id_empresa'] : 0,
            'nome'          => isset($row['nome']) ? (string)$row['nome'] : '',
            'cnpj'          => isset($row['cnpj']) ? (string)$row['cnpj'] : '',
            'email'         => isset($row['email']) && $row['email'] !== null ? (string)$row['email'] : null,
            'telefone'      => isset($row['telefone']) ? (string)$row['telefone'] : '',
            'plano_id'      => isset($row['plano_id']) && $row['plano_id'] !== null ? (int)$row['plano_id'] : null,
            'plano_nome'    => isset($row['plano_nome']) && $row['plano_nome'] !== null ? (string)$row['plano_nome'] : null,
            'status'        => isset($row['status']) ? (string)$row['status'] : '',
            'endereco'      => isset($row['endereco']) && $row['endereco'] !== null ? (string)$row['endereco'] : null,
            'observacao'    => isset($row['observacao']) && $row['observacao'] !== null ? (string)$row['observacao'] : null,
            'criado_em'     => isset($row['criado_em']) ? (string)$row['criado_em'] : null,
            'atualizado_em' => isset($row['atualizado_em']) && $row['atualizado_em'] !== null ? (string)$row['atualizado_em'] : null,
        ];
    }

    $stmt->close();

    $totalPages = (int) max(1, (int) ceil($total / $limit));

    out([
        'ok'       => true,
        'code'     => 'LISTA_EMPRESA_OK',
        'user_msg' => $total > 0
            ? 'Empresas listadas com sucesso.'
            : 'Nenhuma empresa encontrada para os filtros informados.',
        'data' => [
            'items' => $items,
            'meta'  => [
                'page'        => $page,
                'limit'       => $limit,
                'total'       => $total,
                'total_pages' => $totalPages,
                'offset'      => $offset,
                'de'          => $deDate?->format('Y-m-d'),
                'ate'         => $ateDate?->format('Y-m-d'),
                'plano_id'    => $planoId > 0 ? $planoId : null,
                'ordem'       => $ordem,
                'status'      => $status,
                'busca'       => $buscaRaw
            ]
        ]
    ], 200);

} catch (Throwable $e) {
    error_log('LISTA_EMPRESA_EXCEPTION: ' . $e->getMessage());

    out([
        'ok'       => false,
        'code'     => 'LISTA_EMPRESA_ERROR',
        'user_msg' => 'Erro ao listar empresas.'
    ], 500);
}
