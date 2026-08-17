<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        out([
            'ok' => false,
            'code' => 'METHOD_NOT_ALLOWED',
            'user_msg' => 'Método não permitido.'
        ], 405);
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $auth = $_SESSION['auth'] ?? null;

    $idUsuarioSessao = (int)($auth['id_usuario'] ?? 0);
    $statusSessao = (string)($auth['status'] ?? '');

    if ($idUsuarioSessao <= 0) {
        out([
            'ok' => false,
            'code' => 'NOT_AUTHENTICATED',
            'user_msg' => 'Sessão expirada. Faça login novamente.'
        ], 401);
    }

    if ($statusSessao !== '' && $statusSessao !== 'ativo') {
        out([
            'ok' => false,
            'code' => 'SESSION_USER_INACTIVE',
            'user_msg' => 'Seu usuário não está ativo. Faça login novamente.'
        ], 403);
    }

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

    require __DIR__ . '/../../_config/conexao.php';

    if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
        out([
            'ok' => false,
            'code' => 'DB_CONNECTION_ERROR',
            'user_msg' => 'Erro de conexão com banco de dados.'
        ], 500);
    }

    $conexao->set_charset('utf8mb4');

    function lower(mixed $v): string
    {
        return mb_strtolower(trim((string)$v), 'UTF-8');
    }

    function intParam(string $key): int
    {
        $raw = $_GET[$key] ?? 0;
        return is_numeric($raw) ? (int)$raw : 0;
    }

    $stmt = $conexao->prepare("
        SELECT
            e.id_empresa,
            e.nome,
            e.status,
            eu.status AS vinculo_status
        FROM empresa e
        INNER JOIN empresa_usuario eu
                ON eu.id_empresa = e.id_empresa
        WHERE e.id_empresa = ?
          AND eu.id_usuario = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar validação da empresa: ' . $conexao->error);
    }

    $stmt->bind_param('ii', $idEmpresaSessao, $idUsuarioSessao);

    if (!$stmt->execute()) {
        throw new RuntimeException('Erro ao executar validação da empresa: ' . $stmt->error);
    }

    $stmt->bind_result($empresaIdDb, $empresaNomeDb, $empresaStatusDb, $vinculoStatusDb);
    $empresaEncontrada = $stmt->fetch();
    $stmt->close();

    if (!$empresaEncontrada) {
        out([
            'ok' => false,
            'code' => 'USER_COMPANY_LINK_NOT_FOUND',
            'user_msg' => 'Seu usuário não possui vínculo com esta empresa.'
        ], 403);
    }

    if (lower($empresaStatusDb) !== 'ativo') {
        out([
            'ok' => false,
            'code' => 'EMPRESA_INACTIVE',
            'user_msg' => 'A empresa vinculada à sessão está inativa.'
        ], 403);
    }

    if (lower($vinculoStatusDb) !== 'ativo') {
        out([
            'ok' => false,
            'code' => 'USER_COMPANY_LINK_INACTIVE',
            'user_msg' => 'Seu vínculo com esta empresa está inativo.'
        ], 403);
    }

    $idProfissionalSelecionado = intParam('id_profissional');
    if ($idProfissionalSelecionado <= 0) {
        $idProfissionalSelecionado = intParam('profissional_id');
    }

    $idProfissionalSessao = 0;

    if (isset($auth['id_profissional'])) {
        $idProfissionalSessao = (int)$auth['id_profissional'];
    } elseif (isset($_SESSION['id_profissional'])) {
        $idProfissionalSessao = (int)$_SESSION['id_profissional'];
    } elseif (isset($_SESSION['profissional_id'])) {
        $idProfissionalSessao = (int)$_SESSION['profissional_id'];
    } elseif (isset($_SESSION['profissional']['id_profissional'])) {
        $idProfissionalSessao = (int)$_SESSION['profissional']['id_profissional'];
    }

    if ($idProfissionalSelecionado > 0) {
        $stmt = $conexao->prepare("
            SELECT p.id_profissional
            FROM profissional p
            INNER JOIN empresa_usuario eu
                    ON eu.id_usuario = p.id_usuario
            WHERE p.id_profissional = ?
              AND eu.id_empresa = ?
              AND eu.status = 'ativo'
            LIMIT 1
        ");

        if (!$stmt) {
            throw new RuntimeException('Erro ao preparar validação do profissional selecionado: ' . $conexao->error);
        }

        $stmt->bind_param('ii', $idProfissionalSelecionado, $idEmpresaSessao);

        if (!$stmt->execute()) {
            throw new RuntimeException('Erro ao executar validação do profissional selecionado: ' . $stmt->error);
        }

        $stmt->bind_result($idProfissionalDb);

        if ($stmt->fetch()) {
            $idProfissionalSessao = (int)$idProfissionalDb;
        }

        $stmt->close();

        if ($idProfissionalSessao <= 0) {
            out([
                'ok' => false,
                'code' => 'PROFESSIONAL_NOT_FOUND_FOR_COMPANY',
                'user_msg' => 'Profissional não encontrado para a empresa da sessão.'
            ], 404);
        }
    }

    if ($idProfissionalSessao <= 0) {
        $stmt = $conexao->prepare("
            SELECT p.id_profissional
            FROM profissional p
            INNER JOIN empresa_usuario eu
                    ON eu.id_usuario = p.id_usuario
            WHERE p.id_usuario = ?
              AND eu.id_empresa = ?
              AND eu.status = 'ativo'
            LIMIT 1
        ");

        if (!$stmt) {
            throw new RuntimeException('Erro ao preparar busca do profissional: ' . $conexao->error);
        }

        $stmt->bind_param('ii', $idUsuarioSessao, $idEmpresaSessao);

        if (!$stmt->execute()) {
            throw new RuntimeException('Erro ao executar busca do profissional: ' . $stmt->error);
        }

        $stmt->bind_result($idProfissionalDb);

        if ($stmt->fetch()) {
            $idProfissionalSessao = (int)$idProfissionalDb;
        }

        $stmt->close();
    }

    if ($idProfissionalSessao <= 0) {
        out([
            'ok' => false,
            'code' => 'PROFESSIONAL_NOT_FOUND',
            'user_msg' => 'Seu usuário não possui profissional vinculado.'
        ], 404);
    }

    $statusFiltro = lower($_GET['status'] ?? '');
    $whereStatus = '';
    $typesServico = 'ii';
    $paramsServico = [$idEmpresaSessao, $idProfissionalSessao];

    if ($statusFiltro !== '' && $statusFiltro !== 'todos') {
        if (!in_array($statusFiltro, ['ativo', 'inativo'], true)) {
            out([
                'ok' => false,
                'code' => 'INVALID_STATUS_FILTER',
                'user_msg' => 'Filtro de status inválido.'
            ], 422);
        }

        $whereStatus = " AND status = ? ";
        $typesServico .= 's';
        $paramsServico[] = $statusFiltro;
    }

    $stmt = $conexao->prepare("
        SELECT
            id_servico,
            id_empresa,
            id_profissional,
            nome,
            descricao,
            duracao_min,
            valor,
            status,
            criado_em,
            atualizado_em
        FROM servico
        WHERE id_empresa = ?
          AND id_profissional = ?
          {$whereStatus}
        ORDER BY nome ASC, id_servico ASC
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar listagem de serviços: ' . $conexao->error);
    }

    $stmt->bind_param($typesServico, ...$paramsServico);

    if (!$stmt->execute()) {
        throw new RuntimeException('Erro ao executar listagem de serviços: ' . $stmt->error);
    }

    $res = $stmt->get_result();
    $servicos = [];

    while ($row = $res->fetch_assoc()) {
        $servicos[] = [
            'id_servico' => (int)$row['id_servico'],
            'id_empresa' => (int)$row['id_empresa'],
            'id_profissional' => (int)$row['id_profissional'],
            'nome' => (string)$row['nome'],
            'descricao' => $row['descricao'],
            'duracao_min' => (int)$row['duracao_min'],
            'valor' => number_format((float)$row['valor'], 2, '.', ''),
            'status' => (string)$row['status'],
            'criado_em' => $row['criado_em'],
            'atualizado_em' => $row['atualizado_em'],
        ];
    }

    $stmt->close();

    out([
        'ok' => true,
        'code' => 'SERVICES_LOADED',
        'user_msg' => 'Serviços carregados com sucesso.',
        'data' => [
            'id_empresa' => $idEmpresaSessao,
            'id_usuario' => $idUsuarioSessao,
            'id_profissional' => $idProfissionalSessao,
            'total' => count($servicos),
            'servicos' => $servicos
        ]
    ], 200);

} catch (Throwable $e) {
    error_log('[lista_servico] ' . $e->getMessage());

    out([
        'ok' => false,
        'code' => 'INTERNAL_ERROR',
        'user_msg' => 'Erro interno ao listar serviços.',
        'debug' => [
            'message' => $e->getMessage()
        ]
    ], 500);
}
